<?php
/**
 * Saved customer segments for the local Free plugin: deterministic, company-scoped, read-only.
 *
 * A segment is a saved view: a bounded set of conditions over local customer fields. Marketing segments
 * exclude customers without marketing consent and report how many were excluded, so the count is honest.
 * Pure PHP, no WordPress calls.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-booking-reports.php';

final class TZFD_Customer_Segments {

	const MAX_CUSTOMERS = 50000;
	const MAX_SEGMENTS  = 50;

	/**
	 * @param string $company_id Company scope; every customer and segment must match it.
	 * @param string $as_of      Y-m-d, the site-local date the days-since-last-booking are measured to.
	 * @param array  $segments   Saved segment definitions, see validate_segment().
	 * @param array  $customers  Customer records, see validate_customer().
	 * @return array Segment results with members and exclusion counts.
	 * @throws InvalidArgumentException On invalid input.
	 */
	public static function evaluate( $company_id, $as_of, $segments, $customers ) {
		if ( ! self::is_id( $company_id ) || ! is_array( $segments ) || ! is_array( $customers ) ) { throw new InvalidArgumentException( 'invalid_segment_inputs' ); }
		if ( count( $segments ) > self::MAX_SEGMENTS || count( $customers ) > self::MAX_CUSTOMERS ) { throw new InvalidArgumentException( 'too_many_items' ); }
		$today = self::date( $as_of );
		if ( null === $today ) { throw new InvalidArgumentException( 'invalid_as_of_date' ); }

		$seen = array();
		$clean_customers = array();
		foreach ( $customers as $raw ) {
			$c = self::validate_customer( $raw, $company_id, $today );
			if ( isset( $seen[ $c['id'] ] ) ) { throw new InvalidArgumentException( 'duplicate_customer' ); }
			$seen[ $c['id'] ] = true;
			$clean_customers[] = $c;
		}

		$seen_segments = array();
		$results = array();
		foreach ( $segments as $raw ) {
			$s = self::validate_segment( $raw );
			if ( isset( $seen_segments[ $s['id'] ] ) ) { throw new InvalidArgumentException( 'duplicate_segment' ); }
			$seen_segments[ $s['id'] ] = true;

			$members = array();
			$excluded_no_consent = 0;
			foreach ( $clean_customers as $c ) {
				if ( ! self::matches( $s, $c ) ) { continue; }
				if ( $s['requires_marketing_consent'] && ! $c['marketing_consent'] ) {
					$excluded_no_consent++;
					continue;
				}
				$members[] = $c['id'];
			}
			sort( $members, SORT_STRING );
			$results[] = array(
				'id'                   => $s['id'],
				'name'                 => $s['name'],
				'count'                => count( $members ),
				'member_ids'           => $members,
				'excluded_no_consent'  => $excluded_no_consent,
				'marketing'            => $s['requires_marketing_consent'],
			);
		}

		return array( 'company_id' => $company_id, 'as_of' => $today, 'segments' => $results, 'read_only' => true );
	}

	private static function matches( $segment, $customer ) {
		if ( $customer['booked_count'] < $segment['min_bookings'] ) { return false; }
		if ( null !== $segment['lead_source'] && $customer['lead_source'] !== $segment['lead_source'] ) { return false; }
		if ( null !== $segment['min_days_since_last'] || null !== $segment['max_days_since_last'] ) {
			if ( null === $customer['days_since_last'] ) { return false; }
			if ( null !== $segment['min_days_since_last'] && $customer['days_since_last'] < $segment['min_days_since_last'] ) { return false; }
			if ( null !== $segment['max_days_since_last'] && $customer['days_since_last'] > $segment['max_days_since_last'] ) { return false; }
		}
		return true;
	}

	/** @throws InvalidArgumentException */
	private static function validate_customer( $raw, $company_id, $today ) {
		if ( ! is_array( $raw ) ) { throw new InvalidArgumentException( 'customer_not_array' ); }
		$id = $raw['id'] ?? null;
		if ( ! self::is_id( $id ) ) { throw new InvalidArgumentException( 'invalid_customer_id' ); }
		if ( ( $raw['company_id'] ?? null ) !== $company_id ) { throw new InvalidArgumentException( 'cross_company_customer' ); }
		$booked = $raw['booked_count'] ?? null;
		$cancelled = $raw['cancelled_count'] ?? null;
		if ( ! is_int( $booked ) || $booked < 0 || ! is_int( $cancelled ) || $cancelled < 0 || $cancelled > $booked ) {
			throw new InvalidArgumentException( 'invalid_booking_counts' );
		}
		$source = $raw['lead_source'] ?? 'unknown';
		if ( ! in_array( $source, TZFD_Booking_Reports::LEAD_SOURCES, true ) ) { throw new InvalidArgumentException( 'unknown_lead_source' ); }
		$last = $raw['last_booked_date'] ?? null;
		$days = null;
		if ( null !== $last ) {
			$last_date = self::date( $last );
			if ( null === $last_date || strcmp( $last_date, $today ) > 0 ) { throw new InvalidArgumentException( 'invalid_last_booked_date' ); }
			$utc  = new DateTimeZone( 'UTC' );
			$days = (int) ( new DateTimeImmutable( $last_date, $utc ) )->diff( new DateTimeImmutable( $today, $utc ) )->format( '%a' );
		}
		$consent = $raw['marketing_consent'] ?? false;
		if ( ! is_bool( $consent ) ) { throw new InvalidArgumentException( 'invalid_consent_flag' ); }
		return array( 'id' => $id, 'booked_count' => $booked, 'lead_source' => $source, 'days_since_last' => $days, 'marketing_consent' => $consent );
	}

	/** @throws InvalidArgumentException */
	private static function validate_segment( $raw ) {
		if ( ! is_array( $raw ) ) { throw new InvalidArgumentException( 'segment_not_array' ); }
		$allowed = array( 'id', 'name', 'min_bookings', 'min_days_since_last', 'max_days_since_last', 'lead_source', 'requires_marketing_consent' );
		foreach ( array_keys( $raw ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) { throw new InvalidArgumentException( 'unknown_segment_key' ); }
		}
		$id = $raw['id'] ?? null;
		if ( ! self::is_id( $id ) ) { throw new InvalidArgumentException( 'invalid_segment_id' ); }
		$name = $raw['name'] ?? null;
		if ( ! is_string( $name ) || '' === trim( $name ) || strlen( $name ) > 80 ) { throw new InvalidArgumentException( 'invalid_segment_name' ); }
		$min = $raw['min_bookings'] ?? 0;
		if ( ! is_int( $min ) || $min < 0 ) { throw new InvalidArgumentException( 'invalid_min_bookings' ); }
		$min_days = $raw['min_days_since_last'] ?? null;
		$max_days = $raw['max_days_since_last'] ?? null;
		foreach ( array( $min_days, $max_days ) as $bound ) {
			if ( null !== $bound && ( ! is_int( $bound ) || $bound < 0 ) ) { throw new InvalidArgumentException( 'invalid_day_bound' ); }
		}
		if ( null !== $min_days && null !== $max_days && $min_days > $max_days ) { throw new InvalidArgumentException( 'inverted_day_bounds' ); }
		$source = $raw['lead_source'] ?? null;
		if ( null !== $source && ! in_array( $source, TZFD_Booking_Reports::LEAD_SOURCES, true ) ) { throw new InvalidArgumentException( 'unknown_lead_source' ); }
		$consent = $raw['requires_marketing_consent'] ?? false;
		if ( ! is_bool( $consent ) ) { throw new InvalidArgumentException( 'invalid_consent_flag' ); }
		return array(
			'id' => $id, 'name' => $name, 'min_bookings' => $min, 'min_days_since_last' => $min_days,
			'max_days_since_last' => $max_days, 'lead_source' => $source, 'requires_marketing_consent' => $consent,
		);
	}

	private static function date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $p ) || ! checkdate( (int) $p[2], (int) $p[3], (int) $p[1] ) ) {
			return null;
		}
		return $value;
	}

	private static function is_id( $value ) {
		return is_string( $value ) && (bool) preg_match( TZFD_Delegated_Roles::ID_PATTERN, $value );
	}
}
