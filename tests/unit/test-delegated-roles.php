<?php
function tzfd_test_delegated_roles() {
	$R = 'TZFD_Delegated_Roles';
	$grants = array(
		array( 'company_id' => 'co-1', 'user_id' => 10, 'role' => 'company_admin', 'location_ids' => array(), 'staff_id' => null ),
		array( 'company_id' => 'co-1', 'user_id' => 11, 'role' => 'location_admin', 'location_ids' => array( 'loc-a' ), 'staff_id' => null ),
		array( 'company_id' => 'co-1', 'user_id' => 12, 'role' => 'staff', 'location_ids' => array( 'loc-a' ), 'staff_id' => 'st-12' ),
		array( 'company_id' => 'co-1', 'user_id' => 13, 'role' => 'viewer', 'location_ids' => array( 'loc-a', 'loc-b' ), 'staff_id' => null ),
		array( 'company_id' => 'co-2', 'user_id' => 20, 'role' => 'company_admin', 'location_ids' => array(), 'staff_id' => null ),
	);

	tzfd_assert( $R::can( $grants, 'co-1', 10, 'loc-z', $R::CAP_MANAGE_STAFF ), 'company admin reaches any location in own company' );
	tzfd_assert( ! $R::can( $grants, 'co-2', 10, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'company admin of co-1 has no rights in co-2' );
	tzfd_assert( $R::can( $grants, 'co-1', 11, 'loc-a', $R::CAP_EXPORT_DATA ), 'location admin exports own location' );
	tzfd_assert( ! $R::can( $grants, 'co-1', 11, 'loc-b', $R::CAP_VIEW_SCHEDULE ), 'location admin denied at another location' );
	tzfd_assert( ! $R::can( $grants, 'co-1', 12, 'loc-a', $R::CAP_VIEW_REPORTS ), 'staff cannot read reports (least privilege)' );
	tzfd_assert( $R::can( $grants, 'co-1', 12, 'loc-a', $R::CAP_EDIT_BOOKINGS, array( 'booking_staff_id' => 'st-12' ) ), 'staff edits own booking at assigned location' );
	tzfd_assert( ! $R::can( $grants, 'co-1', 12, 'loc-a', $R::CAP_EDIT_BOOKINGS, array( 'booking_staff_id' => 'st-99' ) ), 'staff denied another staff member booking' );
	tzfd_assert( ! $R::can( $grants, 'co-1', 12, 'loc-a', $R::CAP_EDIT_BOOKINGS ), 'staff edit without booking context is denied' );
	tzfd_assert( $R::can( $grants, 'co-1', 12, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'staff may view schedule at assigned location' );
	tzfd_assert( ! $R::can( $grants, 'co-1', 13, 'loc-a', $R::CAP_EDIT_BOOKINGS ), 'viewer cannot edit bookings' );
	tzfd_assert( $R::can( $grants, 'co-1', 13, 'loc-b', $R::CAP_VIEW_REPORTS ), 'viewer reads reports at each granted location' );
	tzfd_assert( ! $R::can( $grants, 'co-1', 10, 'loc-a', 'drop_tables' ), 'unknown capability denied' );
	tzfd_assert( ! $R::can( $grants, 'co-1', 99, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'user with no grant denied' );
	tzfd_assert( ! $R::can( $grants, 'co-1', '10', 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'user id must be an integer, not a string' );

	// Invalid grants are skipped rather than widening access.
	$corrupt = array( array( 'company_id' => 'co-1', 'user_id' => 30, 'role' => 'owner', 'location_ids' => array(), 'staff_id' => null ) );
	tzfd_assert( ! $R::can( $corrupt, 'co-1', 30, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'unknown role grants nothing' );
	$bad_extra = array( array( 'company_id' => 'co-1', 'user_id' => 31, 'role' => 'company_admin', 'location_ids' => array(), 'staff_id' => null, 'is_admin' => true ) );
	tzfd_assert( ! $R::can( $bad_extra, 'co-1', 31, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'unknown key in a grant is rejected' );

	// Malformed grants throw at validation.
	tzfd_throws( function () use ( $R ) { $R::validate( array( 'company_id' => 'co 1', 'user_id' => 1, 'role' => 'viewer', 'location_ids' => array( 'a' ), 'staff_id' => null ) ); }, 'invalid company id with a space throws' );
	tzfd_throws( function () use ( $R ) { $R::validate( array( 'company_id' => 'co-1', 'user_id' => 1, 'role' => 'viewer', 'location_ids' => array(), 'staff_id' => null ) ); }, 'non-admin grant without locations throws' );
	tzfd_throws( function () use ( $R ) { $R::validate( array( 'company_id' => 'co-1', 'user_id' => 1, 'role' => 'company_admin', 'location_ids' => array( 'a' ), 'staff_id' => null ) ); }, 'company admin carrying locations throws' );
	tzfd_throws( function () use ( $R ) { $R::validate( array( 'company_id' => 'co-1', 'user_id' => 1, 'role' => 'staff', 'location_ids' => array( 'a' ), 'staff_id' => null ) ); }, 'staff grant without staff id throws' );
	tzfd_throws( function () use ( $R ) { $R::validate( array( 'company_id' => 'co-1', 'user_id' => 1, 'role' => 'viewer', 'location_ids' => array( 'a', 'a' ), 'staff_id' => null ) ); }, 'duplicate location throws' );

	// Staff transition: revoking a location removes access immediately and drops the grant when none remain.
	$after = $R::revoke( $grants, 'co-1', 12, 'loc-a' );
	tzfd_assert( ! $R::can( $after, 'co-1', 12, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'revoked staff loses access at the site immediately' );
	tzfd_assert( $R::can( $grants, 'co-1', 12, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'revoke returns new grants without mutating the input' );
	$partial = $R::revoke( $grants, 'co-1', 13, 'loc-a' );
	tzfd_assert( $R::can( $partial, 'co-1', 13, 'loc-b', $R::CAP_VIEW_REPORTS ), 'revoking one location keeps the other location' );
	tzfd_assert( ! $R::can( $partial, 'co-1', 13, 'loc-a', $R::CAP_VIEW_REPORTS ), 'revoked location is no longer reachable' );
	$admin_removed = $R::revoke( $grants, 'co-1', 10, 'loc-a' );
	tzfd_assert( ! $R::can( $admin_removed, 'co-1', 10, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'revoking company admin removes it entirely' );
}
