<?php
/**
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

$GLOBALS['tzfd_ags'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_ags_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_ags'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
$tzfd_add = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/addons/core/onboarding.addon.php';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-ai-setup-check.php' ) || ! is_readable( $tzfd_add ) ) {
	tzfd_ags_ok( 'ai-setup: files readable', false, 'AI setup check or onboarding addon not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-ai-free-services.php';
require_once $tzfd_inc . 'class-tzfd-ai-setup-check.php';
require_once $tzfd_add;

$ags_company = TZFD_Scoped_Store::company_id();
tzfd_ags_ok( 'ai-setup: a company is selected for the run', $ags_company > 0, 'company=' . (int) $ags_company );
if ( $ags_company < 1 ) {
	echo 'Summary (ai-guided-setup-runtime): ' . (int) $GLOBALS['tzfd_ags']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_ags']['FAIL'] . " failed\n";
	exit( 1 );
}

wp_set_current_user( 1 );
$ags_obj = null;
global $tzfd_addons_objs_list;
foreach ( (array) $tzfd_addons_objs_list as $ags_candidate ) {
	if ( $ags_candidate instanceof TZFD_Onboarding ) { $ags_obj = $ags_candidate; break; }
}
tzfd_ags_ok( 'ai-setup: onboarding addon object is loaded', null !== $ags_obj );

$ags_hours_key = TZFD_Onboarding::HOURS_KEY;
$ags_before_tags = get_option( TZFD_Ai_Setup_Check::TAGS_OPTION, false );

// Seed a draft with one service and a business name, and no hours yet.
$ags_draft = array(
	'vertical' => '',
	'trades'   => array(),
	'services' => array( array( 'name' => 'Drain unblocking' ) ),
	'details'  => array( 'business_name' => 'Runtime Setup Co', 'intake_mode' => 'instant' ),
);
TZFD_Scoped_Store::update( TZFD_Onboarding::DRAFT, $ags_draft );
TZFD_Scoped_Store::delete( $ags_hours_key );

// 1. Opening hours: blank with nothing saved stays blank. No hours are invented.
TZFD_Onboarding::apply_details( array( 'business_name' => 'Runtime Setup Co', 'opening_hours' => '' ), $ags_draft['services'] );
tzfd_ags_ok( 'ai-setup: blank hours invent nothing', '' === (string) TZFD_Scoped_Store::get( $ags_hours_key, '' ) );

// 2. Typed hours are stored.
TZFD_Onboarding::apply_details( array( 'business_name' => 'Runtime Setup Co', 'opening_hours' => 'Sat 9am to noon' ), $ags_draft['services'] );
tzfd_ags_ok( 'ai-setup: typed hours are stored', 'Sat 9am to noon' === (string) TZFD_Scoped_Store::get( $ags_hours_key, '' ) );

// 3. A blank answer later keeps the saved hours (never overwritten with empty text).
TZFD_Onboarding::apply_details( array( 'business_name' => 'Runtime Setup Co', 'opening_hours' => '   ' ), $ags_draft['services'] );
tzfd_ags_ok( 'ai-setup: blank answer keeps saved hours', 'Sat 9am to noon' === (string) TZFD_Scoped_Store::get( $ags_hours_key, '' ) );

// 4. Hours count as configured only from the saved value or the typed answer, so the gate reads them correctly.
$ags_state = array( 'business_name' => 'Runtime Setup Co', 'services' => array( 'Drain unblocking' ), 'greeting' => '', 'hours_configured' => false, 'connector_available' => false );
tzfd_ags_ok( 'ai-setup: AI gate stays closed while hours are missing', false === TZFD_Ai_Setup_Check::ai_gate( $ags_state )['allowed'] );

// 5. Review step renders the check, lists the missing items and offers the tag tick box for the service.
TZFD_Scoped_Store::delete( $ags_hours_key );
$ags_get_step = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test harness only; restored below.
$_GET['step'] = '9';
ob_start();
$ags_obj->render();
$ags_html = (string) ob_get_clean();
$_GET = $ags_get_step;

tzfd_ags_ok( 'ai-setup: review step shows the AI setup check', false !== strpos( $ags_html, 'AI setup check' ) );
tzfd_ags_ok( 'ai-setup: review step lists opening hours as missing', false !== strpos( $ags_html, 'Opening hours' ) && false !== strpos( $ags_html, 'None are assumed' ) );
tzfd_ags_ok( 'ai-setup: review step offers the service tag, ticked', false !== strpos( $ags_html, 'name="ai_tags[]" value="drain-unblocking" checked' ) );
tzfd_ags_ok( 'ai-setup: review step states that AI stays off', false !== strpos( $ags_html, 'AI stays off' ) );

// 6. Rendering writes nothing: no tags are stored by showing the review.
tzfd_ags_ok( 'ai-setup: rendering the review stores no tags', get_option( TZFD_Ai_Setup_Check::TAGS_OPTION, false ) === $ags_before_tags );

// 7. Finish without a nonce is refused and stores no tags. wp_die is caught so the harness keeps running.
$ags_die_msg = '';
$ags_die_filter = function () {
	return function ( $message ) {
		throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
	};
};
add_filter( 'wp_die_handler', $ags_die_filter );
$ags_saved_post   = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test harness only; restored below.
$ags_saved_server = $_SERVER;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'tz_fd_setup_action' => 'finish', 'ai_tags' => array( 'drain-unblocking' ) );
$ags_refused = false;
try {
	$ags_obj->handle_post();
} catch ( RuntimeException $e ) {
	$ags_refused = true;
	$ags_die_msg = $e->getMessage();
}
$_POST   = $ags_saved_post;
$_SERVER = $ags_saved_server;
tzfd_ags_ok( 'ai-setup: finish without a nonce is refused', $ags_refused );
tzfd_ags_ok( 'ai-setup: refusal is the nonce check, not the write guard', $ags_refused && false === strpos( $ags_die_msg, 'Invalid or oversized' ), 'message=' . substr( $ags_die_msg, 0, 60 ) );
tzfd_ags_ok( 'ai-setup: refused finish stores no tags', get_option( TZFD_Ai_Setup_Check::TAGS_OPTION, false ) === $ags_before_tags );

// 8. Finish by a user without the setup capability is refused.
$ags_user = get_current_user_id();
wp_set_current_user( 0 );
$ags_cap_refused = false;
$ags_cap_msg     = '';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'tz_fd_setup_action' => 'finish', 'ai_tags' => array( 'drain-unblocking' ) );
try {
	$ags_obj->handle_post();
} catch ( RuntimeException $e ) {
	$ags_cap_refused = true;
	$ags_cap_msg     = $e->getMessage();
}
$_POST   = $ags_saved_post;
$_SERVER = $ags_saved_server;
wp_set_current_user( $ags_user ? $ags_user : 1 );
tzfd_ags_ok( 'ai-setup: finish without the setup capability is refused', $ags_cap_refused && false !== strpos( $ags_cap_msg, 'not allowed' ), 'message=' . substr( $ags_cap_msg, 0, 60 ) );
tzfd_ags_ok( 'ai-setup: refused capability check stores no tags', get_option( TZFD_Ai_Setup_Check::TAGS_OPTION, false ) === $ags_before_tags );

remove_filter( 'wp_die_handler', $ags_die_filter );

// Clean up disposable state written by this run.
TZFD_Scoped_Store::delete( TZFD_Onboarding::DRAFT );
TZFD_Scoped_Store::delete( $ags_hours_key );

echo 'Summary (ai-guided-setup-runtime): ' . (int) $GLOBALS['tzfd_ags']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_ags']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_ags']['FAIL'] > 0 ? 1 : 0 );
