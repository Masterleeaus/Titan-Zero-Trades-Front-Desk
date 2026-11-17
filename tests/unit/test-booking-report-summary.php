<?php
/**
 * Pure tests for the day-by-day appointment summary (issue #12): range checks, status buckets,
 * reconciliation, and a CSV that carries only dates and counts.
 */

function tzfd_test_booking_report_summary() {
	$S = 'TZFD_Booking_Report_Summary';
	$items = array(
		array( 'date' => '2026-06-01', 'status_class' => 'confirmed', 'customer' => 'Ann', 'email' => 'ann@example.test' ),
		array( 'date' => '2026-06-01', 'status_class' => 'cancelled', 'customer' => 'Bo', 'email' => 'bo@example.test' ),
		array( 'date' => '2026-06-02', 'status_class' => 'pending', 'customer' => 'Cy', 'email' => 'cy@example.test' ),
		array( 'date' => '2026-06-02', 'status_class' => 'attended' ),
		array( 'date' => '2026-06-03', 'status_class' => 'weird' ),
		array( 'date' => '2026-07-09', 'status_class' => 'confirmed' ),
	);

	$s = $S::summarise( $items, '2026-06-01', '2026-06-03' );
	tzfd_assert_same( 3, count( $s['days'] ), 'one row per day in the range, including empty days' );
	tzfd_assert_same( 5, $s['totals']['appointments'], 'out-of-range appointment is not counted' );
	tzfd_assert_same( 1, $s['totals']['cancelled'], 'cancelled appointments are counted once' );
	tzfd_assert_same( 4, $s['totals']['active'], 'active is appointments minus cancelled' );
	tzfd_assert_same( 1, $s['totals']['other'], 'an unknown status goes to the other bucket, still counted' );
	tzfd_assert( true === $s['reconciled'], 'summary reports reconciled' );
	tzfd_assert_same( $s['totals']['appointments'], array_sum( array_column( $s['days'], 'appointments' ) ), 'day rows add to the total' );

	$empty = $S::summarise( array(), '2026-06-01', '2026-06-01' );
	tzfd_assert( 1 === count( $empty['days'] ) && 0 === $empty['totals']['appointments'], 'no items gives zero counts for each day' );

	tzfd_throws( function () use ( $S ) { $S::summarise( array(), '2026-06-05', '2026-06-01' ); }, 'range that ends before it starts is refused' );
	tzfd_throws( function () use ( $S ) { $S::summarise( array(), '2026-02-30', '2026-03-01' ); }, 'impossible date is refused' );
	tzfd_throws( function () use ( $S ) { $S::summarise( array(), '2025-01-01', '2026-06-01' ); }, 'range longer than a year is refused' );
	tzfd_throws( function () use ( $S ) { $S::summarise( array( array( 'status_class' => 'confirmed' ) ), '2026-06-01', '2026-06-01' ); }, 'item without a date is refused' );

	$rows = $S::csv_rows( $s );
	tzfd_assert_same( array( 'date', 'appointments', 'cancelled', 'active' ), array_keys( $rows[0] ), 'csv rows carry only date and counts' );
	$csv = TZFD_Booking_Reports::csv( array( 'date', 'appointments', 'cancelled', 'active' ), $rows );
	tzfd_assert( false === strpos( $csv, '@example.test' ) && false === strpos( $csv, 'Ann' ), 'csv holds no customer names or emails' );
	tzfd_assert( 0 === strpos( $csv, '"date","appointments","cancelled","active"' ) || 0 === strpos( $csv, 'date,appointments,cancelled,active' ), 'csv header matches the requested columns' );
	tzfd_throws( function () { TZFD_Booking_Reports::csv( array( 'customer' ), array() ); }, 'csv refuses a column outside the allowlist' );
}

