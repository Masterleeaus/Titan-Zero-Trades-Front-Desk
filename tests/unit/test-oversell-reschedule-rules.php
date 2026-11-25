<?php
/**
 * Unit tests for the oversell and reschedule rules (issue #389).
 *
 * Covers the pure capacity/overlap functions the booking submit path and the
 * customer reschedule path rely on:
 *   - TZFD_Scheduling_Rules::capacity_exceeded() and its helpers (main booking path)
 *   - TZFD_Scheduling::assess() and TZFD_Scheduling::slot() (scheduling plugin, reschedule checks)
 * No WordPress and no database. The live public-form and HTTP cases stay in tests/runtime (A18, B13).
 */

$tzfd_oversell_root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes';
require_once $tzfd_oversell_root . '/class-tzfd-scheduling-rules.php';
require_once $tzfd_oversell_root . '/class-tzfd-scheduling.php';

/** One appointment in the shape the main booking path uses. */
function tzfd_oversell_appt( $slot, $extra = array() ) {
	return array_merge(
		array(
			'date'         => '2026-12-01',
			'slot'         => $slot,
			'field'        => 'app1',
			'service'      => 'Clean',
			'serviceindex' => 0,
			'capacity'     => 1,
			'quant'        => 1,
			'cancelled'    => '',
		),
		$extra
	);
}

/** Field configuration for TZFD_Scheduling: app1 has a 60 minute service, capacity 1; app2 has capacity 2. */
function tzfd_oversell_fields( $pa = 0 ) {
	return array(
		(object) array(
			'ftype'          => 'fapp',
			'name'           => 'app1',
			'avoidOverlaping' => false,
			'services'       => array(
				(object) array( 'idx' => 's1', 'name' => 'Clean', 'duration' => 60, 'capacity' => 1, 'pb' => 0, 'pa' => $pa ),
			),
		),
		(object) array(
			'ftype'          => 'fapp',
			'name'           => 'app2',
			'avoidOverlaping' => false,
			'services'       => array(
				(object) array( 'idx' => 's2', 'name' => 'Repair', 'duration' => 60, 'capacity' => 2, 'pb' => 0, 'pa' => 0 ),
			),
		),
	);
}

/** One appointment in the shape TZFD_Scheduling::assess() takes. */
function tzfd_oversell_req( $slot, $extra = array() ) {
	return array_merge(
		array(
			'field'        => 'app1',
			'serviceindex' => 0,
			'service'      => 'Clean',
			'date'         => '2026-12-01',
			'slot'         => $slot,
			'quant'        => 1,
		),
		$extra
	);
}

function tzfd_test_oversell_capacity_rule() {
	$E = 'TZFD_Scheduling_Rules::capacity_exceeded';

	// Free slot: nothing stored, nothing else requested.
	tzfd_assert_same( false, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00' ), array(), array() ), "$E: free slot is not exceeded" );

	// Oversell: the same slot is already taken at capacity 1.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00' ), array( tzfd_oversell_appt( '09:00/10:00' ) ), array() ), "$E: same slot already booked at capacity 1 is exceeded" );

	// Touching intervals are not overlap.
	tzfd_assert_same( false, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '10:00/11:00' ), array( tzfd_oversell_appt( '09:00/10:00' ) ), array() ), "$E: back-to-back slot is not exceeded" );

	// Partial overlap at capacity 1 is refused.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:30/10:30' ), array( tzfd_oversell_appt( '09:00/10:00' ) ), array() ), "$E: partial overlap at capacity 1 is exceeded" );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00' ), array( tzfd_oversell_appt( '09:30/10:30' ) ), array() ), "$E: partial overlap the other way is exceeded" );

	// Same submission: a second requested appointment on the same slot counts too.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00' ), array(), array( tzfd_oversell_appt( '09:00/10:00' ) ) ), "$E: two requests for one slot at capacity 1 are exceeded" );

	// Capacity 2: one booking leaves room, two fill it.
	$cap2 = tzfd_oversell_appt( '09:00/10:00', array( 'capacity' => 2 ) );
	tzfd_assert_same( false, TZFD_Scheduling_Rules::capacity_exceeded( $cap2, array( tzfd_oversell_appt( '09:00/10:00', array( 'capacity' => 2 ) ) ), array() ), "$E: one of two places taken is not exceeded" );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( $cap2, array( tzfd_oversell_appt( '09:00/10:00', array( 'capacity' => 2 ) ), tzfd_oversell_appt( '09:00/10:00', array( 'capacity' => 2 ) ) ), array() ), "$E: two of two places taken is exceeded" );

	// Capacity 2, quantity 2 stored over 09:00-10:00 and a quantity 1 candidate at 09:30-10:30: 3 at 09:30 is over 2.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:30/10:30', array( 'capacity' => 2 ) ), array( tzfd_oversell_appt( '09:00/10:00', array( 'capacity' => 2, 'quant' => 2 ) ) ), array() ), "$E: quantity 2 stored plus 1 at the overlap is over capacity 2" );
	tzfd_assert_same( false, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '10:00/11:00', array( 'capacity' => 2 ) ), array( tzfd_oversell_appt( '09:00/10:00', array( 'capacity' => 2, 'quant' => 2 ) ) ), array() ), "$E: quantity 2 stored ending at 10:00 does not block 10:00" );

	// Quantity is capped at capacity; a zero quantity counts as one place.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00', array( 'quant' => 0 ) ), array( tzfd_oversell_appt( '09:00/10:00' ) ), array() ), "$E: zero quantity still counts as one place" );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00', array( 'quant' => 9 ) ), array(), array() ), "$E: quantity above capacity 1 is capped and then fills the slot" );

	// Different field never conflicts.
	tzfd_assert_same( false, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00' ), array( tzfd_oversell_appt( '09:00/10:00', array( 'field' => 'app2' ) ) ), array() ), "$E: a booking on another field does not block" );

	// Different service on the same field only conflicts when avoid-overlap is set on either side.
	$other = tzfd_oversell_appt( '09:00/10:00', array( 'service' => 'Repair', 'serviceindex' => 1 ) );
	tzfd_assert_same( false, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00' ), array( $other ), array() ), "$E: other service without avoid-overlap does not block" );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00', array( 'avoid_overlapping' => true ) ), array( $other ), array() ), "$E: other service blocks when the candidate avoids overlap" );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '09:00/10:00' ), array( tzfd_oversell_appt( '09:00/10:00', array( 'service' => 'Repair', 'serviceindex' => 1, 'avoid_overlapping' => true ) ) ), array() ), "$E: other service blocks when the stored booking avoids overlap" );

	// Buffers extend the occupied interval.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '10:00/11:00' ), array( tzfd_oversell_appt( '09:00/10:00', array( 'buffer_after' => 15 ) ) ), array() ), "$E: stored buffer after pushes into the next slot" );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( '10:00/11:00', array( 'buffer_before' => 15 ) ), array( tzfd_oversell_appt( '09:00/10:00' ) ), array() ), "$E: candidate buffer before pushes back into the previous slot" );

	// Unparseable slot falls back to the same-service, same-slot check.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::capacity_exceeded( tzfd_oversell_appt( 'nonsense' ), array( tzfd_oversell_appt( 'nonsense' ) ), array() ), "$E: unparseable slot is refused when the same slot is held" );
}

function tzfd_test_oversell_status_and_quantity_helpers() {
	// Occupied statuses hold a place; released statuses do not.
	tzfd_assert_same( true, TZFD_Scheduling_Rules::is_occupied_status( '' ), 'is_occupied_status: approved (stored as empty) holds a place' );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::is_occupied_status( 'Pending' ), 'is_occupied_status: pending holds a place' );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::is_occupied_status( 'Attended' ), 'is_occupied_status: attended holds a place' );
	tzfd_assert_same( false, TZFD_Scheduling_Rules::is_occupied_status( 'Cancelled' ), 'is_occupied_status: cancelled frees the place' );
	tzfd_assert_same( false, TZFD_Scheduling_Rules::is_occupied_status( 'Cancelled by customer' ), 'is_occupied_status: customer cancel frees the place' );
	tzfd_assert_same( false, TZFD_Scheduling_Rules::is_occupied_status( 'Rejected' ), 'is_occupied_status: rejected frees the place' );
	tzfd_assert_same( true, TZFD_Scheduling_Rules::is_occupied_status( 'Cancelled', true ), 'is_occupied_status: a lock always holds the place' );

	tzfd_assert_same( 1, TZFD_Scheduling_Rules::normalize_quantity( 0 ), 'normalize_quantity: zero becomes one' );
	tzfd_assert_same( 1, TZFD_Scheduling_Rules::normalize_quantity( -4 ), 'normalize_quantity: negative becomes one' );
	tzfd_assert_same( 2, TZFD_Scheduling_Rules::normalize_quantity( 5, 2 ), 'normalize_quantity: quantity is capped at capacity' );
	tzfd_assert_same( 1, TZFD_Scheduling_Rules::normalize_quantity( 5, 0 ), 'normalize_quantity: capacity below one is treated as one' );

	tzfd_assert_same( '09:00/10:30', TZFD_Scheduling::slot( '09:00', 90 ), 'TZFD_Scheduling::slot: 90 minutes from 09:00 ends 10:30' );
	tzfd_throws( function () { TZFD_Scheduling::slot( '09:00', 0 ); }, 'TZFD_Scheduling::slot: zero duration is refused' );
	tzfd_throws( function () { TZFD_Scheduling::slot( '25:00', 30 ); }, 'TZFD_Scheduling::slot: impossible start time is refused' );
}

function tzfd_test_oversell_assess_rules() {
	$fields = tzfd_oversell_fields();
	$E      = 'TZFD_Scheduling::assess';

	// Free slot.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00' ) ), array(), $fields );
	tzfd_assert_same( true, $r['ok'], "$E: free slot is accepted" );

	// Oversell: the slot is held at capacity 1.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00' ) ), array( tzfd_oversell_req( '09:00/10:00', array( 'cancelled' => '' ) ) ), $fields );
	tzfd_assert_same( false, $r['ok'], "$E: held slot is refused" );
	tzfd_assert_same( 'capacity_exceeded', $r['conflicts'][0]['code'] ?? '', "$E: held slot reports capacity_exceeded" );

	// Released bookings free the place.
	foreach ( array( 'Cancelled', 'Cancelled by customer', 'Rejected' ) as $released ) {
		$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00' ) ), array( tzfd_oversell_req( '09:00/10:00', array( 'cancelled' => $released ) ) ), $fields );
		tzfd_assert_same( true, $r['ok'], "$E: a $released booking does not hold the slot" );
	}

	// Pending and locked bookings hold the place.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00' ) ), array( tzfd_oversell_req( '09:00/10:00', array( 'cancelled' => 'Pending' ) ) ), $fields );
	tzfd_assert_same( false, $r['ok'], "$E: a pending booking holds the slot" );
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00' ) ), array( tzfd_oversell_req( '09:00/10:00', array( 'cancelled' => 'Cancelled', '_locked' => true ) ) ), $fields );
	tzfd_assert_same( false, $r['ok'], "$E: a locked booking holds the slot even when cancelled" );

	// Capacity 2 on app2: one place left after one booking, none after two.
	$one = array( tzfd_oversell_req( '09:00/10:00', array( 'field' => 'app2', 'serviceindex' => 0, 'service' => 'Repair' ) ) );
	$r   = TZFD_Scheduling::assess( $one, array( tzfd_oversell_req( '09:00/10:00', array( 'field' => 'app2', 'serviceindex' => 0, 'service' => 'Repair' ) ) ), $fields );
	tzfd_assert_same( true, $r['ok'], "$E: capacity 2 with one place taken is accepted" );
	$two = array( tzfd_oversell_req( '09:00/10:00', array( 'field' => 'app2', 'serviceindex' => 0, 'service' => 'Repair' ) ) );
	$stored2 = array(
		tzfd_oversell_req( '09:00/10:00', array( 'field' => 'app2', 'serviceindex' => 0, 'service' => 'Repair', 'cancelled' => '' ) ),
		tzfd_oversell_req( '09:00/10:00', array( 'field' => 'app2', 'serviceindex' => 0, 'service' => 'Repair', 'cancelled' => '' ) ),
	);
	$r = TZFD_Scheduling::assess( $two, $stored2, $fields );
	tzfd_assert_same( false, $r['ok'], "$E: capacity 2 with two places taken is refused" );

	// Bookings on another field do not count.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00' ) ), array( tzfd_oversell_req( '09:00/10:00', array( 'field' => 'app2', 'serviceindex' => 0, 'service' => 'Repair' ) ) ), $fields );
	tzfd_assert_same( true, $r['ok'], "$E: a booking on another field does not block" );

	// Reschedule: the booking's own old record is left out of the stored list, so moving 09:00 to 09:30 is not blocked by itself.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:30/10:30' ) ), array(), $fields );
	tzfd_assert_same( true, $r['ok'], "$E: reschedule to an overlapping slot is allowed when the old record is excluded" );

	// Reschedule into a slot held by someone else is refused, including a partial overlap.
	$other_booking = array( tzfd_oversell_req( '10:00/11:00', array( 'cancelled' => '' ) ) );
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '10:00/11:00' ) ), $other_booking, $fields );
	tzfd_assert_same( false, $r['ok'], "$E: reschedule into a held slot is refused" );
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '10:30/11:30' ) ), $other_booking, $fields );
	tzfd_assert_same( false, $r['ok'], "$E: reschedule partly overlapping a held slot is refused" );

	// Reschedule with a duration that does not match the service is refused.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/09:30' ) ), array(), $fields );
	tzfd_assert_same( false, $r['ok'], "$E: duration that does not match the service is refused" );
	tzfd_assert_same( 'duration_mismatch', $r['conflicts'][0]['code'] ?? '', "$E: duration mismatch reports its code" );

	// Unknown service and over-capacity quantity are refused.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00', array( 'serviceindex' => 5, 'service' => 'Nope' ) ) ), array(), $fields );
	tzfd_assert_same( false, $r['ok'], "$E: unknown service is refused" );
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00', array( 'quant' => 2 ) ) ), array(), $fields );
	tzfd_assert_same( 'invalid_quantity', $r['conflicts'][0]['code'] ?? '', "$E: quantity over service capacity reports invalid_quantity" );

	// Malformed slot and malformed request.
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '9am/10am' ) ), array(), $fields );
	tzfd_assert_same( 'invalid_slot', $r['conflicts'][0]['code'] ?? '', "$E: malformed slot reports invalid_slot" );
	$r = TZFD_Scheduling::assess( 'not-a-list', array(), $fields );
	tzfd_assert_same( 'invalid_request', $r['conflicts'][0]['code'] ?? '', "$E: non-array request reports invalid_request" );

	// Buffers count: service pa 15 makes 09:00-10:00 end at 10:15, which clashes with a 10:00 booking.
	$buffered = tzfd_oversell_fields( 15 );
	$r = TZFD_Scheduling::assess( array( tzfd_oversell_req( '09:00/10:00' ) ), array( tzfd_oversell_req( '10:00/11:00' ) ), $buffered );
	tzfd_assert_same( false, $r['ok'], "$E: service after-buffer makes the next slot a conflict" );
}

function tzfd_test_oversell_reschedule_rules() {
	tzfd_test_oversell_capacity_rule();
	tzfd_test_oversell_status_and_quantity_helpers();
	tzfd_test_oversell_assess_rules();
}
