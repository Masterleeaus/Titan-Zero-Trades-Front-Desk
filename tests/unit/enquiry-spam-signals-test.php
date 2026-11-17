<?php
/**
 * Unit checks for TZFD_Enquiry_Spam_Signals (issue #34). Pure PHP, no WordPress needed.
 * Run: php -I tests/unit/enquiry-spam-signals-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-enquiry-spam-signals.php';

$failures = 0;
$passes   = 0;
function tzfd_check( $label, $cond ) {
    global $failures, $passes;
    if ( $cond ) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}\n";
}

$S    = 'TZFD_Enquiry_Spam_Signals';
$now  = 1800000000;
$long = 'Hi, I need a full house clean next Tuesday morning and can you please confirm the price for three bedrooms and two bathrooms.';
$alt  = 'Hi, I need a full house clean next Tuesday morning and can you please confirm the cost for three bedrooms and two bathrooms.';

// Normalisation and Unicode edge cases.
tzfd_check( 'fullwidth folds to ASCII (NFKC)', $S::signature( 'ＣＡＦＥ' ) === $S::signature( 'cafe' ) );
tzfd_check( 'zero-width space does not change signature', $S::signature( "book\u{200B}ing" ) === $S::signature( 'booking' ) );
tzfd_check( 'soft hyphen and BOM are stripped', $S::signature( "\u{FEFF}sli\u{00AD}de" ) === $S::signature( 'slide' ) );
tzfd_check( 'CRLF and CR equal LF', $S::signature( "a\r\nb" ) === $S::signature( "a\nb" ) && $S::signature( "a\rb" ) === $S::signature( "a\nb" ) );
tzfd_check( 'case-insensitive for Cyrillic', $S::signature( 'ПРИВЕТ' ) === $S::signature( 'привет' ) );
tzfd_check( 'invalid UTF-8 yields no signature', '' === $S::signature( "\xC3\x28bad" ) );
tzfd_check( 'non-string yields no signature', '' === $S::signature( array( 'x' ) ) && '' === $S::signature( null ) );
tzfd_check( 'empty text yields no signature', '' === $S::signature( '   ' ) );
tzfd_check( 'Greek tokens are kept as words', count( $S::tokens( 'Καλημέρα κόσμε' ) ) === 2 );

// Legitimate single enquiry is ok, with no reasons and no auto action.
$r = $S::evaluate( 7, $long, $now, array() );
tzfd_check( 'first enquiry is ok', 'ok' === $r['decision'] && empty( $r['reasons'] ) );
tzfd_check( 'auto action is always none', 'none' === $r['auto_action'] );

// Exact replay of the same text inside the window: review, never a refusal.
$prior = array( array( 'company_id' => 7, 'text' => $long, 'time' => $now - 30 ) );
$r     = $S::evaluate( 7, $long, $now, $prior );
tzfd_check( 'exact replay is flagged for review', 'review' === $r['decision'] && in_array( 'repeat_signature', $r['reasons'], true ) );
tzfd_check( 'replay result carries a group key', 64 === strlen( $r['group_key'] ) );
tzfd_check( 'evaluation is deterministic', $r === $S::evaluate( 7, $long, $now, $prior ) );
tzfd_check( 'Unicode-cosmetic replay is still a repeat', 'review' === $S::evaluate( 7, "\u{200B}" . $long, $now, $prior )['decision'] );

// Near-duplicate: one word changed.
$prior = array( array( 'company_id' => 7, 'text' => $long, 'time' => $now - 60 ) );
$r     = $S::evaluate( 7, $alt, $now, $prior );
tzfd_check( 'near-duplicate is flagged', in_array( 'near_duplicate', $r['reasons'], true ) );
tzfd_check( 'near-duplicate is not an exact signature match', ! in_array( 'repeat_signature', $r['reasons'], true ) );

// False positives: short messages and genuinely different messages.
$prior = array( array( 'company_id' => 7, 'text' => 'please call me', 'time' => $now - 60 ) );
$r     = $S::evaluate( 7, 'please call me', $now, $prior );
tzfd_check( 'short identical message below MIN_TOKENS is not near-dup flagged', ! in_array( 'near_duplicate', $r['reasons'], true ) );
tzfd_check( 'short identical message still counts as repeat', in_array( 'repeat_signature', $r['reasons'], true ) );
$prior = array( array( 'company_id' => 7, 'text' => 'Do you service pool pumps in Richmond?', 'time' => $now - 60 ) );
$r     = $S::evaluate( 7, $long, $now, $prior );
tzfd_check( 'different legitimate enquiry is ok', 'ok' === $r['decision'] );

// Burst: two earlier submissions in the window plus this one.
$prior = array(
    array( 'company_id' => 7, 'text' => 'Quote for gutter clean', 'time' => $now - 100 ),
    array( 'company_id' => 7, 'text' => 'Quote for roof repair', 'time' => $now - 200 ),
);
$r = $S::evaluate( 7, 'Quote for window washing', $now, $prior );
tzfd_check( 'three submissions in window is a burst', in_array( 'burst', $r['reasons'], true ) && 'review' === $r['decision'] );

// Timing edges: window boundary and clock skew.
$edge = array( array( 'company_id' => 7, 'text' => 'Quote for gutter clean', 'time' => $now - $S::BURST_WINDOW - 1 ) );
$r    = $S::evaluate( 7, 'Quote for window washing', $now, array_merge( $edge, $edge ) );
tzfd_check( 'records just outside the window do not count', 'ok' === $r['decision'] );
$skew = array(
    array( 'company_id' => 7, 'text' => 'Quote for gutter clean', 'time' => $now + 30 ),
    array( 'company_id' => 7, 'text' => 'Quote for roof repair', 'time' => $now + 10 ),
);
tzfd_check( 'small future skew counts toward burst', in_array( 'burst', $S::evaluate( 7, 'Quote for window washing', $now, $skew )['reasons'], true ) );
$far = array(
    array( 'company_id' => 7, 'text' => 'Quote for gutter clean', 'time' => $now + 3600 ),
    array( 'company_id' => 7, 'text' => 'Quote for roof repair', 'time' => $now + 3600 ),
);
tzfd_check( 'far-future records are ignored', 'ok' === $S::evaluate( 7, 'Quote for window washing', $now, $far )['decision'] );
$r = $S::evaluate( 7, $long, $now, array( array( 'company_id' => 7, 'text' => $long, 'time' => $now - 600 ) ) );
tzfd_check( 'exactly at the window start counts (inclusive)', 'review' === $r['decision'] );

// Tenant isolation: identical text from another company never matches.
$prior = array( array( 'company_id' => 8, 'text' => $long, 'time' => $now - 30 ) );
tzfd_check( 'other company record is never compared', 'ok' === $S::evaluate( 7, $long, $now, $prior )['decision'] );
$prior = array(
    array( 'company_id' => 8, 'text' => $long, 'time' => $now - 30 ),
    array( 'company_id' => 8, 'text' => $long, 'time' => $now - 40 ),
);
tzfd_check( 'other company records do not feed the burst count', 'ok' === $S::evaluate( 7, 'Quote for window washing', $now, $prior )['decision'] );
tzfd_check( 'group keys differ across companies', $S::evaluate( 7, $long, $now, array( array( 'company_id' => 7, 'text' => $long, 'time' => $now - 1 ) ) )['group_key'] !== $S::evaluate( 8, $long, $now, array( array( 'company_id' => 8, 'text' => $long, 'time' => $now - 1 ) ) )['group_key'] );

// Shared IP / NAT: the detector has no IP input, so two identities behind one NAT stay separate.
$a = $S::evaluate( 7, $long, $now, array() );
$b = $S::evaluate( 7, 'Do you service pool pumps in Richmond?', $now, array() );
tzfd_check( 'two customers behind one NAT are both ok', 'ok' === $a['decision'] && 'ok' === $b['decision'] );

// Malformed input and replay of malformed records fail open to ok, never to a refusal.
tzfd_check( 'company id 0 gives ok with no reasons', 'ok' === $S::evaluate( 0, $long, $now, array() )['decision'] );
tzfd_check( 'string now is refused input and gives ok', 'ok' === $S::evaluate( 7, $long, '1800000000', array() )['decision'] );
tzfd_check( 'non-array prior gives ok', 'ok' === $S::evaluate( 7, $long, $now, null )['decision'] );
$bad = array(
    'not-an-array',
    array( 'company_id' => 7, 'text' => $long ),
    array( 'company_id' => 7, 'text' => $long, 'time' => '1800000000' ),
    array( 'company_id' => '7', 'text' => $long, 'time' => $now - 5 ),
);
$r = $S::evaluate( 7, $long, $now, $bad );
tzfd_check( 'malformed prior records are skipped', 'ok' === $r['decision'] && empty( $r['reasons'] ) );
tzfd_check( 'empty text never flags a repeat', 'ok' === $S::evaluate( 7, '', $now, array( array( 'company_id' => 7, 'text' => '', 'time' => $now - 1 ) ) )['decision'] );

// Replay through a recovered store: same records in another order give the same verdict.
$prior = array(
    array( 'company_id' => 7, 'text' => 'Quote for gutter clean', 'time' => $now - 100 ),
    array( 'company_id' => 7, 'text' => 'Quote for roof repair', 'time' => $now - 200 ),
);
tzfd_check( 'verdict is independent of record order', $S::evaluate( 7, 'Quote for window washing', $now, $prior ) === $S::evaluate( 7, 'Quote for window washing', $now, array_reverse( $prior ) ) );

echo "{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
