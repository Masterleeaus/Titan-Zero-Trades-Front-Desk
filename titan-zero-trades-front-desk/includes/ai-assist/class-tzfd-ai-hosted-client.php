<?php
/**
 * Client-side contract for the optional hosted AI connector.
 *
 * Only the single optional proxy/connector may implement this interface (see docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md).
 * Free booking never depends on it. The default implementation below refuses every call, so no live service is reached.
 * Every result is an array: ok (bool), text (string when ok), reason (string code).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface TZFD_AI_Hosted_Client {
    /** Translate approved content only. Returns ok, text, reason. */
    public function translate( $text, $from_locale, $to_locale );

    /** Generate a short draft from approved facts and quoted, untrusted customer text. Returns ok, text, reason. */
    public function draft( $purpose, array $approved_facts, $quoted_customer_text );
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
