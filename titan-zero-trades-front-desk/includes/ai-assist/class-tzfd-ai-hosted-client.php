<?php
/**
 * Client-side contract for the optional hosted AI connector.
 *
 * Only the single optional proxy/connector may implement this interface (see docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md).
 * Free booking never depends on it. The default implementation below refuses every call, so no live service is reached.
 * Every result is an array: ok (bool), text (string when ok), reason (string code).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-ai-locale.php';

interface TZFD_AI_Hosted_Client {
    /** Translate approved content only. Returns ok, text, reason. */
    public function translate( $text, $from_locale, $to_locale );

    /** Generate a short draft from approved facts and quoted, untrusted customer text. Returns ok, text, reason. */
    public function draft( $purpose, array $approved_facts, $quoted_customer_text );
}

/**
 * Client that routes through the single consent-gated gate, TZFD_Hosted_AI_Connector::request(). Without recorded
 * consent, a registered connector, or the gate class itself, it reports service_unavailable and makes no network call.
 */
final class TZFD_AI_Hosted_Gateway implements TZFD_AI_Hosted_Client {
    /** Only approved English text and two language tags leave the site. Anything else is refused before the gate. */
    public function translate( $text, $from_locale, $to_locale ) {
        $clean_text = TZFD_AI_Locale::clean_text( (string) $text );
        $from       = TZFD_AI_Locale::normalise_tag( $from_locale );
        $to         = TZFD_AI_Locale::normalise_tag( $to_locale );
        if ( null === $clean_text || null === $from || null === $to ) {
            return array( 'ok' => false, 'reason' => 'invalid_input' );
        }
        return self::call( 'translate', array( 'text' => $clean_text, 'from_locale' => $from, 'to_locale' => $to ) );
    }

    public function draft( $purpose, array $approved_facts, $quoted_customer_text ) {
        return self::call( 'draft', array( 'purpose' => (string) $purpose, 'facts' => $approved_facts, 'quoted' => (string) $quoted_customer_text ) );
    }

    private static function call( $purpose, array $payload ) {
        if ( ! class_exists( 'TZFD_Hosted_AI_Connector' ) ) {
            return array( 'ok' => false, 'reason' => 'service_unavailable' );
        }
        $response = TZFD_Hosted_AI_Connector::request( 'free_ai', array_merge( array( 'task' => $purpose ), $payload ) );
        if ( is_wp_error( $response ) ) {
            $code = preg_replace( '/^tzfd_hosted_ai_/', '', (string) $response->get_error_code() );
            return array( 'ok' => false, 'reason' => ( '' !== $code ) ? $code : 'service_unavailable' );
        }
        if ( is_array( $response ) && ! empty( $response['ok'] ) && isset( $response['text'] ) && is_string( $response['text'] ) ) {
            return array( 'ok' => true, 'text' => $response['text'] );
        }
        $reason = ( is_array( $response ) && isset( $response['reason'] ) && is_string( $response['reason'] ) ) ? $response['reason'] : 'service_unavailable';
        return array( 'ok' => false, 'reason' => $reason );
    }
}

/** Default client: the hosted service is not connected. Every call reports why nothing was produced. */
final class TZFD_AI_Hosted_Unavailable implements TZFD_AI_Hosted_Client {
    public function translate( $text, $from_locale, $to_locale ) {
        return array( 'ok' => false, 'reason' => 'service_unavailable' );
    }

    public function draft( $purpose, array $approved_facts, $quoted_customer_text ) {
        return array( 'ok' => false, 'reason' => 'service_unavailable' );
    }
}
