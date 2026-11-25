<?php
/**
 * Real-WordPress outage checks for the setup wizard's service import (issue #447, from #340, #151 and #8).
 *
 * The import has two outside inputs: a web page fetched with wp_safe_remote_get() and a CSV upload. Three cases are
 * run on the real handler, TZFD_Onboarding::handle_post(), inside a disposable WordPress site:
 *   1. A failed fetch: a transport error (WP_Error), an HTTP 503, and an HTTP 404.
 *   2. An oversized page: the transport cuts the body at limit_response_size, and a full body larger than the limit.
 *   3. A bad CSV upload: an upload error code, a wrong extension, an empty file, and a file over the 256 KB limit.
 * Each bad case must give a user-facing error on the import step, keep the saved draft, and leave normal setup and
 * normal booking working afterwards.
 *
 * Only the outside world is answered here. The web fetch is answered at pre_http_request, so no request leaves the
 * sandbox. go() ends in wp_safe_redirect(); a 'wp_redirect' filter that throws carries the redirect URL back, so the
 * error text is read the way the setup screen shows it.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/onboarding-import-outage-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_oi'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_oi_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_oi'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array(
	'class-tzfd-tables.php', 'class-tzfd-intake-mode.php', 'class-tzfd-final-booking-write.php',
	'class-tzfd-transaction-storage.php', 'class-tzfd-booking-create-transaction.php',
	'class-tzfd-scoped-store.php', 'class-tzfd-stored-data.php', 'class-tzfd-resource-catalog.php',
	'class-tzfd-resource-availability.php', 'class-tzfd-slot-holds.php', 'class-tzfd-capacity-lock.php',
	'class-tzfd-booking-capacity-reader.php', 'class-tzfd-customer-cancellation.php',
	'class-tzfd-customer-rescheduling.php', 'class-tzfd-booking-reminder-bridge.php',
	'class-tzfd-booking-action-outbox.php', 'class-tzfd-booking-status-api.php',
	'class-tzfd-resource-admin.php',
) as $tzfd_file ) {
	if ( is_readable( $tzfd_inc . $tzfd_file ) ) {
		require_once $tzfd_inc . $tzfd_file;
	}
}

if ( ! class_exists( 'TZFD_Onboarding' ) || ! class_exists( 'TZFD_Booking_Create_Transaction' ) || ! class_exists( 'TZFD_Scoped_Store' ) ) {
	tzfd_oi_ok( 'onboarding-import: setup wizard and booking classes are loaded', false, 'TZFD_Onboarding or TZFD_Booking_Create_Transaction not found (addon tzfd-Onboarding must be active)' );
	exit( 1 );
}

wp_set_current_user( 1 );
global $wpdb;

$tzfd_company  = (int) TZFD_Scoped_Store::company_id();
$tzfd_bookings = $wpdb->prefix . 'tz_bookings';
// wp eval-file runs this file inside a function, so the helpers below (which use `global`) need these in $GLOBALS.
$GLOBALS['tzfd_company']  = $tzfd_company;
$GLOBALS['tzfd_bookings'] = $tzfd_bookings;
tzfd_oi_ok( 'onboarding-import: a company resolves for the administrator', $tzfd_company > 0, 'company=' . $tzfd_company );

/* ---- Harness state, reset for every case ---- */

$GLOBALS['tzfd_oi_http']       = null;   // The answer for the next web fetch (see the pre_http_request filter).
$GLOBALS['tzfd_oi_http_calls'] = 0;

// Web fetch answers. Only the outside world is replaced: the real wp_safe_remote_get() and the real HTTP API run.
$tzfd_oi_http_filter = function ( $pre, $args, $url ) {
	// Only the test hosts are answered here. Any other request is left to the real HTTP API (none is expected).
	if ( 0 !== strpos( (string) $url, 'https://menu.example.test/' ) ) {
		return $pre;
	}
	$GLOBALS['tzfd_oi_http_calls']++;
	$c = $GLOBALS['tzfd_oi_http'] ?? array( 'error' => 'no answer configured' );
	if ( isset( $c['error'] ) ) {
		return new WP_Error( 'http_request_failed', $c['error'] );
	}
	$body = (string) $c['body'];
	if ( ! empty( $c['truncate'] ) && isset( $args['limit_response_size'] ) ) {
		$body = substr( $body, 0, (int) $args['limit_response_size'] ); // The HTTP API cuts a bounded read at the limit.
	}
	return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => (int) $c['status'], 'message' => '' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $tzfd_oi_http_filter, 10, 3 );

/** Posts one request to the real wizard handler. Returns the step, the error text, and anything that escaped. */
function tzfd_oi_post( array $fields, array $files = array() ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST  = array_merge( array( 'tz_fd_setup_nonce' => wp_create_nonce( 'tz_fd_setup' ) ), $fields );
	// check_admin_referer() reads $_REQUEST, which a CLI process does not fill from the POST it sets here.
	$_REQUEST = array_merge( (array) $_REQUEST, $_POST );
	$_FILES = $files;
	$out    = array( 'step' => '', 'error' => '', 'threw' => '' );
	$redirect_filter = function ( $location ) {
		throw new RuntimeException( 'REDIRECT ' . (string) $location );
	};
	add_filter( 'wp_redirect', $redirect_filter, 1, 1 );
	try {
		( new ReflectionClass( 'TZFD_Onboarding' ) )->newInstanceWithoutConstructor()->handle_post();
		$out['threw'] = 'handler returned without redirecting';
	} catch ( RuntimeException $e ) {
		$msg = $e->getMessage();
		if ( 0 === strpos( $msg, 'REDIRECT ' ) ) {
			$query = (string) wp_parse_url( substr( $msg, 9 ), PHP_URL_QUERY );
			parse_str( $query, $q );
			$out['step']  = isset( $q['step'] ) ? (string) $q['step'] : '';
			$out['error'] = isset( $q['error'] ) ? rawurldecode( (string) $q['error'] ) : '';
		} else {
			$out['threw'] = $msg;
		}
	} catch ( Throwable $e ) {
		$out['threw'] = get_class( $e ) . ': ' . $e->getMessage();
	}
	remove_filter( 'wp_redirect', $redirect_filter, 1 );
	$_POST  = array();
	$_FILES = array();
	return $out;
}

/** Stores a wizard draft the way the wizard does, so each case starts from a known state. */
function tzfd_oi_set_draft( array $candidates ) {
	TZFD_Scoped_Store::update( TZFD_Onboarding::DRAFT, array(
		'vertical' => 'cleaning', 'trades' => array(), 'candidates' => $candidates, 'services' => array(), 'details' => array(),
	) );
}

function tzfd_oi_candidate_names() {
	$draft = TZFD_Scoped_Store::get( TZFD_Onboarding::DRAFT, array() );
	return array_values( array_map( function ( $c ) { return $c['name']; }, (array) ( $draft['candidates'] ?? array() ) ) );
}

/** A normal booking, written the same way the booking page writes one. Returns the create status. */
function tzfd_oi_booking( $label ) {
	global $wpdb, $tzfd_bookings, $tzfd_company;
	$email  = 'rt-447-' . substr( md5( uniqid( '', true ) ), 0, 10 ) . '@example.test';
	$params = array( 'formid' => 1, 'name' => 'RT Customer 447', 'email' => $email, 'notes' => 'onboarding outage check' );
	$row    = array(
		'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => $email,
		'posted_data' => serialize( $params ), 'data' => 'RT CUSTOMER 447', 'whoadded' => '1', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- posted_data holds the plugin's own PHP-serialized format
	);
	$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id=%d', $tzfd_bookings, $tzfd_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache
	$res    = TZFD_Booking_Create_Transaction::create( $wpdb, $tzfd_company, $row, $params, TZFD_Intake_Mode::MODE_INSTANT );
	$after  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id=%d', $tzfd_bookings, $tzfd_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw table count, bypasses the object cache
	$id     = (int) ( $res['id'] ?? 0 );
	tzfd_oi_ok( "onboarding-import: {$label}: normal booking still commits one row", ( $res['status'] ?? '' ) === 'success' && 1 === $after - $before, 'status=' . ( $res['status'] ?? 'none' ) );
	if ( $id > 0 ) {
		$wpdb->delete( $tzfd_bookings, array( 'id' => $id, 'company_id' => $tzfd_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes this run's own test row
	}
}

/** Normal setup after a failure: a pack import (no outside input) reaches the services step with its services. */
function tzfd_oi_normal_import( $label ) {
	$r     = tzfd_oi_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'pack' ) ) );
	$names = tzfd_oi_candidate_names();
	tzfd_oi_ok( "onboarding-import: {$label}: normal pack import still reaches the services step",
		'' === $r['threw'] && '3' === $r['step'] && '' === $r['error'],
		'step=' . $r['step'] . ' error=' . $r['error'] . ' threw=' . $r['threw'] );
	tzfd_oi_ok( "onboarding-import: {$label}: normal pack import saves the pack's services", count( $names ) > 0 && ! in_array( 'Saved before the outage', $names, true ), 'count=' . count( $names ) );
}

/** Each failing case: the error reaches the import step, the draft is unchanged, and normal work still runs. */
function tzfd_oi_check_failure( $label, $r, $message, $saved_name ) {
	tzfd_oi_ok( "onboarding-import: {$label}: handler does not throw", '' === $r['threw'], $r['threw'] );
	tzfd_oi_ok( "onboarding-import: {$label}: user sees the error on the import step",
		'2' === $r['step'] && $message === $r['error'],
		'step=' . $r['step'] . ' error=' . $r['error'] );
	tzfd_oi_ok( "onboarding-import: {$label}: the saved draft is unchanged", array( $saved_name ) === tzfd_oi_candidate_names() );
}

const TZFD_OI_FETCH_ERROR = 'The service web page could not be fetched safely.';
const TZFD_OI_PAGE_ERROR  = 'Invalid service web page response.';
const TZFD_OI_CSV_ERROR   = 'Invalid CSV upload. Select a CSV file up to 256 KB.';

/* ---- 1. A failed fetch ---- */

$tzfd_oi_url = 'https://menu.example.test/services';
$tzfd_oi_fetch_cases = array(
	'transport error (WP_Error)' => array( 'error' => 'simulated fetch outage' ),
	'HTTP 503 from the page'     => array( 'status' => 503, 'body' => '<ul><li>Drain clean $90</li></ul>' ),
	'HTTP 404 from the page'     => array( 'status' => 404, 'body' => '' ),
);
foreach ( $tzfd_oi_fetch_cases as $label => $answer ) {
	$saved_name = 'Saved before the fetch outage';
	tzfd_oi_set_draft( array( array( 'name' => $saved_name, 'price' => '', 'source' => 'pack' ) ) );
	$GLOBALS['tzfd_oi_http']       = $answer;
	$GLOBALS['tzfd_oi_http_calls'] = 0;
	$r = tzfd_oi_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'url' ), 'web_url' => $tzfd_oi_url ) );
	tzfd_oi_check_failure( "web fetch {$label}", $r, TZFD_OI_FETCH_ERROR, $saved_name );
	tzfd_oi_ok( "onboarding-import: web fetch {$label}: exactly one fetch attempt", 1 === $GLOBALS['tzfd_oi_http_calls'], 'calls=' . $GLOBALS['tzfd_oi_http_calls'] );
	tzfd_oi_normal_import( "web fetch {$label}" );
	tzfd_oi_booking( "web fetch {$label}" );
}

/* ---- 2. An oversized page ---- */

$tzfd_oi_max   = TZFD_Onboarding::MAX_HTML;
$tzfd_oi_filler = '<li>Gutter clean ' . str_repeat( 'x', 90 ) . '</li>';
$tzfd_oi_big    = str_repeat( $tzfd_oi_filler, (int) ceil( ( $tzfd_oi_max + 400000 ) / strlen( $tzfd_oi_filler ) ) );
$tzfd_oi_big_cases = array(
	'oversize page (transport cuts the body at the limit)' => array( 'status' => 200, 'body' => $tzfd_oi_big, 'truncate' => true ),
	'oversize page (full body returned)'                    => array( 'status' => 200, 'body' => $tzfd_oi_big, 'truncate' => false ),
);
foreach ( $tzfd_oi_big_cases as $label => $answer ) {
	$saved_name = 'Saved before the oversize page';
	tzfd_oi_set_draft( array( array( 'name' => $saved_name, 'price' => '', 'source' => 'pack' ) ) );
	$GLOBALS['tzfd_oi_http'] = $answer;
	$r = tzfd_oi_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'url' ), 'web_url' => 'https://menu.example.test/big-price-list' ) );
	tzfd_oi_check_failure( $label, $r, TZFD_OI_PAGE_ERROR, $saved_name );
	tzfd_oi_normal_import( $label );
	tzfd_oi_booking( $label );
}

// Boundary: a page of exactly the limit is still accepted, so the refusal starts only above it.
$tzfd_oi_small = '<ul><li>Gutter clean</li><li>Window wash</li></ul>';
tzfd_oi_set_draft( array() );
$GLOBALS['tzfd_oi_http'] = array( 'status' => 200, 'body' => $tzfd_oi_small . str_repeat( ' ', $tzfd_oi_max - strlen( $tzfd_oi_small ) ), 'truncate' => true );
$r = tzfd_oi_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'url' ), 'web_url' => 'https://menu.example.test/exact-limit' ) );
tzfd_oi_ok( 'onboarding-import: a page of exactly the limit is accepted', '3' === $r['step'] && '' === $r['error'], 'step=' . $r['step'] . ' error=' . $r['error'] );

/* ---- 3. A bad CSV upload ---- */

// A real upload cannot be made from WP-CLI, so these are upload records the handler must refuse before parsing.
$tzfd_oi_csv_ok = array( 'error' => 0, 'tmp_name' => '/nonexistent-upload-dir/phpXYZ', 'size' => 120, 'name' => 'services.csv' );
$tzfd_oi_csv_cases = array(
	'upload error code (too large for the server)' => array_merge( $tzfd_oi_csv_ok, array( 'error' => 1 ) ),
	'wrong extension (menu.txt)'                    => array_merge( $tzfd_oi_csv_ok, array( 'name' => 'menu.txt' ) ),
	'empty file'                                    => array_merge( $tzfd_oi_csv_ok, array( 'size' => 0 ) ),
	'over the 256 KB limit'                         => array_merge( $tzfd_oi_csv_ok, array( 'size' => TZFD_Onboarding::MAX_CSV + 1 ) ),
	'not sent by an upload (is_uploaded_file false)' => $tzfd_oi_csv_ok,
);
foreach ( $tzfd_oi_csv_cases as $label => $meta ) {
	$saved_name = 'Saved before the bad upload';
	tzfd_oi_set_draft( array( array( 'name' => $saved_name, 'price' => '', 'source' => 'pack' ) ) );
	$r = tzfd_oi_post(
		array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'csv' ) ),
		array( 'services_csv' => $meta )
	);
	tzfd_oi_check_failure( "bad CSV {$label}", $r, TZFD_OI_CSV_ERROR, $saved_name );
	tzfd_oi_normal_import( "bad CSV {$label}" );
	tzfd_oi_booking( "bad CSV {$label}" );
}

/* ---- Cleanup: this run's draft and filters ---- */

TZFD_Scoped_Store::delete( TZFD_Onboarding::DRAFT );
remove_filter( 'pre_http_request', $tzfd_oi_http_filter, 10 );
$GLOBALS['tzfd_oi_http'] = null;

echo "\nPASS {$GLOBALS['tzfd_oi']['PASS']}  FAIL {$GLOBALS['tzfd_oi']['FAIL']}\n";
exit( $GLOBALS['tzfd_oi']['FAIL'] > 0 ? 1 : 0 );
