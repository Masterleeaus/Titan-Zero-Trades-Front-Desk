<?php
/**
 * Issue #334 (from #8): customer record exposure and session-switch adversarial checks.
 *
 * Pure PHP, no WordPress. The tenant check that gates CRM-linked customer records is
 * TZFD_Workspace_Customer_Owner::matches(). A customer may only be exposed when its stored company_id is exactly
 * the company of the current request. A "session switch" is modelled as the same customer being checked under a
 * different company, a missing or forged company, or a company id written in a form that looks equal but is not.
 */

function tzfd_test_customer_record_exposure() {
	$owner = 'TZFD_Workspace_Customer_Owner';

	// Baseline: the owning company sees its own customer, as an array or as an object.
	tzfd_assert( true === $owner::matches( array( 'id' => 1, 'company_id' => 7 ), 7 ), 'owning company is matched (array)' );
	tzfd_assert( true === $owner::matches( (object) array( 'id' => 1, 'company_id' => '7' ), 7 ), 'owning company is matched (object, string id)' );

	// Session switch: the same record checked after the request switches to another company is refused.
	tzfd_assert( false === $owner::matches( array( 'id' => 1, 'company_id' => 7 ), 8 ), 'switched session (company 8) cannot read company 7 customer' );
	tzfd_assert( false === $owner::matches( (object) array( 'id' => 1, 'company_id' => 7 ), 1 ), 'switched session to the default company cannot read company 7 customer' );

	// Missing or unscoped records are not tenant-authorized.
	tzfd_assert( false === $owner::matches( array( 'id' => 1 ), 7 ), 'customer without company_id is refused' );
	tzfd_assert( false === $owner::matches( array( 'id' => 1, 'company_id' => null ), 7 ), 'customer with null company_id is refused' );
	tzfd_assert( false === $owner::matches( array( 'id' => 1, 'company_id' => 0 ), 7 ), 'customer with company_id 0 is refused' );
	tzfd_assert( false === $owner::matches( array( 'id' => 1, 'company_id' => '' ), 7 ), 'customer with empty company_id is refused' );

	// Values that look like the company id but are not the same integer.
	$lookalikes = array( '07', ' 7', '7 ', "7\n", '7.0', '7e0', '+7', 7.0, true, array( 7 ) );
	foreach ( $lookalikes as $i => $raw ) {
		tzfd_assert( false === $owner::matches( array( 'company_id' => $raw ), 7 ), 'lookalike company_id #' . $i . ' is refused' );
	}

	// Overflowing and non-positive ids are refused on both sides.
	tzfd_assert( false === $owner::matches( array( 'company_id' => '9999999999999999999' ), 9999999999999999999 ), 'overflowing company_id is refused' );
	tzfd_assert( false === $owner::matches( array( 'company_id' => '-7' ), 7 ), 'negative company_id is refused' );
	tzfd_assert( false === $owner::matches( array( 'company_id' => '7' ), 0 ), 'request with company 0 cannot match any customer' );
	tzfd_assert( false === $owner::matches( array( 'company_id' => '7' ), -7 ), 'request with negative company cannot match' );
	tzfd_assert( false === $owner::matches( array( 'company_id' => '7' ), '7' ), 'request company passed as a string is refused (must be int)' );

	// Input that is not a record at all.
	tzfd_assert( false === $owner::matches( null, 7 ), 'null record is refused' );
	tzfd_assert( false === $owner::matches( false, 7 ), 'false record is refused' );
	tzfd_assert( false === $owner::matches( '7', 7 ), 'scalar record is refused' );

	// Each company's own customers stay separate when both are checked in turn.
	$rows = array(
		array( 'id' => 1, 'company_id' => 7 ),
		array( 'id' => 2, 'company_id' => 8 ),
	);
	$visible_to_7 = array_values( array_filter( $rows, function ( $r ) use ( $owner ) { return $owner::matches( $r, 7 ); } ) );
	$visible_to_8 = array_values( array_filter( $rows, function ( $r ) use ( $owner ) { return $owner::matches( $r, 8 ); } ) );
	tzfd_assert( 1 === count( $visible_to_7 ) && 1 === $visible_to_7[0]['id'], 'company 7 sees only its own customer' );
	tzfd_assert( 1 === count( $visible_to_8 ) && 2 === $visible_to_8[0]['id'], 'company 8 sees only its own customer after a switch' );
}
