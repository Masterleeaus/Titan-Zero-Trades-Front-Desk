<?php
/**
 * Runtime checks for Front Desk 3.0.128 r21 on the disposable test site.
 * Run: wp --allow-root eval-file runtime-tests.php   (not shipped in the plugin)
 */
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
    delete_transient( 'tz_fd_sig_' . hash( 'sha256', $replay_sig ) );
} else {
    rt_check( 'signed-request class is loaded', false, 'TZFD_Signed_Request not found' );
}

echo "\nSummary: " . $GLOBALS["rt_pass"] . " passed, " . $GLOBALS["rt_fail"] . " failed\n";
