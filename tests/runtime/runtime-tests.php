<?php
/**
 * Runtime checks for Front Desk 3.0.128 r21 on the disposable test site.
 * Run: wp --allow-root eval-file runtime-tests.php   (not shipped in the plugin)
 */
// Resolves against the active WordPress install (WP_PLUGIN_DIR), not a fixed server path.
if ( ! defined( 'WP_PLUGIN_DIR' ) ) { echo "FAIL harness -- run through WP-CLI\n"; return; }
$tz_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-intake-mode.php', 'class-tzfd-final-booking-write.php', 'class-tzfd-transaction-storage.php', 'class-tzfd-booking-create-transaction.php', 'class-tzfd-privacy-exporter.php' ) as $tz_file ) {
    require_once $tz_inc . $tz_file;
}
global $wpdb;
$GLOBALS['rt_pass'] = 0; $GLOBALS['rt_fail'] = 0;
function rt_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['rt_pass']++; } else { $GLOBALS['rt_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

wp_set_current_user( 1 );
$company = (int) TZFD_Scoped_Store::company_id();
rt_check( 'company resolves for admin', $company > 0, 'company=' . $company );

$email  = 'rt-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
$table  = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$count  = function () use ( $wpdb, $table, $company ) {
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE company_id = %d", $company ) );
};

// 1. Successful create through the real transaction.
$before = $count();
$params = array( 'formid' => 1, 'name' => 'RT Test', 'email' => $email, 'notes' => 'runtime test' );
$row    = array(
    'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1',
    'notifyto' => $email, 'posted_data' => serialize( $params ), 'data' => 'RT TEST', 'whoadded' => '1',
);
$res = TZFD_Booking_Create_Transaction::create( $wpdb, $company, $row, $params, TZFD_Intake_Mode::MODE_INSTANT );
rt_check( 'create returns success', isset( $res['status'] ) && 'success' === $res['status'], 'status=' . ( $res['status'] ?? 'none' ) );
rt_check( 'create adds exactly one row', $count() === $before + 1, 'before=' . $before . ' after=' . $count() );
$new_id = (int) ( $res['id'] ?? 0 );
$stored = $wpdb->get_row( $wpdb->prepare( "SELECT company_id, posted_data FROM $table WHERE id = %d", $new_id ) );
rt_check( 'stored row carries the company id', $stored && (int) $stored->company_id === $company );
rt_check( 'stored payload includes the item number', $stored && false !== strpos( $stored->posted_data, 'itemnumber' ) );

// 2. A write for a company that does not exist must fail and leave no row behind.
$before2 = $count();
$bad = TZFD_Booking_Create_Transaction::create( $wpdb, 0, $row, $params, TZFD_Intake_Mode::MODE_INSTANT );
rt_check( 'create with invalid company fails closed', isset( $bad['status'] ) && 'failed' === $bad['status'] );
rt_check( 'failed create leaves no orphan row', $count() === $before2 );

// 3. Privacy export finds the test booking by its email.
$export = TZFD_Privacy_Exporter::export( $email, 1 );
$items  = isset( $export['data'] ) ? $export['data'] : array();
rt_check( 'export returns the booking', ! empty( $items ) && true === ( $export['done'] ?? false ), 'items=' . count( $items ) );

// 4. Invalid export input matches nothing.
$none = TZFD_Privacy_Exporter::export( '', 1 );
rt_check( 'export with empty email returns no data', empty( $none['data'] ?? array() ) );

// 5. Erase deletes the booking records for that address and reports it.
$erase = TZFD_Privacy_Exporter::erase( $email, 1 );
rt_check( 'erase reports the booking as removed', true === ( $erase['items_removed'] ?? false ) && true === ( $erase['done'] ?? false ), json_encode( $erase['messages'] ?? array() ) );
$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE LOWER(notifyto) = %s", $email ) );
rt_check( 'no booking rows remain for the erased address', 0 === $left, 'remaining=' . $left );
rt_check( 'erase leaves the other bookings for the company alone', $count() >= $before, 'before=' . $before . ' now=' . $count() );

// 6. Erase with empty input removes nothing.
$before3 = $count();
TZFD_Privacy_Exporter::erase( '', 1 );
rt_check( 'erase with empty email removes nothing', $count() === $before3 );

require_once $tz_inc . 'class-tzfd-signed-request.php';
// 7. Missed-call request signatures (HMAC-SHA256 over timestamp.body, 300 s window, replay refused).
if ( class_exists( 'TZFD_Signed_Request' ) ) {
    $secret = TZFD_Signed_Request::new_secret();
    $body   = '{"event":"missed","from":"+61400000000"}';
    $now    = time();
    $ts     = (string) $now;
    $sig    = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret );
    rt_check( 'generated secret is 64 characters', 64 === strlen( $secret ) );
    rt_check( 'valid signature is accepted', TZFD_Signed_Request::signature_valid( $secret, $ts, $sig, $body, $now ) );
    rt_check( 'tampered body is refused', ! TZFD_Signed_Request::signature_valid( $secret, $ts, $sig, $body . ' ', $now ) );
    rt_check( 'stale timestamp is refused', ! TZFD_Signed_Request::signature_valid( $secret, (string) ( $now - 1000 ), 'sha256=' . hash_hmac( 'sha256', ( $now - 1000 ) . '.' . $body, $secret ), $body, $now ) );
    rt_check( 'short secret fails closed', ! TZFD_Signed_Request::signature_valid( '0123', $ts, $sig, $body, $now ) );
    rt_check( 'missing sha256 prefix is refused', ! TZFD_Signed_Request::signature_valid( $secret, $ts, substr( $sig, 7 ), $body, $now ) );
    $replay_sig = 'sha256=' . hash_hmac( 'sha256', 'rt-replay-' . $ts, $secret );
    rt_check( 'first use of a signature is claimed', TZFD_Signed_Request::claim_signature( $replay_sig ) );
    rt_check( 'repeated signature is refused', ! TZFD_Signed_Request::claim_signature( $replay_sig ) );
    $claim_key = TZFD_Signed_Request::claim_key( $replay_sig );
    rt_check( 'claim stores a hash key, not the raw signature', false === strpos( $claim_key, $replay_sig ) && 0 === strpos( $claim_key, 'tz_fd_sig_' ) );
    $expired_sig = 'sha256=' . hash_hmac( 'sha256', 'rt-expired-' . $ts, $secret );
    rt_check( 'signature claimed at t', TZFD_Signed_Request::claim_signature( $expired_sig, $now ) );
    rt_check( 'claim still held inside REPLAY_TTL', ! TZFD_Signed_Request::claim_signature( $expired_sig, $now + TZFD_Signed_Request::REPLAY_TTL - 1 ) );
    rt_check( 'expired claim can be reclaimed once', TZFD_Signed_Request::claim_signature( $expired_sig, $now + TZFD_Signed_Request::REPLAY_TTL ) );
    rt_check( 'reclaimed claim is held again', ! TZFD_Signed_Request::claim_signature( $expired_sig, $now + TZFD_Signed_Request::REPLAY_TTL + 1 ) );
    delete_option( $claim_key );
    delete_option( TZFD_Signed_Request::claim_key( $expired_sig ) );

    // 8. Refused requests never claim a slot (issue #52). Drives the missed-call permission callback directly.
    $claim_rows = function () use ( $wpdb ) {
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '" . $wpdb->esc_like( TZFD_Signed_Request::CLAIM_PREFIX ) . "%'" );
    };
    if ( class_exists( 'TZFD_MissedCall' ) ) {
        $addon = ( new ReflectionClass( 'TZFD_MissedCall' ) )->newInstanceWithoutConstructor();
        $refuse = function ( $ts, $sig ) use ( $addon, $body ) {
            $req = new WP_REST_Request( 'POST', '/titan-zero-front-desk/v1/missed-call' );
            $req->set_header( 'content-type', 'application/json' );
            $req->set_body( $body );
            $req->set_body_params( json_decode( $body, true ) );
            if ( null !== $ts ) { $req->set_header( 'x-tzfd-timestamp', $ts ); }
            if ( null !== $sig ) { $req->set_header( 'x-tzfd-signature', $sig ); }
            return $addon->rest_permission( $req );
        };
        $wrong_sig = 'sha256=' . str_repeat( '0', 64 );
        $stale_ts  = (string) ( $now - 1000 );
        $cases = array(
            'wrong signature is refused' => array( $ts, $wrong_sig ),
            'missing signature is refused' => array( $ts, null ),
            'malformed timestamp is refused' => array( 'not-a-time', $wrong_sig ),
            'stale timestamp is refused' => array( $stale_ts, 'sha256=' . hash_hmac( 'sha256', $stale_ts . '.' . $body, $secret ) ),
        );
        foreach ( $cases as $label => $case ) {
            $before_rows = $claim_rows();
            $result      = $refuse( $case[0], $case[1] );
            rt_check( $label, is_wp_error( $result ) && 401 === ( $result->get_error_data()['status'] ?? 0 ), is_wp_error( $result ) ? $result->get_error_code() : 'not an error' );
            rt_check( $label . ' without claiming', $claim_rows() === $before_rows, 'rows before=' . $before_rows . ' after=' . $claim_rows() );
        }

        // 9. The claim store stays bounded: an expired claim is purged. The purge runs on about 1 call in 20, so retry.
        $purge_sig = 'sha256=' . hash_hmac( 'sha256', 'rt-purge-' . $ts, $secret );
        TZFD_Signed_Request::claim_signature( $purge_sig, $now - TZFD_Signed_Request::REPLAY_TTL - 5 );
        $purged = 0;
        for ( $i = 0; $i < 500 && 0 === $purged; $i++ ) { $purged = TZFD_Signed_Request::purge_expired( $now ); }
        $still_there = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", TZFD_Signed_Request::claim_key( $purge_sig ) ) );
        rt_check( 'expired claim is purged from the store', 0 === $still_there && $purged >= 1, 'purged=' . $purged );
    } else {
        rt_check( 'missed-call addon class is loaded for the refusal checks', false, 'TZFD_MissedCall not found' );
    }
} else {
    rt_check( 'signed-request class is loaded', false, 'TZFD_Signed_Request not found' );
}

// Issue #21: FAQ retrieval checks against the real options, transients and filters. Runs before the summary.
require __DIR__ . '/faq-retrieval-wp-tests.php';
// 8. Signed call-events path for missed-call callback tasks (issue #30): credential gate, tenant denial
// before any write, duplicate and replay handling, anonymous caller, and no caller data in the response.
if ( class_exists( 'TZFD_Telephony_Adapter' ) && class_exists( 'TZFD_Call_Lifecycle' ) && class_exists( 'TZFD_Missed_Call_Tasks' ) ) {
    if ( ! TZFD_Contact_Actions::installed() ) { TZFD_Contact_Actions::install(); }
    if ( TZFD_Call_Lifecycle::SCHEMA_VERSION !== get_option( TZFD_Call_Lifecycle::VERSION_OPTION, '' ) ) { TZFD_Call_Lifecycle::run_install(); }
    $tc_tag     = 'rt' . substr( md5( uniqid( '', true ) ), 0, 8 );
    $tc_secret  = str_repeat( 'q', 40 );
    $tc_map     = array(
        'rt-tel-ok'    => array( 'enabled' => true, 'company_id' => $company, 'provider' => 'rt_phone', 'secret' => $tc_secret ),
        'rt-tel-other' => array( 'enabled' => true, 'company_id' => $company + 1000, 'provider' => 'rt_phone', 'secret' => $tc_secret ),
    );
    $tc_filter  = function ( $creds, $key_id ) use ( $tc_map ) { return isset( $tc_map[ $key_id ] ) ? $tc_map[ $key_id ] : $creds; };
    add_filter( 'tzfd_telephony_provider_credentials_v1', $tc_filter, 10, 2 );
    $tc_send = function ( $key_id, $secret, array $payload, $ts = null ) {
        $ts  = null === $ts ? (string) time() : (string) $ts;
        $raw = wp_json_encode( $payload );
        $req = new WP_REST_Request( 'POST', '/titan-zero-front-desk/v1/call-events' );
        $req->set_header( 'content-type', 'application/json' );
        $req->set_header( 'x-tzfd-key-id', $key_id );
        $req->set_header( 'x-tzfd-timestamp', $ts );
        $req->set_header( 'x-tzfd-signature', hash_hmac( 'sha256', $ts . '.' . $raw, $secret ) );
        $req->set_body( $raw );
        return TZFD_Telephony_Adapter::receive( $req );
    };
    $tc_status = function ( $r ) { return is_wp_error( $r ) ? (int) ( $r->get_error_data()['status'] ?? 0 ) : $r->get_status(); };
    $tc_tasks  = function ( $call_id ) use ( $wpdb, $company ) {
        $key = TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_id );
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . TZFD_Contact_Actions::actions_table() . ' WHERE company_id = %d AND dedupe_key = %s', $company, $key ) );
    };
    $tc_calls  = function ( $company_id, $call_id ) use ( $wpdb ) {
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . TZFD_Call_Lifecycle::tables()['calls'] . ' WHERE company_id = %d AND external_call_hash = %s', $company_id, hash( 'sha256', $call_id ) ) );
    };

    $call_1 = 'CA-' . $tc_tag . '-1';
    $ev_1   = 'EV-' . $tc_tag . '-1';
    $ts_1   = (string) time();
    $r1 = $tc_send( 'rt-tel-ok', $tc_secret, array( 'call_id' => $call_1, 'event_id' => $ev_1, 'event' => 'missed', 'from' => '+61 400 111 222' ), $ts_1 );
    rt_check( 'signed missed call is accepted', 200 === $tc_status( $r1 ), 'status=' . $tc_status( $r1 ) );
    rt_check( 'missed call creates one callback task', 1 === $tc_tasks( $call_1 ) );
    $task_row = $wpdb->get_row( $wpdb->prepare( 'SELECT phone, state, match_status FROM ' . TZFD_Contact_Actions::actions_table() . ' WHERE company_id = %d AND dedupe_key = %s', $company, TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_1 ) ), ARRAY_A );
    rt_check( 'task stores the E.164 number and an open state', is_array( $task_row ) && '+61400111222' === $task_row['phone'] && 'open' === $task_row['state'] && 'unmatched' === $task_row['match_status'], json_encode( $task_row ) );
    $body_1 = is_wp_error( $r1 ) ? '' : wp_json_encode( $r1->get_data() );
    rt_check( 'response does not expose the caller number', '' !== $body_1 && false === strpos( $body_1, '400111222' ) && false === strpos( $body_1, '+61' ) );

    $r2 = $tc_send( 'rt-tel-ok', $tc_secret, array( 'call_id' => $call_1, 'event_id' => $ev_1, 'event' => 'missed', 'from' => '+61 400 111 222' ) );
    rt_check( 'redelivery with a new signature is accepted', 200 === $tc_status( $r2 ) );
    rt_check( 'redelivered event does not create a second task', 1 === $tc_tasks( $call_1 ) );
    $logged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . TZFD_Call_Lifecycle::tables()['events'] . ' WHERE company_id = %d AND external_event_hash = %s', $company, hash( 'sha256', $ev_1 ) ) );
    rt_check( 'incoming provider event id is logged exactly once', 1 === $logged, 'rows=' . $logged );

    $r3 = $tc_send( 'rt-tel-ok', $tc_secret, array( 'call_id' => $call_1, 'event_id' => $ev_1, 'event' => 'missed', 'from' => '+61 400 111 222' ), $ts_1 );
    rt_check( 'identical signed request is answered as replayed', 200 === $tc_status( $r3 ) && ! is_wp_error( $r3 ) && true === ( $r3->get_data()['replayed'] ?? false ) );

    $call_2 = 'CA-' . $tc_tag . '-2';
    $r4 = $tc_send( 'rt-tel-ok', $tc_secret, array( 'call_id' => $call_2, 'event_id' => 'EV-' . $tc_tag . '-2', 'event' => 'missed', 'from' => 'anonymous' ) );
    rt_check( 'anonymous caller still gets a task, with no number stored', 200 === $tc_status( $r4 ) && 1 === $tc_tasks( $call_2 ) );
    $state_2 = $wpdb->get_row( $wpdb->prepare( 'SELECT phone, state FROM ' . TZFD_Contact_Actions::actions_table() . ' WHERE company_id = %d AND dedupe_key = %s', $company, TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_2 ) ), ARRAY_A );
    rt_check( 'anonymous caller task is marked no_callback_number', is_array( $state_2 ) && '' === $state_2['phone'] && 'no_callback_number' === $state_2['state'], json_encode( $state_2 ) );

    $call_3 = 'CA-' . $tc_tag . '-3';
    $r5 = $tc_send( 'rt-tel-other', $tc_secret, array( 'call_id' => $call_3, 'event_id' => 'EV-' . $tc_tag . '-3', 'event' => 'missed', 'from' => '+61400999888' ) );
    rt_check( 'credential for another business is denied with 403', 403 === $tc_status( $r5 ), 'status=' . $tc_status( $r5 ) );
    rt_check( 'wrong-tenant denial writes no call row for that business', 0 === $tc_calls( $company + 1000, $call_3 ), 'rows=' . $tc_calls( $company + 1000, $call_3 ) );
    rt_check( 'wrong-tenant denial creates no task on this site', 0 === $tc_tasks( $call_3 ) );

    rt_check( 'unknown provider key id is refused with 401', 401 === $tc_status( $tc_send( 'rt-tel-none', $tc_secret, array( 'call_id' => 'CA-x', 'event_id' => 'EV-x', 'event' => 'missed' ) ) ) );
    rt_check( 'wrong signing secret is refused with 401', 401 === $tc_status( $tc_send( 'rt-tel-ok', str_repeat( 'z', 40 ), array( 'call_id' => 'CA-y', 'event_id' => 'EV-y', 'event' => 'missed' ) ) ) );

    // Contact matching: two owner-verified rows for one number are ambiguous and resolve to no customer;
    // one row is only a candidate (never authenticated).
    $tc_candidates = array();
    $tc_cand_filter = function ( $candidates, $company_id, $e164 ) use ( &$tc_candidates ) { return $tc_candidates; };
    add_filter( 'tzfd_missed_call_contact_candidates_v1', $tc_cand_filter, 10, 3 );
    $tc_candidates = array(
        array( 'company_id' => $company, 'customer_id' => 11, 'phone' => '+61400555666' ),
        array( 'company_id' => $company, 'customer_id' => 12, 'phone' => '0400 555 666' ),
    );
    $call_4 = 'CA-' . $tc_tag . '-4';
    $r6 = $tc_send( 'rt-tel-ok', $tc_secret, array( 'call_id' => $call_4, 'event_id' => 'EV-' . $tc_tag . '-4', 'event' => 'missed', 'from' => '+61400555666' ) );
    rt_check( 'ambiguous caller match still creates a task', 200 === $tc_status( $r6 ) && 1 === $tc_tasks( $call_4 ) );
    $amb_row = $wpdb->get_row( $wpdb->prepare( 'SELECT match_status, customer_id FROM ' . TZFD_Contact_Actions::actions_table() . ' WHERE company_id = %d AND dedupe_key = %s', $company, TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_4 ) ), ARRAY_A );
    rt_check( 'two matching contacts are ambiguous and link no customer', is_array( $amb_row ) && 'ambiguous' === $amb_row['match_status'] && 0 === (int) $amb_row['customer_id'], json_encode( $amb_row ) );

    $tc_candidates = array( array( 'company_id' => $company, 'customer_id' => 11, 'phone' => '+61400555666' ) );
    $call_5 = 'CA-' . $tc_tag . '-5';
    $r7 = $tc_send( 'rt-tel-ok', $tc_secret, array( 'call_id' => $call_5, 'event_id' => 'EV-' . $tc_tag . '-5', 'event' => 'missed', 'from' => '+61400555666' ) );
    $cand_row = $wpdb->get_row( $wpdb->prepare( 'SELECT match_status, customer_id FROM ' . TZFD_Contact_Actions::actions_table() . ' WHERE company_id = %d AND dedupe_key = %s', $company, TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_5 ) ), ARRAY_A );
    rt_check( 'one matching contact is a candidate only', 200 === $tc_status( $r7 ) && is_array( $cand_row ) && 'candidate' === $cand_row['match_status'] && 11 === (int) $cand_row['customer_id'], json_encode( $cand_row ) );
    remove_filter( 'tzfd_missed_call_contact_candidates_v1', $tc_cand_filter, 10 );

    remove_filter( 'tzfd_telephony_provider_credentials_v1', $tc_filter, 10 );
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TZFD_Contact_Actions::actions_table() . ' WHERE company_id = %d AND dedupe_key IN (%s, %s, %s, %s, %s)', $company,
        TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_1 ), TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_2 ),
        TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_4 ), TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_5 ),
        TZFD_Contact_Rules::dedupe_key( $company, 'missed_call', 'rt_phone|' . $call_3 ) ) );
} else {
    rt_check( 'telephony adapter and missed-call tasks are loaded', false, 'classes missing' );
}

echo "\nSummary: " . $GLOBALS["rt_pass"] . " passed, " . $GLOBALS["rt_fail"] . " failed\n";
