<?php
/** Hardened public calendar-feed result checks. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Public_Slot_Records {
    /** Only a successful, complete database result may drive public availability. */
    public static function valid_query_result( $rows, $last_error ) {
        if ( ! is_array( $rows ) || ! is_string( $last_error ) || '' !== $last_error ) { return false; }
        foreach ( $rows as $row ) {
            if ( ! is_object( $row ) || ! isset( $row->posted_data ) || ! is_string( $row->posted_data ) ) {
                return false;
            }
        }
        return true;
    }
    /** An unreadable booking cannot be treated as an empty slot inventory. */
    public static function valid_booking_payload( $payload ) {
        if ( ! is_array( $payload ) || ! isset( $payload['apps'] ) || ! is_array( $payload['apps'] ) ) { return false; }
        foreach ( $payload['apps'] as $appointment ) {
            if ( ! self::valid_appointment( $appointment ) ) { return false; }
        }
        return true;
    }
    /** A historical record with missing slot metadata must not reach slot construction. */
    public static function valid_appointment( $appointment ) {
        if ( ! is_array( $appointment ) || ! isset( $appointment['date'], $appointment['slot'] ) ||
            ! is_string( $appointment['date'] ) || ! is_string( $appointment['slot'] ) ) { return false; }
        $date = $appointment['date'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ) { return false; }
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
        if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) { return false; }
        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d[\/-]([01]\d|2[0-3]):[0-5]\d$/D', $appointment['slot'] ) ) { return false; }
        $start = substr( $appointment['slot'], 0, 5 );
        $end = substr( $appointment['slot'], 6, 5 );
        if ( $end <= $start ) { return false; }
        foreach ( array( 'field', 'cancelled' ) as $key ) {
            if ( isset( $appointment[$key] ) && ! is_string( $appointment[$key] ) ) { return false; }
        }
        foreach ( array( 'quant', 'serviceindex', 'sid' ) as $key ) {
            if ( isset( $appointment[$key] ) && ! is_scalar( $appointment[$key] ) ) { return false; }
        }
        return true;
    }
}
