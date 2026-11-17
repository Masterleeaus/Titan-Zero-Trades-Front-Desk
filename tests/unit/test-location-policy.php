<?php
function tzfd_test_location_policy() {
	$P = 'TZFD_Location_Policy';
	$rule = function ( $over ) {
		return array_merge( array(
			'id' => 'r1', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'services' => array( 'svc-1' ),
			'min_amount_minor' => null, 'action' => 'require_approval', 'staff_ids' => array(), 'enabled' => true,
		), $over );
	};
	$booking = function ( $over = array() ) {
		return array_merge( array( 'company_id' => 'co-1', 'location_id' => 'loc-a', 'service_id' => 'svc-1', 'amount_minor' => 5000, 'staff_id' => 'st-1' ), $over );
	};

	// Approval: a rule with a threshold applies only at or above it.
	$rules = array( $rule( array( 'min_amount_minor' => 20000 ) ) );
	$d = $P::evaluate( $rules, $booking( array( 'amount_minor' => 19999 ) ) );
	tzfd_assert( false === $d['approval_required'], 'approval not forced below threshold' );
	$d = $P::evaluate( $rules, $booking( array( 'amount_minor' => 20000 ) ) );
	tzfd_assert( true === $d['approval_required'] && array( 'r1' ) === $d['matched_rules'], 'approval forced at threshold' );

	// Location and service scope.
	$d = $P::evaluate( array( $rule( array() ) ), $booking( array( 'location_id' => 'loc-b' ) ) );
	tzfd_assert( false === $d['approval_required'], 'rule for loc-a does not reach loc-b' );
	$d = $P::evaluate( array( $rule( array( 'location_id' => '*', 'services' => array( '*' ) ) ) ), $booking( array( 'location_id' => 'loc-b', 'service_id' => 'svc-9' ) ) );
	tzfd_assert( true === $d['approval_required'], 'company-wide wildcard rule reaches every location and service' );
	$d = $P::evaluate( array( $rule( array( 'services' => array( 'svc-2' ) ) ) ), $booking() );
	tzfd_assert( false === $d['approval_required'], 'service-specific rule ignores other services' );

	// Disabled rules have no effect.
	$d = $P::evaluate( array( $rule( array( 'enabled' => false ) ) ), $booking() );
	tzfd_assert( false === $d['approval_required'], 'disabled rule is ignored' );

	// Staff restriction: intersection across matching rules; restricted to staff, no others.
	$restrict_a = $rule( array( 'id' => 'ra', 'action' => 'restrict_staff', 'staff_ids' => array( 'st-1', 'st-2' ) ) );
	$restrict_b = $rule( array( 'id' => 'rb', 'action' => 'restrict_staff', 'staff_ids' => array( 'st-2', 'st-3' ) ) );
	$d = $P::evaluate( array( $restrict_a, $restrict_b ), $booking() );
	tzfd_assert( array( 'st-2' ) === $d['allowed_staff_ids'], 'staff restrictions intersect' );
	tzfd_assert( $P::assignment_allowed( $d, 'st-2' ) && ! $P::assignment_allowed( $d, 'st-1' ), 'only the intersected staff may be assigned' );
	$none = $P::evaluate( array( $restrict_a, $rule( array( 'id' => 'rc', 'action' => 'restrict_staff', 'staff_ids' => array( 'st-9' ) ) ) ), $booking() );
	tzfd_assert( array() === $none['allowed_staff_ids'] && ! $P::assignment_allowed( $none, 'st-1' ), 'empty intersection blocks every assignment' );
	$open = $P::evaluate( array(), $booking() );
	tzfd_assert( null === $open['allowed_staff_ids'] && $P::assignment_allowed( $open, 'st-7' ), 'no restriction rules means any staff may be assigned' );
	tzfd_assert( ! $P::assignment_allowed( null, 'st-1' ), 'missing decision fails closed' );
	tzfd_assert( ! $P::assignment_allowed( $d, null ), 'null staff is not assignable under a restriction' );

	// Cross-company rule and malformed rules throw, never silently dropped.
	tzfd_throws( function () use ( $P, $rule, $booking ) { $P::evaluate( array( $rule( array( 'company_id' => 'co-2' ) ) ), $booking() ); }, 'cross-company rule throws' );
	tzfd_throws( function () use ( $P, $rule ) { $P::validate_rule( $rule( array( 'action' => 'delete_booking' ) ) ); }, 'unknown action throws' );
	tzfd_throws( function () use ( $P, $rule ) { $P::validate_rule( $rule( array( 'action' => 'restrict_staff', 'staff_ids' => array() ) ) ); }, 'restriction without staff throws' );
	tzfd_throws( function () use ( $P, $rule ) { $P::validate_rule( $rule( array( 'min_amount_minor' => -1 ) ) ); }, 'negative threshold throws' );
	tzfd_throws( function () use ( $P, $rule ) { $P::validate_rule( $rule( array( 'min_amount_minor' => '100' ) ) ); }, 'string threshold throws' );
	tzfd_throws( function () use ( $P, $rule ) { $P::validate_rule( $rule( array( 'services' => array( '*', 'svc-1' ) ) ) ); }, 'wildcard mixed with ids throws' );
	tzfd_throws( function () use ( $P, $rule ) { $P::validate_rule( $rule( array( 'location_id' => '../x' ) ) ); }, 'malformed location id throws' );
	tzfd_throws( function () use ( $P, $rule ) { $P::validate_rule( $rule( array( 'sql' => 'DROP' ) ) ); }, 'unknown rule key throws' );
	tzfd_throws( function () use ( $P, $booking ) { $P::evaluate( array(), $booking( array( 'amount_minor' => 12.5 ) ) ); }, 'fractional amount throws' );
	tzfd_throws( function () use ( $P, $booking ) { $P::evaluate( array(), $booking( array( 'company_id' => null ) ) ); }, 'booking without company scope throws' );
	tzfd_throws( function () use ( $P ) { $P::evaluate( 'not-an-array', array() ); }, 'non-array rules throw' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
