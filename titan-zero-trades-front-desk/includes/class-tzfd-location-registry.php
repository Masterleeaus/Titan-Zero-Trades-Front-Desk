<?php
/**
 * Local location registry for one company (issue #362).
 *
 * Pure PHP, no WordPress calls. A location is a registered, named site that staff grants refer to, so
 * admins pick a registered location instead of typing a free-text id. Rows are validated on every read;
 * a malformed stored row is skipped and counted, never silently trusted.
 *
 * Row keys: id (ID_PATTERN), company_id (ID_PATTERN), name (1 to 120 printable characters), active (bool).
 * Deactivating a location stops new grants to it. It does not remove grants or touch bookings.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';

final class TZFD_Location_Registry {

	const NAME_MAX = 120;
	const LIMIT    = 500;

	/**
	 * Validates and normalises one location row.
	 *
	 * @throws InvalidArgumentException When the row is malformed.
	 */
	public static function validate( $row ) {
		if ( ! is_array( $row ) ) { throw new InvalidArgumentException( 'location_not_array' ); }
		$allowed = array( 'id', 'company_id', 'name', 'active' );
		foreach ( array_keys( $row ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) { throw new InvalidArgumentException( 'unknown_location_key' ); }
		}
		$id = $row['id'] ?? null;
		if ( ! self::is_id( $id ) ) { throw new InvalidArgumentException( 'invalid_location_id' ); }
		$company = $row['company_id'] ?? null;
		if ( ! self::is_id( $company ) ) { throw new InvalidArgumentException( 'invalid_company_id' ); }
		$name = $row['name'] ?? null;
		if ( ! is_string( $name ) ) { throw new InvalidArgumentException( 'invalid_location_name' ); }
		$name = trim( $name );
		if ( '' === $name || strlen( $name ) > self::NAME_MAX || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			throw new InvalidArgumentException( 'invalid_location_name' );
		}
		$active = $row['active'] ?? true;
		if ( ! is_bool( $active ) ) { throw new InvalidArgumentException( 'invalid_active_flag' ); }
		return array( 'id' => $id, 'company_id' => $company, 'name' => $name, 'active' => $active );
	}

	/**
	 * Validates a stored list for one company. Malformed rows and rows from another company are skipped and counted.
	 *
	 * @return array rows (validated, stored order, first occurrence of each id kept), skipped (int).
	 */
	public static function load( $stored, $company_id ) {
		$rows    = array();
		$seen    = array();
		$skipped = 0;
		foreach ( is_array( $stored ) ? $stored : array() as $raw ) {
			try {
				$row = self::validate( $raw );
			} catch ( InvalidArgumentException $e ) {
				$skipped++;
				continue;
			}
			if ( $row['company_id'] !== $company_id || isset( $seen[ $row['id'] ] ) ) {
				$skipped++;
				continue;
			}
			$seen[ $row['id'] ] = true;
			$rows[]             = $row;
		}
		return array( 'rows' => $rows, 'skipped' => $skipped );
	}

	/**
	 * Adds a location. Refuses a duplicate id, a foreign company, a malformed row, or a registry at its limit.
	 *
	 * @return array rows (new list), or throws.
	 * @throws InvalidArgumentException
	 */
	public static function add( $rows, $company_id, $row ) {
		$new = self::validate( $row );
		if ( $new['company_id'] !== $company_id ) { throw new InvalidArgumentException( 'company_mismatch' ); }
		if ( count( $rows ) >= self::LIMIT ) { throw new InvalidArgumentException( 'registry_full' ); }
		foreach ( $rows as $existing ) {
			if ( $existing['id'] === $new['id'] ) { throw new InvalidArgumentException( 'duplicate_location_id' ); }
		}
		$rows[] = $new;
		return $rows;
	}

	/**
	 * Renames or sets the active flag of one registered location. Other fields are unchanged.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function update( $rows, $company_id, $id, $name, $active ) {
		if ( ! self::is_id( $id ) ) { throw new InvalidArgumentException( 'invalid_location_id' ); }
		$found = false;
		$out   = array();
		foreach ( $rows as $row ) {
			if ( $row['id'] === $id ) {
				$candidate = self::validate( array( 'id' => $id, 'company_id' => $company_id, 'name' => $name, 'active' => $active ) );
				$row       = $candidate;
				$found     = true;
			}
			$out[] = $row;
		}
		if ( ! $found ) { throw new InvalidArgumentException( 'unknown_location_id' ); }
		return $out;
	}

	/** Ids of registered locations that accept new grants. */
	public static function active_ids( $rows ) {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['active'] ) ) { $ids[] = $row['id']; }
		}
		return $ids;
	}

	/** Ids from $ids that are not registered (any state). Used to flag legacy free-text grants. */
	public static function unregistered( $rows, $ids ) {
		$known = array();
		foreach ( $rows as $row ) { $known[ $row['id'] ] = true; }
		$out = array();
		foreach ( array_unique( (array) $ids ) as $id ) {
			if ( ! isset( $known[ $id ] ) ) { $out[] = (string) $id; }
		}
		return $out;
	}

	/** Finds one registered location row, or null. */
	public static function find( $rows, $id ) {
		foreach ( $rows as $row ) {
			if ( $row['id'] === $id ) { return $row; }
		}
		return null;
	}

	private static function is_id( $value ) {
		return is_string( $value ) && (bool) preg_match( TZFD_Delegated_Roles::ID_PATTERN, $value );
	}
}
