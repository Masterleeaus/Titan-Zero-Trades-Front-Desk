<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared rules for the Free AI lanes (issues #35, #36, #39, #40, #41).
 *
 * - Consent is off by default. Nothing reaches a hosted service until the administrator grants it.
 * - Hosted calls go only through the filter below. The connector (#16, #4, #43) supplies the callback.
 *   Without a callback every call returns connector_unavailable and the local path carries on.
 * - Payloads are minimised here, for every caller: private keys are dropped and contact details are scrubbed.
 * - Hosted output is untrusted. Callers validate it again before storing or showing it.
 */
final class TZFD_Ai_Free_Core {
	const CONSENT_OPTION           = 'tz_fd_ai_consent';
	const HOSTED_FILTER            = 'tzfd_ai_hosted_request';
	const CONSENT_WITHDRAWN_ACTION = 'tzfd_ai_consent_withdrawn';
	const SCHEMA_VERSION           = 1;

	/** Operations the Free lanes may request. Anything else is refused before the filter runs. */
	const OPERATIONS = array( 'faq.weekly', 'knowledge.improve', 'insights.summary' );

	/** Keys removed from any hosted payload, at any depth. */
	const FORBIDDEN_KEYS = array(
		'name', 'email', 'phone', 'mobile', 'address', 'ip', 'ipaddr', 'customer', 'customer_name',
		'message', 'enquiry', 'transcript', 'raw', 'notes', 'password', 'token', 'secret', 'api_key',
	);

	public static function consent_granted() {
		$c = get_option( self::CONSENT_OPTION, array() );
		return is_array( $c ) && ! empty( $c['granted'] );
	}

	/** Granting stores consent. Withdrawing stores the refusal and fires the purge action for the lanes. */
	public static function set_consent( $granted, $now ) {
		$granted = (bool) $granted;
		update_option( self::CONSENT_OPTION, array( 'granted' => $granted, 'at' => (int) $now ), false );
		if ( ! $granted ) {
			do_action( 'tzfd_ai_consent_withdrawn' );
		}
		return $granted;
	}

	/**
	 * One hosted request. Returns array( 'ok' => true, 'data' => array, 'usage' => array ) or
	 * array( 'ok' => false, 'error' => string ). The connector must echo the company_id it served.
	 */
	public static function hosted_call( $operation, array $payload, $company_id ) {
		if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
			return self::fail( 'operation_not_allowed' );
		}
		if ( ! self::consent_granted() ) {
			return self::fail( 'consent_required' );
		}
		$company_id = (int) $company_id;
		if ( $company_id < 1 ) {
			return self::fail( 'company_required' );
		}
		$envelope = array(
			'schema_version' => self::SCHEMA_VERSION,
			'operation'      => $operation,
			'company_id'     => $company_id,
			'payload'        => self::minimise( $payload ),
		);
		$result = apply_filters( 'tzfd_ai_hosted_request', null, $envelope );
		if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
			return self::fail( is_array( $result ) && isset( $result['error'] ) && is_string( $result['error'] )
				? $result['error'] : 'connector_unavailable' );
		}
		if ( ! isset( $result['company_id'] ) || (int) $result['company_id'] !== $company_id ) {
			return self::fail( 'owner_scope_mismatch' );
		}
		$usage = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array();
		return array(
			'ok'    => true,
			'data'  => isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array(),
			'usage' => array(
				'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
			),
		);
	}

	/** Removes private keys at any depth and scrubs contact details from strings. */
	public static function minimise( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && in_array( strtolower( $k ), self::FORBIDDEN_KEYS, true ) ) {
					continue;
				}
				$out[ $k ] = self::minimise( $v );
			}
			return $out;
		}
		return is_string( $value ) ? self::scrub( $value ) : $value;
	}

	/** Replaces emails, links and phone-length digit runs with neutral markers. */
	public static function scrub( $text ) {
		$t = (string) $text;
		$t = preg_replace( '/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[contact removed]', $t );
		$t = preg_replace( '#https?://\S+#i', '[link removed]', $t );
		$t = preg_replace( '/(?<![\w.])\+?(?=(?:\D*\d){8,})[\d\s().-]{8,}/', '[number removed]', $t );
		return trim( preg_replace( '/\s+/', ' ', $t ) );
	}

	/** Plain text only: tags and angle brackets removed, whitespace collapsed, length capped. */
	public static function plain_text( $text, $max ) {
		$t = wp_strip_all_tags( (string) $text );
		$t = str_replace( array( '<', '>' ), '', $t );
		$t = trim( preg_replace( '/\s+/u', ' ', $t ) );
		return mb_substr( $t, 0, (int) $max );
	}

	/**
	 * Figures in $text (numbers, prices, times) that appear in none of the source texts.
	 * An empty result means every figure is grounded. Used to stop invented prices, hours or counts.
	 */
	public static function ungrounded_figures( $text, array $source_texts ) {
		preg_match_all( '/\$?\d[\d,.]*(?::\d{2})?/', (string) $text, $m );
		$haystack = str_replace( ',', '', implode( ' ', $source_texts ) );
		$missing  = array();
		foreach ( array_unique( $m[0] ) as $figure ) {
			$needle = rtrim( str_replace( ',', '', $figure ), '.,' );
			if ( '' === $needle ) {
				continue;
			}
			// Whole-figure match: "$5" must not be grounded by "$50" in a source.
			$pattern = '/(?<![\d.])' . preg_quote( $needle, '/' ) . '(?![\d])/';
			if ( ! preg_match( $pattern, $haystack ) ) {
				$missing[] = $figure;
			}
		}
		return $missing;
	}

	/** ISO week of a timestamp, for example 2026-W46. */
	public static function week_key( $ts ) { return gmdate( 'o-\WW', (int) $ts ); }

	public static function month_key( $ts ) { return gmdate( 'Y-m', (int) $ts ); }

	public static function day_key( $ts ) { return gmdate( 'Y-m-d', (int) $ts ); }

	public static function fail( $error ) {
		return array( 'ok' => false, 'error' => (string) $error );
	}
}
