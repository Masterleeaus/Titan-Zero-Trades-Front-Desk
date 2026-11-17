<?php
/**
 * Day-by-day appointment counts from the booking items the Calendar already reads (issue #12).
 *
 * Input is the item list from TZFD_Booking_View::fetch(), so the counts come from the same stored
 * appointments the calendar shows. Pure PHP, no WordPress calls. Totals must equal the sum of the days, or
 * the summary throws instead of showing numbers that do not add up.
 *
 * Not measured here, because the plugin does not store them: lead source, first-response time, revenue and
 * refunds, location and service duration per appointment, and enquiry-to-booking conversion. Those are named
 * as gaps on the report screen rather than shown as zero.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Booking_Report_Summary {

	const DAY       = 86400;
	const MAX_DAYS  = 366;
	const MAX_ITEMS = 20000;

	/**
	 * @param array  $items Items with date (Y-m-d) and status_class (confirmed, pending, attended, cancelled, or other).
	 * @param string $from  Y-m-d inclusive.
	 * @param string $to    Y-m-d inclusive.
	 * @return array days (date => counts), totals, range, reconciled.
	 * @throws InvalidArgumentException On invalid range, too many items, or a failed reconciliation.
	 */
	public static function summarise( $items, $from, $to ) {
		$from_ts = self::day( $from );
		$to_ts   = self::day( $to );
		if ( null === $from_ts || null === $to_ts || $from_ts > $to_ts ) { throw new InvalidArgumentException( 'invalid_date_range' ); }
		if ( ( $to_ts - $from_ts ) / self::DAY + 1 > self::MAX_DAYS ) { throw new InvalidArgumentException( 'range_too_long' ); }
		if ( ! is_array( $items ) || count( $items ) > self::MAX_ITEMS ) { throw new InvalidArgumentException( 'invalid_items' ); }

		$days = array();
		for ( $t = $from_ts; $t <= $to_ts; $t += self::DAY ) {
			$days[ gmdate( 'Y-m-d', $t ) ] = self::blank();
		}
		$counted = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['date'] ) || ! is_string( $item['date'] ) ) { throw new InvalidArgumentException( 'invalid_item' ); }
			if ( ! isset( $days[ $item['date'] ] ) ) { continue; }
			$class = isset( $item['status_class'] ) && is_string( $item['status_class'] ) ? $item['status_class'] : 'other';
			$key   = in_array( $class, array( 'confirmed', 'pending', 'attended', 'cancelled' ), true ) ? $class : 'other';
			$days[ $item['date'] ]['appointments']++;
			$days[ $item['date'] ][ $key ]++;
			$counted++;
		}

		$totals = self::blank();
		foreach ( $days as $counts ) {
			foreach ( $totals as $name => $value ) {
				$totals[ $name ] += $counts[ $name ];
			}
		}
		// Each appointment lands in exactly one status bucket, and every counted appointment is in a day.
		$status_sum = $totals['confirmed'] + $totals['pending'] + $totals['attended'] + $totals['cancelled'] + $totals['other'];
		if ( $totals['appointments'] !== $counted || $status_sum !== $totals['appointments'] ) {
			throw new InvalidArgumentException( 'reconciliation_failed' );
		}
		$totals['active'] = $totals['appointments'] - $totals['cancelled'];

		$rows = array();
		foreach ( $days as $date => $counts ) {
			$counts['active'] = $counts['appointments'] - $counts['cancelled'];
			$rows[] = array_merge( array( 'date' => $date ), $counts );
		}

		return array(
			'range'      => array( 'from' => (string) $from, 'to' => (string) $to ),
			'days'       => $rows,
			'totals'     => $totals,
			'reconciled' => true,
		);
	}

	/** Rows for the CSV export: only date and counts, never names, emails or contact details. */
	public static function csv_rows( $summary ) {
		$out = array();
		foreach ( (array) $summary['days'] as $day ) {
			$out[] = array( 'date' => $day['date'], 'appointments' => $day['appointments'], 'cancelled' => $day['cancelled'], 'active' => $day['active'] );
		}
		return $out;
	}

	private static function blank() {
		return array( 'appointments' => 0, 'confirmed' => 0, 'pending' => 0, 'attended' => 0, 'cancelled' => 0, 'other' => 0 );
	}

	private static function day( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $p ) || ! checkdate( (int) $p[2], (int) $p[3], (int) $p[1] ) ) {
			return null;
		}
		return gmmktime( 0, 0, 0, (int) $p[2], (int) $p[3], (int) $p[1] );
	}
}
