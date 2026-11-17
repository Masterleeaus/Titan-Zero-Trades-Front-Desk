<?php
/**
 * TZFD_Rate_Limit decision logic, run against the in-memory wp_options model in fixtures/fake-rate-limit-wpdb.php.
 * The model applies each statement's WHERE clause, so these checks cover the PHP branches. Concurrency is
 * not modelled here: the atomicity comes from the single conditional statements, which need a live MySQL check.
 */
function tzfd_test_rate_limit() {
	if ( ! class_exists( 'TZFD_Rate_Limit', false ) ) {
		require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-rate-limit.php';
	}
	require_once __DIR__ . '/fixtures/fake-rate-limit-wpdb.php';
	$R = 'TZFD_Rate_Limit';

	$GLOBALS['wpdb'] = new TZ_Fake_Rate_Limit_Wpdb();
	$t = 1000000;

	// Limit of 3 per window: three hits pass, the fourth is refused, for the same key in the same window.
	$k = 'visitor-a';
	$got = array();
	for ( $i = 0; $i < 4; $i++ ) { $got[] = $R::allow( $k, 3, 60, $t ); }
	tzfd_assert_same( array( true, true, true, false ), $got, 'limit of 3 allows three hits then refuses the fourth' );

	// Another key has its own count.
	tzfd_assert( $R::allow( 'visitor-b', 3, 60, $t ), 'a second key is not limited by the first' );

	// Once the window has ended, the same key gets a fresh window (expired row is reset, not left full).
	tzfd_assert( $R::allow( $k, 3, 60, $t + 60 ), 'hit at the window end starts a new window' );
	tzfd_assert( $R::allow( $k, 3, 60, $t + 60 ), 'second hit in the new window is allowed' );

	// Limit of 1 per window (lead intake and sign-in email): the second hit inside the window is refused.
	$one = 'once-per-minute';
	tzfd_assert( $R::allow( $one, 1, 60, $t ), 'first hit under a limit of one is allowed' );
	tzfd_assert( false === $R::allow( $one, 1, 60, $t + 59 ), 'second hit 59 seconds later is refused' );
	tzfd_assert( $R::allow( $one, 1, 60, $t + 60 ), 'hit 60 seconds later is allowed again' );

	// Release clears the count so a refusal the visitor did not cause does not use up their window.
	$rel = 'released';
	tzfd_assert( $R::allow( $rel, 1, 60, $t ), 'release test: first hit allowed' );
	tzfd_assert( false === $R::allow( $rel, 1, 60, $t + 1 ), 'release test: second hit refused before release' );
	$R::release( $rel );
	tzfd_assert( $R::allow( $rel, 1, 60, $t + 2 ), 'release test: hit allowed again after release' );

	// Purge removes only expired rows under our prefix and leaves other option names alone.
	$GLOBALS['wpdb']->rows['tz_fd_sig_keepme'] = '9999999999';
	$before = count( $GLOBALS['wpdb']->rows );
	$removed = $R::purge_expired( $t + 10000 );
	tzfd_assert( $removed >= 1, 'purge removes expired rate-limit rows' );
	tzfd_assert( isset( $GLOBALS['wpdb']->rows['tz_fd_sig_keepme'] ), 'purge leaves other plugins\' option rows alone' );
	tzfd_assert( count( $GLOBALS['wpdb']->rows ) < $before, 'purge reduced the row count' );

	// Live windows survive a purge.
	$live = 'still-live';
	tzfd_assert( $R::allow( $live, 1, 3600, $t ), 'live-window test: hit allowed' );
	$R::purge_expired( $t + 10 );
	tzfd_assert( false === $R::allow( $live, 1, 3600, $t + 20 ), 'a row inside its window is not purged' );

	// Stored key is hashed: the raw key (for example an IP address) never appears in the option name.
	$name = $R::option_name( '203.0.113.7' );
	tzfd_assert( 0 === strpos( $name, 'tzfd_rl_' ) && false === strpos( $name, '203.0.113.7' ), 'option name is prefixed and hides the raw key' );
	tzfd_assert( $R::option_name( 'a' ) !== $R::option_name( 'b' ), 'different keys give different option names' );

	// Store failure fails closed.
	$saved = $GLOBALS['wpdb'];
	$GLOBALS['wpdb'] = null;
	tzfd_assert( false === $R::allow( 'no-store', 5, 60, $t ), 'missing database refuses the request' );
	$GLOBALS['wpdb'] = $saved;
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
