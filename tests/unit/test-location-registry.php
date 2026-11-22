<?php
function tzfd_test_location_registry() {
	$R = 'TZFD_Location_Registry';
	$co = 'co-1';
	$row = function ( $id, $name = 'Site', $company = 'co-1', $active = true ) {
		return array( 'id' => $id, 'company_id' => $company, 'name' => $name, 'active' => $active );
	};

	// Validation: good rows are trimmed and normalised.
	$ok = $R::validate( array( 'id' => 'loc-a', 'company_id' => $co, 'name' => '  North Depot  ', 'active' => true ) );
	tzfd_assert( 'North Depot' === $ok['name'] && true === $ok['active'], 'valid location is accepted and its name trimmed' );
	$defaulted = $R::validate( array( 'id' => 'loc-b', 'company_id' => $co, 'name' => 'South' ) );
	tzfd_assert( true === $defaulted['active'], 'a location with no active flag defaults to active' );

	// Validation: negative cases throw.
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => 'loc a', 'company_id' => $co, 'name' => 'x' ) ); }, 'location id with a space throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => str_repeat( 'a', 65 ), 'company_id' => $co, 'name' => 'x' ) ); }, 'location id longer than 64 characters throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => '', 'company_id' => $co, 'name' => 'x' ) ); }, 'empty location id throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => 'loc-a', 'company_id' => 'co 1', 'name' => 'x' ) ); }, 'invalid company id throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => 'loc-a', 'company_id' => $co, 'name' => '   ' ) ); }, 'blank name throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => 'loc-a', 'company_id' => $co, 'name' => str_repeat( 'n', 121 ) ) ); }, 'name longer than 120 characters throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => 'loc-a', 'company_id' => $co, 'name' => "bad\x07name" ) ); }, 'name with a control character throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => 'loc-a', 'company_id' => $co, 'name' => 'x', 'active' => 'yes' ) ); }, 'non-boolean active flag throws' );
	tzfd_throws( function () use ( $R, $co ) { $R::validate( array( 'id' => 'loc-a', 'company_id' => $co, 'name' => 'x', 'is_admin' => true ) ); }, 'unknown key in a location row throws' );
	tzfd_throws( function () use ( $R ) { $R::validate( 'loc-a' ); }, 'a location that is not an array throws' );

	// Loading stored rows: malformed, foreign and duplicate rows are skipped and counted, never trusted.
	$stored = array(
		$row( 'loc-a', 'North' ),
		array( 'id' => 'loc-b', 'company_id' => $co, 'name' => '' ),
		$row( 'loc-c', 'Foreign', 'co-2' ),
		$row( 'loc-a', 'Duplicate' ),
		$row( 'loc-d', 'South', 'co-1', false ),
	);
	$loaded = $R::load( $stored, $co );
	tzfd_assert_same( array( 'loc-a', 'loc-d' ), array_column( $loaded['rows'], 'id' ), 'load keeps valid, in-company, first-occurrence rows in order' );
	tzfd_assert_same( 3, $loaded['skipped'], 'load counts the malformed, foreign and duplicate rows as skipped' );
	tzfd_assert_same( array( 'loc-a' ), $R::active_ids( $loaded['rows'] ), 'inactive locations are not offered for new grants' );
	tzfd_assert_same( array(), $R::load( 'not-an-array', $co )['rows'], 'a non-array stored value loads as no locations' );

	// Add: refuses duplicates, foreign companies and malformed rows; leaves the list unchanged on refusal.
	$rows = $R::add( array(), $co, $row( 'loc-a', 'North' ) );
	tzfd_assert_same( array( 'loc-a' ), array_column( $rows, 'id' ), 'add appends a new location' );
	tzfd_throws( function () use ( $R, $rows, $co ) { $R::add( $rows, $co, array( 'id' => 'loc-a', 'company_id' => $co, 'name' => 'Again' ) ); }, 'add refuses a duplicate location id' );
	tzfd_throws( function () use ( $R, $rows, $co ) { $R::add( $rows, $co, array( 'id' => 'loc-z', 'company_id' => 'co-2', 'name' => 'Other' ) ); }, 'add refuses a location for another company' );
	tzfd_throws( function () use ( $R, $rows, $co ) { $R::add( $rows, $co, array( 'id' => 'loc z', 'company_id' => $co, 'name' => 'Bad id' ) ); }, 'add refuses a free-text location id with a space' );
	$full = array();
	for ( $i = 0; $i < 500; $i++ ) { $full[] = $row( 'loc-' . $i ); }
	tzfd_throws( function () use ( $R, $full, $co ) { $R::add( $full, $co, array( 'id' => 'loc-extra', 'company_id' => $co, 'name' => 'Over' ) ); }, 'add refuses past the registry limit' );

	// Update: rename and deactivate one location; everything else is unchanged.
	$two = array( $row( 'loc-a', 'North' ), $row( 'loc-b', 'South' ) );
	$changed = $R::update( $two, $co, 'loc-b', 'South Yard', false );
	tzfd_assert_same( array( 'South Yard', false ), array( $changed[1]['name'], $changed[1]['active'] ), 'update renames and deactivates the chosen location' );
	tzfd_assert_same( 'North', $changed[0]['name'], 'update leaves other locations unchanged' );
	tzfd_assert_same( array( 'loc-a' ), $R::active_ids( $changed ), 'a deactivated location drops out of the active list' );
	tzfd_throws( function () use ( $R, $two, $co ) { $R::update( $two, $co, 'loc-missing', 'X', true ); }, 'update refuses an unknown location id' );
	tzfd_throws( function () use ( $R, $two, $co ) { $R::update( $two, $co, 'loc-a', '', true ); }, 'update refuses a blank name' );
	tzfd_throws( function () use ( $R, $two, $co ) { $R::update( $two, $co, 'loc-a', 'Ok', 'no' ); }, 'update refuses a non-boolean active flag' );

	// Legacy free-text grants are flagged, not dropped.
	tzfd_assert_same( array( 'legacy-9' ), $R::unregistered( $two, array( 'loc-a', 'legacy-9', 'loc-a' ) ), 'ids not in the registry are reported as unregistered' );
	tzfd_assert( null === $R::find( $two, 'nope' ) && 'South' === $R::find( $two, 'loc-b' )['name'], 'find returns the registered row or null' );

}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
