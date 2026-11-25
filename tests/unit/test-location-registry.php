<?php
function tzfd_test_location_registry() {
	$R   = 'TZFD_Location_Registry';
	$loc = function ( $id, $extra = array() ) {
		return array_merge( array( 'id' => $id, 'name' => 'Site ' . $id, 'timezone' => 'Australia/Melbourne', 'status' => 'active' ), $extra );
	};

	// Validation: a well-formed location is normalised; the name is trimmed and status defaults to active.
	$ok = $R::validate( array( 'id' => 'loc-a', 'name' => '  Richmond  ', 'timezone' => 'Australia/Melbourne' ) );
	tzfd_assert_same( array( 'id' => 'loc-a', 'name' => 'Richmond', 'timezone' => 'Australia/Melbourne', 'status' => 'active' ), $ok, 'valid location is normalised with trimmed name and active status' );
	tzfd_assert( 'UTC' === $R::validate( $loc( 'loc-u', array( 'timezone' => 'UTC' ) ) )['timezone'], 'UTC is an accepted time zone' );

	// Malformed input throws.
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc a' ) ); }, 'id with a space is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( str_repeat( 'x', 65 ) ) ); }, 'id longer than 64 characters is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'id' => 42 ) ) ); }, 'non-string id is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'name' => '   ' ) ) ); }, 'blank name is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'name' => str_repeat( 'n', 121 ) ) ) ); }, 'name over 120 bytes is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'name' => "Bad\x07name" ) ) ); }, 'name with a control character is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'timezone' => 'Mars/Olympus' ) ) ); }, 'unknown time zone is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'timezone' => '+10:00' ) ) ); }, 'offset-style time zone is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'status' => 'deleted' ) ) ); }, 'unknown status is refused' );
	tzfd_throws( function () use ( $R, $loc ) { $R::validate( $loc( 'loc-b', array( 'address' => '1 Main St' ) ) ); }, 'unknown key is refused (no free-form data)' );
	tzfd_throws( function () use ( $R ) { $R::validate( 'loc-a' ); }, 'a bare string is not a location' );

	// Add: new locations are active, ids are unique, and the list is capped.
	$list = $R::add( array(), $loc( 'loc-a' ) );
	tzfd_assert( 1 === count( $list ) && 'loc-a' === $list[0]['id'], 'add appends a new location' );
	tzfd_throws( function () use ( $R, $list, $loc ) { $R::add( $list, $loc( 'loc-a', array( 'name' => 'Copy' ) ) ); }, 'duplicate id is refused on add' );
	tzfd_throws( function () use ( $R, $loc ) { $R::add( array(), $loc( 'loc-z', array( 'status' => 'archived' ) ) ); }, 'a new location cannot start archived' );
	$full = array();
	for ( $i = 0; $i < $R::MAX_LOCATIONS; $i++ ) { $full[] = $loc( 'full-' . $i ); }
	tzfd_throws( function () use ( $R, $full, $loc ) { $R::add( $full, $loc( 'one-more' ) ); }, 'location limit is enforced' );

	// Update: name, time zone and status can change; the id cannot; unknown ids throw.
	$two    = $R::add( $R::add( array(), $loc( 'loc-a' ) ), $loc( 'loc-b' ) );
	$renamed = $R::update( $two, 'loc-a', array( 'name' => 'Renamed', 'timezone' => 'UTC' ) );
	tzfd_assert( 'Renamed' === $R::find( $renamed, 'loc-a' )['name'] && 'UTC' === $R::find( $renamed, 'loc-a' )['timezone'], 'update changes name and time zone' );
	tzfd_assert( 'Site loc-b' === $R::find( $renamed, 'loc-b' )['name'], 'update leaves other locations unchanged' );
	tzfd_assert( $two !== $renamed && 'Site loc-a' === $R::find( $two, 'loc-a' )['name'], 'update returns a new list and does not mutate the input' );
	tzfd_throws( function () use ( $R, $two ) { $R::update( $two, 'loc-a', array( 'id' => 'other' ) ); }, 'update cannot change the id' );
	tzfd_throws( function () use ( $R, $two ) { $R::update( $two, 'loc-a', array( 'name' => '' ) ); }, 'update re-validates the name' );
	tzfd_throws( function () use ( $R, $two ) { $R::update( $two, 'missing', array( 'name' => 'X' ) ); }, 'update of an unknown location throws' );

	// Archive and restore keep the record. An archived location is not active.
	$archived = $R::update( $two, 'loc-b', array( 'status' => 'archived' ) );
	tzfd_assert( ! $R::is_active( $archived, 'loc-b' ) && $R::is_active( $archived, 'loc-a' ), 'archived location is not active; others are' );
	tzfd_assert( 2 === count( $archived ), 'archive keeps the record (nothing is deleted)' );
	tzfd_assert( array( 'loc-a' ) === $R::active_ids( $archived ), 'active_ids lists only active locations' );
	$restored = $R::update( $archived, 'loc-b', array( 'status' => 'active' ) );
	tzfd_assert( $R::is_active( $restored, 'loc-b' ), 'restore makes the location active again' );
	tzfd_assert( ! $R::is_active( $two, 'unregistered-id' ) && null === $R::find( $two, 'unregistered-id' ), 'an unregistered id is never active' );

	// Stored lists: malformed and duplicate records are skipped and counted, never repaired.
	$raw    = array( $loc( 'st-1' ), 'junk', $loc( 'st-1', array( 'name' => 'Dup' ) ), $loc( 'st-2', array( 'timezone' => 'Nope/Zone' ) ), $loc( 'st-3', array( 'status' => 'archived' ) ) );
	$stored = $R::stored( $raw );
	tzfd_assert( 3 === $stored['skipped'], 'stored list skips a non-location string, a bad time zone and a duplicate id (3 skipped)' );
	tzfd_assert_same( array( 'st-1', 'st-3' ), array_column( $stored['locations'], 'id' ), 'stored list keeps only the valid, first-seen records' );
	tzfd_assert( array() === $R::stored( 'not-an-array' )['locations'] && 0 === $R::stored( null )['skipped'], 'non-array stored value yields an empty list' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
