<?php
/**
 * Offline unit checks for the missed-call and callback planners (issue #30).
 *
 * Runs without WordPress: WordPress functions used by the classes are stubbed here.
 * Usage: php -I tests/unit/missed-call-planners.php
 * Exit code 0 means every check passed.
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal WordPress stubs. Transients are kept in memory for the replay checks.
$GLOBALS['tzfd_test_transients'] = array();
function get_transient( $key ) { return $GLOBALS['tzfd_test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['tzfd_test_transients'][ $key ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }

/**
 * In-memory wp_options model for the replay guard (main's TZFD_Signed_Request::claim_signature uses
 * INSERT IGNORE, a conditional UPDATE and a DELETE). Same behaviour as tests/unit/signed-event-tests.php.
 */
final class TZ_Test_Options_Wpdb {
    public $prefix  = 'wp_';
    public $options = 'wp_options';
    public $rows    = array();
    public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
    public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
    public function query( $p ) {
        $sql = $p['sql']; $a = $p['args'];
        if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
            if ( isset( $this->rows[ $a[0] ] ) ) { return 0; }
            $this->rows[ $a[0] ] = $a[1];
            return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE' ) ) {
            if ( ! isset( $this->rows[ $a[1] ] ) || (int) $this->rows[ $a[1] ] > (int) $a[2] ) { return 0; }
            $this->rows[ $a[1] ] = $a[0];
            return 1;
        }
        if ( 0 === strpos( $sql, 'DELETE' ) ) {
            $n = 0; $prefix = rtrim( $a[0], '%' );
            foreach ( $this->rows as $name => $v ) {
                if ( 0 === strpos( $name, $prefix ) && (int) $v <= (int) $a[1] ) { unset( $this->rows[ $name ] ); $n++; }
            }
            return $n;
        }
        return false;
    }
}
$GLOBALS['wpdb'] = new TZ_Test_Options_Wpdb();
function add_action( ...$args ) { return true; }
function add_filter( ...$args ) { return true; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function do_action( ...$args ) { return null; }

$plugin = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/';
require_once $plugin . 'class-tzfd-signed-request.php';
require_once $plugin . 'class-tzfd-call-lifecycle.php';
require_once $plugin . 'class-tzfd-telephony-adapter.php';
require_once $plugin . 'class-tzfd-event-deduplication.php';
require_once $plugin . 'class-tzfd-caller-automation.php';
require_once $plugin . 'class-tzfd-call-recovery.php';

$pass = 0;
$fail = 0;
function check( $name, $cond ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; }
}
function throws( callable $fn ) {
    try { $fn(); return false; } catch ( Throwable $e ) { return true; }
}

// ---- Signed request (missed-call webhook) -------------------------------
$secret = str_repeat( 'ab', 32 ); // 64 chars, meets MIN_SECRET
$body   = '{"event":"missed_call","from":"+61400000000"}';
$now    = 1800000000;
$ts     = (string) $now;
$sig    = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret );

check( 'signed request: valid signature accepted', TZFD_Signed_Request::signature_valid( $secret, $ts, $sig, $body, $now ) );
check( 'signed request: tampered body rejected', ! TZFD_Signed_Request::signature_valid( $secret, $ts, $sig, $body . ' ', $now ) );
check( 'signed request: wrong secret rejected', ! TZFD_Signed_Request::signature_valid( str_repeat( 'cd', 32 ), $ts, $sig, $body, $now ) );
check( 'signed request: short secret fails closed', ! TZFD_Signed_Request::signature_valid( 'short', $ts, $sig, $body, $now ) );
check( 'signed request: stale timestamp (>300s) rejected', ! TZFD_Signed_Request::signature_valid( $secret, $ts, $sig, $body, $now + 301 ) );
check( 'signed request: non-numeric timestamp rejected', ! TZFD_Signed_Request::signature_valid( $secret, 'abc', $sig, $body, $now ) );
check( 'signed request: missing sha256= prefix rejected', ! TZFD_Signed_Request::signature_valid( $secret, $ts, substr( $sig, 7 ), $body, $now ) );
check( 'signed request: first claim of a signature succeeds', TZFD_Signed_Request::claim_signature( $sig ) === true );
check( 'signed request: replayed signature refused', TZFD_Signed_Request::claim_signature( $sig ) === false );

// ---- Telephony adapter: authentication and tenant scoping -----------------
$creds = array( 'enabled' => true, 'company_id' => 7, 'provider' => 'acme_phone', 'secret' => str_repeat( 'k', 40 ) );
$raw   = json_encode( array( 'call_id' => 'CA123456', 'event_id' => 'EV-0000001', 'event' => 'missed', 'company_id' => 99, 'provider' => 'other' ) );
$t     = (string) $now;
$s     = hash_hmac( 'sha256', $t . '.' . $raw, $creds['secret'] );

$ev = TZFD_Telephony_Adapter::validate( $raw, 'key-1', $t, $s, $creds, $now );
check( 'adapter: verified event accepted', is_array( $ev ) );
check( 'adapter: company comes from credentials, not body (body claims 99)', is_array( $ev ) && 7 === $ev['company_id'] && 'acme_phone' === $ev['provider'] );
check( 'adapter: no credentials resolver (null) fails closed', null === TZFD_Telephony_Adapter::validate( $raw, 'key-1', $t, $s, null, $now ) );
check( 'adapter: disabled credentials fail closed', null === TZFD_Telephony_Adapter::validate( $raw, 'key-1', $t, $s, array_merge( $creds, array( 'enabled' => false ) ), $now ) );
check( 'adapter: wrong signature fails closed', null === TZFD_Telephony_Adapter::validate( $raw, 'key-1', $t, str_repeat( '0', 64 ), $creds, $now ) );
check( 'adapter: timestamp outside 300s window fails closed', null === TZFD_Telephony_Adapter::validate( $raw, 'key-1', $t, $s, $creds, $now + 400 ) );
check( 'adapter: malformed key id rejected', null === TZFD_Telephony_Adapter::validate( $raw, 'bad key!', $t, $s, $creds, $now ) );
$bad_event = json_encode( array( 'call_id' => 'CA1', 'event_id' => 'EV1', 'event' => 'transcript_dump' ) );
$bad_sig   = hash_hmac( 'sha256', $t . '.' . $bad_event, $creds['secret'] );
check( 'adapter: unknown event type rejected', null === TZFD_Telephony_Adapter::validate( $bad_event, 'key-1', $t, $bad_sig, $creds, $now ) );

// ---- Event deduplication: duplicate delivery is idempotent ----------------
$company = 7;
$event   = array( 'company_id' => 7, 'domain' => 'phone_calls', 'provider' => 'acme_phone', 'event_id' => 'EV-0000001' );
$ctx     = array( 'verified_company_id' => 7, 'snapshot_authoritative' => true, 'snapshot_complete' => true );
$first   = TZFD_Event_Deduplication::check( $company, $event, array(), $ctx );
check( 'dedup: unseen event planned as new_event', 'new_event' === $first['decision'] );
check( 'dedup: planner never commits (write must be atomic elsewhere)', false === $first['can_commit'] && true === $first['requires_atomic_unique_insert'] );
$second  = TZFD_Event_Deduplication::check( $company, $event, array( $first['event_key'] ), $ctx );
check( 'dedup: redelivered event planned as duplicate', 'duplicate' === $second['decision'] );
check( 'dedup: wrong-tenant context refused', throws( function () use ( $company, $event ) {
    TZFD_Event_Deduplication::check( $company, $event, array(), array( 'verified_company_id' => 8, 'snapshot_authoritative' => true, 'snapshot_complete' => true ) );
} ) );
check( 'dedup: event carrying another company refused', throws( function () use ( $company, $event, $ctx ) {
    $other = $event; $other['company_id'] = 8;
    TZFD_Event_Deduplication::check( $company, $other, array(), $ctx );
} ) );
check( 'dedup: incomplete snapshot refused', throws( function () use ( $company, $event ) {
    TZFD_Event_Deduplication::check( $company, $event, array(), array( 'verified_company_id' => 7, 'snapshot_authoritative' => true, 'snapshot_complete' => false ) );
} ) );

// ---- Caller identity: phone match is a candidate, not authentication -------
$cctx = array( 'verified_company_id' => 7, 'snapshot_authoritative' => true, 'snapshot_complete' => true, 'now_utc' => $now );
$call = array( 'company_id' => 7, 'call_key' => 'call-key-0001' );
$one  = array( array( 'company_id' => 7, 'owner_verified' => true, 'workspace_customer_id' => 11, 'identity_verified' => true ) );
$amb  = array(
    array( 'company_id' => 7, 'owner_verified' => true, 'workspace_customer_id' => 11, 'identity_verified' => true ),
    array( 'company_id' => 7, 'owner_verified' => true, 'workspace_customer_id' => 12, 'identity_verified' => true ),
);
$phone_only = array( array( 'company_id' => 7, 'owner_verified' => true, 'workspace_customer_id' => 11, 'identity_verified' => false ) );

$r1 = TZFD_Caller_Automation::identity( 7, $call, $one, $cctx );
check( 'identity: single verified match resolves', 'verified' === $r1['status'] && 11 === $r1['workspace_customer_id'] );
$r2 = TZFD_Caller_Automation::identity( 7, $call, $amb, $cctx );
check( 'identity: multiple verified matches are ambiguous and resolve to no one', 'ambiguous' === $r2['status'] && null === $r2['workspace_customer_id'] );
$r3 = TZFD_Caller_Automation::identity( 7, $call, $phone_only, $cctx );
check( 'identity: phone-number-only match stays unverified', 'unverified' === $r3['status'] && null === $r3['workspace_customer_id'] );
check( 'identity: candidate from another company refused', throws( function () use ( $call, $cctx ) {
    TZFD_Caller_Automation::identity( 7, $call, array( array( 'company_id' => 8, 'owner_verified' => true, 'workspace_customer_id' => 11, 'identity_verified' => true ) ), $cctx );
} ) );
check( 'identity: unverified owner candidate refused', throws( function () use ( $call, $cctx ) {
    TZFD_Caller_Automation::identity( 7, $call, array( array( 'company_id' => 7, 'owner_verified' => false, 'workspace_customer_id' => 11, 'identity_verified' => true ) ), $cctx );
} ) );
check( 'identity: output carries no phone or name fields', ! array_key_exists( 'phone', $r1 ) && ! array_key_exists( 'name', $r1 ) );

// ---- Callback planning: offline, consent-gated, no duplicate tasks --------
$windows = array( array( 'company_id' => 7, 'owner_verified' => true, 'start_utc' => $now + 600, 'end_utc' => $now + 3600 ) );
$cb_call = array( 'company_id' => 7, 'call_key' => 'call-key-0001', 'status' => 'missed', 'contact_permitted' => true );
$cb = TZFD_Caller_Automation::callback( 7, $cb_call, $windows, array(), $cctx );
check( 'callback: proposed at next verified window', 'proposed' === $cb['status'] && $now + 600 === $cb['callback_at_utc'] );
check( 'callback: dedupe key is deterministic', $cb['dedupe_key'] === hash( 'sha256', '7:call-key-0001:callback' ) );
check( 'callback: no consent yields blocked_consent', 'blocked_consent' === TZFD_Caller_Automation::callback( 7, array_merge( $cb_call, array( 'contact_permitted' => false ) ), $windows, array(), $cctx )['status'] );
$existing = array( array( 'company_id' => 7, 'owner_verified' => true, 'call_key' => 'call-key-0001', 'status' => 'queued' ) );
check( 'callback: already-queued task is not duplicated', 'existing_callback' === TZFD_Caller_Automation::callback( 7, $cb_call, $windows, $existing, $cctx )['status'] );
check( 'callback: no window yields no_window', 'no_window' === TZFD_Caller_Automation::callback( 7, $cb_call, array(), array(), $cctx )['status'] );
check( 'callback: task from another company refused', throws( function () use ( $cb_call, $windows, $cctx ) {
    TZFD_Caller_Automation::callback( 7, $cb_call, $windows, array( array( 'company_id' => 8, 'owner_verified' => true, 'call_key' => 'call-key-0001', 'status' => 'queued' ) ), $cctx );
} ) );

// ---- Recovery decisions for the text-back path ----------------------------
check( 'recovery: eligible missed call', 'eligible' === TZFD_Call_Recovery::outcome( 'missed_call', 'missed', false, true, true ) );
check( 'recovery: opted-out caller never texted', 'opted_out' === TZFD_Call_Recovery::outcome( 'missed_call', 'missed', true, true, true ) );
check( 'recovery: rate limit applied', 'rate_limited' === TZFD_Call_Recovery::outcome( 'missed_call', 'missed', false, false, true ) );
check( 'recovery: unconfigured gateway not sent', 'not_configured' === TZFD_Call_Recovery::outcome( 'missed_call', 'missed', false, true, false ) );
check( 'recovery: answered call ignored', 'ignored' === TZFD_Call_Recovery::outcome( 'missed_call', 'answered', false, true, true ) );

// ---- Call lifecycle: out-of-order events never revert terminal states -----
check( 'lifecycle: missed call cannot move back to answered', null === TZFD_Call_Lifecycle::next_state( 'missed', 'answered' ) );
check( 'lifecycle: ringing call moves to missed', 'missed' === TZFD_Call_Lifecycle::next_state( 'ringing', 'missed' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
