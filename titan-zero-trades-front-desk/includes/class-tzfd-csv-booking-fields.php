<?php
/** Defensive decoding of optional historic booking status fields for CSV export. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_CSV_Booking_Fields {
    /** Return a known status, or empty for missing or corrupt appointment payloads. */
    public static function appointment_status( $booking, $field ) {
        if ( ! is_string( $field ) || ! preg_match( '/^app_status_([1-9][0-9]*)$/D', $field, $matches ) ) { return ''; }
        if ( ! is_array( $booking ) || ! isset( $booking['apps'] ) || ! is_array( $booking['apps'] ) ) { return ''; }
        $index = (int) $matches[1] - 1;
        if ( ! isset( $booking['apps'][ $index ] ) || ! is_array( $booking['apps'][ $index ] ) ) { return ''; }
        $status = $booking['apps'][ $index ]['cancelled'] ?? '';
        return is_scalar( $status ) ? (string) $status : '';
    }

    /** WordPress get_results returns null on error and an array on success. */
    public static function valid_query_result( $rows ) {
        if ( ! is_array( $rows ) ) { return false; }
        foreach ( $rows as $row ) { if ( ! is_object( $row ) ) { return false; } }
        return true;
    }
}
