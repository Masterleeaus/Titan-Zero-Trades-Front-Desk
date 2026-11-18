<?php
/**
 * Delegated tool permissions for the hosted AI Agent Team, enforced inside the Free plugin.
 *
 * The model proposes typed tool calls. This class decides whether WordPress may run one. It never runs
 * anything itself, and it never accepts model-written code or SQL: a tool is a catalogue name and its
 * arguments are checked against a typed allowlist. Pure PHP, no WordPress calls.
 *
 * Decision order (first failing check wins, default deny):
 *   invalid grant, unknown tool, invalid arguments, company mismatch, agent mismatch, expired grant,
 *   stopped, paused, tool not granted, rate limit, dry run, needs approval, allow.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';

final class TZFD_Agent_Tool_Permissions {

	const TOOL_ANSWER           = 'answer';
	const TOOL_READ_PRICING     = 'read_pricing';
	const TOOL_CHECK_AVAILABLE  = 'check_availability';
	const TOOL_HOLD_SLOT        = 'hold_slot';
	const TOOL_BOOK             = 'book';
	const TOOL_CANCEL           = 'cancel';
	const TOOL_RESCHEDULE       = 'reschedule';
	const TOOL_FOLLOW_UP        = 'follow_up';
	const TOOL_DRAFT_ESTIMATE   = 'draft_estimate';
	const TOOL_REQUEST_APPROVAL = 'request_approval';

	const STATE_ACTIVE  = 'active';
	const STATE_PAUSED  = 'paused';
	const STATE_STOPPED = 'stopped';

	const DECISION_ALLOW          = 'allow';
	const DECISION_DENY           = 'deny';
	const DECISION_DRY_RUN        = 'dry_run';
	const DECISION_NEEDS_APPROVAL = 'needs_approval';

	const MAX_ARGS_BYTES = 4096;

	/**
	 * Tool catalogue. Each tool declares its risk, whether it needs owner approval by default, its hourly
	 * ceiling and its typed argument schema. A trailing '?' marks an optional argument.
	 *
	 * @return array<string,array>
	 */
	public static function catalogue() {
		return array(
			self::TOOL_ANSWER           => array( 'risk' => 'read',     'approval' => false, 'max_per_hour' => 120, 'args' => array( 'question' => 'text' ) ),
			self::TOOL_READ_PRICING     => array( 'risk' => 'read',     'approval' => false, 'max_per_hour' => 120, 'args' => array( 'service_id' => 'id' ) ),
			self::TOOL_CHECK_AVAILABLE  => array( 'risk' => 'read',     'approval' => false, 'max_per_hour' => 60,  'args' => array( 'service_id' => 'id', 'location_id' => 'id', 'date' => 'date' ) ),
			self::TOOL_HOLD_SLOT        => array( 'risk' => 'write',    'approval' => false, 'max_per_hour' => 20,  'args' => array( 'service_id' => 'id', 'location_id' => 'id', 'date' => 'date', 'time' => 'time' ) ),
			self::TOOL_BOOK             => array( 'risk' => 'write',    'approval' => true,  'max_per_hour' => 10,  'args' => array( 'service_id' => 'id', 'location_id' => 'id', 'date' => 'date', 'time' => 'time', 'customer_id' => 'id' ) ),
			self::TOOL_CANCEL           => array( 'risk' => 'write',    'approval' => true,  'max_per_hour' => 10,  'args' => array( 'booking_id' => 'id', 'customer_id' => 'id' ) ),
			self::TOOL_RESCHEDULE       => array( 'risk' => 'write',    'approval' => true,  'max_per_hour' => 10,  'args' => array( 'booking_id' => 'id', 'date' => 'date', 'time' => 'time' ) ),
			self::TOOL_FOLLOW_UP        => array( 'risk' => 'external', 'approval' => true,  'max_per_hour' => 20,  'args' => array( 'customer_id' => 'id', 'channel' => 'channel' ) ),
			self::TOOL_DRAFT_ESTIMATE   => array( 'risk' => 'write',    'approval' => false, 'max_per_hour' => 20,  'args' => array( 'service_id' => 'id', 'customer_id' => 'id?' ) ),
			self::TOOL_REQUEST_APPROVAL => array( 'risk' => 'write',    'approval' => false, 'max_per_hour' => 50,  'args' => array( 'action_ref' => 'id', 'reason' => 'text' ) ),
		);
	}

	/**
	 * Validates and normalises a grant. Throws on malformed grants so they are never stored.
	 *
	 * Grant keys: company_id, agent_id, state, dry_run, version, expires_at, tools, auto_approve, max_per_hour.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function validate_grant( $grant ) {
		if ( ! is_array( $grant ) ) { throw new InvalidArgumentException( 'grant_not_array' ); }
		$allowed = array( 'company_id', 'agent_id', 'state', 'dry_run', 'version', 'expires_at', 'tools', 'auto_approve', 'max_per_hour' );
		foreach ( array_keys( $grant ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) { throw new InvalidArgumentException( 'unknown_grant_key' ); }
		}
		foreach ( array( 'company_id', 'agent_id' ) as $key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message from fixed internal field names; not HTML output.
			if ( ! self::is_id( $grant[ $key ] ?? null ) ) { throw new InvalidArgumentException( 'invalid_' . $key ); }
		}
		$state = $grant['state'] ?? self::STATE_ACTIVE;
		if ( ! in_array( $state, array( self::STATE_ACTIVE, self::STATE_PAUSED, self::STATE_STOPPED ), true ) ) { throw new InvalidArgumentException( 'invalid_state' ); }
		$dry = $grant['dry_run'] ?? false;
		if ( ! is_bool( $dry ) ) { throw new InvalidArgumentException( 'invalid_dry_run' ); }
		$version = $grant['version'] ?? 1;
		if ( ! is_int( $version ) || $version < 1 ) { throw new InvalidArgumentException( 'invalid_version' ); }
		$expires = $grant['expires_at'] ?? null;
		if ( null !== $expires && ( ! is_int( $expires ) || $expires < 1 ) ) { throw new InvalidArgumentException( 'invalid_expiry' ); }

		$catalogue = self::catalogue();
		$tools = self::tool_list( $grant['tools'] ?? array(), $catalogue );
		$auto  = self::tool_list( $grant['auto_approve'] ?? array(), $catalogue );
		foreach ( $auto as $tool ) {
			if ( ! in_array( $tool, $tools, true ) ) { throw new InvalidArgumentException( 'auto_approve_not_granted' ); }
			// No unattended outbound messages: an external-risk tool always waits for a person.
			if ( 'external' === $catalogue[ $tool ]['risk'] ) { throw new InvalidArgumentException( 'external_needs_approval' ); }
		}

		$caps = $grant['max_per_hour'] ?? array();
		if ( ! is_array( $caps ) ) { throw new InvalidArgumentException( 'invalid_max_per_hour' ); }
		foreach ( $caps as $tool => $cap ) {
			if ( ! isset( $catalogue[ $tool ] ) || ! is_int( $cap ) || $cap < 0 || $cap > $catalogue[ $tool ]['max_per_hour'] ) {
				throw new InvalidArgumentException( 'max_per_hour_out_of_range' );
			}
		}

		return array(
			'company_id' => $grant['company_id'], 'agent_id' => $grant['agent_id'], 'state' => $state, 'dry_run' => $dry,
			'version' => $version, 'expires_at' => $expires, 'tools' => $tools, 'auto_approve' => $auto, 'max_per_hour' => $caps,
		);
	}

	/**
	 * Decides one proposed tool call. Never throws for a bad request: every problem is a deny with a reason.
	 *
	 * @param array $grant   Stored grant.
	 * @param array $request company_id, agent_id, tool, args, now (Unix seconds), receipts_last_hour (tool => count),
	 *                       approved_by_human (bool, default false).
	 * @return array decision, reason, tool, grant_version, requires_revalidation.
	 */
	public static function decide( $grant, $request ) {
		$tool_name = is_array( $request ) && isset( $request['tool'] ) && is_string( $request['tool'] ) ? $request['tool'] : '';
		$base = array( 'tool' => $tool_name, 'grant_version' => null, 'requires_revalidation' => true, 'executed' => false );
		try {
			$g = self::validate_grant( $grant );
		} catch ( InvalidArgumentException $e ) {
			return self::deny( $base, 'invalid_grant' );
		}
		$base['grant_version'] = $g['version'];
		if ( ! is_array( $request ) ) { return self::deny( $base, 'invalid_request' ); }
		$catalogue = self::catalogue();
		if ( ! isset( $catalogue[ $tool_name ] ) ) { return self::deny( $base, 'unknown_tool' ); }

		try {
			$args = self::validate_args( $tool_name, $request['args'] ?? array() );
		} catch ( InvalidArgumentException $e ) {
			return self::deny( $base, 'invalid_arguments' );
		}
		if ( ( $request['company_id'] ?? null ) !== $g['company_id'] ) { return self::deny( $base, 'company_mismatch' ); }
		if ( ( $request['agent_id'] ?? null ) !== $g['agent_id'] ) { return self::deny( $base, 'agent_mismatch' ); }
		$now = $request['now'] ?? null;
		if ( ! is_int( $now ) ) { return self::deny( $base, 'invalid_time' ); }
		if ( null !== $g['expires_at'] && $now >= $g['expires_at'] ) { return self::deny( $base, 'grant_expired' ); }
		if ( self::STATE_STOPPED === $g['state'] ) { return self::deny( $base, 'agent_stopped' ); }
		if ( self::STATE_PAUSED === $g['state'] ) { return self::deny( $base, 'agent_paused' ); }
		if ( ! in_array( $tool_name, $g['tools'], true ) ) { return self::deny( $base, 'tool_not_granted' ); }

		$ceiling = isset( $g['max_per_hour'][ $tool_name ] ) ? $g['max_per_hour'][ $tool_name ] : $catalogue[ $tool_name ]['max_per_hour'];
		$used = $request['receipts_last_hour'][ $tool_name ] ?? 0;
		if ( ! is_int( $used ) || $used < 0 ) { return self::deny( $base, 'invalid_usage_count' ); }
		if ( $used >= $ceiling ) { return self::deny( $base, 'rate_limited' ); }

		$base['args'] = $args;
		if ( $g['dry_run'] ) { return array_merge( $base, array( 'decision' => self::DECISION_DRY_RUN, 'reason' => 'dry_run_grant' ) ); }
		$needs = $catalogue[ $tool_name ]['approval'] && ! in_array( $tool_name, $g['auto_approve'], true );
		if ( $needs && empty( $request['approved_by_human'] ) ) {
			return array_merge( $base, array( 'decision' => self::DECISION_NEEDS_APPROVAL, 'reason' => 'owner_approval_required' ) );
		}
		return array_merge( $base, array( 'decision' => self::DECISION_ALLOW, 'reason' => 'granted' ) );
	}

	/** Returns a copy of the grant with a new state and a bumped version. The old version stays in receipts. */
	public static function with_state( $grant, $state ) {
		$g = self::validate_grant( $grant );
		if ( ! in_array( $state, array( self::STATE_ACTIVE, self::STATE_PAUSED, self::STATE_STOPPED ), true ) ) { throw new InvalidArgumentException( 'invalid_state' ); }
		$g['state'] = $state;
		$g['version']++;
		return $g;
	}

	/**
	 * Validates typed arguments against the tool schema. Unknown or missing keys, wrong types and oversize
	 * payloads throw.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function validate_args( $tool, $args ) {
		$catalogue = self::catalogue();
		if ( ! isset( $catalogue[ $tool ] ) ) { throw new InvalidArgumentException( 'unknown_tool' ); }
		if ( ! is_array( $args ) ) { throw new InvalidArgumentException( 'args_not_array' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- measures serialised size only; wp_json_encode() is WordPress-only and may change the measured length.
		$encoded = json_encode( $args );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_ARGS_BYTES ) { throw new InvalidArgumentException( 'args_too_large' ); }
		$schema = $catalogue[ $tool ]['args'];
		foreach ( array_keys( $args ) as $key ) {
			if ( ! is_string( $key ) || ! isset( $schema[ $key ] ) ) { throw new InvalidArgumentException( 'unknown_argument' ); }
		}
		$clean = array();
		foreach ( $schema as $name => $spec ) {
			$optional = '?' === substr( $spec, -1 );
			$type = $optional ? substr( $spec, 0, -1 ) : $spec;
			if ( ! array_key_exists( $name, $args ) ) {
				if ( $optional ) { continue; }
				throw new InvalidArgumentException( 'missing_argument' );
			}
			$clean[ $name ] = self::check_type( $type, $args[ $name ] );
		}
		return $clean;
	}

	private static function check_type( $type, $value ) {
		switch ( $type ) {
			case 'id':
				if ( ! self::is_id( $value ) ) { break; }
				return $value;
			case 'date':
				if ( is_string( $value ) && preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $p ) && checkdate( (int) $p[2], (int) $p[3], (int) $p[1] ) ) { return $value; }
				break;
			case 'time':
				if ( is_string( $value ) && preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $value ) ) { return $value; }
				break;
			case 'channel':
				if ( in_array( $value, array( 'email', 'sms', 'call' ), true ) ) { return $value; }
				break;
			case 'text':
				if ( is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 500 && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) { return $value; }
				break;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message from fixed internal type names; not HTML output.
		throw new InvalidArgumentException( 'invalid_' . $type );
	}

	private static function tool_list( $list, $catalogue ) {
		if ( ! is_array( $list ) || array_values( $list ) !== $list ) { throw new InvalidArgumentException( 'invalid_tool_list' ); }
		foreach ( $list as $tool ) {
			if ( ! is_string( $tool ) || ! isset( $catalogue[ $tool ] ) ) { throw new InvalidArgumentException( 'unknown_tool_in_grant' ); }
		}
		return array_values( array_unique( $list ) );
	}

	private static function deny( $base, $reason ) {
		return array_merge( $base, array( 'decision' => self::DECISION_DENY, 'reason' => $reason ) );
	}

	private static function is_id( $value ) {
		return is_string( $value ) && (bool) preg_match( TZFD_Delegated_Roles::ID_PATTERN, $value );
	}
}
