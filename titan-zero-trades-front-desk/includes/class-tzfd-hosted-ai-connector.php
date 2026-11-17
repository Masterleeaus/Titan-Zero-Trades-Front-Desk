<?php
/**
 * The single optional hosted AI connector (contract stub).
 *
 * Every hosted AI request must pass through request(). The free build ships no connector, no transport and no
 * credentials, so every call returns a WP_Error and no network request is made. A connection exists only when:
 *   1. the administrator has recorded consent (option tzfd_hosted_ai_consent = 'granted'), and
 *   2. a connector object is registered through the tzfd_hosted_ai_connector filter.
 * The connector object must expose send( string $capability, array $payload ) and return an array or WP_Error.
 *
 * Contract identifier: tzfd-hosted-ai-connector/1. Capabilities follow docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Hosted_AI_Connector {
    const CONTRACT     = 'tzfd-hosted-ai-connector/1';
    const CONSENT      = 'tzfd_hosted_ai_consent';
    const FILTER       = 'tzfd_hosted_ai_connector';
    const CAPABILITIES = array( 'free_ai', 'assistant', 'agent_team', 'translate' );

    /** Consent is recorded by an administrator in the AI Services screen. Nothing is stored by default. */
    public static function consent_granted() {
        return 'granted' === get_option( self::CONSENT, '' );
    }

    /** The connector object, or null. The free build registers none. */
    public static function connector() {
        $connector = apply_filters( self::FILTER, null );
        return is_object( $connector ) && method_exists( $connector, 'send' ) ? $connector : null;
    }

    /** True only with recorded consent and a registered connector. */
    public static function is_available() {
        return self::consent_granted() && null !== self::connector();
    }

    /**
     * Single gate for every hosted AI request. Returns the connector's array response, or WP_Error.
     *
     * @param string $capability One of CAPABILITIES.
     * @param array  $payload    Data the administrator consented to send. Never contains stored credentials.
     * @return array|WP_Error
     */
    public static function request( $capability, array $payload = array() ) {
        if ( ! in_array( $capability, self::CAPABILITIES, true ) ) {
            return new WP_Error( 'tzfd_hosted_ai_unknown_capability', __( 'This hosted AI capability is not recognised.', 'titan-zero-trades-front-desk' ) );
        }
        if ( ! self::consent_granted() ) {
            return new WP_Error( 'tzfd_hosted_ai_no_consent', __( 'Hosted AI is off until you connect it and allow it.', 'titan-zero-trades-front-desk' ) );
        }
        $connector = self::connector();
        if ( null === $connector ) {
            return new WP_Error( 'tzfd_hosted_ai_not_connected', __( 'No hosted AI connection is set up in this version.', 'titan-zero-trades-front-desk' ) );
        }
        $response = $connector->send( $capability, $payload );
        return is_wp_error( $response ) || is_array( $response ) ? $response : new WP_Error( 'tzfd_hosted_ai_bad_response', __( 'The hosted AI connection returned an unexpected response.', 'titan-zero-trades-front-desk' ) );
    }
}
