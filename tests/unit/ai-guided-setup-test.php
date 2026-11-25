<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions -- standalone CLI harness: prints plain test text to a terminal, never HTML.
/**
 * Checks for the AI-guided setup flow (issue #450; builds on #40). Pure PHP with WordPress stubs. No database, no network.
 * Covers the planner that turns ticked suggestions into writes, and the wizard wiring on the review step.
 * Run from the repository root: php tests/unit/ai-guided-setup-test.php
 */
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-free-services.php';

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function gs_ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo 'FAIL: ' . $name . "\n";
}
function gs_ids( array $suggestions, $type ) {
	$out = array();
	foreach ( $suggestions as $s ) {
		if ( $type === $s['type'] ) { $out[] = $s['id']; }
	}
	return $out;
}

$S = 'TZFD_Ai_Setup_Check';

$state = array(
	'business_name'       => 'Sample Plumbing Co',
	'services'            => array( 'Drain unblocking', 'Hot water repair' ),
	'greeting'            => '',
	'hours_configured'    => true,
	'faq_questions'       => array(),
	'tags'                => array(),
	'connector_available' => false,
);

$offered = $S::suggestions( $state );
$greeting_id = gs_ids( $offered, 'greeting' )[0];
$faq_ids     = gs_ids( $offered, 'faq' );
$tag_id      = gs_ids( $offered, 'tags' )[0];

// Ids are stable: the same state gives the same ids, so a ticked id still matches on the next request.
gs_ok( 'suggestion ids are stable for the same state', gs_ids( $S::suggestions( $state ), 'faq' ) === $faq_ids && $greeting_id === gs_ids( $S::suggestions( $state ), 'greeting' )[0] );

// Ticking everything: greeting and both FAQs are planned, tags are skipped and say why.
$all = $S::plan_apply( $state, array_merge( array( $greeting_id ), $faq_ids, array( $tag_id ) ) );
gs_ok( 'all ticked: greeting planned', 'Hello, thanks for contacting Sample Plumbing Co. How can we help you today?' === $all['greeting'] );
gs_ok( 'all ticked: both FAQs planned', 2 === count( $all['faqs'] ) );
gs_ok( 'all ticked: FAQ answer keeps the service name and no invented figures', 'Yes, we offer Drain unblocking. Please contact us for details.' === $all['faqs'][0]['answer'] );
gs_ok( 'all ticked: tags are skipped, not written', isset( $all['skipped'][ $tag_id ] ) && 'not_saved_here' === $all['skipped'][ $tag_id ] );

// Ticking one FAQ only: nothing else is planned.
$one = $S::plan_apply( $state, array( $faq_ids[1] ) );
gs_ok( 'one FAQ ticked: only that FAQ is planned', 1 === count( $one['faqs'] ) && 'Do you offer Hot water repair?' === $one['faqs'][0]['question'] );
gs_ok( 'one FAQ ticked: no greeting is planned', null === $one['greeting'] );

// Nothing ticked: nothing is planned.
$none = $S::plan_apply( $state, array() );
gs_ok( 'nothing ticked: no greeting', null === $none['greeting'] );
gs_ok( 'nothing ticked: no FAQs', array() === $none['faqs'] );
gs_ok( 'nothing ticked: nothing skipped', array() === $none['skipped'] );

// An id the state does not offer (typed by hand, or stale) is refused, not written.
$forged = $S::plan_apply( $state, array( 'not-a-real-id' ) );
gs_ok( 'unknown id is refused as not_offered', 'not_offered' === ( $forged['skipped']['not-a-real-id'] ?? '' ) && null === $forged['greeting'] && array() === $forged['faqs'] );

// Duplicate ids are applied once.
$dup = $S::plan_apply( $state, array( $faq_ids[0], $faq_ids[0] ) );
gs_ok( 'duplicate id is applied once', 1 === count( $dup['faqs'] ) );

// Planning never writes: the options store is untouched.
$GLOBALS['tzfd_test_options'] = array();
$S::plan_apply( $state, array_merge( array( $greeting_id ), $faq_ids ) );
gs_ok( 'plan_apply writes nothing', array() === $GLOBALS['tzfd_test_options'] );

// A greeting already saved is not offered again, so a ticked greeting id is refused.
$with_greeting = array_merge( $state, array( 'greeting' => 'Welcome to Sample Plumbing.' ) );
$stale = $S::plan_apply( $with_greeting, array( $greeting_id ) );
gs_ok( 'existing greeting: old greeting id is refused', 'not_offered' === ( $stale['skipped'][ $greeting_id ] ?? '' ) && null === $stale['greeting'] );

// An FAQ already saved is not offered again (case and spacing ignored).
$with_faq = array_merge( $state, array( 'faq_questions' => array( '  DO YOU offer drain unblocking? ' ) ) );
$faq_again = $S::plan_apply( $with_faq, array( $faq_ids[0] ) );
gs_ok( 'existing FAQ: its id is refused', 'not_offered' === ( $faq_again['skipped'][ $faq_ids[0] ] ?? '' ) && array() === $faq_again['faqs'] );

// No business name: no greeting is offered, so nothing can be planned from it.
$no_name = array_merge( $state, array( 'business_name' => '' ) );
$nn = $S::plan_apply( $no_name, array( 'greeting' => 'x' ) );
gs_ok( 'no business name: no greeting planned', null === $nn['greeting'] );

// A confirmation without confirmed = true is refused by the core rule, not planned.
$refused = $S::confirm( $offered[0], array() );
gs_ok( 'confirm still needs admin confirmation', 'admin_confirmation_required' === $refused['error'] );

// Wiring check: the wizard review step has the ai_setup action, the checkbox list and the planner call.
$addon = file_get_contents( __DIR__ . '/../../titan-zero-trades-front-desk/addons/core/onboarding.addon.php' );
gs_ok( 'wizard: ai_setup action is handled in handle_post', false !== strpos( $addon, "'ai_setup' === \$action" ) );
gs_ok( 'wizard: review form posts ai_setup', false !== strpos( $addon, 'name="tz_fd_setup_action" value="ai_setup"' ) );
gs_ok( 'wizard: ticked ids use the ai_pick field', false !== strpos( $addon, 'name="ai_pick[]"' ) );
gs_ok( 'wizard: writes go through the planner', false !== strpos( $addon, 'TZFD_Ai_Setup_Check::plan_apply(' ) );
gs_ok( 'wizard: FAQs are saved unapproved', false !== strpos( $addon, "'approved' => false" ) );

echo 'AI guided setup tests: passed ' . $GLOBALS['tzfd_pass'] . ', failed ' . $GLOBALS['tzfd_fail'] . "\n";
exit( $GLOBALS['tzfd_fail'] > 0 ? 1 : 0 );
