<?php
/**
 * Real-WordPress checks for issue #300: the placeholder service "Service 1" must not ship in the default form,
 * and the one-time upgrade must remove it only from calendars with no bookings.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/placeholder-service-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_ps'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_ps_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_ps'][ $status ]++;
	echo $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
	return (bool) $ok;
}

$tzfd_plugin = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/';
if ( ! is_readable( $tzfd_plugin . 'includes/class-tzfd-placeholder-service-cleanup.php' ) ) {
	tzfd_ps_ok( 'placeholder: cleanup class readable', false, 'class-tzfd-placeholder-service-cleanup.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_plugin . 'includes/class-tzfd-placeholder-service-cleanup.php';

global $wpdb;
$forms    = $wpdb->prefix . TZFD_Tables::FORMS;
$bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;

// 1. The default template a new calendar is created from has no placeholder.
$default = json_decode( TZFD_BOOK_DEFAULT_form_structure );
$default_fapp = null;
if ( is_array( $default ) && isset( $default[0] ) ) {
	foreach ( $default[0] as $field ) {
		if ( is_object( $field ) && isset( $field->ftype ) && 'fapp' === $field->ftype ) { $default_fapp = $field; }
	}
}
tzfd_ps_ok( 'placeholder: default template decodes', is_object( $default_fapp ) );
tzfd_ps_ok( 'placeholder: default template has no "Service 1"', false === strpos( TZFD_BOOK_DEFAULT_form_structure, 'Service 1' ) );
tzfd_ps_ok( 'placeholder: default fapp starts with no services', is_object( $default_fapp ) && is_array( $default_fapp->services ) && 0 === count( $default_fapp->services ) );

// 2. Public front-end bundle and its field source carry no placeholder default.
$bundle = file_get_contents( $tzfd_plugin . 'js/tzfdform-public.js' );
tzfd_ps_ok( 'placeholder: public bundle has no "Service 1"', false === strpos( (string) $bundle, 'Service 1' ) );

// 3. Upgrade: a placeholder-only calendar with no bookings is cleaned. Scratch rows are removed at the end.
$placeholder_structure = json_encode( array( array( (object) array( 'ftype' => 'fapp', 'name' => 'fieldname1', 'services' => array( (object) array( 'name' => 'Service 1', 'price' => 1, 'duration' => 60 ) ) ) ), array() ) );
$real_structure        = json_encode( array( array( (object) array( 'ftype' => 'fapp', 'name' => 'fieldname1', 'services' => array( (object) array( 'name' => 'Cleaning', 'price' => 80, 'duration' => 120 ) ) ) ), array() ) );

$wpdb->insert( $forms, array( 'company_id' => 1, 'form_name' => 'tzfd300-empty', 'form_structure' => $placeholder_structure ) );
$id_empty = (int) $wpdb->insert_id;
$wpdb->insert( $forms, array( 'company_id' => 1, 'form_name' => 'tzfd300-booked', 'form_structure' => $placeholder_structure ) );
$id_booked = (int) $wpdb->insert_id;
$wpdb->insert( $forms, array( 'company_id' => 1, 'form_name' => 'tzfd300-real', 'form_structure' => $real_structure ) );
$id_real = (int) $wpdb->insert_id;
$wpdb->insert( $bookings, array( 'formid' => $id_booked, 'time' => current_time( 'mysql' ), 'data' => '', 'posted_data' => '', 'company_id' => 1 ) );
$booking_id = (int) $wpdb->insert_id;

delete_option( TZFD_Placeholder_Service_Cleanup::OPTION );
$report = TZFD_Placeholder_Service_Cleanup::run();

$read = function ( $id ) use ( $wpdb, $forms ) {
	$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT form_structure FROM %i WHERE id = %d', $forms, $id ) );
	$dec = json_decode( (string) $raw );
	return is_object( $dec[0][0] ?? null ) ? $dec[0][0] : null;
};

$f_empty = $read( $id_empty );
tzfd_ps_ok( 'placeholder: upgrade clears placeholder on calendar with no bookings', is_object( $f_empty ) && is_array( $f_empty->services ) && 0 === count( $f_empty->services ) );
tzfd_ps_ok( 'placeholder: upgrade reports the cleared calendar', is_array( $report ) && in_array( $id_empty, $report['changed'], true ) );

$f_booked = $read( $id_booked );
tzfd_ps_ok( 'placeholder: calendar with a booking keeps the placeholder', is_object( $f_booked ) && 1 === count( $f_booked->services ) );
tzfd_ps_ok( 'placeholder: booked calendar is reported as kept', is_array( $report ) && in_array( $id_booked, $report['kept'], true ) );

$f_real = $read( $id_real );
tzfd_ps_ok( 'placeholder: real service is never removed', is_object( $f_real ) && 'Cleaning' === $f_real->services[0]->name );

tzfd_ps_ok( 'placeholder: upgrade marked done', get_option( TZFD_Placeholder_Service_Cleanup::OPTION ) === TZFD_Placeholder_Service_Cleanup::VERSION );

// Clean up the scratch rows so the disposable site is left as it was.
$wpdb->delete( $bookings, array( 'id' => $booking_id ) );
$wpdb->delete( $forms, array( 'id' => $id_empty ) );
$wpdb->delete( $forms, array( 'id' => $id_booked ) );
$wpdb->delete( $forms, array( 'id' => $id_real ) );
delete_option( TZFD_Placeholder_Service_Cleanup::OPTION );

$s = $GLOBALS['tzfd_ps'];
echo "SUMMARY placeholder-service: {$s['PASS']} passed, {$s['FAIL']} failed\n";
exit( $s['FAIL'] > 0 ? 1 : 0 );
