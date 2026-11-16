<?php
/**
 * Unit tests for signed provider events (issues #30 and #31): forged messages, tenant binding, stale and replayed
 * signatures, and the missed-call callback task contract. WordPress functions are stubbed. No database is used.
 * Run: php tests/unit/signed-event-tests.php   (from the repository root)
 */
define( 'ABSPATH', __DIR__ );

$GLOBALS['tz_filters'] = array();
$GLOBALS['tz_transients'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value, ...$args ) {
        return isset( $GLOBALS['tz_filters'][ $hook ] ) ? call_user_func( $GLOBALS['tz_filters'][ $hook ], $value, ...$args ) : $value;
    }
}
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb, $p = 10, $n = 1 ) {} }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['tz_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tz_transients'][ $k ] = $v; return true; } }

$base = __DIR__ . '/../../titan-zero-trades-front-desk/includes/';
require_once $base . 'class-tzfd-rest-request-guard.php';
require_once $base . 'class-tzfd-signed-request.php';
require_once $base . 'class-tzfd-call-lifecycle.php';
require_once $base . 'class-tzfd-telephony-adapter.php';
require_once $base . 'class-tzfd-messaging-replies.php';

final class TZ_Test_Request {
    private $method; private $body; private $headers; private $params;
    public function __construct( $body, array $headers ) {
        $this->method = 'POST'; $this->body = $body; $this->headers = array();
        foreach ( $headers as $k => $v ) { $this->headers[ strtolower( $k ) ] = $v; }
        $decoded = json_decode( $body, true );
        $this->params = is_array( $decoded ) ? $decoded : array();
    }
    public function get_method() { return $this->method; }
    public function get_body() { return $this->body; }
    public function get_header( $name ) { $k = strtolower( $name ); return $this->headers[ $k ] ?? null; }
    public function get_params() { return $this->params; }
}

$GLOBALS['tz_pass'] = 0; $GLOBALS['tz_fail'] = 0;
function tz_check( $name, $ok ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}

$secret = str_repeat( 'k', 40 ); // at least 32 characters
$GLOBALS['tz_filters']['tzfd_telephony_provider_credentials_v1'] = function ( $creds, $key_id ) use ( $secret ) {
    if ( 'site-key-1' === $key_id ) {
        return array( 'enabled' => true, 'company_id' => 7, 'provider' => 'twilio', 'secret' => $secret );
    }
    return null; // Unknown key ids resolve to nothing, so they fail closed.
};

$now = 1800000000;
function sign( $ts, $body, $secret ) { return hash_hmac( 'sha256', $ts . '.' . $body, $secret ); }

// Messaging events.
$body = json_encode( array( 'event' => 'inbound_sms', 'event_id' => 'msg-000001', 'from' => '+61400000000', 'body' => 'book', 'consent' => 'customer_initiated', 'company_id' => 99 ) );
$ts   = (string) $now;
$sig  = sign( $ts, $body, $secret );
$good = new TZ_Test_Request( $body, array( 'X-TZFD-Key-Id' => 'site-key-1', 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Signature' => $sig ) );
$ev   = TZFD_Messaging_Replies::verified_event( $good, $now );
tz_check( 'valid signed reply is accepted', is_array( $ev ) );
tz_check( 'company comes from the credentials, not the body (body company 99 ignored)', is_array( $ev ) && 7 === $ev['company_id'] );
tz_check( 'provider comes from the credentials', is_array( $ev ) && 'twilio' === $ev['provider'] );

$forged = new TZ_Test_Request( $body, array( 'X-TZFD-Key-Id' => 'site-key-1', 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Signature' => str_repeat( '0', 64 ) ) );
tz_check( 'forged signature refused', null === TZFD_Messaging_Replies::verified_event( $forged, $now ) );

$tampered = new TZ_Test_Request( str_replace( 'book', 'cancel', $body ), array( 'X-TZFD-Key-Id' => 'site-key-1', 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Signature' => $sig ) );
tz_check( 'tampered body refused', null === TZFD_Messaging_Replies::verified_event( $tampered, $now ) );

$unknown_key = new TZ_Test_Request( $body, array( 'X-TZFD-Key-Id' => 'other-tenant-key', 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Signature' => $sig ) );
tz_check( 'unknown key id (wrong tenant credential) refused', null === TZFD_Messaging_Replies::verified_event( $unknown_key, $now ) );

$stale_ts = (string) ( $now - 1000 );
$stale = new TZ_Test_Request( $body, array( 'X-TZFD-Key-Id' => 'site-key-1', 'X-TZFD-Timestamp' => $stale_ts, 'X-TZFD-Signature' => sign( $stale_ts, $body, $secret ) ) );
tz_check( 'stale timestamp refused', null === TZFD_Messaging_Replies::verified_event( $stale, $now ) );

$wrong_event = json_encode( array( 'event' => 'received', 'event_id' => 'x1', 'from' => '+61400000000', 'body' => 'hi', 'consent' => 'customer_initiated' ) );
$we = new TZ_Test_Request( $wrong_event, array( 'X-TZFD-Key-Id' => 'site-key-1', 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Signature' => sign( $ts, $wrong_event, $secret ) ) );
tz_check( 'a signed event of another type is not a reply', null === TZFD_Messaging_Replies::verified_event( $we, $now ) );

$no_consent = json_encode( array( 'event' => 'inbound_sms', 'event_id' => 'msg-2', 'from' => '+61400000000', 'body' => 'hi' ) );
$nc = new TZ_Test_Request( $no_consent, array( 'X-TZFD-Key-Id' => 'site-key-1', 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Signature' => sign( $ts, $no_consent, $secret ) ) );
tz_check( 'signed event without a consent field is refused', null === TZFD_Messaging_Replies::verified_event( $nc, $now ) );

$oversize = json_encode( array( 'event' => 'inbound_sms', 'event_id' => 'msg-3', 'from' => '+61400000000', 'body' => str_repeat( 'a', 400 ), 'consent' => 'customer_initiated' ) );
$os = new TZ_Test_Request( $oversize, array( 'X-TZFD-Key-Id' => 'site-key-1', 'X-TZFD-Timestamp' => $ts, 'X-TZFD-Signature' => sign( $ts, $oversize, $secret ) ) );
tz_check( 'oversize reply body refused at the boundary', null === TZFD_Messaging_Replies::verified_event( $os, $now ) );

// Replay: the claim is the replay guard interface. A repeated signature is refused.
$GLOBALS['tz_transients'] = array();
tz_check( 'first use of a signature is claimed', TZFD_Signed_Request::claim_signature( $sig ) );
tz_check( 'replayed signature is refused', ! TZFD_Signed_Request::claim_signature( $sig ) );

// Telephony adapter: caller number is carried through, and a forged adapter event is refused.
$tel = json_encode( array( 'call_id' => 'CA1', 'event_id' => 'evt-1', 'event' => 'missed', 'from' => '+61 400 000 000' ) );
$tsig = sign( $ts, $tel, $secret );
$v = TZFD_Telephony_Adapter::validate( $tel, 'site-key-1', $ts, $tsig, array( 'enabled' => true, 'company_id' => 7, 'provider' => 'twilio', 'secret' => $secret ), $now );
tz_check( 'missed call event validates and keeps the caller number', is_array( $v ) && '+61 400 000 000' === $v['from'] && 'missed' === $v['event'] );
tz_check( 'telephony event with a wrong signature is refused', null === TZFD_Telephony_Adapter::validate( $tel, 'site-key-1', $ts, str_repeat( 'a', 64 ), array( 'enabled' => true, 'company_id' => 7, 'provider' => 'twilio', 'secret' => $secret ), $now ) );
$tel_bad_from = json_encode( array( 'call_id' => 'CA2', 'event_id' => 'evt-2', 'event' => 'missed', 'from' => str_repeat( '9', 60 ) ) );
$vb = TZFD_Telephony_Adapter::validate( $tel_bad_from, 'site-key-1', $ts, sign( $ts, $tel_bad_from, $secret ), array( 'enabled' => true, 'company_id' => 7, 'provider' => 'twilio', 'secret' => $secret ), $now );
tz_check( 'oversize caller field is dropped, event still validates', is_array( $vb ) && '' === $vb['from'] );

echo "\nSummary: {$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
