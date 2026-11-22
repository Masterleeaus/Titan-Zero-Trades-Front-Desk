<?php
/**
 * Real-WordPress runtime tests for Scheduling & Enquiries Manager (folder titan-zero-trades-front-desk).
 *
 * Runs inside a disposable WordPress site, via WP-CLI:
 *   wp eval-file tests/runtime/wp-runtime-tests.php --user=1
 * Driven by tests/runtime/run-wp-runtime.sh, which provisions the site. Not shipped in the plugin.
 *
 * Output: one line per check, "PASS|FAIL|SKIP|GAP name -- detail".
 *   PASS  the check ran and held.
 *   FAIL  the check ran and the behaviour was wrong.
 *   SKIP  the check was not run here; the reason is given.
 *   GAP   a known product gap was observed; it is reported, not counted as a pass.
 * No live external service is called: no SMS, e-mail transport, telephony or hosted AI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_rt'] = array( 'PASS' => 0, 'FAIL' => 0, 'SKIP' => 0, 'GAP' => 0 );

function tzfd_rt_out( $status, $name, $detail = '' ) {
	$GLOBALS['tzfd_rt'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
}
function tzfd_rt_ok( $name, $ok, $detail = '' ) {
	tzfd_rt_out( $ok ? 'PASS' : 'FAIL', $name, $detail );
	return (bool) $ok;
}
function tzfd_rt_skip( $name, $why ) { tzfd_rt_out( 'SKIP', $name, $why ); }
function tzfd_rt_gap( $name, $why ) { tzfd_rt_out( 'GAP', $name, $why ); }

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array(
	'class-tzfd-tables.php', 'class-tzfd-intake-mode.php', 'class-tzfd-final-booking-write.php',
	'class-tzfd-transaction-storage.php', 'class-tzfd-booking-create-transaction.php',
	'class-tzfd-scoped-store.php', 'class-tzfd-stored-data.php', 'class-tzfd-resource-catalog.php',
	'class-tzfd-resource-availability.php', 'class-tzfd-slot-holds.php', 'class-tzfd-capacity-lock.php',
	'class-tzfd-booking-capacity-reader.php', 'class-tzfd-customer-cancellation.php',
	'class-tzfd-customer-rescheduling.php', 'class-tzfd-booking-reminder-bridge.php',
	'class-tzfd-booking-action-outbox.php', 'class-tzfd-booking-status-api.php',
	'class-tzfd-resource-admin.php',
) as $tzfd_file ) {
	if ( is_readable( $tzfd_inc . $tzfd_file ) ) {
		require_once $tzfd_inc . $tzfd_file;
	}
}

global $wpdb;
$tzfd_admin_id = 1;
wp_set_current_user( $tzfd_admin_id );
$tzfd_company  = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
$tzfd_bookings = $wpdb->prefix . 'tz_bookings';
$tzfd_secret   = str_repeat( 'a1', 32 ); // 64 hex characters; test-only secret.
$tzfd_version  = str_repeat( 'b2', 32 );
$tzfd_expires  = time() + 3600;

/* ------------------------------------------------------------------ A. Booking create, slot holds, capacity */

tzfd_rt_ok( 'A0 company resolves for the admin', $tzfd_company > 0, 'company=' . $tzfd_company );

$tzfd_email = 'rt-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
$tzfd_form  = 1;
$tzfd_params = array( 'formid' => $tzfd_form, 'name' => 'RT Customer', 'email' => $tzfd_email, 'notes' => 'runtime test' );
$tzfd_row    = array(
	'formid' => $tzfd_form, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1',
	'notifyto' => $tzfd_email, 'posted_data' => serialize( $tzfd_params ), 'data' => 'RT CUSTOMER', 'whoadded' => (string) $tzfd_admin_id, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- posted_data must hold the plugin's own PHP-serialized format
);
$tzfd_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id=%d', $tzfd_bookings, $tzfd_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache
$tzfd_res    = TZFD_Booking_Create_Transaction::create( $wpdb, $tzfd_company, $tzfd_row, $tzfd_params, TZFD_Intake_Mode::MODE_INSTANT );
$tzfd_id     = (int) ( $tzfd_res['id'] ?? 0 );
tzfd_rt_ok( 'A1 customer booking create commits one row', ( $tzfd_res['status'] ?? '' ) === 'success' && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id=%d', $tzfd_bookings, $tzfd_company ) ) - $tzfd_before, 'status=' . ( $tzfd_res['status'] ?? 'none' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache

$tzfd_orphan_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tzfd_bookings ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache
$tzfd_bad = TZFD_Booking_Create_Transaction::create( $wpdb, 0, $tzfd_row, $tzfd_params, TZFD_Intake_Mode::MODE_INSTANT );
tzfd_rt_ok( 'A2 create for an invalid company fails closed with no row', 'failed' === ( $tzfd_bad['status'] ?? '' ) && $tzfd_orphan_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tzfd_bookings ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache

// Slot holds: the table is an explicit, admin-only install.
$tzfd_hold_ok = class_exists( 'TZFD_Slot_Holds' ) && true === TZFD_Slot_Holds::install();
tzfd_rt_ok( 'A3 slot-hold table installs and passes schema check (admin)', $tzfd_hold_ok );

$tzfd_hold_table = $wpdb->prefix . TZFD_Tables::SLOT_HOLDS;
$tzfd_ok_req = array(
	'company_id' => (string) $tzfd_company, 'verified_company_id' => (string) $tzfd_company,
	'required_resource_ids' => array( 'room-a' ), 'start' => gmdate( 'c', $tzfd_expires + 7200 ),
	'end' => gmdate( 'c', $tzfd_expires + 10800 ), 'ttl_seconds' => 300,
);
$tzfd_hold_req = $tzfd_ok_req;
$tzfd_hold_req['company_id'] = 'x';
try { TZFD_Slot_Holds::validate( $tzfd_hold_req ); $tzfd_v = 'accepted'; } catch ( Throwable $e ) { $tzfd_v = 'refused'; }
tzfd_rt_ok( 'A4 slot hold refuses an unverified company', 'refused' === $tzfd_v );

$tzfd_bad_interval = $tzfd_ok_req;
$tzfd_bad_interval['end'] = $tzfd_ok_req['start'];
try { TZFD_Slot_Holds::validate( $tzfd_bad_interval ); $tzfd_v = 'accepted'; } catch ( Throwable $e ) { $tzfd_v = 'refused'; }
tzfd_rt_ok( 'A5 slot hold refuses an empty or reversed interval', 'refused' === $tzfd_v );

$tzfd_dup_res = $tzfd_ok_req;
$tzfd_dup_res['required_resource_ids'] = array( 'room-a', 'room-a' );
try { TZFD_Slot_Holds::validate( $tzfd_dup_res ); $tzfd_v = 'accepted'; } catch ( Throwable $e ) { $tzfd_v = 'refused'; }
tzfd_rt_ok( 'A6 slot hold refuses duplicate resources', 'refused' === $tzfd_v );

try { $tzfd_clean = TZFD_Slot_Holds::validate( $tzfd_ok_req ); $tzfd_v = 'accepted'; } catch ( Throwable $e ) { $tzfd_v = 'refused: ' . $e->getMessage(); }
tzfd_rt_ok( 'A7 slot hold accepts a well-formed request', 'accepted' === $tzfd_v, $tzfd_v );

// Oversell at the hold layer: one token per company may exist once (unique company_id + token_hash).
$tzfd_token_hash = hash( 'sha256', 'rt-hold-' . $tzfd_email );
$tzfd_hold_row   = array(
	'company_id' => (string) $tzfd_company, 'token_hash' => $tzfd_token_hash, 'state' => 'held',
	'start_utc' => gmdate( 'Y-m-d H:i:s', $tzfd_expires + 7200 ), 'end_utc' => gmdate( 'Y-m-d H:i:s', $tzfd_expires + 10800 ),
	'expires_at' => gmdate( 'Y-m-d H:i:s', $tzfd_expires ), 'payload' => '{}', 'booking_id' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ),
);
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE token_hash=%s', $tzfd_hold_table, $tzfd_token_hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct write; the test needs the database's own unique-key refusal
$wpdb->suppress_errors( true );
$tzfd_first  = $wpdb->insert( $tzfd_hold_table, $tzfd_hold_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct insert; the test needs the database's own unique-key refusal
$tzfd_second = $wpdb->insert( $tzfd_hold_table, $tzfd_hold_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct insert; the test needs the database's own unique-key refusal
$wpdb->suppress_errors( false );
tzfd_rt_ok( 'A8 duplicate hold token for one company is refused by the database', false !== $tzfd_first && false === $tzfd_second, 'first=' . wp_json_encode( $tzfd_first ) . ' second=' . wp_json_encode( $tzfd_second ) );

$tzfd_entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', $tzfd_hold_table, $tzfd_token_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read; the object cache would not reflect the direct insert above
$tzfd_payload_entry = is_array( $tzfd_entry ) ? array_merge( $tzfd_entry, array( 'payload' => wp_json_encode( array(
	'required_resource_ids' => array( 'room-a' ), 'start' => gmdate( 'c', $tzfd_expires + 7200 ),
	'end' => gmdate( 'c', $tzfd_expires + 10800 ), 'quantity' => 1,
) ) ) ) : array();
tzfd_rt_ok( 'A9 hold payload is refused when the state is not held', false === TZFD_Slot_Holds::confirmation_payload( array_merge( $tzfd_payload_entry, array( 'state' => 'released' ) ), (string) $tzfd_company ) );
tzfd_rt_ok( 'A10 hold payload is refused for a different company', false === TZFD_Slot_Holds::confirmation_payload( $tzfd_payload_entry, 'other-company' ) );
tzfd_rt_ok( 'A11 consistent held payload is accepted for confirmation', is_array( TZFD_Slot_Holds::confirmation_payload( $tzfd_payload_entry, (string) $tzfd_company ) ) );

// Capacity lock: a second MySQL connection must not obtain the same calendar lock while it is held.
$tzfd_lock_name = TZFD_Capacity_Lock::name( $wpdb, $tzfd_form );
tzfd_rt_ok( 'A12 capacity lock name is stable and within the 64 character limit', '' !== $tzfd_lock_name && strlen( $tzfd_lock_name ) <= 64 && $tzfd_lock_name === TZFD_Capacity_Lock::name( $wpdb, $tzfd_form ), $tzfd_lock_name );
$tzfd_handle = TZFD_Capacity_Lock::acquire( $wpdb, $tzfd_form, 0 );
tzfd_rt_ok( 'A13 capacity lock is acquired by the first writer', is_array( $tzfd_handle ) );
if ( is_array( $tzfd_handle ) ) {
	$tzfd_other = @new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	if ( $tzfd_other instanceof mysqli && ! $tzfd_other->connect_errno ) {
		$tzfd_r = $tzfd_other->query( "SELECT GET_LOCK('" . $tzfd_other->real_escape_string( $tzfd_lock_name ) . "', 0) AS got" )->fetch_assoc();
		tzfd_rt_ok( 'A14 a second writer cannot take the calendar lock while it is held', '0' === (string) $tzfd_r['got'], 'got=' . $tzfd_r['got'] );
		$tzfd_other->close();
	} else {
		tzfd_rt_skip( 'A14 second writer lock contention', 'second mysqli connection unavailable' );
	}
	tzfd_rt_ok( 'A15 the holder cannot re-enter its own lock on the same connection', false === TZFD_Capacity_Lock::acquire( $wpdb, $tzfd_form, 0 ) );
	tzfd_rt_ok( 'A16 release frees the calendar lock', true === (bool) TZFD_Capacity_Lock::release( $wpdb, $tzfd_handle ) );
}

$tzfd_rows = TZFD_Booking_Capacity_Reader::all_for_calendar( $tzfd_form, $tzfd_company );
tzfd_rt_ok( 'A17 capacity reader returns every reservation for the calendar, not an empty set on error', is_array( $tzfd_rows ) && count( $tzfd_rows ) >= 1, 'rows=' . ( is_array( $tzfd_rows ) ? count( $tzfd_rows ) : 'null' ) );

// A18: oversell through the booking submit path. Calendar capacity is 1 by default, so a second customer asking for
// the same slot must be refused and no second row may be written. The public enquiry form stores enquiries only; the
// booking submit path (pipeline intake, then the slot check) is the path that holds the capacity lock.
$tzfd_os_date  = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
$tzfd_os_draft = array( 'slot' => $tzfd_os_date . ' 10:00', 'name' => 'RT Oversell A', 'email' => 'rt-oversell-a@example.test', 'service_type' => 'rt-oversell', 'description' => 'runtime oversell check' );
$tzfd_os_first  = TZFD_Pipeline::submit( $tzfd_form, $tzfd_os_draft, 'front-desk' );
$tzfd_os_second = TZFD_Pipeline::submit( $tzfd_form, array_merge( $tzfd_os_draft, array( 'name' => 'RT Oversell B', 'email' => 'rt-oversell-b@example.test' ) ), 'front-desk' );
$tzfd_os_rows   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE formid=%d AND notifyto IN (%s, %s)', $tzfd_bookings, $tzfd_form, 'rt-oversell-a@example.test', 'rt-oversell-b@example.test' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw count, bypasses the object cache
tzfd_rt_ok( 'A18 end-to-end oversell prevention through the booking submit path', true === ( $tzfd_os_first['ok'] ?? false ) && 'slot_taken' === ( $tzfd_os_second['error'] ?? '' ) && 1 === $tzfd_os_rows,
	'first=' . wp_json_encode( $tzfd_os_first ) . ' second=' . wp_json_encode( $tzfd_os_second ) . ' rows=' . $tzfd_os_rows );

/* ------------------------------------------------------------------ B. Reschedule and cancel tokens */

$tzfd_rl = TZFD_Customer_Rescheduling::issue( $tzfd_id, $tzfd_form, $tzfd_email, $tzfd_version, $tzfd_secret, time() );
$tzfd_rs = is_array( $tzfd_rl ) ? $tzfd_rl['token'] : '';
tzfd_rt_ok( 'B1 reschedule token is issued for a well-formed request, with a 14-day expiry', 64 === strlen( $tzfd_rs ) && 1209600 === $tzfd_rl['expires'] - $tzfd_rl['issued'] );
tzfd_rt_ok( 'B2 reschedule token ignores e-mail case', $tzfd_rs === TZFD_Customer_Rescheduling::token( $tzfd_id, $tzfd_form, strtoupper( $tzfd_email ), $tzfd_version, $tzfd_secret, $tzfd_rl['issued'], $tzfd_rl['expires'] ) );
tzfd_rt_ok( 'B3 reschedule token fails closed on an empty secret, bad version or missing issue time', '' === TZFD_Customer_Rescheduling::token( $tzfd_id, $tzfd_form, $tzfd_email, $tzfd_version, '', $tzfd_rl['issued'], $tzfd_rl['expires'] ) && '' === TZFD_Customer_Rescheduling::token( $tzfd_id, $tzfd_form, $tzfd_email, 'nope', $tzfd_secret, $tzfd_rl['issued'], $tzfd_rl['expires'] ) && '' === TZFD_Customer_Rescheduling::token( $tzfd_id, $tzfd_form, $tzfd_email, $tzfd_version, $tzfd_secret ) );

$tzfd_ct = TZFD_Customer_Cancellation::create_token( $tzfd_id, $tzfd_email, $tzfd_secret, $tzfd_version, $tzfd_expires );
tzfd_rt_ok( 'B4 cancel token verifies for the same booking, e-mail, version and expiry', TZFD_Customer_Cancellation::verify_token( $tzfd_id, $tzfd_email, $tzfd_ct, $tzfd_secret, $tzfd_version, $tzfd_expires ) );
tzfd_rt_ok( 'B5 cancel token is refused for another booking', ! TZFD_Customer_Cancellation::verify_token( $tzfd_id + 1, $tzfd_email, $tzfd_ct, $tzfd_secret, $tzfd_version, $tzfd_expires ) );
tzfd_rt_ok( 'B6 cancel token is refused for another e-mail address', ! TZFD_Customer_Cancellation::verify_token( $tzfd_id, 'other@example.test', $tzfd_ct, $tzfd_secret, $tzfd_version, $tzfd_expires ) );
tzfd_rt_ok( 'B7 cancel token is refused after the booking version changes', ! TZFD_Customer_Cancellation::verify_token( $tzfd_id, $tzfd_email, $tzfd_ct, $tzfd_secret, str_repeat( 'c3', 32 ), $tzfd_expires ) );
tzfd_rt_ok( 'B8 cancel token is refused once expired', ! TZFD_Customer_Cancellation::verify_token( $tzfd_id, $tzfd_email, $tzfd_ct, $tzfd_secret, $tzfd_version, $tzfd_expires, $tzfd_expires + 1 ) );
tzfd_rt_ok( 'B9 cancel token with a flipped character is refused', ! TZFD_Customer_Cancellation::verify_token( $tzfd_id, $tzfd_email, substr_replace( $tzfd_ct, ( '0' === $tzfd_ct[0] ? '1' : '0' ), 0, 1 ), $tzfd_secret, $tzfd_version, $tzfd_expires ) );
tzfd_rt_ok( 'B10 a booking with a lock flag is not cancellable from the customer link', false === TZFD_Customer_Cancellation::is_cancelable_data( array( 'lock' => 1, 'apps' => array( 'x' ) ) ) );
tzfd_rt_ok( 'B11 a booking without apps is not cancellable from the customer link', false === TZFD_Customer_Cancellation::is_cancelable_data( array() ) );

$tzfd_stored = $wpdb->get_var( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id=%d', $tzfd_bookings, $tzfd_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw column read, bypasses the object cache
$tzfd_cas    = TZFD_Customer_Cancellation::compare_and_swap( $wpdb, $tzfd_id, 'stale-original-value', null );
$tzfd_after  = $wpdb->get_var( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id=%d', $tzfd_bookings, $tzfd_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw column read, bypasses the object cache
tzfd_rt_ok( 'B12 compare-and-swap writes nothing for a stale original and leaves the booking unchanged', empty( $tzfd_cas ) && $tzfd_stored === $tzfd_after, 'result=' . wp_json_encode( $tzfd_cas ) );

// B13: customer cancel through the public link, over HTTP. A booking is made through the booking submit path, then the
// link is previewed (GET), confirmed (POST with the nonce from the preview), and replayed. The cancelled row and the
// staged customer event must each appear once. Reschedule end to end is not covered here (see the report on #334).
$tzfd_cx_email = 'rt-cancel-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
$tzfd_cx_sub   = TZFD_Pipeline::submit( $tzfd_form, array( 'slot' => gmdate( 'Y-m-d', time() + 12 * DAY_IN_SECONDS ) . ' 11:00', 'name' => 'RT Cancel', 'email' => $tzfd_cx_email, 'service_type' => 'rt-cancel', 'description' => 'runtime cancel check' ), 'front-desk' );
$tzfd_cx_id    = (int) ( $tzfd_cx_sub['booking_id'] ?? 0 );
$tzfd_cx_row   = $tzfd_cx_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id=%d', $tzfd_bookings, $tzfd_cx_id ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read for the token version
$tzfd_cx_base  = rtrim( (string) getenv( 'TZFD_RT_URL' ), '/' ) . '/wp-admin/admin-post.php';
$tzfd_cx_exp   = TZFD_Customer_Cancellation::new_expiry();
$tzfd_cx_tok   = is_object( $tzfd_cx_row ) ? TZFD_Customer_Cancellation::create_token( $tzfd_cx_id, $tzfd_cx_email, wp_salt( 'auth' ), TZFD_Customer_Cancellation::row_version( $tzfd_cx_row ), $tzfd_cx_exp ) : '';
$tzfd_cx_args  = array( 'action' => TZFD_Customer_Cancellation::ACTION, 'booking_id' => $tzfd_cx_id, 'token' => $tzfd_cx_tok, 'expires' => $tzfd_cx_exp );
$tzfd_cx_get   = wp_remote_get( add_query_arg( $tzfd_cx_args, $tzfd_cx_base ), array( 'timeout' => 20 ) );
$tzfd_cx_html  = is_wp_error( $tzfd_cx_get ) ? '' : (string) wp_remote_retrieve_body( $tzfd_cx_get );
$tzfd_cx_nonce = preg_match( '/name="_wpnonce" value="([^"]+)"/', $tzfd_cx_html, $tzfd_cx_m ) ? $tzfd_cx_m[1] : '';
tzfd_rt_ok( 'B13a cancel preview shows the confirm form with a booking nonce', 200 === (int) wp_remote_retrieve_response_code( $tzfd_cx_get ) && '' !== $tzfd_cx_nonce && false !== strpos( $tzfd_cx_html, 'confirm_cancel' ),
	'code=' . (int) wp_remote_retrieve_response_code( $tzfd_cx_get ) . ' booking=' . $tzfd_cx_id . ' submitted=' . wp_json_encode( $tzfd_cx_sub ) );

$tzfd_cx_post_args = array_merge( $tzfd_cx_args, array( '_wpnonce' => $tzfd_cx_nonce, 'confirm_cancel' => '1' ) );
$tzfd_cx_post      = wp_remote_post( $tzfd_cx_base, array( 'timeout' => 20, 'body' => $tzfd_cx_post_args ) );
$tzfd_cx_text      = is_wp_error( $tzfd_cx_post ) ? '' : (string) wp_remote_retrieve_body( $tzfd_cx_post );
$tzfd_cx_events    = static function () use ( $wpdb, $tzfd_cx_id ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE booking_id=%d AND event_type=%s', $wpdb->prefix . TZFD_Customer_Event_Outbox::TABLE, $tzfd_cx_id, 'cancelled' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw count of the staged customer events
};
$tzfd_cx_after = $wpdb->get_var( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id=%d', $tzfd_bookings, $tzfd_cx_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw column read, bypasses the object cache
tzfd_rt_ok( 'B13b confirmed cancel is accepted and the booking is no longer cancellable', 200 === (int) wp_remote_retrieve_response_code( $tzfd_cx_post ) && false !== strpos( $tzfd_cx_text, 'has been cancelled' ) && false === TZFD_Customer_Cancellation::is_cancelable_data( maybe_unserialize( $tzfd_cx_after ) ),
	'code=' . (int) wp_remote_retrieve_response_code( $tzfd_cx_post ) );
tzfd_rt_ok( 'B13c the cancel stages exactly one customer event in the outbox', 1 === $tzfd_cx_events(), 'events=' . $tzfd_cx_events() );

$tzfd_cx_replay = wp_remote_post( $tzfd_cx_base, array( 'timeout' => 20, 'body' => $tzfd_cx_post_args ) );
tzfd_rt_ok( 'B13d a replayed cancel is refused and stages no second event', 200 !== (int) wp_remote_retrieve_response_code( $tzfd_cx_replay ) && 1 === $tzfd_cx_events(), 'code=' . (int) wp_remote_retrieve_response_code( $tzfd_cx_replay ) . ' events=' . $tzfd_cx_events() );

// B13e: customer reschedule end to end is not driven. A booking made through the booking submit path is refused by the
// reschedule engine (unsupported_booking): it needs the single-appointment record shape, with the flat app_* keys and a
// service map from the form. A fixture in that shape is still to be written. Reschedule token rules are tested above.
tzfd_rt_skip( 'B13e customer reschedule end to end', 'needs a stored single-appointment record in the engine shape (unsupported_booking for submit-path rows); token rules are tested above' );

/* ------------------------------------------------------------------ C. Reminders and the outbox */

$tzfd_booking_facts = array(
	'company_id' => $tzfd_company, 'verified_company_id' => $tzfd_company, 'authoritative' => true,
	'booking_id' => $tzfd_id, 'starts_at_utc' => $tzfd_expires + 7200, 'status' => 'approved',
	'contact_opt_in' => true, 'recipient_id' => 7,
);
$tzfd_now = $tzfd_expires + 7200 - 3600; // one hour before start, minutes = 60
$tzfd_due = TZFD_Booking_Reminder_Bridge::due( $tzfd_booking_facts, 7, 60, $tzfd_now );
tzfd_rt_ok( 'C1 a reminder is due inside its window for an approved, opted-in booking', is_array( $tzfd_due ) && 'reminder' === $tzfd_due['action'] );
tzfd_rt_ok( 'C2 no reminder when unverified, unapproved, opted out, or outside the window', false === TZFD_Booking_Reminder_Bridge::due( array_merge( $tzfd_booking_facts, array( 'authoritative' => false ) ), 7, 60, $tzfd_now ) &&
	false === TZFD_Booking_Reminder_Bridge::due( array_merge( $tzfd_booking_facts, array( 'status' => 'pending' ) ), 7, 60, $tzfd_now ) &&
	false === TZFD_Booking_Reminder_Bridge::due( array_merge( $tzfd_booking_facts, array( 'contact_opt_in' => false ) ), 7, 60, $tzfd_now ) &&
	false === TZFD_Booking_Reminder_Bridge::due( $tzfd_booking_facts, 7, 60, $tzfd_expires + 7200 - 7200 - 1000 ) &&
	false === TZFD_Booking_Reminder_Bridge::due( $tzfd_booking_facts, 7, 60, $tzfd_expires + 7200 + 1 ) );

$tzfd_outbox = TZFD_Booking_Action_Outbox::table();
tzfd_rt_ok( 'C3 reminder outbox table exists after activation (activation creates it)', TZFD_Booking_Action_Outbox::installed(), 'table=' . $tzfd_outbox );
$tzfd_installed = TZFD_Booking_Action_Outbox::installed() ? true : ( true === TZFD_Booking_Action_Outbox::install() );
tzfd_rt_ok( 'C4 outbox table installs for an administrator (explicit install)', $tzfd_installed, 'table=' . $tzfd_outbox );

if ( $tzfd_installed ) {
	$tzfd_event = 'reminder-v1:rt-' . substr( md5( uniqid( '', true ) ), 0, 16 );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id=%d AND booking_id=%d', $tzfd_outbox, $tzfd_company, $tzfd_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct cleanup of this run's own outbox rows

	$tzfd_unverified = TZFD_Booking_Action_Outbox::enqueue( $tzfd_company, $tzfd_id, 'reminder', 7, $tzfd_event );
	tzfd_rt_ok( 'C5 enqueue refuses a reminder when ownership and consent are not verified', false === $tzfd_unverified, 'result=' . wp_json_encode( $tzfd_unverified ) );

	$tzfd_verify = static function () { return true; };
	add_filter( 'tzfd_booking_verify_owner_v1', $tzfd_verify, 10, 3 );
	add_filter( 'tzfd_booking_verify_recipient_v1', $tzfd_verify, 10, 4 );
	add_filter( 'tzfd_booking_verify_action_v1', $tzfd_verify, 10, 5 );

	$tzfd_first_q = TZFD_Booking_Action_Outbox::enqueue( $tzfd_company, $tzfd_id, 'reminder', 7, $tzfd_event );
	tzfd_rt_ok( 'C6 verified reminder is queued once and not delivered by enqueue', is_array( $tzfd_first_q ) && true === $tzfd_first_q['inserted'] && false === $tzfd_first_q['delivered'] );

	$tzfd_again = TZFD_Booking_Action_Outbox::enqueue( $tzfd_company, $tzfd_id, 'reminder', 7, $tzfd_event );
	$tzfd_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id=%d AND event_hash=%s', $tzfd_outbox, $tzfd_company, hash( 'sha256', $tzfd_company . ':' . $tzfd_id . ':reminder:' . $tzfd_event ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row count, bypasses the object cache
	tzfd_rt_ok( 'C7 repeating the same event is deduplicated (one row)', is_array( $tzfd_again ) && true === $tzfd_again['deduplicated'] && 1 === $tzfd_count, 'rows=' . $tzfd_count );

	$tzfd_other_recipient = TZFD_Booking_Action_Outbox::enqueue( $tzfd_company, $tzfd_id, 'reminder', 8, $tzfd_event );
	tzfd_rt_ok( 'C8 the same event for a different recipient is refused', false === $tzfd_other_recipient );

	$tzfd_bad_action = TZFD_Booking_Action_Outbox::enqueue( $tzfd_company, $tzfd_id, 'send_sms_now', 7, 'reminder-v1:rt-bad-action' );
	tzfd_rt_ok( 'C9 an action type outside the allow-list is refused', false === $tzfd_bad_action );

	remove_filter( 'tzfd_booking_verify_owner_v1', $tzfd_verify, 10 );
	remove_filter( 'tzfd_booking_verify_recipient_v1', $tzfd_verify, 10 );
	remove_filter( 'tzfd_booking_verify_action_v1', $tzfd_verify, 10 );

	$tzfd_queued_again = TZFD_Booking_Reminder_Bridge::queue_due( $tzfd_booking_facts, 7, 60, $tzfd_now );
	tzfd_rt_ok( 'C10 queue_due refuses when the verification filters are removed', false === $tzfd_queued_again );
}
if ( $tzfd_installed ) {
	// C11: dispatch through a local transport stub (filter tzfd_booking_deliver_v1). No provider is called: the stub
	// returns a receipt for the claimed job, or nothing, and records each job it was given. The owner, recipient, action
	// and reminder-current checks are allowed here only for this run and removed afterwards.
	$tzfd_c11_jobs = array();
	$tzfd_c11_mode = 'ack';
	$tzfd_c11_allow = static function () { return true; };
	$tzfd_c11_stub  = static function ( $receipt, $job ) use ( &$tzfd_c11_jobs, &$tzfd_c11_mode ) {
		$tzfd_c11_jobs[] = $job;
		if ( 'ack' !== $tzfd_c11_mode ) { return null; }
		return array( 'ok' => true, 'company_id' => $job['company_id'], 'action' => $job['action'], 'delivery_id' => $job['delivery_id'], 'receipt_id' => 'rt-receipt-' . substr( (string) $job['delivery_id'], 0, 16 ) );
	};
	add_filter( 'tzfd_booking_verify_owner_v1', $tzfd_c11_allow, 10, 3 );
	add_filter( 'tzfd_booking_verify_recipient_v1', $tzfd_c11_allow, 10, 4 );
	add_filter( 'tzfd_booking_verify_action_v1', $tzfd_c11_allow, 10, 5 );
	add_filter( 'tzfd_booking_reminder_current_v1', $tzfd_c11_allow, 10, 5 );
	add_filter( 'tzfd_booking_deliver_v1', $tzfd_c11_stub, 10, 2 );

	$tzfd_c11_hash = hash( 'sha256', $tzfd_company . ':' . $tzfd_id . ':reminder:' . $tzfd_event );
	$tzfd_c11_sent = TZFD_Booking_Action_Outbox::dispatch( 25 );
	$tzfd_c11_row  = $wpdb->get_row( $wpdb->prepare( 'SELECT state, receipt_id FROM %i WHERE company_id=%d AND event_hash=%s', $tzfd_outbox, $tzfd_company, $tzfd_c11_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read after the dispatch worker ran
	$tzfd_c11_seen = array_values( array_filter( $tzfd_c11_jobs, static function ( $job ) use ( $tzfd_c11_hash ) { return isset( $job['delivery_id'] ) && $tzfd_c11_hash === $job['delivery_id']; } ) );
	tzfd_rt_ok( 'C11 a due reminder is handed to the transport once and accepted with its receipt', 1 === count( $tzfd_c11_seen ) && is_array( $tzfd_c11_row ) && 'accepted' === $tzfd_c11_row['state'] && 0 === strpos( (string) $tzfd_c11_row['receipt_id'], 'rt-receipt-' ), 'accepted=' . (int) ( $tzfd_c11_sent['accepted'] ?? 0 ) . ' state=' . ( $tzfd_c11_row['state'] ?? 'none' ) );
	tzfd_rt_ok( 'C11 the job given to the transport carries the company, booking, action and recipient', 1 === count( $tzfd_c11_seen ) && $tzfd_company === $tzfd_c11_seen[0]['company_id'] && $tzfd_id === $tzfd_c11_seen[0]['booking_id'] && 'reminder' === $tzfd_c11_seen[0]['action'] && 7 === $tzfd_c11_seen[0]['recipient_id'] );

	// A transport that returns no receipt must leave the row queued for retry, never accepted.
	$tzfd_c11_fail_event = 'reminder-v1:rt-c11-fail-' . substr( md5( uniqid( '', true ) ), 0, 12 );
	$tzfd_c11_fail_hash  = hash( 'sha256', $tzfd_company . ':' . $tzfd_id . ':reminder:' . $tzfd_c11_fail_event );
	$tzfd_c11_queued     = TZFD_Booking_Action_Outbox::enqueue( $tzfd_company, $tzfd_id, 'reminder', 7, $tzfd_c11_fail_event );
	$tzfd_c11_mode       = 'no_receipt';
	TZFD_Booking_Action_Outbox::dispatch( 25 );
	$tzfd_c11_fail_row = $wpdb->get_row( $wpdb->prepare( 'SELECT state, receipt_id, last_error FROM %i WHERE company_id=%d AND event_hash=%s', $tzfd_outbox, $tzfd_company, $tzfd_c11_fail_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read after the dispatch worker ran
	tzfd_rt_ok( 'C11 a reminder without a transport receipt is not accepted and is queued for retry', is_array( $tzfd_c11_queued ) && is_array( $tzfd_c11_fail_row ) && 'pending' === $tzfd_c11_fail_row['state'] && empty( $tzfd_c11_fail_row['receipt_id'] ) && 'unacknowledged' === $tzfd_c11_fail_row['last_error'] );

	remove_filter( 'tzfd_booking_verify_owner_v1', $tzfd_c11_allow, 10 );
	remove_filter( 'tzfd_booking_verify_recipient_v1', $tzfd_c11_allow, 10 );
	remove_filter( 'tzfd_booking_verify_action_v1', $tzfd_c11_allow, 10 );
	remove_filter( 'tzfd_booking_reminder_current_v1', $tzfd_c11_allow, 10 );
	remove_filter( 'tzfd_booking_deliver_v1', $tzfd_c11_stub, 10 );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id=%d AND event_hash=%s', $tzfd_outbox, $tzfd_company, $tzfd_c11_fail_hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes this run's own retry row
} else {
	tzfd_rt_skip( 'C11 reminder dispatch and delivery', 'outbox table not installed; dispatch has nothing to run' );
}

/* ------------------------------------------------------------------ E. Capability checks (in-process) */

$tzfd_sub_login = 'rtsub' . substr( md5( uniqid( '', true ) ), 0, 8 );
$tzfd_sub_id    = wp_create_user( $tzfd_sub_login, wp_generate_password( 20 ), $tzfd_sub_login . '@example.test' );
if ( is_int( $tzfd_sub_id ) ) {
	$tzfd_sub = new WP_User( $tzfd_sub_id );
	$tzfd_sub->set_role( 'subscriber' );
	wp_set_current_user( $tzfd_sub_id );
	tzfd_rt_ok( 'E1 subscriber is refused by the booking status permission check', false === TZFD_Booking_Status_API::can_read( null ) );
	tzfd_rt_ok( 'E2 subscriber is refused by the resource admin permission check', false === TZFD_Resource_Admin::allowed() );

	$tzfd_rest_req  = new WP_REST_Request( 'GET', '/titan-zero-front-desk/v1/resources/catalog' );
	$tzfd_rest_resp = rest_do_request( $tzfd_rest_req );
	$tzfd_status    = $tzfd_rest_resp->get_status();
	tzfd_rt_ok( 'E3 subscriber REST call to the resource catalog returns 401 or 403', in_array( $tzfd_status, array( 401, 403 ), true ), 'status=' . $tzfd_status );
	wp_delete_user( $tzfd_sub_id );
} else {
	tzfd_rt_skip( 'E1-E3 subscriber capability checks', 'could not create a subscriber user' );
}
wp_set_current_user( $tzfd_admin_id );
tzfd_rt_ok( 'E4 administrator passes the booking status permission check', true === TZFD_Booking_Status_API::can_read( null ), 'if this fails, the tz_fd_access capability is not granted to administrators on activation' );

wp_set_current_user( 0 );
$tzfd_anon = rest_do_request( new WP_REST_Request( 'GET', '/titan-zero-front-desk/v1/bookings/' . $tzfd_id ) )->get_status();
tzfd_rt_ok( 'E5 anonymous REST read of a booking returns 401 or 403', in_array( $tzfd_anon, array( 401, 403 ), true ), 'status=' . $tzfd_anon );
wp_set_current_user( $tzfd_admin_id );

tzfd_rt_skip( 'E6 bad or missing REST nonce on a logged-in request', 'cookie nonce checks run only over HTTP; covered by http-auth-tests.sh' );

/* ------------------------------------------------------------------ F. Uninstall, default (data kept) */

$tzfd_keep_opt = 'tz_fd_rt_keep_' . $tzfd_id;
update_option( $tzfd_keep_opt, 'kept' );
set_transient( 'tz_fd_rt_cache_' . $tzfd_id, 'cache', 3600 );
$tzfd_rows_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d', $tzfd_bookings, $tzfd_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row count, bypasses the object cache
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'titan-zero-trades-front-desk/titan-zero-front-desk.php' ); }
if ( ! defined( 'TZ_DELETE_DATA_ON_UNINSTALL' ) ) {
	require WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/uninstall.php';
	tzfd_rt_ok( 'F1 uninstall without the opt-in keeps the booking row', 1 === $tzfd_rows_before && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d', $tzfd_bookings, $tzfd_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row count, bypasses the object cache
	tzfd_rt_ok( 'F2 uninstall without the opt-in keeps plugin settings', 'kept' === get_option( $tzfd_keep_opt ) );
	// Check the database row, not get_transient(): the object cache would still answer after a direct DELETE.
	tzfd_rt_ok( 'F3 uninstall always clears the plugin cache transient rows', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%tz\\_fd\\_rt\\_cache\\_%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw options row count, as the comment above explains
	delete_option( $tzfd_keep_opt );
} else {
	tzfd_rt_skip( 'F1-F3 uninstall without opt-in', 'TZ_DELETE_DATA_ON_UNINSTALL was already defined in this process' );
}
tzfd_rt_skip( 'F4 uninstall with the opt-in (drops tables)', 'destructive; run in a separate process by run-wp-runtime.sh (uninstall-wipe-check.php)' );

/* ------------------------------------------------------------------ H. Activation without a logged-in administrator */

// Disposable site only. Drop the tables that activation creates, then activate with no current user (as WP-CLI does
// without --user). Activation must recreate all four tables and the call-schema marker, with no failure recorded.
$tzfd_act_tables = array( TZFD_Tables::SLOT_HOLDS, TZFD_Tables::CALLS, TZFD_Tables::CALL_EVENTS, TZFD_Tables::BOOKING_ACTIONS );
foreach ( $tzfd_act_tables as $tzfd_act_bare ) {
	$tzfd_act_full = TZFD_Tables::name( $tzfd_act_bare );
	if ( is_string( $tzfd_act_full ) ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tzfd_act_full ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- disposable test site; recreated by the activation under test
	}
}
delete_option( TZFD_Call_Lifecycle::VERSION_OPTION );
delete_option( TZFD_Call_Lifecycle::FAILURE_OPTION );

wp_set_current_user( 0 );
$tzfd_act_error = '';
try {
	if ( function_exists( 'tz_fd_activate' ) ) {
		ob_start();
		tz_fd_activate();
		ob_end_clean();
	} else {
		$tzfd_act_error = 'tz_fd_activate is not defined';
	}
} catch ( Throwable $e ) {
	if ( ob_get_level() > 0 ) { ob_end_clean(); }
	$tzfd_act_error = $e->getMessage();
}
wp_set_current_user( $tzfd_admin_id );

tzfd_rt_ok( 'H1 activation with no logged-in user completes without an exception', '' === $tzfd_act_error, $tzfd_act_error );
foreach ( $tzfd_act_tables as $tzfd_act_bare ) {
	$tzfd_act_full = TZFD_Tables::name( $tzfd_act_bare );
	$tzfd_act_seen = is_string( $tzfd_act_full ) ? $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tzfd_act_full ) ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
	tzfd_rt_ok( 'H2 activation with no user creates ' . $tzfd_act_bare, is_string( $tzfd_act_seen ) && $tzfd_act_seen === $tzfd_act_full, 'table=' . (string) $tzfd_act_full );
}
tzfd_rt_ok( 'H3 activation with no user records the call schema version and no failure', TZFD_Call_Lifecycle::SCHEMA_VERSION === get_option( TZFD_Call_Lifecycle::VERSION_OPTION, '' ) && '' === get_option( TZFD_Call_Lifecycle::FAILURE_OPTION, '' ) );
tzfd_rt_ok( 'H4 the outbox and slot-hold schema pass their checks after a no-user activation', true === TZFD_Booking_Action_Outbox::create_schema() && TZFD_Booking_Action_Outbox::schema_ready() && true === TZFD_Slot_Holds::create_schema() );
tzfd_rt_ok( 'H5 the call schema step is idempotent when run again with no user', true === TZFD_Call_Lifecycle::run_install() );
wp_set_current_user( 0 );
$tzfd_h6 = is_wp_error( TZFD_Slot_Holds::install() ) && false === TZFD_Booking_Action_Outbox::install() && is_wp_error( TZFD_Call_Lifecycle::install( true ) );
wp_set_current_user( $tzfd_admin_id );
tzfd_rt_ok( 'H6 request install still refuses without an administrator', $tzfd_h6 );

/* ------------------------------------------------------------------ G. Multisite */

if ( is_multisite() ) {
	tzfd_rt_ok( 'G1 multisite install detected', true );
} else {
	tzfd_rt_skip( 'G1 multisite', 'single-site install; multisite needs provision-wp-site.sh --multisite' );
}

/* ------------------------------------------------------------------ H. Weekly AI FAQ suggestions (#35) */

update_option( TZFD_Ai_Faq_Suggestions::ENABLE_OPTION, 1 );
TZFD_Ai_Faq_Suggestions::register();
tzfd_rt_ok( 'H1 weekly FAQ job is scheduled once, weekly', wp_next_scheduled( TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ) !== false && wp_get_schedule( TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ) === 'weekly' );
tzfd_rt_ok( 'H2 weekly job is not duplicated by re-registration', count( array_filter( _get_cron_array() ?: array(), static function ( $events ) { return isset( $events[ TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ] ); } ) ) === 1 );
tzfd_rt_ok( 'H3 review decision handler is registered on admin_post (capability and nonce are checked inside the handler)', has_action( 'admin_post_' . TZFD_Ai_Faq_Suggestions::ACTION ) !== false );
tzfd_rt_ok( 'H4 knowledge publish invalidates the public answer cache', has_action( TZFD_Ai_Knowledge_Builder::CHANGED_ACTION, array( 'TZFD_AI_Answer_Box', 'invalidate' ) ) !== false );
$before_v = TZFD_AI_Answer_Box::knowledge_version();
do_action( TZFD_Ai_Knowledge_Builder::CHANGED_ACTION, 1, 1 );
tzfd_rt_ok( 'H5 a knowledge change bumps the cached answer version', TZFD_AI_Answer_Box::knowledge_version() === $before_v + 1 );
if ( TZFD_Ai_Free_Core::consent_granted() ) {
	tzfd_rt_skip( 'H6 no hosted call without consent', 'consent is already granted on this site; the unit suite covers the refusal' );
} else {
	tzfd_rt_ok( 'H6 no hosted call without consent (weekly job returns nothing)', TZFD_Ai_Faq_Suggestions::run_scheduled( time() ) === array() );
}

echo "\nSummary (wp-runtime-tests): " . (int) $GLOBALS['tzfd_rt']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_rt']['FAIL'] . ' failed, ' . (int) $GLOBALS['tzfd_rt']['GAP'] . ' gap(s), ' . (int) $GLOBALS['tzfd_rt']['SKIP'] . " skipped\n";
exit( (int) $GLOBALS['tzfd_rt']['FAIL'] > 0 ? 1 : 0 );
