<?php
/** Strict scalar request parsing for Front Desk administrative actions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Request_Value {
    /** Reject arrays/objects instead of triggering warnings in WordPress sanitizers. */
    public static function text( $input, $fallback = '' ) {
        if ( ! is_string( $input ) && ! is_int( $input ) ) { return $fallback; }
        return sanitize_text_field( wp_unslash( (string) $input ) );
    }
    public static function key( $input, $fallback = '' ) {
        if ( ! is_string( $input ) && ! is_int( $input ) ) { return $fallback; }
        return sanitize_key( wp_unslash( (string) $input ) );
    }
    /** Reject negative, fractional, oversized and nonnumeric identifiers. */
    public static function id( $input ) {
        if ( ! is_string( $input ) && ! is_int( $input ) ) { return 0; }
        $str = (string) $input;
        if ( ! preg_match( '/^[0-9]{1,20}$/D', $str ) ) { return 0; }
        $id = filter_var( ltrim( $str, '0' ) ?: '0', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $id ? 0 : (int) $id;
    }
}
