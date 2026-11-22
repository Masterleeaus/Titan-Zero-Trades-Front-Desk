<?php
/**
 * Standalone checks for TZFD_Multi_Location (issue #369): read-only multi-location eligibility.
 * Covers fail-closed input validation, cross-company isolation, local-midnight and site-hour rules,
 * closed dates, staff overlap and directional travel buffers. Pure PHP, no WordPress.
 */
function tzfd_test_multi_location() {
	require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-multi-location.php';
	$P = 'TZFD_Multi_Location';

	// Local Melbourne wall-clock time on Monday 2026-11-23 (AEDT, UTC+11) as Unix seconds.
	$local = function ( $hour, $minute = 0 ) {
		return gmmktime( $hour - 11, $minute, 0, 11, 23, 2026 );
	};
	$site = function ( $over = array() ) {
		return array_merge( array(
			'id' => 'loc-a', 'company_id' => 'co-1', 'timezone' => 'Australia/Melbourne',
			'weekly_hours' => array( 1 => array( array( 540, 1020 ) ) ), 'closed_dates' => array(),
		), $over );
	};
	$request = function ( $over = array() ) use ( $local ) {
		return array_merge( array(
			'company_id' => 'co-1', 'verified_company_id' => 'co-1', 'snapshot_company_id' => 'co-1',
			'location_id' => 'loc-a', 'start_utc' => $local( 10 ), 'end_utc' => $local( 11 ),
			'snapshot_complete' => true, 'sites_verified' => true, 'staff_schedule_verified' => true,
			'candidates_verified' => true, 'candidate_staff_ids' => array( 'st-1', 'st-2' ),
		), $over );
	};
	$sites = array( $site(), $site( array( 'id' => 'loc-b', 'weekly_hours' => array( 1 => array( array( 540, 1020 ) ) ) ) ) );
	$travel = array( 'loc-a' => array( 'loc-b' => 60 ), 'loc-b' => array( 'loc-a' => 60 ) );
	$none = array();

	// Happy path: open hours, no conflicts, read-only result that must be revalidated at commit.
	$r = $P::evaluate( $request(), $sites, $none, $travel );
	tzfd_assert_same( true, $r['site_open'], 'open site inside hours is open' );
	tzfd_assert_same( array( 'st-1', 'st-2' ), $r['eligible_staff_ids'], 'free candidates are eligible' );
	tzfd_assert( true === $r['read_only'] && true === $r['requires_commit_revalidation'], 'result is read-only and needs commit revalidation' );
	tzfd_assert_same( null, $r['reason'], 'open result carries no reason' );

	// Negative: each unverified or incomplete input is an error, never a free slot.
	$bad_flags = array( 'snapshot_complete', 'sites_verified', 'staff_schedule_verified', 'candidates_verified' );
	foreach ( $bad_flags as $flag ) {
		tzfd_throws( function () use ( $P, $request, $sites, $none, $travel, $flag ) {
			$P::evaluate( $request( array( $flag => false ) ), $sites, $none, $travel );
		}, "unverified $flag is refused" );
	}
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'verified_company_id' => 'co-2' ) ), $sites, $none, $travel );
	}, 'verified company mismatch is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'snapshot_company_id' => 'co-2' ) ), $sites, $none, $travel );
	}, 'snapshot from another company is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'company_id' => 'co-1/../x' ) ), $sites, $none, $travel );
	}, 'malformed company id is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'end_utc' => 1000 ) ), $sites, $none, $travel );
	}, 'end before start is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'end_utc' => 'later' ) ), $sites, $none, $travel );
	}, 'non-integer end is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel, $local ) {
		$P::evaluate( $request( array( 'start_utc' => $local( 6 ), 'end_utc' => $local( 6 ) + 86401 ) ), $sites, $none, $travel );
	}, 'interval longer than a day is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'location_id' => 'loc-zz' ) ), $sites, $none, $travel );
	}, 'unknown requested location is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'candidate_staff_ids' => array( 'st-1', 'st-1' ) ) ), $sites, $none, $travel );
	}, 'duplicate candidate is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'candidate_staff_ids' => array( '../st' ) ) ), $sites, $none, $travel );
	}, 'malformed candidate id is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $none, $travel ) {
		$P::evaluate( $request( array( 'candidate_staff_ids' => 'st-1' ) ), $sites, $none, $travel );
	}, 'candidate list that is not an array is refused' );

	// Tenant isolation: a site or assignment belonging to another company cannot be used.
	tzfd_throws( function () use ( $P, $request, $travel, $none, $site ) {
		$foreign = array( $site( array( 'company_id' => 'co-2' ) ) );
		$P::evaluate( $request(), $foreign, $none, $travel );
	}, 'foreign site in the snapshot is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $travel ) {
		$P::evaluate( $request(), $sites, array( array( 'staff_id' => 'st-1', 'location_id' => 'loc-a', 'company_id' => 'co-2', 'start_utc' => 1, 'end_utc' => 2 ) ), $travel );
	}, 'foreign-company assignment is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $travel ) {
		$P::evaluate( $request(), $sites, array( array( 'staff_id' => 'st-1', 'location_id' => 'loc-zz', 'company_id' => 'co-1', 'start_utc' => 1, 'end_utc' => 2 ) ), $travel );
	}, 'assignment at an unknown location is refused' );
	tzfd_throws( function () use ( $P, $request, $sites, $travel ) {
		$P::evaluate( $request(), $sites, array( array( 'staff_id' => 'st-1', 'location_id' => 'loc-a', 'company_id' => 'co-1', 'start_utc' => 5, 'end_utc' => 5 ) ), $travel );
	}, 'assignment with empty interval is refused' );
	tzfd_throws( function () use ( $P, $request, $travel, $local ) {
		$bad_site = array( array( 'id' => 'loc-a', 'company_id' => 'co-1', 'timezone' => 'Not/AZone', 'weekly_hours' => array() ) );
		$P::evaluate( $request(), $bad_site, array(), $travel );
	}, 'invalid site timezone is refused' );

	// Site hours and calendar rules.
	$cross = $P::evaluate( $request( array( 'start_utc' => $local( 23 ), 'end_utc' => $local( 24 ) + 60 ) ), $sites, $none, $travel );
	tzfd_assert_same( 'crosses_local_midnight', $cross['reason'], 'booking across local midnight is not offered' );
	tzfd_assert_same( false, $cross['site_open'], 'midnight-crossing booking is not open' );

	$early = $P::evaluate( $request( array( 'start_utc' => $local( 8 ), 'end_utc' => $local( 9 ) ) ), $sites, $none, $travel );
	tzfd_assert_same( 'outside_site_hours', $early['reason'], 'booking before opening is outside site hours' );
	$late = $P::evaluate( $request( array( 'start_utc' => $local( 16 ), 'end_utc' => $local( 17, 30 ) ) ), $sites, $none, $travel );
	tzfd_assert_same( 'outside_site_hours', $late['reason'], 'booking running past closing is outside site hours' );
	$edge = $P::evaluate( $request( array( 'start_utc' => $local( 16 ), 'end_utc' => $local( 17 ) ) ), $sites, $none, $travel );
	tzfd_assert_same( true, $edge['site_open'], 'booking ending exactly at closing is inside hours' );

	$tuesday = $P::evaluate( $request( array( 'start_utc' => $local( 10 ) + 86400, 'end_utc' => $local( 11 ) + 86400 ) ), $sites, $none, $travel );
	tzfd_assert_same( 'outside_site_hours', $tuesday['reason'], 'day without opening hours is closed' );

	$closed = array_map( function ( $s ) { $s['closed_dates'] = array( '2026-11-23' ); return $s; }, $sites );
	$r = $P::evaluate( $request(), $closed, $none, $travel );
	tzfd_assert_same( 'site_closed', $r['reason'], 'closed date blocks the site' );
	tzfd_assert_same( false, $r['site_open'], 'closed date is not open' );

	tzfd_throws( function () use ( $P, $request, $travel, $site ) {
		$P::evaluate( $request(), array( $site( array( 'weekly_hours' => array( 1 => array( array( 1000, 900 ) ) ) ) ) ), array(), $travel );
	}, 'inverted opening window is refused' );

	// Staff overlap and directional travel buffers.
	$busy = array( array( 'staff_id' => 'st-1', 'location_id' => 'loc-a', 'company_id' => 'co-1', 'start_utc' => $local( 10 ), 'end_utc' => $local( 10, 30 ) ) );
	$r = $P::evaluate( $request(), $sites, $busy, $travel );
	tzfd_assert_same( array( 'st-2' ), $r['eligible_staff_ids'], 'overlapping staff is excluded and free staff remains' );

	$from_b = array( array( 'staff_id' => 'st-1', 'location_id' => 'loc-b', 'company_id' => 'co-1', 'start_utc' => $local( 8 ), 'end_utc' => $local( 10 ) ) );
	$r = $P::evaluate( $request( array( 'start_utc' => $local( 10, 30 ), 'end_utc' => $local( 11, 30 ) ) ), $sites, $from_b, $travel );
	tzfd_assert_same( array( 'st-2' ), $r['eligible_staff_ids'], 'staff finishing elsewhere too late for the travel buffer is excluded' );

	$r = $P::evaluate( $request( array( 'start_utc' => $local( 11 ), 'end_utc' => $local( 12 ) ) ), $sites, $from_b, $travel );
	tzfd_assert_same( array( 'st-1', 'st-2' ), $r['eligible_staff_ids'], 'staff with enough travel time is eligible' );

	$after = array( array( 'staff_id' => 'st-1', 'location_id' => 'loc-b', 'company_id' => 'co-1', 'start_utc' => $local( 11, 30 ), 'end_utc' => $local( 12 ) ) );
	$r = $P::evaluate( $request( array( 'start_utc' => $local( 10 ), 'end_utc' => $local( 11 ) ) ), $sites, $after, $travel );
	tzfd_assert_same( array( 'st-2' ), $r['eligible_staff_ids'], 'staff needed at another site straight after the booking is excluded' );

	$r = $P::evaluate( $request(), $sites, $from_b, array( 'loc-a' => array( 'loc-b' => 60 ) ) );
	tzfd_assert_same( array( 'st-2' ), $r['eligible_staff_ids'], 'missing reverse travel minutes fail closed' );
	$r = $P::evaluate( $request( array( 'start_utc' => $local( 12 ), 'end_utc' => $local( 13 ) ) ), $sites, $from_b, array( 'loc-a' => array( 'loc-b' => 5000 ), 'loc-b' => array( 'loc-a' => 60 ) ) );
	tzfd_assert_same( array( 'st-2' ), $r['eligible_staff_ids'], 'travel minutes above a day fail closed' );
}

// Run on its own (php tests/unit/test-multi-location.php): one PASS or FAIL line per assertion, exit 1 on failure.
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
