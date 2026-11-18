<?php
/**
 * The default booking form template (TZFD_BOOK_DEFAULT_form_structure): it must ask for a customer name (required)
 * and a phone number (optional), and the public customer checks must accept a name and refuse an empty one.
 * No WordPress is needed: the template is read from the plugin file as text and decoded here.
 */
function tzfd_test_default_booking_form() {
	$plugin_file = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/titan-zero-front-desk.php';
	$source      = (string) file_get_contents( $plugin_file );
	$found       = preg_match( "/define\\('TZFD_BOOK_DEFAULT_form_structure', '(.*?)'\\);/s", $source, $m );
	tzfd_assert( 1 === $found, 'default form template constant is present in the plugin file' );
	if ( 1 !== $found ) {
		return;
	}

	$form = json_decode( $m[1] );
	tzfd_assert( is_array( $form ) && isset( $form[0] ) && is_array( $form[0] ), 'default form template decodes to a field list' );
	if ( ! is_array( $form ) || ! isset( $form[0] ) ) {
		return;
	}
	$fields  = $form[0];
	$by_name = array();
	foreach ( $fields as $field ) {
		$by_name[ $field->name ] = $field;
	}

	tzfd_assert( isset( $by_name['name'] ), 'default form has a field named name' );
	if ( isset( $by_name['name'] ) ) {
		tzfd_assert_same( 'ftext', $by_name['name']->ftype, 'name field is a single line text field' );
		tzfd_assert_same( 'Name', $by_name['name']->title, 'name field is titled Name' );
		tzfd_assert( true === $by_name['name']->required, 'name field is required' );
	}

	tzfd_assert( isset( $by_name['phone'] ), 'default form has a field named phone' );
	if ( isset( $by_name['phone'] ) ) {
		tzfd_assert_same( 'ftext', $by_name['phone']->ftype, 'phone field is a single line text field' );
		tzfd_assert( false === $by_name['phone']->required, 'phone field is optional' );
	}

	tzfd_assert( isset( $by_name['email'] ) && 'femail' === $by_name['email']->ftype, 'default form keeps the email field' );

	$indexes = array_map( static function ( $f ) { return (int) $f->index; }, $fields );
	tzfd_assert_same( range( 0, count( $fields ) - 1 ), $indexes, 'default form field indexes run in order from zero' );

	// The server-side customer checks (used for public bookings) must enforce the same rules.
	require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-customer-field-check.php';
	$as_check = static function ( $field, $value ) {
		return array(
			'ftype'    => $field->ftype,
			'name'     => $field->name,
			'title'    => $field->title,
			'required' => TZFD_Customer_Field_Check::is_required( $field ),
			'value'    => $value,
		);
	};
	if ( isset( $by_name['name'], $by_name['email'], $by_name['phone'] ) ) {
		$name_f  = $by_name['name'];
		$email_f = $by_name['email'];
		$phone_f = $by_name['phone'];

		tzfd_assert_same(
			'Please enter Name.',
			TZFD_Customer_Field_Check::first_error( array( $as_check( $name_f, '' ), $as_check( $email_f, '' ), $as_check( $phone_f, '' ) ) ),
			'public check refuses a booking with no name'
		);
		tzfd_assert_same(
			'',
			TZFD_Customer_Field_Check::first_error( array( $as_check( $name_f, 'Ada Lovelace' ), $as_check( $email_f, 'ada@example.com' ), $as_check( $phone_f, '' ) ) ),
			'public check accepts a name and email with no phone'
		);
		tzfd_assert_same(
			'',
			TZFD_Customer_Field_Check::first_error( array( $as_check( $name_f, 'Ada Lovelace' ), $as_check( $email_f, 'ada@example.com' ), $as_check( $phone_f, '0412 345 678' ) ) ),
			'public check accepts a name, email and phone'
		);
		tzfd_assert_same(
			'Please enter a valid name in Name.',
			TZFD_Customer_Field_Check::first_error( array( $as_check( $name_f, '12345' ), $as_check( $email_f, 'ada@example.com' ) ) ),
			'public check refuses a name with no letters'
		);
	}
}
