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
    const TABLE = TZFD_Tables::BOOKINGS;

    public static function valid_date( $tz_date ) {
        if ( ! is_string( $tz_date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tz_date ) ) { return false; }
        $parts = explode( '-', $tz_date );
        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
    }

    /** Active (not cancelled) appointments of one calendar on one date: booking_id, email, date, slot. */
    public static function appointments_on( $formid, $tz_date, $limit = 5000 ) {
        global $wpdb;
        if ( ! self::valid_date( $tz_date ) || ! self::positive_id( $formid ) ) { return array(); }
        $form_id = (int) $formid;
        $limit   = is_int( $limit ) && $limit > 0 ? min( 20000, $limit ) : 5000;

        // A public booking rule may execute without a logged-in Workspace company.
        // Resolve the owner from the authoritative calendar row, never caller input.
        $wpdb->last_error = '';
        $owner = $wpdb->get_var( $wpdb->prepare(
            'SELECT company_id FROM %i WHERE id = %d LIMIT 1',
            $wpdb->prefix . TZFD_Tables::FORMS,
            $form_id
        ) );
        if ( ! self::positive_id( $owner ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Booking availability could not be verified.' ); }
        $company_id = (int) $owner;
        // In an interactive company context, a foreign form must fail closed.
        if ( class_exists( 'TZFD_Scoped_Store' ) && is_callable( array( 'TZFD_Scoped_Store', 'company_id' ) ) ) {
            $active_company = TZFD_Scoped_Store::company_id();
            if ( self::positive_id( $active_company ) && (int) $active_company !== $company_id ) { return array(); }
        }
        // The batch size limits each SQL read, not the age of bookings examined.
        // A single LIMIT over the newest records silently misses earlier active
        // appointments once a calendar has more than that many submissions.
        $found = array();
        $before_id = null;
        do {
            $wpdb->last_error = '';
            if ( null === $before_id ) {
                $tz_rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT id, notifyto, posted_data FROM %i WHERE formid = %d AND company_id = %d ORDER BY id DESC LIMIT %d',
                    $wpdb->prefix . self::TABLE, $form_id, $company_id, $limit
                ) );
            } else {
                $tz_rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT id, notifyto, posted_data FROM %i WHERE formid = %d AND company_id = %d AND id < %d ORDER BY id DESC LIMIT %d',
                    $wpdb->prefix . self::TABLE, $form_id, $company_id, $before_id, $limit
                ) );
            }
            if ( ! is_array( $tz_rows ) || ! empty( $wpdb->last_error ) ) {
                throw new RuntimeException( 'Booking availability could not be verified.' );
            }
            $next_before = null;
            foreach ( $tz_rows as $row ) {
                $row_id = isset( $row->id ) && self::positive_id( $row->id ) ? (int) $row->id : 0;
                // Refuse malformed or non-descending cursors rather than returning
                // partial availability counts as if the scan were complete.
                if ( $row_id < 1 || ( null !== $before_id && $row_id >= $before_id ) ||
                    ( null !== $next_before && $row_id >= $next_before ) ) { throw new RuntimeException( 'Booking availability scan was invalid.' ); }
                $next_before = $row_id;
                if ( ! isset( $row->posted_data ) || ! is_string( $row->posted_data ) ) { continue; }
                $tz_data = TZFD_Stored_Data::decode_booking( $row->posted_data );
                if ( ! is_array( $tz_data ) || empty( $tz_data['apps'] ) || ! is_array( $tz_data['apps'] ) ) { continue; }
                foreach ( $tz_data['apps'] as $tz_app ) {
                    if ( ! is_array( $tz_app ) || ! empty( $tz_app['cancelled'] ) ) { continue; }
                    if ( ( $tz_app['date'] ?? '' ) !== $tz_date ) { continue; }
                    $slot = $tz_app['slot'] ?? '';
                    if ( ! is_string( $slot ) ) { continue; }
                    $found[] = array( 'booking_id' => $row_id, 'email' => is_string( $row->notifyto ?? null ) ? $row->notifyto : '', 'date' => $tz_date, 'slot' => $slot );
                }
            }
            if ( count( $tz_rows ) < $limit ) { break; }
            if ( null === $next_before || ( null !== $before_id && $next_before >= $before_id ) ) { throw new RuntimeException( 'Booking availability pagination failed.' ); }
            $before_id = $next_before;
        } while ( true );
        return $found;
    }

    /** Strict positive identifier: never turn a malformed identifier into a valid calendar. */
    private static function positive_id( $value ) {
        return ( is_int( $value ) && $value > 0 ) ||
            ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) &&
                filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) !== false );
    }

    /** Number of active appointments on a date, optionally for one slot. */
    public static function count_on( $formid, $tz_date, $slot = null ) {
        $tz_count = 0;
        foreach ( self::appointments_on( $formid, $tz_date ) as $tz_app ) {
            if ( null === $slot || $tz_app['slot'] === $slot ) { $tz_count++; }
        }
        return $tz_count;
    }

    /** Sorted unique slots that have at least one active appointment on a date. */
    public static function booked_slots( $formid, $tz_date ) {
        $slots = array();
        foreach ( self::appointments_on( $formid, $tz_date ) as $tz_app ) {
            if ( '' !== $tz_app['slot'] ) { $slots[ $tz_app['slot'] ] = true; }
        }
        $slots = array_keys( $slots );
        sort( $slots );
        return $slots;
    }

    /** The date and slot pairs a submission would book, from its apps. */
    public static function submission_slots( $tz_params ) {
        $out = array();
        if ( ! is_array( $tz_params ) || ! isset( $tz_params['apps'] ) || ! is_array( $tz_params['apps'] ) ) { return $out; }
        foreach ( $tz_params['apps'] as $tz_app ) {
            if ( ! is_array( $tz_app ) ) { continue; }
            $tz_date = $tz_app['date'] ?? null;
            $tz_slot = $tz_app['slot'] ?? '';
            if ( self::valid_date( $tz_date ) && is_string( $tz_slot ) ) { $out[] = array( 'date' => $tz_date, 'slot' => $tz_slot ); }
        }
        return $out;
    }
}
