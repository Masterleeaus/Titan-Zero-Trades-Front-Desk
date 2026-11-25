<?php
/**
 * Pairing with the hosted AI service (issue #347).
 *
 * Owner decisions recorded on #347: the pairing endpoint is https://central.titanzero.io/api/tzfd/pair (#456),
 * the site owner enters a pairing code shown by Supercharged, and the per-site secret is delivered out of
 * band through the secret store, provisioned by the Central operator. It is never in the pairing response.
 *
 * Rules:
 * - The endpoint comes only from the constant TZFD_AI_PAIRING_ENDPOINT or the filter tzfd_ai_pairing_endpoint.
 *   It is off by default: with neither set, nothing is sent. Only https URLs with a host and no userinfo are used.
 * - The per-site secret is read from the secret store (TZFD_Central_Tenant::provisioned_secret()) and is never
 *   sent, returned or logged. Without it, nothing is sent.
 * - The pairing code is single use. It is sent once, in the JSON body over HTTPS, and is never stored,
 *   logged, echoed or returned.
 * - The response body carries exactly the eight signed fields of central-pairing/1 and nothing else: the seven
 *   assertion fields plus feature_endpoint, the AI endpoint. It is signed with HMAC-SHA256 in the X-TZFD-Timestamp,
 *   X-TZFD-Nonce and X-TZFD-Signature headers, so the endpoint is covered by the same signature. The signature and
 *   every field are checked by TZFD_Central_Tenant::connect(). The nonce must echo the request. The feature endpoint
 *   must be https with an exact host in feature_hosts() (default superchargedat.work, filter tzfd_ai_feature_hosts).
 *   Subdomains, lookalike domains, http, a port or userinfo are refused. It is then set as the AI connector endpoint.
 * - A refused or failed exchange leaves the existing pairing and credential unchanged.
 *
 * Request (POST, JSON body):    {"contract":"central-pairing/1","site":"<site id>","nonce":"<32 hex>","code":"<pairing code>"}
 * Response (JSON body, 200):    {"contract":"central-pairing/1","site":"<site id>","company_uuid":"<uuid>","operations":["..."],"nonce":"<32 hex>","issued_at":<int>,"expires_at":<int>,"feature_endpoint":"https://..."}
 * Response headers:             X-TZFD-Timestamp, X-TZFD-Nonce, X-TZFD-Signature (sha256=<hex>)
 *
 * See docs/AI-SERVICE-CONNECTOR-CONTRACT.md, section "Pairing", and docs/CENTRAL-COMPANY-PAIRING.md.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Pairing {

    const CONTRACT        = 'central-pairing/1';
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
    const FEATURE_HOSTS_FILTER = 'tzfd_ai_feature_hosts';
    const FEATURE_HOST_DEFAULT = 'superchargedat.work';

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
     * @return array { status: paired|pairing_off|invalid_code|secret_missing|busy|offline|rejected|invalid_response, ... }
     *               Never contains the code, the secret, the response body or the signature.
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

        $secret = TZFD_Central_Tenant::provisioned_secret();
        if ( '' === $secret ) { return self::result( 'secret_missing' ); }

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

        // Exactly the eight signed fields: a missing field, an extra field (including any secret) or a non-object body is refused.
        $fields = TZFD_Central_Tenant::assertion_fields();
        if ( array() !== array_diff( array_keys( $data ), $fields ) || array() !== array_diff( $fields, array_keys( $data ) ) ) {
            return self::result( 'invalid_response', array( 'reason' => 'fields_mismatch' ) );
        }

        $headers = isset( $raw['headers'] ) && is_array( $raw['headers'] ) ? $raw['headers'] : array();
        $echo    = '';
        foreach ( $headers as $name => $value ) {
            if ( is_scalar( $value ) && 'x-tzfd-nonce' === strtolower( (string) $name ) ) { $echo = (string) $value; }
        }
        if ( '' === $echo || ! hash_equals( $nonce, $echo ) ) { return self::result( 'invalid_response', array( 'reason' => 'nonce_not_echoed' ) ); }

        // The AI endpoint is signed with the rest of the body. It must be https on the pairing endpoint's host.
        $feature = is_string( $data['feature_endpoint'] ) ? $data['feature_endpoint'] : '';
        if ( ! self::feature_endpoint_allowed( $feature ) ) {
            return self::result( 'invalid_response', array( 'reason' => 'endpoint_invalid' ) );
        }

        // connect() verifies the signature, timestamp, nonce, site, company, lifetime and replay, then stores the pairing.
        $v = TZFD_Central_Tenant::connect( $response_body, $headers, $site_id, $secret, time() );
        $response_body = '';
        $data          = array();
        if ( empty( $v['ok'] ) ) { return self::result( 'invalid_response', array( 'reason' => (string) $v['reason'] ) ); }

        // Only after the signed pairing is stored: the endpoint and the secret go to the AI connector.
        if ( ! TZFD_AI_Connector::configure( $feature, $site_id, $secret ) ) {
            return self::result( 'invalid_response', array( 'reason' => 'endpoint_rejected' ) );
        }
        TZFD_AI_Connector::revoke_consent(); // a new pairing needs consent again
        return self::result( 'paired', array( 'reason' => (string) $v['reason'] ) );
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

    /**
     * The hosts the AI feature endpoint may be on. Default: superchargedat.work. The filter tzfd_ai_feature_hosts
     * replaces the list. A non-array result allows nothing; non-string entries are dropped.
     */
    public static function feature_hosts() {
        $hosts = function_exists( 'apply_filters' )
            ? apply_filters( 'tzfd_ai_feature_hosts', array( self::FEATURE_HOST_DEFAULT ) )
            : array( self::FEATURE_HOST_DEFAULT );
        if ( ! is_array( $hosts ) ) { return array(); }
        $clean = array();
        foreach ( $hosts as $host ) {
            if ( is_string( $host ) && '' !== $host ) { $clean[] = strtolower( $host ); }
        }
        return $clean;
    }

    /** https only, no userinfo, no explicit port, and a host that is exactly one entry of feature_hosts(). */
    public static function feature_endpoint_allowed( $url ) {
        if ( ! self::valid_https_url( $url ) ) { return false; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see valid_https_url().
        $parts = parse_url( $url );
        if ( isset( $parts['port'] ) ) { return false; }
        return in_array( strtolower( $parts['host'] ), self::feature_hosts(), true );
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
        $headers = array();
        foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
            $headers[ (string) $name ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
        }
        return array(
            'status_code' => (int) wp_remote_retrieve_response_code( $response ),
            'body'        => (string) wp_remote_retrieve_body( $response ),
            'headers'     => $headers,
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
