<?php
/** Strict, read-only input parsing for booking and analytics admin screens. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-export-dates.php';

final class TZFD_Admin_Filter_Input {
    /** Scalar GET parameters only: arrays, objects and excessively long values are rejected. */
    public static function text( $input, $key, $max_length = 200 ) {
        if ( ! is_array( $input ) || ! array_key_exists( $key, $input ) ) { return ''; }
        $value = $input[ $key ];
        if ( ! is_string( $value ) && ! is_int( $value ) ) { return null; }
        $value = (string) $value;
        if ( strlen( $value ) > $max_length || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) { return null; }
        return sanitize_text_field( wp_unslash( $value ) );
    }

    /** Returns an inclusive range or null for invalid parameters. */
    public static function range( $input, $datepicker_format = '' ) {
        $from = self::text( $input, 'dfrom', 80 );
        $to = self::text( $input, 'dto', 80 );
        if ( null === $from || null === $to ) { return null; }
        if ( 'dd/mm/yy' === $datepicker_format ) {
            $from = str_replace( '/', '.', $from );
            $to = str_replace( '/', '.', $to );
        }
        return TZFD_Export_Dates::range( '' === $from ? null : $from, '' === $to ? null : $to );
    }

    public static function enforce( $input, $datepicker_format = '' ) {
        $range = self::range( $input, $datepicker_format );
        if ( null === $range ) {
            wp_die( esc_html__( 'Invalid report filter parameters.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        return $range;
    }
}
