<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions -- standalone CLI harness: prints plain test text to a terminal, never HTML.
/**
 * Checks for #400 T3 (greeting written at first contact) and T5 (confirmed enquiry tags in one per-site list).
 * Pure PHP with WordPress stubs. No database, no network. Run from the repository root: php tests/unit/ai-setup-steps-test.php
 */
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-free-services.php';

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function st_ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo 'FAIL: ' . $name . "\n";
}

$S = 'TZFD_Ai_Setup_Check';
$GLOBALS['tzfd_test_options'] = array();

// T3: the greeting is written at first customer contact, from the business name only.
st_ok( 'first contact writes a greeting when none exists', is_string( $S::first_contact_greeting( '', 'Sample Plumbing Co' ) ) );
st_ok( 'first contact greeting names the business', 'Hello, thanks for contacting Sample Plumbing Co. How can we help you today?' === $S::first_contact_greeting( '', 'Sample Plumbing Co' ) );
st_ok( 'first contact keeps an existing greeting (never replaced)', null === $S::first_contact_greeting( 'Welcome, thanks for calling.', 'Sample Plumbing Co' ) );
st_ok( 'whitespace-only existing greeting counts as none', is_string( $S::first_contact_greeting( "   \n", 'Sample Plumbing Co' ) ) );
st_ok( 'no business name means no greeting is invented', null === $S::first_contact_greeting( '', '   ' ) );
st_ok( 'markup in the business name is stripped from the greeting', false === strpos( (string) $S::first_contact_greeting( '', '<b>Sample</b> Co' ), '<' ) );
st_ok( 'greeting text is built from the name only, no hours or figures', 0 === preg_match( '/\d|\$|\b(am|pm)\b/i', (string) $S::first_contact_greeting( '', 'Sample Plumbing Co' ) ) );

// The greeting is no longer a suggestion, and the missing list says it is written at first contact.
$state = array( 'business_name' => 'Sample Plumbing Co', 'services' => array( 'Drain unblocking' ), 'greeting' => '', 'hours_configured' => true, 'connector_available' => true );
$types = array_column( $S::suggestions( $state ), 'type' );
st_ok( 'no greeting suggestion is made', ! in_array( 'greeting', $types, true ) );
$greeting_item = array_values( array_filter( $S::missing( $state ), static function ( $i ) { return 'greeting' === $i['key']; } ) );
st_ok( 'greeting is listed as optional and written at first contact', 1 === count( $greeting_item ) && false === $greeting_item[0]['required'] && false !== strpos( $greeting_item[0]['hint'], 'first customer enquiry' ) );

// T4: hours are missing until set. Blank hours are reported as missing, never invented.
$no_hours = $S::missing( array_merge( $state, array( 'hours_configured' => false ) ) );
st_ok( 'hours show as missing until set', in_array( 'hours', array_column( $no_hours, 'key' ), true ) );
st_ok( 'AI gate stays closed while hours are missing (existing required rule, unchanged)', false === $S::ai_gate( array_merge( $state, array( 'hours_configured' => false ) ) )['allowed'] );
st_ok( 'AI gate opens once hours are set', true === $S::ai_gate( $state )['allowed'] );

// T5: confirmed tags go to one per-site list. Nothing is stored without confirmation.
$tags = array( 'type' => 'tags', 'value' => array( 'drain-unblocking', 'hot-water' ), 'source' => 'services' );
$before = $GLOBALS['tzfd_test_options'];
$refused = $S::store_confirmed_tags( $tags, array( 'value' => array( 'drain-unblocking' ) ) );
st_ok( 'tags are not stored without admin confirmation', false === ( $refused['ok'] ?? false ) && 'admin_confirmation_required' === ( $refused['error'] ?? '' ) );
st_ok( 'refused tag save writes no option', $before === $GLOBALS['tzfd_test_options'] );

$saved = $S::store_confirmed_tags( $tags, array( 'confirmed' => true, 'value' => array( 'drain-unblocking' ) ) );
st_ok( 'confirmed tags are stored', true === $saved['ok'] && array( 'drain-unblocking' ) === $saved['value'] );
st_ok( 'stored tags are read back from the per-site list', array( 'drain-unblocking' ) === $S::saved_tags() );

$more = $S::store_confirmed_tags( $tags, array( 'confirmed' => true, 'value' => array( 'Hot Water!', 'drain-unblocking' ) ) );
st_ok( 'a second confirmation adds to the list without duplicates and slugs the tags', array( 'drain-unblocking', 'hot-water' ) === $more['value'] && array( 'drain-unblocking', 'hot-water' ) === $S::saved_tags() );

$wrong_type = $S::store_confirmed_tags( array( 'type' => 'faq', 'value' => array( 'x' ) ), array( 'confirmed' => true ) );
st_ok( 'store_confirmed_tags refuses a non-tag suggestion', false === ( $wrong_type['ok'] ?? false ) );

// Saved tags are not suggested again (upgraded sites).
$with_saved = $S::suggestions( array_merge( $state, array( 'tags' => $S::saved_tags() ) ) );
$tag_values = array();
foreach ( $with_saved as $s ) { if ( 'tags' === $s['type'] ) { $tag_values = $s['value']; } }
st_ok( 'a tag already saved is not suggested again', ! in_array( 'drain-unblocking', $tag_values, true ) );

echo 'Passed: ' . $GLOBALS['tzfd_pass'] . "\n";
echo 'Failed: ' . $GLOBALS['tzfd_fail'] . "\n";
exit( 0 === $GLOBALS['tzfd_fail'] ? 0 : 1 );
