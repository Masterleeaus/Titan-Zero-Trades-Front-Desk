<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Reads all calendar reservations for a capacity decision, newest first.
 * Database failures are not equivalent to an empty calendar.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Booking_Capacity_Reader {
    /**
     * @return array|null Array of stored booking rows, or null on query failure.
     */
    public static function all_for_calendar( $calendar_id, $company_id, $page_size = 250 ) {
        global $wpdb;
        $calendar_id = self::id( $calendar_id );
        $company_id = self::id( $company_id );
        if ( ! $calendar_id || ! $company_id || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
            return null;
        }
        $page_size = is_int( $page_size ) ? max( 1, min( 500, $page_size ) ) : 250;
        $table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $cursor = PHP_INT_MAX;
        $seen = array();
        $rows = array();
        do {
            // WordPress retains last_error from an earlier query on some drivers.
            // Clear it before this read, so only errors from this query fail the scan.
            $wpdb->last_error = '';
            $page = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, posted_data FROM %i WHERE formid=%d AND company_id=%d AND id<%d ORDER BY id DESC LIMIT %d',
                $table, $calendar_id, $company_id, $cursor, $page_size
            ) );
            if ( ! is_array( $page ) || ! empty( $wpdb->last_error ) ) {
                return null;
            }
            $count = count( $page );
            foreach ( $page as $row ) {
                if ( ! is_object( $row ) || ! isset( $row->id, $row->posted_data ) ) { return null; }
                $id = self::id( $row->id );
                // A bad or non-descending page could otherwise repeat forever or omit records.
                if ( ! $id || $id >= $cursor || isset( $seen[$id] ) ) { return null; }
                $seen[$id] = true;
                $cursor = $id;
                $rows[] = $row;
            }
        } while ( $count === $page_size );
        return $rows;
    }

    private static function id( $value ) {
        if ( is_int( $value ) ) { return $value > 0 ? $value : null; }
        if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{1,19}$/D', $value ) ) { return null; }
        $id = filter_var( ltrim( $value, '0' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $id ? null : $id;
    }
}
