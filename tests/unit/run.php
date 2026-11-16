<?php
/**
 * Unit runner. Runs two suites in one process and exits 1 when any assertion in either fails:
 *   1. Standalone pure-PHP suite for the local-free cores (issues 11, 12, 14, 15). Not shipped in the plugin.
 *   2. Free AI case files under tests/unit/cases (bootstrap.php harness).
 * Run: php -l on changed files, then: php tests/unit/run.php
 * No WordPress is needed: ABSPATH is defined here so each class's guard passes.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }

/* ---- Suite 1: local-free cores ---- */
$GLOBALS['tzfd_tests'] = array( 'pass' => 0, 'fail' => 0, 'failures' => array() );

function tzfd_assert( $condition, $message ) {
	if ( $condition ) {
		$GLOBALS['tzfd_tests']['pass']++;
	} else {
		$GLOBALS['tzfd_tests']['fail']++;
		$GLOBALS['tzfd_tests']['failures'][] = $message;
	}
}

function tzfd_assert_same( $expected, $actual, $message ) {
	tzfd_assert( $expected === $actual, $message . ' | expected ' . json_encode( $expected ) . ' got ' . json_encode( $actual ) );
}

function tzfd_throws( $callable, $message ) {
	try {
		$callable();
		tzfd_assert( false, $message . ' | no exception thrown' );
	} catch ( Throwable $e ) {
		tzfd_assert( true, $message );
	}
}

$root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes';
foreach ( array(
	'class-tzfd-delegated-roles.php',
	'class-tzfd-location-policy.php',
	'class-tzfd-recurring-local-times.php',
	'class-tzfd-booking-reports.php',
	'class-tzfd-customer-segments.php',
	'class-tzfd-agent-tool-permissions.php',
	'class-tzfd-action-receipt.php',
	'class-tzfd-action-receipt-viewer.php',
	'class-tzfd-upgrade-discovery.php',
) as $file ) {
	require_once $root . '/' . $file;
}
require_once __DIR__ . '/fixtures/fake-action-source.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require_once $file;
}

foreach ( get_defined_functions()['user'] as $fn ) {
	if ( 0 === strpos( $fn, 'tzfd_test_' ) ) {
		$fn();
	}
}

$local_t = $GLOBALS['tzfd_tests'];
foreach ( $local_t['failures'] as $failure ) {
	echo 'FAIL ' . $failure . "\n";
}
echo 'Local-free suite: passed ' . $local_t['pass'] . ', failed ' . $local_t['fail'] . "\n";
$local_fail = $local_t['fail'];

/* ---- Suite 2: Free AI case files (main harness) ---- */
require __DIR__ . '/bootstrap.php';
foreach ( glob( __DIR__ . '/cases/*.php' ) as $case ) {
	require $case;
}
$pass = $GLOBALS['tzfd_t_pass'];
$fail = $GLOBALS['tzfd_t_fail'];
echo "Free AI cases: assertions passed: $pass, failed: $fail\n";

exit( ( $local_fail > 0 || $fail > 0 ) ? 1 : 0 );
