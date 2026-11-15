<?php
/** Consistent bounded REST request validation for public and admin endpoints. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_REST_Request_Guard {
    /** Validate transport before reading parsed parameters or triggering remote services. */
    public static function check( $request, $method, $max_bytes, $max_fields = 40, $content_type = '' ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ||
             ! method_exists( $request, 'get_body' ) || ! method_exists( $request, 'get_header' ) ||
             ! method_exists( $request, 'get_params' ) || $request->get_method() !== $method ||
             ! is_int( $max_bytes ) || $max_bytes < 1 ||
             ! is_int( $max_fields ) || $max_fields < 1 ) { return false; }
        $raw = $request->get_body();
        if ( ! is_string( $raw ) || strlen( $raw ) > $max_bytes ) { return false; }
        $declared = $request->get_header( 'content-length' );
        if ( null !== $declared && '' !== $declared &&
             ( ! is_string( $declared ) || ! preg_match( '/^[0-9]{1,15}$/D', $declared ) ||
               (float) $declared > $max_bytes || (float) $declared !== (float) strlen( $raw ) ) ) { return false; }
        // Read-only routes must not receive an unexpected payload.
        if ( 'GET' === $method && '' !== $raw ) { return false; }
        if ( '' !== $content_type ) {
            $provided = $request->get_header( 'content-type' );
            if ( ! is_string( $provided ) ||
                 ! preg_match( '~^' . preg_quote( $content_type, '~' ) . '(?:\s*;|\s*$)~i', $provided ) ) { return false; }
        }
        $params = $request->get_params();
        if ( ! is_array( $params ) || count( $params ) > $max_fields ) { return false; }
        // WordPress parses nested JSON/form parameters before the route callback.
        // A shallow field-count limit alone leaves deeply nested arrays unchecked.
        $nodes = 0;
        $parsed_bytes = 0;
        // A tiny HTTP body can still accompany large parsed query parameters.
        // Bound the total parsed tree independently of its field count.
        return self::bounded_params( $params, 0, $nodes, 2000, $parsed_bytes, $max_bytes );
    }

    /** Bound nested parameter trees before endpoint-specific sanitisation. */
    private static function bounded_params( $value, $depth, &$nodes, $max_nodes, &$parsed_bytes, $max_bytes ) {
        if ( $depth > 12 || ++$nodes > $max_nodes ) { return false; }
        if ( is_array( $value ) ) {
            if ( count( $value ) > 500 ) { return false; }
            foreach ( $value as $key => $child ) {
                if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 128 ) ) { return false; }
                $parsed_bytes += is_string( $key ) ? strlen( $key ) : 8;
                if ( $parsed_bytes > $max_bytes || ! self::bounded_params( $child, $depth + 1, $nodes, $max_nodes, $parsed_bytes, $max_bytes ) ) { return false; }
            }
            return true;
        }
        // Never allow objects/resources or non-finite JSON numbers through the
        // shared boundary; individual endpoints enforce stricter value rules.
        if ( is_object( $value ) || is_resource( $value ) ) { return false; }
        if ( is_float( $value ) && ! is_finite( $value ) ) { return false; }
        if ( ! ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) ) { return false; }
        $parsed_bytes += is_string( $value ) ? strlen( $value ) : 16;
        return $parsed_bytes <= $max_bytes;
    }
}
