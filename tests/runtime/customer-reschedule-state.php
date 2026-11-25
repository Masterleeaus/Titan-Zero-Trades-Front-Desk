<?php
/**
 * Helper for customer-reschedule-http-tests.sh (issue #428, runtime B13 reschedule half). Runs inside WordPress via WP-CLI:
 *   TZFD_RS_MODE=find     TZFD_RS_EMAIL=<e-mail> wp eval-file ... (prints the booking id, version and stored slot)
 *   TZFD_RS_MODE=state    TZFD_RS_ID=<id> ...     (prints the stored slot and the reschedule outbox rows)
 *   TZFD_RS_MODE=token    TZFD_RS_ID=<id> ...     (prints the booking version and a signed link for it)
 *   TZFD_RS_MODE=dispatch (runs the customer event outbox once, then prints the counts)
 * It sends no mail: wp_mail is captured by a filter. Driven by tests/runtime/customer-reschedule-http-tests.sh.
 * Not shipped in the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

global $wpdb;
$mode    = (string) getenv( 'TZFD_RS_MODE' );
$table   = $wpdb->prefix . TZFD_Bookings::TABLE;
$outbox  = $wpdb->prefix . TZFD_Customer_Event_Outbox::TABLE;
$form_id = 1;

// Capture every wp_mail call instead of sending it.
$GLOBALS['tzfd_rs_mail'] = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
	$GLOBALS['tzfd_rs_mail'][] = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'] ) : '';
	return true;
}, 10, 2 );

/** Returns the stored apps[0] slot and date for a booking, or empty strings. */
$app_of = static function ( $booking_id ) use ( $wpdb, $table ) {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id=%d', $table, $booking_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	$data = is_object( $row ) ? TZFD_Stored_Data::decode_booking( (string) $row->posted_data ) : null;
	$app  = is_array( $data ) && ! empty( $data['apps'][0] ) && is_array( $data['apps'][0] ) ? $data['apps'][0] : array();
	return array( (string) ( $app['date'] ?? '' ), (string) ( $app['slot'] ?? '' ) );
};

if ( 'find' === $mode ) {
	$email = (string) getenv( 'TZFD_RS_EMAIL' );
	$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE formid=%d AND notifyto=%s ORDER BY id DESC LIMIT 1', $table, $form_id, $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	if ( ! is_object( $row ) ) {
		echo "TZFD_RS_ID=0\n";
		exit( 0 );
	}
	list( $date, $slot ) = $app_of( (int) $row->id );
	echo 'TZFD_RS_ID=' . (int) $row->id . "\n";
	echo 'TZFD_RS_DATE=' . $date . "\n";
	echo 'TZFD_RS_SLOT=' . $slot . "\n";
	exit( 0 );
}

$id = (int) getenv( 'TZFD_RS_ID' );

if ( 'token' === $mode ) {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id=%d', $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	if ( ! is_object( $row ) ) {
		echo "FAIL harness -- booking not found\n";
		exit( 1 );
	}
	$version = TZFD_Customer_Rescheduling::version( $row );
	$link    = TZFD_Customer_Rescheduling::issue( (int) $row->id, (int) $row->formid, (string) $row->notifyto, $version, wp_salt( 'auth' ) );
	if ( null === $link ) {
		echo "FAIL harness -- no link could be issued\n";
		exit( 1 );
	}
	echo 'TZFD_RS_VERSION=' . $version . "\n";
	echo 'TZFD_RS_TOKEN=' . $link['token'] . "\n";
	echo 'TZFD_RS_ISSUED=' . $link['issued'] . "\n";
	echo 'TZFD_RS_EXPIRES=' . $link['expires'] . "\n";
	exit( 0 );
}

if ( 'state' === $mode ) {
	list( $date, $slot ) = $app_of( $id );
	$events = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, state FROM %i WHERE booking_id=%d AND event_type=%s', $outbox, $id, 'rescheduled' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	echo 'TZFD_RS_DATE=' . $date . "\n";
	echo 'TZFD_RS_SLOT=' . $slot . "\n";
	echo 'TZFD_RS_EVENTS=' . wp_json_encode( $events ) . "\n";
	echo 'TZFD_RS_MAIL=' . count( $GLOBALS['tzfd_rs_mail'] ) . "\n";
	exit( 0 );
}

if ( 'dispatch' === $mode ) {
	$counts = TZFD_Customer_Event_Outbox::dispatch( 25 );
	echo 'TZFD_RS_DISPATCH=' . wp_json_encode( $counts ) . "\n";
	echo 'TZFD_RS_MAIL=' . count( $GLOBALS['tzfd_rs_mail'] ) . "\n";
	exit( 0 );
}

echo "FAIL harness -- unknown TZFD_RS_MODE\n";
exit( 1 );
