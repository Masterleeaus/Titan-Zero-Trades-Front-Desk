<?php
/**
 * Offline tests for the hosted account pairing (issue #347): TZFD_AI_Pairing and its seven-field response.
 *
 * Run: php -d error_reporting=-1 tests/ai-connector/run-pairing-tests.php
 * No WordPress install and no network. Options and filters are in-memory shims. The transport is replaced
 * through the tzfd_ai_pairing_transport filter, so no request leaves the process.
 *
 * Secrets are generated at runtime and never written into this file. The per-site secret is supplied by the
 * secret store (the tzfd_central_site_secret filter here, or the TZFD_CENTRAL_SITE_SECRET constant).
 *
 * Order matters: the pairing endpoint and secret constants can be defined only once, so the constant checks run last.
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tz_opts']    = array();
$GLOBALS['tz_filters'] = array();
$GLOBALS['tz_calls']   = array();
$GLOBALS['tz_reply']   = null;
$GLOBALS['tz_store']   = bin2hex( random_bytes( 32 ) ); // the provisioned per-site secret (runtime value)

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['tz_opts'] ) ? $GLOBALS['tz_opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['tz_opts'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) {
    if ( array_key_exists( $k, $GLOBALS['tz_opts'] ) ) { return false; }
    $GLOBALS['tz_opts'][ $k ] = $v;
    return true;
}
function delete_option( $k ) { unset( $GLOBALS['tz_opts'][ $k ] ); return true; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['tz_filters'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value, ...$args ) {
    foreach ( isset( $GLOBALS['tz_filters'][ $tag ] ) ? $GLOBALS['tz_filters'][ $tag ] : array() as $cb ) {
        $value = $cb( $value, ...$args );
    }
    return $value;
}
function wp_json_encode( $v ) { return json_encode( $v ); }

require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-connector.php';
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-central-tenant.php';
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-pairing.php';

const ENDPOINT   = 'https://central.titanzero.io/api/tzfd/pair';
const OTHER_END  = 'https://central-staging.titanzero.io/api/tzfd/pair';
const CODE       = 'ABCD-1234-EFGH';
const COMPANY    = '3f2b8c1e-5d4a-4b7e-9c1f-0a1b2c3d4e5f';
const COMPANY_B  = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
const SEVEN      = array( 'contract', 'site', 'company_uuid', 'operations', 'nonce', 'issued_at', 'expires_at' );

$GLOBALS['tz_fail'] = 0;
$GLOBALS['tz_pass'] = 0;
function check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

function reset_state() {
    $GLOBALS['tz_opts']    = array();
    $GLOBALS['tz_filters'] = array();
    $GLOBALS['tz_calls']   = array();
    $GLOBALS['tz_reply']   = null;
    $GLOBALS['tz_last_response_body'] = null;
    add_filter( 'tzfd_central_site_secret', function () { return $GLOBALS['tz_store']; } );
    add_filter( 'tzfd_ai_pairing_transport', function ( $raw, $request ) {
        $GLOBALS['tz_calls'][] = $request;
        $r = $GLOBALS['tz_reply'];
        $out = is_callable( $r ) ? $r( $request ) : $r;
        if ( is_array( $out ) && isset( $out['body'] ) ) { $GLOBALS['tz_last_response_body'] = $out['body']; }
        return $out;
    }, 10, 2 );
}

function use_endpoint( $url ) {
    add_filter( 'tzfd_ai_pairing_endpoint', function () use ( $url ) { return $url; } );
}

/**
 * A pairing reply built the way the hosted service builds one: the seven assertion fields in the body, signed
 * with the provisioned secret over "<timestamp>.<nonce>.<body>" in the X-TZFD-* headers, nonce echoed from the request.
 * $claims overrides body fields (null removes one); $headers overrides headers (null removes one);
 * $body replaces the whole body; $key signs with a different key.
 */
function signed_reply( array $claims = array(), array $headers = array(), $body = null, $key = null ) {
    return function ( $request ) use ( $claims, $headers, $body, $key ) {
        $sent = json_decode( $request['body'], true );
        $now  = time();
        $data = array(
            'contract'     => 'central-pairing/1',
            'site'         => $sent['site'],
            'company_uuid' => COMPANY,
            'operations'   => array( 'hosted.request' ),
            'nonce'        => $sent['nonce'],
            'issued_at'    => $now,
            'expires_at'   => $now + 600,
        );
        foreach ( $claims as $k => $v ) {
            if ( null === $v ) { unset( $data[ $k ] ); } else { $data[ $k ] = $v; }
        }
        $text = null !== $body ? $body : json_encode( $data );
        $hdrs = array(
            'X-TZFD-Timestamp' => (string) $now,
            'X-TZFD-Nonce'     => $sent['nonce'],
            'X-TZFD-Signature' => TZFD_Central_Tenant::sign( null !== $key ? $key : $GLOBALS['tz_store'], (string) $now, $sent['nonce'], $text ),
        );
        foreach ( $headers as $k => $v ) {
            if ( null === $v ) { unset( $hdrs[ $k ] ); } else { $hdrs[ $k ] = $v; }
        }
        return array( 'status_code' => 200, 'body' => $text, 'headers' => $hdrs );
    };
}

function pair_with( $reply, $code = CODE ) {
    $GLOBALS['tz_reply'] = $reply;
    $GLOBALS['tz_calls'] = array();
    return TZFD_AI_Pairing::pair( $code );
}

function stored_record() { return get_option( TZFD_Central_Tenant::OPTION, null ); }
function stored_secret() { return get_option( TZFD_Central_Tenant::SECRET_OPTION, '' ); }
function stored_company() { $r = stored_record(); return is_array( $r ) ? $r['company_uuid'] : ''; }
function lock_held() { return array_key_exists( TZFD_AI_Pairing::LOCK_OPTION, $GLOBALS['tz_opts'] ); }

// ---- A. Endpoint: off by default, https only ----
reset_state();
check( 'A1 endpoint is empty with no constant or filter (off by default)', '' === TZFD_AI_Pairing::endpoint() );
$r = pair_with( signed_reply() );
check( 'A2 pairing is off by default: status pairing_off and no transport call', 'pairing_off' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
check( 'A3 pairing off stores nothing (no site id, no pairing, no secret)', ! array_key_exists( TZFD_AI_Pairing::SITE_OPTION, $GLOBALS['tz_opts'] ) && null === stored_record() && '' === stored_secret() );

reset_state();
use_endpoint( ENDPOINT );
check( 'A4 an https endpoint from the filter is accepted', ENDPOINT === TZFD_AI_Pairing::endpoint() );

reset_state();
use_endpoint( 'http://central.titanzero.io/api/tzfd/pair' );
check( 'A5 http endpoint is refused', '' === TZFD_AI_Pairing::endpoint() );

reset_state();
use_endpoint( 'https://user:pass@central.titanzero.io/api/tzfd/pair' );
check( 'A6 endpoint with userinfo is refused', '' === TZFD_AI_Pairing::endpoint() );

reset_state();
use_endpoint( 'https:///api/tzfd/pair' );
check( 'A7 endpoint with no host is refused', '' === TZFD_AI_Pairing::endpoint() );

reset_state();
use_endpoint( 'ftp://central.titanzero.io/api/tzfd/pair' );
check( 'A8 non-https scheme is refused', '' === TZFD_AI_Pairing::endpoint() );

reset_state();
add_filter( 'tzfd_ai_pairing_endpoint', function () { return array( ENDPOINT ); } );
check( 'A9 a non-string endpoint is refused', '' === TZFD_AI_Pairing::endpoint() );

reset_state();
use_endpoint( 'https://central.titanzero.io/' . str_repeat( 'a', 2100 ) );
check( 'A10 an endpoint over 2048 characters is refused', '' === TZFD_AI_Pairing::endpoint() );

// ---- B. Pairing code validation ----
reset_state();
use_endpoint( ENDPOINT );
$r = pair_with( signed_reply(), '' );
check( 'B1 empty code is invalid_code and sends nothing', 'invalid_code' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
$r = pair_with( signed_reply(), 'ABC D12345' );
check( 'B2 code with a space is invalid_code', 'invalid_code' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
$r = pair_with( signed_reply(), 'ABC12' );
check( 'B3 code shorter than 6 characters is invalid_code', 'invalid_code' === $r['status'] );
$r = pair_with( signed_reply(), str_repeat( 'A', 65 ) );
check( 'B4 code longer than 64 characters is invalid_code', 'invalid_code' === $r['status'] );
$r = pair_with( signed_reply(), array( CODE ) );
check( 'B5 a non-string code is invalid_code', 'invalid_code' === $r['status'] );
$r = pair_with( signed_reply() );
check( 'B6 a valid code reaches the transport exactly once', 'paired' === $r['status'] && 1 === count( $GLOBALS['tz_calls'] ) );

// ---- C. Request wire format (unchanged: contract, site, nonce, code) ----
$req  = $GLOBALS['tz_calls'][0];
$sent = json_decode( $req['body'], true );
check( 'C0 the contract constant is central-pairing/1', 'central-pairing/1' === TZFD_AI_Pairing::CONTRACT );
check( 'C1 request method is POST to the configured endpoint', isset( $req['method'] ) && 'POST' === $req['method'] && ENDPOINT === $req['url'] );
check( 'C2 request body is JSON with contract central-pairing/1', is_array( $sent ) && 'central-pairing/1' === $sent['contract'] );
$sent_keys = is_array( $sent ) ? array_keys( $sent ) : array();
sort( $sent_keys );
check( 'C3 request body carries exactly contract, site, nonce and code', array( 'code', 'contract', 'nonce', 'site' ) === $sent_keys );
check( 'C4 request nonce and site are 32-character hex', is_array( $sent ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $sent['nonce'] ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $sent['site'] ) );
check( 'C5 request body carries the pairing code', is_array( $sent ) && CODE === $sent['code'] );
check( 'C6 request body carries no secret', false === strpos( $req['body'], $GLOBALS['tz_store'] ) );
check( 'C7 timeout is 8 seconds and redirects are not followed', 8 === $req['timeout'] && 0 === $req['redirection'] );
check( 'C8 request is sent as application/json', 'application/json' === $req['headers']['Content-Type'] );

// ---- D. The seven fields of the response: each one is checked ----
reset_state();
use_endpoint( ENDPOINT );
$r = pair_with( signed_reply() );
check( 'D1 a response with exactly the seven signed fields pairs', 'paired' === $r['status'] && COMPANY === stored_company() );
check( 'D2 the response body has exactly the seven fields', is_string( $GLOBALS['tz_last_response_body'] ) && SEVEN === array_keys( json_decode( $GLOBALS['tz_last_response_body'], true ) ) );
foreach ( SEVEN as $field ) {
    reset_state();
    use_endpoint( ENDPOINT );
    $r = pair_with( signed_reply( array( $field => null ) ) );
    check( "D4 a response missing '{$field}' is refused", 'invalid_response' === $r['status'] && null === stored_record() );
}
reset_state();
use_endpoint( ENDPOINT );
$r = pair_with( signed_reply( array( 'contract' => 'central-pairing/0' ) ) );
check( 'D5 contract central-pairing/0 is refused (contract_unsupported)', 'invalid_response' === $r['status'] && 'contract_unsupported' === $r['reason'] && null === stored_record() );
$r = pair_with( signed_reply( array( 'site' => 'someone-else' ) ) );
check( 'D6 a site that is not this site is refused (site_mismatch)', 'invalid_response' === $r['status'] && 'site_mismatch' === $r['reason'] && null === stored_record() );
$r = pair_with( signed_reply( array( 'company_uuid' => 42 ) ) );
check( 'D7 a local integer company_uuid is refused (company_invalid)', 'invalid_response' === $r['status'] && 'company_invalid' === $r['reason'] && null === stored_record() );
$r = pair_with( signed_reply( array( 'company_uuid' => '00000000-0000-0000-0000-000000000000' ) ) );
check( 'D8 a nil company_uuid is refused (company_invalid)', 'invalid_response' === $r['status'] && 'company_invalid' === $r['reason'] );
$r = pair_with( signed_reply( array( 'operations' => array() ) ) );
check( 'D9 an empty operations list is refused (malformed)', 'invalid_response' === $r['status'] && 'malformed' === $r['reason'] );
$r = pair_with( signed_reply( array( 'operations' => array( 7 ) ) ) );
check( 'D10 a non-string operation is refused (malformed)', 'invalid_response' === $r['status'] && 'malformed' === $r['reason'] );
$r = pair_with( signed_reply( array( 'nonce' => str_repeat( '0', 32 ) ) ) );
check( 'D11 a body nonce that differs from the signed header is refused (nonce_mismatch)', 'invalid_response' === $r['status'] && 'nonce_mismatch' === $r['reason'] );
$r = pair_with( signed_reply( array( 'issued_at' => '1700000000' ) ) );
check( 'D12 issued_at must be an integer (malformed)', 'invalid_response' === $r['status'] && 'malformed' === $r['reason'] );
$r = pair_with( signed_reply( array( 'issued_at' => time() + 3600, 'expires_at' => time() + 7200 ) ) );
check( 'D13 an issued_at in the future is refused (future_dated)', 'invalid_response' === $r['status'] && 'future_dated' === $r['reason'] );
$r = pair_with( signed_reply( array( 'expires_at' => time() - 10, 'issued_at' => time() - 700 ) ) );
check( 'D14 an expired assertion is refused (expired)', 'invalid_response' === $r['status'] && 'expired' === $r['reason'] );
$r = pair_with( signed_reply( array( 'expires_at' => time() + 7200 ) ) );
check( 'D15 a lifetime over one hour is refused (lifetime_invalid)', 'invalid_response' === $r['status'] && 'lifetime_invalid' === $r['reason'] );

// ---- E. Body and header integrity, and the no-secret rule ----
reset_state();
use_endpoint( ENDPOINT );
$r = pair_with( signed_reply( array(), array(), null, str_repeat( 'b', 64 ) ) );
check( 'E1 a signature made with another key is refused (bad_signature)', 'invalid_response' === $r['status'] && 'bad_signature' === $r['reason'] && null === stored_record() );
$r = pair_with( signed_reply( array(), array( 'X-TZFD-Nonce' => str_repeat( '0', 32 ) ) ) );
check( 'E2 a nonce header that does not echo the request is refused', 'invalid_response' === $r['status'] && 'nonce_not_echoed' === $r['reason'] && null === stored_record() );
$r = pair_with( signed_reply( array(), array( 'X-TZFD-Signature' => null ) ) );
check( 'E3 a missing signature header is refused (bad_signature)', 'invalid_response' === $r['status'] && null === stored_record() );
$r = pair_with( signed_reply( array(), array( 'X-TZFD-Timestamp' => (string) ( time() - 3600 ) ) ) );
check( 'E4 a timestamp outside the skew window is refused (stale)', 'invalid_response' === $r['status'] && 'stale' === $r['reason'] );
$r = pair_with( signed_reply( array( 'secret' => $GLOBALS['tz_store'] ) ) );
check( 'E5 a response that carries a secret field is refused (fields_mismatch)', 'invalid_response' === $r['status'] && 'fields_mismatch' === $r['reason'] && null === stored_record() );
$r = pair_with( signed_reply( array( 'feature_endpoint' => 'https://central.titanzero.io/api/tzfd/ai' ) ) );
check( 'E6 an extra feature_endpoint field is refused (fields_mismatch)', 'invalid_response' === $r['status'] && 'fields_mismatch' === $r['reason'] );

reset_state();
use_endpoint( ENDPOINT );
$r = pair_with( signed_reply() );
$secret = $GLOBALS['tz_store'];
check( 'E7 the response body never contains the secret', is_string( $GLOBALS['tz_last_response_body'] ) && false === strpos( $GLOBALS['tz_last_response_body'], $secret ) );
check( 'E8 the result never contains the secret or the code', false === strpos( json_encode( $r ), $secret ) && false === strpos( json_encode( $r ), CODE ) );
check( 'E9 the stored pairing record never contains the secret', false === strpos( serialize( stored_record() ), $secret ) );
check( 'E10 the secret is stored from the secret store, and the pairing code is stored nowhere', $secret === stored_secret() && false === strpos( serialize( $GLOBALS['tz_opts'] ), CODE ) );
check( 'E11 the pairing lock is released after success', ! lock_held() );

// ---- F. Pairing through the secret store: rotation and missing secret ----
reset_state();
use_endpoint( ENDPOINT );
TZFD_AI_Connector::grant_consent( array( 'booking_details' ) );
pair_with( signed_reply() );
$connector = get_option( TZFD_AI_Connector::OPTION, array() );
check( 'F1 a successful pairing revokes earlier consent', empty( $connector['consent'] ) );
$site_before = TZFD_AI_Pairing::site_id();
$GLOBALS['tz_store'] = bin2hex( random_bytes( 32 ) ); // the operator provisions a new secret
$r = pair_with( signed_reply() );
check( 'F2 rotation: a pairing with a new provisioned secret replaces the stored secret', 'paired' === $r['status'] && $GLOBALS['tz_store'] === stored_secret() );
check( 'F3 the site id is stable across re-pairing', $site_before === TZFD_AI_Pairing::site_id() );

reset_state();
use_endpoint( ENDPOINT );
$GLOBALS['tz_store'] = 'too-short';
$r = pair_with( signed_reply( array(), array(), null, 'too-short' ) );
check( 'F4 a secret shorter than 32 characters is secret_missing and nothing is sent', 'secret_missing' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
reset_state();
use_endpoint( ENDPOINT );
add_filter( 'tzfd_central_site_secret', function () { return 42; } );
$r = pair_with( signed_reply() );
check( 'F5 a non-string provisioned secret is secret_missing and nothing is sent', 'secret_missing' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );

// ---- G. Failed or refused exchanges keep the existing pairing ----
$GLOBALS['tz_store'] = bin2hex( random_bytes( 32 ) ); // section F left a short secret in the store
reset_state();
use_endpoint( ENDPOINT );
pair_with( signed_reply() );
$good_secret  = $GLOBALS['tz_store'];
$good_record  = stored_record();
$keep = function ( $label, $r, $status ) use ( $good_secret, $good_record ) {
    check( $label, $status === $r['status'] && $good_secret === stored_secret() && $good_record === stored_record() && ! lock_held() );
};
$keep( 'G1 HTTP 500 is rejected and the old pairing is kept', pair_with( function () { return array( 'status_code' => 500, 'body' => '' ); } ), 'rejected' );
$keep( 'G2 a non-JSON body is invalid_response and the old pairing is kept', pair_with( function () { return array( 'status_code' => 200, 'body' => 'not json' ); } ), 'invalid_response' );
$keep( 'G3 a transport error is offline and the old pairing is kept', pair_with( function () { return array( 'error' => 'offline' ); } ), 'offline' );
$keep( 'G4 a reply over 16384 bytes is invalid_response', pair_with( function () { return array( 'status_code' => 200, 'body' => str_repeat( 'x', 16385 ) ); } ), 'invalid_response' );
$r = pair_with( function () { throw new RuntimeException( 'boom' ); } );
check( 'G5 a transport exception is offline, not fatal, and keeps the old pairing', 'offline' === $r['status'] && $good_record === stored_record() && $good_secret === stored_secret() && ! lock_held() );
$GLOBALS['tz_store'] = $good_secret;
$keep( 'G6 a different company without reassignment is refused and the old company is kept', pair_with( signed_reply( array( 'company_uuid' => COMPANY_B ) ) ), 'invalid_response' );
check( 'G7 that refusal reports company_reassign_required', 'company_reassign_required' === pair_with( signed_reply( array( 'company_uuid' => COMPANY_B ) ) )['reason'] && COMPANY === stored_company() );

// ---- H. Lock ----
reset_state();
use_endpoint( ENDPOINT );
$GLOBALS['tz_opts'][ TZFD_AI_Pairing::LOCK_OPTION ] = time();
$r = pair_with( signed_reply() );
check( 'H1 a fresh lock means busy and no transport call', 'busy' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
$GLOBALS['tz_opts'][ TZFD_AI_Pairing::LOCK_OPTION ] = time() - 120;
$r = pair_with( signed_reply() );
check( 'H2 a stale lock (over 30 seconds) is taken over and pairing proceeds', 'paired' === $r['status'] && ! lock_held() );

// ---- I. Constants win over filters (defined last: a constant can be set only once) ----
define( 'TZFD_AI_PAIRING_ENDPOINT', ENDPOINT );
define( 'TZFD_CENTRAL_SITE_SECRET', $GLOBALS['tz_store'] );
reset_state();
use_endpoint( OTHER_END );
add_filter( 'tzfd_central_site_secret', function () { return bin2hex( random_bytes( 32 ) ); } );
check( 'I1 the TZFD_AI_PAIRING_ENDPOINT constant wins over the filter', ENDPOINT === TZFD_AI_Pairing::endpoint() );
check( 'I2 the TZFD_CENTRAL_SITE_SECRET constant wins over the filter', $GLOBALS['tz_store'] === TZFD_Central_Tenant::provisioned_secret() );
$r = pair_with( signed_reply() );
check( 'I3 pairing through the constants sends to the constant endpoint and pairs', 'paired' === $r['status'] && ENDPOINT === $GLOBALS['tz_calls'][0]['url'] );

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( 0 === $GLOBALS['tz_fail'] ? 0 : 1 );
