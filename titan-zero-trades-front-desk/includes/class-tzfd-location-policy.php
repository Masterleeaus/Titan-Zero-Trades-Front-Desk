<?php
/**
 * Custom approval and staff-assignment policies per location, evaluated locally in the Free plugin.
 *
 * Pure PHP, no WordPress calls. Rules are stored by the admin screen and evaluated read-only here.
 * Combination: any matching require_approval rule forces approval (union). Matching restrict_staff rules
 * intersect their staff lists (intersection). A restriction that leaves no staff blocks every assignment.
 * Malformed or cross-company rules throw; they never silently drop a restriction.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';

final class TZFD_Location_Policy {

	const ACTION_REQUIRE_APPROVAL = 'require_approval';
	const ACTION_RESTRICT_STAFF   = 'restrict_staff';
	const ANY                     = '*';

	/** @return array Normalised rule. @throws InvalidArgumentException */
	public static function validate_rule( $rule ) {
		if ( ! is_array( $rule ) ) { throw new InvalidArgumentException( 'rule_not_array' ); }
		$keys = array( 'id', 'company_id', 'location_id', 'services', 'min_amount_minor', 'action', 'staff_ids', 'enabled' );
		foreach ( array_keys( $rule ) as $key ) {
			if ( ! in_array( $key, $keys, true ) ) { throw new InvalidArgumentException( 'unknown_rule_key' ); }
		}
		$id = $rule['id'] ?? null;
		if ( ! self::is_id( $id ) ) { throw new InvalidArgumentException( 'invalid_rule_id' ); }
		$company = $rule['company_id'] ?? null;
		if ( ! self::is_id( $company ) ) { throw new InvalidArgumentException( 'invalid_company_id' ); }
		$location = $rule['location_id'] ?? null;
		if ( self::ANY !== $location && ! self::is_id( $location ) ) { throw new InvalidArgumentException( 'invalid_location_id' ); }

		$services = $rule['services'] ?? null;
		if ( ! is_array( $services ) || empty( $services ) ) { throw new InvalidArgumentException( 'services_required' ); }
		if ( in_array( self::ANY, $services, true ) ) {
			if ( count( $services ) !== 1 ) { throw new InvalidArgumentException( 'wildcard_must_stand_alone' ); }
		} else {
			foreach ( $services as $service ) {
				if ( ! self::is_id( $service ) ) { throw new InvalidArgumentException( 'invalid_service_id' ); }
			}
		}

		$min = $rule['min_amount_minor'] ?? null;
		if ( null !== $min && ( ! is_int( $min ) || $min < 0 ) ) { throw new InvalidArgumentException( 'invalid_min_amount' ); }

		$action = $rule['action'] ?? null;
		if ( ! in_array( $action, array( self::ACTION_REQUIRE_APPROVAL, self::ACTION_RESTRICT_STAFF ), true ) ) { throw new InvalidArgumentException( 'unknown_action' ); }

		$staff = $rule['staff_ids'] ?? array();
		if ( self::ACTION_RESTRICT_STAFF === $action ) {
			if ( ! is_array( $staff ) || empty( $staff ) ) { throw new InvalidArgumentException( 'restrict_needs_staff' ); }
			foreach ( $staff as $member ) {
				if ( ! self::is_id( $member ) ) { throw new InvalidArgumentException( 'invalid_staff_id' ); }
			}
		} elseif ( ! empty( $staff ) ) {
			throw new InvalidArgumentException( 'staff_ids_only_for_restrict' );
		}

		$enabled = $rule['enabled'] ?? true;
		if ( ! is_bool( $enabled ) ) { throw new InvalidArgumentException( 'invalid_enabled_flag' ); }

		return array(
			'id'               => $id,
			'company_id'       => $company,
			'location_id'      => $location,
			'services'         => array_values( $services ),
			'min_amount_minor' => $min,
			'action'           => $action,
			'staff_ids'        => array_values( array_unique( (array) $staff ) ),
			'enabled'          => $enabled,
		);
	}

	/**
	 * Evaluates the rules for one prospective booking.
	 *
	 * @param array $rules   Stored rules (raw or validated).
	 * @param array $booking company_id, location_id, service_id, amount_minor (int >= 0), staff_id (id|null).
	 * @throws InvalidArgumentException On malformed booking, malformed rule or a rule from another company.
	 */
	public static function evaluate( $rules, $booking ) {
		if ( ! is_array( $rules ) || ! is_array( $booking ) ) { throw new InvalidArgumentException( 'invalid_policy_inputs' ); }
		$company  = $booking['company_id'] ?? null;
		$location = $booking['location_id'] ?? null;
		$service  = $booking['service_id'] ?? null;
		$amount   = $booking['amount_minor'] ?? null;
		$staff    = $booking['staff_id'] ?? null;
		if ( ! self::is_id( $company ) || ! self::is_id( $location ) || ! self::is_id( $service ) ) { throw new InvalidArgumentException( 'invalid_booking_scope' ); }
		if ( ! is_int( $amount ) || $amount < 0 ) { throw new InvalidArgumentException( 'invalid_booking_amount' ); }
		if ( null !== $staff && ! self::is_id( $staff ) ) { throw new InvalidArgumentException( 'invalid_booking_staff' ); }

		$approval = false;
		$allowed  = null;
		$matched  = array();
		foreach ( $rules as $raw ) {
			$rule = self::validate_rule( $raw );
			if ( $rule['company_id'] !== $company ) { throw new InvalidArgumentException( 'cross_company_rule' ); }
			if ( ! $rule['enabled'] ) { continue; }
			if ( self::ANY !== $rule['location_id'] && $rule['location_id'] !== $location ) { continue; }
			if ( ! in_array( self::ANY, $rule['services'], true ) && ! in_array( $service, $rule['services'], true ) ) { continue; }
			if ( null !== $rule['min_amount_minor'] && $amount < $rule['min_amount_minor'] ) { continue; }

			$matched[] = $rule['id'];
			if ( self::ACTION_REQUIRE_APPROVAL === $rule['action'] ) {
				$approval = true;
			} else {
				$allowed = null === $allowed ? $rule['staff_ids'] : array_values( array_intersect( $allowed, $rule['staff_ids'] ) );
			}
		}

		return array(
			'company_id'        => $company,
			'location_id'       => $location,
			'approval_required' => $approval,
			'allowed_staff_ids' => $allowed,
			'matched_rules'     => $matched,
			'read_only'         => true,
		);
	}

	/** True when the decision lets $staff_id take the booking. Null means no staff restriction applies. */
	public static function assignment_allowed( $decision, $staff_id ) {
		if ( ! is_array( $decision ) || ! array_key_exists( 'allowed_staff_ids', $decision ) ) { return false; }
		if ( null === $decision['allowed_staff_ids'] ) { return true; }
		return is_string( $staff_id ) && in_array( $staff_id, $decision['allowed_staff_ids'], true );
	}

	private static function is_id( $value ) {
		return is_string( $value ) && (bool) preg_match( TZFD_Delegated_Roles::ID_PATTERN, $value );
	}
}
