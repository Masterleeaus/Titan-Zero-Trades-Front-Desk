<?php
/**
 * Real-WordPress checks for the default booking form (issue #299).
 *
 * The default form used to ask only for an appointment and an email, so bookings had no customer name. The default
 * template now has a required "Name" field and an optional "Phone" field. These checks cover:
 *   1. the default template contains the fields with the right types and required flags;
 *   2. the server-side customer check refuses a booking without a name and accepts name + email;
 *   3. a booking row shaped like the one a submission stores keeps the name, and the bookings list reads it.
 *
 * Scope: checks 1-3 run the same validation and read-back code the public form uses. They do not send an HTTP
 * POST through the form handler, so the full public submission path is not proven by this file.
 *
 * Runs inside a disposable WordPress site, via WP-CLI:
 *   wp eval-file tests/runtime/booking-default-form-name-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_bn'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_bn_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_bn'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

global $wpdb;

// 1. Default template.
$tzfd_bn_form = defined( 'TZFD_BOOK_DEFAULT_form_structure' ) ? json_decode( TZFD_BOOK_DEFAULT_form_structure ) : null;
$tzfd_bn_ok   = is_array( $tzfd_bn_form ) && isset( $tzfd_bn_form[0] ) && is_array( $tzfd_bn_form[0] );
tzfd_bn_ok( 'default-form: template decodes to a field list', $tzfd_bn_ok );
if ( ! $tzfd_bn_ok ) { exit( 1 ); }

$tzfd_bn_items = array();
foreach ( $tzfd_bn_form[0] as $tzfd_bn_item ) {
	if ( isset( $tzfd_bn_item->name ) ) { $tzfd_bn_items[ $tzfd_bn_item->name ] = $tzfd_bn_item; }
}

$tzfd_bn_name = $tzfd_bn_items['name'] ?? null;
tzfd_bn_ok( 'default-form: name field exists as a text field, required', $tzfd_bn_name && 'ftext' === $tzfd_bn_name->ftype && true === $tzfd_bn_name->required && 'Name' === $tzfd_bn_name->title );

$tzfd_bn_phone = $tzfd_bn_items['phone'] ?? null;
tzfd_bn_ok( 'default-form: phone field exists as a text field, optional', $tzfd_bn_phone && 'ftext' === $tzfd_bn_phone->ftype && false === $tzfd_bn_phone->required && 'Phone' === $tzfd_bn_phone->title );

$tzfd_bn_email = $tzfd_bn_items['email'] ?? null;
tzfd_bn_ok( 'default-form: email field kept and required', $tzfd_bn_email && 'femail' === $tzfd_bn_email->ftype && true === $tzfd_bn_email->required );

// 2. Server-side customer checks, built the same way the public submission builds them.
$tzfd_bn_check_file = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-customer-field-check.php';
if ( ! class_exists( 'TZFD_Customer_Field_Check' ) ) { require_once $tzfd_bn_check_file; }

$tzfd_bn_build = function ( array $posted ) use ( $tzfd_bn_form ) {
	$fields = array();
	foreach ( $tzfd_bn_form[0] as $item ) {
		if ( 'fapp' === $item->ftype ) { continue; }
		$fields[] = array(
			'ftype'    => $item->ftype,
			'name'     => (string) $item->name,
			'title'    => (string) $item->title,
			'required' => TZFD_Customer_Field_Check::is_required( $item ),
			'value'    => (string) ( $posted[ $item->name ] ?? '' ),
		);
	}
	return $fields;
};

$tzfd_bn_no_name = TZFD_Customer_Field_Check::first_error( $tzfd_bn_build( array( 'email' => 'visitor@example.test' ) ) );
tzfd_bn_ok( 'public check: booking without a name is refused', 'Please enter Name.' === $tzfd_bn_no_name, 'got: ' . $tzfd_bn_no_name );

$tzfd_bn_ok_pair = TZFD_Customer_Field_Check::first_error( $tzfd_bn_build( array( 'name' => 'Runtime Name', 'email' => 'visitor@example.test' ) ) );
tzfd_bn_ok( 'public check: name and email alone are accepted', '' === $tzfd_bn_ok_pair, 'got: ' . $tzfd_bn_ok_pair );

$tzfd_bn_markup = TZFD_Customer_Field_Check::first_error( $tzfd_bn_build( array( 'name' => '<b>x</b>', 'email' => 'visitor@example.test' ) ) );
tzfd_bn_ok( 'public check: markup in the name is refused', '' !== $tzfd_bn_markup );

// 3. Stored row with the name, read back the way the bookings list reads it.
$tzfd_bn_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$tzfd_bn_date  = '2026-11-20';
$tzfd_bn_data  = array(
	'apps'  => array( array( 'service' => 'Service 1', 'date' => $tzfd_bn_date, 'slot' => '10:30/11:30' ) ),
	'name'  => 'Runtime Name',
	'phone' => '',
	'email' => 'visitor@example.test',
);
$wpdb->insert(
	$tzfd_bn_table,
	array(
		'formid'      => 1,
		'time'        => '2026-11-18 09:00:00',
		'ipaddr'      => '127.0.0.1',
		'notifyto'    => '',
		'data'        => '',
		'posted_data' => serialize( $tzfd_bn_data ),
		'whoadded'    => 'runtime-test-299',
		'company_id'  => 1,
	)
);
$tzfd_bn_id = (int) $wpdb->insert_id;
tzfd_bn_ok( 'stored row: test booking inserted', $tzfd_bn_id > 0 );

if ( $tzfd_bn_id > 0 ) {
	$tzfd_bn_raw     = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id = %d', $tzfd_bn_table, $tzfd_bn_id ) );
	$tzfd_bn_decoded = TZFD_Stored_Data::decode_booking( $tzfd_bn_raw );
	tzfd_bn_ok( 'stored row: name is kept in the stored booking', is_array( $tzfd_bn_decoded ) && 'Runtime Name' === ( $tzfd_bn_decoded['name'] ?? '' ) );

	$tzfd_bn_items_list = is_array( $tzfd_bn_decoded )
		? TZFD_Booking_View::items_from_booking( $tzfd_bn_id, 1, '', 'visitor@example.test', $tzfd_bn_decoded, '2026-11-01', '2026-11-30' )
		: array();
	$tzfd_bn_customer   = $tzfd_bn_items_list[0]['customer'] ?? '';
	tzfd_bn_ok( 'bookings list: customer column shows the name, not the email', 'Runtime Name' === $tzfd_bn_customer, 'got: ' . $tzfd_bn_customer );

	$wpdb->delete( $tzfd_bn_table, array( 'id' => $tzfd_bn_id ), array( '%d' ) );
}

echo 'SUMMARY PASS=' . (int) $GLOBALS['tzfd_bn']['PASS'] . ' FAIL=' . (int) $GLOBALS['tzfd_bn']['FAIL'] . "\n";
