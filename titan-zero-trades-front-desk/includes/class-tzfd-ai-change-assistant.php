<?php
/**
 * Cancellation and rescheduling assistant (issue #25).
 *
 * Deterministic and local. It classifies a customer's change request, explains the notice policy and points the
 * customer to the existing authenticated self-service flow. It never changes a booking, never reads a booking
 * without a verified link, and a caller's phone number is never treated as proof of ownership.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-customer-cancellation.php';

final class TZFD_AI_Change_Assistant {

	const INTENT_CANCEL     = 'cancel';
	const INTENT_RESCHEDULE = 'reschedule';
	const INTENT_UNCLEAR    = 'unclear';
	const INTENT_OTHER      = 'other';

	const ACTION_SELF_SERVICE = 'self_service';
	const ACTION_HANDOFF      = 'handoff';
	const ACTION_GENERAL      = 'general';

	/** Below this confidence the assistant does not guess; it hands off to a person. */
	const MIN_CONFIDENCE = 0.6;

	/** Default notice period (hours) before a booking inside which the customer must contact the business. */
	const DEFAULT_NOTICE_HOURS = 24;

	const CANCEL_PHRASES     = array( 'cancel', 'cancels', 'cancelling', 'cancelled', 'call it off', 'calling it off', 'no longer need', 'dont need', 'do not need', 'not coming' );
	const RESCHEDULE_PHRASES = array( 'reschedule', 'rescheduling', 'move my', 'move the', 'move it', 'change the time', 'change my', 'different time', 'another time', 'another day', 'postpone', 'push back' );
	const POLICY_PHRASES     = array( 'refund', 'refunds', 'charge', 'charged', 'fee', 'fees', 'invoice', 'payment', 'deposit', 'complain', 'complaint', 'lawyer', 'solicitor', 'dispute' );

	/** Fixed, reviewed customer messages. Model output is never shown to the customer in this path. */
	const MESSAGES = array(
		'self_service' => 'To cancel or change a booking, use the secure link in your booking confirmation email. The link opens your booking and confirms the change. We cannot change a booking from a phone call or message alone.',
		'verified'     => 'Your booking link is valid. You can make the change from that page.',
		'policy_late'  => 'Changes inside the notice period need the business to confirm them. We have passed your request to the team.',
		'general'      => 'We can help with cancelling or rescheduling a booking, or pass your message to the team.',
		'handoff'      => 'We have passed your request to the team, who will contact you. We cannot confirm timing or staff availability.',
		'not_verified' => 'We could not match this request to a booking with a valid link. Please use the secure link in your confirmation email.',
	);

	/**
	 * Classify a request. Returns intent, confidence and whether the wording is policy-sensitive.
	 * Pure function: input is only the customer's text.
	 */
	public static function classify( $text ) {
		$padded = ' ' . self::normalise( $text ) . ' ';
		$cancel = self::has_phrase( $padded, self::CANCEL_PHRASES );
		$resch  = self::has_phrase( $padded, self::RESCHEDULE_PHRASES );
		$policy = self::has_phrase( $padded, self::POLICY_PHRASES );

		if ( $cancel && $resch ) {
			$intent = self::INTENT_UNCLEAR;
			$conf   = 0.5;
		} elseif ( $cancel ) {
			$intent = self::INTENT_CANCEL;
			$conf   = 0.9;
		} elseif ( $resch ) {
			$intent = self::INTENT_RESCHEDULE;
			$conf   = 0.9;
		} else {
			$intent = self::INTENT_OTHER;
			$conf   = 0.0;
		}
		return array( 'intent' => $intent, 'confidence' => $conf, 'policy_sensitive' => $policy );
	}

	/**
	 * Accept a hint from any model or external service. Only a value from the closed intent list survives.
	 * Anything else, including action names, booking IDs or permission claims, is discarded.
	 */
	public static function sanitize_model_hint( $hint ) {
		$allowed = array( self::INTENT_CANCEL, self::INTENT_RESCHEDULE, self::INTENT_UNCLEAR, self::INTENT_OTHER );
		$intent  = is_array( $hint ) && isset( $hint['intent'] ) && is_string( $hint['intent'] ) ? $hint['intent'] : '';
		return in_array( $intent, $allowed, true ) ? $intent : self::INTENT_OTHER;
	}

	/**
	 * Check a self-service link against a stored booking row. Fails closed and returns the same empty result for
	 * a missing row, another tenant's row, a stale version (any change to the booking, including a prior
	 * cancellation or reschedule) and an expired or forged token.
	 *
	 * @param object|null $row       Booking row fetched with WHERE id = %d AND company_id = %d.
	 * @param int         $tenant_id Tenant the request is scoped to.
	 * @param string      $token     Token from the link.
	 * @param string      $version   Version from the link.
	 * @param int         $expires   Expiry from the link (Unix time).
	 * @param string      $secret    Site secret (wp_salt('auth') in WordPress).
	 * @param int|null    $now       Unix time, injectable for tests.
	 * @return array { ok: bool, booking_id: int }
	 */
	public static function verify_ownership( $row, $tenant_id, $token, $version, $expires, $secret, $now = null ) {
		$refused = array( 'ok' => false, 'booking_id' => 0 );
		if ( ! is_object( $row ) || ! isset( $row->id, $row->company_id, $row->notifyto, $row->formid ) ) {
			return $refused;
		}
		$tenant_id = TZFD_Customer_Cancellation::normalize_booking_id( $tenant_id );
		if ( ! $tenant_id || (int) $row->company_id !== $tenant_id ) {
			return $refused;
		}
		$booking_id = TZFD_Customer_Cancellation::normalize_booking_id( $row->id );
		if ( ! $booking_id || ! is_string( $version ) || ! hash_equals( TZFD_Customer_Cancellation::row_version( $row ), $version ) ) {
			return $refused;
		}
		$ok = TZFD_Customer_Cancellation::verify_token(
			$booking_id,
			(string) $row->notifyto,
			$token,
			$secret,
			$version,
			TZFD_Customer_Cancellation::normalize_expiry( $expires ),
			$now
		);
		return $ok ? array( 'ok' => true, 'booking_id' => $booking_id ) : $refused;
	}

	/**
	 * True when the change is still allowed without the business's help: now is at or before start minus notice.
	 * Works on absolute Unix time, so DST shifts do not change the notice period.
	 */
	public static function notice_window_open( $start_epoch, $now, $notice_hours = self::DEFAULT_NOTICE_HOURS ) {
		$start = (int) $start_epoch;
		$now   = (int) $now;
		$hours = (int) $notice_hours;
		if ( $start <= 0 || $now <= 0 || $hours < 0 ) {
			return false;
		}
		return $now <= $start - ( $hours * 3600 );
	}

	/**
	 * Convert a stored local wall-clock start ("Y-m-d H:i:s") in an IANA zone to Unix time.
	 * Returns 0 for unparseable input. Times in a DST gap resolve forward, as PHP does.
	 */
	public static function local_start_epoch( $local_datetime, $timezone ) {
		if ( ! is_string( $local_datetime ) || ! is_string( $timezone ) ) {
			return 0;
		}
		try {
			$zone = new DateTimeZone( $timezone );
			$when = new DateTimeImmutable( $local_datetime, $zone );
			return $when->getTimestamp();
		} catch ( Exception $error ) {
			return 0;
		}
	}

	/**
	 * Build the reply for one request. The customer's phone number, if supplied, is ignored for authorisation.
	 *
	 * @param string $text    Customer message.
	 * @param array  $context Optional: 'verified' (bool, from verify_ownership), 'start_epoch' (int, only when verified),
	 *                        'now' (int), 'notice_hours' (int).
	 * @return array { action: string, intent: string, message: string, reason: string }
	 */
	public static function respond( $text, array $context = array() ) {
		$class  = self::classify( $text );
		$intent = $class['intent'];
		$verified = ! empty( $context['verified'] ) && true === $context['verified'];
		$now      = isset( $context['now'] ) ? (int) $context['now'] : time();
		$hours    = isset( $context['notice_hours'] ) ? (int) $context['notice_hours'] : self::DEFAULT_NOTICE_HOURS;

		if ( $class['policy_sensitive'] ) {
			return self::reply( self::ACTION_HANDOFF, $intent, 'handoff', 'policy_sensitive' );
		}
		if ( self::INTENT_OTHER === $intent ) {
			return self::reply( self::ACTION_GENERAL, $intent, 'general', 'no_change_intent' );
		}
		if ( $class['confidence'] < self::MIN_CONFIDENCE || self::INTENT_UNCLEAR === $intent ) {
			return self::reply( self::ACTION_HANDOFF, $intent, 'handoff', 'low_confidence' );
		}
		if ( ! $verified ) {
			return self::reply( self::ACTION_SELF_SERVICE, $intent, 'not_verified', 'unverified_link_required' );
		}
		$start = isset( $context['start_epoch'] ) ? (int) $context['start_epoch'] : 0;
		if ( $start && ! self::notice_window_open( $start, $now, $hours ) ) {
			return self::reply( self::ACTION_HANDOFF, $intent, 'policy_late', 'inside_notice_window' );
		}
		return self::reply( self::ACTION_SELF_SERVICE, $intent, 'verified', 'verified_self_service' );
	}

	private static function reply( $action, $intent, $message_key, $reason ) {
		return array(
			'action'  => $action,
			'intent'  => $intent,
			'message' => self::MESSAGES[ $message_key ],
			'reason'  => $reason,
		);
	}

	/** Lowercase, drop apostrophes, turn other punctuation into spaces, collapse whitespace. */
	private static function normalise( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}
		$text = strtolower( str_replace( array( "'", '’' ), '', $text ) );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function has_phrase( $padded, array $phrases ) {
		foreach ( $phrases as $phrase ) {
			if ( false !== strpos( $padded, ' ' . $phrase . ' ' ) ) {
				return true;
			}
		}
		return false;
	}
}
