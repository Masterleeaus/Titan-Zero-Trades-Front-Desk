<?php
/**
 * Helper for customer-cancel-http-tests.sh (issue #334, B13). Runs inside WordPress via WP-CLI:
 *   TZFD_B13_MODE=setup   wp eval-file tests/runtime/customer-cancel-state.php --user=1
 *   TZFD_B13_MODE=state   TZFD_B13_ID=<id> ... (prints the booking state and outbox rows)
 *   TZFD_B13_MODE=dispatch (runs the customer event outbox once, then prints the counts)
 * Setup prints KEY=value lines for the shell script. It sends no mail: wp_mail is captured by a filter.
 * Driven by tests/runtime/customer-cancel-http-tests.sh. Not shipped in the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

global $wpdb;
$mode    = (string) getenv( 'TZFD_B13_MODE' );
$company = (int) TZFD_Scoped_Store::company_id();
$table   = $wpdb->prefix . TZFD_Bookings::TABLE;
$outbox  = $wpdb->prefix . TZFD_Customer_Event_Outbox::TABLE;

// Capture every wp_mail call instead of sending it. The count shows whether a customer message was produced.
$GLOBALS['tzfd_b13_mail'] = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
	$GLOBALS['tzfd_b13_mail'][] = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'] ) : '';
	return true;
}, 10, 2 );

if ( 'setup' === $mode ) {
	$form  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE company_id=%d ORDER BY id LIMIT 1', $wpdb->prefix . TZFD_Tables::FORMS, $company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup lookup
	$email = 'b13-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
	$when  = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
	$data  = array( 'name' => 'B13 Customer', 'email' => $email, 'apps' => array( array( 'date' => $when, 'slot' => '', 'service' => 'Visit', 'cancelled' => '' ) ) );
	$wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row
		'formid' => $form, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => $email,
		'posted_data' => serialize( $data ), 'data' => 'B13 CUSTOMER', 'whoadded' => '1', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the plugin's own stored format
	) );
	$id  = (int) $wpdb->insert_id;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id=%d', $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture read
	$expires = TZFD_Customer_Cancellation::new_expiry();
	$token   = TZFD_Customer_Cancellation::create_token( $id, $email, wp_salt( 'auth' ), TZFD_Customer_Cancellation::row_version( $row ), $expires );
	echo 'TZFD_B13_ID=' . $id . "\n";
	echo 'TZFD_B13_TOKEN=' . $token . "\n";
	echo 'TZFD_B13_EXPIRES=' . $expires . "\n";
	echo 'TZFD_B13_EMAIL=' . $email . "\n";
	echo 'TZFD_B13_FORM=' . $form . "\n";
	echo 'TZFD_B13_COMPANY=' . $company . "\n";
	exit( 0 );
}

$id = (int) getenv( 'TZFD_B13_ID' );

if ( 'state' === $mode ) {
	$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id=%d', $table, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	$data = is_object( $row ) ? TZFD_Stored_Data::decode_booking( (string) $row->posted_data ) : null;
	$cancelled = is_array( $data ) && ! empty( $data['apps'] ) && 'Cancelled by customer' === ( $data['apps'][0]['cancelled'] ?? '' );
	$events = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, state FROM %i WHERE booking_id=%d AND event_type=%s', $outbox, $id, 'cancelled' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	echo 'TZFD_B13_CANCELLED=' . ( $cancelled ? '1' : '0' ) . "\n";
	echo 'TZFD_B13_EVENTS=' . wp_json_encode( $events ) . "\n";
	echo 'TZFD_B13_MAIL=' . count( $GLOBALS['tzfd_b13_mail'] ) . "\n";
	exit( 0 );
}

if ( 'dispatch' === $mode ) {
	$counts = TZFD_Customer_Event_Outbox::dispatch( 25 );
	echo 'TZFD_B13_DISPATCH=' . wp_json_encode( $counts ) . "\n";
	echo 'TZFD_B13_MAIL=' . count( $GLOBALS['tzfd_b13_mail'] ) . "\n";
	exit( 0 );
}

echo "FAIL harness -- unknown TZFD_B13_MODE\n";
exit( 1 );
