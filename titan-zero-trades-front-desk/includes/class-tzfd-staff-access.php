<?php
/**
 * Stored, location-scoped staff access for one company (issue #11).
 *
 * Grants are checked with TZFD_Delegated_Roles, which denies by default and skips any grant that does not
 * validate. This class adds what the pure model does not have: where grants are kept, who may change them,
 * and an operation log of every change. Each company has its own options, so nothing leaks across companies.
 *
 * Rules:
 *   - Only a site administrator may grant company_admin. Anyone else may grant only the roles below company level.
 *   - A location-level actor may change grants only for locations where that actor holds manage_staff.
 *   - A grant for the same user, role and staff id is merged, so adding a second location never drops the first.
 *   - Every change is written to the operation log with actor, action, user, role and locations. No values
 *     beyond ids are logged.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';

final class TZFD_Staff_Access {

	const GRANTS_PREFIX = 'tzfd_staff_access_g_';
	const LOG_PREFIX    = 'tzfd_staff_access_l_';
	const LOG_CAP       = 200;

	/** Validated grants for a company, and the number of stored entries that were skipped as malformed. */
	public static function grants( $company ) {
		$company = self::company( $company );
		$raw     = get_option( self::GRANTS_PREFIX . $company, array() );
		$valid   = array();
		$skipped = 0;
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			try {
				$valid[] = TZFD_Delegated_Roles::validate( $item );
			} catch ( InvalidArgumentException $e ) {
				$skipped++;
			}
		}
		return array( 'grants' => $valid, 'skipped' => $skipped );
	}

	/** True only when a stored, valid grant allows the capability at this location. */
	public static function allowed( $company, $user_id, $location_id, $capability, $context = array() ) {
		$company = self::company( $company );
		return TZFD_Delegated_Roles::can( self::grants( $company )['grants'], $company, (int) $user_id, (string) $location_id, $capability, $context );
	}

	/**
	 * Adds or merges a grant. $actor_is_admin is true for a site administrator.
	 *
	 * @return array ok (bool), code (string).
	 */
	public static function assign( $company, $actor_id, $actor_is_admin, $grant, $now ) {
		$company = self::company( $company );
		try {
			$new = TZFD_Delegated_Roles::validate( $grant );
		} catch ( InvalidArgumentException $e ) {
			return self::result( false, 'invalid_grant' );
		}
		if ( $new['company_id'] !== $company ) { return self::result( false, 'company_mismatch' ); }
		if ( TZFD_Delegated_Roles::ROLE_COMPANY_ADMIN === $new['role'] && ! $actor_is_admin ) { return self::result( false, 'forbidden' ); }
		if ( ! $actor_is_admin && ! self::may_manage( $company, $actor_id, $new['location_ids'] ) ) { return self::result( false, 'forbidden' ); }

		$stored = self::grants( $company )['grants'];
		$merged = false;
		foreach ( $stored as $i => $existing ) {
			if ( $existing['user_id'] === $new['user_id'] && $existing['role'] === $new['role'] && $existing['staff_id'] === $new['staff_id'] ) {
				$stored[ $i ]['location_ids'] = array_values( array_unique( array_merge( $existing['location_ids'], $new['location_ids'] ) ) );
				$merged = true;
				break;
			}
		}
		if ( ! $merged ) { $stored[] = $new; }
		update_option( self::GRANTS_PREFIX . $company, $stored, false );
		self::log_change( $company, $actor_id, 'assign', $new, $now );
		return self::result( true, $merged ? 'merged' : 'added' );
	}

	/**
	 * Removes one location from a user's grants. Removing the last location removes the grant. Company admin grants
	 * can be removed only by a site administrator.
	 *
	 * @return array ok (bool), code (string).
	 */
	public static function revoke( $company, $actor_id, $actor_is_admin, $user_id, $location_id, $now ) {
		$company = self::company( $company );
		if ( ! $actor_is_admin && ! self::may_manage( $company, $actor_id, array( (string) $location_id ) ) ) { return self::result( false, 'forbidden' ); }
		$before = self::grants( $company )['grants'];
		$after  = TZFD_Delegated_Roles::revoke( $before, $company, (int) $user_id, (string) $location_id );
		if ( ! $actor_is_admin ) {
			// A location actor may not remove a company-level grant.
			foreach ( $before as $g ) {
				if ( (int) $g['user_id'] === (int) $user_id && TZFD_Delegated_Roles::ROLE_COMPANY_ADMIN === $g['role'] ) { return self::result( false, 'forbidden' ); }
			}
		}
		if ( count( $after ) === count( $before ) && self::same( $before, $after ) ) { return self::result( false, 'nothing_to_revoke' ); }
		update_option( self::GRANTS_PREFIX . $company, $after, false );
		self::log_change( $company, $actor_id, 'revoke', array( 'user_id' => (int) $user_id, 'location_ids' => array( (string) $location_id ) ), $now );
		return self::result( true, 'revoked' );
	}

	/** Operation log for a company, newest first. Read-only. */
	public static function log( $company ) {
		$company = self::company( $company );
		$rows    = get_option( self::LOG_PREFIX . $company, array() );
		return array_reverse( is_array( $rows ) ? array_values( $rows ) : array() );
	}

	/** True when the actor holds manage_staff at every location in the list. */
	private static function may_manage( $company, $actor_id, $locations ) {
		if ( empty( $locations ) ) { return false; }
		$grants = self::grants( $company )['grants'];
		foreach ( $locations as $location ) {
			if ( ! TZFD_Delegated_Roles::can( $grants, $company, (int) $actor_id, (string) $location, TZFD_Delegated_Roles::CAP_MANAGE_STAFF ) ) { return false; }
		}
		return true;
	}

	private static function log_change( $company, $actor_id, $action, $grant, $now ) {
		$rows = get_option( self::LOG_PREFIX . $company, array() );
		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		$rows[] = array(
			'at'           => (int) $now,
			'actor_id'     => (int) $actor_id,
			'action'       => $action,
			'user_id'      => (int) $grant['user_id'],
			'role'         => isset( $grant['role'] ) ? (string) $grant['role'] : '',
			'location_ids' => array_values( (array) $grant['location_ids'] ),
		);
		if ( count( $rows ) > self::LOG_CAP ) { $rows = array_slice( $rows, -self::LOG_CAP ); }
		update_option( self::LOG_PREFIX . $company, $rows, false );
	}

	private static function same( $a, $b ) {
		return count( $a ) === count( $b ) && array_map( 'serialize', $a ) === array_map( 'serialize', $b );
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
