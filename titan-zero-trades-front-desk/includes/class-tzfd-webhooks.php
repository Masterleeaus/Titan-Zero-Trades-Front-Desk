<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Opt-in asynchronous delivery for allowlisted schema-v1 booking events.
 * Endpoint configuration is supplied by trusted local extension code.
 */
class TZFD_Webhooks {
    const CRON_HOOK = 'titan_zero_front_desk_deliver_webhook';
    const CONFIG_FILTER = 'titan_zero_front_desk_webhook_config';
    const MAX_ATTEMPTS = 5;
    const MAX_BODY_BYTES = 65536;

    public static function register() {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'titan_zero_front_desk_booking_event', array( __CLASS__, 'receive_event' ), 20, 2 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'deliver' ), 10, 3 );
    }

    /** Queue only allowlisted schema-v1 events. This method never makes HTTP requests. */
    public static function receive_event( $event_name, $payload ) {
        if ( ! in_array( $event_name, array( 'booking.created', 'booking.payment_update_attempted', 'booking.status_update_attempted' ), true ) ) { return false; }
        return self::enqueue( $event_name, $payload );
    }

    public static function enqueue( $event_name, $payload ) {
        if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'time' ) ) { return false; }

        $config = self::get_config();
        if ( ! is_array( $config ) ) { return false; }

        $payload = self::canonical_payload( $event_name, $payload );
        if ( ! is_array( $payload ) ) { return false; }

        $body = self::encode_payload( $payload );
        if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY_BYTES ) { return false; }

        $event_id = self::event_id( $body );
        // The same exact event arguments are deduplicated by WordPress Cron.
        // Store no endpoint credentials in the scheduled arguments.
        return (bool) wp_schedule_single_event(
            time() + 60,
            self::CRON_HOOK,
            array( $event_id, $body, 1 )
        );
    }

    /**
     * Deliver one queued attempt. The body is the exact JSON string used to
     * derive the event ID and is reused unchanged for every retry.
     */
    public static function deliver( $event_id, $body, $attempt ) {
        if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY_BYTES ) { return; }
        if ( ! is_string( $event_id ) || self::event_id( $body ) !== $event_id ) { return; }
        if ( ! is_int( $attempt ) || $attempt < 1 || $attempt > self::MAX_ATTEMPTS ) { return; }

        // Cron options can outlive the code that produced them. A matching
        // public body hash is not proof that the payload obeys our privacy schema.
        $event_name = self::event_name_from_body( $body );
        if ( null === $event_name ) { return; }

        $config = self::get_config();
        if ( ! is_array( $config ) || ! function_exists( 'wp_safe_remote_post' ) ) { return; }

        $timestamp = (string) time();
        $signature = hash_hmac( 'sha256', $body, $config['secret'] );
        $response = wp_safe_remote_post( $config['url'], array(
            'timeout' => 5,
            'redirection' => 0,
            'blocking' => true,
            'sslverify' => true,
            'limit_response_size' => 1024,
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-TZFD-Event' => $event_name,
                'X-TZFD-Event-ID' => $event_id,
                'Idempotency-Key' => $event_id,
                'X-TZFD-Timestamp' => $timestamp,
                'X-TZFD-Signature' => 'sha256=' . $signature,
            ),
            'body' => $body,
        ) );

        if ( self::is_transient_failure( $response ) && $attempt < self::MAX_ATTEMPTS ) {
            $delays = array( 60, 300, 900, 3600 );
            $delay = $delays[ $attempt - 1 ];
            wp_schedule_single_event(
                time() + $delay,
                self::CRON_HOOK,
                array( $event_id, $body, $attempt + 1 )
            );
        }
    }

    /** Return only the documented schema-v1 fields; reject malformed known fields. */
    private static function canonical_payload( $event_name, $payload ) {
        if ( ! is_array( $payload ) ) { return null; }
        if ( ! isset( $payload['schema_version'] ) || 1 !== $payload['schema_version'] ) { return null; }
        if ( ! isset( $payload['event'] ) || $event_name !== $payload['event'] ) { return null; }

        if ( 'booking.payment_update_attempted' === $event_name ) {
            if ( ! isset( $payload['booking_id'] ) || ! is_int( $payload['booking_id'] ) || $payload['booking_id'] < 1 ) { return null; }
            if ( ! isset( $payload['status'] ) || ! is_string( $payload['status'] ) ) { return null; }
            $payment_statuses = array( 'pending', 'succeeded', 'failed', 'cancelled', 'refunded' );
            if ( ! in_array( $payload['status'], $payment_statuses, true ) ) { return null; }
            return array(
                'schema_version' => 1,
                'event' => 'booking.payment_update_attempted',
                'booking_id' => $payload['booking_id'],
                'status' => $payload['status'],
            );
        }

        if ( 'booking.status_update_attempted' === $event_name ) {
            if ( ! isset( $payload['booking_id'] ) || ! is_int( $payload['booking_id'] ) || $payload['booking_id'] < 1 ) { return null; }
            if ( ! isset( $payload['status'] ) || ! is_string( $payload['status'] ) || strlen( $payload['status'] ) > 250 ) { return null; }
            if ( 1 !== preg_match( '//u', $payload['status'] ) || preg_match( '/[\x00-\x1F\x7F]/', $payload['status'] ) ) { return null; }
            return array(
                'schema_version' => 1,
                'event' => 'booking.status_update_attempted',
                'booking_id' => $payload['booking_id'],
                'status' => $payload['status'],
            );
        }

        if ( 'booking.created' !== $event_name ) { return null; }
        if ( ! isset( $payload['booking_id'] ) || ! is_int( $payload['booking_id'] ) || $payload['booking_id'] < 1 ) { return null; }
        if ( ! isset( $payload['form_id'] ) || ! is_int( $payload['form_id'] ) || $payload['form_id'] < 0 ) { return null; }
        if ( ! isset( $payload['form_name'] ) || ! is_string( $payload['form_name'] ) ) { return null; }
        if ( ! isset( $payload['appointments'] ) || ! is_array( $payload['appointments'] ) ) { return null; }

        $canonical = array(
            'schema_version' => 1,
            'event' => 'booking.created',
            'booking_id' => $payload['booking_id'],
            'form_id' => $payload['form_id'],
            'form_name' => $payload['form_name'],
            'appointments' => array(),
        );
        $appointment_keys = array( 'id', 'service_id', 'service_index', 'service', 'date', 'slot', 'duration', 'price', 'quantity', 'status' );

        foreach ( $payload['appointments'] as $appointment ) {
            if ( ! is_array( $appointment ) ) { return null; }
            foreach ( $appointment_keys as $tzfd_key ) {
                if ( ! array_key_exists( $tzfd_key, $appointment ) ) { return null; }
            }
            foreach ( array( 'id', 'service_index', 'duration', 'quantity' ) as $tzfd_key ) {
                if ( ! is_int( $appointment[ $tzfd_key ] ) ) { return null; }
            }
            foreach ( array( 'service_id', 'service', 'date', 'slot', 'status' ) as $tzfd_key ) {
                if ( ! is_string( $appointment[ $tzfd_key ] ) ) { return null; }
            }
            if ( ! is_int( $appointment['price'] ) && ! is_float( $appointment['price'] ) ) { return null; }
            if ( ! is_finite( (float) $appointment['price'] ) || $appointment['price'] < 0 ) { return null; }
            if ( $appointment['id'] < 0 || $appointment['duration'] < 0 || $appointment['quantity'] < 1 ) { return null; }

            $canonical_appointment = array();
            foreach ( $appointment_keys as $tzfd_key ) {
                $canonical_appointment[ $tzfd_key ] = $appointment[ $tzfd_key ];
            }
            $canonical['appointments'][] = $canonical_appointment;
        }

        return $canonical;
    }

    private static function event_name_from_body( $body ) {
        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) || ! isset( $payload['event'] ) || ! is_string( $payload['event'] ) ) { return null; }
        $canonical = self::canonical_payload( $payload['event'], $payload );
        if ( ! is_array( $canonical ) || $canonical !== $payload ) { return null; }
        // Enforce our exact encoder as well as the decoded shape: this rejects
        // duplicate JSON keys without rewriting the body or its idempotency key.
        if ( self::encode_payload( $canonical ) !== $body ) { return null; }
        return $payload['event'];
    }

    /** One wire encoder shared by enqueue and persisted-queue validation. */
    private static function encode_payload( $payload ) {
        return wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /** Validate the configured destination on enqueue and again before every request. */
    private static function get_config() {
        if ( ! function_exists( 'apply_filters' ) || ! function_exists( 'wp_http_validate_url' ) ) { return null; }

        $settings = apply_filters( 'titan_zero_front_desk_webhook_config', array() ); // Same value as self::CONFIG_FILTER; literal keeps the hook name checkable.
        if ( ! is_array( $settings ) || empty( $settings['url'] ) || ! isset( $settings['secret'] ) ) { return null; }
        if ( ! is_string( $settings['url'] ) || ! is_string( $settings['secret'] ) || strlen( $settings['secret'] ) < 32 ) { return null; }

        $url = trim( $settings['url'] );
        $parse = function_exists( 'wp_parse_url' ) ? 'wp_parse_url' : 'parse_url';
        $parts = $parse( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return null; }
        if ( 'https' !== strtolower( $parts['scheme'] ) ) { return null; }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return null; }

        $validated_url = wp_http_validate_url( $url );
        if ( ! is_string( $validated_url ) || '' === $validated_url ) { return null; }
        $validated_parts = $parse( $validated_url );
        if ( ! is_array( $validated_parts ) || empty( $validated_parts['scheme'] ) || 'https' !== strtolower( $validated_parts['scheme'] ) ) { return null; }

        return array( 'url' => $validated_url, 'secret' => $settings['secret'] );
    }

    private static function event_id( $body ) {
        return 'bbv1-' . hash( 'sha256', $body );
    }

    private static function is_transient_failure( $response ) {
        if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) { return true; }
        if ( ! is_array( $response ) || ! function_exists( 'wp_remote_retrieve_response_code' ) ) { return true; }

        $status = (int) wp_remote_retrieve_response_code( $response );
        return 0 === $status || 408 === $status || 425 === $status || 429 === $status || $status >= 500;
    }
}
