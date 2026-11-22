<?php
/**
 * Standalone adversarial tests for the inbound webhook verifiers (issue #8).
 *
 * Covers the pure verification functions only: telephony call-events validation, the signed
 * missed-call request helper, the Twilio status signature and payload checks, and the shared REST
 * request guard. No WordPress install is needed; minimal stubs stand in for WP functions.
 *
 * Run: php -d display_errors=1 tests/security/webhook-adversarial-tests.php
 * Not shipped in the plugin package.
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/tmp/tzfd-test-wp/' ); }

$tzfd_plugin = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/';

// ---- minimal WordPress stubs -------------------------------------------------
function __( $text ) { return $text; }
function apply_filters( $hook, $value ) { return $GLOBALS['tz_filters'][ $hook ] ?? $value; }
function add_action( ...$args ) { return true; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
$GLOBALS['tz_transients'] = array();
function get_transient( $k ) { return $GLOBALS['tz_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['tz_transients'][ $k ] = $v; return true; }
$GLOBALS['tz_options'] = array();
function add_option( $k, $v, $deprecated = '', $autoload = 'yes' ) { if ( isset( $GLOBALS['tz_options'][ $k ] ) ) { return false; } $GLOBALS['tz_options'][ $k ] = (string) $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['tz_options'][ $k ] ?? $d; }
function wp_cache_delete( $k, $g = '' ) { return true; }
// Stand-in for $wpdb: the INSERT IGNORE claim, the expired-claim UPDATE and the purge DELETE used by claim_signature().
class TZ_Fake_WPDB {
    public $options = 'wp_options';
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
    public function query( $prepared ) {
        list( $sql, $a ) = $prepared;
        if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) { // args: name, now
            if ( isset( $GLOBALS['tz_options'][ $a[0] ] ) ) return 0;
            $GLOBALS['tz_options'][ $a[0] ] = (string) $a[1];
            return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE' ) ) { // args: new value, name, cutoff; only numeric records are taken over
            $cur = $GLOBALS['tz_options'][ $a[1] ] ?? null;
            if ( null === $cur || ! ctype_digit( (string) $cur ) || (int) $cur > (int) $a[2] ) return 0;
            $GLOBALS['tz_options'][ $a[1] ] = (string) $a[0];
            return 1;
        }
        return 0; // DELETE (purge) finds nothing to remove in this stand-in
    }
}
$GLOBALS['wpdb'] = new TZ_Fake_WPDB();
function rest_url( $p ) { return 'https://example.test/wp-json/' . $p; }
class WP_Error { public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
class WP_REST_Response { public $data; public function __construct( $d = null ) { $this->data = $d; } }
class TZFD_Call_Lifecycle { const EVENTS = array( 'received', 'answered', 'missed', 'abandoned', 'completed', 'failed' ); }

require_once $tzfd_plugin . 'includes/class-tzfd-signed-request.php';
require_once $tzfd_plugin . 'includes/class-tzfd-rest-request-guard.php';
require_once $tzfd_plugin . 'includes/class-tzfd-telephony-adapter.php';
if ( ! defined( 'TZFD_TWILIO_AUTH_TOKEN' ) ) { define( 'TZFD_TWILIO_AUTH_TOKEN', str_repeat( 't', 32 ) ); }
require_once $tzfd_plugin . 'includes/class-tzfd-twilio-status.php';

// ---- tiny request double for TZFD_REST_Request_Guard ------------------------
class TZ_Fake_Request {
    public $method; public $body; public $headers; public $params;
    public function __construct( $method, $body, $headers = array(), $params = array() ) {
        $this->method = $method; $this->body = $body; $this->headers = $headers; $this->params = $params;
    }
    public function get_method() { return $this->method; }
    public function get_body() { return $this->body; }
    public function get_header( $name ) { $n = strtolower( $name ); return $this->headers[ $n ] ?? null; }
    public function get_params() { return $this->params; }
}

// ---- harness ------------------------------------------------------------------
$GLOBALS['tz_pass'] = 0; $GLOBALS['tz_fail'] = 0;
function tz_check( $name, $ok ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}

$now    = 1_900_000_000;
$secret = str_repeat( 'a', 64 );
$tel_creds = array( 'enabled' => true, 'company_id' => 7, 'provider' => 'acme-voice', 'secret' => str_repeat( 'k', 40 ) );

// ---- 1. Telephony call-events: authentication and body trust ----------------
$body = json_encode( array( 'call_id' => 'c1', 'event_id' => 'e1', 'event' => 'missed', 'company_id' => 999, 'provider' => 'evil' ) );
$ts   = (string) $now;
$sig  = hash_hmac( 'sha256', $ts . '.' . $body, $tel_creds['secret'] );

$ev = TZFD_Telephony_Adapter::validate( $body, 'key1', $ts, $sig, $tel_creds, $now );
tz_check( 'telephony: valid signed event is accepted', is_array( $ev ) );
tz_check( 'telephony: company comes from credentials, not the body', is_array( $ev ) && 7 === $ev['company_id'] );
tz_check( 'telephony: provider comes from credentials, not the body', is_array( $ev ) && 'acme-voice' === $ev['provider'] );
tz_check( 'telephony: forged signature (wrong secret) refused', null === TZFD_Telephony_Adapter::validate( $body, 'key1', $ts, hash_hmac( 'sha256', $ts . '.' . $body, str_repeat( 'x', 40 ) ), $tel_creds, $now ) );
tz_check( 'telephony: body tampered after signing refused', null === TZFD_Telephony_Adapter::validate( str_replace( 'missed', 'answered', $body ), 'key1', $ts, $sig, $tel_creds, $now ) );
tz_check( 'telephony: stale timestamp (400s old) refused', null === TZFD_Telephony_Adapter::validate( $body, 'key1', (string) ( $now - 400 ), hash_hmac( 'sha256', ( $now - 400 ) . '.' . $body, $tel_creds['secret'] ), $tel_creds, $now ) );
tz_check( 'telephony: future timestamp (400s ahead) refused', null === TZFD_Telephony_Adapter::validate( $body, 'key1', (string) ( $now + 400 ), hash_hmac( 'sha256', ( $now + 400 ) . '.' . $body, $tel_creds['secret'] ), $tel_creds, $now ) );
$off = $tel_creds; $off['enabled'] = false;
tz_check( 'telephony: disabled credentials fail closed', null === TZFD_Telephony_Adapter::validate( $body, 'key1', $ts, $sig, $off, $now ) );
tz_check( 'telephony: no credentials resolver (null) fails closed', null === TZFD_Telephony_Adapter::validate( $body, 'key1', $ts, $sig, null, $now ) );
$short = $tel_creds; $short['secret'] = str_repeat( 'k', 31 );
tz_check( 'telephony: secret shorter than 32 bytes refused', null === TZFD_Telephony_Adapter::validate( $body, 'key1', $ts, hash_hmac( 'sha256', $ts . '.' . $body, $short['secret'] ), $short, $now ) );
tz_check( 'telephony: malformed key id refused', null === TZFD_Telephony_Adapter::validate( $body, "key1\n", $ts, $sig, $tel_creds, $now ) );
tz_check( 'telephony: non-hex signature refused', null === TZFD_Telephony_Adapter::validate( $body, 'key1', $ts, str_repeat( 'G', 64 ), $tel_creds, $now ) );

// ---- 2. Telephony call-events: malformed events -----------------------------
$cases = array(
    'malformed JSON'          => '{"call_id":"c1",',
    'unknown event type'      => json_encode( array( 'call_id' => 'c1', 'event_id' => 'e1', 'event' => 'DROP TABLE' ) ),
    'missing event_id'        => json_encode( array( 'call_id' => 'c1', 'event' => 'missed' ) ),
    'call_id as array'        => json_encode( array( 'call_id' => array( 'x' ), 'event_id' => 'e1', 'event' => 'missed' ) ),
    'empty call_id'           => json_encode( array( 'call_id' => '', 'event_id' => 'e1', 'event' => 'missed' ) ),
    'over-long event_id'      => json_encode( array( 'call_id' => 'c1', 'event_id' => str_repeat( 'e', 256 ), 'event' => 'missed' ) ),
    'JSON scalar, not object' => '"missed"',
);
foreach ( $cases as $label => $raw ) {
    $s = hash_hmac( 'sha256', $ts . '.' . $raw, $tel_creds['secret'] );
    tz_check( 'telephony malformed event refused: ' . $label, null === TZFD_Telephony_Adapter::validate( $raw, 'key1', $ts, $s, $tel_creds, $now ) );
}
$huge = str_repeat( ' ', 16385 );
tz_check( 'telephony: body over 16384 bytes refused', null === TZFD_Telephony_Adapter::validate( $huge, 'key1', $ts, hash_hmac( 'sha256', $ts . '.' . $huge, $tel_creds['secret'] ), $tel_creds, $now ) );

// ---- 3. Signed request (missed-call) ----------------------------------------
$mc_body = 'event=missed_call&from=+61400000000';
$mc_ts   = (string) $now;
$mc_sig  = 'sha256=' . hash_hmac( 'sha256', $mc_ts . '.' . $mc_body, $secret );
tz_check( 'signed request: valid signature accepted', TZFD_Signed_Request::signature_valid( $secret, $mc_ts, $mc_sig, $mc_body, $now ) );
tz_check( 'signed request: wrong secret refused', ! TZFD_Signed_Request::signature_valid( str_repeat( 'b', 64 ), $mc_ts, $mc_sig, $mc_body, $now ) );
tz_check( 'signed request: short configured secret (<64) refused', ! TZFD_Signed_Request::signature_valid( str_repeat( 'a', 63 ), $mc_ts, $mc_sig, $mc_body, $now ) );
tz_check( 'signed request: empty secret (unconfigured) refused', ! TZFD_Signed_Request::signature_valid( '', $mc_ts, $mc_sig, $mc_body, $now ) );
tz_check( 'signed request: tampered body refused', ! TZFD_Signed_Request::signature_valid( $secret, $mc_ts, $mc_sig, $mc_body . '&status=busy', $now ) );
tz_check( 'signed request: timestamp swapped (signature reused at new time) refused', ! TZFD_Signed_Request::signature_valid( $secret, (string) ( $now + 1 ), $mc_sig, $mc_body, $now + 1 ) );
tz_check( 'signed request: signature without sha256= prefix refused', ! TZFD_Signed_Request::signature_valid( $secret, $mc_ts, hash_hmac( 'sha256', $mc_ts . '.' . $mc_body, $secret ), $mc_body, $now ) );
tz_check( 'signed request: non-numeric timestamp refused', ! TZFD_Signed_Request::signature_valid( $secret, 'abc', $mc_sig, $mc_body, $now ) );
tz_check( 'signed request: stale timestamp (301s) refused', ! TZFD_Signed_Request::signature_valid( $secret, $mc_ts, $mc_sig, $mc_body, $now + 301 ) );
tz_check( 'signed request: missing headers (null) refused', ! TZFD_Signed_Request::signature_valid( $secret, null, null, $mc_body, $now ) );
tz_check( 'signed request: replay of identical signature refused', TZFD_Signed_Request::claim_signature( $mc_sig ) && ! TZFD_Signed_Request::claim_signature( $mc_sig ) );
$exp_sig = 'sha256=' . str_repeat( '1', 64 );
$exp_opt = TZFD_Signed_Request::claim_key( $exp_sig );
$GLOBALS['tz_options'][ $exp_opt ] = (string) ( time() - 700 ); // record older than REPLAY_TTL
tz_check( 'signed request: expired replay record can be reclaimed once', TZFD_Signed_Request::claim_signature( $exp_sig ) );
tz_check( 'signed request: reclaimed record blocks an immediate second claim', ! TZFD_Signed_Request::claim_signature( $exp_sig ) );
$GLOBALS['tz_options'][ $exp_opt ] = 'corrupt'; // non-numeric record: must not be reclaimed
tz_check( 'signed request: corrupt replay record fails closed', ! TZFD_Signed_Request::claim_signature( $exp_sig ) );
tz_check( 'signed request: different signature is not blocked by a replay record', TZFD_Signed_Request::claim_signature( 'sha256=' . str_repeat( '0', 64 ) ) );

// ---- 4. Twilio status callback ----------------------------------------------
$tw_url    = 'https://example.test/wp-json/tzfd/v1/twilio-status';
$tw_params = array( 'MessageSid' => 'SM' . str_repeat( 'a', 32 ), 'MessageStatus' => 'delivered' );
$tw_sig    = self_twilio_sign( $tw_url, $tw_params );
function self_twilio_sign( $url, $params ) {
    ksort( $params, SORT_STRING ); $input = $url;
    foreach ( $params as $k => $v ) { $input .= $k . $v; }
    return base64_encode( hash_hmac( 'sha1', $input, TZFD_TWILIO_AUTH_TOKEN, true ) );
}
tz_check( 'twilio: valid signature verifies', TZFD_Twilio_Status::verified_signature( $tw_url, $tw_params, $tw_sig ) );
tz_check( 'twilio: forged status (changed MessageStatus) refused', ! TZFD_Twilio_Status::verified_signature( $tw_url, array_merge( $tw_params, array( 'MessageStatus' => 'failed' ) ), $tw_sig ) );
tz_check( 'twilio: signature bound to URL (other host) refused', ! TZFD_Twilio_Status::verified_signature( 'https://evil.test/wp-json/tzfd/v1/twilio-status', $tw_params, $tw_sig ) );
tz_check( 'twilio: parameter key with illegal characters refused', ! TZFD_Twilio_Status::verified_signature( $tw_url, array_merge( $tw_params, array( 'Bad-Key' => 'x' ) ), $tw_sig ) );
tz_check( 'twilio: non-base64 signature refused', ! TZFD_Twilio_Status::verified_signature( $tw_url, $tw_params, '!!!' ) );
tz_check( 'twilio: payload with MessageStatus=queued refused as terminal', ! TZFD_Twilio_Status::valid_payload( array( 'MessageSid' => 'SM' . str_repeat( 'a', 32 ), 'MessageStatus' => 'queued' ) ) );
tz_check( 'twilio: malformed MessageSid refused', ! TZFD_Twilio_Status::valid_payload( array( 'MessageSid' => 'SM1', 'MessageStatus' => 'delivered' ) ) );
tz_check( 'twilio: array-valued MessageSid refused', ! TZFD_Twilio_Status::valid_payload( array( 'MessageSid' => array( 'x' ), 'MessageStatus' => 'delivered' ) ) );
tz_check( 'twilio: more than 30 fields refused', ! TZFD_Twilio_Status::valid_payload( array_merge( $tw_params, array_combine( array_map( fn( $i ) => 'F' . $i, range( 1, 29 ) ), array_fill( 0, 29, 'x' ) ) ) ) );

// ---- 5. Shared REST request guard -------------------------------------------
$ok_req = new TZ_Fake_Request( 'POST', 'a=1', array( 'content-length' => '3', 'content-type' => 'application/x-www-form-urlencoded' ), array( 'a' => '1' ) );
tz_check( 'guard: well-formed POST accepted', TZFD_REST_Request_Guard::check( $ok_req, 'POST', 4096, 30, 'application/x-www-form-urlencoded' ) );
tz_check( 'guard: wrong method refused', ! TZFD_REST_Request_Guard::check( $ok_req, 'GET', 4096, 30 ) );
tz_check( 'guard: GET carrying a body refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'GET', 'x=1', array(), array() ), 'GET', 1024, 12 ) );
tz_check( 'guard: content-length disagrees with body refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a=1', array( 'content-length' => '99' ), array() ), 'POST', 4096, 30 ) );
tz_check( 'guard: content-length not numeric refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a=1', array( 'content-length' => '1e3' ), array() ), 'POST', 4096, 30 ) );
tz_check( 'guard: wrong content-type refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a=1', array( 'content-type' => 'text/plain' ), array() ), 'POST', 4096, 30, 'application/x-www-form-urlencoded' ) );
tz_check( 'guard: body over limit refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', str_repeat( 'a', 4097 ), array(), array() ), 'POST', 4096, 30 ) );
tz_check( 'guard: too many top-level params refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a', array(), array_fill_keys( array_map( fn( $i ) => 'p' . $i, range( 1, 31 ) ), 'x' ) ), 'POST', 4096, 30 ) );
$deep = 'v'; for ( $i = 0; $i < 14; $i++ ) { $deep = array( 'n' => $deep ); }
tz_check( 'guard: nesting deeper than 12 refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a', array(), array( 'x' => $deep ) ), 'POST', 4096, 30 ) );
tz_check( 'guard: object in parsed params refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a', array(), array( 'x' => new stdClass() ) ), 'POST', 4096, 30 ) );
tz_check( 'guard: INF float refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a', array(), array( 'x' => INF ) ), 'POST', 4096, 30 ) );
tz_check( 'guard: tiny body with oversized parsed query refused', ! TZFD_REST_Request_Guard::check( new TZ_Fake_Request( 'POST', 'a', array(), array( 'x' => str_repeat( 'q', 5000 ) ) ), 'POST', 4096, 30 ) );
tz_check( 'guard: invalid max_bytes argument refused (fail closed)', ! TZFD_REST_Request_Guard::check( $ok_req, 'POST', 0, 30 ) );

echo "\n" . $GLOBALS['tz_pass'] . ' passed, ' . $GLOBALS['tz_fail'] . " failed\n";
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
