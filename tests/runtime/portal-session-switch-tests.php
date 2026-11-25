<?php
/**
 * Runtime checks for client portal session switching and session-scoped exposure (issue #334).
 *
 * Run inside a disposable WordPress site, via WP-CLI:
 *   wp eval-file tests/runtime/portal-session-switch-tests.php --user=1
 * Driven by tests/runtime/run-wp-runtime.sh. Not shipped in the plugin.
 *
 * Output: one line per check, "PASS|FAIL name -- detail". Exit 1 when any check fails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_ps'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_ps_check( $name, $ok, $detail = '' ) {
	$GLOBALS['tzfd_ps'][ $ok ? 'PASS' : 'FAIL' ]++;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

if ( ! class_exists( 'TZFD_Client_Portal' ) || ! class_exists( 'TZFD_Scoped_Store' ) ) {
	echo "FAIL portal-session -- client portal classes not loaded\n";
	exit( 1 );
}

wp_set_current_user( 1 );
$tzfd_ps_company = (int) TZFD_Scoped_Store::company_id();
tzfd_ps_check( 'P0 company resolves for the admin', $tzfd_ps_company > 0, 'company=' . $tzfd_ps_company );

$tzfd_ps_a = 'ClientA' . substr( md5( uniqid( '', true ) ), 0, 20 );   // letters and digits, valid token shape
$tzfd_ps_b = 'ClientB' . substr( md5( uniqid( '', true ) ), 0, 20 );
$tzfd_ps_c = 'ClientC' . substr( md5( uniqid( '', true ) ), 0, 20 );
$tzfd_ps_email_a = 'portal-a-' . substr( md5( $tzfd_ps_a ), 0, 8 ) . '@example.test';
$tzfd_ps_email_b = 'portal-b-' . substr( md5( $tzfd_ps_b ), 0, 8 ) . '@example.test';
$tzfd_ps_cookie  = TZFD_Client_Portal::COOKIE;

$tzfd_ps_store = function ( $token, $identity ) {
	set_transient( TZFD_Client_Portal::token_key( TZFD_Client_Portal::SESSION_PREFIX, $token ), $identity, TZFD_Client_Portal::SESSION_TTL );
};
$tzfd_ps_read_as = function ( $token ) use ( $tzfd_ps_cookie ) {
	$_COOKIE[ $tzfd_ps_cookie ] = $token;
	return TZFD_Client_Portal::session_email();
};

$tzfd_ps_store( $tzfd_ps_a, array( 'email' => $tzfd_ps_email_a, 'company_id' => $tzfd_ps_company ) );
tzfd_ps_check( 'P1 a session for this company returns its client email', $tzfd_ps_read_as( $tzfd_ps_a ) === $tzfd_ps_email_a );

// P2: a session stored for another company is refused, even with the right token.
$tzfd_ps_store( $tzfd_ps_c, array( 'email' => $tzfd_ps_email_b, 'company_id' => $tzfd_ps_company + 999 ) );
tzfd_ps_check( 'P2 a session issued for another company returns no email', '' === $tzfd_ps_read_as( $tzfd_ps_c ) );

// P3: an email-only (legacy) session is refused.
$tzfd_ps_legacy = 'LegacyD' . substr( md5( uniqid( '', true ) ), 0, 20 );
$tzfd_ps_store( $tzfd_ps_legacy, $tzfd_ps_email_a );
tzfd_ps_check( 'P3 a legacy email-only session returns no email', '' === $tzfd_ps_read_as( $tzfd_ps_legacy ) );

// P4: malformed and unknown cookies return nothing.
tzfd_ps_check( 'P4 a short cookie value is refused without a lookup', '' === $tzfd_ps_read_as( 'short' ) );
tzfd_ps_check( 'P4 a cookie with symbols is refused', '' === $tzfd_ps_read_as( 'abc-def_ghi.jkl' ) );
tzfd_ps_check( 'P4 an unknown well-formed token returns no email', '' === $tzfd_ps_read_as( 'NoSuchSession' . substr( md5( uniqid( '', true ) ), 0, 20 ) ) );

// P5: switching sessions ends the previous session in this browser.
$tzfd_ps_store( $tzfd_ps_b, array( 'email' => $tzfd_ps_email_b, 'company_id' => $tzfd_ps_company ) );
$tzfd_ps_new_b = TZFD_Client_Portal::open_session( array( 'email' => $tzfd_ps_email_b, 'company_id' => $tzfd_ps_company ), $tzfd_ps_a );
tzfd_ps_check( 'P5 opening a session for client B ends client A session in the same browser', '' === $tzfd_ps_read_as( $tzfd_ps_a ) );
tzfd_ps_check( 'P5 the new session is for client B only', $tzfd_ps_read_as( $tzfd_ps_new_b ) === $tzfd_ps_email_b );
tzfd_ps_check( 'P5 the new session token is not the old one', $tzfd_ps_new_b !== $tzfd_ps_a && TZFD_Client_Portal::is_token( $tzfd_ps_new_b ) );

// P6: a stale or forged previous cookie does not delete anything else.
$tzfd_ps_other = 'OtherE' . substr( md5( uniqid( '', true ) ), 0, 20 );
$tzfd_ps_store( $tzfd_ps_other, array( 'email' => $tzfd_ps_email_a, 'company_id' => $tzfd_ps_company ) );
TZFD_Client_Portal::open_session( array( 'email' => $tzfd_ps_email_b, 'company_id' => $tzfd_ps_company ), 'not-a-token' );
tzfd_ps_check( 'P6 a malformed previous cookie leaves other sessions alone', $tzfd_ps_read_as( $tzfd_ps_other ) === $tzfd_ps_email_a );
TZFD_Client_Portal::open_session( array( 'email' => $tzfd_ps_email_b, 'company_id' => $tzfd_ps_company ), '' );
tzfd_ps_check( 'P6 an empty previous cookie leaves other sessions alone', $tzfd_ps_read_as( $tzfd_ps_other ) === $tzfd_ps_email_a );

// P7: sign-out ends the session (the logout handler deletes the same transient).
delete_transient( TZFD_Client_Portal::token_key( TZFD_Client_Portal::SESSION_PREFIX, $tzfd_ps_new_b ) );
tzfd_ps_check( 'P7 after sign-out the client B session returns no email', '' === $tzfd_ps_read_as( $tzfd_ps_new_b ) );

// P8: a session for one client never returns another client's bookings. The portal reads only session_email().
$tzfd_ps_session_a = TZFD_Client_Portal::open_session( array( 'email' => $tzfd_ps_email_a, 'company_id' => $tzfd_ps_company ) );
$tzfd_ps_email_seen = $tzfd_ps_read_as( $tzfd_ps_session_a );
$tzfd_ps_records = TZFD_Client_Portal::records_for( $tzfd_ps_email_seen );
tzfd_ps_check( 'P8 records for the session are scoped to the session client', $tzfd_ps_email_seen === $tzfd_ps_email_a && is_array( $tzfd_ps_records['bookings'] ) );

// P9/P10: customer record exposure. Each client sees only their own booking rows, never another client's.
global $wpdb;
$tzfd_ps_table  = $wpdb->prefix . TZFD_Bookings::TABLE;
$tzfd_ps_forms  = $wpdb->prefix . TZFD_Tables::FORMS;
$tzfd_ps_form   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE company_id=%d ORDER BY id LIMIT 1', $tzfd_ps_forms, $tzfd_ps_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup lookup
$tzfd_ps_when   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
$tzfd_ps_ids    = array();
foreach ( array( $tzfd_ps_email_a, $tzfd_ps_email_b ) as $tzfd_ps_mail ) {
	$tzfd_ps_posted = serialize( array( 'name' => 'Portal ' . $tzfd_ps_mail, 'email' => $tzfd_ps_mail, 'apps' => array( array( 'date' => $tzfd_ps_when, 'slot' => '', 'service' => 'Visit' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- plugin's own stored format
	$wpdb->insert( $tzfd_ps_table, array( 'formid' => $tzfd_ps_form, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => $tzfd_ps_mail, 'posted_data' => $tzfd_ps_posted, 'data' => 'PORTAL', 'whoadded' => '1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture row
	$tzfd_ps_ids[ $tzfd_ps_mail ] = (int) $wpdb->insert_id;
}
$tzfd_ps_ids_a = wp_list_pluck( TZFD_Client_Portal::records_for( $tzfd_ps_email_a )['bookings'], 'booking_id' );
$tzfd_ps_ids_b = wp_list_pluck( TZFD_Client_Portal::records_for( $tzfd_ps_email_b )['bookings'], 'booking_id' );
tzfd_ps_check( 'P9 client A sees their own booking', in_array( $tzfd_ps_ids[ $tzfd_ps_email_a ], array_map( 'intval', $tzfd_ps_ids_a ), true ), 'ids=' . implode( ',', $tzfd_ps_ids_a ) );
tzfd_ps_check( 'P10 client A does not see client B booking', ! in_array( $tzfd_ps_ids[ $tzfd_ps_email_b ], array_map( 'intval', $tzfd_ps_ids_a ), true ) );
tzfd_ps_check( 'P10 client B sees their own booking and not client A', in_array( $tzfd_ps_ids[ $tzfd_ps_email_b ], array_map( 'intval', $tzfd_ps_ids_b ), true ) && ! in_array( $tzfd_ps_ids[ $tzfd_ps_email_a ], array_map( 'intval', $tzfd_ps_ids_b ), true ) );
foreach ( $tzfd_ps_ids as $tzfd_ps_row_id ) {
	$wpdb->delete( $tzfd_ps_table, array( 'id' => $tzfd_ps_row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes this test's fixture rows
}

unset( $_COOKIE[ $tzfd_ps_cookie ] );
echo "\nSummary (portal-session-switch-tests): " . (int) $GLOBALS['tzfd_ps']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_ps']['FAIL'] . " failed\n";
exit( $GLOBALS['tzfd_ps']['FAIL'] > 0 ? 1 : 0 );
