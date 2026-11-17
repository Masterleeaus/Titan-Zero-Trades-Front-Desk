<?php
/**
 * Boundary, replay and false-positive checks for the live enquiry spam and duplicate rules (issue #34).
 *
 * Run from the repository root:
 *   php -I tests/unit/enquiry-spam-boundary-test.php
 * No WordPress is loaded. The rules class is pure PHP and makes no network calls.
 *
 * Tenant isolation and the stored review queue need the WordPress database and are covered by
 * tests/integration/enquiry-triage-db-test.php (not run by CI yet; see the issue #34 PR).
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-enquiry-triage-rules.php';

$R    = 'TZFD_Enquiry_Triage_Rules';
$pass = 0;
$fail = array();

function sb_check( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; return; }
    $fail[] = $name . ( '' !== $detail ? ' :: ' . $detail : '' );
}

// Fixed clock: 16 Nov 2026 10:00 UTC.
$NOW  = strtotime( '2026-11-16 10:00:00 UTC' );
$TEXT = 'Can you come and fix the kitchen tap on Tuesday morning please';

function sb_prior( $text, $email, $ip, $ts ) {
    return array(
        'signature' => TZFD_Enquiry_Triage_Rules::signature( $text ),
        'tokens'    => TZFD_Enquiry_Triage_Rules::tokens( $text ),
        'email'     => $email,
        'ip_hash'   => $ip,
        'ts'        => $ts,
    );
}

// ---- Duplicate window boundary (24 hours) -------------------------------------------------
$a = $R::spam_assessment( $TEXT, 'x@example.com', 'ipA', $NOW, array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW - $R::DUPLICATE_WINDOW ) ) );
sb_check( 'prior exactly at the 24h window still counts as identical', 'identical' === $a['dup_kind'], json_encode( $a ) );

$a = $R::spam_assessment( $TEXT, 'x@example.com', 'ipA', $NOW, array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW - $R::DUPLICATE_WINDOW - 1 ) ) );
sb_check( 'prior one second past the 24h window is ignored', '' === $a['dup_kind'] && 0 === $a['score'], json_encode( $a ) );

// ---- Clock skew (5 minutes into the future) ------------------------------------------------
$a = $R::spam_assessment( $TEXT, 'x@example.com', 'ipA', $NOW, array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW + 300 ) ) );
sb_check( 'prior 5 minutes in the future still counts', 'identical' === $a['dup_kind'], json_encode( $a ) );

$a = $R::spam_assessment( $TEXT, 'x@example.com', 'ipA', $NOW, array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW + 301 ) ) );
sb_check( 'prior 301 seconds in the future is ignored', '' === $a['dup_kind'], json_encode( $a ) );

// ---- Burst window boundary (600 seconds, 5 from one address) -------------------------------
$at_edge = array();
$past    = array();
for ( $i = 0; $i < 5; $i++ ) {
    $at_edge[] = sb_prior( 'Different request ' . $i . ' about my own job', 'p' . $i . '@example.com', 'ipNAT', $NOW - $R::BURST_WINDOW );
    $past[]    = sb_prior( 'Different request ' . $i . ' about my own job', 'p' . $i . '@example.com', 'ipNAT', $NOW - $R::BURST_WINDOW - 1 );
}
$a = $R::spam_assessment( 'A new question about my boiler service', 'new@example.com', 'ipNAT', $NOW, $at_edge );
sb_check( 'five same-address priors at exactly 600s count as a burst', in_array( 'burst_from_address', $a['signals'], true ), json_encode( $a ) );

$a = $R::spam_assessment( 'A new question about my boiler service', 'new@example.com', 'ipNAT', $NOW, $past );
sb_check( 'five same-address priors at 601s do not count as a burst', ! in_array( 'burst_from_address', $a['signals'], true ), json_encode( $a ) );

// ---- Shared IP (NAT) false positives -------------------------------------------------------
// A household or office behind one address sends several different messages: no hold.
$office = array();
for ( $i = 0; $i < 9; $i++ ) {
    $office[] = sb_prior( 'Office request ' . $i . ' for a different site and a different job', 'staff' . $i . '@example.com', 'ipOffice', $NOW - 60 * ( $i + 1 ) );
}
$a = $R::spam_assessment( 'Please quote for re-tiling the bathroom floor in unit four', 'manager@example.com', 'ipOffice', $NOW, $office );
sb_check( 'busy shared address is never held by burst alone', false === $a['hold'] && 1 === $a['score'], json_encode( $a ) );

// ---- Preserve legitimate bookings and repeats --------------------------------------------
// A customer who resends the same booking request once is a duplicate, not spam.
$a = $R::spam_assessment( $TEXT, 'customer@example.com', 'ipCust', $NOW, array( sb_prior( $TEXT, 'customer@example.com', 'ipCust', $NOW - 120 ) ) );
sb_check( 'one resend by the same customer is not held', false === $a['hold'] && 'identical' === $a['dup_kind'], json_encode( $a ) );

$a = $R::evaluate( 'Can I book a clean for Friday 20 Nov at 9am please', 'customer@example.com', 'ipCust', $NOW, array(), null, 2026 );
sb_check( 'legitimate booking request is routed as a booking, not spam', false === $a['spam']['hold'] && 'booking' === $a['primary_intent'], json_encode( $a['primary_intent'] ) );

// Two different customers writing the same short line are not held for it.
$a = $R::spam_assessment( 'Please call me back', 'second@example.com', 'ipTwo', $NOW, array( sb_prior( 'Please call me back', 'first@example.com', 'ipOne', $NOW - 200 ) ) );
sb_check( 'short identical line from two people is not held', false === $a['hold'], json_encode( $a ) );

// ---- Replay: the same input gives the same answer and does not change the priors --------
$priors = array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW - 3600 ) );
$copy   = $priors;
$first  = $R::spam_assessment( $TEXT, 'x@example.com', 'ipA', $NOW, $priors );
$second = $R::spam_assessment( $TEXT, 'x@example.com', 'ipA', $NOW, $priors );
sb_check( 'replaying the same assessment gives the same result', $first === $second );
sb_check( 'assessment does not modify the prior rows', $copy === $priors );

$e1 = $R::evaluate( $TEXT, 'x@example.com', 'ipA', $NOW, $priors, null, 2026 );
$e2 = $R::evaluate( $TEXT, 'x@example.com', 'ipA', $NOW, $priors, null, 2026 );
sb_check( 'full evaluation is deterministic on replay', $e1 === $e2 );

// ---- Unicode and invisible characters -----------------------------------------------------
$fullwidth = "\u{FF23}an you come and fix the kitchen tap on Tuesday morning please";
$zerowidth = "Can you come and fix\u{200B} the kitchen tap on Tuesday morning please";
$a = $R::spam_assessment( $fullwidth, 'x@example.com', 'ipA', $NOW, array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW - 60 ) ) );
sb_check( 'fullwidth variant of the same text is an identical duplicate', 'identical' === $a['dup_kind'], json_encode( $a ) );

$a = $R::spam_assessment( $zerowidth, 'x@example.com', 'ipA', $NOW, array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW - 60 ) ) );
sb_check( 'zero-width space does not hide a duplicate', 'identical' === $a['dup_kind'], json_encode( $a ) );

$rtl = "\u{200F}" . $TEXT;
$a   = $R::spam_assessment( $rtl, 'x@example.com', 'ipA', $NOW, array( sb_prior( $TEXT, 'y@example.com', 'ipB', $NOW - 60 ) ) );
sb_check( 'right-to-left mark does not hide a duplicate', 'identical' === $a['dup_kind'], json_encode( $a ) );

$cjk = str_repeat( "\u{4F60}\u{597D}", 15 ); // 30 CJK characters, no spaces.
$a   = $R::spam_assessment( $cjk, 'x@example.com', 'ipA', $NOW, array( sb_prior( $cjk . '!', 'y@example.com', 'ipB', $NOW - 60 ) ) );
sb_check( 'CJK text without spaces is compared without error', is_array( $a ) && 'identical' === $a['dup_kind'], json_encode( $a ) );

// Invalid UTF-8 and empty text never produce an identical signal.
$a = $R::spam_assessment( "\xC3\x28 broken", 'x@example.com', 'ipA', $NOW, array( sb_prior( "\xC3\x28 broken", 'y@example.com', 'ipB', $NOW - 60 ) ) );
sb_check( 'invalid UTF-8 is not treated as an identical duplicate', '' === $R::signature( "\xC3\x28 broken" ) && 'identical' !== $a['dup_kind'], json_encode( $a ) );

$a = $R::spam_assessment( "   \n\t ", 'x@example.com', 'ipA', $NOW, array( sb_prior( '   ', 'y@example.com', 'ipB', $NOW - 60 ) ) );
sb_check( 'blank text never matches blank text', '' === $a['dup_kind'] && false === $a['hold'], json_encode( $a ) );

echo 'Passed: ' . $pass . PHP_EOL;
echo 'Failed: ' . count( $fail ) . PHP_EOL;
foreach ( $fail as $f ) { echo 'FAIL: ' . $f . PHP_EOL; }
exit( empty( $fail ) ? 0 : 1 );
