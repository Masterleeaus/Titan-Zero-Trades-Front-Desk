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

// Complete site: gate open, FAQs and tags offered. The greeting is not a suggestion (written at first contact, #400).
$ready = $S::review( $full );
rv_ok( 'complete site opens the AI gate', true === $ready['gate']['allowed'] && array() === $ready['gate']['missing'] );
$types = array_column( $ready['suggestions'], 'type' );
rv_ok( 'greeting is not offered as a confirm-first suggestion', ! in_array( 'greeting', $types, true ) );
rv_ok( 'complete site gets one FAQ per service', 2 === count( array_keys( $types, 'faq', true ) ) );
rv_ok( 'complete site gets a tag suggestion', in_array( 'tags', $types, true ) );
rv_ok( 'every suggestion needs confirmation', array_reduce( $ready['suggestions'], static function ( $c, $s ) { return $c && true === $s['needs_confirmation']; }, true ) );

// Absent provider: the hosted connector is listed as optional and does not close the AI gate (owner decision, #400).
$no_connector = $S::review( array_merge( $full, array( 'connector_available' => false ) ) );
rv_ok( 'no connector still opens the AI gate (connector is not required before the gate opens)', true === $no_connector['gate']['allowed'] && array() === $no_connector['gate']['missing'] );
rv_ok( 'no connector is listed as an optional item', in_array( 'hosted_connector', rv_keys( $no_connector['missing'] ), true ) );
$connector_item = array_values( array_filter( $no_connector['missing'], static function ( $i ) { return 'hosted_connector' === $i['key']; } ) );
rv_ok( 'no connector is not a required setup item', 1 === count( $connector_item ) && false === $connector_item[0]['required'] );
rv_ok( 'no connector still gives suggestions for the rest of setup', ! empty( $no_connector['suggestions'] ) );

// Upgraded site: an existing greeting and an existing FAQ are kept, not offered again.
$upgraded = $S::review( array_merge( $full, array(
	'greeting'      => 'Welcome, thanks for calling Sample Plumbing.',
	'faq_questions' => array( 'Do you offer Drain unblocking?' ),
) ) );
$up_types = array_column( $upgraded['suggestions'], 'type' );
rv_ok( 'upgraded site has no greeting suggestion', ! in_array( 'greeting', $up_types, true ) );
$up_faq = array_values( array_filter( $upgraded['suggestions'], static function ( $s ) { return 'faq' === $s['type']; } ) );
rv_ok( 'upgraded site is not offered the same FAQ again', 1 === count( $up_faq ) && false !== strpos( $up_faq[0]['value']['question'], 'Hot water' ) );

// No invented facts: no figures, prices or hours in any suggestion text.
$texts = array();
foreach ( $ready['suggestions'] as $s ) { $texts[] = $S::describe( $s ); }
$joined = implode( ' | ', $texts );
rv_ok( 'suggestion text has no figures, prices or times', 0 === preg_match( '/\d|\$|\b(am|pm)\b/i', $joined ) );
rv_ok( 'describe returns the FAQ and tag lines', false !== strpos( $joined, 'FAQ: Do you offer Drain unblocking?' ) && false !== strpos( $joined, 'Tags: drain-unblocking' ) && false === strpos( $joined, 'Greeting: ' ) );

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

// Saved FAQ entries are read for the duplicate check (upgraded sites). Reads only, skips unusable rows.
$saved = $S::faq_questions_from_entries( array(
	array( 'id' => 'a', 'question' => 'Do you offer Drain unblocking?', 'answer' => 'Yes.' ),
	array( 'id' => 'b', 'question' => '   ', 'answer' => 'Blank question is skipped.' ),
	array( 'id' => 'c', 'answer' => 'No question key is skipped.' ),
	'not-an-array',
	array( 'id' => 'd', 'question' => array( 'nested' ) ),
) );
rv_ok( 'saved FAQ entries give only usable questions', array( 'Do you offer Drain unblocking?' ) === $saved );
rv_ok( 'no saved entries gives an empty list', array() === $S::faq_questions_from_entries( array() ) );
$saved_review = $S::review( array_merge( $full, array( 'faq_questions' => $saved ) ) );
$saved_faq = array_values( array_filter( $saved_review['suggestions'], static function ( $s ) { return 'faq' === $s['type']; } ) );
rv_ok( 'saved FAQ question is not suggested again', 1 === count( $saved_faq ) && false !== strpos( $saved_faq[0]['value']['question'], 'Hot water' ) );
rv_ok( 'duplicate match ignores case and spacing', 1 === count( array_filter( $S::review( array_merge( $full, array( 'faq_questions' => array( '  do you OFFER drain unblocking?  ' ) ) ) )['suggestions'], static function ( $s ) { return 'faq' === $s['type']; } ) ) );

// describe ignores unknown types.
rv_ok( 'describe ignores unknown suggestion types', '' === $S::describe( array( 'type' => 'price', 'value' => '60' ) ) );


// #171 / #254: missing configuration blocks enabling AI; administrator confirmation is required.
$enable_empty = $S::enable_ai( array(), true, 1700000000 );
rv_ok( 'enable AI refused on empty site with missing_configuration', false === $enable_empty['ok'] && 'missing_configuration' === $enable_empty['error'] && in_array( 'business_name', $enable_empty['missing'], true ) );
rv_ok( 'enable AI refused on empty site leaves consent off', false === TZFD_Ai_Free_Core::consent_granted() );
$enable_unconfirmed = $S::enable_ai( $full, false, 1700000000 );
rv_ok( 'enable AI refused without administrator confirmation', false === $enable_unconfirmed['ok'] && 'admin_confirmation_required' === $enable_unconfirmed['error'] );
rv_ok( 'unconfirmed enable leaves consent off', false === TZFD_Ai_Free_Core::consent_granted() );
$no_key_required = array_filter( $S::missing( array_merge( $full, array( 'connector_available' => false ) ) ), static function ( $i ) { return $i['required']; } );
rv_ok( 'no AI connector adds no required setup item (no AI needed to configure or book)', array() === $no_key_required );
rv_ok( 'confirm refused without administrator confirmation', false === $S::confirm( array( 'type' => 'greeting', 'value' => 'Hi there.', 'source' => 'template' ), array() )['ok'] );
$confirmed_greeting = $S::confirm( array( 'type' => 'greeting', 'value' => 'Hi there.', 'source' => 'template' ), array( 'confirmed' => true ) );
rv_ok( 'confirm still validates a greeting when one is passed in directly', true === $confirmed_greeting['ok'] && 'Hi there.' === $confirmed_greeting['value'] );
$enable_ok = $S::enable_ai( $full, true, 1700000000 );
rv_ok( 'complete site with confirmation enables AI', true === $enable_ok['ok'] && true === TZFD_Ai_Free_Core::consent_granted() );

echo 'Passed: ' . $GLOBALS['tzfd_pass'] . "\n";
echo 'Failed: ' . $GLOBALS['tzfd_fail'] . "\n";
exit( 0 === $GLOBALS['tzfd_fail'] ? 0 : 1 );
