<?php
/**
 * Standalone unit runner for the pure-PHP plugin classes. Not shipped in the plugin.
 * Run: php -l on changed files, then: php tests/unit/run.php
 * No WordPress is needed: ABSPATH is defined here so each class's guard passes.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }

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

$t = $GLOBALS['tzfd_tests'];
foreach ( $t['failures'] as $failure ) {
	echo 'FAIL ' . $failure . "\n";
}
echo 'Passed ' . $t['pass'] . ', failed ' . $t['fail'] . "\n";
exit( $t['fail'] > 0 ? 1 : 0 );
