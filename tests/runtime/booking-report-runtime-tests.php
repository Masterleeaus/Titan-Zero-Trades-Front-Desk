<?php
/**
 * Real-WordPress checks for the booking report (issue #12): the report reads stored appointments for the company,
 * counts cancelled and active separately, leaves out-of-range days, and is shown only to an administrator.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/booking-report-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_br'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_br_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_br'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
$tzfd_need = array( 'class-tzfd-booking-view.php', 'class-tzfd-booking-reports.php', 'class-tzfd-booking-report-summary.php', 'class-tzfd-booking-report-admin.php' );
foreach ( $tzfd_need as $tzfd_file ) {
	if ( ! is_readable( $tzfd_inc . $tzfd_file ) ) {
		tzfd_br_ok( 'booking-report: ' . $tzfd_file . ' readable', false, 'not found in the active plugin' );
		exit( 1 );
	}
}
require_once $tzfd_inc . 'class-tzfd-booking-report-admin.php';

wp_set_current_user( 1 );
global $wpdb;

$br_forms    = $wpdb->prefix . 'tz_forms';
$br_bookings = $wpdb->prefix . 'tz_bookings';
$br_company  = 1; // TZFD_Local_Business::id() on a single-business site.

// A form that belongs to the company, so the booking reads join to it.
$wpdb->insert( $br_forms, array( 'company_id' => $br_company, 'form_name' => 'RT booking report form' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$br_form = (int) $wpdb->insert_id;
tzfd_br_ok( 'booking-report: test form created for the company', $br_form > 0 );

// Dates in a range nobody uses, so the counts are exact.
$br_from = '2031-01-01';
$br_to   = '2031-01-03';
$br_store = function ( $company, $apps ) use ( $wpdb, $br_bookings, $br_form ) {
	$posted = serialize( array( 'apps' => $apps, 'name' => 'RT Customer', 'email' => 'rt-report@example.test' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stored in the plugin's own format.
	$wpdb->insert( $br_bookings, array( 'formid' => $br_form, 'time' => '2030-12-20 10:00:00', 'ipaddr' => '127.0.0.1', 'notifyto' => 'rt-report@example.test', 'posted_data' => $posted, 'data' => 'RT', 'whoadded' => '1', 'company_id' => $company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->insert_id;
};
$br_ids = array(
	$br_store( $br_company, array(
		array( 'date' => '2031-01-01', 'slot' => '09:00/10:00', 'service' => 'Boiler service', 'cancelled' => '' ),
		array( 'date' => '2031-01-01', 'slot' => '11:00/12:00', 'service' => 'Drain clear', 'cancelled' => 'Cancelled by customer' ),
	) ),
	$br_store( $br_company, array(
		array( 'date' => '2031-01-02', 'slot' => '13:00/14:00', 'service' => 'Pump check', 'cancelled' => '' ),
	) ),
	// Outside the range: must not be counted.
	$br_store( $br_company, array(
		array( 'date' => '2031-02-10', 'slot' => '09:00/10:00', 'service' => 'Out of range', 'cancelled' => '' ),
	) ),
	// Another company: must not be counted.
	$br_store( $br_company + 1, array(
		array( 'date' => '2031-01-02', 'slot' => '08:00/09:00', 'service' => 'Other company', 'cancelled' => '' ),
	) ),
);
tzfd_br_ok( 'booking-report: sample bookings stored', count( array_filter( $br_ids ) ) === 4 );

// 1. The report reads the stored appointments for this company and range.
$br_read    = TZFD_Booking_View::fetch( $br_from, $br_to, 0 );
$br_summary = TZFD_Booking_Report_Summary::summarise( $br_read['items'], $br_from, $br_to );
tzfd_br_ok( 'booking-report: three appointments in range, company scoped', 3 === $br_summary['totals']['appointments'] && true === $br_read['available'] );
tzfd_br_ok( 'booking-report: one cancelled and two active', 1 === $br_summary['totals']['cancelled'] && 2 === $br_summary['totals']['active'] );
tzfd_br_ok( 'booking-report: per-day counts match the stored dates', 2 === $br_summary['days'][0]['appointments'] && 1 === $br_summary['days'][1]['appointments'] && 0 === $br_summary['days'][2]['appointments'] );

// 2. The admin screen shows the same numbers for the same range; a subscriber sees nothing.
$_GET['from'] = $br_from; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test input only.
$_GET['to']   = $br_to;   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test input only.
ob_start();
TZFD_Booking_Report_Admin::render();
$br_html = ob_get_clean();
unset( $_GET['from'], $_GET['to'] );
tzfd_br_ok( 'booking-report: administrator sees the totals', false !== strpos( $br_html, '3 appointments, 1 cancelled, 2 active.' ) );
tzfd_br_ok( 'booking-report: screen says what is not measured', false !== strpos( $br_html, 'Not measured yet' ) && false !== strpos( $br_html, 'lead source' ) );
tzfd_br_ok( 'booking-report: screen shows no customer name or email', false === strpos( $br_html, 'rt-report@example.test' ) && false === strpos( $br_html, 'RT Customer' ) );

// 3. Permission isolation: a non-administrator sees nothing, whichever roles exist on this site.
// A temporary editor and a temporary subscriber are created here, so the check never depends on existing users.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( 'editor', 'subscriber' ) as $br_role ) {
	$br_login = 'rt_report_' . $br_role . '_' . wp_rand( 100000, 999999 );
	$br_uid   = wp_insert_user( array(
		'user_login' => $br_login,
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => $br_login . '@example.test',
		'role'       => $br_role,
	) );
	if ( ! is_int( $br_uid ) || $br_uid < 1 ) {
		tzfd_br_ok( 'booking-report: temporary ' . $br_role . ' user created', false, 'wp_insert_user failed' );
		continue;
	}
	wp_set_current_user( $br_uid );
	$_GET['from'] = $br_from; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test input only.
	$_GET['to']   = $br_to;   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test input only.
	ob_start();
	TZFD_Booking_Report_Admin::render();
	$br_other_html = ob_get_clean();
	unset( $_GET['from'], $_GET['to'] );
	tzfd_br_ok( 'booking-report: ' . $br_role . ' sees nothing', '' === $br_other_html );
	tzfd_br_ok( 'booking-report: ' . $br_role . ' cannot read the report capability', ! current_user_can( TZFD_Booking_Report_Admin::CAP ) );
	wp_set_current_user( 1 );
	wp_delete_user( $br_uid );
}

// Clean up.
foreach ( $br_ids as $br_id ) {
	if ( $br_id > 0 ) { $wpdb->delete( $br_bookings, array( 'id' => $br_id ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$wpdb->delete( $br_forms, array( 'id' => $br_form ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

echo 'Summary (booking-report-runtime): ' . (int) $GLOBALS['tzfd_br']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_br']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_br']['FAIL'] > 0 ? 1 : 0 );
