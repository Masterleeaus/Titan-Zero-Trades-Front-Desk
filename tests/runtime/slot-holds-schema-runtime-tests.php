<?php
/**
 * Real-WordPress checks for the slot-holds table installation (issue #186).
 *
 * dbDelta used to print "Invalid default value for 'id'" on every run after the first, because the CREATE statement had
 * a DEFAULT '' that dbDelta applied to the AUTO_INCREMENT id. These checks use a scratch table prefix so the live
 * wp_tz_slot_holds table is not touched: create the schema, run it again (the path that failed), and read the errors.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/slot-holds-schema-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_sh'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_sh_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_sh'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-slot-holds.php' ) ) {
	tzfd_sh_ok( 'slot-holds: class file readable', false, 'class-tzfd-slot-holds.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-slot-holds.php';

global $wpdb;
$sh_prefix = 'tzfd186_';
$sh_saved  = $wpdb->prefix;
$sh_table  = $sh_prefix . TZFD_Slot_Holds::TABLE;
$wpdb->prefix = $sh_prefix;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $sh_table );
$wpdb->last_error = '';

// Record every statement the install runs. dbDelta's failing ALTER is visible here even though a later SHOW query
// clears $wpdb->last_error.
$sh_log = array();
$sh_rec = function ( $query ) use ( &$sh_log ) {
	$sh_log[] = (string) $query;
	return $query;
};
add_filter( 'query', $sh_rec );

// First install (fresh table).
$sh_first = TZFD_Slot_Holds::create_schema();
$sh_first_log = $sh_log;
tzfd_sh_ok( 'slot-holds: fresh install creates a ready table', true === $sh_first );
tzfd_sh_ok( 'slot-holds: fresh install issues no ALTER', 0 === count( array_filter( $sh_first_log, function ( $q ) { return 0 === stripos( ltrim( $q ), 'ALTER TABLE' ); } ) ) );

// Second run: dbDelta compares against the existing table (the path that failed).
$sh_log    = array();
$sh_second = TZFD_Slot_Holds::create_schema();
$sh_alters = array_values( array_filter( $sh_log, function ( $q ) { return 0 === stripos( ltrim( $q ), 'ALTER TABLE' ); } ) );
remove_filter( 'query', $sh_rec );
tzfd_sh_ok( 'slot-holds: second run keeps the table ready', true === $sh_second );
tzfd_sh_ok( 'slot-holds: second run issues no ALTER (no SET DEFAULT on id)', 0 === count( $sh_alters ), $sh_alters ? substr( $sh_alters[0], 0, 160 ) : '' );

// The id column keeps its auto-increment and no default was added to it.
$sh_col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $sh_table, 'id' ), ARRAY_A );
tzfd_sh_ok( 'slot-holds: id stays AUTO_INCREMENT with no default', is_array( $sh_col ) && false !== stripos( (string) $sh_col['Extra'], 'auto_increment' ) && null === $sh_col['Default'], is_array( $sh_col ) ? 'Extra=' . $sh_col['Extra'] . ' Default=' . var_export( $sh_col['Default'], true ) : 'no id column' );

$wpdb->query( 'DROP TABLE IF EXISTS ' . $sh_table );
$wpdb->prefix = $sh_saved;

echo "slot-holds: {$GLOBALS['tzfd_sh']['PASS']} passed, {$GLOBALS['tzfd_sh']['FAIL']} failed\n";
exit( $GLOBALS['tzfd_sh']['FAIL'] > 0 ? 1 : 0 );
