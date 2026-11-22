<?php
/**
 * Pairing with the hosted AI service (issue #347).
 *
 * Owner decisions recorded on #347: the pairing endpoint is the one recorded on #456
 * (https://central.titanzero.io/api/tzfd/pair), the site owner enters a pairing code shown by
 * Supercharged, and the per-site secret is issued by the hosted service at pairing.
 *
 * Rules:
 * - The endpoint comes only from the constant TZFD_AI_PAIRING_ENDPOINT or the filter tzfd_ai_pairing_endpoint.
 *   It is off by default: with neither set, nothing is sent. Only https URLs with a host and no userinfo are used.
 * - The pairing code is single use. It is sent once, in the JSON body over HTTPS, and is never stored,
 *   logged, echoed or returned.
 * - The response must echo the contract, the site id and the nonce, carry a 64 lowercase hex secret, and
 *   name a feature endpoint on the same host as the pairing endpoint. Anything else is refused and the
 *   existing connection is left unchanged.
 * - A successful pairing stores the secret and feature endpoint through TZFD_AI_Connector::configure() and
 *   revokes consent, so data never goes to a new endpoint on old consent.
 *
 * Request (POST, JSON body):    {"contract":"tzfd-pair/1","site":"<site id>","nonce":"<hex>","code":"<pairing code>"}
 * Response (JSON body, 200):    {"contract":"tzfd-pair/1","site":"<site id>","nonce":"<hex>","secret":"<64 hex>","feature_endpoint":"https://..."}
 *
 * See docs/AI-SERVICE-CONNECTOR-CONTRACT.md, section "Pairing".
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Pairing {

    const CONTRACT        = 'tzfd-pair/1';
    const ENDPOINT_CONST  = 'TZFD_AI_PAIRING_ENDPOINT';
    const ENDPOINT_FILTER = 'tzfd_ai_pairing_endpoint';
    const TRANSPORT_FILTER = 'tzfd_ai_pairing_transport';
    const SITE_OPTION     = 'tzfd_ai_pair_site_id';
    const LOCK_OPTION     = 'tzfd_ai_pair_lock';
    const LOCK_STALE      = 30;     // seconds before a crashed pairing lock may be taken over
    const TIMEOUT         = 8;      // seconds
    const MAX_REQUEST_BYTES  = 1024;
    const MAX_RESPONSE_BYTES = 16384;
    const CODE_PATTERN    = '/^[A-Za-z0-9-]{6,64}$/D';
    const SECRET_PATTERN  = '/^[0-9a-f]{64}$/D';
    const SITE_PATTERN    = '/^[A-Za-z0-9_.-]{1,64}$/D';

    /**
     * The https pairing endpoint, or '' when pairing is off. Constant first, then filter.
     */
    public static function endpoint() {
        $raw = '';
        if ( defined( self::ENDPOINT_CONST ) ) {
            $raw = constant( self::ENDPOINT_CONST );
        } elseif ( function_exists( 'apply_filters' ) ) {
            $raw = apply_filters( 'tzfd_ai_pairing_endpoint', '' );
        }
        return self::valid_https_url( $raw ) ? (string) $raw : '';
    }

    /**
     * Exchanges a single-use pairing code for a per-site secret.
     *
     * @return array { status: paired|pairing_off|invalid_code|busy|offline|rejected|invalid_response, ... }
     *               Never contains the code, the secret or the response body.
     */
    public static function pair( $code ) {
        $endpoint = self::endpoint();
        if ( '' === $endpoint ) { return self::result( 'pairing_off' ); }
        if ( ! is_string( $code ) || 1 !== preg_match( self::CODE_PATTERN, $code ) ) { return self::result( 'invalid_code' ); }
        if ( ! self::lock() ) { return self::result( 'busy' ); }

        try {
            return self::exchange( $endpoint, $code );
        } finally {
            self::unlock();
        }
    }

    private static function exchange( $endpoint, $code ) {
        $site_id = self::site_id();
        if ( '' === $site_id ) { return self::result( 'invalid_response' ); }

        $nonce = bin2hex( random_bytes( 16 ) );
        $body  = self::encode( array(
            'contract' => self::CONTRACT,
            'site'     => $site_id,
            'nonce'    => $nonce,
            'code'     => $code,
        ) );
        if ( ! is_string( $body ) || strlen( $body ) > self::MAX_REQUEST_BYTES ) { return self::result( 'invalid_code' ); }

        $request = array(
            'url'         => $endpoint,
            'method'      => 'POST',
            'headers'     => array( 'Content-Type' => 'application/json', 'X-TZFD-Contract' => self::CONTRACT ),
            'body'        => $body,
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
        );

        try {
            $raw = self::transport( $request );
        } catch ( Throwable $e ) {
            return self::result( 'offline' );
        }
        if ( ! is_array( $raw ) || isset( $raw['error'] ) ) { return self::result( 'offline' ); }
        if ( 200 !== (int) ( $raw['status_code'] ?? 0 ) ) { return self::result( 'rejected', array( 'http_status' => (int) ( $raw['status_code'] ?? 0 ) ) ); }

        $response_body = isset( $raw['body'] ) && is_string( $raw['body'] ) ? $raw['body'] : '';
        if ( strlen( $response_body ) > self::MAX_RESPONSE_BYTES ) { return self::result( 'invalid_response' ); }
        $data = json_decode( $response_body, true );
        if ( ! is_array( $data ) ) { return self::result( 'invalid_response' ); }

        if ( ! isset( $data['contract'] ) || self::CONTRACT !== $data['contract'] ) { return self::result( 'invalid_response' ); }
        if ( ! isset( $data['site'] ) || ! is_string( $data['site'] ) || ! hash_equals( $site_id, $data['site'] ) ) { return self::result( 'invalid_response' ); }
        if ( ! isset( $data['nonce'] ) || ! is_string( $data['nonce'] ) || ! hash_equals( $nonce, $data['nonce'] ) ) { return self::result( 'invalid_response' ); }
        if ( ! isset( $data['secret'] ) || ! is_string( $data['secret'] ) || 1 !== preg_match( self::SECRET_PATTERN, $data['secret'] ) ) { return self::result( 'invalid_response' ); }

        $feature = isset( $data['feature_endpoint'] ) && is_string( $data['feature_endpoint'] ) ? $data['feature_endpoint'] : '';
        if ( ! self::valid_https_url( $feature ) || ! self::same_host( $endpoint, $feature ) ) { return self::result( 'invalid_response' ); }

        // configure() validates the endpoint, site id and secret before it writes anything, so a refusal keeps the old connection.
        $saved = TZFD_AI_Connector::configure( $feature, $site_id, $data['secret'] );
        $data  = array();
        if ( ! $saved ) { return self::result( 'invalid_response' ); }

        TZFD_AI_Connector::revoke_consent(); // rotation: consent must be granted again for the new pairing
        return self::result( 'paired' );
    }

    /** Stable per-site id, created once and kept across re-pairing. */
    public static function site_id() {
        $id = get_option( self::SITE_OPTION, '' );
        if ( is_string( $id ) && 1 === preg_match( self::SITE_PATTERN, $id ) ) { return $id; }
        $id = bin2hex( random_bytes( 16 ) );
        update_option( self::SITE_OPTION, $id, false );
        return $id;
    }

    /** https, with a host, and no userinfo. */
    public static function valid_https_url( $url ) {
        if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 ) { return false; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure-PHP suite runs without WordPress; the checks below are the same either way.
        $parts = parse_url( $url );
        return is_array( $parts )
            && isset( $parts['scheme'], $parts['host'] )
            && 'https' === strtolower( $parts['scheme'] )
            && '' !== $parts['host']
            && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
    }

    private static function same_host( $a, $b ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see valid_https_url().
        $pa = parse_url( $a );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see valid_https_url().
        $pb = parse_url( $b );
        return is_array( $pa ) && is_array( $pb ) && strtolower( $pa['host'] ) === strtolower( $pb['host'] );
    }

    private static function transport( array $request ) {
        $raw = function_exists( 'apply_filters' ) ? apply_filters( 'tzfd_ai_pairing_transport', null, $request ) : null;
        if ( null !== $raw ) { return $raw; }
        if ( ! function_exists( 'wp_remote_post' ) ) { return array( 'error' => 'offline' ); }
        $response = wp_remote_post( $request['url'], array(
            'timeout'     => $request['timeout'],
            'headers'     => $request['headers'],
            'body'        => $request['body'],
            'redirection' => 0,
        ) );
        if ( is_wp_error( $response ) ) { return array( 'error' => 'offline' ); }
        return array(
            'status_code' => (int) wp_remote_retrieve_response_code( $response ),
            'body'        => (string) wp_remote_retrieve_body( $response ),
        );
    }

    /** Atomic lock on a unique option row. A lock older than LOCK_STALE seconds is taken over. */
    private static function lock() {
        if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) { return true; }
        $taken = (int) get_option( self::LOCK_OPTION, 0 );
        if ( time() - $taken <= self::LOCK_STALE ) { return false; }
        delete_option( self::LOCK_OPTION );
        return (bool) add_option( self::LOCK_OPTION, time(), '', 'no' );
    }

    private static function unlock() {
        delete_option( self::LOCK_OPTION );
    }

    /** JSON encoding that works with or without WordPress loaded. */
    private static function encode( $value ) {
        return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
    }

    private static function result( $status, array $extra = array() ) {
        return array( 'status' => $status ) + $extra;
    }
}
