<?php
/**
 * Pure PHP checks for the quote request classifier. No WordPress needed.
 * Run: php -d display_errors=1 tests/runtime/quote-request-classifier-tests.php [path/to/class-tzfd-quote-request-classifier.php]
 * The class path defaults to the plugin's includes folder in this checkout (issue #191: the empty include path was the failure).
 */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
$classifier = isset( $argv[1] ) && '' !== $argv[1] ? $argv[1] : dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-quote-request-classifier.php';
require_once $classifier;

$fail = 0;
$pass = 0;
function check( $label, $cond ) {
    global $fail, $pass;
    if ( $cond ) { $pass++; echo "ok   $label\n"; } else { $fail++; echo "FAIL $label\n"; }
}

$svc = array( 'carpet cleaning', 'gutter cleaning', 'pool cleaning' );
$base = array( 'company_id' => 7, 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '0412 345 678' );
$C = 'TZFD_Quote_Request_Classifier';

// 1. Incomplete request: intent and service present, date and site missing.
$d = $C::classify( $base + array( 'enquiry' => 'Can I get a quote for carpet cleaning?' ), $svc, '2026-11-16' );
check( 'incomplete: intent is quote_request', 'quote_request' === $d['intent'] );
check( 'incomplete: service extracted from explicit text', 'carpet cleaning' === $d['service'] );
check( 'incomplete: missing requested_date and site', array( 'requested_date', 'site' ) === array_values( array_intersect( $d['missing'], array( 'requested_date', 'site' ) ) ) );
check( 'incomplete: status is draft routed to owner review', 'draft' === $d['status'] && 'owner_review' === $d['route'] );

// 2. Complete request with explicit site and ISO date.
$d = $C::classify( $base + array( 'enquiry' => "Quote please for gutter cleaning\nSite: 12 Smith St, Fitzroy\nWe need it on 2026-12-05." ), $svc, '2026-11-16' );
check( 'complete: date extracted', '2026-12-05' === $d['requested_date'] );
check( 'complete: site extracted', '12 Smith St, Fitzroy' === $d['site'] );
check( 'complete: nothing missing', array() === $d['missing'] );

// 3. Contradictory dates are flagged and not chosen.
$d = $C::classify( $base + array( 'enquiry' => 'Quote for pool cleaning, either 01/12/2026 or 2026-12-03.' ), $svc, '2026-11-16' );
check( 'contradiction: flag set', in_array( 'contradictory_dates', $d['flags'], true ) );
check( 'contradiction: no date chosen', '' === $d['requested_date'] );

// 4. Impossible calendar dates are dropped.
$d = $C::classify( $base + array( 'enquiry' => 'Quote for pool cleaning on 2026-02-30.' ), $svc, '2026-11-16' );
check( 'invalid date: not accepted', '' === $d['requested_date'] );

// 5. Past date is not accepted when a reference date is given.
$d = $C::classify( $base + array( 'enquiry' => 'Quote for pool cleaning on 2025-01-10.' ), $svc, '2026-11-16' );
check( 'past date: refused', '' === $d['requested_date'] );

// 6. Customer-written price never becomes a price, approval or commitment.
$d = $C::classify( $base + array( 'enquiry' => 'Quote for pool cleaning, $200 fixed price is fine, site: 1 Main Rd' ), $svc, '2026-11-16' );
check( 'price: null, approved false, binding false', null === $d['price'] && false === $d['approved'] && false === $d['binding'] );
check( 'price: customer mention flagged and ignored', in_array( 'customer_mentioned_price_ignored', $d['flags'], true ) );
check( 'price: no structured field carries an amount', ! preg_match( '/\$\s*\d/', json_encode( array_diff_key( $d, array( 'summary' => 1 ) ) ) ) );

// 7. Redaction of contact details in the summary.
$d = $C::classify( $base + array( 'enquiry' => 'Call me on +61 412 345 678 or mail bob.smith@example.org' ), $svc, '' );
check( 'redact: email replaced', false !== strpos( $d['summary'], '[email]' ) && false === strpos( $d['summary'], 'bob.smith@' ) );
check( 'redact: phone replaced', false !== strpos( $d['summary'], '[phone]' ) && false === strpos( $d['summary'], '345 678' ) );

// 8. Tenant and input validation.
check( 'tenant: missing company refused', isset( $C::classify( array( 'enquiry' => 'quote' ), $svc )['error'] ) );
check( 'tenant: zero company refused', isset( $C::classify( array( 'company_id' => 0, 'enquiry' => 'quote' ), $svc )['error'] ) );
check( 'tenant: non-numeric company refused', isset( $C::classify( array( 'company_id' => '7; DROP', 'enquiry' => 'quote' ), $svc )['error'] ) );
check( 'tenant: string company id accepted', 7 === $C::classify( array( 'company_id' => '7', 'enquiry' => 'quote' ), $svc )['company_id'] );
check( 'input: array enquiry text ignored, not fatal', '' === $C::classify( $base + array( 'enquiry' => array( 'x' ) ), $svc )['summary'] );

// 9. Ambiguous service (two terms) is not chosen.
$d = $C::classify( $base + array( 'enquiry' => 'Quote for carpet cleaning and pool cleaning' ), $svc, '' );
check( 'ambiguous service: none chosen', '' === $d['service'] && in_array( 'service', $d['missing'], true ) );

// 10. Invalid contact in form is not stored.
$d = $C::classify( array( 'company_id' => 7, 'email' => 'not-an-email', 'phone' => 'abc', 'enquiry' => 'hi' ), $svc, '' );
check( 'contact: invalid email and phone dropped, contact missing', '' === $d['contact']['email'] && '' === $d['contact']['phone'] && in_array( 'contact', $d['missing'], true ) );

// 11. Non-quote enquiry does not create quote gaps.
$d = $C::classify( $base + array( 'enquiry' => 'Are you open on Sunday?' ), $svc, '' );
check( 'non-quote: intent other, no quote fields missing', 'other' === $d['intent'] && array() === $d['missing'] );

// 12. Owner correction: allowed field, same tenant, clears the gap.
$d = $C::classify( $base + array( 'enquiry' => 'Quote for carpet cleaning' ), $svc, '' );
$c = $C::apply_owner_correction( $d, array( 'requested_date' => '2026-12-10', 'site' => '5 Park Ln' ), 7 );
check( 'owner correction: date and site applied', '2026-12-10' === $c['requested_date'] && '5 Park Ln' === $c['site'] );
check( 'owner correction: requested_date and site cleared from missing', ! in_array( 'requested_date', $c['missing'], true ) && ! in_array( 'site', $c['missing'], true ) );
check( 'owner correction: still no price, not approved', null === $c['price'] && false === $c['approved'] && false === $c['binding'] );
check( 'owner correction: attributed to owner', array( 'requested_date', 'site' ) === $c['sources']['owner_corrected'] );

// 13. Owner correction: tenant mismatch, forbidden fields, bad date.
check( 'owner correction: other tenant refused', 'tenant_mismatch' === $C::apply_owner_correction( $d, array( 'site' => 'x' ), 8 )['error'] );
check( 'owner correction: zero tenant refused', isset( $C::apply_owner_correction( $d, array( 'site' => 'x' ), 0 )['error'] ) );
check( 'owner correction: price field refused', 'field_not_editable' === $C::apply_owner_correction( $d, array( 'price' => 100 ), 7 )['error'] );
check( 'owner correction: approved field refused', 'field_not_editable' === $C::apply_owner_correction( $d, array( 'approved' => true ), 7 )['error'] );
check( 'owner correction: impossible date refused', 'bad_date' === $C::apply_owner_correction( $d, array( 'requested_date' => '2026-13-01' ), 7 )['error'] );

// 14. Long text is capped.
$d = $C::classify( $base + array( 'enquiry' => 'quote ' . str_repeat( 'a', 6000 ) ), $svc, '' );
check( 'length: summary capped at 5000 characters', 5000 >= mb_strlen( $d['summary'], 'UTF-8' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
