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

echo "\nSummary: " . $GLOBALS["rt_pass"] . " passed, " . $GLOBALS["rt_fail"] . " failed\n";
