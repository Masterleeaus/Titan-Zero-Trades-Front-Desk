<?php
/**
 * Offline tests for TZFD_AI_Connector (contract tzfd-ai/1).
 *
 * Run: php -l and then  php tests/ai-connector/run-ai-connector-tests.php
 * No WordPress install and no network: option storage and filters are in-memory shims,
 * and every provider call goes through the fake transport below.
 */
define( 'ABSPATH', __DIR__ . '/' );

// Shim for WordPress core wp_parse_url(); the fixtures need only the scheme, host and port parts.
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }

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

const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';
const SITE   = 'site-a';

$GLOBALS['tz_fail'] = 0;
$GLOBALS['tz_pass'] = 0;
function check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

/** Builds a fake service reply. Overrides let a test tamper with any part. */
function fake_reply( array $req, array $o = array() ) {
    $ts     = isset( $o['ts'] ) ? $o['ts'] : (string) time();
    $nonce  = isset( $o['nonce'] ) ? $o['nonce'] : $req['headers']['X-TZFD-Nonce'];
    $json   = isset( $o['json'] ) ? $o['json'] : array(
        'contract' => 'tzfd-ai/1', 'site' => isset( $o['site'] ) ? $o['site'] : SITE,
        'status'   => 'ok', 'data' => array( 'answer' => 'Open 8 to 5' ),
        'usage'    => array( 'tokens_in' => 10, 'tokens_out' => 20, 'cache_hit' => false ),
    );
    $body   = isset( $o['body'] ) ? $o['body'] : json_encode( $json );
    $secret = isset( $o['secret'] ) ? $o['secret'] : SECRET;
    $sig    = isset( $o['sig'] ) ? $o['sig'] : TZFD_AI_Connector::sign( $secret, $ts, $nonce, $body );
    return array(
        'status_code' => isset( $o['code'] ) ? $o['code'] : 200,
        'headers'     => array( 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Nonce' => $nonce, 'X-TZFD-Signature' => $sig ),
        'body'        => $body,
    );
}

add_filter( 'tzfd_ai_connector_transport', function ( $value, $req ) {
    $GLOBALS['tz_calls'][] = $req;
    $reply = $GLOBALS['tz_reply'];
    if ( null === $reply ) { return array( 'error' => 'offline' ); }
    return is_callable( $reply ) ? $reply( $req ) : $reply;
}, 10, 2 );

function reset_state() {
    $GLOBALS['tz_opts']  = array();
    $GLOBALS['tz_calls'] = array();
    $GLOBALS['tz_reply'] = null;
    $GLOBALS['tz_filters']['tzfd_ai_connector_budget_limits'] = array();
}
function connect_all() {
    TZFD_AI_Connector::configure( 'https://ai.example.test/v1/request', SITE, SECRET );
    TZFD_AI_Connector::grant_consent( array( 'faq.read', 'reports.read', 'customer.read', 'booking.read', 'booking.write' ) );
    TZFD_AI_Connector::set_entitlements( array( 'free_ai', 'assistant', 'agent_team' ) );
}
function calls() { return count( $GLOBALS['tz_calls'] ); }
function with_limits( array $limits ) {
    $GLOBALS['tz_filters']['tzfd_ai_connector_budget_limits'] = array(
        function ( $l ) use ( $limits ) { return $limits + $l; },
    );
}
$C = 'TZFD_AI_Connector';

// 1. Defaults are off: no consent, no transport.
reset_state();
$r = $C::request( 'faq.answer', array( 'q' => 'hours' ) );
check( 'consent is off by default and blocks the call', 'consent_required' === $r['status'] && true === $r['use_local'] && 0 === calls() );

// 2. Consent without a configured endpoint fails safe.
reset_state();
$C::grant_consent( array( 'faq.read' ) );
$C::set_entitlements( array( 'free_ai' ) );
$r = $C::request( 'faq.answer' );
check( 'unset endpoint returns not_configured without transport', 'not_configured' === $r['status'] && 0 === calls() );

// 3. Configure validation.
reset_state();
check( 'http endpoint rejected', false === $C::configure( 'http://ai.example.test/x', SITE, SECRET ) );
check( 'short secret rejected', false === $C::configure( 'https://ai.example.test/x', SITE, 'short' ) );
check( 'bad site id rejected', false === $C::configure( 'https://ai.example.test/x', "a b\n", SECRET ) );
check( 'valid configuration accepted', true === $C::configure( 'https://ai.example.test/x', SITE, SECRET ) );

// 4. Tier and scope gates.
reset_state();
connect_all();
$C::set_entitlements( array( 'free_ai' ) );
$r = $C::request( 'assistant.turn' );
check( 'unentitled tier is refused before transport', 'tier_not_entitled' === $r['status'] && 0 === calls() );
$C::set_entitlements( array( 'free_ai', 'agent_team' ) );
$C::grant_consent( array( 'faq.read' ) );
$r = $C::request( 'agent.run' );
check( 'scope not granted by admin is refused', 'scope_not_granted' === $r['status'] && in_array( 'booking.write', $r['missing_scopes'], true ) && 0 === calls() );
$C::grant_consent( array( 'faq.read', 'bogus.scope' ) );
check( 'unknown scopes are dropped on grant', array( 'faq.read' ) === $C::status()['scopes'] );

// 5. Unknown feature and invalid payload.
reset_state();
connect_all();
check( 'unknown feature is not allowed', 'feature_not_allowed' === $C::request( 'shell.exec' )['status'] && 0 === calls() );
$r = $C::request( 'faq.answer', array( 'q' => str_repeat( 'x', 20000 ) ) );
check( 'oversized payload is invalid and uncharged', 'invalid_request' === $r['status'] && 0 === calls() && empty( $C::usage()['budget'] ) );

// 6. Happy path: signed request, verified response, no secret in the wire body or result.
reset_state();
connect_all();
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$r = $C::request( 'faq.answer', array( 'q' => 'hours' ) );
check( 'happy path returns ok with data', true === $r['ok'] && 'ok' === $r['status'] && 'Open 8 to 5' === $r['data']['answer'] && false === $r['use_local'] );
$req = $GLOBALS['tz_calls'][0];
$h   = $req['headers'];
check( 'request carries contract, site and a 32-hex nonce', 'tzfd-ai/1' === $h['X-TZFD-Contract'] && SITE === $h['X-TZFD-Site'] && 1 === preg_match( '/^[0-9a-f]{32}$/', $h['X-TZFD-Nonce'] ) );
check( 'request signature verifies over timestamp.nonce.body', hash_equals( $C::sign( SECRET, $h['X-TZFD-Timestamp'], $h['X-TZFD-Nonce'], $req['body'] ), $h['X-TZFD-Signature'] ) );
check( 'secret never appears in the wire request', false === strpos( json_encode( $req ), SECRET ) );
check( 'secret never appears in result, status or usage', false === strpos( json_encode( array( $r, $C::status(), $C::usage() ) ), SECRET ) );
check( 'request uses https endpoint only', 0 === strpos( $req['url'], 'https://' ) );

// 7. Budget: reserve before the call, refuse with allowance_exhausted, concurrent nested call sees the reservation.
reset_state();
connect_all();
with_limits( array( 'model_fallback' => 2 ) );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$C::request( 'faq.answer' );
$C::request( 'faq.answer' );
$before = calls();
$r = $C::request( 'faq.answer' );
check( 'third call over a limit of 2 is allowance_exhausted', 'allowance_exhausted' === $r['status'] && true === $r['use_local'] && 2 === $before && 2 === calls() );

reset_state();
connect_all();
with_limits( array( 'model_fallback' => 1 ) );
$nested = null;
$GLOBALS['tz_reply'] = function ( $req ) use ( $C, &$nested ) {
    if ( null === $nested ) { $nested = $C::request( 'faq.answer' ); }
    return fake_reply( $req );
};
$outer = $C::request( 'faq.answer' );
check( 'reservation happens before the provider call (nested call is refused)', 'allowance_exhausted' === $nested['status'] && 'ok' === $outer['status'] && 1 === calls() );

// 8. Offline service: local fallback and the reservation is returned.
reset_state();
connect_all();
$GLOBALS['tz_reply'] = null; // fake transport reports offline
$r = $C::request( 'faq.answer' );
check( 'offline service returns offline with use_local', 'offline' === $r['status'] && true === $r['use_local'] );
check( 'offline call gives its budget unit back', 0 === ( $C::usage()['budget']['model_fallback'] ?? 0 ) );

// 9. Default transport is not reachable in this harness (no wp_remote_post): offline, never a network call.
reset_state();
connect_all();
$saved_transport = $GLOBALS['tz_filters']['tzfd_ai_connector_transport'];
$GLOBALS['tz_filters']['tzfd_ai_connector_transport'] = array();
$r = $C::request( 'faq.answer' );
check( 'without a transport filter the connector degrades to offline', 'offline' === $r['status'] && 0 === calls() );
$GLOBALS['tz_filters']['tzfd_ai_connector_transport'] = $saved_transport;

// 10. Tamper and replay checks on the response.
$cases = array(
    'bad signature'          => array( 'sig' => 'sha256=' . str_repeat( '0', 64 ) ),
    'signed with other key'  => array( 'secret' => str_repeat( 'z', 48 ) ),
    'nonce not echoed'       => array( 'nonce' => str_repeat( 'a', 32 ) ),
    'stale timestamp'        => array( 'ts' => (string) ( time() - 1000 ) ),
    'wrong tenant (signed)'  => array( 'site' => 'site-b' ),
    'non-json body'          => array( 'body' => 'not json' ),
    'oversized response'     => array( 'body' => json_encode( array( 'contract' => 'tzfd-ai/1', 'site' => SITE, 'status' => 'ok', 'data' => array( 'x' => str_repeat( 'a', 70000 ) ) ) ) ),
);
foreach ( $cases as $label => $o ) {
    reset_state();
    connect_all();
    $GLOBALS['tz_reply'] = function ( $req ) use ( $o ) { return fake_reply( $req, $o ); };
    $r = $C::request( 'faq.answer' );
    check( "response rejected: $label", 'invalid_response' === $r['status'] && true === $r['use_local'], $r['status'] );
}
reset_state();
connect_all();
$GLOBALS['tz_reply'] = function ( $req ) { $r = fake_reply( $req ); $r['headers'] = array_map( 'strval', $r['headers'] ); unset( $r['headers']['X-TZFD-Signature'] ); return $r; };
check( 'missing signature header is rejected', 'invalid_response' === $C::request( 'faq.answer' )['status'] );

// 11. Service status codes and signed service statuses.
$statuses = array(
    'http 500 is service_error'          => array( 'code' => 500 ),
    'signed service_error'              => array( 'json' => array( 'contract' => 'tzfd-ai/1', 'site' => SITE, 'status' => 'service_error' ) ),
    'signed allowance_exhausted'        => array( 'json' => array( 'contract' => 'tzfd-ai/1', 'site' => SITE, 'status' => 'allowance_exhausted' ) ),
    'signed contract_unsupported'       => array( 'json' => array( 'contract' => 'tzfd-ai/9', 'site' => SITE, 'status' => 'contract_unsupported' ) ),
);
$expect = array( 'http 500 is service_error' => 'service_error', 'signed service_error' => 'service_error', 'signed allowance_exhausted' => 'allowance_exhausted', 'signed contract_unsupported' => 'contract_unsupported' );
foreach ( $statuses as $label => $o ) {
    reset_state();
    connect_all();
    $GLOBALS['tz_reply'] = function ( $req ) use ( $o ) { return fake_reply( $req, $o ); };
    $r = $C::request( 'faq.answer' );
    check( "status mapping: $label", $expect[ $label ] === $r['status'] && true === $r['use_local'], $r['status'] );
}

// 12. Revocation takes effect at once; disconnect clears the secret.
reset_state();
connect_all();
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
check( 'call succeeds before revocation', 'ok' === $C::request( 'faq.answer' )['status'] );
$C::revoke_consent();
$before = calls();
check( 'revocation blocks the next call with no transport', 'consent_required' === $C::request( 'faq.answer' )['status'] && $before === calls() );
connect_all();
$C::disconnect();
check( 'disconnect removes the secret', false === $C::status()['secret_set'] && false === $C::status()['connected'] );

// 13. Usage counters and cache hits are recorded; allowance resets by month.
reset_state();
connect_all();
$GLOBALS['tz_reply'] = function ( $req ) {
    return fake_reply( $req, array( 'json' => array( 'contract' => 'tzfd-ai/1', 'site' => SITE, 'status' => 'ok', 'data' => array(), 'usage' => array( 'tokens_in' => 5, 'tokens_out' => 7, 'cache_hit' => true ) ) ) );
};
$C::request( 'faq.answer' );
$f = $C::usage()['features']['faq.answer'];
check( 'usage records calls, ok, tokens and cache hits', 1 === $f['calls'] && 1 === $f['ok'] && 5 === $f['tokens_in'] && 7 === $f['tokens_out'] && 1 === $f['cache_hits'] );
reset_state();
connect_all();
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$prev = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) );
$GLOBALS['tz_opts'][ TZFD_AI_Connector::USAGE_PREFIX . $prev ] = array( 'budget' => array( 'model_fallback' => 50 ) );
check( 'last month\'s exhausted allowance does not block this month', 'ok' === $C::request( 'faq.answer' )['status'] );

// 14. Stale lock is taken over; a live lock makes the request wait (busy) without a call.
reset_state();
connect_all();
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$GLOBALS['tz_opts'][ TZFD_AI_Connector::LOCK_OPTION ] = time();
$r = $C::request( 'faq.answer' );
check( 'live budget lock returns busy without a call', 'busy' === $r['status'] && 0 === calls() );
$GLOBALS['tz_opts'][ TZFD_AI_Connector::LOCK_OPTION ] = time() - 60;
check( 'stale budget lock is cleared and the call proceeds', 'ok' === $C::request( 'faq.answer' )['status'] );
check( 'lock is released after use', ! array_key_exists( TZFD_AI_Connector::LOCK_OPTION, $GLOBALS['tz_opts'] ) );

// 15. Entitlements and budgets are per feature.
check( 'budget table covers pilot limits', array( 'model_fallback' => 50, 'embedding' => 200, 'faq_batch' => 4, 'insight' => 1 ) === ( function () use ( $C ) { $GLOBALS['tz_filters']['tzfd_ai_connector_budget_limits'] = array(); return $C::budget_limits(); } )() );

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
