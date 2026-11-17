<?php
/**
 * Location-scoped staff roles for the single Scheduling & Enquiries Manager plugin.
 *
 * Pure PHP, no WordPress calls. Default deny: a capability is granted only by a valid grant for the
 * same company, the same user and an in-scope location. Malformed grants grant nothing.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Delegated_Roles {

	const CAP_VIEW_SCHEDULE    = 'view_schedule';
	const CAP_EDIT_BOOKINGS    = 'edit_bookings';
	const CAP_APPROVE_BOOKINGS = 'approve_bookings';
	const CAP_MANAGE_STAFF     = 'manage_staff';
	const CAP_MANAGE_LOCATION  = 'manage_location';
	const CAP_VIEW_REPORTS     = 'view_reports';
	const CAP_EXPORT_DATA      = 'export_data';

	const ROLE_COMPANY_ADMIN  = 'company_admin';
	const ROLE_LOCATION_ADMIN = 'location_admin';
	const ROLE_STAFF          = 'staff';
	const ROLE_VIEWER         = 'viewer';

	const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

	/** @return string[] Every capability this model knows about. */
	public static function capabilities() {
		return array(
			self::CAP_VIEW_SCHEDULE, self::CAP_EDIT_BOOKINGS, self::CAP_APPROVE_BOOKINGS,
			self::CAP_MANAGE_STAFF, self::CAP_MANAGE_LOCATION, self::CAP_VIEW_REPORTS, self::CAP_EXPORT_DATA,
		);
	}

	/** @return array<string,string[]> Role => capabilities. Least privilege: staff cannot see reports. */
	public static function role_capabilities() {
		return array(
			self::ROLE_COMPANY_ADMIN  => self::capabilities(),
			self::ROLE_LOCATION_ADMIN => array(
				self::CAP_VIEW_SCHEDULE, self::CAP_EDIT_BOOKINGS, self::CAP_APPROVE_BOOKINGS,
				self::CAP_MANAGE_STAFF, self::CAP_MANAGE_LOCATION, self::CAP_VIEW_REPORTS, self::CAP_EXPORT_DATA,
			),
			self::ROLE_STAFF          => array( self::CAP_VIEW_SCHEDULE, self::CAP_EDIT_BOOKINGS ),
			self::ROLE_VIEWER         => array( self::CAP_VIEW_SCHEDULE, self::CAP_VIEW_REPORTS ),
		);
	}

	/**
	 * Validates and normalises one grant.
	 *
	 * Grant keys: company_id, user_id, role, location_ids (list), staff_id (required for staff).
	 * company_admin carries no location list because it covers every location in the company.
	 *
	 * @throws InvalidArgumentException When the grant is malformed.
	 */
	public static function validate( $grant ) {
		if ( ! is_array( $grant ) ) { throw new InvalidArgumentException( 'grant_not_array' ); }
		$allowed = array( 'company_id', 'user_id', 'role', 'location_ids', 'staff_id' );
		foreach ( array_keys( $grant ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) { throw new InvalidArgumentException( 'unknown_grant_key' ); }
		}
		$company = $grant['company_id'] ?? null;
		if ( ! is_string( $company ) || ! preg_match( self::ID_PATTERN, $company ) ) { throw new InvalidArgumentException( 'invalid_company_id' ); }
		$user = $grant['user_id'] ?? null;
		if ( ! is_int( $user ) || $user < 1 ) { throw new InvalidArgumentException( 'invalid_user_id' ); }
		$role = $grant['role'] ?? null;
		$roles = self::role_capabilities();
		if ( ! is_string( $role ) || ! isset( $roles[ $role ] ) ) { throw new InvalidArgumentException( 'unknown_role' ); }

		$locations = $grant['location_ids'] ?? array();
		if ( ! is_array( $locations ) || array_values( $locations ) !== $locations ) { throw new InvalidArgumentException( 'invalid_location_list' ); }
		$seen = array();
		foreach ( $locations as $location ) {
			if ( ! is_string( $location ) || ! preg_match( self::ID_PATTERN, $location ) || isset( $seen[ $location ] ) ) {
				throw new InvalidArgumentException( 'invalid_location_id' );
			}
			$seen[ $location ] = true;
		}
		if ( self::ROLE_COMPANY_ADMIN === $role ) {
			if ( ! empty( $locations ) ) { throw new InvalidArgumentException( 'company_admin_takes_no_locations' ); }
		} elseif ( empty( $locations ) ) {
			throw new InvalidArgumentException( 'location_scope_required' );
		}

		$staff = $grant['staff_id'] ?? null;
		if ( self::ROLE_STAFF === $role ) {
			if ( ! is_string( $staff ) || ! preg_match( self::ID_PATTERN, $staff ) ) { throw new InvalidArgumentException( 'staff_id_required' ); }
		} elseif ( null !== $staff ) {
			throw new InvalidArgumentException( 'staff_id_only_for_staff_role' );
		}

		return array(
			'company_id'   => $company,
			'user_id'      => $user,
			'role'         => $role,
			'location_ids' => array_values( $locations ),
			'staff_id'     => $staff,
		);
	}

	/**
	 * True only when a valid grant allows the capability in this company and location.
	 * Invalid grants are skipped, so corrupted data denies access instead of widening it.
	 *
	 * @param array  $grants      Stored grants.
	 * @param string $company_id  Company the request is scoped to.
	 * @param int    $user_id     Acting user.
	 * @param string $location_id Location the request targets.
	 * @param string $capability  One of capabilities().
	 * @param array  $context     Optional: booking_staff_id, for own-booking checks on the staff role.
	 */
	public static function can( $grants, $company_id, $user_id, $location_id, $capability, $context = array() ) {
		if ( ! is_array( $grants ) || ! in_array( $capability, self::capabilities(), true ) ) { return false; }
		if ( ! is_string( $company_id ) || ! is_string( $location_id ) || ! is_int( $user_id ) ) { return false; }
		$context = is_array( $context ) ? $context : array();
		foreach ( $grants as $raw ) {
			try {
				$grant = self::validate( $raw );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
			if ( $grant['company_id'] !== $company_id || $grant['user_id'] !== $user_id ) { continue; }
			if ( ! in_array( $capability, self::role_capabilities()[ $grant['role'] ], true ) ) { continue; }
			if ( self::ROLE_COMPANY_ADMIN !== $grant['role'] && ! in_array( $location_id, $grant['location_ids'], true ) ) { continue; }
			if ( self::ROLE_STAFF === $grant['role'] && self::CAP_EDIT_BOOKINGS === $capability ) {
				if ( ! isset( $context['booking_staff_id'] ) || $context['booking_staff_id'] !== $grant['staff_id'] ) { continue; }
			}
			return true;
		}
		return false;
	}

	/**
	 * Removes one location from a user's grant in a company. Removing the last location removes the grant.
	 * Company admin grants are removed entirely. Used when a staff member leaves a site or changes role.
	 */
	public static function revoke( $grants, $company_id, $user_id, $location_id ) {
		$out = array();
		foreach ( (array) $grants as $raw ) {
			try {
				$grant = self::validate( $raw );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
			$matches = $grant['company_id'] === $company_id && $grant['user_id'] === $user_id;
			if ( ! $matches ) { $out[] = $grant; continue; }
			if ( self::ROLE_COMPANY_ADMIN === $grant['role'] ) { continue; }
			$grant['location_ids'] = array_values( array_diff( $grant['location_ids'], array( $location_id ) ) );
			if ( ! empty( $grant['location_ids'] ) ) { $out[] = $grant; }
		}
		return $out;
	}
}
