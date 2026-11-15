<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Exhaustive booking-export reader. Never return a partial dataset as a valid export.
 * Uses stable ID keyset pagination rather than an arbitrary latest-record limit.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Booking_Export_Reader {
    // Exports must fail entirely rather than exhaust memory or return a partial CSV.
    const MAX_EXPORT_ROWS = 50000;
    const MAX_EXPORT_BYTES = 67108864;

    /**
     * @return array|null Matching rows or null when a database query/row fails.
     */
    public static function read( $company_id, $calendar_id, $upper_time, $blocked_email = null, $page_size = 250 ) {
        global $wpdb;
        if ( ! self::id( $company_id ) || ! is_int( $calendar_id ) || $calendar_id < 0 ||
             ! is_string( $upper_time ) || strlen( $upper_time ) > 32 ||
             ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?: [0-9]{2}:[0-9]{2}:[0-9]{2})?$/D', $upper_time ) ||
             ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ||
             ( null !== $blocked_email && ( ! is_string( $blocked_email ) || strlen( $blocked_email ) > 320 ) ) ) { return null; }
        $page_size = is_int( $page_size ) ? max( 1, min( 500, $page_size ) ) : 250;
        $table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $cursor = PHP_INT_MAX;
        $rows = array();
        $total_bytes = 0;
        do {
            // Each optional filter selects one fixed literal statement, prepared inline, so the
            // SQL never carries concatenated fragments. Only values are bound through placeholders.
            $wpdb->last_error = '';
            if ( $calendar_id > 0 && null !== $blocked_email ) {
                $page = $wpdb->get_results( $wpdb->prepare( 'SELECT id, time, notifyto, posted_data, data, ipaddr FROM %i WHERE company_id = %d AND time <= %s AND formid = %d AND notifyto <> %s AND id < %d ORDER BY id DESC LIMIT %d', $table, $company_id, $upper_time, $calendar_id, $blocked_email, $cursor, $page_size ) );
            } elseif ( $calendar_id > 0 ) {
                $page = $wpdb->get_results( $wpdb->prepare( 'SELECT id, time, notifyto, posted_data, data, ipaddr FROM %i WHERE company_id = %d AND time <= %s AND formid = %d AND id < %d ORDER BY id DESC LIMIT %d', $table, $company_id, $upper_time, $calendar_id, $cursor, $page_size ) );
            } elseif ( null !== $blocked_email ) {
                $page = $wpdb->get_results( $wpdb->prepare( 'SELECT id, time, notifyto, posted_data, data, ipaddr FROM %i WHERE company_id = %d AND time <= %s AND notifyto <> %s AND id < %d ORDER BY id DESC LIMIT %d', $table, $company_id, $upper_time, $blocked_email, $cursor, $page_size ) );
            } else {
                $page = $wpdb->get_results( $wpdb->prepare( 'SELECT id, time, notifyto, posted_data, data, ipaddr FROM %i WHERE company_id = %d AND time <= %s AND id < %d ORDER BY id DESC LIMIT %d', $table, $company_id, $upper_time, $cursor, $page_size ) );
            }
            if ( ! is_array( $page ) || ! empty( $wpdb->last_error ) ) { return null; }
            if ( count( $rows ) + count( $page ) > self::MAX_EXPORT_ROWS ) { return null; }
            foreach ( $page as $row ) {
                if ( ! is_object( $row ) || ! isset( $row->id, $row->posted_data ) ||
                     ! self::id( $row->id ) || (int) $row->id >= $cursor ||
                     ! is_string( $row->posted_data ) ) { return null; }
                // Bound storage before appending rows. Legacy rows may contain huge blobs.
                foreach ( array( 'posted_data', 'data', 'notifyto', 'time', 'ipaddr' ) as $field ) {
                    if ( isset( $row->$field ) ) {
                        if ( ! is_string( $row->$field ) ) { return null; }
                        $total_bytes += strlen( $row->$field );
                    }
                }
                if ( $total_bytes > self::MAX_EXPORT_BYTES ) { return null; }
                $cursor = (int) $row->id;
                $rows[] = $row;
            }
        } while ( count( $page ) === $page_size );
        return $rows;
    }

    private static function id( $value ) {
        return ( is_int( $value ) && $value > 0 ) ||
            ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) &&
              false !== filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) );
    }
}
