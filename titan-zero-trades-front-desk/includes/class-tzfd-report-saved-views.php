<?php
/**
 * Saved report views for the local Free plugin (issue #365): named, validated filter sets over
 * TZFD_Booking_Reports, scoped to one company and to the locations the viewer may see.
 *
 * A view stores only filters (date range, location ids, lead sources). It never stores data, so a stored
 * view cannot leak figures. Running a view applies the viewer's location scope: a view that names a location
 * the viewer may not see is refused, not silently narrowed. Pure PHP, no WordPress calls.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-booking-reports.php';

final class TZFD_Report_Saved_Views {

	const MAX_VIEWS = 50;

	/**
	 * Validate one saved view definition and return its normalised form.
	 *
	 * @param string $company_id Company the view must belong to.
	 * @param array  $raw        id, company_id, name, from, to, location_ids (optional), lead_sources (optional).
	 * @return array Normalised view.
	 * @throws InvalidArgumentException On unknown keys, bad ids, bad dates, bad filters or cross-company views.
	 */
	public static function validate_view( $company_id, $raw ) {
		if ( ! self::is_id( $company_id ) || ! is_array( $raw ) ) { throw new InvalidArgumentException( 'invalid_view_inputs' ); }
		$allowed = array( 'id', 'company_id', 'name', 'from', 'to', 'location_ids', 'lead_sources' );
		foreach ( array_keys( $raw ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) { throw new InvalidArgumentException( 'unknown_view_key' ); }
		}
		if ( ( $raw['company_id'] ?? null ) !== $company_id ) { throw new InvalidArgumentException( 'cross_company_view' ); }
		$id = $raw['id'] ?? null;
		if ( ! self::is_id( $id ) ) { throw new InvalidArgumentException( 'invalid_view_id' ); }
		$name = $raw['name'] ?? null;
		if ( ! is_string( $name ) || '' === trim( $name ) || strlen( $name ) > 80 ) { throw new InvalidArgumentException( 'invalid_view_name' ); }
		$from = self::date( $raw['from'] ?? null );
		$to   = self::date( $raw['to'] ?? null );
		if ( null === $from || null === $to || strcmp( $from, $to ) > 0 ) { throw new InvalidArgumentException( 'invalid_date_range' ); }
		$locations = self::list_or_null( $raw['location_ids'] ?? null );
		if ( null !== $locations ) {
			foreach ( $locations as $location ) {
				if ( ! self::is_id( $location ) ) { throw new InvalidArgumentException( 'invalid_location_id' ); }
			}
		}
		$sources = self::list_or_null( $raw['lead_sources'] ?? null );
		if ( null !== $sources && array_diff( $sources, TZFD_Booking_Reports::LEAD_SOURCES ) ) {
			throw new InvalidArgumentException( 'unknown_lead_source_filter' );
		}
		return array(
			'id'           => $id,
			'company_id'   => $company_id,
			'name'         => $name,
			'from'         => $from,
			'to'           => $to,
			'location_ids' => $locations,
			'lead_sources' => $sources,
		);
	}

	/**
	 * Check a set of saved views for one company: count limit, unique ids and names.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function validate_set( $company_id, $views ) {
		if ( ! is_array( $views ) || count( $views ) > self::MAX_VIEWS ) { throw new InvalidArgumentException( 'too_many_views' ); }
		$ids = array();
		$names = array();
		$clean = array();
		foreach ( $views as $raw ) {
			$v = self::validate_view( $company_id, $raw );
			if ( isset( $ids[ $v['id'] ] ) ) { throw new InvalidArgumentException( 'duplicate_view_id' ); }
			$key = strtolower( $v['name'] );
			if ( isset( $names[ $key ] ) ) { throw new InvalidArgumentException( 'duplicate_view_name' ); }
			$ids[ $v['id'] ] = true;
			$names[ $key ]   = true;
			$clean[]         = $v;
		}
		return $clean;
	}

	/**
	 * Run one saved view for a viewer. The viewer's allowed locations bound the report: a view that names a
	 * location outside that list is refused. A view with no location filter is limited to the allowed list.
	 *
	 * @param array $view     A view from validate_view().
	 * @param array $viewer   company_id and allowed_location_ids (non-empty list).
	 * @param array $records  Enquiry records for the company, see TZFD_Booking_Reports.
	 * @param array $capacity location_id => capacity minutes.
	 * @return array Result of TZFD_Booking_Reports::summarise().
	 * @throws InvalidArgumentException On a company mismatch, an empty viewer scope, or a location outside the scope.
	 */
	public static function run( $view, $viewer, $records, $capacity = array() ) {
		if ( ! is_array( $view ) || ! is_array( $viewer ) ) { throw new InvalidArgumentException( 'invalid_view_inputs' ); }
		if ( ( $viewer['company_id'] ?? null ) !== ( $view['company_id'] ?? null ) ) { throw new InvalidArgumentException( 'cross_company_view' ); }
		$allowed = self::list_or_null( $viewer['allowed_location_ids'] ?? null );
		if ( null === $allowed || empty( $allowed ) ) { throw new InvalidArgumentException( 'no_location_scope' ); }
		foreach ( $allowed as $location ) {
			if ( ! self::is_id( $location ) ) { throw new InvalidArgumentException( 'invalid_location_id' ); }
		}

		if ( null === $view['location_ids'] ) {
			$locations = $allowed;
		} else {
			if ( array_diff( $view['location_ids'], $allowed ) ) { throw new InvalidArgumentException( 'location_outside_scope' ); }
			$locations = $view['location_ids'];
		}

		$request = array( 'from' => $view['from'], 'to' => $view['to'], 'location_ids' => $locations );
		if ( null !== $view['lead_sources'] ) { $request['lead_sources'] = $view['lead_sources']; }
		return TZFD_Booking_Reports::summarise( $view['company_id'], $request, $records, $capacity );
	}

	private static function list_or_null( $value ) {
		if ( null === $value ) { return null; }
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'invalid_filter_list' ); }
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) { throw new InvalidArgumentException( 'invalid_filter_value' ); }
			$out[] = $item;
		}
		return array_values( array_unique( $out ) );
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
