<?php
/**
 * Opt-in uninstall check. DESTRUCTIVE: drops Front Desk tables on the disposable site.
 * Run in its own process, after the other runtime checks:
 *   wp eval-file tests/runtime/uninstall-wipe-check.php --user=1
 * Output format matches wp-runtime-tests.php ("PASS|FAIL|GAP name -- detail").
 */
if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress\n";
	exit( 1 );
}
if ( defined( 'TZ_DELETE_DATA_ON_UNINSTALL' ) ) {
	echo "FAIL uninstall-wipe -- opt-in already defined in this process; run it alone\n";
	exit( 1 );
}

global $wpdb;
require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-tables.php';

$tzfd_owned  = TZFD_Tables::owned();
$tzfd_probe  = $wpdb->prefix . 'tz_bookings';
$tzfd_exists = static function ( $name ) use ( $wpdb ) {
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
};

// Make sure there is data to be dropped, then keep a settings row to see it removed.
$wpdb->insert( $tzfd_probe, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct insert so there is data for the uninstall to drop
	'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => 'wipe@example.test',
	'posted_data' => serialize( array( 'formid' => 1 ) ), 'data' => 'WIPE', 'whoadded' => '1', 'company_id' => 1, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- posted_data must hold the plugin's own PHP-serialized format
) );
update_option( 'tz_fd_wipe_probe', 'set' );
update_option( 'tzfd_ai_connector_secret', 'probe-secret', false );

$tzfd_before_owned = 0;
foreach ( $tzfd_owned as $tzfd_bare ) {
	if ( $tzfd_exists( $wpdb->prefix . $tzfd_bare ) ) { $tzfd_before_owned++; }
}

define( 'WP_UNINSTALL_PLUGIN', 'titan-zero-trades-front-desk/titan-zero-front-desk.php' );
define( 'TZ_DELETE_DATA_ON_UNINSTALL', true );
require WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/uninstall.php';

$tzfd_results = array();
$tzfd_results[] = array( ! $tzfd_exists( $tzfd_probe ), 'PASS', 'opt-in uninstall drops the bookings table' );
$tzfd_still = array();
foreach ( $tzfd_owned as $tzfd_bare ) {
	if ( $tzfd_exists( $wpdb->prefix . $tzfd_bare ) ) { $tzfd_still[] = $tzfd_bare; }
}
$tzfd_results[] = array( empty( $tzfd_still ), 'PASS', 'opt-in uninstall drops every table in TZFD_Tables::owned()' . ( $tzfd_still ? ' (still present: ' . implode( ',', $tzfd_still ) . ')' : '' ) );
// Database row, not get_option(): the object cache would still answer after a direct DELETE.
$tzfd_results[] = array( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s", 'tz_fd_wipe_probe' ) ), 'PASS', 'opt-in uninstall deletes plugin settings' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw options row count, bypasses the object cache
$tzfd_results[] = array( ! $tzfd_exists( $wpdb->prefix . 'tzfd_local_customers' ), 'PASS', 'opt-in uninstall drops tzfd_local_customers (outside TZFD_Tables::owned(), listed in TZFD_Uninstaller)' );
$tzfd_results[] = array( ! $tzfd_exists( $wpdb->prefix . 'tzfd_local_services' ), 'PASS', 'opt-in uninstall drops tzfd_local_services' );
$tzfd_results[] = array( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s", 'tzfd_ai_connector_secret' ) ), 'PASS', 'opt-in uninstall deletes the AI connector secret option' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw options row count, bypasses the object cache

$tzfd_local_failed = 0;
foreach ( $tzfd_results as $tzfd_local_r ) {
	list( $tzfd_ok, $tzfd_label, $tzfd_text ) = $tzfd_local_r;
	if ( 'GAP' === $tzfd_label ) {
		echo ( $tzfd_ok ? 'GAP' : 'PASS' ) . ' uninstall-wipe -- ' . esc_html( $tzfd_text ) . "\n";
	} else {
		if ( ! $tzfd_ok ) { $tzfd_local_failed++; }
		echo ( $tzfd_ok ? 'PASS' : 'FAIL' ) . ' uninstall-wipe -- ' . esc_html( $tzfd_text ) . "\n";
	}
}
echo "Summary (uninstall-wipe-check): owned tables present before wipe=" . (int) $tzfd_before_owned . "\n";
exit( $tzfd_local_failed > 0 ? 1 : 0 );
