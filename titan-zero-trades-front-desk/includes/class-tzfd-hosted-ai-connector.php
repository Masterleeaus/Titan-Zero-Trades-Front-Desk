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
    const CAPABILITIES = array( 'free_ai', 'assistant', 'agent_team' );

    /**
     * Owner decision (#330, 2026-11-18): hosted AI content is capped. Each text value may hold at most this many
     * characters, and the encoded payload at most MAX_PAYLOAD_BYTES. Oversized requests are refused, never truncated.
     * Local rules, the knowledge store and ordinary bookings are not capped. The 2000 matches TZFD_AI_Locale::MAX_TEXT_CHARS.
     */
    const MAX_CONTENT_CHARS = 2000;
    const MAX_PAYLOAD_BYTES = 8192;

    /** Consent is recorded by an administrator in the AI Services screen. Nothing is stored by default. */
    public static function consent_granted() {
        return 'granted' === get_option( self::CONSENT, '' );
    }

    /** The connector object, or null. The free build registers none. */
    public static function connector() {
        $connector = apply_filters( 'tzfd_hosted_ai_connector', null );
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
        $too_big = self::content_size_error( $payload );
        if ( null !== $too_big ) {
            return $too_big;
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

    /**
     * Returns null when the payload is within the content cap, otherwise a WP_Error. Pure: no WordPress calls
     * except wp_json_encode(). Nothing is sent or truncated when the cap is exceeded.
     *
     * @param array $payload Data about to leave the site.
     * @return WP_Error|null
     */
    public static function content_size_error( array $payload ) {
        $too_big = __( 'This message is too long to send to hosted AI. Shorten it and try again.', 'titan-zero-trades-front-desk' );
        if ( ! self::text_within_limit( $payload ) ) {
            return new WP_Error( 'tzfd_hosted_ai_content_too_large', $too_big );
        }
        $json = wp_json_encode( $payload );
        if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
            return new WP_Error( 'tzfd_hosted_ai_content_too_large', $too_big );
        }
        return null;
    }

    /** True when every string, at any depth, holds at most MAX_CONTENT_CHARS characters. */
    private static function text_within_limit( $value ) {
        if ( is_string( $value ) ) {
            return mb_strlen( $value, 'UTF-8' ) <= self::MAX_CONTENT_CHARS;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( ! self::text_within_limit( $item ) ) {
                    return false;
                }
            }
        }
        return true;
    }
}
