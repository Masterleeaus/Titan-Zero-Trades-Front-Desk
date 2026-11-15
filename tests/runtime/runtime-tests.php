<?php
/**
 * Runtime checks for Front Desk 3.0.128 r21 on the disposable test site.
 * Run: wp --allow-root eval-file runtime-tests.php   (not shipped in the plugin)
 */
$tz_inc = '/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/includes/';
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

// 5. Erase keeps booking records (documented: the admin deletes them in Front Desk).
//    It must say so, and it must not remove them silently.
$erase = TZFD_Privacy_Exporter::erase( $email, 1 );
rt_check( 'erase keeps booking records and says so', true === ( $erase['items_retained'] ?? false ) && false === ( $erase['items_removed'] ?? true ), json_encode( $erase['messages'] ?? array() ) );
$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE notifyto = %s", $email ) );
rt_check( 'booking row is still present after erase (by design)', 1 === $left, 'remaining=' . $left );
// Cleanup of the test row, done directly so the test leaves the site as it found it.
$wpdb->delete( $table, array( 'notifyto' => $email ) );

// 6. Erase with empty input removes nothing.
$before3 = $count();
TZFD_Privacy_Exporter::erase( '', 1 );
rt_check( 'erase with empty email removes nothing', $count() === $before3 );

echo "\nSummary: " . $GLOBALS["rt_pass"] . " passed, " . $GLOBALS["rt_fail"] . " failed\n";
