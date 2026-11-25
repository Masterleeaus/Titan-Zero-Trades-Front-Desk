<?php
/**
 * Issue #447 (from #340, #151, #8): outage tests for the setup wizard's service import.
 *
 * The import has two outside inputs: a web page fetched with wp_safe_remote_get() and a CSV upload. When either fails
 * the wizard must show a user-facing error, keep the saved draft as it was, and leave normal setup working.
 *
 * Each case runs the real TZFD_Onboarding::handle_post() code path. Only the WordPress functions around it are
 * stubbed: wp_safe_remote_get() is the HTTP layer, and it can fail, return a non-200 status, or return a body that
 * is truncated the way the HTTP API truncates to limit_response_size. go() ends in wp_safe_redirect(), which the
 * stub turns into an exception that carries the redirect URL, so the error text is read back the way the screen
 * shows it. No network request leaves the process.
 *
 * Acceptance (#447):
 *   1. A failed web fetch (WP_Error, or a non-200 status) gives a user-facing error and normal setup still works.
 *   2. An oversized web page is refused with a user-facing error, whether or not the transport truncates it.
 *   3. A bad CSV upload is refused with a user-facing error, and normal setup still works.
 *
 * Runs under tests/unit/run.php, and directly: php tests/unit/test-onboarding-import-outage.php
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! function_exists( 'tzfd_assert' ) ) {
	// Direct run: the same contract as run.php.
	$GLOBALS['tzfd_tests'] = array( 'pass' => 0, 'fail' => 0, 'failures' => array() );
	function tzfd_assert( $condition, $message ) {
		if ( $condition ) {
			$GLOBALS['tzfd_tests']['pass']++;
		} else {
			$GLOBALS['tzfd_tests']['fail']++;
			$GLOBALS['tzfd_tests']['failures'][] = $message;
		}
	}
}

if ( ! isset( $GLOBALS['tzfd_test_options'] ) ) { $GLOBALS['tzfd_test_options'] = array(); }
if ( ! isset( $GLOBALS['tzfd_test_caps'] ) ) { $GLOBALS['tzfd_test_caps'] = false; }

/* ---- WordPress stubs. Each one is defined only when no other harness in this process has defined it. ---- */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! class_exists( 'TZFD_Local_Business' ) ) {
	// The company the scoped option store writes to. Company 1 is the only one these tests use.
	class TZFD_Local_Business {
		public static function id() { return 1; }
		public static function default_id() { return 1; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '', basename( (string) $s ) ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $domain = '' ) { return $s; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $domain = '' ) { return $s; } }
if ( ! function_exists( 'map_deep' ) ) {
	function map_deep( $value, $callback ) {
		return is_array( $value ) ? array_map( function ( $v ) use ( $callback ) { return map_deep( $v, $callback ); }, $value ) : call_user_func( $callback, $value );
	}
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	// The nonce is not under test here. The handler's own nonce check is covered by the runtime suite.
	function check_admin_referer( $action = -1, $name = '_wpnonce' ) { return 1; }
}
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) { throw new RuntimeException( 'WP_DIE ' . (string) $message ); }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) { throw new RuntimeException( 'REDIRECT ' . (string) $location ); }
}
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'https://site.example.test/wp-admin/' . ltrim( (string) $path, '/' ); } }
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'add_submenu_page' ) ) { function add_submenu_page( ...$args ) { return ''; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { return true; } }
if ( ! function_exists( 'plugin_dir_url' ) ) { function plugin_dir_url( $f ) { return 'https://site.example.test/wp-content/plugins/tzfd/'; } }
if ( ! function_exists( 'plugin_dir_path' ) ) { function plugin_dir_path( $f ) { return dirname( (string) $f ) . '/'; } }
if ( ! function_exists( 'plugin_basename' ) ) { function plugin_basename( $f ) { return basename( (string) $f ); } }
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; } }
if ( ! function_exists( 'wp_normalize_path' ) ) { function wp_normalize_path( $p ) { return (string) $p; } }
if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir() { return array( 'basedir' => '/nonexistent-uploads', 'baseurl' => 'https://site.example.test/uploads' ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return (bool) $GLOBALS['tzfd_test_caps']; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['tzfd_test_options'] ) ? $GLOBALS['tzfd_test_options'][ $name ] : $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['tzfd_test_options'][ $name ] = $value; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) { unset( $GLOBALS['tzfd_test_options'][ $name ] ); return true; }
}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value, ...$args ) { return $value; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $domain = '' ) { return $s; } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }

/**
 * The HTTP layer for wp_safe_remote_get(). $GLOBALS['tzfd_imp_http'] describes the next answer:
 *   error     string  transport failure (returned as WP_Error)
 *   status    int     HTTP status code
 *   body      string  full response body the server would send
 *   truncate  bool    true: cut the body to limit_response_size, as the HTTP API does for a bounded read
 */
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ) {
		$GLOBALS['tzfd_imp_http_calls'][] = array( $url, $args );
		$c = $GLOBALS['tzfd_imp_http'] ?? array( 'error' => 'no answer configured' );
		if ( isset( $c['error'] ) ) {
			return new WP_Error( 'http_request_failed', $c['error'] );
		}
		$body = (string) $c['body'];
		if ( ! empty( $c['truncate'] ) && isset( $args['limit_response_size'] ) ) {
			$body = substr( $body, 0, (int) $args['limit_response_size'] );
		}
		return array( 'response' => array( 'code' => (int) $c['status'] ), 'body' => $body );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? '' ) : ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
}

/* ---- Plugin code under test ---- */
$tzfd_imp_root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
require_once $tzfd_imp_root . '/includes/class-tzfd-request-scalar.php';
require_once $tzfd_imp_root . '/includes/class-tzfd-scoped-store.php';
require_once $tzfd_imp_root . '/includes/class-tzfd-tables.php';
require_once $tzfd_imp_root . '/includes/class-tzfd-builtin-verticals.php';
require_once $tzfd_imp_root . '/includes/class-tzfd-vertical-registry.php';
require_once $tzfd_imp_root . '/addons/core/vertical-packs.addon.php';
require_once $tzfd_imp_root . '/includes/class-tzfd-booking-page.php';
require_once $tzfd_imp_root . '/includes/class-tzfd-intake-mode.php';
require_once $tzfd_imp_root . '/addons/messaging/missed-call.addon.php';
require_once $tzfd_imp_root . '/addons/core/onboarding.addon.php';

/** Saved state, put back after every case so the cases do not see each other's options or request data. */
function tzfd_imp_begin() {
	$GLOBALS['tzfd_imp_saved'] = array(
		'options' => $GLOBALS['tzfd_test_options'],
		'caps'    => $GLOBALS['tzfd_test_caps'],
		'server'  => $_SERVER,
		'post'    => $_POST,
		'files'   => $_FILES,
	);
	$GLOBALS['tzfd_test_options']   = array();
	$GLOBALS['tzfd_test_caps']      = true; // An administrator who may run setup.
	$GLOBALS['tzfd_imp_http']       = null;
	$GLOBALS['tzfd_imp_http_calls'] = array();
}

function tzfd_imp_end() {
	$saved = $GLOBALS['tzfd_imp_saved'];
	$GLOBALS['tzfd_test_options'] = $saved['options'];
	$GLOBALS['tzfd_test_caps']    = $saved['caps'];
	$_SERVER = $saved['server'];
	$_POST   = $saved['post'];
	$_FILES  = $saved['files'];
	$GLOBALS['tzfd_imp_http'] = null;
}

/** Stores a wizard draft the way the wizard does, so the case starts from a known state. */
function tzfd_imp_set_draft( array $draft ) {
	TZFD_Scoped_Store::update( TZFD_Onboarding::DRAFT, array_merge(
		array( 'vertical' => 'cleaning', 'trades' => array(), 'candidates' => array(), 'services' => array(), 'details' => array() ),
		$draft
	) );
}

function tzfd_imp_draft() {
	return TZFD_Scoped_Store::get( TZFD_Onboarding::DRAFT, array() );
}

/**
 * Posts one request to the real handler. Returns the step the wizard redirected to, the error text the screen would
 * show (the same decoding the screen applies), the HTTP calls it made, and anything that escaped the handler.
 */
function tzfd_imp_post( array $fields, array $files = array() ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST  = array_merge( array( 'tz_fd_setup_nonce' => 'nonce-447' ), $fields );
	$_FILES = $files;
	$out    = array( 'step' => '', 'error' => '', 'threw' => '', 'http_calls' => 0 );
	$handler = ( new ReflectionClass( 'TZFD_Onboarding' ) )->newInstanceWithoutConstructor();
	try {
		$handler->handle_post();
		$out['threw'] = 'handler returned without redirecting';
	} catch ( RuntimeException $e ) {
		$msg = $e->getMessage();
		if ( 0 === strpos( $msg, 'REDIRECT ' ) ) {
			$query = (string) parse_url( substr( $msg, 9 ), PHP_URL_QUERY );
			parse_str( $query, $q );
			$out['step']  = isset( $q['step'] ) ? (string) $q['step'] : '';
			$out['error'] = isset( $q['error'] ) ? rawurldecode( (string) $q['error'] ) : '';
		} else {
			$out['threw'] = $msg;
		}
	} catch ( Throwable $e ) {
		$out['threw'] = get_class( $e ) . ': ' . $e->getMessage();
	}
	$out['http_calls'] = count( $GLOBALS['tzfd_imp_http_calls'] );
	$_POST  = array();
	$_FILES = array();
	return $out;
}

/**
 * Negative case: normal setup still works after a failure. A pack import (no outside input) must redirect to the
 * services step with no error and produce the pack's services.
 */
function tzfd_imp_assert_normal_import_works( $label ) {
	$r     = tzfd_imp_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'pack' ) ) );
	$draft = tzfd_imp_draft();
	$names = array_map( function ( $c ) { return $c['name']; }, (array) $draft['candidates'] );
	tzfd_assert( '' === $r['threw'] && '3' === $r['step'] && '' === $r['error'],
		"$label: normal pack import still reaches the services step (step=" . $r['step'] . ' error=' . $r['error'] . ' threw=' . $r['threw'] . ')' );
	$from_pack = count( $names ) > 0;
	foreach ( (array) $draft['candidates'] as $c ) {
		if ( 0 !== strpos( (string) $c['source'], 'pack' ) ) { $from_pack = false; }
	}
	tzfd_assert( $from_pack, "$label: normal pack import saves the pack's services to the draft" );
}

/** Acceptance 1: the fetch fails (transport error, or a status other than 200). */
function tzfd_test_import_outage_web_fetch_fails() {
	$url      = 'https://menu.example.test/services';
	$message  = 'The service web page could not be fetched safely.';
	$cases    = array(
		'transport error (WP_Error)' => array( 'error' => 'simulated fetch outage' ),
		'HTTP 503 from the page'     => array( 'status' => 503, 'body' => '<ul><li>Drain clean $90</li></ul>' ),
		'HTTP 404 from the page'     => array( 'status' => 404, 'body' => '' ),
	);
	foreach ( $cases as $label => $answer ) {
		tzfd_imp_begin();
		tzfd_imp_set_draft( array( 'candidates' => array( array( 'name' => 'Saved before the outage', 'price' => '', 'source' => 'pack' ) ) ) );
		$GLOBALS['tzfd_imp_http'] = $answer;
		$r     = tzfd_imp_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'url' ), 'web_url' => $url ) );
		$draft = tzfd_imp_draft();
		tzfd_assert( '' === $r['threw'], "web fetch $label: handler does not throw (" . $r['threw'] . ')' );
		tzfd_assert( '2' === $r['step'] && $message === $r['error'],
			"web fetch $label: user sees the fetch error on the import step (step=" . $r['step'] . " error=" . $r['error'] . ')' );
		tzfd_assert( 1 === $r['http_calls'], "web fetch $label: exactly one fetch attempt" );
		tzfd_assert( array( 'Saved before the outage' ) === array_map( function ( $c ) { return $c['name']; }, (array) $draft['candidates'] ),
			"web fetch $label: the saved draft is unchanged" );
		tzfd_imp_assert_normal_import_works( "web fetch $label" );
		tzfd_imp_end();
	}
}

/** Acceptance 2: the page is larger than the limit. The page is refused, whether the transport truncates it or not. */
function tzfd_test_import_outage_web_page_oversize() {
	$max      = TZFD_Onboarding::MAX_HTML;
	$url      = 'https://menu.example.test/big-price-list';
	$message  = 'Invalid service web page response.';
	$draft    = array( 'candidates' => array( array( 'name' => 'Saved before the oversize page', 'price' => '', 'source' => 'pack' ) ) );
	$filler   = '<li>Gutter clean ' . str_repeat( 'x', 90 ) . '</li>';
	$big      = str_repeat( $filler, (int) ceil( ( $max + 400000 ) / strlen( $filler ) ) );

	// The transport cuts the body at limit_response_size (WordPress' bounded read).
	tzfd_imp_begin();
	tzfd_imp_set_draft( $draft );
	$GLOBALS['tzfd_imp_http'] = array( 'status' => 200, 'body' => $big, 'truncate' => true );
	$r = tzfd_imp_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'url' ), 'web_url' => $url ) );
	tzfd_assert( '' === $r['threw'], 'oversize page (truncating transport): handler does not throw (' . $r['threw'] . ')' );
	tzfd_assert( '2' === $r['step'] && $message === $r['error'],
		'oversize page (truncating transport): user sees the refusal, not a silently truncated list (step=' . $r['step'] . ' error=' . $r['error'] . ')' );
	tzfd_imp_assert_normal_import_works( 'oversize page (truncating transport)' );
	tzfd_imp_end();

	// The transport returns the whole body with no truncation.
	tzfd_imp_begin();
	tzfd_imp_set_draft( $draft );
	$GLOBALS['tzfd_imp_http'] = array( 'status' => 200, 'body' => $big, 'truncate' => false );
	$r = tzfd_imp_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'url' ), 'web_url' => $url ) );
	tzfd_assert( '2' === $r['step'] && $message === $r['error'],
		'oversize page (full body): user sees the refusal (step=' . $r['step'] . ' error=' . $r['error'] . ')' );
	tzfd_imp_assert_normal_import_works( 'oversize page (full body)' );
	tzfd_imp_end();

	// Boundary: a page of exactly the limit is still accepted, so the refusal starts only above it.
	$html = '<ul><li>Gutter clean</li><li>Window wash</li></ul>';
	$exact = $html . str_repeat( ' ', $max - strlen( $html ) );
	tzfd_imp_begin();
	tzfd_imp_set_draft( $draft );
	$GLOBALS['tzfd_imp_http'] = array( 'status' => 200, 'body' => $exact, 'truncate' => true );
	$r = tzfd_imp_post( array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'url' ), 'web_url' => $url ) );
	tzfd_assert( '3' === $r['step'] && '' === $r['error'],
		'page of exactly the limit is accepted (step=' . $r['step'] . ' error=' . $r['error'] . ')' );
	tzfd_imp_end();
}

/** Acceptance 3: the CSV upload is bad. Each shape must be refused with the upload message and nothing parsed. */
function tzfd_test_import_outage_bad_csv_upload() {
	$message = 'Invalid CSV upload. Select a CSV file up to 256 KB.';
	$ok_meta = array( 'error' => 0, 'tmp_name' => '/nonexistent-upload-dir/phpXYZ', 'size' => 120, 'name' => 'services.csv' );
	$cases   = array(
		'file not sent by an upload (is_uploaded_file false)' => $ok_meta,
		'upload error code (too large for the server)'         => array_merge( $ok_meta, array( 'error' => 1 ) ),
		'wrong extension (menu.txt)'                           => array_merge( $ok_meta, array( 'name' => 'menu.txt' ) ),
		'empty file'                                           => array_merge( $ok_meta, array( 'size' => 0 ) ),
		'over the 256 KB limit'                                => array_merge( $ok_meta, array( 'size' => TZFD_Onboarding::MAX_CSV + 1 ) ),
	);
	foreach ( $cases as $label => $meta ) {
		tzfd_imp_begin();
		tzfd_imp_set_draft( array( 'candidates' => array( array( 'name' => 'Saved before the bad upload', 'price' => '', 'source' => 'pack' ) ) ) );
		$r     = tzfd_imp_post(
			array( 'tz_fd_setup_action' => 'sources', 'sources' => array( 'csv' ) ),
			array( 'services_csv' => $meta )
		);
		$draft = tzfd_imp_draft();
		tzfd_assert( '' === $r['threw'], "bad CSV $label: handler does not throw (" . $r['threw'] . ')' );
		tzfd_assert( '2' === $r['step'] && $message === $r['error'],
			"bad CSV $label: user sees the upload error (step=" . $r['step'] . ' error=' . $r['error'] . ')' );
		tzfd_assert( array( 'Saved before the bad upload' ) === array_map( function ( $c ) { return $c['name']; }, (array) $draft['candidates'] ),
			"bad CSV $label: the saved draft is unchanged" );
		tzfd_imp_assert_normal_import_works( "bad CSV $label" );
		tzfd_imp_end();
	}
}

// Direct run: execute the tests here and exit 1 on any failure.
if ( PHP_SAPI === 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	foreach ( array( 'tzfd_test_import_outage_web_fetch_fails', 'tzfd_test_import_outage_web_page_oversize', 'tzfd_test_import_outage_bad_csv_upload' ) as $fn ) {
		$fn();
	}
	$t = $GLOBALS['tzfd_tests'];
	foreach ( $t['failures'] as $failure ) {
		echo 'FAIL ' . $failure . "\n";
	}
	echo 'Onboarding import outage: passed ' . $t['pass'] . ', failed ' . $t['fail'] . "\n";
	exit( $t['fail'] > 0 ? 1 : 0 );
}
