<?php
/**
 * Guided booking assistant planning step (issue #24).
 *
 * The assistant plans a booking; it never submits one. Time slots come only from the
 * `tzfd_ai_booking_slots` filter, which must be backed by the authoritative booking engine.
 * A slot the engine did not return is rejected, so the assistant cannot invent availability.
 * Confirmation hands the customer to the existing booking form, which remains the only write path.
 *
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-service-finder.php';

final class TZFD_Booking_Assistant {

	const MAX_DAYS_AHEAD = 365;
	const SLOT_PATTERN   = '/^([01]\d|2[0-3]):[0-5]\d\/([01]\d|2[0-3]):[0-5]\d$/D';

	/**
	 * Build the next step of a guided booking. No write happens here.
	 *
	 * @param mixed  $service_id Selected service ID from the customer.
	 * @param mixed  $date       Requested date, YYYY-MM-DD.
	 * @param mixed  $slot       Chosen slot in the engine's format (HH:MM/HH:MM), or empty to list options.
	 * @param array  $services   Visible catalogue records.
	 * @param string $today      Current site date, YYYY-MM-DD. Injected so tests are deterministic.
	 * @return array{status: string, service_id: int, date: string, slot: string, slots: string[], message: string}
	 */
	public static function plan( $service_id, $date, $slot, array $services, $today ) {
		$result = array( 'status' => 'invalid', 'service_id' => 0, 'date' => '', 'slot' => '', 'slots' => array(), 'message' => '' );

		$id = TZFD_Service_Finder::validate_selection( $service_id, $services );
		if ( 0 === $id ) {
			$result['message'] = 'service_not_available';
			return $result;
		}
		$result['service_id'] = $id;

		if ( ! self::valid_date( $date, $today ) ) {
			$result['message'] = 'date_not_valid';
			return $result;
		}
		$result['date'] = $date;

		// The authoritative engine supplies the list. An empty list means no feed is connected.
		$offered = apply_filters( 'tzfd_ai_booking_slots', array(), $id, $date );
		$offered = is_array( $offered ) ? array_values( array_filter( $offered, 'is_string' ) ) : array();
		$offered = array_values( array_filter( $offered, function ( $candidate ) {
			return 1 === preg_match( self::SLOT_PATTERN, $candidate ) && substr( $candidate, 0, 5 ) < substr( $candidate, 6, 5 );
		} ) );
		$result['slots'] = $offered;

		if ( '' === (string) $slot ) {
			if ( empty( $offered ) ) {
				$result['status']  = 'no_slots_feed';
				$result['message'] = 'availability_unavailable';
				return $result;
			}
			$result['status'] = 'choose_slot';
			return $result;
		}

		if ( ! is_string( $slot ) || ! in_array( $slot, $offered, true ) ) {
			$result['status']  = 'slot_not_offered';
			$result['message'] = 'slot_not_available';
			return $result;
		}

		$result['slot']    = $slot;
		$result['status']  = 'confirm';
		$result['message'] = 'confirm_before_submit';
		return $result;
	}

	/**
	 * Real calendar date, not in the past, within the booking horizon.
	 *
	 * @param mixed  $date  Candidate date.
	 * @param string $today Current site date.
	 * @return bool
	 */
	public static function valid_date( $date, $today ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $today ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
		$now    = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, new DateTimeZone( 'UTC' ) );
		if ( ! $parsed || ! $now || $parsed->format( 'Y-m-d' ) !== $date ) {
			return false;
		}
		$days = (int) $now->diff( $parsed )->format( '%r%a' );
		return $days >= 0 && $days <= self::MAX_DAYS_AHEAD;
	}

	/**
	 * Deep link to the existing booking form, carrying the validated selection as query parameters.
	 * The booking form does not yet read these parameters; wiring it is an open item on #23 and #24.
	 *
	 * @param string $booking_url Permalink of the booking page, or empty.
	 * @param array  $plan        Output of plan().
	 * @return string Empty when no booking page URL is configured.
	 */
	public static function handoff_url( $booking_url, array $plan ) {
		if ( ! is_string( $booking_url ) || '' === $booking_url || 0 === (int) $plan['service_id'] ) {
			return '';
		}
		$args = array( 'tzfd_service' => (int) $plan['service_id'] );
		if ( '' !== $plan['date'] ) {
			$args['tzfd_date'] = $plan['date'];
		}
		if ( '' !== $plan['slot'] ) {
			$args['tzfd_slot'] = $plan['slot'];
		}
		return add_query_arg( $args, $booking_url );
	}
}
