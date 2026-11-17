<?php
function tzfd_test_location_policy_bulk() {
	$P = 'TZFD_Location_Policy';
	$rule = function ( $over ) {
		return array_merge( array(
			'id' => 'r1', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'services' => array( 'svc-1' ),
			'min_amount_minor' => null, 'action' => 'require_approval', 'staff_ids' => array(), 'enabled' => true,
		), $over );
	};
	$stored = array(
		$rule( array( 'id' => 'r1' ) ),
		$rule( array( 'id' => 'r2', 'enabled' => false ) ),
		$rule( array( 'id' => 'r3', 'location_id' => 'loc-b', 'action' => 'restrict_staff', 'staff_ids' => array( 'st-1' ) ) ),
	);

	// Disable two rules: only their flag changes, order and other fields are kept.
	$out = $P::bulk_set_enabled( $stored, array( 'r1', 'r3' ), false, 'co-1' );
	tzfd_assert_same( array( 'r1', 'r2', 'r3' ), array_column( $out, 'id' ), 'bulk update keeps stored order' );
	tzfd_assert_same( array( false, false, false ), array_column( $out, 'enabled' ), 'bulk disable sets every selected rule' );
	tzfd_assert_same( 'loc-b', $out[2]['location_id'], 'bulk update keeps other fields of the rule' );
	tzfd_assert_same( array( 'st-1' ), $out[2]['staff_ids'], 'bulk update keeps staff ids of the rule' );

	// Enable a single rule; the other disabled rule stays disabled.
	$out = $P::bulk_set_enabled( $stored, array( 'r2' ), true, 'co-1' );
	tzfd_assert_same( array( true, true, true ), array_column( $out, 'enabled' ), 'bulk enable sets the selected rule and leaves the others as enabled' );

	// The caller's input is not modified.
	tzfd_assert( true === $stored[0]['enabled'] && false === $stored[1]['enabled'], 'bulk update does not mutate input' );

	// Changed rules still evaluate as before: a disabled rule no longer forces approval.
	$booking = array( 'company_id' => 'co-1', 'location_id' => 'loc-a', 'service_id' => 'svc-1', 'amount_minor' => 5000, 'staff_id' => 'st-1' );
	$d = $P::evaluate( $P::bulk_set_enabled( $stored, array( 'r1' ), false, 'co-1' ), $booking );
	tzfd_assert( false === $d['approval_required'], 'rule disabled in bulk no longer applies' );

	// Atomic failures: each throws and returns nothing.
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( $stored, array( 'r1', 'missing' ), false, 'co-1' ); }, 'unknown id in a batch rejects the whole batch' );
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( $stored, array( 'r1', 'r1' ), false, 'co-1' ); }, 'duplicate id in a batch throws' );
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( $stored, array(), false, 'co-1' ); }, 'empty batch throws' );
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( $stored, array_map( function ( $i ) { return 'x' . $i; }, range( 1, 101 ) ), false, 'co-1' ); }, 'batch above 100 ids throws' );
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( $stored, array( '../r1' ), false, 'co-1' ); }, 'malformed id throws' );
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( $stored, array( 'r1' ), 'false', 'co-1' ); }, 'non-boolean flag throws' );
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( $stored, array( 'r1' ), false, 'co-9' ); }, 'rules of another company are refused' );
	tzfd_throws( function () use ( $P, $stored ) { $P::bulk_set_enabled( array_merge( $stored, array( $stored[0] ) ), array( 'r1' ), false, 'co-1' ); }, 'duplicate stored rule id throws' );
	tzfd_throws( function () use ( $P, $stored ) {
		$bad = $stored;
		$bad[] = array( 'id' => 'r9', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'services' => array( 'svc-1' ), 'min_amount_minor' => null, 'action' => 'delete_booking', 'staff_ids' => array(), 'enabled' => true );
		$P::bulk_set_enabled( $bad, array( 'r1' ), false, 'co-1' );
	}, 'malformed stored rule makes the batch fail' );
}
