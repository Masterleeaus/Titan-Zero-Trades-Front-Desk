<?php
function tzfd_test_recurring_local_times() {
	$R = 'TZFD_Recurring_Local_Times';
	$utc = function ( $ts ) { return gmdate( 'Y-m-d H:i', $ts ); };

	// Normal day, no transition: 09:00 Melbourne in June (AEST, +10) is 23:00 UTC the previous day.
	$out = $R::expand( array( '2026-06-01' ), '09:00', 'Australia/Melbourne', 60 );
	$o = $out['occurrences'][0];
	tzfd_assert_same( '2026-05-31 23:00', $utc( $o['start_utc'] ), 'normal Melbourne local time converts to UTC' );
	tzfd_assert_same( '2026-06-01 00:00', $utc( $o['end_utc'] ), 'duration is absolute minutes' );
	tzfd_assert_same( null, $o['adjustment'], 'normal occurrence has no adjustment' );

	// Melbourne DST ends Sunday 2026-04-05 at 03:00 AEDT (back to 02:00 AEST). 02:30 happens twice.
	$overlap_earlier = $R::expand( array( '2026-04-05' ), '02:30', 'Australia/Melbourne', 30, $R::GAP_SHIFT, $R::OVERLAP_EARLIER )['occurrences'][0];
	tzfd_assert_same( '2026-04-04 15:30', $utc( $overlap_earlier['start_utc'] ), 'repeated 02:30 uses the earlier (AEDT) instant' );
	tzfd_assert_same( 'overlap_earlier', $overlap_earlier['adjustment'], 'repeated time is flagged as overlap_earlier' );
	$overlap_later = $R::expand( array( '2026-04-05' ), '02:30', 'Australia/Melbourne', 30, $R::GAP_SHIFT, $R::OVERLAP_LATER )['occurrences'][0];
	tzfd_assert_same( '2026-04-04 16:30', $utc( $overlap_later['start_utc'] ), 'later policy selects the AEST instant' );

	// Melbourne DST starts Sunday 2026-10-04: 02:00 AEST jumps to 03:00 AEDT, so 02:30 never happens.
	$shifted = $R::expand( array( '2026-10-04' ), '02:30', 'Australia/Melbourne', 60, $R::GAP_SHIFT );
	tzfd_assert_same( '2026-10-03 16:30', $utc( $shifted['occurrences'][0]['start_utc'] ), 'nonexistent 02:30 shifts to the first real minute (03:30 AEDT)' );
	tzfd_assert_same( 'gap_shifted_forward', $shifted['occurrences'][0]['adjustment'], 'shifted gap occurrence is flagged' );
	$skipped = $R::expand( array( '2026-10-04' ), '02:30', 'Australia/Melbourne', 60, $R::GAP_SKIP );
	tzfd_assert( array() === $skipped['occurrences'] && 'nonexistent_local_time' === $skipped['skipped'][0]['reason'], 'skip policy drops the nonexistent time and reports it' );

	// New York: spring forward 2026-03-08 02:00 EST to 03:00 EDT. Fall back 2026-11-01 02:00 EDT to 01:00 EST.
	$ny_gap = $R::expand( array( '2026-03-08' ), '02:30', 'America/New_York', 60 )['occurrences'][0];
	tzfd_assert_same( '2026-03-08 07:30', $utc( $ny_gap['start_utc'] ), 'New York 02:30 on spring-forward day becomes 03:30 EDT (07:30 UTC)' );
	$ny_overlap = $R::expand( array( '2026-11-01' ), '01:30', 'America/New_York', 60, $R::GAP_SHIFT, $R::OVERLAP_EARLIER )['occurrences'][0];
	tzfd_assert_same( '2026-11-01 05:30', $utc( $ny_overlap['start_utc'] ), 'New York 01:30 on fall-back day picks EDT (05:30 UTC)' );
	$ny_later = $R::expand( array( '2026-11-01' ), '01:30', 'America/New_York', 60, $R::GAP_SHIFT, $R::OVERLAP_LATER )['occurrences'][0];
	tzfd_assert_same( '2026-11-01 06:30', $utc( $ny_later['start_utc'] ), 'New York 01:30 later policy picks EST (06:30 UTC)' );

	// Weekly series crossing the New York DST change keeps every local wall time at 09:00.
	$series = $R::expand( array( '2026-03-06', '2026-03-13', '2026-03-20' ), '09:00', 'America/New_York', 90 );
	$locals = array_map( function ( $o ) { return $o['local']; }, $series['occurrences'] );
	tzfd_assert_same( array( '2026-03-06 09:00', '2026-03-13 09:00', '2026-03-20 09:00' ), $locals, 'weekly series keeps local wall time across DST' );
	tzfd_assert_same( 3, count( array_unique( array_map( function ( $o ) { return $o['start_utc']; }, $series['occurrences'] ) ) ), 'each occurrence is a distinct instant' );

	// Input validation: all failures throw before anything is returned.
	tzfd_throws( function () use ( $R ) { $R::expand( array( '2026-02-30' ), '09:00', 'Australia/Melbourne', 60 ); }, 'impossible date throws' );
	tzfd_throws( function () use ( $R ) { $R::expand( array( '2026-06-01' ), '9am', 'Australia/Melbourne', 60 ); }, 'malformed time throws' );
	tzfd_throws( function () use ( $R ) { $R::expand( array( '2026-06-01' ), '09:00', 'Mars/Olympus', 60 ); }, 'unknown timezone throws' );
	tzfd_throws( function () use ( $R ) { $R::expand( array( '2026-06-01' ), '09:00', 'Australia/Melbourne', 0 ); }, 'zero duration throws' );
	tzfd_throws( function () use ( $R ) { $R::expand( array( '2026-06-01' ), '09:00', 'Australia/Melbourne', 60, 'shift_backward' ); }, 'unknown gap policy throws' );
	tzfd_throws( function () use ( $R ) { $R::expand( array( '2026-06-01', 'x' ), '09:00', 'Australia/Melbourne', 60 ); }, 'one bad date rejects the whole list' );
	tzfd_throws( function () use ( $R ) { $R::expand( array_fill( 0, 367, '2026-06-01' ), '09:00', 'Australia/Melbourne', 60 ); }, 'more than the maximum occurrences throws' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
