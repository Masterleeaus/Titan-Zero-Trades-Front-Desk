<?php
/**
 * Helper for customer-reschedule-http-tests.sh (issue #463, B13 reschedule half). Runs inside WordPress via WP-CLI:
 *   TZFD_RS_MODE=setup    wp eval-file tests/runtime/customer-reschedule-state.php --user=1
 *   TZFD_RS_MODE=state    TZFD_RS_ID=<id> ... (booking slot, receipt, outbox rows, reminder queue)
 *   TZFD_RS_MODE=dispatch (runs the customer event outbox once, then prints counts and captured mail)
 *   TZFD_RS_MODE=restore  TZFD_RS_ID=<id> ... (removes the fixture booking and puts calendar 1 back)
 * Setup makes calendar 1 one appointment service (capacity 2, 60 minutes), turns on the customer copy, enables the
 * reminder add-on with a 2 hour lead when it can, stores one booking in the scheduler's own representation, and
 * prints KEY=value lines for the shell script. It sends no mail: wp_mail is captured by a filter.
 * Driven by tests/runtime/customer-reschedule-http-tests.sh. Not shipped in the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

global $wpdb;
$mode     = (string) getenv( 'TZFD_RS_MODE' );
$form_id  = 1;
$forms    = $wpdb->prefix . TZFD_Tables::FORMS;
$bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$outbox   = $wpdb->prefix . TZFD_Customer_Event_Outbox::TABLE;
$backup   = 'tzfd_reschedule_test_backup';
$company  = (int) TZFD_Scoped_Store::company_id();

// Capture every wp_mail call instead of sending it. The reschedule email is queued, so it is captured at dispatch.
$GLOBALS['tzfd_rs_mail'] = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
	$GLOBALS['tzfd_rs_mail'][] = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'] ) : '';
	return true;
}, 10, 2 );

/** Unix timestamps of the reminder hook events queued for one booking, sorted. */
function tzfd_rs_reminder_times( $booking_id ) {
	$times = array();
	if ( ! function_exists( '_get_cron_array' ) || ! class_exists( 'TZFD_Reminder' ) ) { return $times; }
	foreach ( _get_cron_array() as $timestamp => $hooks ) {
		if ( ! is_array( $hooks ) || empty( $hooks[ TZFD_Reminder::HOOK ] ) || ! is_array( $hooks[ TZFD_Reminder::HOOK ] ) ) { continue; }
		foreach ( $hooks[ TZFD_Reminder::HOOK ] as $event ) {
			$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
			if ( isset( $args[0] ) && (int) $args[0] === (int) $booking_id ) { $times[] = (int) $timestamp; }
		}
	}
	sort( $times );
	return $times;
}

/** Reminder lead time in hours for calendar 1, or 0 when the add-on is absent or off. */
function tzfd_rs_reminder_hours() {
	if ( ! class_exists( 'TZFD_Reminder' ) || ! class_exists( 'TZFD_Addon_Support' ) ) { return 0; }
	$settings = TZFD_Addon_Support::form_settings( TZFD_Reminder::KEY, 1, TZFD_Reminder::defaults() );
	return is_array( $settings ) ? (int) ( $settings['hours'] ?? 0 ) : 0;
}

if ( 'setup' === $mode ) {
	// The plugin keeps its engine in a local variable at boot, so the harness builds one. TZFD_Scheduling_Plugin
	// has no constructor in its class chain, so creating it has no side effects.
	$engine = $GLOBALS['tz_fd_plugin'] ?? new TZFD_Scheduling_Plugin();
	if ( ! $engine instanceof TZFD_Scheduling_Plugin ) {
		echo "FAIL harness -- scheduling engine not loaded\n";
		exit( 1 );
	}
	$form = $wpdb->get_row( $wpdb->prepare( 'SELECT form_structure, cu_enable_copy_to_user, cu_emailformat FROM %i WHERE id=%d', $forms, $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup read
	if ( ! is_array( $form ) ) {
		echo "FAIL reschedule setup -- calendar 1 does not exist\n";
		exit( 1 );
	}
	$original_reminder = class_exists( 'TZFD_Addon_Support' ) ? TZFD_Addon_Support::form_settings( TZFD_Reminder::KEY, 1, TZFD_Reminder::defaults() ) : null;
	update_option( $backup, array( 'form' => $form, 'reminder' => $original_reminder ), false );

	$decoded = json_decode( (string) $form['form_structure'] );
	$fname   = '';
	if ( is_array( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
		foreach ( $decoded[0] as $field ) {
			if ( '' === $fname && is_object( $field ) && isset( $field->ftype, $field->name ) && 'fapp' === $field->ftype ) {
				$field->services = array( (object) array(
					'name' => 'Reschedule visit', 'price' => 0, 'duration' => 60, 'capacity' => 2, 'pb' => 0, 'pa' => 0, 'ohindex' => 0,
				) );
				$fname = (string) $field->name;
			}
		}
	}
	if ( '' === $fname ) {
		echo "FAIL reschedule setup -- calendar 1 has no appointment field\n";
		exit( 1 );
	}
	$wpdb->update( $forms, array( 'form_structure' => wp_json_encode( $decoded ), 'cu_enable_copy_to_user' => 'true', 'cu_emailformat' => 'text' ), array( 'id' => $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture write

	if ( class_exists( 'TZFD_Addon_Support' ) && class_exists( 'TZFD_Reminder' ) ) {
		$request = TZFD_Reminder::defaults();
		$request['hours'] = '2';
		TZFD_Addon_Support::save_form_settings( TZFD_Reminder::KEY, $form_id, TZFD_Reminder::defaults(), $request );
	}

	$when     = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
	$new_when = gmdate( 'Y-m-d', strtotime( '+31 days' ) );
	$email    = 'rs-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
	$app      = array( 'id' => 1, 'cancelled' => 'Approved', 'serviceindex' => 0, 'service' => 'Reschedule visit', 'duration' => 60,
		'price' => 0, 'date' => $when, 'slot' => '09:00/10:00', 'field' => $fname, 'quant' => 1, 'sid' => '' );
	$old_text = $engine->get_appointments_text( array( $app ) );
	$data     = array( 'name' => 'Reschedule Customer', 'email' => $email, 'formid' => $form_id, 'apps' => array( $app ) );
	$data[ $fname ]          = $old_text;
	$data['app_date_1']      = $engine->format_date( $when );
	$data['app_slot_1']      = '09:00/10:00';
	$data['app_starttime_1'] = $engine->format12hours( '09:00', true );
	$data['app_endtime_1']   = $engine->format12hours( '10:00', true );
	$data['app_status_1']    = 'Approved';
	$data['app_service_1']   = 'Reschedule visit';
	$data['app_duration_1']  = 60;
	$data['app_price_1']     = 0;
	$data['app_quantity_1']  = 1;

	$wpdb->insert( $bookings, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row
		'formid' => $form_id, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => $email,
		'posted_data' => serialize( $data ), 'data' => "Appointments:\n" . $old_text . "\n", 'whoadded' => '1', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the plugin's own stored format
	) );
	$id  = (int) $wpdb->insert_id;
	$row = $id > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id=%d', $bookings, $id ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture read
	if ( ! is_object( $row ) ) {
		echo "FAIL reschedule setup -- booking row not stored\n";
		exit( 1 );
	}
	$version = TZFD_Customer_Rescheduling::version( $row );
	$link    = TZFD_Customer_Rescheduling::issue( $id, $form_id, $email, $version, wp_salt( 'auth' ) );
	if ( null === $link ) {
		echo "FAIL reschedule setup -- no reschedule link could be issued\n";
		exit( 1 );
	}

	// The reminder add-on queues one reminder per appointment when a booking is saved. Mirror that for this row.
	$hours  = tzfd_rs_reminder_hours();
	$before = array();
	if ( $hours > 0 && class_exists( 'TZFD_Timed_Notices' ) ) {
		TZFD_Timed_Notices::schedule_booking( array( 'itemnumber' => $id, 'formid' => $form_id, 'apps' => array( $app ) ),
			TZFD_Reminder::KEY, TZFD_Reminder::HOOK, 'start', -1, TZFD_Reminder::defaults(), $company );
		$before = tzfd_rs_reminder_times( $id );
	}
	$reminder_on = ( $hours > 0 && 1 === count( $before ) ) ? 1 : 0;

	update_option( $backup . '_fixture', array( 'id' => $id, 'hours' => $reminder_on ? $hours : 0,
		'new_date' => $new_when, 'new_start' => '11:00' ), false );

	echo 'TZFD_RS_ID=' . $id . "\n";
	echo 'TZFD_RS_FORM=' . $form_id . "\n";
	echo 'TZFD_RS_EMAIL=' . $email . "\n";
	echo 'TZFD_RS_TOKEN=' . $link['token'] . "\n";
	echo 'TZFD_RS_ISSUED=' . $link['issued'] . "\n";
	echo 'TZFD_RS_EXPIRES=' . $link['expires'] . "\n";
	echo 'TZFD_RS_VERSION=' . $version . "\n";
	echo 'TZFD_RS_OLDDATE=' . $when . "\n";
	echo 'TZFD_RS_OLDSTART=09:00' . "\n";
	echo 'TZFD_RS_NEWDATE=' . $new_when . "\n";
	echo 'TZFD_RS_NEWSTART=11:00' . "\n";
	echo 'TZFD_RS_REMINDER=' . $reminder_on . "\n";
	exit( 0 );
}

$id = (int) getenv( 'TZFD_RS_ID' );

if ( 'state' === $mode ) {
	$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id=%d', $bookings, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	$data = is_object( $row ) ? TZFD_Stored_Data::decode_booking( (string) $row->posted_data ) : null;
	$app  = is_array( $data ) && ! empty( $data['apps'][0] ) && is_array( $data['apps'][0] ) ? $data['apps'][0] : array();
	$events = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, state FROM %i WHERE booking_id=%d ORDER BY id', $outbox, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read
	$fixture  = get_option( $backup . '_fixture' );
	$expected = 0;
	if ( is_array( $fixture ) && (int) $fixture['hours'] > 0 ) {
		$expected = ( new DateTimeImmutable( $fixture['new_date'] . ' ' . $fixture['new_start'] . ':00', wp_timezone() ) )->getTimestamp() - (int) $fixture['hours'] * 3600;
	}
	echo 'TZFD_RS_APP_DATE=' . ( $app['date'] ?? '' ) . "\n";
	echo 'TZFD_RS_APP_SLOT=' . ( $app['slot'] ?? '' ) . "\n";
	echo 'TZFD_RS_STATUS=' . ( $app['cancelled'] ?? '' ) . "\n";
	echo 'TZFD_RS_RECEIPT=' . ( is_array( $data ) && isset( $data[ TZFD_Customer_Rescheduling::RECEIPT ] ) ? 1 : 0 ) . "\n";
	echo 'TZFD_RS_EVENTS=' . wp_json_encode( $events ) . "\n";
	echo 'TZFD_RS_REMINDERS=' . implode( ',', tzfd_rs_reminder_times( $id ) ) . "\n";
	echo 'TZFD_RS_REMINDER_EXPECTED=' . $expected . "\n";
	exit( 0 );
}

if ( 'dispatch' === $mode ) {
	$counts = TZFD_Customer_Event_Outbox::dispatch( 25 );
	echo 'TZFD_RS_DISPATCH=' . wp_json_encode( $counts ) . "\n";
	echo 'TZFD_RS_MAIL=' . count( $GLOBALS['tzfd_rs_mail'] ) . "\n";
	echo 'TZFD_RS_MAIL_TO=' . implode( ',', $GLOBALS['tzfd_rs_mail'] ) . "\n";
	exit( 0 );
}

if ( 'restore' === $mode ) {
	if ( $id > 0 ) {
		if ( class_exists( 'TZFD_Timed_Notices' ) && class_exists( 'TZFD_Reminder' ) ) {
			TZFD_Timed_Notices::clear_booking( TZFD_Reminder::HOOK, $id, $company );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE booking_id=%d', $outbox, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup
		$wpdb->delete( $bookings, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup
	}
	$saved = get_option( $backup );
	if ( is_array( $saved ) && is_array( $saved['form'] ?? null ) ) {
		$wpdb->update( $forms, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture restore
			'form_structure' => (string) $saved['form']['form_structure'],
			'cu_enable_copy_to_user' => (string) $saved['form']['cu_enable_copy_to_user'],
			'cu_emailformat' => (string) $saved['form']['cu_emailformat'],
		), array( 'id' => $form_id ) );
	}
	if ( is_array( $saved ) && is_array( $saved['reminder'] ?? null ) && class_exists( 'TZFD_Addon_Support' ) && class_exists( 'TZFD_Reminder' ) ) {
		TZFD_Addon_Support::save_form_settings( TZFD_Reminder::KEY, $form_id, TZFD_Reminder::defaults(), $saved['reminder'] );
	}
	delete_option( $backup );
	delete_option( $backup . '_fixture' );
	echo "TZFD_RS_RESTORED=1\n";
	exit( 0 );
}

echo "FAIL harness -- unknown TZFD_RS_MODE\n";
exit( 1 );
