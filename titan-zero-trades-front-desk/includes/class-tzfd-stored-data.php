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
        $tz_value = self::parse( $serialized );
        $remaining = self::MAX_NODES;
        return is_array( $tz_value ) && self::safe_value( $tz_value, 0, $remaining ) ? $tz_value : null;
    }

    /**
     * Read-only projection of explicitly named fields. Unrelated legacy object
     * fields remain inert and are discarded, not restored, returned or rewritten.
     * Never use a projection to replace the full persisted booking record.
     */
    public static function project( $serialized, array $keys ) {
        $tz_value = self::parse( $serialized );
        if ( ! is_array( $tz_value ) ) { return null; }
        $result = array();
        foreach ( $keys as $tz_key ) {
            if ( ! is_string( $tz_key ) ) { return null; }
            if ( array_key_exists( $tz_key, $tz_value ) ) { $result[ $tz_key ] = $tz_value[ $tz_key ]; }
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
            $tz_value = unserialize( $serialized, array( 'allowed_classes' => false, 'max_depth' => self::MAX_DEPTH ) );
        } catch ( Throwable $error ) {
            return null;
        }
        return is_array( $tz_value ) ? $tz_value : null;
    }

    /** Validate the common booking envelope without changing valid field values. */
    public static function decode_booking( $serialized ) {
        $tz_data = self::decode( $serialized );
        if ( null === $tz_data ) { return null; }
        if ( array_key_exists( 'apps', $tz_data ) ) {
            if ( ! is_array( $tz_data['apps'] ) ) { return null; }
            foreach ( $tz_data['apps'] as $tz_app ) {
                if ( ! is_array( $tz_app ) ) { return null; }
                foreach ( array( 'date', 'slot', 'field', 'service', 'cancelled', 'cancelreason' ) as $field ) {
                    if ( isset( $tz_app[ $field ] ) && ! is_string( $tz_app[ $field ] ) ) { return null; }
                }
            }
        }
        return $tz_data;
    }

    /** Bound both depth and work, including repeated/cyclic serialized references. */
    private static function safe_value( $tz_value, $depth, &$remaining ) {
        if ( $depth > self::MAX_DEPTH || --$remaining < 0 ) { return false; }
        if ( is_array( $tz_value ) ) {
            foreach ( $tz_value as $child ) {
                if ( ! self::safe_value( $child, $depth + 1, $remaining ) ) { return false; }
            }
            return true;
        }
        return null === $tz_value || is_string( $tz_value ) || is_int( $tz_value ) || is_bool( $tz_value ) ||
            ( is_float( $tz_value ) && is_finite( $tz_value ) );
    }
}
