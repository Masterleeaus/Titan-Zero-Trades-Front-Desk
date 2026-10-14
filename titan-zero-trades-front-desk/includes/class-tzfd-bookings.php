<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-stored-data.php';

/**
 * Read-only appointment lookups over the bookings table, for the booking-rule add-ons.
 * Cancelled appointments are never counted. Dates are Y-m-d and slots are "HH:MM/HH:MM",
 * matching how bookings are stored. Nothing here returns customer names.
 */
final class TZFD_Bookings {
    const TABLE = 'tzfd_messages';

    public static function valid_date( $tzfd_date ) {
        if ( ! is_string( $tzfd_date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tzfd_date ) ) { return false; }
        $parts = explode( '-', $tzfd_date );
        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
    }

    /** Active (not cancelled) appointments of one calendar on one date: booking_id, email, date, slot. */
    public static function appointments_on( $formid, $tzfd_date, $limit = 5000 ) {
        global $wpdb;
        if ( ! self::valid_date( $tzfd_date ) ) { return array(); }
        $tzfd_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, notifyto, posted_data FROM %i WHERE formid = %d ORDER BY id DESC LIMIT %d",
            $wpdb->prefix . self::TABLE, (int) $formid, max( 1, min( 20000, (int) $limit ) )
        ) );
        $found = array();
        foreach ( (array) $tzfd_rows as $row ) {
            $tzfd_data = TZFD_Stored_Data::decode_booking( (string) $row->posted_data );
            if ( ! is_array( $tzfd_data ) || empty( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) ) { continue; }
            foreach ( $tzfd_data['apps'] as $tzfd_app ) {
                if ( ! is_array( $tzfd_app ) || ! empty( $tzfd_app['cancelled'] ) ) { continue; }
                if ( ( $tzfd_app['date'] ?? '' ) !== $tzfd_date ) { continue; }
                $found[] = array( 'booking_id' => (int) $row->id, 'email' => (string) $row->notifyto, 'date' => $tzfd_date, 'slot' => (string) ( $tzfd_app['slot'] ?? '' ) );
            }
        }
        return $found;
    }

    /** Number of active appointments on a date, optionally for one slot. */
    public static function count_on( $formid, $tzfd_date, $slot = null ) {
        $tzfd_count = 0;
        foreach ( self::appointments_on( $formid, $tzfd_date ) as $tzfd_app ) {
            if ( null === $slot || $tzfd_app['slot'] === $slot ) { $tzfd_count++; }
        }
        return $tzfd_count;
    }

    /** Sorted unique slots that have at least one active appointment on a date. */
    public static function booked_slots( $formid, $tzfd_date ) {
        $slots = array();
        foreach ( self::appointments_on( $formid, $tzfd_date ) as $tzfd_app ) {
            if ( '' !== $tzfd_app['slot'] ) { $slots[ $tzfd_app['slot'] ] = true; }
        }
        $slots = array_keys( $slots );
        sort( $slots );
        return $slots;
    }

    /** The date and slot pairs a submission would book, from its apps. */
    public static function submission_slots( $tzfd_params ) {
        $out = array();
        foreach ( (array) ( $tzfd_params['apps'] ?? array() ) as $tzfd_app ) {
            if ( ! is_array( $tzfd_app ) ) { continue; }
            $tzfd_date = (string) ( $tzfd_app['date'] ?? '' );
            if ( self::valid_date( $tzfd_date ) ) { $out[] = array( 'date' => $tzfd_date, 'slot' => (string) ( $tzfd_app['slot'] ?? '' ) ); }
        }
        return $out;
    }
}
