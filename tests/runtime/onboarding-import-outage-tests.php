<?php
/**
 * Real-WordPress outage checks for the service imports in the setup wizard (issue #447, child of #340).
 *
 * The wizard reads services from a web page the administrator names (wp_safe_remote_get) and from a CSV upload.
 * When the page fetch fails, is refused or is oversized, and when the upload is bad, the wizard must return a
 * user-facing error, leave the draft's service candidates as they were, and leave normal booking working.
 *
 * Failures are simulated with pre_http_request, so no request leaves the sandbox. The page target is example.com, a
 * reserved domain. wp_safe_remote_get checks the host before that filter runs. If the sandbox cannot resolve
 * example.com, the page cases are reported as SKIP, not PASS. The private-address case (10.255.255.1) needs no DNS.
 *
 * Limits of this check:
 * - pre_http_request bypasses WordPress's limit_response_size. The oversize case therefore proves only the wizard's
 *   own MAX_HTML guard, not what WordPress does with a page larger than limit_response_size on the wire.
 * - is_uploaded_file() is false for any file this process creates, so an accepted upload cannot be driven in-process.
 *   The upload cases here are all refused. The accepted-upload path is not covered by this file.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/onboarding-import-outage-tests.php --user=1
 * Output: one line per check, "PASS|FAIL|SKIP name -- detail". The summary line is last.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_obo'] = array( 'PASS' => 0, 'FAIL' => 0, 'SKIP' => 0 );

function tzfd_obo_out( $status, $name, $detail = '' ) {
	$GLOBALS['tzfd_obo'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return 'PASS' === $status;
}

function tzfd_obo_ok( $name, $ok, $detail = '' ) {
	return tzfd_obo_out( $ok ? 'PASS' : 'FAIL', $name, $detail );
}

function tzfd_obo_skip( $name, $why ) {
	tzfd_obo_out( 'SKIP', $name, $why );
}

function tzfd_obo_response( $code, $body ) {
	return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
}

function tzfd_obo_summary_and_exit() {
	echo 'Summary (onboarding-import-outage): ' . (int) $GLOBALS['tzfd_obo']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_obo']['FAIL'] . ' failed, ' . (int) $GLOBALS['tzfd_obo']['SKIP'] . " skipped\n";
	exit( (int) $GLOBALS['tzfd_obo']['FAIL'] > 0 ? 1 : 0 );
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array(
	'class-tzfd-tables.php', 'class-tzfd-intake-mode.php', 'class-tzfd-final-booking-write.php',
	'class-tzfd-transaction-storage.php', 'class-tzfd-booking-create-transaction.php', 'class-tzfd-scoped-store.php',
) as $tzfd_file ) {
	if ( is_readable( $tzfd_inc . $tzfd_file ) ) {
		require_once $tzfd_inc . $tzfd_file;
	}
}

if ( ! class_exists( 'TZFD_Onboarding' ) || ! class_exists( 'TZFD_Scoped_Store' ) || ! class_exists( 'TZFD_Booking_Create_Transaction' ) ) {
	tzfd_obo_ok( 'onboarding-import: setup wizard and booking classes are loaded', false, 'a required class is missing from the active plugin' );
	tzfd_obo_summary_and_exit();
}

global $wpdb, $tzfd_addons_objs_list;
wp_set_current_user( 1 );

$tzfd_obo_company = (int) TZFD_Scoped_Store::company_id();
tzfd_obo_ok( 'onboarding-import: a company resolves for the administrator', $tzfd_obo_company > 0, 'company=' . $tzfd_obo_company );
if ( $tzfd_obo_company < 1 ) {
	tzfd_obo_summary_and_exit();
}

$tzfd_obo_bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$tzfd_obo_orig_draft = TZFD_Scoped_Store::get( TZFD_Onboarding::DRAFT, array() );
$tzfd_obo_orig_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$tzfd_obo_nonce = wp_create_nonce( 'tz_fd_setup' );

// The handler ends with wp_safe_redirect() + exit, and with wp_die() on bad input. Both are turned into exceptions here,
// so each case returns to this file. The redirect filter runs before the exit, so the step and error are still seen.
$tzfd_obo_redirect = function ( $location ) {
	throw new RuntimeException( 'REDIRECT ' . $location );
};
add_filter( 'wp_redirect', $tzfd_obo_redirect, 1 );

$tzfd_obo_die = function () {
	return function ( $message ) {
		throw new RuntimeException( 'DIE ' . wp_strip_all_tags( (string) $message ) );
	};
};
add_filter( 'wp_die_handler', $tzfd_obo_die );

// Simulated page responses. Only the mode changes between cases; every request is answered here, none leaves the sandbox.
$tzfd_obo_mode  = 'transport';
$tzfd_obo_calls = 0;
$tzfd_obo_http  = function ( $pre, $args, $url ) use ( &$tzfd_obo_mode, &$tzfd_obo_calls ) {
	$tzfd_obo_calls++;
	switch ( $tzfd_obo_mode ) {
		case 'transport':
			return new WP_Error( 'http_request_failed', 'simulated page outage' );
		case 'http500':
			return tzfd_obo_response( 500, '<html>server error</html>' );
		case 'oversize':
			return tzfd_obo_response( 200, str_repeat( 'x', TZFD_Onboarding::MAX_HTML + 1 ) );
		default:
			return tzfd_obo_response( 200, '<ul><li>Drain clearing $80</li></ul>' );
	}
};
add_filter( 'pre_http_request', $tzfd_obo_http, 10, 3 );

$tzfd_obo_fetch_msg = 'The service web page could not be fetched safely.';
$tzfd_obo_size_msg  = 'Invalid service web page response.';
$tzfd_obo_csv_msg   = 'Invalid CSV upload. Select a CSV file up to 256 KB.';
$tzfd_obo_page      = 'https://example.com/services';
$tzfd_obo_resolves  = (bool) wp_http_validate_url( $tzfd_obo_page );

/** Runs the setup handler for one POST. Returns the redirect step and error, or the wp_die message. */
function tzfd_obo_run( array $post, array $files ) {
	global $tzfd_addons_objs_list;
	$_POST    = $post;
	$_REQUEST = $post;
	$_FILES   = $files;
	$obo      = isset( $tzfd_addons_objs_list['tzfd-Onboarding'] ) ? $tzfd_addons_objs_list['tzfd-Onboarding'] : new TZFD_Onboarding();
	try {
		$obo->handle_post();
		return array( 'outcome' => 'returned', 'step' => '', 'error' => '' );
	} catch ( RuntimeException $e ) {
		$msg = $e->getMessage();
		if ( 0 === strpos( $msg, 'REDIRECT ' ) ) {
			parse_str( (string) wp_parse_url( substr( $msg, 9 ), PHP_URL_QUERY ), $query );
			return array(
				'outcome' => 'redirect',
				'step'    => (string) ( $query['step'] ?? '' ),
				'error'   => isset( $query['error'] ) ? rawurldecode( (string) $query['error'] ) : '',
			);
		}
		return array( 'outcome' => 'died', 'step' => '', 'error' => substr( $msg, 4 ) );
	}
}

/** Puts a known candidate list in the draft, so a later change to it is visible. Returns the seeded list. */
function tzfd_obo_seed() {
	$draft               = TZFD_Onboarding::draft();
	$draft['candidates'] = array( array( 'name' => 'Outage baseline service', 'price' => '', 'source' => 'typed' ) );
	TZFD_Scoped_Store::update( TZFD_Onboarding::DRAFT, $draft );
	return $draft['candidates'];
}

/** Writes one booking through the same transaction the customer form uses. Returns its status and the row change. */
function tzfd_obo_booking( $wpdb, $bookings, $company ) {
	$email  = 'obo-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
	$params = array( 'formid' => 1, 'name' => 'Outage Customer', 'email' => $email, 'notes' => 'outage runtime check' );
	$row    = array(
		'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1',
		'notifyto' => $email, 'posted_data' => serialize( $params ), 'data' => 'OUTAGE CUSTOMER', 'whoadded' => '1', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- posted_data holds the plugin's own PHP-serialized format
	);
	$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bookings ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache
	$res    = TZFD_Booking_Create_Transaction::create( $wpdb, $company, $row, $params, TZFD_Intake_Mode::MODE_INSTANT );
	$after  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bookings ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache
	return array( 'status' => (string) ( $res['status'] ?? 'none' ), 'added' => $after - $before );
}

/**
 * One failure case: the import must return the expected error with the draft unchanged, make the expected number of
 * HTTP calls (null = not asserted), and normal booking must still succeed with exactly one new row.
 */
$tzfd_obo_failure = function ( $label, $mode, array $post, array $files, $expect_error, $expect_calls ) use ( $wpdb, $tzfd_obo_bookings, $tzfd_obo_company, $tzfd_obo_nonce, &$tzfd_obo_mode, &$tzfd_obo_calls ) {
	$seed                = tzfd_obo_seed();
	$tzfd_obo_mode       = $mode;
	$tzfd_obo_calls      = 0;
	$post['tz_fd_setup_action'] = 'sources';
	$post['tz_fd_setup_nonce']  = $tzfd_obo_nonce;
	$out                 = tzfd_obo_run( $post, $files );
	$draft_after         = TZFD_Onboarding::draft()['candidates'];
	$calls_ok            = null === $expect_calls || $expect_calls === $tzfd_obo_calls;
	$detail              = 'outcome=' . $out['outcome'] . ' step=' . $out['step'] . ' error=' . var_export( $out['error'], true ) . ' calls=' . $tzfd_obo_calls;
	tzfd_obo_ok( "onboarding-import: {$label} returns a user-facing error", 'redirect' === $out['outcome'] && '2' === $out['step'] && $expect_error === $out['error'], $detail );
	if ( null !== $expect_calls ) {
		tzfd_obo_ok( "onboarding-import: {$label} makes {$expect_calls} HTTP call(s)", $calls_ok, 'calls=' . $tzfd_obo_calls );
	}
	tzfd_obo_ok( "onboarding-import: {$label} leaves the draft service list unchanged", $draft_after === $seed, 'candidates=' . count( (array) $draft_after ) );
	$bk = tzfd_obo_booking( $wpdb, $tzfd_obo_bookings, $tzfd_obo_company );
	tzfd_obo_ok( "onboarding-import: {$label} leaves normal booking working", 'success' === $bk['status'] && 1 === $bk['added'], 'status=' . $bk['status'] . ' rows_added=' . $bk['added'] );
};

$tzfd_obo_page_post = array( 'sources' => array( 'url' ), 'web_url' => $tzfd_obo_page );

// Positive controls: the same handler, with the same stubs, succeeds on a normal request. Without these, the failure
// results above would not show that the handler works at all.
$tzfd_obo_mode  = 'ok';
$tzfd_obo_calls = 0;
if ( $tzfd_obo_resolves ) {
	$out = tzfd_obo_run( array( 'tz_fd_setup_action' => 'sources', 'tz_fd_setup_nonce' => $tzfd_obo_nonce ) + $tzfd_obo_page_post, array() );
	$names = array_column( (array) TZFD_Onboarding::draft()['candidates'], 'name' );
	tzfd_obo_ok( 'onboarding-import: control, a normal page fetch moves to step 3 with no error', 'redirect' === $out['outcome'] && '3' === $out['step'] && '' === $out['error'], 'step=' . $out['step'] . ' error=' . var_export( $out['error'], true ) );
	tzfd_obo_ok( 'onboarding-import: control, the page service is read into the draft', in_array( 'Drain clearing', $names, true ), 'names=' . implode( '|', $names ) );
} else {
	tzfd_obo_skip( 'onboarding-import: control, normal page fetch', 'example.com is refused or does not resolve here, so the page path cannot be exercised' );
}
$tzfd_obo_mode = 'transport';
$out = tzfd_obo_run( array( 'tz_fd_setup_action' => 'sources', 'tz_fd_setup_nonce' => $tzfd_obo_nonce, 'sources' => array( 'site' ) ), array() );
tzfd_obo_ok( 'onboarding-import: control, a calendar-only import moves to step 3 with no error', 'redirect' === $out['outcome'] && '3' === $out['step'] && '' === $out['error'], 'step=' . $out['step'] . ' error=' . var_export( $out['error'], true ) );

// Page failures. Each case needs example.com to resolve; the private-address case does not.
if ( $tzfd_obo_resolves ) {
	$tzfd_obo_failure( 'failed page fetch (transport error)', 'transport', $tzfd_obo_page_post, array(), $tzfd_obo_fetch_msg, 1 );
	$tzfd_obo_failure( 'failed page fetch (HTTP 500)', 'http500', $tzfd_obo_page_post, array(), $tzfd_obo_fetch_msg, 1 );
	$tzfd_obo_failure( 'oversized page fetch', 'oversize', $tzfd_obo_page_post, array(), $tzfd_obo_size_msg, 1 );
} else {
	foreach ( array( 'failed page fetch (transport error)', 'failed page fetch (HTTP 500)', 'oversized page fetch' ) as $tzfd_obo_skipped ) {
		tzfd_obo_skip( "onboarding-import: {$tzfd_obo_skipped}", 'example.com is refused or does not resolve here' );
	}
}

// A private address is refused by wp_safe_remote_get before any request is made, whatever the stub would return.
$tzfd_obo_failure( 'page on a private address (unroutable)', 'transport', array( 'sources' => array( 'url' ), 'web_url' => 'https://10.255.255.1/services' ), array(), $tzfd_obo_fetch_msg, null );

// Bad CSV uploads. Each is refused before any parsing. None of them makes an HTTP call.
$tzfd_obo_csv_post = array( 'sources' => array( 'csv' ) );
$tzfd_obo_failure( 'CSV step with no file', 'transport', $tzfd_obo_csv_post, array(), $tzfd_obo_csv_msg, 0 );
$tzfd_obo_failure( 'CSV upload with a PHP upload error', 'transport', $tzfd_obo_csv_post, array(
	'services_csv' => array( 'name' => 'services.csv', 'type' => 'text/csv', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ),
), $tzfd_obo_csv_msg, 0 );
$tzfd_obo_failure( 'CSV upload with a non-CSV name', 'transport', $tzfd_obo_csv_post, array(
	'services_csv' => array( 'name' => 'services.txt', 'type' => 'text/plain', 'tmp_name' => '/nonexistent/tzfd-outage-447.txt', 'error' => 0, 'size' => 40 ),
), $tzfd_obo_csv_msg, 0 );
$tzfd_obo_failure( 'CSV upload over the size limit', 'transport', $tzfd_obo_csv_post, array(
	'services_csv' => array( 'name' => 'services.csv', 'type' => 'text/csv', 'tmp_name' => '/nonexistent/tzfd-outage-447.csv', 'error' => 0, 'size' => TZFD_Onboarding::MAX_CSV + 1 ),
), $tzfd_obo_csv_msg, 0 );
$tzfd_obo_failure( 'empty CSV upload', 'transport', $tzfd_obo_csv_post, array(
	'services_csv' => array( 'name' => 'services.csv', 'type' => 'text/csv', 'tmp_name' => '/nonexistent/tzfd-outage-447.csv', 'error' => 0, 'size' => 0 ),
), $tzfd_obo_csv_msg, 0 );

// A real local CSV that was not sent by an HTTP upload. is_uploaded_file() refuses it, which is the rule being tested.
$tzfd_obo_local_csv = tempnam( sys_get_temp_dir(), 'tzfdobo' );
if ( false !== $tzfd_obo_local_csv ) {
	file_put_contents( $tzfd_obo_local_csv, "name,price\nDrain clearing,80\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary test fixture
	$tzfd_obo_failure( 'CSV that was not an HTTP upload', 'transport', $tzfd_obo_csv_post, array(
		'services_csv' => array( 'name' => 'services.csv', 'type' => 'text/csv', 'tmp_name' => $tzfd_obo_local_csv, 'error' => 0, 'size' => filesize( $tzfd_obo_local_csv ) ),
	), $tzfd_obo_csv_msg, 0 );
	unlink( $tzfd_obo_local_csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the temporary test fixture
} else {
	tzfd_obo_skip( 'onboarding-import: CSV that was not an HTTP upload', 'could not create a temporary file' );
}

// Clean up: remove the hooks, restore the request globals and the draft this check found.
remove_filter( 'pre_http_request', $tzfd_obo_http, 10 );
remove_filter( 'wp_redirect', $tzfd_obo_redirect, 1 );
remove_filter( 'wp_die_handler', $tzfd_obo_die );
$_POST    = array();
$_REQUEST = array();
$_FILES   = array();
if ( null === $tzfd_obo_orig_method ) {
	unset( $_SERVER['REQUEST_METHOD'] );
} else {
	$_SERVER['REQUEST_METHOD'] = $tzfd_obo_orig_method;
}
TZFD_Scoped_Store::update( TZFD_Onboarding::DRAFT, $tzfd_obo_orig_draft );

tzfd_obo_summary_and_exit();
