<?php
/** Validate booking-list mutation parameters before entering the legacy dispatcher. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Admin_Mutation_Request {
    private const ACTIONS = array( 'statusmark', 'resend', 'delmark', 'del', 'ld', 'ud', 'lu' );

    public static function validate( $request ) {
        if ( ! is_array( $request ) ) { return false; }
        $actions = array();
        foreach ( self::ACTIONS as $key ) {
            if ( ! array_key_exists( $key, $request ) ) { continue; }
            $value = $request[ $key ];
            if ( ! is_string( $value ) && ! is_int( $value ) ) { return false; }
            if ( '' === (string) $value ) { continue; }
            if ( 'del' === $key ) {
                if ( 'all' !== $value ) { return false; }
            } elseif ( in_array( $key, array( 'resend', 'ld', 'ud', 'lu' ), true ) ) {
                if ( ! self::positive_id( $value ) ) { return false; }
            } elseif ( '1' !== (string) $value ) {
                return false;
            }
            $actions[] = $key;
        }
        if ( count( $actions ) > 1 ) { return false; }
        if ( ! $actions ) { return true; }
        // A nonce alone never authorises a GET or a logged-out mutation.
        // The calling booking-list screen also checks per-calendar delegated staff ACLs;
        // do not impose an administrator-only capability here and break those roles.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- method compared exactly to 'POST'; a sanitizer could strip %xx and change the result.
        if ( 'POST' !== wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
             ! function_exists( 'is_admin' ) || ! is_admin() ||
             ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) { return false; }
        // The nonce is a CSRF control, not a substitute for per-record capabilities.
        if ( ! isset( $request['anonce'] ) || ! is_string( $request['anonce'] ) ||
            ! function_exists( 'wp_verify_nonce' ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $request['anonce'] ) ), 'tz_fd_actions_booking' ) ) { return false; }
        foreach ( array( 'status', 'sbmi', 'or' ) as $key ) {
            if ( isset( $request[ $key ] ) && ! is_string( $request[ $key ] ) ) { return false; }
        }
        if ( isset( $request['udidx'] ) && ! self::nonnegative_id( $request['udidx'] ) ) { return false; }
        $is_bulk = in_array( $actions[0], array( 'statusmark', 'delmark' ), true );
        foreach ( $request as $key => $value ) {
            if ( 1 !== preg_match( '/^c([0-9]+)$/D', (string) $key, $match ) ) { continue; }
            if ( (int) $match[1] > 50 || ! $is_bulk || ! self::positive_id( $value ) ) { return false; }
        }
        if ( 'statusmark' === $actions[0] && ( ! isset( $request['sbmi'] ) ||
            ! is_string( $request['sbmi'] ) || '' === $request['sbmi'] ) ) { return false; }
        if ( 'ud' === $actions[0] && ( ! isset( $request['status'] ) ||
            ! is_string( $request['status'] ) || '' === $request['status'] ) ) { return false; }
        return true;
    }

    private static function nonnegative_id( $raw ) {
        if ( ! is_int( $raw ) && ! is_string( $raw ) ) { return false; }
        return ( 1 === preg_match( '/^[0-9]{1,19}$/D', (string) $raw ) ) &&
            ( (int) $raw === 0 || self::positive_id( $raw ) );
    }

    private static function positive_id( $raw ) {
        if ( ! is_int( $raw ) && ! is_string( $raw ) ) { return false; }
        if ( 1 !== preg_match( '/^[0-9]{1,19}$/D', (string) $raw ) ) { return false; }
        $normalized = ltrim( (string) $raw, '0' );
        if ( '' === $normalized ) { return false; }
        return false !== filter_var( $normalized, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
    }
}
