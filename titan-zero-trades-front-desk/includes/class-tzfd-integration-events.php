<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TZFD_Integration_Events {
    const SCHEMA_VERSION = 1;

    public static function register() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'tz_fd_process_data', array( __CLASS__, 'dispatch_created' ), 20, 1 );
            add_action( 'tz_fd_update_status', array( __CLASS__, 'dispatch_status_update_attempted' ), 20, 2 );
        }
    }

    /** Normalize scalar text without allowing markup or array/object coercion. */
    private static function text_value( $tz_value ) {
        if ( ! is_string( $tz_value ) && ! is_int( $tz_value ) && ! is_float( $tz_value ) ) { return ''; }
        if ( is_float( $tz_value ) && ! is_finite( $tz_value ) ) { return ''; }
        $tz_value = (string) $tz_value;
        if ( function_exists( 'sanitize_text_field' ) ) { return sanitize_text_field( $tz_value ); }
        // Standalone consumers/tests have no WordPress bootstrap. Never emit invalid UTF-8.
        return preg_match( '//u', $tz_value ) ? trim( preg_replace( '/<[^>]*>/', '', $tz_value ) ) : '';
    }

    private static function integer_value( $tz_value, $fallback = 0 ) {
        if ( is_int( $tz_value ) ) { return $tz_value >= 0 ? $tz_value : $fallback; }
        if ( is_float( $tz_value ) ) {
            return is_finite( $tz_value ) && $tz_value >= 0 && $tz_value < (float) PHP_INT_MAX && floor( $tz_value ) === $tz_value ? (int) $tz_value : $fallback;
        }
        if ( ! is_string( $tz_value ) || ! preg_match( '/^[0-9]+$/D', $tz_value ) ) { return $fallback; }
        $tz_value = ltrim( $tz_value, '0' );
        if ( '' === $tz_value ) { return 0; }
        $integer = filter_var( $tz_value, FILTER_VALIDATE_INT );
        return false === $integer ? $fallback : $integer;
    }

    private static function price_value( $tz_value ) {
        if ( ! is_int( $tz_value ) && ! is_float( $tz_value ) && ! is_string( $tz_value ) ) { return 0.0; }
        if ( ! is_numeric( $tz_value ) ) { return 0.0; }
        $tz_value = (float) $tz_value;
        return is_finite( $tz_value ) && $tz_value >= 0 ? $tz_value : 0.0;
    }

    public static function normalize_created_payload( $tz_params ) {
        if ( ! is_array( $tz_params ) ) { $tz_params = array(); }

        $payload = array(
            'schema_version' => self::SCHEMA_VERSION,
            'event' => 'booking.created',
            'booking_id' => isset( $tz_params['itemnumber'] ) ? self::integer_value( $tz_params['itemnumber'] ) : 0,
            'form_id' => isset( $tz_params['formid'] ) ? self::integer_value( $tz_params['formid'] ) : 0,
            'form_name' => isset( $tz_params['formname'] ) ? self::text_value( $tz_params['formname'] ) : '',
            'appointments' => array(),
        );

        if ( empty( $tz_params['apps'] ) || ! is_array( $tz_params['apps'] ) ) { return $payload; }

        foreach ( $tz_params['apps'] as $appointment ) {
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

    public static function dispatch_created( $tz_params ) {
        $payload = self::normalize_created_payload( $tz_params );

        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'titan_zero_front_desk_booking_created_payload', $payload, $tz_params );
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
