<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-free-core.php';

/**
 * AI-guided setup helpers for the first-run setup (issue #40).
 *
 * - Deterministic and offline. Booking and setup never need AI, a key or a connector.
 * - Suggestions come only from values the administrator entered (business name, service names).
 *   Hours, prices and other facts are never invented. Those fields are reported as missing.
 * - Every suggestion needs confirmation. Nothing is written by this class.
 * - Existing values are kept: a suggestion is made only for a field that is empty, or for an FAQ or tag
 *   that does not already exist. That is what keeps upgraded sites safe.
 * - "required" in missing() means required before AI can be enabled. Booking never depends on these items.
 * - AI can be enabled only when every required item is present. Consent is recorded through the core and is off by default.
 */
final class TZFD_Ai_Setup_Check {
	const MAX_SERVICE_FAQS = 10;
	const MAX_TAGS         = 8;

	/**
	 * State keys: business_name, greeting, services (array of names), hours_configured (bool),
	 * faq_questions (array), tags (array), connector_available (bool).
	 * Returns the items still missing. Each item: key, label, required (bool), hint.
	 */
	public static function missing( array $state ) {
		$out = array();
		if ( '' === trim( (string) ( $state['business_name'] ?? '' ) ) ) {
			$out[] = self::item( 'business_name', __( 'Business name', 'titan-zero-trades-front-desk' ), true, __( 'Enter the name customers should see.', 'titan-zero-trades-front-desk' ) );
		}
		if ( empty( $state['services'] ) ) {
			$out[] = self::item( 'services', __( 'Services', 'titan-zero-trades-front-desk' ), true, __( 'Add the services you offer. Nothing is added for you.', 'titan-zero-trades-front-desk' ) );
		}
		if ( empty( $state['hours_configured'] ) ) {
			$out[] = self::item( 'hours', __( 'Opening hours', 'titan-zero-trades-front-desk' ), true, __( 'Set your own hours. None are assumed.', 'titan-zero-trades-front-desk' ) );
		}
		if ( empty( $state['connector_available'] ) ) {
			$out[] = self::item( 'hosted_connector', __( 'Hosted AI connection', 'titan-zero-trades-front-desk' ), false, __( 'Optional. Booking and rules-based FAQs work without it.', 'titan-zero-trades-front-desk' ) );
		}
		if ( '' === trim( (string) ( $state['greeting'] ?? '' ) ) ) {
			$out[] = self::item( 'greeting', __( 'Greeting', 'titan-zero-trades-front-desk' ), false, __( 'A suggested greeting is available.', 'titan-zero-trades-front-desk' ) );
		}
		return $out;
	}

	/**
	 * Question text from saved FAQ entries, for the duplicate check in suggestions(). Reads only; nothing is written.
	 * Entries without a usable question are skipped.
	 */
	public static function faq_questions_from_entries( array $entries ) {
		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['question'] ) || ! is_string( $entry['question'] ) ) { continue; }
			if ( '' !== trim( $entry['question'] ) ) { $out[] = $entry['question']; }
		}
		return $out;
	}

	/**
	 * AI can be enabled only when every required item is present. The hosted connector is optional (owner decision
	 * on #400, recorded 2026-11-22): it is listed in missing() but never closes the gate, so the free plugin works without it.
	 */
	public static function ai_gate( array $state ) {
		$required = array_filter( self::missing( $state ), static function ( $i ) { return $i['required']; } );
		$keys = array_map( static function ( $i ) { return $i['key']; }, $required );
		return array( 'allowed' => empty( $keys ), 'missing' => array_values( array_unique( $keys ) ) );
	}

	/** Suggestions to review. Each one needs confirmation before it is written. */
	public static function suggestions( array $state ) {
		$out   = array();
		$name  = TZFD_Ai_Free_Core::plain_text( $state['business_name'] ?? '', 120 );
		$services = array();
		foreach ( (array) ( $state['services'] ?? array() ) as $s ) {
			$s = TZFD_Ai_Free_Core::plain_text( $s, 120 );
			if ( '' !== $s ) { $services[] = $s; }
		}
		$services = array_values( array_unique( $services ) );

		if ( '' === trim( (string) ( $state['greeting'] ?? '' ) ) && '' !== $name ) {
			$out[] = self::suggestion( 'greeting', 'Hello, thanks for contacting ' . $name . '. How can we help you today?', 'business_name' );
		}

		$existing = array();
		foreach ( (array) ( $state['faq_questions'] ?? array() ) as $q ) {
			$existing[ strtolower( trim( (string) $q ) ) ] = true;
		}
		$n = 0;
		foreach ( $services as $svc ) {
			if ( $n >= self::MAX_SERVICE_FAQS ) { break; }
			$question = 'Do you offer ' . $svc . '?';
			if ( isset( $existing[ strtolower( $question ) ] ) ) { continue; }
			$out[] = self::suggestion( 'faq', array(
				'question' => $question,
				'answer'   => 'Yes, we offer ' . $svc . '. Please contact us for details.',
			), 'service:' . $svc );
			$n++;
		}

		$have_tags = array();
		foreach ( (array) ( $state['tags'] ?? array() ) as $t ) {
			$have_tags[ strtolower( (string) $t ) ] = true;
		}
		$tags = array();
		foreach ( $services as $svc ) {
			$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $svc ) ), '-' );
			if ( '' !== $slug && ! isset( $have_tags[ $slug ] ) && ! in_array( $slug, $tags, true ) ) {
				$tags[] = $slug;
			}
		}
		if ( ! empty( $tags ) ) {
			$out[] = self::suggestion( 'tags', array_slice( $tags, 0, self::MAX_TAGS ), 'services' );
		}
		return $out;
	}

	/**
	 * Confirms one suggestion with the administrator's edits. Returns array( 'ok', 'value' ).
	 * Requires confirmed = true. FAQ answers must not state figures the source does not contain.
	 */
	public static function confirm( array $suggestion, array $edits ) {
		if ( empty( $edits['confirmed'] ) ) {
			return TZFD_Ai_Free_Core::fail( 'admin_confirmation_required' );
		}
		$type = $suggestion['type'] ?? '';
		$src  = (string) ( $suggestion['source'] ?? '' );
		if ( 'faq' === $type ) {
			$q = TZFD_Ai_Free_Core::plain_text( $edits['question'] ?? $suggestion['value']['question'], 200 );
			$a = TZFD_Ai_Free_Core::plain_text( $edits['answer'] ?? $suggestion['value']['answer'], 600 );
			if ( strlen( $q ) < 8 || strlen( $a ) < 8 ) { return TZFD_Ai_Free_Core::fail( 'too_short' ); }
			$source_text = substr( $src, 0, 8 ) === 'service:' ? substr( $src, 8 ) : '';
			if ( ! empty( TZFD_Ai_Free_Core::ungrounded_figures( $a, array( $q, $source_text ) ) ) ) {
				return TZFD_Ai_Free_Core::fail( 'ungrounded_figures' );
			}
			return array( 'ok' => true, 'value' => array( 'question' => $q, 'answer' => $a ) );
		}
		if ( 'greeting' === $type ) {
			$g = TZFD_Ai_Free_Core::plain_text( $edits['value'] ?? $suggestion['value'], 300 );
			return '' === $g ? TZFD_Ai_Free_Core::fail( 'empty' ) : array( 'ok' => true, 'value' => $g );
		}
		if ( 'tags' === $type ) {
			$list = is_array( $edits['value'] ?? null ) ? $edits['value'] : (array) $suggestion['value'];
			$clean = array();
			foreach ( $list as $t ) {
				$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' );
				if ( '' !== $slug && ! in_array( $slug, $clean, true ) ) { $clean[] = $slug; }
			}
			return array( 'ok' => true, 'value' => array_slice( $clean, 0, self::MAX_TAGS ) );
		}
		return TZFD_Ai_Free_Core::fail( 'unknown_type' );
	}

	/**
	 * Turns AI on only after the gate passes and the administrator confirms.
	 * Returns array( 'ok' => true ) or array( 'ok' => false, 'error', 'missing' ).
	 */
	public static function enable_ai( array $state, $admin_confirmed, $now ) {
		$gate = self::ai_gate( $state );
		if ( ! $gate['allowed'] ) {
			return array( 'ok' => false, 'error' => 'missing_configuration', 'missing' => $gate['missing'] );
		}
		if ( ! $admin_confirmed ) {
			return TZFD_Ai_Free_Core::fail( 'admin_confirmation_required' );
		}
		TZFD_Ai_Free_Core::set_consent( true, $now );
		return array( 'ok' => true, 'missing' => array() );
	}

	/**
	 * Read-only review for the setup screen: what is missing, the gate result and the suggestions.
	 * Nothing here is written. Each suggestion still needs administrator confirmation through confirm().
	 */
	public static function review( array $state ) {
		return array(
			'missing'     => self::missing( $state ),
			'gate'        => self::ai_gate( $state ),
			'suggestions' => self::suggestions( $state ),
		);
	}

	/** Plain-text line for one suggestion, for the review list. Shows only text that the suggestion already holds. */
	public static function describe( array $suggestion ) {
		$type  = $suggestion['type'] ?? '';
		$value = $suggestion['value'] ?? '';
		if ( 'faq' === $type && is_array( $value ) ) {
			return 'FAQ: ' . (string) ( $value['question'] ?? '' );
		}
		if ( 'tags' === $type && is_array( $value ) ) {
			return 'Tags: ' . implode( ', ', array_map( 'strval', $value ) );
		}
		if ( 'greeting' === $type ) {
			return 'Greeting: ' . (string) $value;
		}
		return '';
	}

	private static function item( $key, $label, $required, $hint ) {
		return array( 'key' => $key, 'label' => $label, 'required' => (bool) $required, 'hint' => $hint );
	}

	private static function suggestion( $type, $value, $source ) {
		return array( 'id' => substr( hash( 'sha256', $type . '|' . $source . '|' . wp_json_encode( $value ) ), 0, 12 ),
			'type' => $type, 'value' => $value, 'source' => $source, 'needs_confirmation' => true );
	}
}
