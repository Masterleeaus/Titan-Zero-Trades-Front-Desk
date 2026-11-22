<?php
/**
 * Local location registry for the single Scheduling & Enquiries Manager plugin (issue #362).
 *
 * Pure PHP, no WordPress calls. A location exists only once it is registered here, so staff grants can no
 * longer point at free-text ids. Ids are fixed at creation. Locations are archived, never deleted, so that
 * booking and grant history keep their reference. Malformed records are rejected, never silently repaired.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';

final class TZFD_Location_Registry {

	const STATUS_ACTIVE   = 'active';
	const STATUS_ARCHIVED = 'archived';
	const MAX_LOCATIONS   = 200;
	const MAX_NAME_BYTES  = 120;

	/** Fields an update may change. The id is fixed once the location exists. */
	const MUTABLE_FIELDS = array( 'name', 'timezone', 'status' );

	/** @return array Normalised location. @throws InvalidArgumentException */
	public static function validate( $location ) {
		if ( ! is_array( $location ) ) { throw new InvalidArgumentException( 'location_not_array' ); }
		$allowed = array( 'id', 'name', 'timezone', 'status' );
		foreach ( array_keys( $location ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) { throw new InvalidArgumentException( 'unknown_location_key' ); }
		}

		$id = $location['id'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( TZFD_Delegated_Roles::ID_PATTERN, $id ) ) { throw new InvalidArgumentException( 'invalid_location_id' ); }

		$name = $location['name'] ?? null;
		if ( ! is_string( $name ) ) { throw new InvalidArgumentException( 'invalid_location_name' ); }
		$name = trim( $name );
		if ( '' === $name || strlen( $name ) > self::MAX_NAME_BYTES || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			throw new InvalidArgumentException( 'invalid_location_name' );
		}

		$tz = $location['timezone'] ?? null;
		if ( ! is_string( $tz ) || ! preg_match( '/^[A-Za-z]+(?:[\/_+-][A-Za-z0-9_+-]+)*$/', $tz ) ) { throw new InvalidArgumentException( 'invalid_location_timezone' ); }
		try {
			new DateTimeZone( $tz );
		} catch ( Throwable $e ) {
			throw new InvalidArgumentException( 'invalid_location_timezone' );
		}

		$status = $location['status'] ?? self::STATUS_ACTIVE;
		if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_ARCHIVED ), true ) ) { throw new InvalidArgumentException( 'unknown_location_status' ); }

		return array(
			'id'       => $id,
			'name'     => $name,
			'timezone' => $tz,
			'status'   => $status,
		);
	}

	/**
	 * Validated stored list. A malformed or duplicate record is skipped and counted, so it grants nothing.
	 *
	 * @return array locations (list), skipped (int).
	 */
	public static function stored( $raw ) {
		$valid   = array();
		$seen    = array();
		$skipped = 0;
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			try {
				$location = self::validate( $item );
			} catch ( InvalidArgumentException $e ) {
				$skipped++;
				continue;
			}
			if ( isset( $seen[ $location['id'] ] ) ) {
				$skipped++;
				continue;
			}
			$seen[ $location['id'] ] = true;
			$valid[]                 = $location;
		}
		return array( 'locations' => $valid, 'skipped' => $skipped );
	}

	/** Adds a new active location. @throws InvalidArgumentException */
	public static function add( $list, $location ) {
		$new = self::validate( $location );
		if ( self::STATUS_ACTIVE !== $new['status'] ) { throw new InvalidArgumentException( 'new_location_must_be_active' ); }
		if ( count( $list ) >= self::MAX_LOCATIONS ) { throw new InvalidArgumentException( 'location_limit_reached' ); }
		if ( null !== self::find( $list, $new['id'] ) ) { throw new InvalidArgumentException( 'duplicate_location_id' ); }
		$list[] = $new;
		return $list;
	}

	/**
	 * Changes name, timezone or status of one location. Any other key, including id, throws.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function update( $list, $id, $changes ) {
		if ( ! is_array( $changes ) ) { throw new InvalidArgumentException( 'invalid_changes' ); }
		foreach ( array_keys( $changes ) as $key ) {
			if ( ! in_array( $key, self::MUTABLE_FIELDS, true ) ) { throw new InvalidArgumentException( 'immutable_location_field' ); }
		}
		$out   = array();
		$found = false;
		foreach ( $list as $item ) {
			if ( $item['id'] !== $id ) {
				$out[] = $item;
				continue;
			}
			$found = true;
			$out[] = self::validate( array_merge( $item, $changes ) );
		}
		if ( ! $found ) { throw new InvalidArgumentException( 'unknown_location' ); }
		return $out;
	}

	/** @return array|null The location with this id, or null. */
	public static function find( $list, $id ) {
		foreach ( $list as $item ) {
			if ( $item['id'] === $id ) { return $item; }
		}
		return null;
	}

	/** True only for a registered, active location. */
	public static function is_active( $list, $id ) {
		$item = self::find( $list, $id );
		return null !== $item && self::STATUS_ACTIVE === $item['status'];
	}

	/** @return string[] Ids of active locations, in stored order. */
	public static function active_ids( $list ) {
		$ids = array();
		foreach ( $list as $item ) {
			if ( self::STATUS_ACTIVE === $item['status'] ) { $ids[] = $item['id']; }
		}
		return $ids;
	}
}
