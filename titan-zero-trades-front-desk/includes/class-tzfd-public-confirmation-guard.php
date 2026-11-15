<?php
/** Explicit, fail-closed nonce gate for public, signed booking confirmations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Public_Confirmation_Guard {
    /** GET is a preview only. For POST, both a valid booking ID and nonce are mandatory. */
    public static function authorized( $method, $posted, $action_prefix ) {
        if ( 'POST' !== $method || ! is_array( $posted ) ||
             ! in_array( $action_prefix, array( 'tz_fd_cancel_', 'tz_fd_reschedule_' ), true ) ) {
            return false;
        }
        if ( ! isset( $posted['booking_id'], $posted['_wpnonce'] ) ||
             ! ( is_string( $posted['booking_id'] ) || is_int( $posted['booking_id'] ) ) ||
             ! is_string( $posted['_wpnonce'] ) ) { return false; }
        $raw_id = wp_unslash( (string) $posted['booking_id'] );
        if ( '' === $raw_id || strlen( $raw_id ) > 19 || ! ctype_digit( $raw_id ) ) { return false; }
        $booking_id = filter_var( $raw_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( false === $booking_id ) { return false; }
        $nonce = sanitize_text_field( wp_unslash( $posted['_wpnonce'] ) );
        if ( '' === $nonce || strlen( $nonce ) > 128 ) { return false; }
        return (bool) wp_verify_nonce( $nonce, $action_prefix . $booking_id );
    }
}
