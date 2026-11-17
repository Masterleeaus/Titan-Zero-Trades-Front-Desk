<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions -- standalone CLI harness: prints plain test text to a terminal, never HTML.
/**
 * Checks for the read-only AI setup review (issue #40). Pure PHP with WordPress stubs. No database, no network.
 * Run from the repository root: php -I tests/unit/ai-setup-review-test.php
 */
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-free-services.php';

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function rv_ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo 'FAIL: ' . $name . "\n";
}
function rv_keys( array $items ) {
	return array_map( static function ( $i ) { return $i['key']; }, $items );
}

$S = 'TZFD_Ai_Setup_Check';

$full = array(
	'business_name'       => 'Sample Plumbing Co',
	'services'            => array( 'Drain unblocking', 'Hot water repair' ),
	'greeting'            => '',
	'hours_configured'    => true,
	'faq_questions'       => array(),
	'tags'                => array(),
	'connector_available' => true,
);

// Empty and new site: everything required is missing, AI gate closed, nothing suggested.
$empty = $S::review( array() );
rv_ok( 'empty site lists business name, services and hours', in_array( 'business_name', rv_keys( $empty['missing'] ), true ) && in_array( 'services', rv_keys( $empty['missing'] ), true ) && in_array( 'hours', rv_keys( $empty['missing'] ), true ) );
rv_ok( 'empty site keeps AI gate closed', false === $empty['gate']['allowed'] );
rv_ok( 'empty site suggests nothing and does not fail', array() === $empty['suggestions'] );

// Complete site: gate open, greeting, FAQs and tags offered.
$ready = $S::review( $full );
rv_ok( 'complete site opens the AI gate', true === $ready['gate']['allowed'] && array() === $ready['gate']['missing'] );
$types = array_column( $ready['suggestions'], 'type' );
rv_ok( 'complete site gets a greeting suggestion', in_array( 'greeting', $types, true ) );
rv_ok( 'complete site gets one FAQ per service', 2 === count( array_keys( $types, 'faq', true ) ) );
rv_ok( 'complete site gets a tag suggestion', in_array( 'tags', $types, true ) );
rv_ok( 'every suggestion needs confirmation', array_reduce( $ready['suggestions'], static function ( $c, $s ) { return $c && true === $s['needs_confirmation']; }, true ) );

// Absent provider: the hosted connector is listed and blocks AI, but setup items are unaffected.
$no_connector = $S::review( array_merge( $full, array( 'connector_available' => false ) ) );
rv_ok( 'no connector keeps the gate closed and names the connector', false === $no_connector['gate']['allowed'] && in_array( 'hosted_connector', $no_connector['gate']['missing'], true ) );
$connector_item = array_values( array_filter( $no_connector['missing'], static function ( $i ) { return 'hosted_connector' === $i['key']; } ) );
rv_ok( 'no connector is not a required setup item', 1 === count( $connector_item ) && false === $connector_item[0]['required'] );
rv_ok( 'no connector still gives suggestions for the rest of setup', ! empty( $no_connector['suggestions'] ) );

// Upgraded site: an existing greeting and an existing FAQ are kept, not offered again.
$upgraded = $S::review( array_merge( $full, array(
	'greeting'      => 'Welcome, thanks for calling Sample Plumbing.',
	'faq_questions' => array( 'Do you offer Drain unblocking?' ),
) ) );
$up_types = array_column( $upgraded['suggestions'], 'type' );
rv_ok( 'upgraded site keeps the existing greeting', ! in_array( 'greeting', $up_types, true ) );
$up_faq = array_values( array_filter( $upgraded['suggestions'], static function ( $s ) { return 'faq' === $s['type']; } ) );
rv_ok( 'upgraded site is not offered the same FAQ again', 1 === count( $up_faq ) && false !== strpos( $up_faq[0]['value']['question'], 'Hot water' ) );

// No invented facts: no figures, prices or hours in any suggestion text.
$texts = array();
foreach ( $ready['suggestions'] as $s ) { $texts[] = $S::describe( $s ); }
$joined = implode( ' | ', $texts );
rv_ok( 'suggestion text has no figures, prices or times', 0 === preg_match( '/\d|\$|\b(am|pm)\b/i', $joined ) );
rv_ok( 'describe returns the greeting, FAQ and tag lines', 0 === strpos( $joined, 'Greeting: ' ) && false !== strpos( $joined, 'FAQ: Do you offer Drain unblocking?' ) && false !== strpos( $joined, 'Tags: drain-unblocking' ) );

// Hostile service names are plain text, with markup removed.
$hostile = $S::review( array_merge( $full, array( 'services' => array( '<script>alert(1)</script>Drain' ) ) ) );
$hostile_texts = array();
foreach ( $hostile['suggestions'] as $s ) { $hostile_texts[] = $S::describe( $s ); }
rv_ok( 'service names with markup are stored as plain text', 0 === preg_match( '/[<>]/', implode( ' ', $hostile_texts ) ) );

// Caps: at most 10 service FAQs, however many services the administrator entered.
$many = array();
for ( $i = 1; $i <= 14; $i++ ) { $many[] = 'Service ' . $i; }
$cap = $S::review( array_merge( $full, array( 'services' => $many ) ) );
rv_ok( 'FAQ suggestions are capped at ten', 10 === count( array_filter( $cap['suggestions'], static function ( $s ) { return 'faq' === $s['type']; } ) ) );

// Read-only: the review writes no option and grants no consent.
$GLOBALS['tzfd_test_options'] = array( 'probe' => 'before' );

$before = $GLOBALS['tzfd_test_options'];
$S::review( $full );
$S::review( array() );
rv_ok( 'review writes no option', $before === $GLOBALS['tzfd_test_options'] );
rv_ok( 'review leaves AI consent off', false === TZFD_Ai_Free_Core::consent_granted() );

// Replay: the same state gives the same review.
rv_ok( 'review is deterministic on replay', $S::review( $full ) === $S::review( $full ) );

// describe ignores unknown types.
rv_ok( 'describe ignores unknown suggestion types', '' === $S::describe( array( 'type' => 'price', 'value' => '60' ) ) );

echo 'Passed: ' . $GLOBALS['tzfd_pass'] . "\n";
echo 'Failed: ' . $GLOBALS['tzfd_fail'] . "\n";
exit( 0 === $GLOBALS['tzfd_fail'] ? 0 : 1 );
