<?php
/**
 * Static guard for the authorisation review (issue #253, from #151): every REST route the plugin registers must declare a
 * permission_callback that is not __return_true, and no admin-ajax handler may appear without a reviewed allowlist entry.
 * Reads source from disk, so it runs without WordPress. A new entrypoint fails here until its authorisation is reviewed.
 */
require_once __DIR__ . '/wp-stubs.php';

function tzfd_rest_ajax_guard_sources() {
	$root  = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	$files = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			$files[ $file->getPathname() ] = (string) file_get_contents( $file->getPathname() );
		}
	}
	ksort( $files );
	return $files;
}

function tzfd_test_rest_ajax_entrypoint_guard() {
	// Reviewed admin-ajax actions. Empty: the plugin registers no wp_ajax_ handlers today.
	$ajax_allowlist = array();

	$sources     = tzfd_rest_ajax_guard_sources();
	tzfd_assert( count( $sources ) > 50, 'plugin source is readable for the entrypoint guard' );

	$routes = 0;
	foreach ( $sources as $path => $src ) {
		$rel = basename( $path );
		$pos = 0;
		while ( false !== ( $pos = strpos( $src, 'register_rest_route(', $pos ) ) ) {
			$next  = strpos( $src, 'register_rest_route(', $pos + 20 );
			$block = substr( $src, $pos, false === $next ? strlen( $src ) - $pos : $next - $pos );
			$routes++;
			$pos++;
			$has_perm = (bool) preg_match( "/['\"]permission_callback['\"]\s*=>/", $block );
			tzfd_assert( $has_perm, "REST route in {$rel} at offset {$pos} declares a permission_callback" );
			tzfd_assert( ! preg_match( '/__return_true/', $block ), "REST route in {$rel} does not use __return_true" );
		}

		if ( preg_match_all( "/['\"]wp_ajax_(nopriv_)?([A-Za-z0-9_]+)['\"]/", $src, $m ) ) {
			foreach ( $m[2] as $action ) {
				tzfd_assert( in_array( $action, $ajax_allowlist, true ), "admin-ajax action {$action} in {$rel} is on the reviewed allowlist" );
			}
		}
	}

	tzfd_assert( $routes >= 9, "entrypoint guard found the registered REST routes ({$routes})" );
	tzfd_assert( array() === $ajax_allowlist, 'no admin-ajax actions are registered beyond the reviewed allowlist' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure.
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
