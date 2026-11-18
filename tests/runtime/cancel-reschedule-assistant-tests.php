<?php
/**
 * WordPress integration checks for the cancel/reschedule assistant (issue #25).
 * Run: wp --allow-root eval-file cancel-reschedule-assistant-tests.php   (not shipped in the plugin)
 * Uses the real wp_salt('auth') secret and the real $wpdb, against the disposable test site only.
 */
// Path from WordPress itself, so it matches the file the plugin loads (issue #191: was a hard-coded server path).
$tzfd_local_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-scoped-store.php', 'class-tzfd-customer-cancellation.php', 'class-tzfd-cancel-reschedule-assistant.php' ) as $tzfd_local_file ) {
    require_once $tzfd_local_inc . $tzfd_local_file;
}
global $wpdb;
$GLOBALS['ra_pass'] = 0; $GLOBALS['ra_fail'] = 0;
function ra_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['ra_pass']++; } else { $GLOBALS['ra_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

$A      = 'TZFD_Cancel_Reschedule_Assistant';
$C      = 'TZFD_Customer_Cancellation';
wp_set_current_user( 1 );
$company = (int) TZFD_Scoped_Store::company_id();
ra_check( 'company resolves for admin', $company > 0, 'company=' . $company );

$secret  = wp_salt( 'auth' );
$email   = 'ra-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
$booking = 987654321; // Not a real row on this site.
$table   = $wpdb->prefix . TZFD_Tables::BOOKINGS;

// Nonexistent booking: the real DB lookup returns nothing, so the assistant refuses.
$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, formid, notifyto, posted_data, data FROM $table WHERE id = %d", $booking ) );
ra_check( 'nonexistent booking has no row', null === $row );
ra_check( 'nonexistent booking is refused', $A::decide( 'cancel', array( 'booking_found' => false, 'booking_company_id' => $company, 'request_company_id' => $company, 'ownership_verified' => false ) ) === 'refuse' );

// Build a real signed link for a synthetic booking version and verify through the assistant.
$version = $C::row_version( (object) array( 'posted_data' => 'v1', 'data' => 'd1', 'notifyto' => $email, 'formid' => 1 ) );
$expires = $C::new_expiry( time() );
$token   = $C::create_token( $booking, $email, $secret, $version, $expires );
ra_check( 'real secret yields a 64-char token', 64 === strlen( $token ) );

$ctx = array( 'booking_id' => $booking, 'email' => $email, 'token' => $token, 'secret' => $secret, 'version' => $version, 'expires' => $expires );
ra_check( 'valid token verifies', $A::ownership_verified( $ctx ) );
ra_check( 'expired token refused', ! $A::ownership_verified( array_merge( $ctx, array( 'now' => $expires + 1 ) ) ) );
ra_check( 'token refused for another booking (IDOR)', ! $A::ownership_verified( array_merge( $ctx, array( 'booking_id' => $booking + 1 ) ) ) );
ra_check( 'phone number alone does not verify', ! $A::ownership_verified( array( 'booking_id' => $booking, 'caller_phone' => '+61400000000', 'secret' => $secret, 'version' => $version, 'expires' => $expires ) ) );

// Double change: after a real change the stored version differs and the old link stops working.
$changed = $C::row_version( (object) array( 'posted_data' => 'v2', 'data' => 'd2', 'notifyto' => $email, 'formid' => 1 ) );
ra_check( 'old link refused after a change', ! $A::ownership_verified( array_merge( $ctx, array( 'version' => $changed ) ) ) );

// Tenant isolation with the real company id: a different company is refused even with a valid link.
$other = $company + 1000;
ra_check( 'cross-tenant request refused with valid link', $A::decide( 'reschedule', array( 'booking_found' => true, 'booking_company_id' => $company, 'request_company_id' => $other, 'ownership_verified' => true ) ) === 'refuse' );
ra_check( 'verified same-tenant cancel routes to cancel link', $A::decide( 'cancel', array( 'booking_found' => true, 'booking_company_id' => $company, 'request_company_id' => $company, 'ownership_verified' => true ) ) === 'send_cancel_link' );

// Classification with a real call-to-action message.
ra_check( 'classify cancel message', $A::classify( 'Please cancel my appointment tomorrow' ) === 'cancel' );
ra_check( 'classify reschedule message', $A::classify( 'Can we reschedule to Friday?' ) === 'reschedule' );

echo "\nSummary: {$GLOBALS['ra_pass']} passed, {$GLOBALS['ra_fail']} failed\n";
