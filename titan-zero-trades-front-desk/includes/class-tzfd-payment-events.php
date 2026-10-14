<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local payment-result event contract for trusted payment extensions. */
class TZFD_Payment_Events {
    const SCHEMA_VERSION = 1;
    const EVENT_NAME = 'booking.payment_update_attempted';

    /**
     * Emit a minimized local event after a trusted extension has verified its
     * own provider result. This method does not verify, persist or mutate it.
     */
    public static function dispatch_update_attempted( $booking_id, $status ) {
        if ( ! function_exists( 'do_action' ) ) { return false; }

        $booking_id = self::normalize_booking_id( $booking_id );
        $status = self::normalize_status( $status );
        if ( $booking_id < 1 || null === $status ) { return false; }

        $payload = array(
            'schema_version' => self::SCHEMA_VERSION,
            'event' => self::EVENT_NAME,
            'booking_id' => $booking_id,
            'status' => $status,
        );

        do_action( 'titan_zero_front_desk_payment_update_attempted', $payload );
        do_action( 'titan_zero_front_desk_booking_event', self::EVENT_NAME, $payload );
        return true;
    }

    private static function normalize_booking_id( $tzfd_value ) {
        if ( is_int( $tzfd_value ) ) { return $tzfd_value > 0 ? $tzfd_value : 0; }
        if ( ! is_string( $tzfd_value ) || ! preg_match( '/^[0-9]+$/D', $tzfd_value ) ) { return 0; }

        $tzfd_value = ltrim( $tzfd_value, '0' );
        if ( '' === $tzfd_value ) { return 0; }
        $max = (string) PHP_INT_MAX;
        if ( strlen( $tzfd_value ) > strlen( $max ) || ( strlen( $tzfd_value ) === strlen( $max ) && strcmp( $tzfd_value, $max ) > 0 ) ) { return 0; }

        return (int) $tzfd_value;
    }

    private static function normalize_status( $status ) {
        if ( ! is_string( $status ) ) { return null; }
        $status = strtolower( trim( $status ) );
        $allowed = array( 'pending', 'succeeded', 'failed', 'cancelled', 'refunded' );
        return in_array( $status, $allowed, true ) ? $status : null;
    }
}
