<?php
/**
 * Offline fixtures for lead urgency detection (issue #29).
 * Run: php -I tests/unit/lead-urgency-fixtures.php  (from the repo root; no WordPress needed).
 * WordPress integration (storage, alerts, admin page) is covered by tests/runtime and needs a live site.
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-lead-urgency.php';

$failures = 0;
$total    = 0;
function check( $label, $actual, $expected ) {
    global $failures, $total;
    $total++;
    if ( $actual !== $expected ) {
        $failures++;
        echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
    }
}
$L = 'TZFD_Lead_Urgency';
$ref = '2026-11-16 08:00:00';

// Urgent language.
check( 'urgent: flooding and ASAP', $L::classify( 'My geyser burst pipe is flooding the laundry, please come ASAP', $ref )['automatic'], 'urgent' );
check( 'urgent: no hot water', $L::classify( 'This is an emergency, no hot water since this morning', $ref )['automatic'], 'urgent' );

// Non-urgent language.
check( 'normal: no rush', $L::classify( 'Hi, do you do end of lease cleans? No rush, whenever suits.', $ref )['automatic'], 'normal' );
check( 'normal: plain quote request', $L::classify( 'Could I book a quote next month?', $ref )['automatic'], 'normal' );
check( 'normal: empty text', $L::classify( '', $ref )['automatic'], 'normal' );

// Negated language does not fire.
check( 'negated: not urgent', $L::classify( 'This is not urgent at all', $ref )['automatic'], 'normal' );
check( 'negated: not an emergency', $L::classify( "It's not an emergency, just a quote", $ref )['automatic'], 'normal' );
check( 'negated: not making a complaint', $L::classify( 'I am not making a complaint, just a question', $ref )['automatic'], 'normal' );
check( 'negated: dont call me back', $L::classify( "Don't call me back, email is fine", $ref )['automatic'], 'normal' );
// A negator in an earlier clause does not cancel a later clause.
check( 'negation stops at clause break', $L::classify( 'No, it is urgent', $ref )['automatic'], 'urgent' );
$r = $L::classify( 'Not really urgent, but please call me back', $ref );
check( 'negated urgent but callback still counts', $r['automatic'], 'urgent' );
check( 'negated urgent adds no urgent reason', count( array_filter( $r['reasons'], fn( $x ) => str_starts_with( $x, 'Urgent wording' ) ) ), 0 );

// Complaints and callbacks.
check( 'complaint: refund', $L::classify( 'I want a refund, this job was unacceptable', $ref )['automatic'], 'urgent' );
check( 'callback: call me back', $L::classify( 'Please call me back on 0400 000 000', $ref )['automatic'], 'urgent' );

// Expiring booking dates: a date term plus an explicit date inside the window (default 2 days).
$r = $L::classify( 'My quote expires on 17/11/2026, can you hold the slot?', $ref );
check( 'expiring: date inside window', $r['automatic'], 'urgent' );
check( 'expiring: reason names the date', $r['reasons'][0], 'Expiring booking date: 2026-11-17 with "expires"' );
check( 'expiring: date outside window', $L::classify( 'The offer expires 20/11/2026', $ref )['automatic'], 'normal' );
check( 'expiring: past date ignored', $L::classify( 'Offer expires 01/01/2020', $ref )['automatic'], 'normal' );
check( 'expiring: date without a term', $L::classify( 'I need a booking on 17 Nov', $ref )['automatic'], 'normal' );
check( 'expiring: tomorrow with term', $L::classify( 'Expiring tomorrow, please hold it', $ref )['automatic'], 'urgent' );
check( 'expiring: named month, same day', $L::classify( 'Deadline 16 nov for the deposit', $ref )['automatic'], 'urgent' );
check( 'expiring: invalid calendar date ignored', $L::classify( 'expires 31/02/2026', $ref )['automatic'], 'normal' );
// Clean dates for a Jan reference: a named date with no year that already passed means next year.
check( 'expiring: yearless date past this year rolls to next year', $L::classify( 'expires 2 Jan', '2026-12-31 09:00:00' )['automatic'], 'urgent' );

// Configurable rules: disabling a category or adding a phrase changes the result.
$rules = $L::default_rules();
$rules['complaint']['enabled'] = false;
check( 'config: disabled complaint category is silent', $L::classify( 'I want a refund', $ref, $rules )['automatic'], 'normal' );
$rules = $L::default_rules();
$rules['urgent_term']['phrases'][] = 'bring the ladder';
check( 'config: added phrase fires', $L::classify( 'please bring the ladder', $ref, $rules )['automatic'], 'urgent' );
$rules = $L::default_rules();
$rules['expiry_window_days'] = 0;
check( 'config: zero window excludes tomorrow', $L::classify( 'Expiring tomorrow', $ref, $rules )['automatic'], 'normal' );

// Rule sanitising: long, empty, duplicate and pipe-containing phrases are cleaned or dropped.
$clean = $L::normalise_rules( array( 'urgent_term' => array( 'enabled' => true, 'phrases' => array( 'Burst|pipe', '', str_repeat( 'x', 61 ), 'asap', 'ASAP' ) ) ) );
check( 'rules: phrases cleaned and deduplicated', $clean['urgent_term']['phrases'], array( 'burst pipe', 'asap' ) );
check( 'rules: window clamped', $L::normalise_rules( array( 'expiry_window_days' => 99 ) )['expiry_window_days'], 14 );

// Priority override: an admin value wins; otherwise the automatic result (or not assessed).
check( 'override: urgent over normal', $L::effective( 'normal', 'urgent' ), 'urgent' );
check( 'override: normal over urgent', $L::effective( 'urgent', 'normal' ), 'normal' );
check( 'override: automatic when none set', $L::effective( 'urgent', '' ), 'urgent' );
check( 'override: invalid value ignored', $L::effective( 'normal', 'bogus' ), 'normal' );
check( 'override: not assessed stays empty', $L::effective( '', '' ), '' );

// Audit trace for an override.
$ev = $L::override_event( 5, '', 'urgent' );
check( 'audit: action and entity', array( $ev['action'], $ev['entity'], $ev['entity_id'] ), array( 'enquiry.urgency_override', 'enquiry', 5 ) );
check( 'audit: summary shows both levels', $ev['summary'], 'Urgency override: automatic to urgent' );

// Consent (admin opt-in) and notification throttling.
$on = array( 'enabled' => true, 'throttle_minutes' => 15 );
check( 'consent: alerts off means no alert', $L::alert_allowed( array( 'enabled' => false ), 'urgent', 0, 1000 ), false );
check( 'consent: normal enquiry never alerts', $L::alert_allowed( $on, 'normal', 0, 1000 ), false );
check( 'alert: first urgent enquiry alerts', $L::alert_allowed( $on, 'urgent', 0, 1000 ), true );
check( 'throttle: inside window suppressed', $L::alert_allowed( $on, 'urgent', 1000, 1000 + 899 ), false );
check( 'throttle: at window edge allowed', $L::alert_allowed( $on, 'urgent', 1000, 1000 + 900 ), true );
check( 'throttle: default window when missing', $L::alert_allowed( array( 'enabled' => true ), 'urgent', 1000, 1000 + 899 ), false );
check( 'throttle: window capped at a day', $L::alert_allowed( array( 'enabled' => true, 'throttle_minutes' => 99999 ), 'urgent', 1000, 1000 + 86400 ), true );

// Pure path: classification ran with no WordPress function loaded.
check( 'offline: no WordPress functions were needed', function_exists( 'get_option' ), false );

echo ( $failures ? "FAILED" : "OK" ) . ": $failures of $total checks failed\n";
exit( $failures ? 1 : 0 );
