<?php
/**
 * Human handoff and escalation (issue #27).
 *
 * Turns an uncertain or policy-sensitive conversation into a structured enquiry (source, intent, confidence,
 * permitted summary, reason). The enquiry is stored in the existing Enquiries table, where the owner sees it, and
 * the owner is notified through the existing message outbox. This path needs no hosted AI: a disabled or
 * exhausted proxy changes only the recorded state, never whether a handoff succeeds.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Human_Handoff {

	const SOURCE       = 'ai_handoff';
	const MIN_CONFIDENCE = 0.6;
	const SUMMARY_MAX  = 280;
	const RATE_LIMIT   = 3;      // Handoffs per contact per window.
	const RATE_WINDOW  = 3600;   // Seconds.

	const STATUS_READY         = 'ready';
	const STATUS_INVALID       = 'invalid';
	const STATUS_NEEDS_CONSENT = 'needs_consent';
	const STATUS_NEEDS_CONTACT = 'needs_contact';
	const STATUS_RATE_LIMITED  = 'rate_limited';

	const INTENTS = array( 'cancel', 'reschedule', 'unclear', 'other', 'booking', 'quote', 'complaint', 'payment' );
	const REASONS = array( 'low_confidence', 'policy_sensitive', 'inside_notice_window', 'customer_request', 'hosted_unavailable' );
	const PROXY_STATES = array( 'disabled', 'ready', 'exhausted' );

	/**
	 * Build a handoff. Pure: no storage, no sending. Returns status, enquiry (when ready) and errors.
	 *
	 * @param array       $input       Keys: text, intent, confidence (0..1), reason, name, email, phone, consent (true only).
	 * @param string      $proxy_state 'disabled' | 'ready' | 'exhausted'. Recorded for audit. Fails closed to 'disabled'.
	 * @param callable|null $limiter   fn( string $contact_key ): bool. Returns false when the contact is over the limit.
	 */
	public static function build( array $input, $proxy_state = 'disabled', $limiter = null ) {
		$text = isset( $input['text'] ) && is_string( $input['text'] ) ? trim( $input['text'] ) : '';
		if ( '' === $text || mb_strlen( $text ) > 4000 ) {
			return self::result( self::STATUS_INVALID, array( 'message_empty_or_too_long' ) );
		}
		$reason = isset( $input['reason'] ) && is_string( $input['reason'] ) ? $input['reason'] : '';
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return self::result( self::STATUS_INVALID, array( 'reason_not_allowed' ) );
		}
		if ( ! isset( $input['confidence'] ) || ! is_numeric( $input['confidence'] ) ) {
			return self::result( self::STATUS_INVALID, array( 'confidence_missing' ) );
		}
		$confidence = max( 0.0, min( 1.0, (float) $input['confidence'] ) );

		// Customer consent must be an explicit true. Nothing about the customer is stored without it.
		if ( ! isset( $input['consent'] ) || true !== $input['consent'] ) {
			return self::result( self::STATUS_NEEDS_CONSENT, array() );
		}

		$email = self::clean_email( isset( $input['email'] ) ? $input['email'] : '' );
		$phone = self::clean_phone( isset( $input['phone'] ) ? $input['phone'] : '' );
		if ( '' === $email && '' === $phone ) {
			return self::result( self::STATUS_NEEDS_CONTACT, array() );
		}

		$contact_key = '' !== $email ? 'email:' . $email : 'phone:' . $phone;
		if ( is_callable( $limiter ) && true !== call_user_func( $limiter, $contact_key ) ) {
			return self::result( self::STATUS_RATE_LIMITED, array() );
		}

		$intent = isset( $input['intent'] ) && is_string( $input['intent'] ) ? $input['intent'] : 'unclear';
		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			$intent = 'other';
		}

		return array(
			'status'  => self::STATUS_READY,
			'errors'  => array(),
			'enquiry' => array(
				'source'          => self::SOURCE,
				'intent'          => $intent,
				'confidence'      => round( $confidence, 2 ),
				'below_threshold' => $confidence < self::MIN_CONFIDENCE,
				'reason'          => $reason,
				'summary'         => self::permitted_summary( $text ),
				'summary_source'  => 'rules',
				'ai_proxy_state'  => in_array( $proxy_state, self::PROXY_STATES, true ) ? $proxy_state : 'disabled',
				'name'            => self::clean_name( isset( $input['name'] ) ? $input['name'] : '' ),
				'email'           => $email,
				'phone'           => $phone,
			),
		);
	}

	/**
	 * Remove contact details and links from the customer's text, then cap its length. The owner receives this
	 * summary, not the raw transcript.
	 */
	public static function permitted_summary( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}
		$text = preg_replace( '~https?://\S+|www\.\S+~i', '[link removed]', $text );
		$text = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email removed]', (string) $text );
		$text = preg_replace( '/\+?\d[\d\s().\-]{6,}\d/', '[phone removed]', (string) $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		return mb_strlen( $text ) > self::SUMMARY_MAX ? rtrim( mb_substr( $text, 0, self::SUMMARY_MAX - 3 ) ) . '...' : $text;
	}

	/**
	 * Store then notify. A failed notification leaves the enquiry in the owner's Enquiries list, so it is not lost.
	 *
	 * @param callable $store  fn( array $enquiry ): int  (0 on failure)
	 * @param callable $notify fn( array $enquiry, int $id ): bool
	 */
	public static function route( array $enquiry, $store, $notify ) {
		$id = (int) call_user_func( $store, $enquiry );
		if ( $id <= 0 ) {
			return array( 'routed' => false, 'id' => 0, 'notified' => false, 'error' => 'store_failed' );
		}
		$notified = (bool) call_user_func( $notify, $enquiry, $id );
		return array( 'routed' => true, 'id' => $id, 'notified' => $notified, 'error' => '' );
	}

	/**
	 * WordPress wiring: insert into the existing Enquiries table for the active company, then queue an owner email.
	 * Fails closed (returns 0) when no company is resolved. The structured fields are stored as a JSON envelope in
	 * the enquiry column, so no schema change is needed.
	 */
	public static function store_enquiry( array $enquiry ) {
		global $wpdb;
		if ( ! class_exists( 'TZFD_Scoped_Store' ) || ! class_exists( 'TZFD_Tables' ) ) {
			return 0;
		}
		$company = (int) TZFD_Scoped_Store::company_id();
		if ( $company <= 0 ) {
			return 0;
		}
		$envelope = wp_json_encode( array(
			'source' => $enquiry['source'], 'intent' => $enquiry['intent'], 'confidence' => $enquiry['confidence'],
			'reason' => $enquiry['reason'], 'ai_proxy_state' => $enquiry['ai_proxy_state'],
		) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			$wpdb->prefix . TZFD_Tables::ENQUIRIES,
			array(
				'company_id' => $company, 'name' => $enquiry['name'], 'email' => $enquiry['email'],
				'phone' => $enquiry['phone'], 'enquiry' => $enquiry['summary'] . "\n\n" . $envelope,
				'source' => self::SOURCE, 'received_utc' => $now, 'status' => 'new', 'created' => $now,
			)
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** WordPress wiring: owner email through the existing outbox. The contact is filterable, defaults to admin email. */
	public static function notify_owner( array $enquiry, $id ) {
		if ( ! class_exists( 'TZFD_Outbox' ) ) {
			return false;
		}
		$owner = apply_filters( 'tzfd_ai_handoff_owner_contact', get_option( 'admin_email' ), $enquiry );
		$body  = "A customer needs a person to follow up (enquiry #" . (int) $id . ", reason: " . $enquiry['reason'] . ").\n\n" .
			$enquiry['summary'];
		return '' !== TZFD_Outbox::queue( 'email', (string) $owner, $body, 0, self::SOURCE );
	}

	/** WordPress wiring: at most RATE_LIMIT handoffs per contact per RATE_WINDOW, counted atomically (TZFD_Rate_Limit). */
	public static function rate_allow( $contact_key ) {
		require_once __DIR__ . '/class-tzfd-rate-limit.php';
		return TZFD_Rate_Limit::allow( 'ah|' . (string) $contact_key, self::RATE_LIMIT, self::RATE_WINDOW );
	}

	private static function result( $status, array $errors ) {
		return array( 'status' => $status, 'errors' => $errors, 'enquiry' => null );
	}

	private static function clean_email( $raw ) {
		$raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		return filter_var( $raw, FILTER_VALIDATE_EMAIL ) ? $raw : '';
	}

	private static function clean_phone( $raw ) {
		$raw = is_string( $raw ) ? preg_replace( '/[^0-9+]/', '', $raw ) : '';
		$digits = (string) preg_replace( '/\D/', '', (string) $raw );
		return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? $raw : '';
	}

	private static function clean_name( $raw ) {
		$raw = is_string( $raw ) ? trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $raw ) ) : '';
		return mb_substr( $raw, 0, 190 );
	}
}
