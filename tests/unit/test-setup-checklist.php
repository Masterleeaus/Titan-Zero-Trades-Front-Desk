<?php
/**
 * Pure tests for the first-run checklist (issue #15): detected versus self-reported steps, completion, and
 * progress recording that needs consent and never overwrites an earlier completion time.
 */
require_once __DIR__ . '/wp-stubs.php';

function tzfd_test_setup_checklist() {
	$C = 'TZFD_Setup_Checklist';
	$steps = $C::steps();

	tzfd_assert_same( 7, count( $steps ), 'checklist has the seven first-run steps' );
	$self = array_keys( array_filter( $steps, function ( $s ) { return 'self' === $s['source']; } ) );
	sort( $self );
	tzfd_assert_same( array( 'form', 'hours', 'reminders' ), $self, 'steps that cannot be detected are self-reported' );

	$none = $C::evaluate( array(), array() );
	tzfd_assert( false === $none['complete'] && 0 === $none['done_count'], 'nothing is done for an empty site' );

	$detected = $C::evaluate( array( 'services' => true, 'calendar' => true, 'test_booking' => true, 'customer' => true ), array() );
	tzfd_assert_same( 4, $detected['done_count'], 'detected steps count as done when the facts say so' );
	tzfd_assert( false === $detected['steps']['hours']['done'] && false === $detected['steps']['reminders']['done'], 'self-reported steps are not done by detection' );

	$manual = $C::evaluate( array(), array( 'hours' => true, 'form' => true, 'reminders' => true ) );
	tzfd_assert( true === $manual['steps']['hours']['done'] && 3 === $manual['done_count'], 'a self-reported step counts once the administrator marks it' );
	tzfd_assert( false === $manual['steps']['services']['done'], 'a manual mark does not complete a detected step' );

	$all = $C::evaluate( array( 'services' => true, 'calendar' => true, 'test_booking' => true, 'customer' => true ), array( 'hours' => true, 'form' => true, 'reminders' => true ) );
	tzfd_assert( true === $all['complete'] && 7 === $all['done_count'] && 7 === $all['total'], 'all seven done is complete' );

	// Progress records: no consent, no writes; consent, first completion time kept.
	$now = 1800000000;
	tzfd_assert_same( array(), $C::record( false, array(), $detected['steps'], $now ), 'without consent nothing is recorded' );
	$first = $C::record( true, array(), $detected['steps'], $now );
	tzfd_assert( 4 === count( $first ) && isset( $first['services'] ) && ! isset( $first['hours'] ), 'with consent only done steps are recorded' );
	$later = $C::record( true, $first, $all['steps'], $now + 500 );
	tzfd_assert( $now === $later['services'] && $now + 500 === $later['hours'], 'an earlier completion time is kept; new steps get their own time' );
	tzfd_assert_same( $first, $C::record( false, $first, $all['steps'], $now + 900 ), 'turning consent off freezes the existing record' );
	tzfd_assert_same( array( 'services' => $now ), $C::record( true, array( 'services' => $now ), array( 'services' => array( 'done' => true ), 'unknown' => array( 'done' => true ) ), $now + 1 ), 'steps not in the catalogue are never recorded' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
