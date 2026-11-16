<?php
/**
 * Opt-in uninstall check. DESTRUCTIVE: drops Front Desk tables on the disposable site.
 * Run in its own process, after the other runtime checks:
 *   wp eval-file tests/runtime/uninstall-wipe-check.php --user=1
 * Output format matches wp-runtime-tests.php ("PASS|FAIL|GAP name -- detail").
 */
if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress\n";
	return;
}
if ( defined( 'TZ_DELETE_DATA_ON_UNINSTALL' ) ) {
	echo "FAIL uninstall-wipe -- opt-in already defined in this process; run it alone\n";
	return;
}

global $wpdb;
require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-tables.php';

$tz_owned  = TZFD_Tables::owned();
$tz_probe  = $wpdb->prefix . 'tz_bookings';
$tz_exists = static function ( $name ) use ( $wpdb ) {
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
};

// Make sure there is data to be dropped, then keep a settings row to see it removed.
$wpdb->insert( $tz_probe, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct insert so there is data for the uninstall to drop
	'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => 'wipe@example.test',
	'posted_data' => serialize( array( 'formid' => 1 ) ), 'data' => 'WIPE', 'whoadded' => '1', 'company_id' => 1, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- posted_data must hold the plugin's own PHP-serialized format
) );
update_option( 'tz_fd_wipe_probe', 'set' );

$tz_before_owned = 0;
foreach ( $tz_owned as $tz_bare ) {
	if ( $tz_exists( $wpdb->prefix . $tz_bare ) ) { $tz_before_owned++; }
}

define( 'WP_UNINSTALL_PLUGIN', 'titan-zero-trades-front-desk/titan-zero-front-desk.php' );
define( 'TZ_DELETE_DATA_ON_UNINSTALL', true );
require WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/uninstall.php';

$tz_results = array();
$tz_results[] = array( ! $tz_exists( $tz_probe ), 'PASS', 'opt-in uninstall drops the bookings table' );
$tz_still = array();
foreach ( $tz_owned as $tz_bare ) {
	if ( $tz_exists( $wpdb->prefix . $tz_bare ) ) { $tz_still[] = $tz_bare; }
}
$tz_results[] = array( empty( $tz_still ), 'PASS', 'opt-in uninstall drops every table in TZFD_Tables::owned()' . ( $tz_still ? ' (still present: ' . implode( ',', $tz_still ) . ')' : '' ) );
// Database row, not get_option(): the object cache would still answer after a direct DELETE.
$tz_results[] = array( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s", 'tz_fd_wipe_probe' ) ), 'PASS', 'opt-in uninstall deletes plugin settings' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw options row count, bypasses the object cache
$tz_results[] = array( $tz_exists( $wpdb->prefix . 'tzfd_local_customers' ), 'GAP', 'tzfd_local_customers is retained after an opt-in uninstall because it is outside TZFD_Tables::owned(); confirm whether customer records should be erased on opt-in' );

foreach ( $tz_results as $tz_r ) {
	list( $tz_ok, $tz_label, $tz_text ) = $tz_r;
	if ( 'GAP' === $tz_label ) {
		echo ( $tz_ok ? 'GAP' : 'PASS' ) . ' uninstall-wipe -- ' . esc_html( $tz_text ) . "\n";
	} else {
		echo ( $tz_ok ? 'PASS' : 'FAIL' ) . ' uninstall-wipe -- ' . esc_html( $tz_text ) . "\n";
	}
}
echo "Summary (uninstall-wipe-check): owned tables present before wipe=" . (int) $tz_before_owned . "\n";
