<?php
/**
 * Offline tests for TZFD_AI_Connector (contract tzfd-ai/1).
 *
 * Run: php -l and then  php tests/ai-connector/run-ai-connector-tests.php
 * No WordPress install and no network: option storage and filters are in-memory shims,
 * and every provider call goes through the fake transport below.
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
$C::set_entitlements( array( 'free_ai' ) ); // Only the free entitlement reported: no paid tier is required.
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$r = $C::request( 'assistant.turn' );
check( 'no paid tier gate: assistant turn with consent and scopes reaches transport', 'ok' === $r['status'] && 1 === calls() && false === $r['use_local'] );
$GLOBALS['tz_calls'] = array();
$GLOBALS['tz_reply'] = null;
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
$r = $C::request( 'faq.answer', array( 'q' => 'ok', 'context' => array( 'note' => str_repeat( 'y', 2001 ) ) ) );
check( 'text over 2000 characters is refused even under the byte cap, uncharged', 'invalid_request' === $r['status'] && 0 === calls() && empty( $C::usage()['budget'] ) );
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$r = $C::request( 'faq.answer', array( 'q' => 'ok', 'context' => array( 'note' => str_repeat( 'y', 2000 ) ) ) );
check( 'text of exactly 2000 characters is accepted', 'ok' === $r['status'] && 1 === calls(), $r['status'] . ' calls=' . calls() );

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

// 10b. Replay: a validly signed reply captured for one request must not satisfy the next request.
reset_state();
connect_all();
$captured = null;
$GLOBALS['tz_reply'] = function ( $req ) use ( &$captured ) { $captured = fake_reply( $req ); return $captured; };
$first_reply = $C::request( 'faq.answer' );
$GLOBALS['tz_reply'] = function ( $req ) use ( &$captured ) { return $captured; };
$replayed    = $C::request( 'faq.answer' );
check( 'the first signed reply verifies', 'ok' === $first_reply['status'] );
check( 'a captured valid reply replayed on a new request is refused', 'invalid_response' === $replayed['status'] && true === $replayed['use_local'], $replayed['status'] );

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
$GLOBALS['tz_opts'][ TZFD_AI_Connector::USAGE_PREFIX . $prev ] = array( 'budget' => array( 'model_fallback' => 200 ) );
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

// 15. Entitlements and budgets are per feature. Defaults are the standing per-site monthly contract figures.
$GLOBALS['tz_filters']['tzfd_ai_connector_budget_limits'] = array();
$defaults = $C::budget_limits();
check( 'budget table matches contract figures', array( 'model_fallback' => 200, 'embedding' => 800, 'faq_batch' => 16, 'insight' => 4 ) === $defaults );
check( 'default model_fallback budget is 200 per site per month', 200 === $defaults['model_fallback'] );
check( 'default embedding budget is 800 per site per month', 800 === $defaults['embedding'] );
check( 'default faq_batch budget is 16 per site per month', 16 === $defaults['faq_batch'] );
check( 'default insight budget is 4 per site per month', 4 === $defaults['insight'] );
check( 'budget table has no pilot values left', ! in_array( $defaults['model_fallback'], array( 50 ), true ) && ! in_array( $defaults['faq_batch'], array( 4 ), true ) && ! in_array( $defaults['insight'], array( 1 ), true ) );

// 15b. Issue #331: live set is 'contract'; the pilot set is named and selectable only by the profile filter.
$profiles = $C::budget_profiles();
check( 'profiles hold contract and pilot sets', array( 'contract', 'pilot' ) === array_keys( $profiles ) );
check( 'pilot profile holds provisional #284 figures', array( 'model_fallback' => 50, 'embedding' => 200, 'faq_batch' => 4, 'insight' => 1 ) === $profiles['pilot'] );
$GLOBALS['tz_filters']['tzfd_ai_connector_budget_profile'] = array();
check( 'live budget profile defaults to contract', $profiles['contract'] === $C::budget_limits() );
$GLOBALS['tz_filters']['tzfd_ai_connector_budget_profile'] = array( function () { return 'pilot'; } );
check( 'profile filter can select the pilot set', $profiles['pilot'] === $C::budget_limits() );
$GLOBALS['tz_filters']['tzfd_ai_connector_budget_profile'] = array( function () { return 'unknown'; } );
check( 'unknown profile name falls back to contract', $profiles['contract'] === $C::budget_limits() );
$GLOBALS['tz_filters']['tzfd_ai_connector_budget_profile'] = array();

// 16. Disclosure: provider, data classes, quota; retention is an owner decision (null), never a promise.
reset_state();
$d = $C::disclosure();
check( 'disclosure names provider, data classes and quota', isset( $d['provider'] ) && in_array( 'customer.read', $d['data_classes'], true ) && 200 === $d['quota']['model_fallback'] );
check( 'disclosure leaves retention to the owner (null)', array_key_exists( 'retention', $d ) && null === $d['retention'] && in_array( 'retention', $d['owner_decisions'], true ) );
check( 'disclosure never includes the secret', false === strpos( json_encode( $d ), SECRET ) );

// 16b. AI is on by default; a setup notice names the data and the terms link (placeholder, owner review).
reset_state();
check( 'AI is enabled by default', true === TZFD_AI_Connector::status()['enabled'] );
$n = TZFD_AI_Connector::setup_notice();
check( 'setup notice is marked for owner review and names the data classes', true === $n['owner_review'] && false !== strpos( $n['text'], 'customer.read' ) );
check( 'setup notice records the wording as a proposed default pending owner sign-off', 'agent_proposed_default_pending_owner_signoff' === $n['wording_status'] && false === strpos( $n['text'], 'OWNER REVIEW' ) );
check( 'setup notice carries a consent confirmation that names the withdrawal right', false !== strpos( $n['confirmation'], 'agree' ) && false !== strpos( $n['confirmation'], 'withdraw' ) );
$GLOBALS['tz_filters']['tzfd_ai_consent_confirmation'] = array( function () { return 'Filtered test confirmation.'; } );
check( 'consent confirmation comes from the tzfd_ai_consent_confirmation filter', 'Filtered test confirmation.' === TZFD_AI_Connector::setup_notice()['confirmation'] );
unset( $GLOBALS['tz_filters']['tzfd_ai_consent_confirmation'] );
check( 'setup notice never includes the secret', false === strpos( json_encode( $n ), SECRET ) );
check( 'default terms link is the draft terms page', 'https://titanzero.io/scheduling-enquiries-terms/' === $n['terms_url'] );
$GLOBALS['tz_filters']['tzfd_ai_terms_url'] = array( function () { return 'https://owner.example.test/terms'; } );
check( 'terms link comes from the tzfd_ai_terms_url filter', 'https://owner.example.test/terms' === TZFD_AI_Connector::setup_notice()['terms_url'] );
unset( $GLOBALS['tz_filters']['tzfd_ai_terms_url'] );
TZFD_AI_Connector::set_enabled( false );
check( 'turning AI off makes requests return ai_disabled without transport', 'ai_disabled' === $C::request( 'faq.answer' )['status'] && 0 === count( $GLOBALS['tz_calls'] ) );
TZFD_AI_Connector::set_enabled( true );

// 17. Cost report: counters are summed, cache ratio is hits over successes, money only with a full price table.
reset_state();
$GLOBALS['tz_opts'][ TZFD_AI_Connector::USAGE_PREFIX . gmdate( 'Y-m' ) ] = array(
    'budget'   => array( 'model_fallback' => 3 ),
    'features' => array(
        'faq.answer'      => array( 'calls' => 4, 'ok' => 3, 'fallback_local' => 1, 'tokens_in' => 1000, 'tokens_out' => 500, 'embedding_tokens' => 0, 'cache_hits' => 1, 'latency_ms' => 90 ),
        'faq.embed_query' => array( 'calls' => 2, 'ok' => 2, 'fallback_local' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'embedding_tokens' => 2000, 'cache_hits' => 0, 'latency_ms' => 40 ),
    ),
);
$cr = $C::cost_report();
check( 'cost report sums calls, tokens and embedding tokens', 6 === $cr['totals']['calls'] && 5 === $cr['totals']['ok'] && 1000 === $cr['totals']['tokens_in'] && 2000 === $cr['totals']['embedding_tokens'] );
check( 'cache hit ratio is hits over successful calls', 0.2 === $cr['cache_hit_ratio'] );
check( 'no money figure without a price table', false === $cr['cost_known'] && null === $cr['cost'] );
check( 'incomplete price table produces no cost', false === $C::cost_report( array( 'input' => 0.5, 'output' => 1.5 ) )['cost_known'] );
check( 'negative price is rejected, not used', false === $C::cost_report( array( 'input' => -1, 'output' => 1, 'embedding' => 1 ) )['cost_known'] );
$priced = $C::cost_report( array( 'input' => 0.5, 'output' => 1.5, 'embedding' => 0.1 ) );
check( 'cost equals priced tokens per 1,000 (1000*0.5+500*1.5+2000*0.1)/1000 = 1.45', true === $priced['cost_known'] && 1.45 === $priced['cost'] );
reset_state();
$empty = $C::cost_report( array( 'input' => 1, 'output' => 1, 'embedding' => 1 ) );
check( 'empty month reports zero calls and a zero cache ratio', 0 === $empty['totals']['calls'] && 0.0 === $empty['cache_hit_ratio'] && 0.0 === $empty['cost'] );

// 18. Tenant ceiling: counts at the contract budget ceilings, no money assumed.
$t = $C::tenant_ceiling( 100 );
check( 'tenant ceiling scales each budget by tenant count', 20000 === $t['max_units_by_budget']['model_fallback'] && 80000 === $t['max_units_by_budget']['embedding'] && 1600 === $t['max_units_by_budget']['faq_batch'] && 400 === $t['max_units_by_budget']['insight'] && 102000 === $t['max_units_total'] );
check( 'negative tenant count is clamped to zero', 0 === $C::tenant_ceiling( -5 )['max_units_total'] );
// 19. Consent is bound to the disclosure version; an old consent does not count.
reset_state();
connect_all();
check( 'structured disclosure is present in status', isset( $C::status()['disclosure']['provider'] ) && 'tzfd-ai-consent-1' === $C::status()['consent_version'] );
$GLOBALS['tz_opts'][ TZFD_AI_Connector::OPTION ]['consent_version'] = 'tzfd-ai-consent-0';
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$before = calls();
check( 'consent under an older disclosure version is refused without transport', 'consent_required' === $C::request( 'faq.answer' )['status'] && $before === calls() );
check( 'status reports consent false for an older disclosure version', false === $C::status()['consent'] );
$C::grant_consent( array( 'faq.read' ) );
check( 'granting again under the current version restores the call', 'ok' === $C::request( 'faq.answer' )['status'] );

// 20. Event log: redacted, bounded, cleared on demand, never holds the secret or payload.
reset_state();
connect_all();
$GLOBALS['tz_reply'] = function ( $req ) { return fake_reply( $req ); };
$C::request( 'faq.answer', array( 'question' => 'PRIVATE-QUESTION-TEXT' ) );
$C::request( 'shell.exec' );
$events = $C::events();
check( 'each request writes one event', 2 === count( $events ) && 'faq.answer' === $events[0]['feature'] && 'ok' === $events[0]['status'] );
check( 'unknown feature is logged as unknown, not as the caller string', 'unknown' === $events[1]['feature'] && 'feature_not_allowed' === $events[1]['status'] );
check( 'event entries hold only at, feature, status, latency_ms', array( 'at', 'feature', 'status', 'latency_ms' ) === array_keys( $events[0] ) );
$blob = json_encode( array( $events, $C::status() ) );
check( 'payload and secret never appear in the event log or status', false === strpos( $blob, 'PRIVATE-QUESTION-TEXT' ) && false === strpos( $blob, SECRET ) );
for ( $i = 0; $i < 60; $i++ ) { $C::request( 'faq.answer' ); }
check( 'event log is bounded to EVENT_LIMIT', TZFD_AI_Connector::EVENT_LIMIT === count( $C::events() ) );
check( 'clear_events empties the log', true === $C::clear_events() && array() === $C::events() );

// 21. Retention: only integers 1..365 accepted; events older than the window are pruned on write.
check( 'retention default is 30 days', 30 === $C::retention_days() );
check( 'retention 0 is refused', false === $C::set_retention_days( 0 ) );
check( 'retention 366 is refused', false === $C::set_retention_days( 366 ) );
check( 'retention string "7" is refused (must be an integer)', false === $C::set_retention_days( '7' ) );
check( 'retention 7 is accepted', true === $C::set_retention_days( 7 ) && 7 === $C::retention_days() );
$GLOBALS['tz_opts'][ TZFD_AI_Connector::EVENT_OPTION ] = array(
    array( 'at' => time() - 8 * 86400, 'feature' => 'faq.answer', 'status' => 'ok', 'latency_ms' => 1 ),
    array( 'at' => time() - 86400, 'feature' => 'faq.answer', 'status' => 'ok', 'latency_ms' => 1 ),
);
$C::request( 'faq.answer' );
$ages = array_map( function ( $e ) { return time() - $e['at']; }, $C::events() );
check( 'event older than retention is pruned on the next write', 2 === count( $C::events() ) && max( $ages ) < 7 * 86400 + 5 );

// 22. Budget race (single-process interleaving): ten requests made inside an outer provider call never push
// model_fallback past its limit of 3. Each interleaved request reserves before it calls the provider.
reset_state();
connect_all();
with_limits( array( 'model_fallback' => 3 ) );
$interleaved = array();
$depth       = 0;
$GLOBALS['tz_reply'] = function ( $req ) use ( $C, &$interleaved, &$depth ) {
    if ( 0 === $depth ) {
        $depth++;
        for ( $i = 0; $i < 10; $i++ ) { $interleaved[] = $C::request( 'faq.answer' )['status']; }
        $depth--;
    }
    return fake_reply( $req );
};
$outer  = $C::request( 'faq.answer' );
$counts = array_count_values( $interleaved );
check( 'interleaved requests stop at the model_fallback limit of 3', 3 === calls() && 'ok' === $outer['status'] && 2 === ( $counts['ok'] ?? 0 ) && 8 === ( $counts['allowance_exhausted'] ?? 0 ) );
check( 'race leaves exactly 3 units recorded', 3 === ( $C::usage()['budget']['model_fallback'] ?? -1 ) );

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
