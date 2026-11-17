<?php
/**
 * Unit tests for the cancellation/rescheduling assistant (#25) and human handoff (#27).
 * Run: php tests/unit/run-change-and-handoff-tests.php   (exit code 0 means all passed)
 * Pure PHP, no WordPress. WordPress wiring functions are not exercised here.
 */
define( 'ABSPATH', __DIR__ . '/' );
$plugin = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/';
require_once $plugin . 'class-tzfd-ai-change-assistant.php';
require_once $plugin . 'class-tzfd-ai-human-handoff.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;
function t_check( $name, $ok ) {
	if ( $ok ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail']++; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}

$A = 'TZFD_AI_Change_Assistant';
$H = 'TZFD_AI_Human_Handoff';

// ---------- #25 classification and policy ----------
t_check( 'cancel wording classifies as cancel', $A::classify( 'I need to cancel my booking tomorrow' )['intent'] === 'cancel' );
t_check( 'reschedule wording classifies as reschedule', $A::classify( 'Can I reschedule to Friday?' )['intent'] === 'reschedule' );
t_check( 'apostrophe form is normalised', $A::classify( "I don't need it anymore, please cancel" )['intent'] === 'cancel' );
t_check( 'both cancel and reschedule is unclear with low confidence', $A::classify( 'cancel or reschedule, not sure' )['intent'] === 'unclear' && $A::classify( 'cancel or reschedule, not sure' )['confidence'] < 0.6 );
t_check( 'small talk is other', $A::classify( 'what time do you open?' )['intent'] === 'other' );
t_check( 'refund wording is flagged policy-sensitive', $A::classify( 'I want a refund for the cancelled visit' )['policy_sensitive'] === true );
t_check( 'non-string input does not throw', $A::classify( array( 'x' ) )['intent'] === 'other' );

// Model output is untrusted: only the closed intent list survives.
t_check( 'model hint with extra action is reduced to a valid intent', $A::sanitize_model_hint( array( 'intent' => 'cancel', 'action' => 'delete_booking', 'booking_id' => 5 ) ) === 'cancel' );
t_check( 'model hint with unknown intent is other', $A::sanitize_model_hint( array( 'intent' => 'cancel_booking_7' ) ) === 'other' );
t_check( 'model hint that is not an array is other', $A::sanitize_model_hint( 'cancel' ) === 'other' );

// ---------- #25 respond: phone alone never authorises ----------
$r = $A::respond( 'please cancel my booking', array( 'caller_phone' => '+61400111222', 'verified' => false ) );
t_check( 'phone-only caller gets self-service redirect, not a change', $r['action'] === 'self_service' && $r['reason'] === 'unverified_link_required' );
t_check( 'phone-only reply names no booking data', false === strpos( $r['message'], '+614' ) && false === strpos( $r['message'], 'booking #' ) );
t_check( 'respond never returns an execute action', ! in_array( $r['action'], array( 'cancel', 'reschedule', 'execute' ), true ) );
t_check( 'policy-sensitive request is handed off', $A::respond( 'refund please, I was charged' )['action'] === 'handoff' );
t_check( 'unclear request is handed off', $A::respond( 'cancel or reschedule' )['reason'] === 'low_confidence' );
t_check( 'general question stays general', $A::respond( 'do you do weekends?' )['action'] === 'general' );

// ---------- #25 notice window, DST-safe (absolute time) ----------
// Melbourne: DST ends 2026-04-05 (03:00 AEDT -> 02:00 AEST) and starts 2026-10-04 (02:00 AEST -> 03:00 AEDT).
$start_utc_1 = gmmktime( 0, 0, 0, 10, 4, 2026 ); // 2026-10-04 11:00 AEDT
$now_utc_1   = gmmktime( 0, 30, 0, 10, 3, 2026 ); // 2026-10-03 10:30 AEST (24.5 wall-clock hours before, 23.5 elapsed)
t_check( 'local 11:00 after DST start resolves to correct UTC', $A::local_start_epoch( '2026-10-04 11:00:00', 'Australia/Melbourne' ) === $start_utc_1 );
t_check( 'DST-crossing 24h notice uses elapsed time and refuses at 23.5 elapsed hours', false === $A::notice_window_open( $start_utc_1, $now_utc_1, 24 ) );
t_check( 'exactly 24 elapsed hours before start is still allowed', true === $A::notice_window_open( $start_utc_1, $start_utc_1 - 86400, 24 ) );
$gap = $A::local_start_epoch( '2026-10-04 02:30:00', 'Australia/Melbourne' ); // Non-existent local time in the spring gap.
t_check( 'spring-gap local time resolves without error', $gap > 0 );
$fold = $A::local_start_epoch( '2026-04-05 02:30:00', 'Australia/Melbourne' ); // Ambiguous local time in the autumn fold.
t_check( 'autumn-fold local time resolves deterministically to the later (AEST) occurrence', $fold === gmmktime( 16, 30, 0, 4, 4, 2026 ) );
t_check( 'invalid timezone fails closed to 0', 0 === $A::local_start_epoch( '2026-10-04 11:00:00', 'Mars/Olympus' ) );
t_check( 'notice window refuses zero or negative start', false === $A::notice_window_open( 0, time(), 24 ) );

$verified_late = $A::respond( 'I want to cancel', array( 'verified' => true, 'start_epoch' => $start_utc_1, 'now' => $now_utc_1, 'notice_hours' => 24 ) );
t_check( 'verified request inside notice window is handed off', $verified_late['action'] === 'handoff' && $verified_late['reason'] === 'inside_notice_window' );
$verified_ok = $A::respond( 'I want to cancel', array( 'verified' => true, 'start_epoch' => $start_utc_1, 'now' => $start_utc_1 - 3 * 86400, 'notice_hours' => 24 ) );
t_check( 'verified request outside notice window goes to self-service', $verified_ok['action'] === 'self_service' && $verified_ok['reason'] === 'verified_self_service' );

// ---------- #25 ownership: expired, IDOR, nonexistent, double-change, forged ----------
$secret  = 'test-secret-not-real';
$now     = 1800000000;
$expires = $now + TZFD_Customer_Cancellation::TOKEN_TTL;
$row     = (object) array( 'id' => 7, 'company_id' => 3, 'notifyto' => 'jo@example.com', 'formid' => 2, 'posted_data' => 'a:1:{s:1:"x";s:1:"y";}', 'data' => 'BOOKING', );
$version = TZFD_Customer_Cancellation::row_version( $row );
$token   = TZFD_Customer_Cancellation::create_token( 7, 'jo@example.com', $secret, $version, $expires );

t_check( 'valid link verifies for its own tenant', $A::verify_ownership( $row, 3, $token, $version, $expires, $secret, $now )['ok'] === true );
t_check( 'expired link is refused', $A::verify_ownership( $row, 3, $token, $version, $expires, $secret, $expires + 1 )['ok'] === false );
t_check( 'cross-tenant request (IDOR) is refused', $A::verify_ownership( $row, 4, $token, $version, $expires, $secret, $now )['ok'] === false );
t_check( 'nonexistent booking (null row) is refused', $A::verify_ownership( null, 3, $token, $version, $expires, $secret, $now )['ok'] === false );
t_check( 'empty token (phone-only identity) is refused', $A::verify_ownership( $row, 3, '', $version, $expires, $secret, $now )['ok'] === false );
t_check( 'forged token is refused', $A::verify_ownership( $row, 3, str_repeat( 'a', 64 ), $version, $expires, $secret, $now )['ok'] === false );
t_check( 'wrong secret is refused', $A::verify_ownership( $row, 3, $token, 'other-secret', $expires, $secret, $now ) ['ok'] === false );

$other = clone $row;
$other->notifyto = 'mallory@example.com';
t_check( 'link issued for another customer does not open this booking', $A::verify_ownership( $other, 3, $token, $version, $expires, $secret, $now )['ok'] === false );

$cancelled = clone $row;
$cancelled->posted_data = 'a:1:{s:1:"x";s:9:"cancelled";}';
t_check( 'link is stale after a change (double-change guard)', $A::verify_ownership( $cancelled, 3, $token, $version, $expires, $secret, $now )['ok'] === false );
t_check( 'tenant id of zero is refused', $A::verify_ownership( $row, 0, $token, $version, $expires, $secret, $now )['ok'] === false );

// ---------- #27 handoff ----------
$base = array( 'text' => 'I am not sure if my visit is still on, can someone check?', 'intent' => 'unclear', 'confidence' => 0.3,
	'reason' => 'low_confidence', 'name' => 'Jo', 'email' => 'jo@example.com', 'consent' => true );
$ready = $H::build( $base, 'disabled' );
t_check( 'low confidence with consent and contact is ready', $ready['status'] === 'ready' );
t_check( 'low confidence is flagged below threshold', $ready['enquiry']['below_threshold'] === true );
t_check( 'structured enquiry carries source, intent, confidence, summary and reason', $ready['enquiry']['source'] === 'ai_handoff' && $ready['enquiry']['intent'] === 'unclear' && $ready['enquiry']['confidence'] === 0.3 && '' !== $ready['enquiry']['summary'] && $ready['enquiry']['reason'] === 'low_confidence' );

$no_consent = $H::build( array_merge( $base, array( 'consent' => false ) ) );
t_check( 'no customer consent stores nothing', $no_consent['status'] === 'needs_consent' && null === $no_consent['enquiry'] );
t_check( 'consent given as a string is not accepted', $H::build( array_merge( $base, array( 'consent' => 'yes' ) ) )['status'] === 'needs_consent' );

$no_contact = $H::build( array_merge( $base, array( 'email' => 'not-an-email', 'phone' => '12' ) ) );
t_check( 'no valid contact details asks for a contact form', $no_contact['status'] === 'needs_contact' );

$phone_only = $H::build( array_merge( $base, array( 'email' => '', 'phone' => '+61 400 111 222' ) ) );
t_check( 'phone-only contact is accepted', $phone_only['status'] === 'ready' && $phone_only['enquiry']['phone'] === '+61400111222' );

$disabled = $H::build( $base, 'disabled' );
t_check( 'disabled proxy still hands off with rule-based summary', $disabled['status'] === 'ready' && $disabled['enquiry']['ai_proxy_state'] === 'disabled' && $disabled['enquiry']['summary_source'] === 'rules' );
$exhausted = $H::build( $base, 'exhausted' );
t_check( 'exhausted allowance still hands off', $exhausted['status'] === 'ready' && $exhausted['enquiry']['ai_proxy_state'] === 'exhausted' );
$bogus = $H::build( $base, 'ready; DROP TABLE' );
t_check( 'unknown proxy state fails closed to disabled', $bogus['enquiry']['ai_proxy_state'] === 'disabled' );

t_check( 'empty message is invalid', $H::build( array_merge( $base, array( 'text' => '   ' ) ) )['status'] === 'invalid' );
t_check( 'oversized message is invalid', $H::build( array_merge( $base, array( 'text' => str_repeat( 'a', 4001 ) ) ) )['status'] === 'invalid' );
t_check( 'free-text reason outside the allowed list is invalid', $H::build( array_merge( $base, array( 'reason' => 'please_refund' ) ) )['status'] === 'invalid' );
t_check( 'missing confidence is invalid', $H::build( array_merge( $base, array( 'confidence' => null ) ) )['status'] === 'invalid' );
t_check( 'confidence above 1 is clamped', $H::build( array_merge( $base, array( 'confidence' => 7 ) ) )['enquiry']['confidence'] === 1.0 );
t_check( 'unknown intent becomes other', $H::build( array_merge( $base, array( 'intent' => 'hack' ) ) )['enquiry']['intent'] === 'other' );

$limited = $H::build( $base, 'ready', function () { return false; } );
t_check( 'spam: over the contact rate limit is refused and nothing is built', $limited['status'] === 'rate_limited' && null === $limited['enquiry'] );
$seen = array();
$allowed = $H::build( $base, 'ready', function ( $key ) use ( &$seen ) { $seen[] = $key; return true; } );
t_check( 'rate limiter is keyed by normalised contact', $allowed['status'] === 'ready' && $seen === array( 'email:jo@example.com' ) );

$summary = $H::permitted_summary( 'Call me on +61 400 111 222 or mail jo.smith@example.com. See https://example.com/x now' );
t_check( 'summary removes phone number', false === strpos( $summary, '400 111' ) && false !== strpos( $summary, '[phone removed]' ) );
t_check( 'summary removes email address', false === strpos( $summary, 'jo.smith' ) && false !== strpos( $summary, '[email removed]' ) );
t_check( 'summary removes links', false === strpos( $summary, 'example.com' ) );
t_check( 'summary is capped at 280 characters', mb_strlen( $H::permitted_summary( str_repeat( 'word ', 200 ) ) ) <= 280 );

$stored = null;
$routed = $H::route( $ready['enquiry'], function ( $e ) use ( &$stored ) { $stored = $e; return 42; }, function () { return true; } );
t_check( 'route stores then notifies and reports both', $routed['routed'] === true && $routed['id'] === 42 && $routed['notified'] === true && $stored === $ready['enquiry'] );
$notify_down = $H::route( $ready['enquiry'], function () { return 9; }, function () { return false; } );
t_check( 'failed notification keeps the stored enquiry', $notify_down['routed'] === true && $notify_down['notified'] === false );
$store_down = $H::route( $ready['enquiry'], function () { return 0; }, function () { throw new RuntimeException( 'must not notify' ); } );
t_check( 'failed store does not notify (no lost or phantom alert)', $store_down['routed'] === false && $store_down['error'] === 'store_failed' );

echo "\nSummary: {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
exit( $GLOBALS['t_fail'] > 0 ? 1 : 0 );
