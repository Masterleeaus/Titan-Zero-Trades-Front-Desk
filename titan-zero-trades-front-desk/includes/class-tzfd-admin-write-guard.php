<?php
/** Bounded, method-restricted administrative write requests. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Admin_Write_Guard {
    /** Avoid processing oversized/structurally invalid POST bodies before nonce validation. */
    public static function valid( $method, $post, $content_length = null, $max_fields = 2000, $max_bytes = 16777216 ) {
        if ( 'POST' !== $method || ! is_array( $post ) ||
             ! is_int( $max_fields ) || $max_fields < 1 || $max_fields > 100000 ||
             ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > 67108864 ||
             count( $post ) > $max_fields ) { return false; }
        if ( null !== $content_length && ( ! is_string( $content_length ) && ! is_int( $content_length ) ||
            ! preg_match( '/^[0-9]{1,20}$/D', (string) $content_length ) ||
            (float) $content_length > $max_bytes ) ) { return false; }
        $nodes = 0;
        $bytes = 0;
        return self::bounded( $post, 0, $nodes, $max_fields * 4, $bytes, $max_bytes );
    }
    private static function bounded( $values, $depth, &$nodes, $max_nodes, &$bytes, $max_bytes ) {
        if ( $depth > 8 || ! is_array( $values ) ) { return false; }
        foreach ( $values as $key => $value ) {
            ++$nodes;
            $bytes += is_string( $key ) ? strlen( $key ) : strlen( (string) $key );
            if ( $bytes > $max_bytes ) { return false; }
            if ( $nodes > $max_nodes || ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 200 ) ) ) { return false; }
            if ( is_array( $value ) ) {
                if ( ! self::bounded( $value, $depth + 1, $nodes, $max_nodes, $bytes, $max_bytes ) ) { return false; }
            } elseif ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) && ! is_bool( $value ) ) {
                return false;
            } else {
                if ( is_float( $value ) && ! is_finite( $value ) ) { return false; }
                $bytes += strlen( (string) $value );
                if ( $bytes > $max_bytes ) { return false; }
            }
        }
        return true;
    }
    public static function enforce( $max_fields = 2000, $max_bytes = 16777216 ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- structure and size check only; the method is compared exactly, and no field value is used or written here.
        if ( ! self::valid( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ), $_POST,
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- size is checked strictly (digits only) by valid(); a sanitizer could change which values pass.
            wp_unslash( $_SERVER['CONTENT_LENGTH'] ?? null ), $max_fields, $max_bytes ) ) {
            wp_die( esc_html__( 'Invalid or oversized administrative request.', 'titan-zero-trades-front-desk' ),
                '', array( 'response' => 400 ) );
        }
    }
}
