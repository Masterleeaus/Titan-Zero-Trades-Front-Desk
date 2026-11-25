<?php
/**
 * Site-to-Central company pairing (issue #376).
 *
 * The plugin keeps its own site-local integer company_id (TZFD_Local_Business::id()) for every local
 * table, booking and setting. That integer is never sent to the hosted service and is never treated as a
 * Central company UUID. This class stores the one explicit pairing reference that maps the current site to
 * a canonical Central company UUID, plus the per-site credential used to sign hosted calls.
 *
 * Provisional wire format: the pairing assertion below uses the signing scheme of tzfd-ai/1 (HMAC-SHA256 over
 * "<timestamp>.<nonce>.<body>") and the field names in assertion_fields(). The Central #186 contract text
 * could not be read from this session, so the contract identifier and field names must be confirmed against
 * Central #186 before any hosted pairing goes live. See docs/CENTRAL-COMPANY-PAIRING.md.
 *
 * Fail-safe rules: every method returns a structured result. Nothing here deletes local booking data, and
 * resolve() never makes a network call. Missing, expired, mismatched or unknown-version pairings return an
 * error, so hosted calls stop while local booking keeps working.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Central_Tenant {
    const RECORD_VERSION  = 1;                       // stored record layout; unknown versions fail closed
    const CONTRACT        = 'central-pairing/1';     // PROVISIONAL, see file header
    const OPTION          = 'tzfd_central_tenant';   // pairing reference (no secret), autoload off
    const SECRET_OPTION   = 'tzfd_central_tenant_secret'; // per-site credential, autoload off
    const NONCE_OPTION    = 'tzfd_central_pairing_nonces'; // consumed nonces => hash => time
    const AUDIT_OPTION    = 'tzfd_central_tenant_audit';   // company UUIDs and times only, never secrets
    const MIN_SECRET      = 32;                      // characters
    const MAX_BODY_BYTES  = 8192;
    const SKEW            = 300;                     // seconds either side of the site clock
    const MAX_LIFETIME    = 3600;                    // longest assertion lifetime accepted
    const NONCE_TTL       = 3600;                    // consumed nonces are remembered this long
    const AUDIT_LIMIT     = 20;
    const OPERATION_HOSTED = 'hosted.request';       // the only operation the AI connector uses

    /** Assertion fields the hosted service must send. Names are provisional (see file header). */
    public static function assertion_fields() {
        return array( 'contract', 'site', 'company_uuid', 'operations', 'nonce', 'issued_at', 'expires_at' );
    }

    /**
     * True only for a canonical, non-nil UUID string. A local integer (1, '1', 42) is always false, so the
     * site-local company_id can never pass as a Central company UUID.
     */
    public static function is_company_uuid( $value ) {
        if ( ! is_string( $value ) ) { return false; }
        if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) { return false; }
        return '00000000-0000-0000-0000-000000000000' !== strtolower( $value );
    }

    /**
     * The per-site secret from the secret store, provisioned by the Central operator. It is never taken from a
     * pairing response. Source: the constant TZFD_CENTRAL_SITE_SECRET, else the filter tzfd_central_site_secret.
     * Returns '' when absent or shorter than MIN_SECRET, so nothing is sent without it. Never log or echo the result.
     */
    public static function provisioned_secret() {
        $raw = '';
        if ( defined( 'TZFD_CENTRAL_SITE_SECRET' ) ) {
            $raw = constant( 'TZFD_CENTRAL_SITE_SECRET' );
        } elseif ( function_exists( 'apply_filters' ) ) {
            $raw = apply_filters( 'tzfd_central_site_secret', '' );
        }
        return is_string( $raw ) && strlen( $raw ) >= self::MIN_SECRET ? $raw : '';
    }

    /** HMAC-SHA256 over "<timestamp>.<nonce>.<body>", formatted as sha256=<hex>. */
    public static function sign( $secret, $timestamp, $nonce, $body ) {
        return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, (string) $secret );
    }

    /**
     * Checks a signed pairing assertion and returns its claims. Does not store anything.
     *
     * @param string $raw_body Exact JSON body as received (signature covers these bytes).
     * @param array  $headers  Response headers: X-TZFD-Timestamp, X-TZFD-Nonce, X-TZFD-Signature.
     * @param string $site_id  This site's identifier (must match the assertion's site).
     * @param string $secret   Per-site credential.
     * @param int    $now      Current Unix time.
     */
    public static function verify_assertion( $raw_body, array $headers, $site_id, $secret, $now ) {
        if ( ! is_string( $raw_body ) || '' === $raw_body || strlen( $raw_body ) > self::MAX_BODY_BYTES ) { return self::fail( 'malformed' ); }
        if ( ! is_string( $site_id ) || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,64}$/D', $site_id ) ) { return self::fail( 'site_invalid' ); }
        if ( ! is_string( $secret ) || strlen( $secret ) < self::MIN_SECRET ) { return self::fail( 'credential_missing' ); }

        $h = array();
        foreach ( $headers as $name => $value ) {
            if ( is_scalar( $value ) ) { $h[ strtolower( (string) $name ) ] = (string) $value; }
        }
        $ts    = isset( $h['x-tzfd-timestamp'] ) ? $h['x-tzfd-timestamp'] : '';
        $nonce = isset( $h['x-tzfd-nonce'] ) ? $h['x-tzfd-nonce'] : '';
        $sig   = isset( $h['x-tzfd-signature'] ) ? $h['x-tzfd-signature'] : '';
        if ( 1 !== preg_match( '/^[0-9]{1,11}$/D', $ts ) || abs( (int) $now - (int) $ts ) > self::SKEW ) { return self::fail( 'stale' ); }
        if ( 1 !== preg_match( '/^[0-9a-f]{32}$/D', $nonce ) ) { return self::fail( 'nonce_invalid' ); }
        if ( ! hash_equals( self::sign( $secret, $ts, $nonce, $raw_body ), $sig ) ) { return self::fail( 'bad_signature' ); }

        $claims = json_decode( $raw_body, true );
        if ( ! is_array( $claims ) ) { return self::fail( 'malformed' ); }
        foreach ( self::assertion_fields() as $field ) {
            if ( ! array_key_exists( $field, $claims ) ) { return self::fail( 'missing_field' ); }
        }
        if ( self::CONTRACT !== $claims['contract'] ) { return self::fail( 'contract_unsupported' ); }
        if ( ! is_string( $claims['site'] ) || ! hash_equals( $site_id, $claims['site'] ) ) { return self::fail( 'site_mismatch' ); }
        if ( ! self::is_company_uuid( $claims['company_uuid'] ) ) { return self::fail( 'company_invalid' ); }
        if ( ! is_string( $claims['nonce'] ) || ! hash_equals( $nonce, $claims['nonce'] ) ) { return self::fail( 'nonce_mismatch' ); }
        if ( ! is_int( $claims['issued_at'] ) || ! is_int( $claims['expires_at'] ) ) { return self::fail( 'malformed' ); }
        if ( $claims['issued_at'] > (int) $now + 30 ) { return self::fail( 'future_dated' ); }
        if ( $claims['expires_at'] <= (int) $now ) { return self::fail( 'expired' ); }
        $life = $claims['expires_at'] - $claims['issued_at'];
        if ( $life < 1 || $life > self::MAX_LIFETIME ) { return self::fail( 'lifetime_invalid' ); }
        if ( ! is_array( $claims['operations'] ) || array() === $claims['operations'] ) { return self::fail( 'malformed' ); }
        foreach ( $claims['operations'] as $op ) {
            if ( ! is_string( $op ) ) { return self::fail( 'malformed' ); }
        }
        if ( self::nonce_seen( $nonce, $now ) ) { return self::fail( 'replayed' ); }

        return array( 'ok' => true, 'reason' => 'verified', 'claims' => $claims );
    }

    /**
     * Stores the pairing after a verified assertion. A different company than the current one is refused
     * unless $reassign is true. The site credential is replaced on every successful call (rotation).
     */
    public static function connect( $raw_body, array $headers, $site_id, $secret, $now, $reassign = false ) {
        $v = self::verify_assertion( $raw_body, $headers, $site_id, $secret, $now );
        if ( empty( $v['ok'] ) ) { return $v; }
        $claims  = $v['claims'];
        $current = self::current();
        if ( null !== $current && $current['company_uuid'] !== $claims['company_uuid'] && ! $reassign ) {
            return self::fail( 'company_reassign_required' );
        }
        $action = null === $current ? 'connect' : ( $current['company_uuid'] === $claims['company_uuid'] ? 'rotate' : 'reassign' );

        $record = array(
            'v'            => self::RECORD_VERSION,
            'contract'     => self::CONTRACT,
            'site_id'      => $site_id,
            'company_uuid' => $claims['company_uuid'],
            'operations'   => array_values( array_unique( $claims['operations'] ) ),
            'paired_at'    => (int) $now,
            'expires_at'   => (int) $claims['expires_at'],
        );
        update_option( self::OPTION, $record, false );
        update_option( self::SECRET_OPTION, $secret, false );
        self::remember_nonce( $headers, $now );
        self::audit( $action, $claims['company_uuid'], $now );
        return array( 'ok' => true, 'reason' => $action, 'company_uuid' => $claims['company_uuid'] );
    }

    /**
     * Confirms this site may send an operation to its paired company. No network call is made.
     * Returns ok with company_uuid, or an error reason. Local data is never read or changed here.
     */
    public static function resolve( $site_id, $operation = self::OPERATION_HOSTED, $now = null ) {
        $now    = null === $now ? time() : (int) $now;
        $record = get_option( self::OPTION, null );
        if ( ! is_array( $record ) ) { return self::fail( 'not_paired' ); }
        if ( ! isset( $record['v'] ) || self::RECORD_VERSION !== $record['v'] ) { return self::fail( 'pairing_unsupported' ); }
        if ( ! isset( $record['site_id'], $record['company_uuid'], $record['expires_at'], $record['operations'] ) ) { return self::fail( 'pairing_invalid' ); }
        if ( ! is_string( $site_id ) || ! is_string( $record['site_id'] ) || ! hash_equals( $record['site_id'], $site_id ) ) { return self::fail( 'site_mismatch' ); }
        if ( ! self::is_company_uuid( $record['company_uuid'] ) ) { return self::fail( 'pairing_invalid' ); }
        if ( (int) $record['expires_at'] <= $now ) { return self::fail( 'pairing_expired' ); }
        if ( ! is_array( $record['operations'] ) || ! in_array( $operation, $record['operations'], true ) ) { return self::fail( 'operation_not_paired' ); }
        if ( '' === (string) get_option( self::SECRET_OPTION, '' ) ) { return self::fail( 'credential_missing' ); }
        return array( 'ok' => true, 'reason' => 'resolved', 'company_uuid' => $record['company_uuid'] );
    }

    /**
     * Removes the pairing reference and the site credential. Local bookings, customers, settings and
     * integer company_id values stay exactly as they were.
     */
    public static function disconnect( $now = null ) {
        $current = self::current();
        delete_option( self::SECRET_OPTION );
        delete_option( self::OPTION );
        if ( null !== $current ) { self::audit( 'disconnect', $current['company_uuid'], null === $now ? time() : (int) $now ); }
        return true;
    }

    /** Admin status. Never includes the credential. */
    public static function status() {
        $current = self::current();
        return array(
            'connected'    => null !== $current && '' !== (string) get_option( self::SECRET_OPTION, '' ),
            'company_uuid' => null === $current ? '' : $current['company_uuid'],
            'site_id'      => null === $current ? '' : $current['site_id'],
            'expires_at'   => null === $current ? 0 : (int) $current['expires_at'],
            'has_credential' => '' !== (string) get_option( self::SECRET_OPTION, '' ),
            'record_version' => self::RECORD_VERSION,
            'audit'        => self::audit_log(),
        );
    }

    /** Redacted audit trail: action, company UUID and time. */
    public static function audit_log() {
        $log = get_option( self::AUDIT_OPTION, array() );
        return is_array( $log ) ? array_values( $log ) : array();
    }

    /** Stored pairing record, or null. Used for status and reassignment checks only. */
    private static function current() {
        $record = get_option( self::OPTION, null );
        if ( ! is_array( $record ) || ! isset( $record['company_uuid'] ) || ! self::is_company_uuid( $record['company_uuid'] ) ) { return null; }
        return $record;
    }

    private static function nonce_seen( $nonce, $now ) {
        $seen = get_option( self::NONCE_OPTION, array() );
        if ( ! is_array( $seen ) ) { return false; }
        $key = hash( 'sha256', $nonce );
        return isset( $seen[ $key ] ) && (int) $seen[ $key ] >= (int) $now - self::NONCE_TTL;
    }

    private static function remember_nonce( array $headers, $now ) {
        $h = array();
        foreach ( $headers as $name => $value ) {
            if ( is_scalar( $value ) ) { $h[ strtolower( (string) $name ) ] = (string) $value; }
        }
        if ( ! isset( $h['x-tzfd-nonce'] ) ) { return; }
        $seen = get_option( self::NONCE_OPTION, array() );
        $seen = is_array( $seen ) ? $seen : array();
        foreach ( $seen as $key => $time ) {
            if ( (int) $time < (int) $now - self::NONCE_TTL ) { unset( $seen[ $key ] ); }
        }
        $seen[ hash( 'sha256', $h['x-tzfd-nonce'] ) ] = (int) $now;
        update_option( self::NONCE_OPTION, $seen, false );
    }

    private static function audit( $action, $company_uuid, $now ) {
        $log   = get_option( self::AUDIT_OPTION, array() );
        $log   = is_array( $log ) ? array_values( $log ) : array();
        $log[] = array( 'action' => (string) $action, 'company_uuid' => (string) $company_uuid, 'at' => (int) $now );
        $log   = array_slice( $log, -self::AUDIT_LIMIT );
        update_option( self::AUDIT_OPTION, $log, false );
    }

    private static function fail( $reason ) {
        return array( 'ok' => false, 'reason' => (string) $reason );
    }
}
