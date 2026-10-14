<?php
/** Bounded decoder for the inherited, database-only array storage format. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Stored_Data {
    const MAX_BYTES = 1048576;
    const MAX_DEPTH = 32;
    const MAX_NODES = 10000;

    /**
     * Read an existing legacy record without restoring executable PHP objects.
     * This is not a request-body parser. New external APIs must use JSON instead.
     * Returns null for malformed/oversized data; callers must not treat that as
     * an empty calendar or overwrite the original stored record.
     *
     * @param mixed $serialized Existing database value.
     * @return array|null
     */
    public static function decode( $serialized ) {
        $tzfd_value = self::parse( $serialized );
        $remaining = self::MAX_NODES;
        return is_array( $tzfd_value ) && self::safe_value( $tzfd_value, 0, $remaining ) ? $tzfd_value : null;
    }

    /**
     * Read-only projection of explicitly named fields. Unrelated legacy object
     * fields remain inert and are discarded, not restored, returned or rewritten.
     * Never use a projection to replace the full persisted booking record.
     */
    public static function project( $serialized, array $keys ) {
        $tzfd_value = self::parse( $serialized );
        if ( ! is_array( $tzfd_value ) ) { return null; }
        $result = array();
        foreach ( $keys as $tzfd_key ) {
            if ( ! is_string( $tzfd_key ) ) { return null; }
            if ( array_key_exists( $tzfd_key, $tzfd_value ) ) { $result[ $tzfd_key ] = $tzfd_value[ $tzfd_key ]; }
        }
        $remaining = self::MAX_NODES;
        return self::safe_value( $result, 0, $remaining ) ? $result : null;
    }

    private static function parse( $serialized ) {
        if ( ! is_string( $serialized ) || '' === $serialized || strlen( $serialized ) > self::MAX_BYTES ) {
            return null;
        }
        if ( 1 !== preg_match( '/^a:[0-9]+:\{/', $serialized ) ) { return null; }
        try {
            $tzfd_value = @unserialize( $serialized, array( 'allowed_classes' => false, 'max_depth' => self::MAX_DEPTH ) );
        } catch ( Throwable $error ) {
            return null;
        }
        return is_array( $tzfd_value ) ? $tzfd_value : null;
    }

    /** Validate the common booking envelope without changing valid field values. */
    public static function decode_booking( $serialized ) {
        $tzfd_data = self::decode( $serialized );
        if ( null === $tzfd_data ) { return null; }
        if ( array_key_exists( 'apps', $tzfd_data ) ) {
            if ( ! is_array( $tzfd_data['apps'] ) ) { return null; }
            foreach ( $tzfd_data['apps'] as $tzfd_app ) {
                if ( ! is_array( $tzfd_app ) ) { return null; }
                foreach ( array( 'date', 'slot', 'field', 'service', 'cancelled', 'cancelreason' ) as $field ) {
                    if ( isset( $tzfd_app[ $field ] ) && ! is_string( $tzfd_app[ $field ] ) ) { return null; }
                }
            }
        }
        return $tzfd_data;
    }

    /** Bound both depth and work, including repeated/cyclic serialized references. */
    private static function safe_value( $tzfd_value, $depth, &$remaining ) {
        if ( $depth > self::MAX_DEPTH || --$remaining < 0 ) { return false; }
        if ( is_array( $tzfd_value ) ) {
            foreach ( $tzfd_value as $child ) {
                if ( ! self::safe_value( $child, $depth + 1, $remaining ) ) { return false; }
            }
            return true;
        }
        return null === $tzfd_value || is_string( $tzfd_value ) || is_int( $tzfd_value ) || is_bool( $tzfd_value ) ||
            ( is_float( $tzfd_value ) && is_finite( $tzfd_value ) );
    }
}
