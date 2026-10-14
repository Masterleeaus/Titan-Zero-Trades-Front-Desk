<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TZFD_Integration_Events {
    const SCHEMA_VERSION = 1;

    public static function register() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'tzfd_process_data', array( __CLASS__, 'dispatch_created' ), 20, 1 );
            add_action( 'tzfd_update_status', array( __CLASS__, 'dispatch_status_update_attempted' ), 20, 2 );
        }
    }

    /** Normalize scalar text without allowing markup or array/object coercion. */
    private static function text_value( $tzfd_value ) {
        if ( ! is_string( $tzfd_value ) && ! is_int( $tzfd_value ) && ! is_float( $tzfd_value ) ) { return ''; }
        if ( is_float( $tzfd_value ) && ! is_finite( $tzfd_value ) ) { return ''; }
        $tzfd_value = (string) $tzfd_value;
        if ( function_exists( 'sanitize_text_field' ) ) { return sanitize_text_field( $tzfd_value ); }
        // Standalone consumers/tests have no WordPress bootstrap. Never emit invalid UTF-8.
        return preg_match( '//u', $tzfd_value ) ? trim( preg_replace( '/<[^>]*>/', '', $tzfd_value ) ) : '';
    }

    private static function integer_value( $tzfd_value, $fallback = 0 ) {
        if ( is_int( $tzfd_value ) ) { return $tzfd_value >= 0 ? $tzfd_value : $fallback; }
        if ( is_float( $tzfd_value ) ) {
            return is_finite( $tzfd_value ) && $tzfd_value >= 0 && $tzfd_value < (float) PHP_INT_MAX && floor( $tzfd_value ) === $tzfd_value ? (int) $tzfd_value : $fallback;
        }
        if ( ! is_string( $tzfd_value ) || ! preg_match( '/^[0-9]+$/D', $tzfd_value ) ) { return $fallback; }
        $tzfd_value = ltrim( $tzfd_value, '0' );
        if ( '' === $tzfd_value ) { return 0; }
        $integer = filter_var( $tzfd_value, FILTER_VALIDATE_INT );
        return false === $integer ? $fallback : $integer;
    }

    private static function price_value( $tzfd_value ) {
        if ( ! is_int( $tzfd_value ) && ! is_float( $tzfd_value ) && ! is_string( $tzfd_value ) ) { return 0.0; }
        if ( ! is_numeric( $tzfd_value ) ) { return 0.0; }
        $tzfd_value = (float) $tzfd_value;
        return is_finite( $tzfd_value ) && $tzfd_value >= 0 ? $tzfd_value : 0.0;
    }

    public static function normalize_created_payload( $tzfd_params ) {
        if ( ! is_array( $tzfd_params ) ) { $tzfd_params = array(); }

        $payload = array(
            'schema_version' => self::SCHEMA_VERSION,
            'event' => 'booking.created',
            'booking_id' => isset( $tzfd_params['itemnumber'] ) ? self::integer_value( $tzfd_params['itemnumber'] ) : 0,
            'form_id' => isset( $tzfd_params['formid'] ) ? self::integer_value( $tzfd_params['formid'] ) : 0,
            'form_name' => isset( $tzfd_params['formname'] ) ? self::text_value( $tzfd_params['formname'] ) : '',
            'appointments' => array(),
        );

        if ( empty( $tzfd_params['apps'] ) || ! is_array( $tzfd_params['apps'] ) ) { return $payload; }

        foreach ( $tzfd_params['apps'] as $appointment ) {
            if ( ! is_array( $appointment ) ) { continue; }

            $quantity = isset( $appointment['quant'] ) ? self::integer_value( $appointment['quant'], 1 ) : 1;
            if ( $quantity < 1 ) { $quantity = 1; }

            $payload['appointments'][] = array(
                'id' => isset( $appointment['id'] ) ? self::integer_value( $appointment['id'] ) : 0,
                'service_id' => isset( $appointment['sid'] ) ? self::text_value( $appointment['sid'] ) : '',
                'service_index' => isset( $appointment['serviceindex'] ) ? self::integer_value( $appointment['serviceindex'], -1 ) : -1,
                'service' => isset( $appointment['service'] ) ? self::text_value( $appointment['service'] ) : '',
                'date' => isset( $appointment['date'] ) ? self::text_value( $appointment['date'] ) : '',
                'slot' => isset( $appointment['slot'] ) ? self::text_value( $appointment['slot'] ) : '',
                'duration' => isset( $appointment['duration'] ) ? self::integer_value( $appointment['duration'] ) : 0,
                'price' => isset( $appointment['price'] ) ? self::price_value( $appointment['price'] ) : 0.0,
                'quantity' => $quantity,
                'status' => isset( $appointment['cancelled'] ) ? self::text_value( $appointment['cancelled'] ) : '',
            );
        }

        return $payload;
    }

    /**
     * Normalize the legacy status-update hook without inventing a transition
     * result or accepting malformed IDs/status values.
     */
    public static function normalize_status_update_attempted_payload( $booking_id, $status ) {
        if ( ! is_int( $booking_id ) && ! is_string( $booking_id ) ) { return null; }
        if ( ! is_string( $status ) || 1 !== preg_match( '//u', $status ) ) { return null; }

        $booking_id = self::integer_value( $booking_id, 0 );
        if ( $booking_id < 1 ) { return null; }

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'event' => 'booking.status_update_attempted',
            'booking_id' => $booking_id,
            'status' => self::text_value( $status ),
        );
    }

    public static function dispatch_created( $tzfd_params ) {
        $payload = self::normalize_created_payload( $tzfd_params );

        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'titan_zero_front_desk_booking_created_payload', $payload, $tzfd_params );
            if ( is_array( $filtered ) ) { $payload = $filtered; }
        }

        if ( function_exists( 'do_action' ) ) {
            // Default events deliberately exclude the raw submitted intake.
            do_action( 'titan_zero_front_desk_booking_created', $payload );
            do_action( 'titan_zero_front_desk_booking_event', 'booking.created', $payload );
        }
    }

    public static function dispatch_status_update_attempted( $booking_id, $status ) {
        $payload = self::normalize_status_update_attempted_payload( $booking_id, $status );
        if ( ! is_array( $payload ) || ! function_exists( 'do_action' ) ) { return; }

        do_action( 'titan_zero_front_desk_booking_status_update_attempted', $payload );
        do_action( 'titan_zero_front_desk_booking_event', 'booking.status_update_attempted', $payload );
    }
}
