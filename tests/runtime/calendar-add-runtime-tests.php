<?php
/**
 * Real-WordPress checks for "Add New" on the calendars list (issue #182).
 *
 * The list form posts the add flag `a` in the request body (POST). The router used to read only $_GET['a'], so the
 * POST went to the Dashboard and no calendar was created. These checks cover the routing decision for both methods and
 * the list handler's acceptance of the body flag.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/calendar-add-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_ca'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_ca_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_ca'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-admin-menu.php' ) || ! is_readable( $tzfd_inc . 'class-tzfd-form-list-input.php' ) ) {
	tzfd_ca_ok( 'calendar-add: class files readable', false, 'admin menu or list input class not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-admin-menu.php';
require_once $tzfd_inc . 'class-tzfd-form-list-input.php';

$CA = 'TZFD_Admin_Menu';

// Save and restore the superglobals this test changes.
$ca_get  = $_GET;
$ca_post = $_POST;

// 1. The list form's POST body flag routes to the list handler (was: Dashboard).
$_GET  = array( 'page' => 'tzfd' );
$_POST = array( 'a' => '1', 'name' => 'QA calendar', 'anonce' => 'x' );
tzfd_ca_ok( 'calendar-add: POST a=1 is an add request', true === $CA::is_calendar_add_request() );

// 2. The legacy GET form still routes to the list handler.
$_GET  = array( 'page' => 'tzfd', 'a' => '1' );
$_POST = array();
tzfd_ca_ok( 'calendar-add: GET a=1 is still an add request', true === $CA::is_calendar_add_request() );

// 3. A plain Dashboard request is not an add request.
$_GET  = array( 'page' => 'tzfd' );
$_POST = array();
tzfd_ca_ok( 'calendar-add: plain Dashboard request is not an add request', false === $CA::is_calendar_add_request() );

// 4. The list handler accepts the body flag exactly as the list form sends it (a=1, name, anonce).
$ca_valid = TZFD_Form_List_Input::validate( array( 'a' => '1', 'name' => 'QA calendar', 'anonce' => 'abc123' ) );
tzfd_ca_ok( 'calendar-add: list input accepts a=1 with a name and nonce', ! is_wp_error( $ca_valid ) && 'a' === $ca_valid['operation'] && 'QA calendar' === $ca_valid['name'] );

// 5. A create without a nonce is refused before any write.
$ca_nonce = TZFD_Form_List_Input::validate( array( 'a' => '1', 'name' => 'QA calendar' ) );
tzfd_ca_ok( 'calendar-add: create without a nonce is refused', is_wp_error( $ca_nonce ) );

$_GET  = $ca_get;
$_POST = $ca_post;

echo "calendar-add: {$GLOBALS['tzfd_ca']['PASS']} passed, {$GLOBALS['tzfd_ca']['FAIL']} failed\n";
exit( $GLOBALS['tzfd_ca']['FAIL'] > 0 ? 1 : 0 );
