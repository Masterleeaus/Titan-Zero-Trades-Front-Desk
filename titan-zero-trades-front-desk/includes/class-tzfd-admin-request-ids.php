<?php
/** Strict integer request parameters for Front Desk administrative screens. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Admin_Request_Ids {
    /** Return null for malformed requests; absence of cal means explicit default scope. */
    public static function calendar( $query ) {
        return self::parse( $query, 'cal', 0, 0, PHP_INT_MAX );
    }

    /** A required identifier must be a scalar, strictly positive decimal integer. */
    public static function positive( $raw ) {
        if ( ! is_string( $raw ) && ! is_int( $raw ) ) { return null; }
        if ( is_int( $raw ) ) { return $raw > 0 ? $raw : null; }
        if ( '' === $raw || strlen( $raw ) > 19 || ! preg_match( '/^[0-9]+$/D', $raw ) ) { return null; }
        $normalized = ltrim( $raw, '0' );
        if ( '' === $normalized ) { return null; }
        $id = filter_var( $normalized, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $id ? null : $id;
    }

    /** Page 1 is the default. Reject extreme offsets and partial integers. */
    public static function page( $query ) {
        return self::parse( $query, 'p', 1, 1, 100000 );
    }

    /** Reads only the one named key, so other request fields never reach the parser. */
    private static function parse( $query, $key, $default, $minimum, $maximum ) {
        if ( ! is_array( $query ) ) { return null; }
        if ( ! array_key_exists( $key, $query ) ) { return $default; }
        $raw = $query[ $key ];
        if ( is_string( $raw ) ) { $raw = wp_unslash( $raw ); }
        if ( ! is_string( $raw ) && ! is_int( $raw ) ) { return null; }
        if ( is_string( $raw ) && ( '' === $raw || ! preg_match( '/^[0-9]+$/D', $raw ) || strlen( $raw ) > 19 ) ) { return null; }
        if ( is_int( $raw ) && $raw < 0 ) { return null; }
        // Normalize decimal leading zeros; FILTER_VALIDATE_INT treats them as invalid octal-like input.
        if ( is_string( $raw ) ) {
            $raw = ltrim( $raw, '0' );
            $raw = '' === $raw ? '0' : $raw;
        }
        $value = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => $minimum, 'max_range' => $maximum ) ) );
        return false === $value ? null : $value;
    }
}
