<?php
/**
 * Real-WordPress checks for the confirmation shown after a public booking is saved (issue #184).
 *
 * A calendar with no return page of its own used to send the visitor to the home page with no confirmation. The
 * confirmation now shows the booking reference and the booked time, read back from the stored row. These checks
 * insert a row, render the confirmation through the plugin's own method, and capture the page it produces.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/booking-confirmation-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_bc'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_bc_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_bc'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

global $wpdb, $tz_plugin;

if ( ! is_object( $tz_plugin ) || ! method_exists( $tz_plugin, 'render_booking_confirmation' ) ) {
	tzfd_bc_ok( 'booking-confirmation: plugin object loaded', false, 'render_booking_confirmation not available on $tz_plugin' );
	exit( 1 );
}

$bc_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$bc_date  = '2026-11-20';
$bc_slot  = '10:30/11:30';
$bc_posted = serialize( array( 'apps' => array( array( 'service' => 'Service 1', 'date' => $bc_date, 'slot' => $bc_slot ) ) ) );
$wpdb->insert(
	$bc_table,
	array(
		'formid'    => 1,
		'time'      => '2026-11-17 21:22:56',
		'ipaddr'    => '127.0.0.1',
		'notifyto'  => '',
		'data'      => '',
		'posted_data' => $bc_posted,
		'whoadded'  => 'runtime-test-184',
		'company_id' => 1,
	)
);
$bc_id = (int) $wpdb->insert_id;
tzfd_bc_ok( 'booking-confirmation: test booking row stored', $bc_id > 0 );

// Capture the page the confirmation produces instead of letting wp_die() end the process.
$bc_captured = null;
$bc_handler  = function () use ( &$bc_captured ) {
	return function ( $message, $title = '', $args = array() ) use ( &$bc_captured ) {
		$bc_captured = array( 'message' => (string) $message, 'title' => (string) $title );
		throw new RuntimeException( 'wp_die captured' );
	};
};
add_filter( 'wp_die_handler', $bc_handler );
try {
	$bc_method = new ReflectionMethod( $tz_plugin, 'render_booking_confirmation' );
	$bc_method->setAccessible( true );
	$bc_method->invoke( $tz_plugin, $bc_id );
} catch ( RuntimeException $e ) {
	// Expected: the captured wp_die() call.
} catch ( Throwable $e ) {
	tzfd_bc_ok( 'booking-confirmation: renders without error', false, get_class( $e ) . ': ' . $e->getMessage() );
}
remove_filter( 'wp_die_handler', $bc_handler );

$bc_msg = is_array( $bc_captured ) ? $bc_captured['message'] : '';
tzfd_bc_ok( 'booking-confirmation: confirmation page is shown', is_array( $bc_captured ) && false !== strpos( $bc_msg, 'Your booking is confirmed.' ) );
tzfd_bc_ok( 'booking-confirmation: shows the booking reference', false !== strpos( $bc_msg, 'Booking reference: ' . $bc_id ) );
tzfd_bc_ok( 'booking-confirmation: shows the booked date and slot start', false !== strpos( $bc_msg, date_i18n( get_option( 'date_format' ), strtotime( $bc_date ) ) . ' 10:30' ) );
tzfd_bc_ok( 'booking-confirmation: shows the slot, not the submission time', false === strpos( $bc_msg, '21:22' ) );

// A reference that does not exist still shows the confirmation text, without a time line.
$bc_captured = null;
add_filter( 'wp_die_handler', $bc_handler );
try {
	$bc_method->invoke( $tz_plugin, 0 );
} catch ( RuntimeException $e ) {
	// Expected.
}
remove_filter( 'wp_die_handler', $bc_handler );
tzfd_bc_ok( 'booking-confirmation: missing row still shows the confirmation without a time line', is_array( $bc_captured ) && false !== strpos( $bc_captured['message'], 'Your booking is confirmed.' ) && false === strpos( $bc_captured['message'], 'Date and time:' ) );

// A damaged stored value must not break the page: the confirmation still shows, without the slot line.
$wpdb->update( $bc_table, array( 'posted_data' => 'a:1:{s:4:"apps";' ), array( 'id' => $bc_id ), array( '%s' ), array( '%d' ) );
$bc_captured = null;
add_filter( 'wp_die_handler', $bc_handler );
try {
	$bc_method->invoke( $tz_plugin, $bc_id );
} catch ( RuntimeException $e ) {
	// Expected.
}
remove_filter( 'wp_die_handler', $bc_handler );
tzfd_bc_ok( 'booking-confirmation: damaged stored data still shows the confirmation', is_array( $bc_captured ) && false !== strpos( $bc_captured['message'], 'Booking reference: ' . $bc_id ) && false === strpos( $bc_captured['message'], 'Date and time:' ) );

$wpdb->delete( $bc_table, array( 'id' => $bc_id ), array( '%d' ) );

echo "booking-confirmation: {$GLOBALS['tzfd_bc']['PASS']} passed, {$GLOBALS['tzfd_bc']['FAIL']} failed\n";
exit( $GLOBALS['tzfd_bc']['FAIL'] > 0 ? 1 : 0 );
