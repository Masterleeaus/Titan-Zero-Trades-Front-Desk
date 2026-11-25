<?php
/**
 * Pure tests for the enquiries-by-lead-source breakdown on the booking report screen (issue #365, T1).
 * Synthetic rows only. The admin read is checked by source inspection, because WordPress is not loaded here.
 */

function tzfd_test_lead_source_breakdown() {
	$B = 'TZFD_Booking_Reports';

	// Stored source text maps to the report's lead source list. Unlisted text is 'other', empty is 'unknown'.
	tzfd_assert_same( 'web_form', $B::normalise_lead_source( 'website-form' ), 'website-form maps to web_form' );
	tzfd_assert_same( 'phone', $B::normalise_lead_source( 'Phone' ), 'source matching ignores case' );
	tzfd_assert_same( 'walk_in', $B::normalise_lead_source( ' walk-in ' ), 'source matching trims whitespace' );
	tzfd_assert_same( 'other', $B::normalise_lead_source( 'radio-advert' ), 'unlisted source text maps to other' );
	tzfd_assert_same( 'unknown', $B::normalise_lead_source( '' ), 'empty source maps to unknown' );
	tzfd_assert_same( 'unknown', $B::normalise_lead_source( null ), 'null source maps to unknown' );
	tzfd_assert_same( 'unknown', $B::normalise_lead_source( array( 'phone' ) ), 'non-string source maps to unknown' );
	foreach ( $B::SOURCE_ALIASES as $alias => $target ) {
		tzfd_assert( in_array( $target, $B::LEAD_SOURCES, true ), 'alias ' . $alias . ' targets a listed lead source' );
	}

	// Window: whole days, inclusive, in UTC. Month and leap-year ends roll over correctly.
	$w = $B::utc_window( '2026-05-01', '2026-05-31' );
	tzfd_assert_same( array( 'start' => '2026-05-01 00:00:00', 'end' => '2026-06-01 00:00:00' ), $w, 'window covers May 1 to May 31 inclusive' );
	$leap = $B::utc_window( '2028-02-28', '2028-02-29' );
	tzfd_assert_same( '2028-03-01 00:00:00', $leap['end'], 'window end rolls over a leap day' );
	tzfd_assert( null === $B::utc_window( '2026-06-01', '2026-05-01' ), 'reversed window is rejected' );
	tzfd_assert( null === $B::utc_window( '2026-02-30', '2026-03-01' ), 'impossible date is rejected' );

	$rows = array(
		array( 'source' => 'website-form', 'received_utc' => '2026-05-02 09:00:00' ),
		array( 'source' => 'phone', 'received_utc' => '2026-05-31 23:59:59' ),
		array( 'source' => '', 'received_utc' => '2026-05-15 12:00:00' ),
		array( 'source' => 'referral', 'received_utc' => '2026-06-01 00:00:00' ),
		array( 'source' => 'phone', 'received_utc' => '2026-04-30 23:59:59' ),
		array( 'source' => 'radio', 'received_utc' => '2026-05-20 08:00:00' ),
	);
	$b = $B::lead_source_breakdown( '2026-05-01', '2026-05-31', $rows );
	tzfd_assert_same( 4, $b['total'], 'breakdown counts only enquiries received in range' );
	tzfd_assert_same( 1, $b['by_lead_source']['web_form'], 'website-form counted as web_form' );
	tzfd_assert_same( 1, $b['by_lead_source']['phone'], 'last second of the last day is in range, first second of next day is not' );
	tzfd_assert_same( 1, $b['by_lead_source']['unknown'], 'empty source counted as unknown, not dropped' );
	tzfd_assert_same( 1, $b['by_lead_source']['other'], 'unlisted source counted as other' );
	tzfd_assert_same( 0, $b['by_lead_source']['referral'], 'enquiry on the day after the range is excluded' );
	tzfd_assert_same( $B::LEAD_SOURCES, array_keys( $b['by_lead_source'] ), 'every lead source key is present in order' );
	tzfd_assert( $b['reconciled'] === true, 'breakdown is reconciled' );

	$empty = $B::lead_source_breakdown( '2025-01-01', '2025-01-31', $rows );
	tzfd_assert_same( 0, $empty['total'], 'empty range has zero total' );
	tzfd_assert_same( 0, array_sum( $empty['by_lead_source'] ), 'empty range has zero per-source counts' );

	tzfd_throws( function () use ( $B ) { $B::lead_source_breakdown( '2026-06-01', '2026-05-01', array() ); }, 'reversed range throws' );
	tzfd_throws( function () use ( $B ) { $B::lead_source_breakdown( '2026-05-01', '2026-05-31', 'rows' ); }, 'non-array rows throw' );
	tzfd_throws( function () use ( $B ) { $B::lead_source_breakdown( '2026-05-01', '2026-05-31', array( array( 'source' => 'phone' ) ) ); }, 'row without received_utc throws' );
	tzfd_throws( function () use ( $B ) { $B::lead_source_breakdown( '2026-05-01', '2026-05-31', array( array( 'source' => 'phone', 'received_utc' => '2026-02-30 10:00:00' ) ) ); }, 'impossible received date throws' );
	tzfd_throws( function () use ( $B ) { $B::lead_source_breakdown( '2026-05-01', '2026-05-31', array( array( 'source' => 'phone', 'received_utc' => '2026-05-02' ) ) ); }, 'received_utc without a time throws' );
	tzfd_throws( function () use ( $B ) {
		$many = array_fill( 0, $B::MAX_ENQUIRY_ROWS + 1, array( 'source' => 'phone', 'received_utc' => '2026-05-02 10:00:00' ) );
		$B::lead_source_breakdown( '2026-05-01', '2026-05-31', $many );
	}, 'more rows than the cap throws' );

	// Source inspection of the admin read: company scope, capability gates and the column allowlist.
	$admin = file_get_contents( dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-booking-report-admin.php' );
	tzfd_assert( false !== strpos( $admin, 'WHERE company_id = %d AND received_utc >= %s AND received_utc < %s' ), 'lead source read is company scoped and bounded by the UTC window' );
	tzfd_assert( false !== strpos( $admin, "'SELECT source, received_utc FROM %i" ), 'lead source read selects only source and received time' );
	tzfd_assert( false === strpos( $admin, 'SELECT *' ), 'lead source read does not select whole enquiry rows' );
	tzfd_assert( false !== strpos( $admin, 'TZFD_Company_Read_Context::id()' ), 'lead source read takes the company from the read context' );
	tzfd_assert_same( 3, substr_count( $admin, 'current_user_can( self::CAP )' ), 'menu, render and CSV export each still check the report capability' );
	tzfd_assert( false !== strpos( $admin, "check_admin_referer( 'tzfd_booking_report_csv' )" ), 'CSV export still verifies its nonce' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure.
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
