<?php
/**
 * Pairing handshake for the hosted AI connector (contract tzfd-pair/1, issue #347).
 *
 * Owner decision (proposed default, see docs/AI-SERVICE-CONNECTOR-CONTRACT.md "Pairing"):
 *   1. The hosted pairing endpoint is NOT built into the code. It comes from the constant
 *      TZFD_AI_PAIRING_ENDPOINT or the filter tzfd_ai_pairing_endpoint. With neither set, pairing is
 *      off and no request is ever sent (fail closed).
 *   2. The hosted service mints a single-use pairing code bound to the business account. The site
 *      administrator pastes it into WordPress. The code is sent once, over HTTPS, in the JSON body only.
 *   3. The service answers with a per-site secret (64 lowercase hex characters) issued once, and the
 *      feature endpoint. The feature endpoint must be https and on the same host as the pairing endpoint.
 *   4. The secret is stored through TZFD_AI_Connector::configure(). It is never shown, logged or returned
 *      to the browser. A later pairing replaces it (rotation) and resets consent, so the administrator
 *      must grant scopes again for the new connection.
 *   5. Pairing never grants consent or entitlements by itself.
 *
 * Request (POST, JSON body):
 *   X-TZFD-Contract: tzfd-pair/1, X-TZFD-Site: <site id>, X-TZFD-Timestamp, X-TZFD-Nonce (32 hex)
 *   body: {"contract":"tzfd-pair/1","site":"<site id>","pairing_code":"<code>"}
 *
 * Response (HTTP 200, JSON, max 8 KB) must echo X-TZFD-Nonce and carry:
 *   {"contract":"tzfd-pair/1","site":"<site id>","status":"paired","secret":"<64 hex>","feature_endpoint":"https://..."}
 *
 * Transport seam: apply_filters( 'tzfd_ai_pairing_transport', null, $request ), same shape as the connector.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-ai-connector.php';

final class TZFD_AI_Pairing {
    const CONTRACT          = 'tzfd-pair/1';
    const ENDPOINT_CONSTANT = 'TZFD_AI_PAIRING_ENDPOINT';
    /**
     * Code default: empty, so pairing is off. Proposed production value, not active until the owner confirms it:
     * https://central.titanzero.io/api/tzfd/pair
     */
    const DEFAULT_ENDPOINT  = '';
    const SITE_OPTION       = 'tzfd_ai_site_id';
    const LOCK_OPTION       = 'tzfd_ai_pairing_lock';
    const LOCK_STALE        = 30;     // seconds before a crashed pairing lock may be taken over
    const SIGNATURE_WINDOW  = 300;    // seconds either side of the site clock for the response timestamp
    const TIMEOUT           = 10;     // seconds
    const MAX_RESPONSE_BYTES = 8192;
    const CODE_PATTERN      = '/^[A-Za-z0-9_-]{16,64}$/D';
    const SECRET_PATTERN    = '/^[a-f0-9]{64}$/D';

    /**
     * Pairing endpoint: constant, then filter. Only an https URL with a host is returned; anything else is ''.
     */
    public static function endpoint() {
        $url = self::DEFAULT_ENDPOINT;
        if ( defined( self::ENDPOINT_CONSTANT ) && is_string( constant( self::ENDPOINT_CONSTANT ) ) ) {
            $url = constant( self::ENDPOINT_CONSTANT );
        }
        if ( function_exists( 'apply_filters' ) ) {
            $url = apply_filters( 'tzfd_ai_pairing_endpoint', $url );
        }
        return self::valid_https_url( $url ) ? (string) $url : '';
    }

    /** True when a pairing endpoint is configured. Says nothing about whether the site is paired. */
    public static function endpoint_configured() {
        return '' !== self::endpoint();
    }

    /**
     * Stable local site id (site-<24 hex>), created on first use and kept across pairings so the service can
     * recognise the site. It carries no URL, user or business detail.
     */
    public static function site_id() {
        $id = get_option( self::SITE_OPTION, '' );
        if ( is_string( $id ) && 1 === preg_match( '/^site-[a-f0-9]{24}$/D', $id ) ) {
            return $id;
        }
        $id = 'site-' . bin2hex( random_bytes( 12 ) );
        update_option( self::SITE_OPTION, $id, false );
        return $id;
    }

    /** Safe status for the admin screen. Never includes the code or the secret. */
    public static function status() {
        $connector = TZFD_AI_Connector::status();
        return array(
            'contract'         => self::CONTRACT,
            'endpoint_set'     => self::endpoint_configured(),
            'paired'           => (bool) $connector['secret_set'],
            'site_id'          => (string) get_option( self::SITE_OPTION, '' ),
        );
    }

    /**
     * Exchanges a one-time pairing code for the per-site secret. Always returns a structured array with
     * ok, status and no secret. Statuses: ok, invalid_code, code_rejected, not_configured, busy, offline,
     * service_error, invalid_response.
     */
    public static function pair( $code ) {
        if ( ! is_string( $code ) || 1 !== preg_match( self::CODE_PATTERN, $code ) ) {
            return self::result( 'invalid_code' );
        }
        $endpoint = self::endpoint();
        if ( '' === $endpoint ) {
            return self::result( 'not_configured' );
        }
        if ( ! self::lock() ) {
            return self::result( 'busy' );
        }

        $site    = self::site_id();
        $nonce   = bin2hex( random_bytes( 16 ) );
        $body    = wp_json_encode( array(
            'contract'     => self::CONTRACT,
            'site'         => $site,
            'pairing_code' => $code,
        ) );
        $request = array(
            'url'     => $endpoint,
            'headers' => array(
                'Content-Type'     => 'application/json',
                'X-TZFD-Contract'  => self::CONTRACT,
                'X-TZFD-Site'      => $site,
                'X-TZFD-Timestamp' => (string) time(),
                'X-TZFD-Nonce'     => $nonce,
            ),
            'body'    => is_string( $body ) ? $body : '',
            'timeout' => self::TIMEOUT,
        );

        $raw     = self::transport( $request );
        $outcome = self::interpret( $raw, $endpoint, $site, $nonce );

        if ( 'ok' === $outcome['status'] ) {
            // Rotation: a new secret resets consent so the administrator grants scopes again for this connection.
            $stored = TZFD_AI_Connector::configure( $outcome['feature_endpoint'], $site, $outcome['secret'] );
            if ( $stored ) {
                TZFD_AI_Connector::revoke_consent();
            } else {
                $outcome = array( 'status' => 'invalid_response' );
            }
        }
        self::unlock();

        return self::result( $outcome['status'] );
    }

    // ---- internals -------------------------------------------------------

    private static function result( $status ) {
        return array(
            'ok'        => 'ok' === $status,
            'status'    => $status,
            'use_local' => 'ok' !== $status,
            'contract'  => self::CONTRACT,
        );
    }

    private static function valid_https_url( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) { return false; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure-PHP suite runs without WordPress; the checks below are the same either way.
        $parts = parse_url( $url );
        return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] )
            && 'https' === strtolower( $parts['scheme'] )
            && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
            && '' !== $parts['host'];
    }

    private static function same_host( $a, $b ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see valid_https_url().
        $pa = parse_url( $a );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see valid_https_url().
        $pb = parse_url( $b );
        return is_array( $pa ) && is_array( $pb ) && isset( $pa['host'], $pb['host'] )
            && strtolower( $pa['host'] ) === strtolower( $pb['host'] );
    }

    private static function transport( array $request ) {
        $raw = function_exists( 'apply_filters' ) ? apply_filters( 'tzfd_ai_pairing_transport', null, $request ) : null;
        if ( null === $raw ) {
            if ( ! function_exists( 'wp_remote_post' ) ) { return array( 'error' => 'offline' ); }
            $response = wp_remote_post( $request['url'], array(
                'timeout'     => $request['timeout'],
                'headers'     => $request['headers'],
                'body'        => $request['body'],
                'redirection' => 0,
            ) );
            if ( is_wp_error( $response ) ) { return array( 'error' => 'offline' ); }
            $h       = wp_remote_retrieve_headers( $response );
            $headers = is_object( $h ) && method_exists( $h, 'getAll' ) ? $h->getAll() : ( is_array( $h ) ? $h : array() );
            return array(
                'status_code' => (int) wp_remote_retrieve_response_code( $response ),
                'headers'     => $headers,
                'body'        => (string) wp_remote_retrieve_body( $response ),
            );
        }
        return $raw;
    }

    /**
     * Maps the transport answer to a status and, on success, the secret and feature endpoint.
     * Fails closed: any doubt about the echo, the site, the host or the secret format is invalid_response.
     */
    private static function interpret( $raw, $endpoint, $site, $nonce ) {
        $none = array( 'status' => 'offline' );
        if ( ! is_array( $raw ) || isset( $raw['error'] ) ) { return $none; }

        $code = isset( $raw['status_code'] ) ? (int) $raw['status_code'] : 0;
        if ( $code >= 400 && $code < 500 ) { return array( 'status' => 'code_rejected' ); }
        if ( 200 !== $code ) { return array( 'status' => 'service_error' ); }

        $body = isset( $raw['body'] ) && is_string( $raw['body'] ) ? $raw['body'] : '';
        if ( '' === $body || strlen( $body ) > self::MAX_RESPONSE_BYTES ) { return array( 'status' => 'invalid_response' ); }

        $headers = array();
        foreach ( isset( $raw['headers'] ) && is_array( $raw['headers'] ) ? $raw['headers'] : array() as $name => $value ) {
            if ( is_scalar( $value ) ) { $headers[ strtolower( (string) $name ) ] = (string) $value; }
        }
        $ts   = isset( $headers['x-tzfd-timestamp'] ) ? $headers['x-tzfd-timestamp'] : '';
        $echo = isset( $headers['x-tzfd-nonce'] ) ? $headers['x-tzfd-nonce'] : '';
        if ( 1 !== preg_match( '/^[0-9]{1,11}$/D', $ts ) || abs( time() - (int) $ts ) > self::SIGNATURE_WINDOW ) {
            return array( 'status' => 'invalid_response' );
        }
        if ( ! hash_equals( (string) $nonce, $echo ) ) { return array( 'status' => 'invalid_response' ); }

        $json = json_decode( $body, true );
        if ( ! is_array( $json ) || ! isset( $json['contract'], $json['site'], $json['status'] ) ) {
            return array( 'status' => 'invalid_response' );
        }
        if ( self::CONTRACT !== $json['contract'] || ! is_string( $json['site'] ) || ! hash_equals( $site, $json['site'] ) ) {
            return array( 'status' => 'invalid_response' );
        }
        if ( 'code_rejected' === $json['status'] ) { return array( 'status' => 'code_rejected' ); }
        if ( 'paired' !== $json['status'] ) { return array( 'status' => 'invalid_response' ); }

        $secret   = isset( $json['secret'] ) && is_string( $json['secret'] ) ? $json['secret'] : '';
        $feature  = isset( $json['feature_endpoint'] ) && is_string( $json['feature_endpoint'] ) ? $json['feature_endpoint'] : '';
        if ( 1 !== preg_match( self::SECRET_PATTERN, $secret ) ) { return array( 'status' => 'invalid_response' ); }
        if ( ! self::valid_https_url( $feature ) || ! self::same_host( $endpoint, $feature ) ) {
            return array( 'status' => 'invalid_response' );
        }

        return array( 'status' => 'ok', 'secret' => $secret, 'feature_endpoint' => $feature );
    }

    /** Atomic lock on a unique option row, so two admins cannot exchange codes at once. */
    private static function lock() {
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) { return true; }
            $taken = (int) get_option( self::LOCK_OPTION, 0 );
            if ( $taken > 0 && time() - $taken <= self::LOCK_STALE ) { return false; }
            delete_option( self::LOCK_OPTION );
        }
        return false;
    }

    private static function unlock() {
        delete_option( self::LOCK_OPTION );
    }
}
