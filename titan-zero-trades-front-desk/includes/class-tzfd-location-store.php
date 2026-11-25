<?php
/**
 * Stored location registry for one company, with an operation log of every change (issue #362).
 *
 * Each company has its own options, so nothing leaks across companies. The pure rules live in
 * TZFD_Location_Registry. This class only reads and writes them and records ids, field names and actor ids.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-location-registry.php';

final class TZFD_Location_Store {

	const LOCATIONS_PREFIX = 'tzfd_locations_';
	const LOG_PREFIX       = 'tzfd_location_log_';
	const LOG_CAP          = 200;

	/** Validated locations for a company, and the number of stored entries skipped as malformed. */
	public static function locations( $company ) {
		$company = self::company( $company );
		return TZFD_Location_Registry::stored( get_option( self::LOCATIONS_PREFIX . $company, array() ) );
	}

	/** True only when every id is a registered, active location of this company. */
	public static function has_active( $company, $ids ) {
		if ( ! is_array( $ids ) || empty( $ids ) ) { return false; }
		$active = TZFD_Location_Registry::active_ids( self::locations( $company )['locations'] );
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! in_array( $id, $active, true ) ) { return false; }
		}
		return true;
	}

	/** @return array ok (bool), code (string). */
	public static function add( $company, $actor_id, $location, $now ) {
		$company = self::company( $company );
		try {
			$list = TZFD_Location_Registry::add( self::locations( $company )['locations'], $location );
		} catch ( InvalidArgumentException $e ) {
			return self::result( false, $e->getMessage() );
		}
		update_option( self::LOCATIONS_PREFIX . $company, $list, false );
		self::log_change( $company, $actor_id, 'create', $location['id'], array( 'id' ), $now );
		return self::result( true, 'created' );
	}

	/**
	 * Changes name, timezone or status. The caller has already checked permissions and archive safety.
	 *
	 * @return array ok (bool), code (string).
	 */
	public static function update( $company, $actor_id, $location_id, $changes, $now ) {
		$company = self::company( $company );
		try {
			$list = TZFD_Location_Registry::update( self::locations( $company )['locations'], (string) $location_id, $changes );
		} catch ( InvalidArgumentException $e ) {
			return self::result( false, $e->getMessage() );
		}
		$changed = array_keys( $changes );
		$action  = in_array( 'status', $changed, true ) ? ( 'archived' === $changes['status'] ? 'archive' : 'restore' ) : 'update';
		update_option( self::LOCATIONS_PREFIX . $company, $list, false );
		self::log_change( $company, $actor_id, $action, (string) $location_id, $changed, $now );
		return self::result( true, $action );
	}

	/** Operation log for a company, newest first. Read-only. */
	public static function log( $company ) {
		$company = self::company( $company );
		$rows    = get_option( self::LOG_PREFIX . $company, array() );
		return array_reverse( is_array( $rows ) ? array_values( $rows ) : array() );
	}

	private static function log_change( $company, $actor_id, $action, $location_id, $fields, $now ) {
		$rows   = get_option( self::LOG_PREFIX . $company, array() );
		$rows   = is_array( $rows ) ? array_values( $rows ) : array();
		$rows[] = array(
			'at'          => (int) $now,
			'actor_id'    => (int) $actor_id,
			'action'      => (string) $action,
			'location_id' => (string) $location_id,
			'fields'      => array_values( array_map( 'strval', (array) $fields ) ),
		);
		if ( count( $rows ) > self::LOG_CAP ) { $rows = array_slice( $rows, -self::LOG_CAP ); }
		update_option( self::LOG_PREFIX . $company, $rows, false );
	}

	private static function company( $company ) {
		$company = (string) $company;
		if ( ! preg_match( TZFD_Delegated_Roles::ID_PATTERN, $company ) ) { throw new InvalidArgumentException( 'invalid_company_id' ); }
		return $company;
	}

	private static function result( $ok, $code ) {
		return array( 'ok' => (bool) $ok, 'code' => (string) $code );
	}
}
