<?php
/**
 * Real-WordPress checks for the AI answer box form on plain permalinks (issue #185).
 *
 * On plain permalinks the page is ?page_id=N. A GET form with no action replaces that query string on submit, so the
 * question landed on the home page and the answer was never shown. The form now posts to the page's own URL and carries
 * the page's query as hidden fields. These checks render the shortcode for a page and read the form it produces.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/ai-answer-box-form-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_ab'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_ab_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_ab'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-ai-widget.php' ) ) {
	tzfd_ab_ok( 'ai-answer-box: widget class readable', false, 'class-tzfd-ai-widget.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-ai-widget.php';

// A page that carries the answer box, as a visitor would reach it.
$ab_page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AI answer box runtime test',
		'post_content' => '[tzfd_ai_answer_box]',
	)
);
tzfd_ab_ok( 'ai-answer-box: test page created', $ab_page_id > 0 );

$GLOBALS['post'] = get_post( $ab_page_id );
setup_postdata( $GLOBALS['post'] );
$ab_html = TZFD_AI_Widget::render();
wp_reset_postdata();

if ( preg_match( '/<form class="tzfd-ai-form" action="([^"]*)" method="get">/', $ab_html, $ab_m ) ) {
	$ab_action = html_entity_decode( $ab_m[1] );
} else {
	$ab_action = null;
}
tzfd_ab_ok( 'ai-answer-box: form has an explicit action', null !== $ab_action && '' !== $ab_action, null === $ab_action ? 'no action attribute on the form' : '' );
tzfd_ab_ok( 'ai-answer-box: form action points at the page, not the home page', null !== $ab_action && false === strpos( $ab_action, '?' ) && false !== strpos( $ab_action, 'http' ) );

// The page id travels as a hidden field, so the submitted URL still opens this page on plain permalinks.
tzfd_ab_ok( 'ai-answer-box: page_id sent as a hidden field', false !== strpos( $ab_html, '<input type="hidden" name="page_id" value="' . $ab_page_id . '"' ) );
tzfd_ab_ok( 'ai-answer-box: question field is still named tzfd_ai_q', false !== strpos( $ab_html, 'name="tzfd_ai_q"' ) );

// Simulate the submitted request: the browser sends the hidden page_id and the question together.
$ab_saved = $_GET;
$_GET     = array( 'page_id' => (string) $ab_page_id, 'tzfd_ai_q' => 'What are your opening hours?' );
$GLOBALS['post'] = get_post( $ab_page_id );
setup_postdata( $GLOBALS['post'] );
$ab_answered = TZFD_AI_Widget::render();
wp_reset_postdata();
$_GET = $ab_saved;
tzfd_ab_ok( 'ai-answer-box: submitted question renders the answer box on the page', false !== strpos( $ab_answered, 'class="tzfd-ai-answer"' ) && false !== strpos( $ab_answered, 'value="What are your opening hours?"' ) );

wp_delete_post( $ab_page_id, true );

echo "ai-answer-box: {$GLOBALS['tzfd_ab']['PASS']} passed, {$GLOBALS['tzfd_ab']['FAIL']} failed\n";
exit( $GLOBALS['tzfd_ab']['FAIL'] > 0 ? 1 : 0 );
