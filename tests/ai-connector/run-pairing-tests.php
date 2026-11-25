<?php
/**
 * Offline tests for TZFD_AI_Pairing (contract tzfd-pair/1, issue #347).
 *
 * Run: php -l on the pairing class, then  php tests/ai-connector/run-pairing-tests.php
 * No WordPress install and no network: option storage and filters are in-memory shims, and every
 * pairing call goes through the fake transport below. Exits 1 on any failed check.
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

require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-pairing.php';

const ENDPOINT = 'https://pair.example.test/api/tzfd/pair';
const SECRET   = 'a1b2c3d4e5f60718293a4b5c6d7e8f901a2b3c4d5e6f708192a3b4c5d6e7f809';
const CODE     = 'ABCD-1234-EFGH-5678';

$GLOBALS['tz_fail'] = 0;
$GLOBALS['tz_pass'] = 0;
function check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

/** Builds a fake pairing reply. Overrides let a test tamper with any part. */
function fake_pair_reply( array $req, array $o = array() ) {
    $ts    = isset( $o['ts'] ) ? $o['ts'] : (string) time();
    $nonce = isset( $o['nonce'] ) ? $o['nonce'] : $req['headers']['X-TZFD-Nonce'];
    $site  = isset( $o['site'] ) ? $o['site'] : $req['headers']['X-TZFD-Site'];
    $json  = isset( $o['json'] ) ? $o['json'] : array(
        'contract'         => 'tzfd-pair/1',
        'site'             => $site,
        'status'           => 'paired',
        'secret'           => isset( $o['secret_value'] ) ? $o['secret_value'] : SECRET,
        'feature_endpoint' => isset( $o['feature'] ) ? $o['feature'] : 'https://pair.example.test/api/tzfd/ai',
    );
    $body = isset( $o['body'] ) ? $o['body'] : json_encode( $json );
    return array(
        'status_code' => isset( $o['code'] ) ? $o['code'] : 200,
        'headers'     => array( 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Nonce' => $nonce ),
        'body'        => $body,
    );
}

add_filter( 'tzfd_ai_pairing_transport', function ( $value, $req ) {
    $GLOBALS['tz_calls'][] = $req;
    $reply = $GLOBALS['tz_reply'];
    return is_callable( $reply ) ? $reply( $req ) : array( 'error' => 'offline' );
}, 10, 2 );

function reset_state() {
    $GLOBALS['tz_opts']  = array();
    $GLOBALS['tz_calls'] = array();
    $GLOBALS['tz_reply'] = null;
}
function calls() { return count( $GLOBALS['tz_calls'] ); }
function set_endpoint( $url ) {
    $GLOBALS['tz_filters']['tzfd_ai_pairing_endpoint'] = array( function () use ( $url ) { return $url; } );
}
function clear_endpoint_filter() { unset( $GLOBALS['tz_filters']['tzfd_ai_pairing_endpoint'] ); }

$P = 'TZFD_AI_Pairing';
$C = 'TZFD_AI_Connector';

// ---- 1. Defaults fail closed -------------------------------------------------

reset_state();
clear_endpoint_filter();
check( 'no endpoint is built in: pairing is off by default', '' === $P::endpoint() && false === $P::endpoint_configured() );
$r = $P::pair( CODE );
check( 'pairing without an endpoint returns not_configured and sends nothing', 'not_configured' === $r['status'] && false === $r['ok'] && true === $r['use_local'] && 0 === calls() );
check( 'a site id is still created locally without any network use', 1 === preg_match( '/^site-[a-f0-9]{24}$/', $P::site_id() ) );

reset_state();
set_endpoint( 'http://pair.example.test/api/tzfd/pair' );
check( 'http endpoint is ignored', '' === $P::endpoint() );
set_endpoint( 'https://user:pw@pair.example.test/api' );
check( 'endpoint with userinfo is ignored', '' === $P::endpoint() );
set_endpoint( 'not a url' );
check( 'malformed endpoint is ignored', '' === $P::endpoint() );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req ); };
$r = $P::pair( CODE );
check( 'http endpoint never reaches the transport', 'not_configured' === $r['status'] && 0 === calls() );
clear_endpoint_filter();

// ---- 2. Input validation -----------------------------------------------------

reset_state();
set_endpoint( ENDPOINT );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req ); };
foreach ( array( '', 'short', str_repeat( 'a', 65 ), 'has space in code 1', 'semi;colon-code-xx', null, 12345678901234567 ) as $bad ) {
    $r = $P::pair( $bad );
    if ( 'invalid_code' !== $r['status'] ) { check( 'invalid code is refused', false, json_encode( $bad ) ); }
}
check( 'malformed codes are refused before any transport call', 0 === calls() );
check( 'a valid code shape is accepted for the exchange', 'ok' === $P::pair( CODE )['status'] && 1 === calls() );

// ---- 3. Wire format ----------------------------------------------------------

$req = $GLOBALS['tz_calls'][0];
$h   = $req['headers'];
$body = json_decode( $req['body'], true );
check( 'request goes to the pairing endpoint over https', ENDPOINT === $req['url'] && 0 === strpos( $req['url'], 'https://' ) );
check( 'request carries contract, site and a 32-hex nonce', 'tzfd-pair/1' === $h['X-TZFD-Contract'] && 1 === preg_match( '/^site-[a-f0-9]{24}$/', $h['X-TZFD-Site'] ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $h['X-TZFD-Nonce'] ) );
check( 'the pairing code travels only in the JSON body', CODE === $body['pairing_code'] && false === strpos( json_encode( $h ) . $req['url'], CODE ) );
check( 'no secret is sent before one exists', false === strpos( json_encode( $req ), SECRET ) );

// ---- 4. Happy path and storage -----------------------------------------------

reset_state();
set_endpoint( ENDPOINT );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req ); };
$C_grant = TZFD_AI_Connector::grant_consent( array( 'faq.read' ) );
$r = $P::pair( CODE );
check( 'happy path returns ok without secret or code in the result', true === $r['ok'] && 'ok' === $r['status'] && false === $r['use_local'] && false === strpos( json_encode( $r ), SECRET ) && false === strpos( json_encode( $r ), CODE ) );
$st = $C::status();
check( 'connector now holds the endpoint and the secret', true === $st['endpoint_set'] && true === $st['secret_set'] && $P::site_id() === $st['site_id'] );
check( 'feature endpoint comes from the paired response', 'https://pair.example.test/api/tzfd/ai' === ( get_option( 'tzfd_ai_connector', array() )['endpoint'] ?? '' ) );
check( 'pairing resets consent so scopes must be granted again', false === $st['consent'] && array() === $st['scopes'] );
check( 'paired status is reported without the secret', true === $P::status()['paired'] && false === strpos( json_encode( array( $P::status(), $st ) ), SECRET ) );
check( 'the code is not stored anywhere', false === strpos( json_encode( $GLOBALS['tz_opts'] ), CODE ) );
check( 'the lock is released after a successful pairing', ! array_key_exists( 'tzfd_ai_pairing_lock', $GLOBALS['tz_opts'] ) );

$site_before = $P::site_id();
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req, array( 'secret_value' => str_repeat( 'b', 64 ) ) ); };
$r2 = $P::pair( 'WXYZ-9876-QRST-5432' );
check( 'a second pairing rotates the secret and keeps the site id', 'ok' === $r2['status'] && $site_before === $P::site_id() && str_repeat( 'b', 64 ) === get_option( 'tzfd_ai_connector_secret' ) );

// ---- 5. Negative paths, nothing stored -----------------------------------------

/** Runs one tampered reply on a fresh state and returns [result, stored secret or null]. */
function attempt( $reply_fn ) {
    reset_state();
    set_endpoint( ENDPOINT );
    $GLOBALS['tz_reply'] = $reply_fn;
    $r = TZFD_AI_Pairing::pair( CODE );
    return array( $r, get_option( 'tzfd_ai_connector_secret', null ) );
}

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'nonce' => str_repeat( '0', 32 ) ) ); } );
check( 'replayed or cross-request nonce is invalid_response and stores nothing', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'site' => 'site-other' ) ); } );
check( 'answer for another site is invalid_response', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://evil.example.test/ai' ) ); } );
check( 'feature endpoint on another host is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'http://pair.example.test/ai' ) ); } );
check( 'feature endpoint over http is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'secret_value' => 'short' ) ); } );
check( 'short secret is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'secret_value' => strtoupper( SECRET ) ) ); } );
check( 'secret that is not 64 lowercase hex is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'json' => array( 'contract' => 'tzfd-pair/1', 'site' => 'x', 'status' => 'paired' ) ) ); } );
check( 'answer missing its fields is invalid_response', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'ts' => (string) ( time() - 3600 ) ) ); } );
check( 'stale response timestamp is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'body' => str_repeat( 'x', 9000 ) ) ); } );
check( 'oversized response is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'body' => '{not json' ) ); } );
check( 'non-JSON response is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'json' => array( 'contract' => 'tzfd-pair/1', 'site' => $req['headers']['X-TZFD-Site'], 'status' => 'pending' ) ) ); } );
check( 'unknown pairing status is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'code' => 401 ) ); } );
check( 'a 401 from the service means code_rejected', 'code_rejected' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'json' => array( 'contract' => 'tzfd-pair/1', 'site' => $req['headers']['X-TZFD-Site'], 'status' => 'code_rejected' ) ) ); } );
check( 'a signed code_rejected status maps to code_rejected', 'code_rejected' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'code' => 503 ) ); } );
check( 'a 5xx from the service means service_error', 'service_error' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return array( 'error' => 'offline' ); } );
check( 'transport failure means offline with use_local', 'offline' === $r['status'] && true === $r['use_local'] && null === $s );

// Feature endpoint on the Supercharged host (allowlist default, 2026-11-25).
list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://superchargedat.work/api/tzfd/ai' ) ); } );
check( 'feature endpoint on the default Supercharged host is accepted', 'ok' === $r['status'] && SECRET === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://SuperchargedAT.work/api/tzfd/ai' ) ); } );
check( 'the allowlisted host is matched case-insensitively', 'ok' === $r['status'] );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://api.superchargedat.work/ai' ) ); } );
check( 'a subdomain of the allowlisted host is refused (exact match only)', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://superchargedat.work.evil.example.test/ai' ) ); } );
check( 'a lookalike host is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://evilsuperchargedat.work/ai' ) ); } );
check( 'a different domain ending in the same letters is refused', 'invalid_response' === $r['status'] && null === $s );

list( $r, $s ) = attempt( function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'http://superchargedat.work/ai' ) ); } );
check( 'the Supercharged host over http is refused', 'invalid_response' === $r['status'] && null === $s );

reset_state();
set_endpoint( ENDPOINT );
add_filter( 'tzfd_ai_feature_hosts', function () { return array( 'Other-Feature.example.test', 'not a host', 42 ); } );
check( 'the feature allowlist filter replaces the default and drops invalid entries', array( 'other-feature.example.test' ) === $P::feature_hosts() );
$GLOBALS['tz_filters']['tzfd_ai_feature_hosts'] = array( function () { return array( 'other-feature.example.test' ); } );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://other-feature.example.test/ai' ) ); };
check( 'a host added through the filter is accepted', 'ok' === $P::pair( CODE )['status'] );
$GLOBALS['tz_filters']['tzfd_ai_feature_hosts'] = array( function () { return array(); } );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req, array( 'feature' => 'https://superchargedat.work/ai' ) ); };
check( 'an empty filter leaves only the pairing host allowed', 'invalid_response' === $P::pair( 'WXYZ-9876-QRST-5432' )['status'] );
unset( $GLOBALS['tz_filters']['tzfd_ai_feature_hosts'] );
clear_endpoint_filter();

// A failed exchange keeps the previous connection intact.
reset_state();
set_endpoint( ENDPOINT );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req ); };
$P::pair( CODE );
$before = get_option( 'tzfd_ai_connector_secret' );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req, array( 'code' => 403 ) ); };
$r = $P::pair( 'WXYZ-9876-QRST-5432' );
check( 'a failed rotation leaves the previous secret in place', 'code_rejected' === $r['status'] && $before === get_option( 'tzfd_ai_connector_secret' ) );

// ---- 6. Concurrency ------------------------------------------------------------

reset_state();
set_endpoint( ENDPOINT );
update_option( 'tzfd_ai_pairing_lock', time(), false );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req ); };
$r = $P::pair( CODE );
check( 'a fresh lock held by another pairing returns busy with no call', 'busy' === $r['status'] && 0 === calls() );

reset_state();
set_endpoint( ENDPOINT );
update_option( 'tzfd_ai_pairing_lock', time() - 3600, false );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_pair_reply( $req ); };
$r = $P::pair( CODE );
check( 'a stale lock from a crashed pairing is taken over', 'ok' === $r['status'] && 1 === calls() );

// ---- 7. Endpoint from a constant -------------------------------------------------

define( 'TZFD_AI_PAIRING_ENDPOINT', 'https://const.example.test/pair' );
clear_endpoint_filter();
check( 'the wp-config constant sets the endpoint when no filter overrides it', 'https://const.example.test/pair' === $P::endpoint() );
set_endpoint( 'https://filter.example.test/pair' );
check( 'the filter overrides the constant', 'https://filter.example.test/pair' === $P::endpoint() );
clear_endpoint_filter();

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
