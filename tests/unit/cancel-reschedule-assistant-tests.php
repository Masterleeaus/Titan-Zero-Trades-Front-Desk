<?php
/**
 * Unit checks for TZFD_Cancel_Reschedule_Assistant (no WordPress needed).
 * Run: php tests/unit/cancel-reschedule-assistant-tests.php
 * Outside the plugin folder, so the WordPress.org package does not include it.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-cancel-reschedule-assistant.php';

$failures = 0;
$passes   = 0;
function tzfd_assert( $label, $ok ) {
    global $failures, $passes;
    if ( $ok ) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n";
}

$A      = 'TZFD_Cancel_Reschedule_Assistant';
$C      = 'TZFD_Customer_Cancellation';
$secret = str_repeat( 's', 32 );
$email  = 'customer@example.test';
$ver1   = hash( 'sha256', 'version-1' );
$ver2   = hash( 'sha256', 'version-2' );
$now    = 1800000000;
$exp    = $now + 3600;

// Classification.
tzfd_assert( 'cancel intent', $A::classify( 'Please cancel my booking' ) === 'cancel' );
tzfd_assert( 'reschedule intent', $A::classify( 'Can I reschedule to another day?' ) === 'reschedule' );
tzfd_assert( 'cancel and rebook is uncertain', $A::classify( 'cancel and rebook for Friday' ) === 'uncertain' );
tzfd_assert( 'empty is none', $A::classify( '   ' ) === 'none' );
tzfd_assert( 'non-string is none', $A::classify( array( 'cancel' ) ) === 'none' );
tzfd_assert( 'oversized is uncertain', $A::classify( str_repeat( 'cancel ', 300 ) ) === 'uncertain' );
tzfd_assert( 'unrelated is none', $A::classify( 'What are your opening hours?' ) === 'none' );

// Token ownership, expiry, IDOR and version (double-change) invalidation.
$token = $C::create_token( 42, $email, $secret, $ver1, $exp );
tzfd_assert( 'valid token verifies', $C::verify_token( 42, $email, $token, $secret, $ver1, $exp, $now ) );
tzfd_assert( 'expired token refused', ! $C::verify_token( 42, $email, $token, $secret, $ver1, $exp, $exp + 1 ) );
tzfd_assert( 'token for booking 42 refused on 43 (IDOR)', ! $C::verify_token( 43, $email, $token, $secret, $ver1, $exp, $now ) );
tzfd_assert( 'token refused for other email', ! $C::verify_token( 42, 'other@example.test', $token, $secret, $ver1, $exp, $now ) );
tzfd_assert( 'token refused with wrong secret', ! $C::verify_token( 42, $email, $token, str_repeat( 'x', 32 ), $ver1, $exp, $now ) );
tzfd_assert( 'nonexistent booking id normalises to 0', 0 === $C::normalize_booking_id( '0' ) && 0 === $C::normalize_booking_id( 'abc' ) );
tzfd_assert( 'token with no expiry refused', ! $C::verify_token( 42, $email, $token, $secret, $ver1, 0, $now ) );

// Double change: a stored change alters row_version, so an older link stops working.
$before = (object) array( 'posted_data' => 'a', 'data' => 'b', 'notifyto' => $email, 'formid' => 7 );
$after  = (object) array( 'posted_data' => 'a2', 'data' => 'b2', 'notifyto' => $email, 'formid' => 7 );
tzfd_assert( 'row version changes after a change', $C::row_version( $before ) !== $C::row_version( $after ) );
$v_before = $C::row_version( $before );
$stale    = $C::create_token( 42, $email, $secret, $v_before, $exp );
tzfd_assert( 'link from before the change is refused after it', ! $C::verify_token( 42, $email, $stale, $secret, $C::row_version( $after ), $exp, $now ) );

// DST: expiry is an absolute UTC timestamp, so a link issued before an Australian
// DST change (Melbourne 2026-10-04 02:00 AEST -> 03:00 AEDT) still expires at the same instant.
$tz   = new DateTimeZone( 'Australia/Melbourne' );
$pre  = new DateTime( '2026-10-04 01:30:00', $tz );
$post = new DateTime( '2026-10-04 03:30:00', $tz );
$issued = $pre->getTimestamp();
$ttl_expiry = $C::new_expiry( $issued );
tzfd_assert( 'DST gap: expiry is 14 days of absolute time', $ttl_expiry - $issued === 1209600 );
$dst_token = $C::create_token( 42, $email, $secret, $ver1, $ttl_expiry );
tzfd_assert( 'DST gap: token valid just before expiry', $C::verify_token( 42, $email, $dst_token, $secret, $ver1, $ttl_expiry, $post->getTimestamp() ) );
tzfd_assert( 'DST gap: token refused after expiry', ! $C::verify_token( 42, $email, $dst_token, $secret, $ver1, $ttl_expiry, $ttl_expiry + 1 ) );

// Decisions: ownership, tenant isolation, nonexistent bookings, uncertain handoff.
$base = array( 'booking_found' => true, 'booking_company_id' => 5, 'request_company_id' => 5, 'ownership_verified' => true );
tzfd_assert( 'verified cancel sends to cancel link', $A::decide( 'cancel', $base ) === 'send_cancel_link' );
tzfd_assert( 'verified reschedule sends to reschedule link', $A::decide( 'reschedule', $base ) === 'send_reschedule_link' );
tzfd_assert( 'unverified cancel asks for a verified link', $A::decide( 'cancel', array_merge( $base, array( 'ownership_verified' => false ) ) ) === 'request_verified_link' );
tzfd_assert( 'cross-tenant refused', $A::decide( 'cancel', array_merge( $base, array( 'request_company_id' => 9 ) ) ) === 'refuse' );
tzfd_assert( 'cross-tenant refused even if verified', $A::decide( 'reschedule', array_merge( $base, array( 'request_company_id' => 9 ) ) ) === 'refuse' );
tzfd_assert( 'nonexistent booking refused', $A::decide( 'cancel', array_merge( $base, array( 'booking_found' => false ) ) ) === 'refuse' );
tzfd_assert( 'company zero refused', $A::decide( 'cancel', array_merge( $base, array( 'booking_company_id' => 0, 'request_company_id' => 0 ) ) ) === 'refuse' );
tzfd_assert( 'uncertain hands off', $A::decide( 'uncertain', $base ) === 'handoff_to_staff' );
tzfd_assert( 'none hands off', $A::decide( 'none', $base ) === 'handoff_to_staff' );
tzfd_assert( 'decisions never return a change action', ! in_array( $A::decide( 'cancel', $base ), array( 'cancelled', 'rescheduled' ), true ) );
// A model-proposed intent this code does not know must never reach a link, even with verified ownership.
tzfd_assert( 'unknown model intent hands off', $A::decide( 'refund', $base ) === 'handoff_to_staff' );
tzfd_assert( 'guessed booking action hands off', $A::decide( 'book', array_merge( $base, array( 'ownership_verified' => true ) ) ) === 'handoff_to_staff' );
tzfd_assert( 'empty intent hands off', $A::decide( '', $base ) === 'handoff_to_staff' );

// Audit receipts: invalid events are refused before any database write.
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-local-audit.php';
$L    = 'TZFD_Local_Audit';
$good = array( 'action' => 'booking.cancel_link_sent', 'entity' => 'booking', 'entity_id' => 42, 'summary' => 'Cancel link sent' );
tzfd_assert( 'audit refuses company 0', false === $L::insert( 0, 1, 'user', $good ) );
tzfd_assert( 'audit refuses unknown actor type', false === $L::insert( 1, 1, 'customer', $good ) );
tzfd_assert( 'audit refuses bad action name', false === $L::insert( 1, 1, 'system', array_merge( $good, array( 'action' => 'Cancel Booking!' ) ) ) );
tzfd_assert( 'audit refuses zero entity id', false === $L::insert( 1, 1, 'system', array_merge( $good, array( 'entity_id' => 0 ) ) ) );
tzfd_assert( 'audit refuses numeric-string entity id', false === $L::insert( 1, 1, 'system', array_merge( $good, array( 'entity_id' => '42' ) ) ) );
tzfd_assert( 'audit refuses control characters in summary', false === $L::insert( 1, 1, 'system', array_merge( $good, array( 'summary' => "ok\ninjected" ) ) ) );
tzfd_assert( 'audit refuses summary over 250 bytes', false === $L::insert( 1, 1, 'system', array_merge( $good, array( 'summary' => str_repeat( 'a', 251 ) ) ) ) );
tzfd_assert( 'audit refuses missing key', false === $L::insert( 1, 1, 'system', array( 'action' => 'booking.cancel_link_sent' ) ) );

// Phone number never authorises: ownership_verified ignores caller_phone and needs a valid token.
tzfd_assert( 'phone alone does not verify', ! $A::ownership_verified( array( 'booking_id' => 42, 'caller_phone' => '+61400000000', 'secret' => $secret, 'version' => $ver1, 'expires' => $exp, 'now' => $now ) ) );
tzfd_assert( 'valid token verifies through assistant', $A::ownership_verified( array( 'booking_id' => 42, 'email' => $email, 'token' => $token, 'secret' => $secret, 'version' => $ver1, 'expires' => $exp, 'now' => $now ) ) );

// Policy text: fixed template, invalid numbers fall back to contact wording.
$text = $A::policy_summary( array( 'cancel_notice_hours' => 24, 'reschedule_notice_hours' => 12 ) );
tzfd_assert( 'policy text uses validated hours', false !== strpos( $text, '12 hours' ) && false !== strpos( $text, '24 hours' ) );
tzfd_assert( 'policy rejects negative hours', false === strpos( $A::policy_summary( array( 'cancel_notice_hours' => -1, 'reschedule_notice_hours' => 1 ) ), 'hours' ) );
tzfd_assert( 'policy rejects string hours', false === strpos( $A::policy_summary( array( 'cancel_notice_hours' => '24', 'reschedule_notice_hours' => 1 ) ), 'hours' ) );

echo "Passed: {$passes}, Failed: {$failures}\n";
exit( $failures ? 1 : 0 );
