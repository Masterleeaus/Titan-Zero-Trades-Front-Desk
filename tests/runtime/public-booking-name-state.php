<?php
/**
 * Helper for public-booking-name-http-tests.sh (issue #420). Runs inside WordPress via WP-CLI:
 *   TZFD_PN_MODE=setup   TZFD_PN_EMAIL=<test email>  wp eval-file tests/runtime/public-booking-name-state.php --user=1
 *       Prints TZFD_PN_CAPTCHA=on|off first. When the CAPTCHA is required, it stops there and changes nothing, so the
 *       public POST is not sent. The CAPTCHA is never turned off by this file. Otherwise it checks the default form
 *       fields (name required, email required, phone optional), adds one appointment service to calendar 1, publishes
 *       a page with the calendar shortcode, and prints TZFD_PN_PAGE and TZFD_PN_DATE.
 *   TZFD_PN_MODE=lookup  TZFD_PN_EMAIL=<test email>  wp eval-file ...
 *       Prints the stored rows for that test email and the customer value the bookings list reads for the first row.
 *   TZFD_PN_MODE=restore TZFD_PN_EMAIL=<test email>  wp eval-file ...
 *       Deletes the test rows for that email (only addresses under example.test are accepted), puts calendar 1 back as
 *       it was, and removes the test page.
 * Driven by tests/runtime/public-booking-name-http-tests.sh. Not shipped in the plugin. No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-stored-data.php', 'class-tzfd-scheduling-rules.php', 'class-tzfd-customer-field-check.php' ) as $tzfd_file ) {
	if ( is_readable( $tzfd_inc . $tzfd_file ) ) {
		require_once $tzfd_inc . $tzfd_file;
	}
}

global $wpdb;
$mode     = (string) getenv( 'TZFD_PN_MODE' );
$email    = (string) getenv( 'TZFD_PN_EMAIL' );
$forms    = $wpdb->prefix . TZFD_Tables::FORMS;
$bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$backup   = 'tzfd_pn_test_backup';
$form_id  = 1;
$date     = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
$slot     = '13:00/14:00';

// Only a test address under example.test may be read or deleted by this helper.
$tzfd_pn_test_email = (bool) preg_match( '/^[a-z0-9._+-]+@example\.test$/', $email );

if ( 'setup' === $mode ) {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT form_structure, cv_enable_captcha FROM %i WHERE id=%d', $forms, $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup read
	if ( ! is_array( $row ) ) {
		echo "FAIL public-booking setup -- calendar 1 does not exist\n";
		exit( 1 );
	}

	// Same rule the public handler applies: the CAPTCHA is required unless the form turns it off, and only when GD exists.
	$captcha_on = 'false' !== (string) $row['cv_enable_captcha'] && function_exists( 'imagecreatetruecolor' );
	echo 'TZFD_PN_CAPTCHA=' . ( $captcha_on ? 'on' : 'off' ) . "\n";
	if ( $captcha_on ) {
		exit( 0 );
	}

	$decoded = json_decode( (string) $row['form_structure'] );
	$fields  = array();
	$fapp    = null;
	if ( is_array( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
		foreach ( $decoded[0] as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->ftype ) ) {
				continue;
			}
			if ( 'fapp' === $field->ftype ) {
				$fapp = $field;
			}
			if ( isset( $field->name ) ) {
				$fields[ (string) $field->name ] = $field;
			}
		}
	}

	$name_ok  = isset( $fields['name'] ) && 'ftext' === $fields['name']->ftype && TZFD_Customer_Field_Check::is_required( $fields['name'] );
	$email_ok = isset( $fields['email'] ) && 'femail' === $fields['email']->ftype && TZFD_Customer_Field_Check::is_required( $fields['email'] );
	$phone_ok = isset( $fields['phone'] ) && 'ftext' === $fields['phone']->ftype && ! TZFD_Customer_Field_Check::is_required( $fields['phone'] );
	if ( null === $fapp || ! $name_ok || ! $email_ok || ! $phone_ok ) {
		echo 'FAIL public-booking setup -- calendar 1 needs an appointment field, a required name, a required email and an optional phone (appointment '
			. ( null === $fapp ? 'missing' : 'ok' ) . ', name ' . ( $name_ok ? 'ok' : 'not ok' ) . ', email ' . ( $email_ok ? 'ok' : 'not ok' )
			. ', phone ' . ( $phone_ok ? 'ok' : 'not ok' ) . ")\n";
		exit( 1 );
	}

	update_option( $backup, $row, false );
	$fapp->services = array( (object) array(
		'name' => 'Name check visit', 'price' => 0, 'duration' => 60, 'capacity' => 1, 'pb' => 0, 'pa' => 0, 'ohindex' => 0,
	) );
	$wpdb->update( $forms, array( 'form_structure' => wp_json_encode( $decoded ) ), array( 'id' => $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture write

	$page_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Public booking name test form',
		'post_content' => '[TZFD_FRONT_DESK id="' . $form_id . '"]',
	) );
	if ( ! is_int( $page_id ) || $page_id < 1 ) {
		echo "FAIL public-booking setup -- could not publish the form page\n";
		exit( 1 );
	}
	update_option( $backup . '_page', $page_id, false );

	echo 'TZFD_PN_PAGE=' . $page_id . "\n";
	echo 'TZFD_PN_DATE=' . $date . "\n";
	exit( 0 );
}

if ( 'lookup' === $mode ) {
	if ( ! $tzfd_pn_test_email ) {
		echo "FAIL public-booking lookup -- TZFD_PN_EMAIL is not a test address\n";
		exit( 1 );
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, posted_data FROM %i WHERE formid=%d AND posted_data LIKE %s', $bookings, $form_id, '%' . $wpdb->esc_like( $email ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read, bypasses the object cache
	$rows = is_array( $rows ) ? $rows : array();
	echo 'TZFD_PN_ROWS=' . count( $rows ) . "\n";
	if ( isset( $rows[0] ) ) {
		$first   = $rows[0];
		$decoded = TZFD_Stored_Data::decode_booking( (string) $first['posted_data'] );
		$data    = is_array( $decoded ) ? $decoded : array();
		$from    = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$to      = gmdate( 'Y-m-d', strtotime( '+90 days' ) );
		$items   = TZFD_Booking_View::items_from_booking( (int) $first['id'], $form_id, '', $email, $data, $from, $to );
		echo 'TZFD_PN_ID=' . (int) $first['id'] . "\n";
		echo 'TZFD_PN_NAME=' . (string) ( $data['name'] ?? '' ) . "\n";
		echo 'TZFD_PN_PHONE=' . (string) ( $data['phone'] ?? '' ) . "\n";
		echo 'TZFD_PN_LIST_CUSTOMER=' . (string) ( $items[0]['customer'] ?? '' ) . "\n";
	}
	exit( 0 );
}

if ( 'restore' === $mode ) {
	if ( $tzfd_pn_test_email ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE formid=%d AND posted_data LIKE %s', $bookings, $form_id, '%' . $wpdb->esc_like( $email ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup
	}
	$row = get_option( $backup );
	if ( is_array( $row ) ) {
		$wpdb->update( $forms, array( 'form_structure' => (string) $row['form_structure'], 'cv_enable_captcha' => (string) $row['cv_enable_captcha'] ), array( 'id' => $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture restore
	}
	$page_id = (int) get_option( $backup . '_page' );
	if ( $page_id > 0 ) {
		wp_delete_post( $page_id, true );
	}
	delete_option( $backup );
	delete_option( $backup . '_page' );
	echo "TZFD_PN_RESTORED=1\n";
	exit( 0 );
}

echo "FAIL harness -- unknown TZFD_PN_MODE\n";
exit( 1 );
