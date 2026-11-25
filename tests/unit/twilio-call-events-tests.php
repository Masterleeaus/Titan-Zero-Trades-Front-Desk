<?php
/**
 * Offline tests for the signed Twilio call-status route that creates missed-call callback tasks (issue #357).
 *
 * Covers: URL and payload checks, the event mapping (no-answer and busy are missed, everything else is ignored),
 * signature forgery and wrong-URL refusals, replay, wrong tenant, failed writes that release the claim so the
 * retry is processed, one task per call, and the rule that this route never sends an SMS or calls a provider API.
 * WordPress functions are stubbed. The call lifecycle and task store are stubbed; the replay claim uses the real
 * TZFD_Signed_Request against an in-memory wp_options model.
 *
 * Run: php -d error_reporting=-1 tests/unit/twilio-call-events-tests.php   (from the repository root)
 */
define( 'ABSPATH', __DIR__ );

$tzfd_plugin = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/';

// ---- minimal WordPress stubs -------------------------------------------------
function __( $text ) { return $text; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function add_action( ...$args ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function rest_url( $p ) { return 'https://example.test/wp-json/' . $p; }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_data() { return $this->data; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_REST_Response { public $data; public function __construct( $d = null ) { $this->data = $d; } }

// In-memory wp_options model for TZFD_Signed_Request (INSERT IGNORE, conditional UPDATE, DELETE by name or by prefix).
$GLOBALS['tz_options'] = array();
class TZ_Test_Wpdb {
    public $options = 'wp_options';
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
    public function query( $prepared ) {
        list( $sql, $a ) = $prepared;
        if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
            if ( isset( $GLOBALS['tz_options'][ $a[0] ] ) ) { return 0; }
            $GLOBALS['tz_options'][ $a[0] ] = (string) $a[1];
            return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE' ) ) {
            $cur = $GLOBALS['tz_options'][ $a[1] ] ?? null;
            if ( null === $cur || ! ctype_digit( (string) $cur ) || (int) $cur > (int) $a[2] ) { return 0; }
            $GLOBALS['tz_options'][ $a[1] ] = (string) $a[0];
            return 1;
        }
        if ( 0 === strpos( $sql, 'DELETE FROM' ) && 1 === count( $a ) ) { // release: exact name
            $existed = isset( $GLOBALS['tz_options'][ $a[0] ] );
            unset( $GLOBALS['tz_options'][ $a[0] ] );
            return $existed ? 1 : 0;
        }
        return 0; // purge of expired claims: nothing to do in these checks
    }
}
$GLOBALS['wpdb'] = new TZ_Test_Wpdb();

// Scoped store and call/task stores. The lifecycle and task stubs record what the route asked them to write.
$GLOBALS['tz_company'] = 7;
class TZFD_Scoped_Store { public static function company_id() { return (int) $GLOBALS['tz_company']; } }
$GLOBALS['tz_lc_calls'] = array();
$GLOBALS['tz_lc_fail']  = false;
class TZFD_Call_Lifecycle {
    public static function record_event( $company_id, $provider, $call_id, $event_id, $event, $authenticated = false ) {
        if ( true !== $authenticated ) { return new WP_Error( 'tzfd_call_unauthorised', 'auth' ); }
        if ( $GLOBALS['tz_lc_fail'] ) { return new WP_Error( 'tzfd_call_unavailable', 'lifecycle unavailable', array( 'status' => 503 ) ); }
        $GLOBALS['tz_lc_calls'][] = array( $company_id, $provider, $call_id, $event_id, $event );
        return array( 'ok' => true, 'event' => $event );
    }
}
$GLOBALS['tz_tasks'] = array();
$GLOBALS['tz_task_fail'] = false;
class TZFD_Missed_Call_Tasks {
    public static function record( $company_id, $provider, $call_id, $from ) {
        if ( $GLOBALS['tz_task_fail'] ) { return new WP_Error( 'tzfd_missed_task_unavailable', 'task unavailable', array( 'status' => 503 ) ); }
        $key = $company_id . '|' . $provider . '|' . $call_id;
        if ( isset( $GLOBALS['tz_tasks'][ $key ] ) ) { return array( 'ok' => true, 'task' => 'duplicate' ); }
        $GLOBALS['tz_tasks'][ $key ] = $from;
        return array( 'ok' => true, 'task' => 'created' );
    }
}

require_once $tzfd_plugin . 'includes/class-tzfd-signed-request.php';
require_once $tzfd_plugin . 'includes/class-tzfd-rest-request-guard.php';
if ( ! defined( 'TZFD_TWILIO_SMS_ENABLED' ) ) { define( 'TZFD_TWILIO_SMS_ENABLED', true ); }
if ( ! defined( 'TZFD_TWILIO_ACCOUNT_SID' ) ) { define( 'TZFD_TWILIO_ACCOUNT_SID', 'AC' . str_repeat( 'a', 32 ) ); }
if ( ! defined( 'TZFD_TWILIO_AUTH_TOKEN' ) ) { define( 'TZFD_TWILIO_AUTH_TOKEN', str_repeat( 't', 32 ) ); }
if ( ! defined( 'TZFD_TWILIO_FROM' ) ) { define( 'TZFD_TWILIO_FROM', '+61400000000' ); }
if ( ! defined( 'TZFD_TWILIO_CALL_STATUS_CALLBACK_URL' ) ) { define( 'TZFD_TWILIO_CALL_STATUS_CALLBACK_URL', 'https://example.test/wp-json/tzfd/v1/twilio-call-status' ); }
require_once $tzfd_plugin . 'includes/class-tzfd-twilio-sms.php';
require_once $tzfd_plugin . 'includes/class-tzfd-twilio-status.php';
require_once $tzfd_plugin . 'includes/class-tzfd-twilio-call-events.php';

// ---- request double -----------------------------------------------------------
class TZ_Test_Request {
    public $method; public $body; public $headers; public $params;
    public function __construct( $body, $headers, $method = 'POST' ) {
        $this->method = $method; $this->body = $body; $this->headers = $headers;
        $this->params = array(); parse_str( $body, $this->params );
    }
    public function get_method() { return $this->method; }
    public function get_body() { return $this->body; }
    public function get_header( $name ) { $n = strtolower( $name ); return $this->headers[ $n ] ?? null; }
    public function get_params() { return $this->params; }
    public function get_body_params() { return $this->params; }
}

function tz_sign( $url, $params, $token = TZFD_TWILIO_AUTH_TOKEN ) {
    ksort( $params, SORT_STRING );
    $input = $url;
    foreach ( $params as $k => $v ) { $input .= $k . $v; }
    return base64_encode( hash_hmac( 'sha1', $input, $token, true ) );
}

const TZ_URL = 'https://example.test/wp-json/tzfd/v1/twilio-call-status';

// Builds a signed request. $sign_params is what the signature covers; $send_params is what the body carries.
function tz_request( $send_params, $sign_params = null, $overrides = array() ) {
    $sign_params = null === $sign_params ? $send_params : $sign_params;
    $body = http_build_query( $send_params, '', '&', PHP_QUERY_RFC3986 );
    $headers = array_merge( array(
        'content-type'      => 'application/x-www-form-urlencoded',
        'x-twilio-signature' => tz_sign( TZ_URL, $sign_params ),
    ), $overrides );
    return new TZ_Test_Request( $body, $headers );
}

function tz_missed( $status = 'no-answer', $extra = array() ) {
    return array_merge( array(
        'AccountSid' => TZFD_TWILIO_ACCOUNT_SID,
        'CallSid'    => 'CA' . str_repeat( 'b', 32 ),
        'CallStatus' => $status,
        'From'       => '+61400111222',
        'To'         => '+61400000000',
        'Direction'  => 'inbound',
    ), $extra );
}

// ---- harness ------------------------------------------------------------------
$GLOBALS['tz_pass'] = 0; $GLOBALS['tz_fail'] = 0;
function tz_check( $name, $ok ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}
function tz_reset() {
    $GLOBALS['tz_options'] = array(); $GLOBALS['tz_lc_calls'] = array(); $GLOBALS['tz_tasks'] = array();
    $GLOBALS['tz_lc_fail'] = false; $GLOBALS['tz_task_fail'] = false; $GLOBALS['tz_company'] = 7;
}

// ---- 1. URL configuration -------------------------------------------------------
tz_check( 'configured URL is the site route when Twilio is configured', TZFD_Twilio_Call_Events::configured_url() === TZ_URL );
tz_check( 'valid_callback_url accepts the exact https route', TZFD_Twilio_Call_Events::valid_callback_url( TZ_URL ) === TZ_URL );
tz_check( 'valid_callback_url refuses plain http', false === TZFD_Twilio_Call_Events::valid_callback_url( 'http://example.test/wp-json/tzfd/v1/twilio-call-status' ) );
tz_check( 'valid_callback_url refuses another host', false === TZFD_Twilio_Call_Events::valid_callback_url( 'https://evil.test/wp-json/tzfd/v1/twilio-call-status' ) );
tz_check( 'valid_callback_url refuses another route', false === TZFD_Twilio_Call_Events::valid_callback_url( 'https://example.test/wp-json/tzfd/v1/twilio-status' ) );
tz_check( 'valid_callback_url refuses a trailing slash', false === TZFD_Twilio_Call_Events::valid_callback_url( TZ_URL . '/' ) );
tz_check( 'valid_callback_url refuses a query string', false === TZFD_Twilio_Call_Events::valid_callback_url( TZ_URL . '?x=1' ) );
tz_check( 'valid_callback_url refuses a non-string', false === TZFD_Twilio_Call_Events::valid_callback_url( array( TZ_URL ) ) );

// ---- 2. Payload and event mapping ---------------------------------------------
$e = TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer' ) );
tz_check( 'no-answer maps to a missed event', is_array( $e ) && 'missed' === $e['event'] );
tz_check( 'event carries the call id and the caller number', is_array( $e ) && 'CA' . str_repeat( 'b', 32 ) === $e['call_id'] && '+61400111222' === $e['from'] );
tz_check( 'event id joins the call sid and the status', is_array( $e ) && $e['event_id'] === 'CA' . str_repeat( 'b', 32 ) . ':no-answer' );
tz_check( 'busy maps to a missed event', 'missed' === ( TZFD_Twilio_Call_Events::event_for( tz_missed( 'busy' ) )['event'] ?? '' ) );
tz_check( 'completed is accepted but ignored', 'ignored' === ( TZFD_Twilio_Call_Events::event_for( tz_missed( 'completed' ) )['event'] ?? '' ) );
tz_check( 'ringing is accepted but ignored', 'ignored' === ( TZFD_Twilio_Call_Events::event_for( tz_missed( 'ringing' ) )['event'] ?? '' ) );
tz_check( 'unknown call status is refused', null === TZFD_Twilio_Call_Events::event_for( tz_missed( 'weird' ) ) );
tz_check( 'a call from another Twilio account is refused', null === TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer', array( 'AccountSid' => 'AC' . str_repeat( 'c', 32 ) ) ) ) );
tz_check( 'a missing AccountSid is refused', null === TZFD_Twilio_Call_Events::event_for( array_diff_key( tz_missed(), array( 'AccountSid' => 1 ) ) ) );
tz_check( 'a short CallSid is refused', null === TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer', array( 'CallSid' => 'CA1' ) ) ) );
tz_check( 'an upper-case CallSid is refused', null === TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer', array( 'CallSid' => 'CA' . str_repeat( 'B', 32 ) ) ) ) );
tz_check( 'an array-valued CallSid is refused', null === TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer', array( 'CallSid' => array( 'CA' . str_repeat( 'b', 32 ) ) ) ) ) );
tz_check( 'a payload over 40 fields is refused', null === TZFD_Twilio_Call_Events::event_for( tz_missed() + array_combine( array_map( fn( $i ) => 'F' . $i, range( 1, 40 ) ), array_fill( 0, 40, 'x' ) ) ) );
tz_check( 'an over-long From is dropped but the missed call still counts', 'missed' === ( TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer', array( 'From' => str_repeat( '1', 41 ) ) ) )['event'] ?? '' ) && '' === TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer', array( 'From' => str_repeat( '1', 41 ) ) ) )['from'] );
tz_check( 'a non-string From is dropped', '' === ( TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer', array( 'From' => array( 'x' ) ) ) )['from'] ?? null ) );
$same1 = TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer' ) );
$same2 = TZFD_Twilio_Call_Events::event_for( tz_missed( 'no-answer' ) );
$later = TZFD_Twilio_Call_Events::event_for( tz_missed( 'completed' ) );
tz_check( 'an identical redelivery maps to the same event id', $same1['event_id'] === $same2['event_id'] );
tz_check( 'a later status for the same call gets a different event id', $later['event_id'] !== $same1['event_id'] );

// ---- 3. Authorisation (signature, shape, method) ---------------------------------
tz_reset();
tz_check( 'a correctly signed missed call passes authorisation', true === TZFD_Twilio_Call_Events::authorise( tz_request( tz_missed() ) ) );
tz_check( 'a forged status (body changed, signature from no-answer) is refused', is_wp_error( TZFD_Twilio_Call_Events::authorise( tz_request( tz_missed( 'busy' ), tz_missed( 'no-answer' ) ) ) ) );
tz_check( 'a signature for another URL is refused', is_wp_error( TZFD_Twilio_Call_Events::authorise( new TZ_Test_Request( http_build_query( tz_missed() ), array( 'content-type' => 'application/x-www-form-urlencoded', 'x-twilio-signature' => tz_sign( 'https://evil.test/wp-json/tzfd/v1/twilio-call-status', tz_missed() ) ) ) ) ) );
tz_check( 'a missing signature header is refused', is_wp_error( TZFD_Twilio_Call_Events::authorise( new TZ_Test_Request( http_build_query( tz_missed() ), array( 'content-type' => 'application/x-www-form-urlencoded' ) ) ) ) );
tz_check( 'a malformed signature is refused', is_wp_error( TZFD_Twilio_Call_Events::authorise( tz_request( tz_missed(), null, array( 'x-twilio-signature' => '!!!' ) ) ) ) );
tz_check( 'a JSON content type is refused', is_wp_error( TZFD_Twilio_Call_Events::authorise( tz_request( tz_missed(), null, array( 'content-type' => 'application/json' ) ) ) ) );
tz_check( 'a GET request is refused', is_wp_error( TZFD_Twilio_Call_Events::authorise( new TZ_Test_Request( http_build_query( tz_missed() ), array( 'content-type' => 'application/x-www-form-urlencoded', 'x-twilio-signature' => tz_sign( TZ_URL, tz_missed() ) ), 'GET' ) ) ) );
tz_check( 'an oversize body is refused', is_wp_error( TZFD_Twilio_Call_Events::authorise( tz_request( array_merge( tz_missed(), array( 'Pad' => str_repeat( 'x', 4200 ) ) ) ) ) ) );

// ---- 4. Receive: task creation, replay, tenant, failed writes -----------------------
tz_reset();
$r1 = TZFD_Twilio_Call_Events::receive( tz_request( tz_missed() ) );
tz_check( 'a signed missed call is recorded', is_array( $r1 ) && 'recorded' === $r1['state'] );
tz_check( 'the missed call is written for the active business and provider', 1 === count( $GLOBALS['tz_lc_calls'] ) && array( 7, 'twilio', 'CA' . str_repeat( 'b', 32 ), 'CA' . str_repeat( 'b', 32 ) . ':no-answer', 'missed' ) === array_slice( $GLOBALS['tz_lc_calls'][0], 0, 5 ) );
tz_check( 'one callback task is created with the caller number', 1 === count( $GLOBALS['tz_tasks'] ) && '+61400111222' === reset( $GLOBALS['tz_tasks'] ) );
tz_check( 'the created task is reported as created', 'created' === ( $r1['task'] ?? '' ) );
$r2 = TZFD_Twilio_Call_Events::receive( tz_request( tz_missed() ) );
tz_check( 'an identical redelivery is answered as a replay', is_array( $r2 ) && 'replayed' === $r2['state'] );
tz_check( 'a replay writes nothing new', 1 === count( $GLOBALS['tz_lc_calls'] ) && 1 === count( $GLOBALS['tz_tasks'] ) );
$r3 = TZFD_Twilio_Call_Events::receive( tz_request( tz_missed( 'busy' ) ) );
tz_check( 'a later busy callback for the same call writes the event but not a second task', is_array( $r3 ) && 'recorded' === $r3['state'] && 'duplicate' === $r3['task'] && 2 === count( $GLOBALS['tz_lc_calls'] ) && 1 === count( $GLOBALS['tz_tasks'] ) );
$r4 = TZFD_Twilio_Call_Events::receive( tz_request( tz_missed( 'completed', array( 'CallSid' => 'CA' . str_repeat( 'c', 32 ) ) ) ) );
tz_check( 'a completed call is acknowledged and ignored', is_array( $r4 ) && 'ignored' === $r4['state'] && 2 === count( $GLOBALS['tz_lc_calls'] ) && 1 === count( $GLOBALS['tz_tasks'] ) );

tz_reset();
$GLOBALS['tz_company'] = 0;
$r5 = TZFD_Twilio_Call_Events::receive( tz_request( tz_missed( 'no-answer', array( 'CallSid' => 'CA' . str_repeat( 'd', 32 ) ) ) ) );
tz_check( 'no active business refuses the write', is_wp_error( $r5 ) && 403 === ( $r5->data['status'] ?? 0 ) );
tz_check( 'a refused tenant leaves no call row and no task', 0 === count( $GLOBALS['tz_lc_calls'] ) && 0 === count( $GLOBALS['tz_tasks'] ) );

tz_reset();
$GLOBALS['tz_lc_fail'] = true;
$req = tz_request( tz_missed( 'no-answer', array( 'CallSid' => 'CA' . str_repeat( 'e', 32 ) ) ) );
$r6 = TZFD_Twilio_Call_Events::receive( $req );
tz_check( 'a failed call write returns an error so the request is retried', is_wp_error( $r6 ) );
$GLOBALS['tz_lc_fail'] = false;
$r7 = TZFD_Twilio_Call_Events::receive( $req );
tz_check( 'the retry after a failed call write is processed, not treated as a replay', is_array( $r7 ) && 'recorded' === $r7['state'] && 1 === count( $GLOBALS['tz_lc_calls'] ) );

tz_reset();
$GLOBALS['tz_task_fail'] = true;
$req = tz_request( tz_missed( 'no-answer', array( 'CallSid' => 'CA' . str_repeat( 'f', 32 ) ) ) );
$r8 = TZFD_Twilio_Call_Events::receive( $req );
tz_check( 'a failed task write returns 503 so the request is retried', is_wp_error( $r8 ) && 503 === ( $r8->data['status'] ?? 0 ) );
$GLOBALS['tz_task_fail'] = false;
$r9 = TZFD_Twilio_Call_Events::receive( $req );
tz_check( 'the retry after a failed task write creates the task', is_array( $r9 ) && 'recorded' === $r9['state'] && 1 === count( $GLOBALS['tz_tasks'] ) );

// ---- 5. Regression guard: the route sends nothing outbound -----------------------
$src = file_get_contents( $tzfd_plugin . 'includes/class-tzfd-twilio-call-events.php' );
tz_check( 'the call-status route makes no outbound HTTP call (no SMS, no provider API)', false === strpos( $src, 'wp_remote_' ) && false === strpos( $src, 'wp_safe_remote_' ) && false === strpos( $src, 'api.twilio.com' ) );

echo "\n" . $GLOBALS['tz_pass'] . ' passed, ' . $GLOBALS['tz_fail'] . " failed\n";
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
