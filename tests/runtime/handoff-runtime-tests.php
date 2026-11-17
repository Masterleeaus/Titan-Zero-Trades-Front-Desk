<?php
/**
 * WordPress runtime checks for the human handoff store (issue #27).
 * Run from the WordPress root: wp --allow-root eval-file tests/runtime/handoff-runtime-tests.php
 * Not shipped in the plugin.
 */
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    fwrite( STDERR, "FAIL harness -- WP_PLUGIN_DIR is not defined; run through WP-CLI inside a WordPress root\n" );
    exit( 2 );
}
$tz_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-request-value.php', 'class-tzfd-scoped-store.php', 'class-tzfd-lead-intake.php', 'class-tzfd-enquiry-review.php', 'class-tzfd-human-handoff.php' ) as $tz_file ) {
    require_once $tz_inc . $tz_file;
}
global $wpdb;
$GLOBALS['hr_pass'] = 0; $GLOBALS['hr_fail'] = 0;
function hr_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['hr_pass']++; } else { $GLOBALS['hr_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

$H       = 'TZFD_Human_Handoff';
$company = (int) TZFD_Scoped_Store::company_id();
$other   = $company + 5000; // a company id that owns nothing on this site.
hr_check( 'company resolves', $company > 0, 'company=' . $company );

$tag   = substr( md5( uniqid( '', true ) ), 0, 10 );
$email = 'hf-' . $tag . '@example.test';
$built = $H::build_enquiry( array(
    'text' => 'Please call me about a callback. My mobile is 0412 345 678 and email ' . $email,
    'source' => 'website-form', 'intent' => 'callback', 'confidence' => null,
    'reasons' => array( 'customer_requested' ), 'consent' => true,
    'name' => 'HF ' . $tag, 'email' => $email, 'phone' => '',
) );
hr_check( 'enquiry built with consent', ! empty( $built['ok'] ) );

// 1. Store writes one row for the company and it is visible to the owner view.
$id = $H::store( $company, $built['enquiry'] );
hr_check( 'store returns a row id', $id > 0, 'id=' . $id );
$rows = TZFD_Enquiry_Review::list_for_company( $company, '', 100 );
$mine = null;
foreach ( $rows as $r ) { if ( (int) $r->id === $id ) { $mine = $r; break; } }
hr_check( 'owner view lists the handoff', null !== $mine );
hr_check( 'row carries the company id', $mine && (int) $mine->company_id === $company );
hr_check( 'row source is website-form', $mine && 'website-form' === $mine->source );
hr_check( 'row status is new', $mine && 'new' === $mine->status );
hr_check( 'structured intent stored', $mine && false !== strpos( $mine->enquiry, 'Intent: callback' ) );
hr_check( 'confidence recorded as not scored', $mine && false !== strpos( $mine->enquiry, 'Confidence: not scored' ) );
hr_check( 'reason stored', $mine && false !== strpos( $mine->enquiry, 'Reasons: customer_requested' ) );
hr_check( 'consent line recorded', $mine && false !== strpos( $mine->enquiry, 'Consent: given (recorded' ) );
hr_check( 'raw phone removed from stored summary', $mine && false === strpos( $mine->enquiry, '0412 345 678' ) );
hr_check( 'raw email absent from stored summary text', $mine && false === strpos( $mine->enquiry, $email ) );

// 2. Tenant isolation: another company does not see the row, and a store for an unknown company fails closed.
$other_rows = TZFD_Enquiry_Review::list_for_company( $other, '', 100 );
$leak = false;
foreach ( $other_rows as $r ) { if ( (int) $r->id === $id ) { $leak = true; } }
hr_check( 'other company cannot list the handoff', ! $leak );
hr_check( 'store with company 0 is refused', 0 === $H::store( 0, $built['enquiry'] ) );
$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}" . TZFD_Tables::ENQUIRIES . " WHERE email = %s", $email ) );
hr_check( 'refused store writes no row', 1 === $before, 'rows=' . $before );

// 3. Unauthorized write path: a store with no contact details or no consent never reaches storage.
$no_contact = $H::build_enquiry( array( 'text' => 'x', 'consent' => true ) );
hr_check( 'no-contact enquiry never built', empty( $no_contact['ok'] ) && 'no_contact_details' === $no_contact['reason'] );
$no_consent = $H::build_enquiry( array( 'text' => 'x', 'consent' => false, 'email' => $email ) );
hr_check( 'no-consent enquiry never built', empty( $no_consent['ok'] ) && 'consent_required' === $no_consent['reason'] );

// 4. Existing Free intake still works and is not affected by the handoff class.
$legacy = TZFD_Lead_Intake::receive( array( 'company_id' => $company, 'name' => 'Legacy ' . $tag, 'email' => 'legacy-' . $tag . '@example.test', 'enquiry' => 'legacy enquiry ' . $tag, 'source' => 'website-form' ) );
hr_check( 'existing lead intake still stores enquiries', $legacy > 0 );

// Clean up the rows this run created.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}" . TZFD_Tables::ENQUIRIES . " WHERE email IN (%s, %s)", $email, 'legacy-' . $tag . '@example.test' ) );

echo "\nSummary: " . $GLOBALS['hr_pass'] . " passed, " . $GLOBALS['hr_fail'] . " failed\n";
exit( $GLOBALS['hr_fail'] > 0 ? 1 : 0 );
