<?php
/**
 * Turns recurring local calendar dates plus a local start time into absolute UTC instants, DST-safe.
 *
 * Calendar dates come from TZFD_Scheduling_Rules::recurrence_dates(). This class adds the wall-clock time
 * and the site timezone. Pure PHP, no WordPress calls.
 *
 * Gap (clock jumps forward, the wall time never happens): shift_forward starts at the same instant the
 * wall clock would reach it on the earlier offset, so the booking begins at the first real minute after
 * the gap. skip drops that occurrence and reports it. Overlap (clock repeats): earlier or later instant.
 * Duration is absolute minutes, so a series keeps its length across a DST change.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Recurring_Local_Times {

	const MAX_OCCURRENCES = 366;
	const GAP_SHIFT       = 'shift_forward';
	const GAP_SKIP        = 'skip';
	const OVERLAP_EARLIER = 'earlier';
	const OVERLAP_LATER   = 'later';

	/**
	 * @param string[] $local_dates      Y-m-d dates in the site timezone.
	 * @param string   $local_time       HH:MM, 24 hour.
	 * @param string   $timezone         IANA identifier, e.g. Australia/Melbourne.
	 * @param int      $duration_minutes 1 to 1440.
	 * @return array{occurrences: array, skipped: array}
	 * @throws InvalidArgumentException On invalid input. Nothing is partially returned.
	 */
	public static function expand( $local_dates, $local_time, $timezone, $duration_minutes, $gap = self::GAP_SHIFT, $overlap = self::OVERLAP_EARLIER ) {
		if ( ! is_array( $local_dates ) || count( $local_dates ) > self::MAX_OCCURRENCES || array_values( $local_dates ) !== $local_dates ) {
			throw new InvalidArgumentException( 'invalid_date_list' );
		}
		if ( ! is_string( $local_time ) || ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $local_time ) ) {
			throw new InvalidArgumentException( 'invalid_local_time' );
		}
		if ( ! is_string( $timezone ) ) { throw new InvalidArgumentException( 'invalid_timezone' ); }
		try {
			$tz = new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			throw new InvalidArgumentException( 'invalid_timezone' );
		}
		if ( ! is_int( $duration_minutes ) || $duration_minutes < 1 || $duration_minutes > 1440 ) {
			throw new InvalidArgumentException( 'invalid_duration' );
		}
		if ( ! in_array( $gap, array( self::GAP_SHIFT, self::GAP_SKIP ), true ) ) { throw new InvalidArgumentException( 'invalid_gap_policy' ); }
		if ( ! in_array( $overlap, array( self::OVERLAP_EARLIER, self::OVERLAP_LATER ), true ) ) { throw new InvalidArgumentException( 'invalid_overlap_policy' ); }

		$occurrences = array();
		$skipped     = array();
		$seen        = array();
		foreach ( $local_dates as $date ) {
			if ( ! is_string( $date ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $p ) || ! checkdate( (int) $p[2], (int) $p[3], (int) $p[1] ) ) {
				throw new InvalidArgumentException( 'invalid_local_date' );
			}
			$wall   = $date . ' ' . $local_time;
			$result = self::resolve( $wall, $tz, $overlap );
			if ( 'gap' === $result['kind'] && self::GAP_SKIP === $gap ) {
				$skipped[] = array( 'local' => $wall, 'reason' => 'nonexistent_local_time' );
				continue;
			}
			if ( isset( $seen[ $result['start'] ] ) ) {
				$skipped[] = array( 'local' => $wall, 'reason' => 'duplicate_instant' );
				continue;
			}
			$seen[ $result['start'] ] = true;
			$occurrences[] = array(
				'local'      => $wall,
				'start_utc'  => $result['start'],
				'end_utc'    => $result['start'] + $duration_minutes * 60,
				'adjustment' => $result['adjustment'],
			);
		}
		return array( 'occurrences' => $occurrences, 'skipped' => $skipped );
	}

	/** Resolves one wall-clock string to a UTC instant. Returns kind normal, gap or overlap. */
	private static function resolve( $wall, DateTimeZone $tz, $overlap ) {
		$wall_ts = ( new DateTimeImmutable( $wall . ':00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$offsets = array();
		foreach ( array( -172800, -86400, 0, 86400, 172800 ) as $delta ) {
			$offsets[] = $tz->getOffset( new DateTimeImmutable( '@' . ( $wall_ts + $delta ) ) );
		}
		$offsets = array_values( array_unique( $offsets ) );
		sort( $offsets );

		$valid = array();
		foreach ( $offsets as $offset ) {
			$candidate = $wall_ts - $offset;
			$back      = ( new DateTimeImmutable( '@' . $candidate ) )->setTimezone( $tz )->format( 'Y-m-d H:i' );
			if ( $back === $wall ) { $valid[ $candidate ] = true; }
		}
		$starts = array_keys( $valid );
		sort( $starts );

		if ( 1 === count( $starts ) ) { return array( 'kind' => 'normal', 'start' => $starts[0], 'adjustment' => null ); }
		if ( count( $starts ) > 1 ) {
			$pick = self::OVERLAP_LATER === $overlap ? $starts[ count( $starts ) - 1 ] : $starts[0];
			return array( 'kind' => 'overlap', 'start' => $pick, 'adjustment' => 'overlap_' . $overlap );
		}
		// No valid instant: the wall time falls in a forward jump. The smallest offset is the one in force before the gap.
		return array( 'kind' => 'gap', 'start' => $wall_ts - $offsets[0], 'adjustment' => 'gap_shifted_forward' );
	}
}
