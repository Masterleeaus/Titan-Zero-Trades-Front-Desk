<?php
function tzfd_test_report_saved_views() {
	$V = 'TZFD_Report_Saved_Views';
	$base = array( 'id' => 'v1', 'company_id' => 'co-1', 'name' => 'May web and phone', 'from' => '2026-05-01', 'to' => '2026-05-31' );

	// Validation: a well-formed view normalises; unknown keys, bad ids, bad dates and bad filters are refused.
	$ok = $V::validate_view( 'co-1', $base + array( 'location_ids' => array( 'loc-a' ), 'lead_sources' => array( 'web_form', 'phone' ) ) );
	tzfd_assert_same( array( 'loc-a' ), $ok['location_ids'], 'valid view keeps its location filter' );
	tzfd_assert_same( null, $V::validate_view( 'co-1', $base )['lead_sources'], 'a view with no source filter stores null, not an empty list' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_view( 'co-1', $base + array( 'sql' => 'x' ) ); }, 'unknown view key throws' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_view( 'co-2', $base ); }, 'view owned by another company throws' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_view( 'co-1', array_merge( $base, array( 'from' => '2026-06-01' ) ) ); }, 'inverted date range throws' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_view( 'co-1', array_merge( $base, array( 'name' => str_repeat( 'x', 81 ) ) ) ); }, 'view name longer than 80 characters throws' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_view( 'co-1', array_merge( $base, array( 'lead_sources' => array( 'telepathy' ) ) ) ); }, 'unknown lead source in a view throws' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_view( 'co-1', array_merge( $base, array( 'location_ids' => array( '../x' ) ) ) ); }, 'malformed location id throws' );

	// Set validation: duplicate ids and names (case-insensitive) and the count limit.
	tzfd_assert_same( 2, count( $V::validate_set( 'co-1', array( $base, array_merge( $base, array( 'id' => 'v2', 'name' => 'Other' ) ) ) ) ), 'two distinct views validate as a set' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_set( 'co-1', array( $base, array_merge( $base, array( 'name' => 'MAY WEB AND PHONE' ) ) ) ); }, 'duplicate view name ignoring case throws' );
	tzfd_throws( function () use ( $V, $base ) { $V::validate_set( 'co-1', array( $base, $base ) ); }, 'duplicate view id throws' );
	$many = array();
	for ( $i = 0; $i <= $V::MAX_VIEWS; $i++ ) { $many[] = array_merge( $base, array( 'id' => 'm' . $i, 'name' => 'N' . $i ) ); }
	tzfd_throws( function () use ( $V, $many ) { $V::validate_set( 'co-1', $many ); }, 'more than the view limit throws' );

	// Running a view: records from two locations and two companies, one scope per viewer.
	$records = array(
		array( 'id' => 'r1', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'lead_source' => 'web_form', 'enquiry_date' => '2026-05-02', 'response_seconds' => 60, 'booked' => true, 'cancelled' => false, 'duration_minutes' => 60, 'transactions' => array() ),
		array( 'id' => 'r2', 'company_id' => 'co-1', 'location_id' => 'loc-b', 'lead_source' => 'phone', 'enquiry_date' => '2026-05-03', 'response_seconds' => 120, 'booked' => false, 'cancelled' => false, 'duration_minutes' => 0, 'transactions' => array() ),
		array( 'id' => 'r3', 'company_id' => 'co-1', 'location_id' => 'loc-b', 'lead_source' => 'phone', 'enquiry_date' => '2026-05-04', 'response_seconds' => 180, 'booked' => true, 'cancelled' => false, 'duration_minutes' => 30, 'transactions' => array() ),
	);
	$full  = array( 'company_id' => 'co-1', 'allowed_location_ids' => array( 'loc-a', 'loc-b' ) );
	$scope = array( 'company_id' => 'co-1', 'allowed_location_ids' => array( 'loc-a' ) );
	$view_all = $V::validate_view( 'co-1', $base );

	$r = $V::run( $view_all, $full, $records, array() );
	tzfd_assert_same( 3, $r['totals']['enquiries'], 'unfiltered view across both locations counts every enquiry in range' );

	// A viewer limited to loc-a sees only loc-a, even when the view has no location filter.
	$limited = $V::run( $view_all, $scope, $records, array() );
	tzfd_assert_same( 1, $limited['totals']['enquiries'], 'no location filter is bounded by the viewer scope' );

	// A view that names a location outside the viewer's scope is refused, not narrowed silently.
	$outside = $V::validate_view( 'co-1', $base + array( 'location_ids' => array( 'loc-b' ) ) );
	tzfd_throws( function () use ( $V, $outside, $records, $scope ) { $V::run( $outside, $scope, $records, array() ); }, 'view naming an out-of-scope location is refused' );

	// A viewer from another company cannot run the view.
	tzfd_throws( function () use ( $V, $view_all, $records ) { $V::run( $view_all, array( 'company_id' => 'co-2', 'allowed_location_ids' => array( 'loc-a' ) ), $records, array() ); }, 'viewer from another company is refused' );

	// A viewer with no location scope is refused.
	tzfd_throws( function () use ( $V, $view_all, $records ) { $V::run( $view_all, array( 'company_id' => 'co-1', 'allowed_location_ids' => array() ), $records, array() ); }, 'empty viewer scope is refused' );

	// Source filter inside a view narrows the cohort and keeps the reconciled totals.
	$phone = $V::validate_view( 'co-1', $base + array( 'lead_sources' => array( 'phone' ) ) );
	$p = $V::run( $phone, $full, $records, array() );
	tzfd_assert_same( 2, $p['totals']['enquiries'], 'lead source filter in a saved view keeps phone enquiries only' );
	tzfd_assert( $p['reconciled'] === true, 'filtered saved view is reconciled' );
}
