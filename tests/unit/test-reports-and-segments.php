<?php
function tzfd_test_booking_reports() {
	$B = 'TZFD_Booking_Reports';
	$records = array(
		array( 'id' => 'r1', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'lead_source' => 'web_form', 'enquiry_date' => '2026-05-02', 'response_seconds' => 600, 'booked' => true, 'cancelled' => false, 'duration_minutes' => 60,
			'transactions' => array(
				array( 'id' => 't1', 'type' => 'payment', 'status' => 'settled', 'amount_minor' => 10000, 'date' => '2026-05-02' ),
				array( 'id' => 't2', 'type' => 'refund', 'status' => 'settled', 'amount_minor' => 2000, 'date' => '2026-05-10' ),
			) ),
		array( 'id' => 'r2', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'lead_source' => 'phone', 'enquiry_date' => '2026-05-03', 'response_seconds' => 120, 'booked' => true, 'cancelled' => true, 'duration_minutes' => 60,
			'transactions' => array( array( 'id' => 't3', 'type' => 'payment', 'status' => 'settled', 'amount_minor' => 5000, 'date' => '2026-05-03' ) ) ),
		array( 'id' => 'r3', 'company_id' => 'co-1', 'location_id' => 'loc-b', 'lead_source' => 'phone', 'enquiry_date' => '2026-05-04', 'response_seconds' => null, 'booked' => false, 'cancelled' => false, 'duration_minutes' => 0,
			'transactions' => array( array( 'id' => 't4', 'type' => 'payment', 'status' => 'pending', 'amount_minor' => 3000, 'date' => '2026-05-04' ) ) ),
		array( 'id' => 'r4', 'company_id' => 'co-1', 'location_id' => 'loc-b', 'lead_source' => 'web_form', 'enquiry_date' => '2026-04-30', 'response_seconds' => 60, 'booked' => true, 'cancelled' => false, 'duration_minutes' => 60, 'transactions' => array() ),
		array( 'id' => 'r5', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'enquiry_date' => '2026-05-20', 'response_seconds' => 300, 'booked' => true, 'cancelled' => false, 'duration_minutes' => 90,
			'transactions' => array( array( 'id' => 't5', 'type' => 'payment', 'status' => 'settled', 'amount_minor' => 4000, 'date' => '2026-06-02' ) ) ),
	);
	$request = array( 'from' => '2026-05-01', 'to' => '2026-05-31' );
	$capacity = array( 'loc-a' => 600, 'loc-b' => 480 );

	$r = $B::summarise( 'co-1', $request, $records, $capacity );
	tzfd_assert_same( array( 'enquiries' => 4, 'booked' => 3, 'revenue_minor' => 13000 ), $r['totals'], 'totals count only the cohort; revenue is settled cash in range' );
	tzfd_assert_same( 3, $r['conversion']['numerator'], 'conversion numerator is booked cohort' );
	tzfd_assert_same( 4, $r['conversion']['denominator'], 'conversion denominator is enquiries' );
	tzfd_assert( abs( $r['conversion']['rate'] - 0.75 ) < 1e-9, 'conversion rate is 3/4' );
	tzfd_assert( abs( $r['cancellation']['rate'] - 1 / 3 ) < 1e-9, 'cancellation rate is 1 cancelled of 3 booked' );
	tzfd_assert_same( 300, $r['response_seconds_median'], 'median response over responders 600,120,300 is 300' );
	tzfd_assert_same( 8000, $r['by_lead_source']['web_form']['revenue_minor'], 'web_form revenue is payment less refund' );
	tzfd_assert_same( 5000, $r['by_lead_source']['phone']['revenue_minor'], 'phone revenue excludes the pending payment' );
	tzfd_assert( $r['reconciled'] === true, 'report is reconciled' );
	tzfd_assert_same( 'loc-a', $r['utilisation'][0]['location_id'], 'utilisation rows are sorted by location' );
	tzfd_assert_same( 150, $r['utilisation'][0]['booked_minutes'], 'cancelled booking excluded from booked minutes' );
	tzfd_assert( abs( $r['utilisation'][0]['rate'] - 0.25 ) < 1e-9, 'utilisation is 150 of 600 minutes' );
	tzfd_assert_same( 0.0, $r['utilisation'][1]['rate'], 'location with capacity but no bookings shows zero utilisation' );

	// Filters narrow the cohort without changing the arithmetic.
	$f = $B::summarise( 'co-1', $request + array( 'location_ids' => array( 'loc-a' ) ), $records, $capacity );
	tzfd_assert_same( array( 'enquiries' => 3, 'booked' => 3, 'revenue_minor' => 13000 ), $f['totals'], 'location filter narrows the cohort' );
	tzfd_assert( abs( $f['conversion']['rate'] - 1.0 ) < 1e-9, 'filtered conversion' );
	$s = $B::summarise( 'co-1', $request + array( 'lead_sources' => array( 'phone' ) ), $records, $capacity );
	tzfd_assert_same( 2, $s['totals']['enquiries'], 'lead source filter keeps only phone enquiries' );

	// Empty cohort: rates are null, not a misleading zero.
	$empty = $B::summarise( 'co-1', array( 'from' => '2025-01-01', 'to' => '2025-01-31' ), $records, array() );
	tzfd_assert( null === $empty['conversion']['rate'] && null === $empty['response_seconds_median'], 'empty cohort has null rates and median' );

	// Median of an even count averages the middle two.
	$even = $B::summarise( 'co-1', $request, array(
		array( 'id' => 'e1', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'enquiry_date' => '2026-05-02', 'response_seconds' => 100, 'booked' => false, 'cancelled' => false, 'duration_minutes' => 0, 'transactions' => array() ),
		array( 'id' => 'e2', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'enquiry_date' => '2026-05-02', 'response_seconds' => 200, 'booked' => false, 'cancelled' => false, 'duration_minutes' => 0, 'transactions' => array() ),
	), array() );
	tzfd_assert( abs( $even['response_seconds_median'] - 150 ) < 1e-9, 'median of 100 and 200 is 150' );

	// Utilisation is capped at 100 percent.
	$over = $B::summarise( 'co-1', $request, array(
		array( 'id' => 'o1', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'enquiry_date' => '2026-05-02', 'booked' => true, 'cancelled' => false, 'duration_minutes' => 700, 'transactions' => array() ),
	), array( 'loc-a' => 600 ) );
	tzfd_assert( abs( $over['utilisation'][0]['rate'] - 1.0 ) < 1e-9, 'utilisation capped at 1.0' );

	// Failures: all throw.
	tzfd_throws( function () use ( $B, $request, $records ) { $B::summarise( 'co-2', $request, $records, array() ); }, 'records from another company are rejected' );
	tzfd_throws( function () use ( $B, $request, $records ) { $B::summarise( 'co-1', array( 'from' => '2026-05-31', 'to' => '2026-05-01' ), $records, array() ); }, 'inverted range throws' );
	tzfd_throws( function () use ( $B, $records ) { $B::summarise( 'co-1', array( 'from' => '2026-02-30', 'to' => '2026-05-01' ), $records, array() ); }, 'impossible date throws' );
	tzfd_throws( function () use ( $B, $request, $records ) { $B::summarise( 'co-1', $request + array( 'lead_sources' => array( 'carrier_pigeon' ) ), $records, array() ); }, 'unknown lead source filter throws' );
	$dup = array( $records[0], $records[0] );
	tzfd_throws( function () use ( $B, $request, $dup ) { $B::summarise( 'co-1', $request, $dup, array() ); }, 'duplicate record throws' );
	$bad_tx = $records;
	$bad_tx[0]['transactions'][0]['amount_minor'] = -5;
	tzfd_throws( function () use ( $B, $request, $bad_tx ) { $B::summarise( 'co-1', $request, $bad_tx, array() ); }, 'negative transaction amount throws' );
	$bad_src = $records;
	$bad_src[0]['lead_source'] = 'telepathy';
	tzfd_throws( function () use ( $B, $request, $bad_src ) { $B::summarise( 'co-1', $request, $bad_src, array() ); }, 'unknown record lead source throws' );
	tzfd_throws( function () use ( $B, $request, $records ) {
		$x = $records;
		$x[0]['booked'] = false;
		$x[0]['cancelled'] = true;
		$B::summarise( 'co-1', $request, $x, array() );
	}, 'cancelled but not booked throws' );

	// CSV: allowlisted columns, and spreadsheet formula cells are neutralised.
	$csv = $B::csv( array( 'source', 'enquiries', 'revenue_minor' ), array( array( 'source' => '=HYPERLINK("x")', 'enquiries' => 2, 'revenue_minor' => 8000 ) ) );
	tzfd_assert_same( "source,enquiries,revenue_minor\r\n\"'=HYPERLINK(\"\"x\"\")\",\"2\",\"8000\"\r\n", $csv, 'CSV neutralises formulas and escapes quotes' );
	tzfd_throws( function () use ( $B ) { $B::csv( array( 'password_hash' ), array() ); }, 'non-allowlisted export column throws' );
}

function tzfd_test_customer_segments() {
	$S = 'TZFD_Customer_Segments';
	$customers = array(
		array( 'id' => 'c1', 'company_id' => 'co-1', 'booked_count' => 5, 'cancelled_count' => 0, 'last_booked_date' => '2026-05-20', 'lead_source' => 'web_form', 'marketing_consent' => true ),
		array( 'id' => 'c2', 'company_id' => 'co-1', 'booked_count' => 2, 'cancelled_count' => 1, 'last_booked_date' => '2026-01-31', 'lead_source' => 'phone', 'marketing_consent' => false ),
		array( 'id' => 'c3', 'company_id' => 'co-1', 'booked_count' => 0, 'cancelled_count' => 0, 'last_booked_date' => null, 'lead_source' => 'unknown', 'marketing_consent' => true ),
		array( 'id' => 'c4', 'company_id' => 'co-1', 'booked_count' => 3, 'cancelled_count' => 0, 'last_booked_date' => '2025-05-31', 'lead_source' => 'phone', 'marketing_consent' => true ),
	);
	$segments = array(
		array( 'id' => 'regulars', 'name' => 'Regulars', 'min_bookings' => 3 ),
		array( 'id' => 'lapsed', 'name' => 'Lapsed 90 days', 'min_days_since_last' => 90, 'requires_marketing_consent' => true ),
		array( 'id' => 'phone', 'name' => 'Phone leads', 'lead_source' => 'phone' ),
		array( 'id' => 'everyone', 'name' => 'Everyone' ),
	);
	$out = $S::evaluate( 'co-1', '2026-05-31', $segments, $customers );
	$by = array();
	foreach ( $out['segments'] as $seg ) { $by[ $seg['id'] ] = $seg; }
	tzfd_assert_same( array( 'c1', 'c4' ), $by['regulars']['member_ids'], 'regulars are customers with 3 or more bookings' );
	tzfd_assert_same( array( 'c4' ), $by['lapsed']['member_ids'], 'lapsed marketing segment drops customers without consent' );
	tzfd_assert_same( 1, $by['lapsed']['excluded_no_consent'], 'excluded no-consent count is reported honestly' );
	tzfd_assert_same( array( 'c2', 'c4' ), $by['phone']['member_ids'], 'lead source segment' );
	tzfd_assert_same( 4, $by['everyone']['count'], 'segment with no conditions includes every customer, even with no bookings' );
	tzfd_assert( ! in_array( 'c3', $by['lapsed']['member_ids'], true ), 'customer with no booking date never matches a day-based segment' );

	tzfd_throws( function () use ( $S, $segments, $customers ) { $S::evaluate( 'co-2', '2026-05-31', $segments, $customers ); }, 'customers from another company are rejected' );
	tzfd_throws( function () use ( $S, $segments, $customers ) {
		$x = $customers;
		$x[0]['last_booked_date'] = '2026-06-01';
		$S::evaluate( 'co-1', '2026-05-31', $segments, $x );
	}, 'last booking after as-of date throws' );
	tzfd_throws( function () use ( $S, $customers ) { $S::evaluate( 'co-1', '2026-05-31', array( array( 'id' => 'x', 'name' => 'X', 'min_days_since_last' => 50, 'max_days_since_last' => 10 ) ), $customers ); }, 'inverted day bounds throw' );
	tzfd_throws( function () use ( $S, $customers ) { $S::evaluate( 'co-1', '2026-05-31', array( array( 'id' => 'x', 'name' => 'X', 'sql' => 'x' ) ), $customers ); }, 'unknown segment key throws' );
	tzfd_throws( function () use ( $S, $customers ) {
		$x = $customers;
		$x[1] = $customers[0];
		$S::evaluate( 'co-1', '2026-05-31', array(), $x );
	}, 'duplicate customer id throws' );
	tzfd_throws( function () use ( $S, $customers ) {
		$x = $customers;
		$x[0]['cancelled_count'] = 9;
		$S::evaluate( 'co-1', '2026-05-31', array(), $x );
	}, 'more cancellations than bookings throws' );
}
