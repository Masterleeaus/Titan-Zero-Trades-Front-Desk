<?php
/**
 * Unit tests for the first-run setup wizard's pure parsing helpers (#15).
 * Run: php tests/unit/onboarding-setup-test.php
 * Covers service import from CSV, web page and typed text, de-duplication and caps.
 * No database, no network. Malicious markup in imported names must not survive as markup.
 */
require_once __DIR__ . '/wp-stubs.php';
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( wp_strip_all_tags( (string) $s ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); }
}
require_once __DIR__ . '/../../titan-zero-trades-front-desk/addons/core/onboarding.addon.php';

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo "FAIL: {$name}\n";
}
function names_of( array $items ) {
	return array_map( static function ( $i ) { return $i['name']; }, $items );
}

/* ---------- CSV import ---------- */
$csv = TZFD_Onboarding::candidates_from_csv( "Name,Price\nBoiler repair,80\n\n  Drain unblocking  ,\n" );
ok( 'csv: header row is skipped and blank lines are ignored', names_of( $csv ) === array( 'Boiler repair', 'Drain unblocking' ) );
ok( 'csv: price is read from the second column when present', $csv[0]['price'] === '80' && $csv[1]['price'] === '' );
ok( 'csv: every candidate records its source', $csv[0]['source'] === 'csv' );

$hostile = TZFD_Onboarding::candidates_from_csv( "<script>alert(1)</script>Gutter clean,<b>5</b>\n" );
ok( 'csv: markup in a service name is stripped', count( $hostile ) === 1 && false === strpos( $hostile[0]['name'], '<' ) && false === strpos( $hostile[0]['name'], '>' ) );
ok( 'csv: a name longer than 120 characters is cut', strlen( TZFD_Onboarding::candidates_from_csv( str_repeat( 'a', 300 ) )[0]['name'] ) === 120 );

$many = str_repeat( "Service\n", TZFD_Onboarding::MAX_SERVICES + 50 );
ok( 'csv: imports are capped at MAX_SERVICES', count( TZFD_Onboarding::candidates_from_csv( $many ) ) === TZFD_Onboarding::MAX_SERVICES );

/* ---------- web page import ---------- */
$html = '<ul><li>Boiler repair - $80</li><li><b>Drain</b> unblocking</li><li>x</li><li>Emergency call-out £120.50</li></ul>';
$web  = TZFD_Onboarding::candidates_from_html( $html );
ok( 'html: list items are read and too-short items skipped', names_of( $web ) === array( 'Boiler repair', 'Drain unblocking', 'Emergency call-out' ) );
ok( 'html: a currency sign is read as a price and removed from the name', $web[0]['price'] === '$80' && $web[2]['price'] === '£120.50' );
ok( 'html: page import records web as the source', $web[0]['source'] === 'web' );
ok( 'html: no list items gives an empty list', TZFD_Onboarding::candidates_from_html( '<p>No list here</p>' ) === array() );

$items = str_repeat( '<li>Service item</li>', TZFD_Onboarding::MAX_ITEMS + 20 );
ok( 'html: items are capped at MAX_ITEMS', count( TZFD_Onboarding::candidates_from_html( $items ) ) === TZFD_Onboarding::MAX_ITEMS );

/* ---------- typed text ---------- */
$typed = TZFD_Onboarding::parse_services_text( "Boiler repair\r\n\r\nDrain unblocking\n" );
ok( 'typed: one service per line, blank lines dropped', names_of( $typed ) === array( 'Boiler repair', 'Drain unblocking' ) );
ok( 'typed: typed services have no price and source typed', $typed[0]['price'] === '' && $typed[0]['source'] === 'typed' );

/* ---------- de-duplication: first occurrence wins, ignoring case and spacing ---------- */
$merged = TZFD_Onboarding::merge_candidates( array(
	array( 'name' => 'Boiler repair', 'price' => '80', 'source' => 'pack' ),
	array( 'name' => '  boiler   REPAIR ', 'price' => '', 'source' => 'csv' ),
	array( 'name' => '', 'price' => '', 'source' => 'csv' ),
	array( 'name' => 'Drain', 'price' => '', 'source' => 'web' ),
) );
ok( 'merge: repeats ignoring case and spacing are removed', names_of( $merged ) === array( 'Boiler repair', 'Drain' ) );
ok( 'merge: the first source wins', $merged[0]['source'] === 'pack' && $merged[0]['price'] === '80' );

/* ---------- missing-service import is not invented ---------- */
ok( 'no source text gives no services and nothing is invented', TZFD_Onboarding::candidates_from_csv( '' ) === array() && TZFD_Onboarding::parse_services_text( '' ) === array() );

/* ---------- report ---------- */
echo $GLOBALS['tzfd_fail'] === 0
	? "Onboarding setup tests: passed {$GLOBALS['tzfd_pass']}, failed 0\n"
	: "Onboarding setup tests: passed {$GLOBALS['tzfd_pass']}, failed {$GLOBALS['tzfd_fail']}\n";
exit( $GLOBALS['tzfd_fail'] > 0 ? 1 : 0 );
