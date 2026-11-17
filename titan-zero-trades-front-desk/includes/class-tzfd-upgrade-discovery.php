<?php
/**
 * First-run checklist and optional, dismissible discovery of the hosted AI services.
 *
 * Pure PHP, no WordPress calls. The Free plugin is complete without an account: the checklist covers
 * local setup only, and no step asks for registration. Upgrade links appear only after activation, never
 * before, and only when the hosted services are actually live. Dismissal is permanent until the owner
 * re-enables it, and the notice is capped and snoozed so it cannot nag.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Upgrade_Discovery {

	/**
	 * Flip only after the hosted Assistant or Agent Team passes end-to-end tests. The architecture doc
	 * forbids advertising hosted plans before the backend is ready.
	 */
	const HOSTED_SERVICES_LIVE = false;
	const SNOOZE_SECONDS       = 2592000; // 30 days between notices.
	const MAX_SHOWS            = 2;       // Lifetime cap on notices, then the owner must open the page themselves.

	/** Local first-run steps. Keys are stable identifiers, labels are display text. */
	const STEPS = array(
		'service_catalogue'  => 'Add your services and prices',
		'staff_calendar'     => 'Add staff and their calendar',
		'operating_hours'    => 'Set your operating hours',
		'publish_form'       => 'Publish your booking form',
		'test_booking'       => 'Make a test booking',
		'view_customer'      => 'Open a customer record',
		'configure_reminders' => 'Configure reminders',
	);

	/** Steps that must be complete before the site counts as activated. */
	const ACTIVATION_STEPS = array( 'service_catalogue', 'operating_hours', 'publish_form', 'test_booking' );

	/**
	 * @param array $facts Map of step key => bool.
	 * @return array{steps: array, completed: int, total: int, activated: bool}
	 * @throws InvalidArgumentException When a step is missing or not a boolean.
	 */
	public static function checklist( $facts ) {
		if ( ! is_array( $facts ) ) { throw new InvalidArgumentException( 'facts_not_array' ); }
		foreach ( array_keys( $facts ) as $key ) {
			if ( ! isset( self::STEPS[ $key ] ) ) { throw new InvalidArgumentException( 'unknown_step' ); }
		}
		$steps = array();
		$completed = 0;
		$done_map = array();
		foreach ( self::STEPS as $key => $label ) {
			if ( ! array_key_exists( $key, $facts ) || ! is_bool( $facts[ $key ] ) ) { throw new InvalidArgumentException( 'invalid_step_fact' ); }
			$done = $facts[ $key ];
			$done_map[ $key ] = $done;
			if ( $done ) { $completed++; }
			$steps[] = array( 'key' => $key, 'label' => $label, 'done' => $done );
		}
		$activated = true;
		foreach ( self::ACTIVATION_STEPS as $key ) {
			if ( ! $done_map[ $key ] ) { $activated = false; }
		}
		return array( 'steps' => $steps, 'completed' => $completed, 'total' => count( self::STEPS ), 'activated' => $activated );
	}

	/**
	 * Decides whether to show the upgrade discovery notice.
	 *
	 * @param array $state {dismissed: bool, shows: int, next_allowed_at: int|null}
	 * @param array $facts Checklist facts, see checklist().
	 * @param int   $now   Unix seconds.
	 * @param bool  $hosted_live Injectable for tests; defaults to HOSTED_SERVICES_LIVE.
	 * @return array show (bool), reason (string), and the offers to show when show is true.
	 */
	public static function decide( $state, $facts, $now, $hosted_live = self::HOSTED_SERVICES_LIVE ) {
		$s = self::validate_state( $state );
		$list = self::checklist( $facts );
		if ( ! is_int( $now ) || $now < 0 ) { throw new InvalidArgumentException( 'invalid_time' ); }
		if ( ! $hosted_live ) { return self::none( 'hosted_services_not_live' ); }
		if ( ! $list['activated'] ) { return self::none( 'not_activated' ); }
		if ( $s['dismissed'] ) { return self::none( 'dismissed' ); }
		if ( $s['shows'] >= self::MAX_SHOWS ) { return self::none( 'show_limit_reached' ); }
		if ( null !== $s['next_allowed_at'] && $now < $s['next_allowed_at'] ) { return self::none( 'snoozed' ); }
		return array( 'show' => true, 'reason' => 'eligible', 'offers' => self::offers() );
	}

	/** State after the notice is shown once. */
	public static function record_shown( $state, $now ) {
		$s = self::validate_state( $state );
		if ( ! is_int( $now ) || $now < 0 ) { throw new InvalidArgumentException( 'invalid_time' ); }
		$s['shows']++;
		$s['next_allowed_at'] = $now + self::SNOOZE_SECONDS;
		return $s;
	}

	/** State after the owner dismisses the notice. It stays dismissed until the owner clears it. */
	public static function dismiss( $state ) {
		$s = self::validate_state( $state );
		$s['dismissed'] = true;
		return $s;
	}

	/**
	 * Accurate, neutral descriptions of the optional services. They say what the Free plugin keeps and what
	 * the hosted service adds, and they make no claim about features that do not exist yet.
	 */
	public static function offers() {
		return array(
			array(
				'id'          => 'assistant',
				'title'       => 'Titan Zero AI Assistant',
				'description' => 'An optional hosted service for text enquiries. Bookings, calendars, customers and reminders stay in this plugin. Requires a Titan account and a paid plan.',
			),
			array(
				'id'          => 'agent_team',
				'title'       => 'Titan Zero AI Agent Team',
				'description' => 'An optional hosted service with supervised agents for enquiries and booking changes. Booking and cancellation changes can require your approval, and you can pause or stop the agents at any time. Requires a Titan account and a paid plan.',
			),
		);
	}

	private static function none( $reason ) {
		return array( 'show' => false, 'reason' => $reason, 'offers' => array() );
	}

	/** @throws InvalidArgumentException */
	private static function validate_state( $state ) {
		if ( ! is_array( $state ) ) { throw new InvalidArgumentException( 'state_not_array' ); }
		$dismissed = $state['dismissed'] ?? false;
		$shows = $state['shows'] ?? 0;
		$next = $state['next_allowed_at'] ?? null;
		if ( ! is_bool( $dismissed ) || ! is_int( $shows ) || $shows < 0 ) { throw new InvalidArgumentException( 'invalid_state' ); }
		if ( null !== $next && ( ! is_int( $next ) || $next < 0 ) ) { throw new InvalidArgumentException( 'invalid_state' ); }
		return array( 'dismissed' => $dismissed, 'shows' => $shows, 'next_allowed_at' => $next );
	}
}
