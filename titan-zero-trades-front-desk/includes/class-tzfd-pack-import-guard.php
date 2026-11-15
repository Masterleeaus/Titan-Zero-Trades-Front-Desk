<?php
/** Structural and resource limits for imported industry-pack JSON. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Pack_Import_Guard {
    const MAX_BYTES = 524288;
    const MAX_NODES = 3000;
    const MAX_DEPTH = 8;
    /** Validate an uploaded pack before sanitisation, indexing or persistence. */
    public static function validate( $pack ) {
        if ( ! is_array( $pack ) || count( $pack ) > 40 ||
             ! isset( $pack['id'], $pack['name'] ) ||
             ! is_string( $pack['id'] ) || ! is_string( $pack['name'] ) ||
             ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $pack['id'] ) ||
             '' === trim( $pack['name'] ) || strlen( $pack['name'] ) > 150 ) { return false; }
        foreach ( array( 'kb' => 200, 'catalog' => 300, 'service_types' => 300, 'staff_skills' => 300, 'urgency_signals' => 10 ) as $key => $limit ) {
            if ( isset( $pack[ $key ] ) && ( ! is_array( $pack[ $key ] ) || count( $pack[ $key ] ) > $limit ) ) { return false; }
        }
        $nodes = 0;
        $bytes = 0;
        return self::walk( $pack, 0, $nodes, $bytes );
    }
    private static function walk( $value, $depth, &$nodes, &$bytes ) {
        if ( ++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH ) { return false; }
        if ( is_array( $value ) ) {
            if ( count( $value ) > 500 ) { return false; }
            foreach ( $value as $key => $child ) {
                if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 100 ) ) { return false; }
                $bytes += strlen( (string) $key );
                if ( $bytes > self::MAX_BYTES || ! self::walk( $child, $depth + 1, $nodes, $bytes ) ) { return false; }
            }
            return true;
        }
        if ( is_string( $value ) ) {
            if ( strlen( $value ) > 12000 ) { return false; }
            $bytes += strlen( $value );
            return $bytes <= self::MAX_BYTES && (bool) preg_match( '//u', $value );
        }
        if ( is_int( $value ) || is_bool( $value ) || null === $value ) { return true; }
        return is_float( $value ) && is_finite( $value );
    }
}
