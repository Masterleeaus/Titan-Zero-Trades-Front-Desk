<?php
/**
 * Helper for reschedule-http-tests.sh (issue #463, B13 reschedule half). Runs inside WordPress via WP-CLI:
 *   TZFD_RS_MODE=setup    wp eval-file tests/runtime/reschedule-state.php --user=1
 *       makes calendar 1 take one booking per slot (60 minute visits), publishes a page with the calendar shortcode,
 *       and prints KEY=value lines. The original calendar row is kept in an option until restore runs.
 *   TZFD_RS_MODE=issue    TZFD_RS_EMAIL=<customer e-mail> (the newest booking for that e-mail, and a reschedule link for it)
 *   TZFD_RS_MODE=answer   TZFD_RS_CHALLENGE=<id> (the answer stored for one challenge id from the visitor's rand_code cookie)
 *   TZFD_RS_MODE=state    TZFD_RS_ID=<id> (the stored slot for the booking, and its outbox rows)
 *   TZFD_RS_MODE=dispatch (runs the customer event outbox once; prints the counts and the captured recipients)
 *   TZFD_RS_MODE=restore  (puts back the calendar row and removes the test page)
 * No mail is sent: wp_mail is captured by a filter. Driven by tests/runtime/reschedule-http-tests.sh. Not shipped in the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-stored-data.php', 'class-tzfd-scheduling-rules.php' ) as $tzfd_file ) {
	if ( is_readable( $tzfd_inc . $tzfd_file ) ) {
		require_once $tzfd_inc . $tzfd_file;
	}
}

global $wpdb;
$mode     = (string) getenv( 'TZFD_RS_MODE' );
$forms    = $wpdb->prefix . TZFD_Tables::FORMS;
$bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$outbox   = $wpdb->prefix . TZFD_Customer_Event_Outbox::TABLE;
$backup   = 'tzfd_reschedule_test_backup';
$form_id  = 1;

// Capture every wp_mail call instead of sending it. The dispatch mode prints the recipients it captured.
$GLOBALS['tzfd_rs_mail'] = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
	$GLOBALS['tzfd_rs_mail'][] = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'] ) : '';
	return true;
}, 10, 2 );

if ( 'setup' === $mode ) {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT form_structure, cv_enable_captcha FROM %i WHERE id=%d', $forms, $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup read
	if ( ! is_array( $row ) ) {
		echo "FAIL reschedule setup -- calendar 1 does not exist\n";
		exit( 1 );
	}
	update_option( $backup, $row, false );

	$decoded = json_decode( (string) $row['form_structure'] );
	$seeded  = false;
	if ( is_array( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
		foreach ( $decoded[0] as $field ) {
			if ( is_object( $field ) && isset( $field->ftype ) && 'fapp' === $field->ftype ) {
				$field->services = array( (object) array(
					'name' => 'Reschedule visit', 'price' => 0, 'duration' => 60, 'capacity' => 1, 'pb' => 0, 'pa' => 0, 'ohindex' => 0,
				) );
				$seeded = true;
			}
		}
	}
	if ( ! $seeded ) {
		echo "FAIL reschedule setup -- calendar 1 has no appointment field\n";
		exit( 1 );
	}
	$wpdb->update( $forms, array( 'form_structure' => wp_json_encode( $decoded ) ), array( 'id' => $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture write

	$page_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Reschedule test booking form',
		'post_content' => '[TZFD_FRONT_DESK id="' . $form_id . '"]',
	) );
	if ( ! is_int( $page_id ) || $page_id < 1 ) {
		echo "FAIL reschedule setup -- could not publish the form page\n";
		exit( 1 );
	}
	update_option( $backup . '_page', $page_id, false );

	echo 'TZFD_RS_PAGE=' . $page_id . "\n";
	echo 'TZFD_RS_DATE=' . gmdate( 'Y-m-d', strtotime( '+31 days' ) ) . "\n";
	exit( 0 );
}

if ( 'answer' === $mode ) {
	// The answer the server stored for one challenge id from the visitor's rand_code cookie. The check itself is unchanged.
	$challenge = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) getenv( 'TZFD_RS_CHALLENGE' ) ) );
	$answer    = '' !== $challenge ? get_transient( 'tzfd-captcha-' . $challenge ) : false;
	echo 'TZFD_RS_CODE=' . ( is_string( $answer ) ? $answer : '' ) . "\n";
	exit( 0 );
}

if ( 'issue' === $mode ) {
	$email = strtolower( trim( (string) getenv( 'TZFD_RS_EMAIL' ) ) );
	$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE formid=%d AND notifyto=%s ORDER BY id DESC LIMIT 1', $bookings, $form_id, $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	if ( ! is_object( $row ) ) {
		echo "TZFD_RS_ISSUE=missing\n";
		exit( 0 );
	}
	$version = TZFD_Customer_Rescheduling::version( $row );
	$link    = TZFD_Customer_Rescheduling::issue( (int) $row->id, (int) $row->formid, (string) $row->notifyto, $version, wp_salt( 'auth' ) );
	if ( null === $link ) {
		echo "TZFD_RS_ISSUE=unsigned\n";
		exit( 0 );
	}
	echo 'TZFD_RS_ID=' . (int) $row->id . "\n";
	echo 'TZFD_RS_VERSION=' . $version . "\n";
	echo 'TZFD_RS_TOKEN=' . $link['token'] . "\n";
	echo 'TZFD_RS_ISSUED=' . $link['issued'] . "\n";
	echo 'TZFD_RS_EXPIRES=' . $link['expires'] . "\n";
	echo 'TZFD_RS_ISSUE=ok' . "\n";
	exit( 0 );
}

if ( 'state' === $mode ) {
	$id  = (int) getenv( 'TZFD_RS_ID' );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id=%d', $bookings, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	$data = is_object( $row ) ? TZFD_Stored_Data::decode_booking( (string) $row->posted_data ) : null;
	$slot = is_array( $data ) && ! empty( $data['apps'][0]['slot'] ) ? (string) $data['apps'][0]['slot'] : '';
	$date = is_array( $data ) && ! empty( $data['apps'][0]['date'] ) ? (string) $data['apps'][0]['date'] : '';
	$events = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, state FROM %i WHERE booking_id=%d ORDER BY id', $outbox, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	echo 'TZFD_RS_SLOT=' . $slot . "\n";
	echo 'TZFD_RS_DATE_STORED=' . $date . "\n";
	echo 'TZFD_RS_EVENTS=' . wp_json_encode( (array) $events ) . "\n";
	exit( 0 );
}

if ( 'dispatch' === $mode ) {
	$counts = TZFD_Customer_Event_Outbox::dispatch( 25 );
	echo 'TZFD_RS_DISPATCH=' . wp_json_encode( $counts ) . "\n";
	echo 'TZFD_RS_MAIL=' . wp_json_encode( $GLOBALS['tzfd_rs_mail'] ) . "\n";
	exit( 0 );
}

if ( 'restore' === $mode ) {
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
	echo "TZFD_RS_RESTORED=1\n";
	exit( 0 );
}

echo "FAIL harness -- unknown TZFD_RS_MODE\n";
exit( 1 );
