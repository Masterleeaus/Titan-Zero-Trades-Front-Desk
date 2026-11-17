<?php
/**
 * Standalone runner for the tests/unit/test-*.php files (issue #192).
 *
 * Each test file calls tzfd_test_* functions that use tzfd_assert(). run.php runs them inside one process. When a file
 * is run directly (php tests/unit/test-x.php), its footer loads this runner, which:
 *   - loads the same plugin classes that run.php loads,
 *   - runs every tzfd_test_* function defined by that file,
 *   - prints one PASS or FAIL line per assertion, then a summary line,
 *   - exits 1 on any FAIL, and also when the file ran zero assertions (a file with no checks cannot pass).
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) ) { define( 'TZFD_UNIT_FILE_RUNNER', true ); }

if ( ! function_exists( 'tzfd_assert' ) ) {
	function tzfd_assert( $condition, $message ) {
		if ( $condition ) {
			$GLOBALS['tzfd_standalone']['pass']++;
			echo 'PASS ' . $message . "\n";
		} else {
			$GLOBALS['tzfd_standalone']['fail']++;
			echo 'FAIL ' . $message . "\n";
		}
	}
}

if ( ! function_exists( 'tzfd_assert_same' ) ) {
	function tzfd_assert_same( $expected, $actual, $message ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone CLI runner without WordPress; only builds a failure message.
		tzfd_assert( $expected === $actual, $message . ' | expected ' . json_encode( $expected ) . ' got ' . json_encode( $actual ) );
	}
}

if ( ! function_exists( 'tzfd_throws' ) ) {
	function tzfd_throws( $callable, $message ) {
		try {
			$callable();
			tzfd_assert( false, $message . ' | no exception thrown' );
		} catch ( Throwable $e ) {
			tzfd_assert( true, $message );
		}
	}
}

/**
 * Runs one test file in this process and returns the exit status (0 all passed, 1 otherwise).
 */
function tzfd_standalone_run( $file ) {
	$GLOBALS['tzfd_standalone'] = array( 'pass' => 0, 'fail' => 0 );
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
		'class-tzfd-agent-gateway.php',
		'class-tzfd-booking-report-summary.php',
		'class-tzfd-setup-checklist.php',
	) as $include ) {
		require_once $root . '/' . $include;
	}
	require_once __DIR__ . '/fixtures/fake-action-source.php';

	// The footer of $file is the script that called this function, so the file is already loaded here.
	require_once $file;
	$ran = 0;
	foreach ( get_defined_functions()['user'] as $fn ) {
		if ( 0 === strpos( $fn, 'tzfd_test_' ) ) {
			$fn();
			$ran++;
		}
	}
	$pass = $GLOBALS['tzfd_standalone']['pass'];
	$fail = $GLOBALS['tzfd_standalone']['fail'];
	if ( 0 === $ran ) {
		echo 'FAIL no tzfd_test_* function defined in ' . basename( $file ) . "\n";
		$fail++;
	}
	if ( 0 === $pass + $fail ) {
		echo 'FAIL no assertions ran in ' . basename( $file ) . "\n";
		$fail++;
	}
	echo basename( $file ) . ': ' . $pass . ' passed, ' . $fail . " failed\n";
	return $fail > 0 ? 1 : 0;
}
