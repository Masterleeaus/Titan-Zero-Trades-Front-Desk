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

	// Measures from a consented record (issue #367).
	$none_m = $C::measures( array() );
	tzfd_assert( false === $none_m['setup_complete'] && 0 === $none_m['steps_recorded'] && null === $none_m['first_booking_at'] && false === $none_m['converted'], 'an empty record measures nothing' );
	$null_m = $C::measures( null );
	tzfd_assert( 0 === $null_m['steps_recorded'] && null === $null_m['seconds_to_first_booking'], 'a non-array record is treated as empty, not an error' );

	$full = array();
	foreach ( array_keys( $steps ) as $i => $key ) { $full[ $key ] = $now + ( $i * 100 ); }
	$m = $C::measures( $full );
	tzfd_assert( true === $m['setup_complete'] && 7 === $m['steps_recorded'], 'all seven recorded steps mean setup is complete' );
	tzfd_assert_same( $now + 600, $m['setup_completed_at'], 'setup completion time is the last step first seen done' );
	tzfd_assert_same( $now + 400, $m['first_booking_at'], 'first booking time is the test_booking step time' );
	tzfd_assert( false === $m['converted'], 'a booking seen before setup completed is not counted as converted after setup' );
	tzfd_assert_same( 400, $m['seconds_to_first_booking'], 'time to first booking runs from the earliest recorded step' );

	$after = $full;
	$after['test_booking'] = $now + 900;
	$ma = $C::measures( $after );
	tzfd_assert( true === $ma['converted'] && 900 === $ma['seconds_to_first_booking'], 'a booking seen after setup completion is converted' );

	$partial = array( 'services' => $now, 'test_booking' => $now + 50, 'unknown' => $now, 'calendar' => 0, 'customer' => 'x' );
	$mp = $C::measures( $partial );
	tzfd_assert( false === $mp['setup_complete'] && 2 === $mp['steps_recorded'] && null === $mp['setup_completed_at'], 'unknown, zero and non-numeric entries are ignored and setup is not complete' );
	tzfd_assert( false === $mp['converted'] && 50 === $mp['seconds_to_first_booking'], 'a partial record still reports a booking time but no conversion' );

	tzfd_assert_same( $now + 600, $m['configured_at'], 'configured time is the last non-booking step first seen done' );

	$no_booking = $full;
	unset( $no_booking['test_booking'] );
	$mn = $C::measures( $no_booking );
	tzfd_assert( false === $mn['setup_complete'] && $now + 600 === $mn['configured_at'] && null === $mn['first_booking_at'] && false === $mn['converted'] && null === $mn['seconds_to_first_booking'], 'configured setup without a booking is not converted and has no booking time' );

	$booked_first = array( 'services' => $now, 'calendar' => $now + 1, 'hours' => $now + 2, 'form' => $now + 3, 'reminders' => $now + 4, 'customer' => $now + 5, 'test_booking' => $now + 6 );
	$mb = $C::measures( $booked_first );
	tzfd_assert( true === $mb['setup_complete'] && true === $mb['converted'] && 6 === $mb['seconds_to_first_booking'], 'configuration finished and then a booking, with the booking last, is converted' );
	$mbb = $C::measures( array( 'services' => $now, 'calendar' => $now + 1, 'hours' => $now + 2, 'form' => $now + 3, 'reminders' => $now + 4, 'customer' => $now + 5, 'test_booking' => $now ) );
	tzfd_assert( false === $mbb['converted'] && $now === $mbb['first_booking_at'], 'a booking seen before the last configuration step is not converted' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
