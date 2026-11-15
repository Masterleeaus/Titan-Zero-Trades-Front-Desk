<?php
/** Validates legacy CSV-export date filters and calendar identifiers before database queries. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Export_Dates {
    /** Do not let a malformed calendar turn into zero, which means every calendar. */
    public static function calendar_id( $value ) {
        if ( null === $value || '' === $value ) { return 0; }
        if ( is_int( $value ) ) { return $value > 0 ? $value : null; }
        if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $value ) ) { return null; }
        $number = (int) $value;
        return $number > 0 && (string) $number === $value ? $number : null;
    }

    /** Supports both existing date-picker values and short relative expressions used by scheduled exports. */
    public static function date( $value ) {
        if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 80 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
            return null;
        }
        // PHP's date parser silently normalizes impossible ISO dates (e.g. February 30).
        if ( preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts ) &&
             ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) { return null; }
        $timestamp = strtotime( $value );
        if ( false === $timestamp ) { return null; }
        $date = gmdate( 'Y-m-d', $timestamp );
        return is_string( $date ) && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date ) ? $date : null;
    }

    /** A null boundary means no filter; explicit invalid dates fail closed. */
    public static function range( $start, $end ) {
        $from = null === $start ? null : self::date( $start );
        $to   = null === $end ? null : self::date( $end );
        if ( ( null !== $start && null === $from ) || ( null !== $end && null === $to ) ) { return null; }
        if ( null !== $from && null !== $to && strcmp( $from, $to ) > 0 ) { return null; }
        return array( $from, $to );
    }
}
