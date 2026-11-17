<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions -- standalone CLI harness: prints plain test text to a terminal, never HTML.
/**
 * Refused capability checks must answer HTTP 403, not the WordPress default 500 (security review finding).
 * Pure PHP with wp-stubs.php. No database, no network. Run from the repository root:
 *   php tests/unit/refusal-status-tests.php
 *
 * Each admin refusal is called with the capability denied. wp_die() is stubbed to record its arguments and throw,
 * so the harness sees the status and message the plugin would send. The message text is checked too, so only the
 * status changes.
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return $s; } }
if ( ! function_exists( 'add_submenu_page' ) ) { function add_submenu_page( ...$args ) { return true; } }

final class TZFD_Refusal_Die extends RuntimeException {}

if ( ! function_exists( 'wp_die' ) ) {
	/** Records what WordPress would receive, then stops the request the way wp_die() does. */
	function wp_die( $message = '', $title = '', $args = array() ) {
		$GLOBALS['tzfd_refusal'] = array( 'message' => $message, 'title' => $title, 'args' => $args );
		throw new TZFD_Refusal_Die( 'wp_die' );
	}
}

$plugin_inc = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/';
foreach ( array(
	'class-tzfd-pipeline-screen.php',
	'class-tzfd-admin-menu.php',
	'class-tzfd-customer-admin.php',
	'class-tzfd-scheduling-preview.php',
) as $file ) {
	require_once $plugin_inc . $file;
}

$GLOBALS['tzfd_pass'] = 0;
$GLOBALS['tzfd_fail'] = 0;
function rs_check( $name, $ok ) {
	if ( $ok ) { $GLOBALS['tzfd_pass']++; } else { $GLOBALS['tzfd_fail']++; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}

// Signed-in subscriber: the capability is refused.
$GLOBALS['tzfd_test_caps'] = false;

// Refusals that follow a current_user_can() check and sent HTTP 500 before this change, with the message text they keep.
$cases = array(
	array( 'TZFD_Pipeline_Screen', 'render', 'You do not have permission to view this page.' ),
	array( 'TZFD_Pipeline_Screen', 'handle', 'You do not have permission to do that.' ),
	array( 'TZFD_Admin_Menu', 'portal_builder', 'Access denied.' ),
	array( 'TZFD_Customer_Admin', 'page', 'Not permitted.' ),
	array( 'TZFD_Scheduling_Preview', 'page', 'You cannot access this preview.' ),
);

foreach ( $cases as $case ) {
	list( $class, $method, $message ) = $case;
	$label = $class . '::' . $method;

	$GLOBALS['tzfd_refusal'] = null;
	// handle() checks the request method before the capability; a POST reaches the capability refusal.
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$reached = false;
	try {
		call_user_func( array( $class, $method ) );
	} catch ( TZFD_Refusal_Die $e ) {
		$reached = true;
	} catch ( Throwable $e ) {
		rs_check( $label . ' ran without a fatal error (' . $e->getMessage() . ')', false );
		continue;
	}
	rs_check( $label . ' refusal reaches wp_die()', $reached && is_array( $GLOBALS['tzfd_refusal'] ) );
	if ( ! is_array( $GLOBALS['tzfd_refusal'] ) ) { continue; }

	$args    = $GLOBALS['tzfd_refusal']['args'];
	$status  = is_array( $args ) && isset( $args['response'] ) ? $args['response'] : null;
	$msg_ok  = $GLOBALS['tzfd_refusal']['message'] === esc_html__( $message, 'titan-zero-trades-front-desk' );

	rs_check( $label . ' sends HTTP 403 (got ' . var_export( $status, true ) . ')', 403 === $status );
	rs_check( $label . ' keeps its message text', $msg_ok );
}

echo "\nSummary: {$GLOBALS['tzfd_pass']} passed, {$GLOBALS['tzfd_fail']} failed\n";
exit( $GLOBALS['tzfd_fail'] > 0 ? 1 : 0 );
