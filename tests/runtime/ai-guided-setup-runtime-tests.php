<?php
/**
 * Real-WordPress checks for the AI-guided setup review step (issue #450; builds on #40, #171 and #400).
 * The review step lists suggestions with tick boxes for FAQs. Only ticked FAQs are saved, unapproved.
 * Tags are listed but never ticked and never saved. The greeting is not offered (written at first contact, #400).
 * AI is never turned on from the wizard.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from the CI "Extra WordPress test files" step:
 *   wp eval-file tests/runtime/ai-guided-setup-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 *
 * Limit: the wizard's POST handler ends each branch with exit, so this file cannot call handle_post() and keep
 * running. It drives the review markup and the write path (apply_ai_setup) directly, through reflection. The write
 * path is the one the handler calls with the sanitized ai_pick values.
 * Real-WordPress checks for the AI-guided setup steps (issues #40, #171, #450): the optional opening-hours step keeps
 * saved hours and never invents them, the review step lists what is missing and offers only the tags that the
 * services produce, rendering writes nothing, and the finish action refuses a request without a valid nonce or
 * without the setup capability.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/ai-guided-setup-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called. No hosted AI is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_ais'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_ais_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_ais'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

/** Calls a private static method of the onboarding wizard. */
function tzfd_ais_call( $method, array $args ) {
	$ref = new ReflectionMethod( 'TZFD_Onboarding', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

/** Renders the review step to a string. */
function tzfd_ais_render( array $draft ) {
	ob_start();
	tzfd_ais_call( 'render_ai_setup_review', array( $draft ) );
	return (string) ob_get_clean();
}

if ( ! class_exists( 'TZFD_Onboarding' ) || ! class_exists( 'TZFD_Ai_Setup_Check' ) || ! class_exists( 'TZFD_Faq_Retrieval' ) || ! class_exists( 'TZFD_Scoped_Store' ) ) {
	tzfd_ais_ok( 'ai-setup: wizard classes are loaded', false, 'TZFD_Onboarding, TZFD_Ai_Setup_Check, TZFD_Faq_Retrieval or TZFD_Scoped_Store is missing' );
	exit( 1 );
}

wp_set_current_user( 1 );
$ais_company = (int) TZFD_Scoped_Store::company_id();
tzfd_ais_ok( 'ai-setup: a company is selected on the test site', $ais_company > 0 );
if ( $ais_company < 1 ) {
	echo 'Summary (ai-guided-setup-runtime): 0 passed, 1 failed' . "\n";
	exit( 1 );
}

// Keep the site's own FAQs and tags aside, start from none, and put them back at the end.
$ais_orig_entries = TZFD_Faq_Retrieval::all_entries( $ais_company );
$ais_orig_tags    = get_option( TZFD_Ai_Setup_Check::TAGS_OPTION, null );
TZFD_Faq_Retrieval::save_entries( $ais_company, array() );
delete_option( TZFD_Ai_Setup_Check::TAGS_OPTION );

// A small wizard draft. The names are unusual so they cannot collide with other suites on this site.
$ais_draft = array(
	'vertical'   => '',
	'trades'     => array(),
	'candidates' => array(),
	'services'   => array( array( 'name' => 'Gutter cleaning' ), array( 'name' => 'Pipe relining' ) ),
	'details'    => array( 'business_name' => 'RT Gutter and Pipe Co', 'opening_hours' => 'Mon-Fri 8am-5pm' ),
);

// The suggestions the review step should offer for this draft, read from the same state the wizard uses.
$ais_state    = tzfd_ais_call( 'ai_setup_state', array( $ais_draft ) );
$ais_offered  = TZFD_Ai_Setup_Check::suggestions( $ais_state );
$ais_faq_ids  = array();
$ais_tag_id   = '';
$ais_greet    = 0;
foreach ( $ais_offered as $ais_s ) {
	if ( 'faq' === $ais_s['type'] ) { $ais_faq_ids[] = $ais_s['id']; }
	if ( 'tags' === $ais_s['type'] ) { $ais_tag_id = $ais_s['id']; }
	if ( 'greeting' === $ais_s['type'] ) { $ais_greet++; }
}

// 1. Suggestions are listed on the review step.
tzfd_ais_ok( 'ai-setup: two FAQ suggestions are offered for two services', 2 === count( $ais_faq_ids ) );
tzfd_ais_ok( 'ai-setup: a tag suggestion is offered for the services', '' !== $ais_tag_id );
tzfd_ais_ok( 'ai-setup: no greeting is offered on the review step', 0 === $ais_greet );

$ais_html = tzfd_ais_render( $ais_draft );
tzfd_ais_ok( 'ai-setup: review lists the suggestions', false !== strpos( $ais_html, 'Suggestions for you to review:' ) );
tzfd_ais_ok( 'ai-setup: each FAQ suggestion has its question text', false !== strpos( $ais_html, 'FAQ: Do you offer Gutter cleaning?' ) && false !== strpos( $ais_html, 'FAQ: Do you offer Pipe relining?' ) );
tzfd_ais_ok( 'ai-setup: the review posts the ai_setup action and the nonce', false !== strpos( $ais_html, 'name="tz_fd_setup_action" value="ai_setup"' ) && false !== strpos( $ais_html, 'name="tz_fd_setup_nonce"' ) );
tzfd_ais_ok( 'ai-setup: the review form posts back to the setup review step', false !== strpos( $ais_html, 'page=tzfd-setup&amp;step=9' ) );

// 2. The tick boxes: one per FAQ, none for the tag, none for the greeting.
tzfd_ais_ok( 'ai-setup: exactly two tick boxes, one per FAQ', 2 === substr_count( $ais_html, 'name="ai_pick[]"' ) );
$ais_all_faq_ticked = true;
foreach ( $ais_faq_ids as $ais_id ) {
	if ( false === strpos( $ais_html, 'value="' . $ais_id . '"' ) ) { $ais_all_faq_ticked = false; }
}
tzfd_ais_ok( 'ai-setup: each FAQ has a tick box with its id', $ais_all_faq_ticked );
tzfd_ais_ok( 'ai-setup: the tag is listed as not saved by this step', false !== strpos( $ais_html, 'not saved by this step' ) );
tzfd_ais_ok( 'ai-setup: the tag has no tick box', false === strpos( $ais_html, 'value="' . $ais_tag_id . '"' ) );
tzfd_ais_ok( 'ai-setup: no greeting line on the review step', false === strpos( $ais_html, 'Greeting:' ) );
tzfd_ais_ok( 'ai-setup: the review states that FAQs are saved unapproved', false !== strpos( $ais_html, 'saved as not approved' ) );

// 3. Nothing is saved before the administrator posts a tick.
tzfd_ais_ok( 'ai-setup: no FAQ is saved before any tick', array() === TZFD_Faq_Retrieval::all_entries( $ais_company ) );
tzfd_ais_ok( 'ai-setup: no tag is saved before any tick', array() === TZFD_Ai_Setup_Check::saved_tags() );

// 4. Ticking one FAQ saves only that FAQ, unapproved. The unticked FAQ is not saved.
$ais_written = (int) tzfd_ais_call( 'apply_ai_setup', array( $ais_draft, array( $ais_faq_ids[1] ) ) );
tzfd_ais_ok( 'ai-setup: one ticked FAQ is written', 1 === $ais_written, 'written=' . $ais_written );
$ais_entries = TZFD_Faq_Retrieval::all_entries( $ais_company );
tzfd_ais_ok( 'ai-setup: only the ticked FAQ is in the store', 1 === count( $ais_entries ) && 'Do you offer Pipe relining?' === ( $ais_entries[0]['question'] ?? '' ) );
tzfd_ais_ok( 'ai-setup: the saved FAQ is unapproved', 1 === count( $ais_entries ) && false === ( $ais_entries[0]['approved'] ?? true ) );
tzfd_ais_ok( 'ai-setup: the unticked FAQ is not saved', false === strpos( wp_json_encode( $ais_entries ), 'Gutter cleaning' ) );
tzfd_ais_ok( 'ai-setup: an unapproved FAQ is never answered from', array() === TZFD_Faq_Retrieval::approved_entries( $ais_company ) );
tzfd_ais_ok( 'ai-setup: no tag is saved after a FAQ tick', array() === TZFD_Ai_Setup_Check::saved_tags() );

// 5. Items that were not ticked, or were typed in by hand, are refused. Nothing new is saved.
$ais_tag_post = (int) tzfd_ais_call( 'apply_ai_setup', array( $ais_draft, array( $ais_tag_id ) ) );
tzfd_ais_ok( 'ai-setup: a tag id posted by hand is not saved', 0 === $ais_tag_post && array() === TZFD_Ai_Setup_Check::saved_tags() );
$ais_unknown = (int) tzfd_ais_call( 'apply_ai_setup', array( $ais_draft, array( 'not-a-real-id' ) ) );
tzfd_ais_ok( 'ai-setup: an id the review does not offer is not saved', 0 === $ais_unknown && 1 === count( TZFD_Faq_Retrieval::all_entries( $ais_company ) ) );
$ais_greet_before = TZFD_Scoped_Store::get( 'tz_fd_ai_greeting', '' );
$ais_greet_post   = (int) tzfd_ais_call( 'apply_ai_setup', array( $ais_draft, array( 'greeting' ) ) );
tzfd_ais_ok( 'ai-setup: a greeting id posted by hand is not written', 0 === $ais_greet_post && $ais_greet_before === TZFD_Scoped_Store::get( 'tz_fd_ai_greeting', '' ) );
$ais_none = (int) tzfd_ais_call( 'apply_ai_setup', array( $ais_draft, array() ) );
tzfd_ais_ok( 'ai-setup: nothing ticked saves nothing', 0 === $ais_none && 1 === count( TZFD_Faq_Retrieval::all_entries( $ais_company ) ) );

// 6. After saving, the saved FAQ is no longer offered, so it cannot be saved twice.
$ais_state2   = tzfd_ais_call( 'ai_setup_state', array( $ais_draft ) );
$ais_faq_now  = array();
foreach ( TZFD_Ai_Setup_Check::suggestions( $ais_state2 ) as $ais_s ) {
	if ( 'faq' === $ais_s['type'] ) { $ais_faq_now[] = $ais_s['id']; }
}
tzfd_ais_ok( 'ai-setup: the saved FAQ is not offered again', array( $ais_faq_ids[0] ) === $ais_faq_now );
$ais_html2 = tzfd_ais_render( $ais_draft );
tzfd_ais_ok( 'ai-setup: the review shows one tick box after one save', 1 === substr_count( $ais_html2, 'name="ai_pick[]"' ) );
$ais_again = (int) tzfd_ais_call( 'apply_ai_setup', array( $ais_draft, array( $ais_faq_ids[1] ) ) );
tzfd_ais_ok( 'ai-setup: ticking the same FAQ again saves nothing new', 0 === $ais_again && 1 === count( TZFD_Faq_Retrieval::all_entries( $ais_company ) ) );

// Clean up: put back the site's own FAQs, tags and greeting state.
TZFD_Faq_Retrieval::save_entries( $ais_company, $ais_orig_entries );
if ( null === $ais_orig_tags ) {
	delete_option( TZFD_Ai_Setup_Check::TAGS_OPTION );
} else {
	update_option( TZFD_Ai_Setup_Check::TAGS_OPTION, $ais_orig_tags, false );
}

echo 'Summary (ai-guided-setup-runtime): ' . (int) $GLOBALS['tzfd_ais']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_ais']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_ais']['FAIL'] > 0 ? 1 : 0 );
