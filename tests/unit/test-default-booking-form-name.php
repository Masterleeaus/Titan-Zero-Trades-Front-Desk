<?php
/**
 * Issue #299: the default booking form must ask for a customer name, and the server-side customer check must refuse a
 * public booking that has no name. Reads the real default template constant from the plugin file, so the test cannot
 * drift from the shipped template.
 */
function tzfd_test_default_booking_form_name() {
	$plugin_file = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/titan-zero-front-desk.php';
	$src         = is_readable( $plugin_file ) ? file_get_contents( $plugin_file ) : '';
	$matched     = preg_match( "/define\\('TZFD_BOOK_DEFAULT_form_structure',\\s*'(.*?)'\\);/s", $src, $m );
	tzfd_assert( 1 === $matched, 'default template constant found in the plugin file' );
	if ( 1 !== $matched ) { return; }

	$template = json_decode( $m[1] );
	tzfd_assert( is_array( $template ) && isset( $template[0] ) && is_array( $template[0] ), 'default template decodes to a field list' );
	if ( ! is_array( $template ) || ! isset( $template[0] ) ) { return; }

	$items = array();
	foreach ( $template[0] as $item ) {
		if ( isset( $item->name ) ) { $items[ $item->name ] = $item; }
	}

	$class_file = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-customer-field-check.php';
	if ( ! class_exists( 'TZFD_Customer_Field_Check' ) ) { require_once $class_file; }

	// Build the field list the same way the public submission does (fapp skipped, value as posted).
	$build = function ( array $posted ) use ( $template ) {
		$fields = array();
		foreach ( $template[0] as $item ) {
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

	tzfd_assert( isset( $items['name'] ) && 'ftext' === $items['name']->ftype && true === $items['name']->required && 'Name' === $items['name']->title, 'default template: required Name text field' );
	tzfd_assert( isset( $items['phone'] ) && 'ftext' === $items['phone']->ftype && false === $items['phone']->required && 'Phone' === $items['phone']->title, 'default template: optional Phone text field' );
	tzfd_assert( isset( $items['email'] ) && 'femail' === $items['email']->ftype && true === $items['email']->required, 'default template: required email field kept' );

	tzfd_assert_same( 'Please enter Name.', TZFD_Customer_Field_Check::first_error( $build( array( 'email' => 'visitor@example.test' ) ) ), 'public booking with only an email is refused for the missing name' );
	tzfd_assert_same( '', TZFD_Customer_Field_Check::first_error( $build( array( 'name' => 'Ada Visitor', 'email' => 'visitor@example.test' ) ) ), 'public booking with name and email is accepted' );
	tzfd_assert_same( '', TZFD_Customer_Field_Check::first_error( $build( array( 'name' => 'Ada Visitor', 'phone' => '', 'email' => 'visitor@example.test' ) ) ), 'empty optional phone does not block a booking' );
	tzfd_assert( '' !== TZFD_Customer_Field_Check::first_error( $build( array( 'name' => '   ', 'email' => 'visitor@example.test' ) ) ), 'whitespace-only name is refused' );
	tzfd_assert( '' !== TZFD_Customer_Field_Check::first_error( $build( array( 'name' => '<b>x</b>', 'email' => 'visitor@example.test' ) ) ), 'markup in the name is refused' );
	tzfd_assert( '' !== TZFD_Customer_Field_Check::first_error( $build( array( 'name' => 'Ada Visitor', 'email' => 'not-an-email' ) ) ), 'invalid email is refused' );
}
