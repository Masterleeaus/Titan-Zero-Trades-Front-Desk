<?php
/**
 * Offline tests for the hosted account pairing (issue #347): TZFD_AI_Pairing.
 *
 * Run: php -l on the changed files, then  php -d error_reporting=-1 tests/ai-connector/run-pairing-tests.php
 * No WordPress install and no network. Options and filters are in-memory shims. The transport is replaced
 * through the tzfd_ai_pairing_transport filter, so no request leaves the process.
 *
 * Order matters: the pairing constant can be defined only once, so the constant checks run last.
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tz_opts']    = array();
$GLOBALS['tz_filters'] = array();
$GLOBALS['tz_calls']   = array();
$GLOBALS['tz_reply']   = null;

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
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-pairing.php';

const ENDPOINT   = 'https://central.titanzero.io/api/tzfd/pair';
const OTHER_END  = 'https://central-staging.titanzero.io/api/tzfd/pair';
const FEATURE    = 'https://central.titanzero.io/api/tzfd/ai';
const CODE       = 'ABCD-1234-EFGH';
const SECRET_A   = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const SECRET_B   = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

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
    add_filter( 'tzfd_ai_pairing_transport', function ( $raw, $request ) {
        $GLOBALS['tz_calls'][] = $request;
        $r = $GLOBALS['tz_reply'];
        return is_callable( $r ) ? $r( $request ) : $r;
    }, 10, 2 );
}

function use_endpoint( $url ) {
    add_filter( 'tzfd_ai_pairing_endpoint', function () use ( $url ) { return $url; } );
}

/** A well-formed hosted reply that echoes the request's site and nonce. $o overrides fields; null removes one. */
function ok_reply( array $o = array() ) {
    return function ( $request ) use ( $o ) {
        $sent = json_decode( $request['body'], true );
        $data = array(
            'contract'         => 'central-pairing/1',
            'site'             => $sent['site'],
            'nonce'            => $sent['nonce'],
            'secret'           => SECRET_A,
            'feature_endpoint' => FEATURE,
        );
        foreach ( $o as $k => $v ) {
            if ( null === $v ) { unset( $data[ $k ] ); } else { $data[ $k ] = $v; }
        }
        return array( 'status_code' => 200, 'body' => json_encode( $data ) );
    };
}

function pair_with( $reply, $code = CODE ) {
    $GLOBALS['tz_reply'] = $reply;
    $GLOBALS['tz_calls'] = array();
    return TZFD_AI_Pairing::pair( $code );
}

function stored_secret() { return get_option( TZFD_AI_Connector::SECRET_OPTION, '' ); }
function stored_endpoint() { $o = get_option( TZFD_AI_Connector::OPTION, array() ); return isset( $o['endpoint'] ) ? $o['endpoint'] : ''; }
function stored_consent() { $o = get_option( TZFD_AI_Connector::OPTION, array() ); return ! empty( $o['consent'] ); }
function lock_held() { return array_key_exists( TZFD_AI_Pairing::LOCK_OPTION, $GLOBALS['tz_opts'] ); }

// ---- A. Endpoint: off by default, https only (no constant yet) ----
reset_state();
check( 'A1 endpoint is empty with no constant or filter (off by default)', '' === TZFD_AI_Pairing::endpoint() );
$r = pair_with( ok_reply() );
check( 'A2 pairing is off by default: status pairing_off and no transport call', 'pairing_off' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
check( 'A3 pairing off stores nothing (no site id, no secret)', ! array_key_exists( TZFD_AI_Pairing::SITE_OPTION, $GLOBALS['tz_opts'] ) && '' === stored_secret() );

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
$r = pair_with( ok_reply(), '' );
check( 'B1 empty code is invalid_code and sends nothing', 'invalid_code' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
$r = pair_with( ok_reply(), 'ABC D12345' );
check( 'B2 code with a space is invalid_code', 'invalid_code' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
$r = pair_with( ok_reply(), 'ABC12' );
check( 'B3 code shorter than 6 characters is invalid_code', 'invalid_code' === $r['status'] );
$r = pair_with( ok_reply(), str_repeat( 'A', 65 ) );
check( 'B4 code longer than 64 characters is invalid_code', 'invalid_code' === $r['status'] );
$r = pair_with( ok_reply(), array( CODE ) );
check( 'B5 a non-string code is invalid_code', 'invalid_code' === $r['status'] );
$r = pair_with( ok_reply() );
check( 'B6 a valid code reaches the transport exactly once', 'paired' === $r['status'] && 1 === count( $GLOBALS['tz_calls'] ) );

// ---- C. Wire format ----
$req = $GLOBALS['tz_calls'][0];
check( 'C1 request method is POST', isset( $req['method'] ) && 'POST' === $req['method'] );
check( 'C2 request goes to the configured endpoint', ENDPOINT === $req['url'] );
$sent = json_decode( $req['body'], true );
check( 'C0 the contract constant is central-pairing/1', 'central-pairing/1' === TZFD_AI_Pairing::CONTRACT );
check( 'C3 request body is JSON with contract central-pairing/1', is_array( $sent ) && 'central-pairing/1' === $sent['contract'] );
check( 'C4 request body carries the site id and a 32-character hex nonce', is_array( $sent ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $sent['nonce'] ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $sent['site'] ) );
check( 'C5 request body carries the pairing code', is_array( $sent ) && CODE === $sent['code'] );
check( 'C6 request body carries no secret', false === strpos( $req['body'], 'secret' ) );
check( 'C7 timeout is 8 seconds and redirects are not followed', 8 === $req['timeout'] && 0 === $req['redirection'] );
check( 'C8 request is sent as application/json', 'application/json' === $req['headers']['Content-Type'] );

// ---- D. Happy path and rotation ----
reset_state();
use_endpoint( ENDPOINT );
TZFD_AI_Connector::grant_consent( array( 'booking_details' ) );
$r = pair_with( ok_reply() );
check( 'D1 a valid pairing returns paired', 'paired' === $r['status'] );
check( 'D2 the secret is stored in the connector secret option', SECRET_A === stored_secret() );
check( 'D3 the connector endpoint is the feature endpoint from the reply', FEATURE === stored_endpoint() );
$o = get_option( TZFD_AI_Connector::OPTION, array() );
$site = TZFD_AI_Pairing::site_id();
check( 'D4 the connector site id is the pairing site id', isset( $o['site_id'] ) && $site === $o['site_id'] );
check( 'D5 the result holds no secret and no code', false === strpos( json_encode( $r ), SECRET_A ) && false === strpos( json_encode( $r ), CODE ) );
check( 'D6 the pairing code is not stored in any option', false === strpos( serialize( $GLOBALS['tz_opts'] ), CODE ) );
check( 'D7 the pairing lock is released after success', ! lock_held() );
check( 'D8 consent granted before pairing is revoked by pairing', ! stored_consent() );

$site_before = TZFD_AI_Pairing::site_id();
$r = pair_with( ok_reply( array( 'secret' => SECRET_B ) ) );
check( 'D9 rotation: a second pairing replaces the secret', 'paired' === $r['status'] && SECRET_B === stored_secret() );
check( 'D10 the site id is stable across re-pairing', $site_before === TZFD_AI_Pairing::site_id() && $site_before === $site );

// ---- E. Failed exchanges keep the existing connection ----
reset_state();
use_endpoint( ENDPOINT );
TZFD_AI_Connector::grant_consent( array( 'booking_details' ) );
pair_with( ok_reply() );
TZFD_AI_Connector::grant_consent( array( 'booking_details' ) );
$keep = function ( $label, $r, $status ) {
    check( $label, $status === $r['status'] && SECRET_A === stored_secret() && FEATURE === stored_endpoint() && ! lock_held() && true === stored_consent() );
};
$keep( 'E1 HTTP 500 is rejected, old secret and endpoint kept, lock released, consent kept', pair_with( function () { return array( 'status_code' => 500, 'body' => '' ); } ), 'rejected' );
$keep( 'E2 a non-JSON body is invalid_response and keeps the old connection', pair_with( function () { return array( 'status_code' => 200, 'body' => 'not json' ); } ), 'invalid_response' );
$keep( 'E3 a wrong contract is invalid_response', pair_with( ok_reply( array( 'contract' => 'central-pairing/0' ) ) ), 'invalid_response' );
$keep( 'E4 a site echo that does not match is invalid_response', pair_with( ok_reply( array( 'site' => 'someone-else' ) ) ), 'invalid_response' );
$keep( 'E5 a nonce echo that does not match is invalid_response', pair_with( ok_reply( array( 'nonce' => str_repeat( '0', 32 ) ) ) ), 'invalid_response' );
$keep( 'E6 a 63-character secret is invalid_response', pair_with( ok_reply( array( 'secret' => str_repeat( 'a', 63 ) ) ) ), 'invalid_response' );
$keep( 'E7 an uppercase-hex secret is invalid_response', pair_with( ok_reply( array( 'secret' => strtoupper( SECRET_B ) ) ) ), 'invalid_response' );
$keep( 'E8 a reply without a feature endpoint is invalid_response', pair_with( ok_reply( array( 'feature_endpoint' => null ) ) ), 'invalid_response' );
$keep( 'E9 a feature endpoint on another host is invalid_response', pair_with( ok_reply( array( 'feature_endpoint' => 'https://evil.example.test/ai' ) ) ), 'invalid_response' );
$keep( 'E10 a feature endpoint over http is invalid_response', pair_with( ok_reply( array( 'feature_endpoint' => 'http://central.titanzero.io/ai' ) ) ), 'invalid_response' );
$keep( 'E11 a transport error is offline and keeps the old connection', pair_with( function () { return array( 'error' => 'offline' ); } ), 'offline' );
$keep( 'E12 a reply over 16384 bytes is invalid_response', pair_with( function () { return array( 'status_code' => 200, 'body' => str_repeat( 'x', 16385 ) ); } ), 'invalid_response' );
$r = pair_with( function () { throw new RuntimeException( 'boom' ); } );
check( 'E13 a transport exception is offline, not a fatal error, and keeps the old secret', 'offline' === $r['status'] && SECRET_A === stored_secret() && ! lock_held() );

// ---- F. Lock ----
reset_state();
use_endpoint( ENDPOINT );
$GLOBALS['tz_opts'][ TZFD_AI_Pairing::LOCK_OPTION ] = time();
$r = pair_with( ok_reply() );
check( 'F1 a fresh lock means busy and no transport call', 'busy' === $r['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
$GLOBALS['tz_opts'][ TZFD_AI_Pairing::LOCK_OPTION ] = time() - 120;
$r = pair_with( ok_reply() );
check( 'F2 a stale lock (over 30 seconds) is taken over and pairing proceeds', 'paired' === $r['status'] && ! lock_held() );

// ---- G. Pairing constant wins over the filter (defined last: a constant can be set only once) ----
define( 'TZFD_AI_PAIRING_ENDPOINT', ENDPOINT );
reset_state();
use_endpoint( OTHER_END );
check( 'G1 the TZFD_AI_PAIRING_ENDPOINT constant wins over the filter', ENDPOINT === TZFD_AI_Pairing::endpoint() );
$r = pair_with( ok_reply() );
check( 'G2 pairing through the constant sends the request to the constant endpoint', 'paired' === $r['status'] && ENDPOINT === $GLOBALS['tz_calls'][0]['url'] );

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( 0 === $GLOBALS['tz_fail'] ? 0 : 1 );
