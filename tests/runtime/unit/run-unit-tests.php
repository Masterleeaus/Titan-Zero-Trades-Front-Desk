<?php
/**
 * Pure-PHP unit tests for the booking create, capacity, cancellation, rescheduling and upgrade-safety logic.
 *
 * Run:  php -d display_errors=1 tests/runtime/unit/run-unit-tests.php
 * Needs PHP 8.x with ext-json, ext-hash, ext-filter. No WordPress or database is required.
 *
 * These tests do NOT prove behaviour inside WordPress: they call the plugin's own classes
 * against stubs in bootstrap.php. The on-site checks are listed in ../README.md.
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['ut_pass'] = 0;
$GLOBALS['ut_fail'] = 0;
$GLOBALS['ut_section'] = '';

function section( $name ) {
    $GLOBALS['ut_section'] = $name;
    echo "\n== $name\n";
}

function check( $name, $ok, $detail = '' ) {
    if ( $ok ) {
        $GLOBALS['ut_pass']++;
    } else {
        $GLOBALS['ut_fail']++;
    }
    echo ( $ok ? '  PASS ' : '  FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

/** True when $fn throws an exception of class $class (or any Throwable when $class is empty). */
function throws( callable $fn, $class = '' ) {
    try {
        $fn();
        return false;
    } catch ( Throwable $e ) {
        return '' === $class || $e instanceof $class;
    }
}

function fresh_db() {
    $db = new TZFD_Fake_Wpdb();
    $GLOBALS['wpdb'] = $db;
    return $db;
}

$NOW = 1800000000;
$GLOBALS['tzfd_now'] = $NOW;

/* ------------------------------------------------------------------ */
section( 'Booking policy: create gating (TZFD_Booking_Policy)' );

function policy_request( $start_offset = 7200, $duration = 3600 ) {
    global $NOW;
    return array(
        'company_id' => 7, 'verified_company_id' => 7, 'service_id' => 'svc-1', 'site_id' => 'site-1',
        'start_utc' => $NOW + $start_offset, 'end_utc' => $NOW + $start_offset + $duration,
    );
}
function policy_rules( array $override = array() ) {
    return array_merge( array(
        'company_id' => 7, 'service_id' => 'svc-1', 'site_id' => 'site-1', 'enabled' => true,
        'min_notice_minutes' => 60, 'max_advance_days' => 30, 'duration_minutes' => 60, 'buffer_minutes' => 0,
        'max_bookings_per_customer' => 0, 'required_prerequisites' => array(), 'requires_approval' => false,
        'requires_deposit' => false,
    ), $override );
}
function policy_facts( array $override = array() ) {
    global $NOW;
    return array_merge( array(
        'company_id' => 7, 'verified' => true, 'complete' => true, 'now_utc' => $NOW,
        'booking_count' => 0, 'capacity_remaining' => 1, 'satisfied_prerequisites' => array(),
    ), $override );
}

$r = TZFD_Booking_Policy::evaluate( policy_request(), policy_rules(), policy_facts() );
check( 'valid request in capacity is eligible', true === $r['allowed'] && 'eligible' === $r['decision'] );
check( 'policy never commits a booking', false === $r['booking_committed'] && true === $r['requires_authoritative_commit_revalidation'] );

$r = TZFD_Booking_Policy::evaluate( policy_request( 1800 ), policy_rules(), policy_facts() );
check( 'booking inside minimum notice is rejected', in_array( 'insufficient_notice', $r['reasons'], true ) );

$r = TZFD_Booking_Policy::evaluate( policy_request( 40 * 86400 ), policy_rules(), policy_facts() );
check( 'booking beyond max advance days is rejected', in_array( 'too_far_ahead', $r['reasons'], true ) );

$r = TZFD_Booking_Policy::evaluate( policy_request( 7200, 1800 ), policy_rules(), policy_facts() );
check( 'duration that does not match the service is rejected', in_array( 'invalid_duration', $r['reasons'], true ) );

$r = TZFD_Booking_Policy::evaluate( policy_request(), policy_rules(), policy_facts( array( 'capacity_remaining' => 0 ) ) );
check( 'zero remaining capacity is rejected (no oversell)', false === $r['allowed'] && in_array( 'capacity_unavailable', $r['reasons'], true ) );

$r = TZFD_Booking_Policy::evaluate( policy_request(), policy_rules( array( 'max_bookings_per_customer' => 2 ) ), policy_facts( array( 'booking_count' => 2 ) ) );
check( 'per-customer booking limit is enforced', in_array( 'booking_limit', $r['reasons'], true ) );

$r = TZFD_Booking_Policy::evaluate( policy_request(), policy_rules( array( 'enabled' => false ) ), policy_facts() );
check( 'disabled service is rejected', in_array( 'service_disabled', $r['reasons'], true ) );

$r = TZFD_Booking_Policy::evaluate( policy_request(), policy_rules( array( 'required_prerequisites' => array( 'waiver' ) ) ), policy_facts() );
check( 'missing prerequisite is rejected', in_array( 'prerequisite_missing', $r['reasons'], true ) );

$r = TZFD_Booking_Policy::evaluate( policy_request(), policy_rules( array( 'requires_approval' => true ) ), policy_facts() );
check( 'approval-required service returns approval_required, not confirmed', 'approval_required' === $r['decision'] && true === $r['requires_approval'] );

$r = TZFD_Booking_Policy::evaluate( policy_request(), policy_rules( array( 'requires_deposit' => true ) ), policy_facts() );
check( 'deposit is handed off to invoicing, not charged here', true === $r['deposit_handoff_required'] && 'invoicing' === $r['deposit_owner'] );

check( 'unverified facts fail closed', throws( fn() => TZFD_Booking_Policy::evaluate( policy_request(), policy_rules(), policy_facts( array( 'verified' => false ) ) ), 'InvalidArgumentException' ) );
check( 'facts from another company fail closed', throws( fn() => TZFD_Booking_Policy::evaluate( policy_request(), policy_rules(), policy_facts( array( 'company_id' => 8 ) ) ), 'InvalidArgumentException' ) );
check( 'policy for another service fails closed', throws( fn() => TZFD_Booking_Policy::evaluate( policy_request(), policy_rules( array( 'service_id' => 'svc-2' ) ), policy_facts() ), 'InvalidArgumentException' ) );
check( 'end before start fails closed', throws( fn() => TZFD_Booking_Policy::evaluate( array_merge( policy_request(), array( 'end_utc' => policy_request()['start_utc'] ) ), policy_rules(), policy_facts() ), 'InvalidArgumentException' ) );

/* ------------------------------------------------------------------ */
section( 'Lifecycle status contract (TZFD_Lifecycle_Rules)' );

check( "'Approved' is stored as an empty string", TZFD_Lifecycle_Rules::is_allowed_admin_status( '', array( 'Approved', 'Pending' ) ) );
check( 'unknown status is refused', false === TZFD_Lifecycle_Rules::is_allowed_admin_status( 'Refunded-maybe', array( 'Approved', 'Pending' ) ) );
check( 'non-string status is refused', false === TZFD_Lifecycle_Rules::is_allowed_admin_status( array( 'Pending' ), array( 'Pending' ) ) );
check( 'an incomplete configured list still yields the empty (Approved) status', array( '' ) === TZFD_Lifecycle_Rules::allowed_admin_statuses( null ) );

/* ------------------------------------------------------------------ */
section( 'Capacity reads and double-booking rules (TZFD_Demand_Capacity, TZFD_Booking_Capacity_Reader)' );

$ctx = array( 'verified_company_id' => 7, 'snapshot_authoritative' => true, 'snapshot_complete' => true );
function slot( $key, $capacity, $assigned, $demand = 0, array $override = array() ) {
    return array_merge( array(
        'company_id' => 7, 'owner_verified' => true, 'availability_verified' => true, 'slot_key' => $key,
        'capacity' => $capacity, 'assigned' => $assigned, 'forecast_demand' => $demand,
    ), $override );
}

check( 'assigned above capacity is refused (no oversell in the snapshot)', throws( fn() => TZFD_Demand_Capacity::balance( 7, array( slot( 'slot-aaaaaaaa', 2, 3 ) ), $ctx ), 'InvalidArgumentException' ) );
check( 'unverified availability is refused', throws( fn() => TZFD_Demand_Capacity::balance( 7, array( slot( 'slot-aaaaaaaa', 2, 1, 0, array( 'availability_verified' => false ) ) ), $ctx ), 'InvalidArgumentException' ) );
check( 'duplicate slot key is refused', throws( fn() => TZFD_Demand_Capacity::balance( 7, array( slot( 'slot-aaaaaaaa', 2, 1 ), slot( 'slot-aaaaaaaa', 2, 1 ) ), $ctx ), 'InvalidArgumentException' ) );
check( 'snapshot from another company is refused', throws( fn() => TZFD_Demand_Capacity::balance( 7, array( slot( 'slot-aaaaaaaa', 2, 1, 0, array( 'company_id' => 8 ) ) ), $ctx ), 'InvalidArgumentException' ) );

$ranked = TZFD_Demand_Capacity::balance( 7, array( slot( 'slot-bbbbbbbb', 4, 1, 1 ), slot( 'slot-aaaaaaaa', 4, 4, 3 ) ), $ctx );
check( 'free capacity is computed per slot', 0 === $ranked['ranked_capacity'][0]['free_capacity'] && 3 === $ranked['ranked_capacity'][1]['free_capacity'] );
check( 'slot with the largest forecast shortfall ranks first', 'slot-aaaaaaaa' === $ranked['ranked_capacity'][0]['slot_key'] && 3 === $ranked['ranked_capacity'][0]['forecast_shortfall'] && 0 === $ranked['ranked_capacity'][1]['forecast_shortfall'] );
check( 'balancing is read-only and needs roster approval', true === $ranked['read_only'] && true === $ranked['requires_human_roster_approval'] );

// Forecast needs at least three same-hour observations.
$hist = function ( $n ) {
    $rows = array();
    for ( $i = 1; $i <= $n; $i++ ) {
        $rows[] = array( 'company_id' => 7, 'owner_verified' => true, 'kind' => 'bookings', 'period_key' => sprintf( '2030-01-%02dT09', $i ),
            'record_key' => 'rec-' . str_pad( (string) $i, 8, '0', STR_PAD_LEFT ), 'count' => $i * 2 );
    }
    return $rows;
};
$f = TZFD_Demand_Capacity::forecast( 7, 'bookings', $hist( 2 ), '2030-02-01T09', $ctx );
check( 'forecast refuses with fewer than three observations', 'insufficient_history' === $f['status'] );
$f = TZFD_Demand_Capacity::forecast( 7, 'bookings', $hist( 4 ), '2030-02-01T09', $ctx );
check( 'forecast uses same-hour mean once history suffices', 'forecast' === $f['status'] && 5 === $f['expected_count'] );

// Booking capacity reader: pages through every reservation and treats failure as unknown, not empty.
function reader_db( array $ids, $error = false, $unordered = false ) {
    $db = fresh_db();
    $db->when( '/FROM `wp_tz_bookings`/', function ( $sql, $db ) use ( $ids, $error, $unordered ) {
        if ( $error ) { $db->last_error = 'simulated read failure'; return array(); }
        preg_match( '/id<(\d+) ORDER BY id DESC LIMIT (\d+)/', $sql, $m );
        $cursor = (int) $m[1];
        $limit = (int) $m[2];
        $rows = array();
        // The real table returns newest first; the unordered variant returns oldest first to exercise the reader's guard.
        $pool = $unordered ? $ids : array_reverse( $ids );
        foreach ( $pool as $id ) {
            if ( $id < $cursor ) { $rows[] = (object) array( 'id' => (string) $id, 'posted_data' => 'a:0:{}' ); }
            if ( count( $rows ) >= $limit ) { break; }
        }
        return $rows;
    } );
    return $db;
}

$db = reader_db( array( 1, 2, 3, 4, 5 ) );
$rows = TZFD_Booking_Capacity_Reader::all_for_calendar( 1, 7, 2 );
check( 'reader pages through all reservations', is_array( $rows ) && 5 === count( $rows ) );
check( 'reader returns newest first', is_array( $rows ) && '5' === (string) $rows[0]->id );
check( 'reader stops after a short final page', 3 === count( array_filter( $db->queries, fn( $q ) => false !== strpos( $q, 'FROM `wp_tz_bookings`' ) ) ) );

$GLOBALS['wpdb'] = reader_db( array( 1, 2 ), true );
check( 'reader returns null on query failure, never an empty calendar', null === TZFD_Booking_Capacity_Reader::all_for_calendar( 1, 7, 2 ) );

$GLOBALS['wpdb'] = reader_db( array( 1, 2, 3 ), false, true );
check( 'reader rejects a non-descending page', null === TZFD_Booking_Capacity_Reader::all_for_calendar( 1, 7, 2 ) );

$GLOBALS['wpdb'] = fresh_db();
check( 'reader refuses unscoped reads (company 0)', null === TZFD_Booking_Capacity_Reader::all_for_calendar( 1, 0 ) && 0 === count( $GLOBALS['wpdb']->queries ) );

/* ------------------------------------------------------------------ */
section( 'Per-form capacity lock (TZFD_Capacity_Lock)' );

$db = fresh_db();
$db->when( '/^SELECT CONNECTION_ID\(\)$/', fn() => '42' );
$db->when( '/GET_LOCK\(/', fn( $sql ) => (object) array( 'connection_id' => '42', 'acquired' => $GLOBALS['lock_result'] ?? '1' ) );
$db->when( '/RELEASE_LOCK\(/', fn() => '1' );

$n1 = TZFD_Capacity_Lock::name( $db, 1 );
$n2 = TZFD_Capacity_Lock::name( $db, 2 );
check( 'lock name is deterministic per form', $n1 === TZFD_Capacity_Lock::name( $db, 1 ) );
check( 'lock names differ between forms', '' !== $n1 && $n1 !== $n2 );
check( 'lock name fits the 64-character MySQL limit', strlen( $n1 ) <= 64 );
check( 'invalid form IDs produce no lock name', '' === TZFD_Capacity_Lock::name( $db, 0 ) && '' === TZFD_Capacity_Lock::name( $db, 'abc' ) && '' === TZFD_Capacity_Lock::name( $db, -1 ) );

$GLOBALS['lock_result'] = '1';
$h = TZFD_Capacity_Lock::acquire( $db, 1, 1 );
check( 'lock is granted when free', is_array( $h ) && isset( $h['token'] ) );
check( 'second acquire on the same connection is refused (no re-entry)', false === TZFD_Capacity_Lock::acquire( $db, 1, 1 ) );
check( 'a forged handle cannot release the lock', false === TZFD_Capacity_Lock::release( $db, array_merge( $h, array( 'token' => str_repeat( '0', 32 ) ) ) ) );
check( 'the owning handle releases the lock', true === TZFD_Capacity_Lock::release( $db, $h ) );
check( 'a released handle cannot be reused', false === TZFD_Capacity_Lock::release( $db, $h ) );

$GLOBALS['lock_result'] = '0';
check( 'lock busy after timeout is refused, so the write does not proceed', false === TZFD_Capacity_Lock::acquire( $db, 1, 1 ) );
$GLOBALS['lock_result'] = '1';

check( 'timeout outside 0..30 falls back to the default', is_array( $h2 = TZFD_Capacity_Lock::acquire( $db, 1, 999 ) ) );
TZFD_Capacity_Lock::release( $db, $h2 );

/* ------------------------------------------------------------------ */
section( 'Booking create transaction (TZFD_Booking_Create_Transaction)' );

function create_row() {
    return array( 'formid' => 1, 'time' => '2030-01-01 09:00:00', 'ipaddr' => '127.0.0.1', 'notifyto' => 'customer@example.test',
        'posted_data' => 'x', 'data' => 'DATA', 'whoadded' => '1' );
}
function create_params() {
    return array( 'formid' => 1, 'name' => 'Test Customer', 'email' => 'customer@example.test' );
}

$db = fresh_db();
$db->seed( 'tz_bookings', array() );
$res = TZFD_Booking_Create_Transaction::create( $db, 7, create_row(), create_params(), TZFD_Intake_Mode::MODE_INSTANT );
check( 'successful create reports success with the new id', 'success' === $res['status'] && 1 === $res['id'] );
check( 'successful create commits exactly one row for the company', 1 === count( $db->rows( 'tz_bookings' ) ) && 7 === (int) $db->rows( 'tz_bookings' )[0]['company_id'] );
$stored = unserialize( $db->rows( 'tz_bookings' )[0]['posted_data'] );
check( 'stored payload carries the item number', is_array( $stored ) && 1 === $stored['itemnumber'] );
check( 'a successful create issues COMMIT and not ROLLBACK', in_array( 'COMMIT', $db->queries, true ) && ! in_array( 'ROLLBACK', $db->queries, true ) );

$res = TZFD_Booking_Create_Transaction::create( $db, 7, create_row(), create_params() + array( 'apps' => array( array( 'id' => 1, 'cancelled' => '' ) ) ), TZFD_Intake_Mode::MODE_REQUEST );
$stored = unserialize( $db->rows( 'tz_bookings' )[1]['posted_data'] );
check( 'request-mode create stores the Pending intake marker', 'request' === ( $stored['titan_zero_front_desk_intake_mode'] ?? '' ) );

$db = fresh_db();
$db->seed( 'tz_bookings', array( array( 'id' => 9, 'company_id' => 7, 'posted_data' => 'keep' ) ) );
$before = $db->rows( 'tz_bookings' );
$res = TZFD_Booking_Create_Transaction::create( $db, 0, create_row(), create_params(), TZFD_Intake_Mode::MODE_INSTANT );
check( 'create with company 0 fails closed before any statement runs', 'failed' === $res['status'] && 0 === count( $db->queries ) );

$db = fresh_db();
$db->engine = 'MyISAM';
$res = TZFD_Booking_Create_Transaction::create( $db, 7, create_row(), create_params(), TZFD_Intake_Mode::MODE_INSTANT );
check( 'non-InnoDB storage is refused before START TRANSACTION', 'failed' === $res['status'] && ! in_array( 'START TRANSACTION', $db->queries, true ) );

// Insert failure: the transaction must roll back and leave committed rows untouched.
class TZFD_Fake_Wpdb_Insert_Fails extends TZFD_Fake_Wpdb {
    public function insert( $table, $data ) { return false; }
}
$db = new TZFD_Fake_Wpdb_Insert_Fails();
$GLOBALS['wpdb'] = $db;
$db->seed( 'tz_bookings', array( array( 'id' => 9, 'company_id' => 7, 'posted_data' => 'keep' ) ) );
$res = TZFD_Booking_Create_Transaction::create( $db, 7, create_row(), create_params(), TZFD_Intake_Mode::MODE_INSTANT );
check( 'insert failure reports failed with no id', 'failed' === $res['status'] && 0 === $res['id'] );
check( 'insert failure rolls back', in_array( 'ROLLBACK', $db->queries, true ) );
check( 'insert failure leaves no orphan row', 1 === count( $db->rows( 'tz_bookings' ) ) );

// Lost COMMIT acknowledgement: must be reported unknown, and must not invite a rollback or retry.
$db = fresh_db();
$db->fail_commit = true;
$res = TZFD_Booking_Create_Transaction::create( $db, 7, create_row(), create_params(), TZFD_Intake_Mode::MODE_INSTANT );
check( 'failed COMMIT is reported as unknown, not success', 'unknown' === $res['status'] && 1 === $res['id'] );
check( 'failed COMMIT does not issue a ROLLBACK that could mislead the caller', ! in_array( 'ROLLBACK', $db->queries, true ) );

/* ------------------------------------------------------------------ */
section( 'Customer cancellation links and state (TZFD_Customer_Cancellation)' );

$secret = str_repeat( 'k', 40 );
$version = str_repeat( 'a', 64 );
$exp = TZFD_Customer_Cancellation::new_expiry( $NOW );
$tok = TZFD_Customer_Cancellation::create_token( 15, 'Customer@Example.test', $secret, $version, $exp );
check( 'cancellation token is 64 hex characters', 1 === preg_match( '/^[a-f0-9]{64}$/', $tok ) );
check( 'token verifies for its booking, email (case-insensitive) and version', TZFD_Customer_Cancellation::verify_token( 15, 'customer@example.test', $tok, $secret, $version, $exp, $NOW ) );
check( 'token is refused after its 14-day window', ! TZFD_Customer_Cancellation::verify_token( 15, 'customer@example.test', $tok, $secret, $version, $exp, $exp + 1 ) );
check( 'token is refused for a different booking', ! TZFD_Customer_Cancellation::verify_token( 16, 'customer@example.test', $tok, $secret, $version, $exp, $NOW ) );
check( 'token is refused for a different email', ! TZFD_Customer_Cancellation::verify_token( 15, 'other@example.test', $tok, $secret, $version, $exp, $NOW ) );
check( 'token is refused once the booking version changed', ! TZFD_Customer_Cancellation::verify_token( 15, 'customer@example.test', $tok, $secret, str_repeat( 'b', 64 ), $exp, $NOW ) );
check( 'token is refused with a different secret', ! TZFD_Customer_Cancellation::verify_token( 15, 'customer@example.test', $tok, str_repeat( 'z', 40 ), $version, $exp, $NOW ) );
check( 'malformed token is refused', ! TZFD_Customer_Cancellation::verify_token( 15, 'customer@example.test', 'nothex', $secret, $version, $exp, $NOW ) );
check( 'token without an expiry is never issued', '' === TZFD_Customer_Cancellation::create_token( 15, 'customer@example.test', $secret, $version, 0 ) );
check( 'expiry is 14 days from issue', $exp - $NOW === TZFD_Customer_Cancellation::TOKEN_TTL && 1209600 === TZFD_Customer_Cancellation::TOKEN_TTL );

$apps = array( 'apps' => array( array( 'id' => 1, 'cancelled' => '' ) ), 'app_status_1' => '' );
check( 'a pending-or-open booking is cancelable', TZFD_Customer_Cancellation::is_cancelable_data( $apps ) );
check( 'a booking with a lock is not cancelable by the customer', ! TZFD_Customer_Cancellation::is_cancelable_data( $apps + array( 'lock' => 1 ) ) );
check( 'mismatched flat status is refused', ! TZFD_Customer_Cancellation::is_cancelable_data( array( 'apps' => array( array( 'cancelled' => '' ) ), 'app_status_1' => 'Pending' ) ) );
check( 'an unknown status is refused', ! TZFD_Customer_Cancellation::is_cancelable_data( array( 'apps' => array( array( 'cancelled' => 'Refunded' ) ) ) ) );

$cancelled = TZFD_Customer_Cancellation::with_cancelled_status( $apps );
check( 'cancellation sets every appointment to the cancelled status', 'Cancelled by customer' === $cancelled['apps'][0]['cancelled'] && 'Cancelled by customer' === $cancelled['app_status_1'] );
check( 'a cancelled booking cannot be cancelled again', false === TZFD_Customer_Cancellation::with_cancelled_status( $cancelled ) );

// Compare-and-swap: the update must be guarded by the exact stored snapshot.
$serialized = serialize( $apps );
$row = (object) array( 'formid' => 1, 'notifyto' => 'customer@example.test', 'data' => 'DATA', 'posted_data' => $serialized );
$db = fresh_db();
$db->when( '/^UPDATE /', fn() => 1 );
$res = TZFD_Customer_Cancellation::compare_and_swap( $db, 15, $serialized, $row );
$sql = end( $db->queries );
check( 'CAS update succeeds on the matching snapshot', 1 === $res );
check( 'CAS guards on the whole stored snapshot in binary comparison', false !== strpos( $sql, 'BINARY posted_data=BINARY' ) && false !== strpos( $sql, 'BINARY data=BINARY' ) && false !== strpos( $sql, 'BINARY notifyto=BINARY' ) );
check( 'CAS is scoped to the booking and form', false !== strpos( $sql, 'id=15' ) && false !== strpos( $sql, 'formid=1' ) );

$stale = (object) array( 'formid' => 1, 'notifyto' => 'customer@example.test', 'data' => 'DATA', 'posted_data' => 'a:0:{}' );
$db = fresh_db();
check( 'CAS refuses when the authenticated row no longer matches', null === TZFD_Customer_Cancellation::compare_and_swap( $db, 15, $serialized, $stale ) && 0 === count( $db->queries ) );
check( 'CAS refuses an invalid booking id', null === TZFD_Customer_Cancellation::compare_and_swap( $db, 0, $serialized, $row ) );

/* ------------------------------------------------------------------ */
section( 'Customer rescheduling links (TZFD_Customer_Rescheduling)' );

$rt = TZFD_Customer_Rescheduling::token( 15, 1, 'customer@example.test', $version, $secret );
check( 'reschedule token is deterministic', $rt === TZFD_Customer_Rescheduling::token( 15, 1, 'customer@example.test', $version, $secret ) && '' !== $rt );
check( 'reschedule token is bound to the form', $rt !== TZFD_Customer_Rescheduling::token( 15, 2, 'customer@example.test', $version, $secret ) );
check( 'reschedule token is bound to the booking version', $rt !== TZFD_Customer_Rescheduling::token( 15, 1, 'customer@example.test', str_repeat( 'c', 64 ), $secret ) );
check( 'reschedule token is not interchangeable with a cancel token', $rt !== $tok );
check( 'reschedule token refuses a malformed version', '' === TZFD_Customer_Rescheduling::token( 15, 1, 'customer@example.test', 'short', $secret ) );

check( 'reschedule accepts a same-day slot', TZFD_Customer_Rescheduling::same_day_slot( '09:00/10:00' ) );
check( 'reschedule refuses an overnight slot (12-hour formatter cannot label it)', ! TZFD_Customer_Rescheduling::same_day_slot( '23:00/01:00' ) );
check( 'reschedule refuses a slot ending before it starts', ! TZFD_Customer_Rescheduling::same_day_slot( '10:00/09:00' ) );
check( 'reschedule refuses out-of-range clock values', ! TZFD_Customer_Rescheduling::same_day_slot( '24:00/25:00' ) );

$v1 = TZFD_Customer_Rescheduling::version( (object) array( 'posted_data' => 'p1', 'data' => 'd', 'notifyto' => 'n@example.test', 'formid' => 1 ) );
$v2 = TZFD_Customer_Rescheduling::version( (object) array( 'posted_data' => 'p2', 'data' => 'd', 'notifyto' => 'n@example.test', 'formid' => 1 ) );
check( 'a change to posted_data changes the booking version (old links die)', $v1 !== $v2 && 64 === strlen( $v1 ) );
check( 'oversized stored payload is refused by the reschedule decoder', false === TZFD_Customer_Rescheduling::decode( str_repeat( 'a', 1048577 ) ) );
check( 'non-serialized payload is refused by the decoder', false === TZFD_Customer_Rescheduling::decode( 'not serialized' ) );

/* ------------------------------------------------------------------ */
section( 'Legacy stored-data decoder (TZFD_Stored_Data) and upgrade safety' );

check( 'a plain legacy array decodes', is_array( TZFD_Stored_Data::decode( serialize( array( 'apps' => array( array( 'id' => 1 ) ) ) ) ) ) );
check( 'a legacy object payload is not restored as an object', null === TZFD_Stored_Data::decode( 'a:1:{s:1:"x";O:8:"stdClass":0:{}}' ) );
check( 'malformed legacy data returns null, never an empty calendar', null === TZFD_Stored_Data::decode( 'a:2:{' ) );
check( 'oversized legacy data returns null before parsing', null === TZFD_Stored_Data::decode( 'a:0:{}' . str_repeat( ' ', TZFD_Stored_Data::MAX_BYTES ) ) );
check( 'projection returns only named fields', array( 'apps' => array( 1 ) ) === TZFD_Stored_Data::project( serialize( array( 'apps' => array( 1 ), 'other' => 'x' ) ), array( 'apps' ) ) );
check( 'decode_booking rejects a non-array appointment list', null === TZFD_Stored_Data::decode_booking( serialize( array( 'apps' => 'oops' ) ) ) );
check( 'decode_booking rejects a non-string appointment date', null === TZFD_Stored_Data::decode_booking( serialize( array( 'apps' => array( array( 'date' => 20300101 ) ) ) ) ) );

/* ------------------------------------------------------------------ */
section( 'Slot holds and schema readiness (TZFD_Slot_Holds)' );

$cols = array( array( 'Field' => 'id' ), array( 'Field' => 'company_id' ), array( 'Field' => 'token_hash' ), array( 'Field' => 'state' ),
    array( 'Field' => 'start_utc' ), array( 'Field' => 'end_utc' ), array( 'Field' => 'expires_at' ), array( 'Field' => 'payload' ),
    array( 'Field' => 'booking_id' ), array( 'Field' => 'created_at' ) );
$good_idx = array(
    array( 'Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Seq_in_index' => 1, 'Non_unique' => 0 ),
    array( 'Key_name' => 'company_token', 'Column_name' => 'company_id', 'Seq_in_index' => 1, 'Non_unique' => 0 ),
    array( 'Key_name' => 'company_token', 'Column_name' => 'token_hash', 'Seq_in_index' => 2, 'Non_unique' => 0 ),
);
check( 'schema is ready with the company-scoped unique token index', TZFD_Slot_Holds::schema_ready( $cols, $good_idx ) );
check( 'schema is NOT ready when the token index is not scoped to the company', ! TZFD_Slot_Holds::schema_ready( $cols, array_slice( $good_idx, 0, 1 ) ) );
check( 'schema is NOT ready when a required column is missing', ! TZFD_Slot_Holds::schema_ready( array_slice( $cols, 1 ), $good_idx ) );
check( 'schema is NOT ready when the unique index is non-unique', ! TZFD_Slot_Holds::schema_ready( $cols, array( $good_idx[0], array( 'Key_name' => 'company_token', 'Column_name' => 'company_id', 'Seq_in_index' => 1, 'Non_unique' => 1 ), $good_idx[2] ) ) );

$req = array( 'company_id' => 'co-1', 'verified_company_id' => 'co-1', 'required_resource_ids' => array( 'r1' ),
    'start' => '2030-01-01T09:00:00Z', 'end' => '2030-01-01T10:00:00Z', 'ttl_seconds' => 300 );
check( 'valid hold request validates', is_array( TZFD_Slot_Holds::validate( $req ) ) );
check( 'hold with an unverified company is refused', throws( fn() => TZFD_Slot_Holds::validate( array_merge( $req, array( 'verified_company_id' => 'co-2' ) ) ), 'InvalidArgumentException' ) );
check( 'hold with a duplicate resource is refused', throws( fn() => TZFD_Slot_Holds::validate( array_merge( $req, array( 'required_resource_ids' => array( 'r1', 'r1' ) ) ) ), 'InvalidArgumentException' ) );
check( 'hold with end before start is refused', throws( fn() => TZFD_Slot_Holds::validate( array_merge( $req, array( 'end' => '2030-01-01T08:00:00Z' ) ) ), 'InvalidArgumentException' ) );
check( 'hold longer than 24 hours is refused', throws( fn() => TZFD_Slot_Holds::validate( array_merge( $req, array( 'end' => '2030-01-03T10:00:00Z' ) ) ), 'InvalidArgumentException' ) );
check( 'hold TTL below 30 seconds is refused', throws( fn() => TZFD_Slot_Holds::validate( array_merge( $req, array( 'ttl_seconds' => 10 ) ) ), 'InvalidArgumentException' ) );
check( 'hold TTL above 900 seconds is refused', throws( fn() => TZFD_Slot_Holds::validate( array_merge( $req, array( 'ttl_seconds' => 901 ) ) ), 'InvalidArgumentException' ) );
check( 'hold with a local time and no offset is refused', throws( fn() => TZFD_Slot_Holds::validate( array_merge( $req, array( 'start' => '2030-01-01 09:00:00' ) ) ), 'InvalidArgumentException' ) );

$t0 = TZFD_Resource_Availability::instant( '2030-01-01T09:00:00Z' );
$t1 = TZFD_Resource_Availability::instant( '2030-01-01T10:00:00Z' );
$held = array(
    'company_id' => 'co-1', 'state' => 'held', 'start_utc' => gmdate( 'Y-m-d H:i:s', $t0 ), 'end_utc' => gmdate( 'Y-m-d H:i:s', $t1 ),
    'payload' => json_encode( array( 'required_resource_ids' => array( 'r1' ), 'start' => '2030-01-01T09:00:00Z', 'end' => '2030-01-01T10:00:00Z', 'quantity' => 1 ) ),
);
check( 'a held slot yields its confirmation payload', is_array( TZFD_Slot_Holds::confirmation_payload( $held, 'co-1' ) ) );
check( 'a hold for another company is never confirmed', false === TZFD_Slot_Holds::confirmation_payload( $held, 'co-2' ) );
check( 'an already-confirmed hold is not confirmed again', false === TZFD_Slot_Holds::confirmation_payload( array_merge( $held, array( 'state' => 'confirmed' ) ), 'co-1' ) );
check( 'a hold whose stored times differ from its payload is refused', false === TZFD_Slot_Holds::confirmation_payload( array_merge( $held, array( 'end_utc' => '2030-01-01 11:00:00' ) ), 'co-1' ) );
check( 'a payload with zero quantity is refused', false === TZFD_Slot_Holds::confirmation_payload( array_merge( $held, array( 'payload' => json_encode( array( 'required_resource_ids' => array( 'r1' ), 'start' => '2030-01-01T09:00:00Z', 'end' => '2030-01-01T10:00:00Z', 'quantity' => 0 ) ) ) ), 'co-1' ) );

/* ------------------------------------------------------------------ */
section( 'Versioned migrations and upgrade safety (TZFD_Migrations)' );

$GLOBALS['tzfd_options'] = array();
$GLOBALS['tzfd_user_caps'] = array( 'manage_options' => true );
$plan = TZFD_Migrations::plan();
check( 'with no reviewed migrations the plan changes nothing', array() === $plan['steps'] && true === $plan['no_changes_made'] );
check( 'migration run refuses without explicit authorization', is_object( TZFD_Migrations::run( false ) ) && 'tzfd_migration_forbidden' === TZFD_Migrations::run( false )->code );
$GLOBALS['tzfd_user_caps'] = array();
check( 'migration run refuses a user without manage_options', 'tzfd_migration_forbidden' === TZFD_Migrations::run( true )->code );
$GLOBALS['tzfd_user_caps'] = array( 'manage_options' => true );
$res = TZFD_Migrations::run( true );
check( 'authorized run with no steps completes and releases its lock', is_array( $res ) && 'complete' === $res['status'] && false === get_option( TZFD_Migrations::LOCK, false ) );
update_option( TZFD_Migrations::LOCK, time() - 10 );
check( 'a fresh lock blocks a concurrent migration run', 'tzfd_migration_locked' === TZFD_Migrations::run( true )->code );
update_option( TZFD_Migrations::LOCK, time() - 3600 );
check( 'an expired lock is not silently taken over', 'tzfd_migration_stale_lock' === TZFD_Migrations::run( true )->code );
check( 'clearing a lock needs administrator approval', 'tzfd_migration_forbidden' === TZFD_Migrations::clear_lock_after_review( false )->code );

echo "\nSummary: {$GLOBALS['ut_pass']} passed, {$GLOBALS['ut_fail']} failed\n";
exit( $GLOBALS['ut_fail'] > 0 ? 1 : 0 );
