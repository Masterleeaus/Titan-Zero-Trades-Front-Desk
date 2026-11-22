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

    public static function valid_date( $tzfd_date ) {
        if ( ! is_string( $tzfd_date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tzfd_date ) ) { return false; }
        $parts = explode( '-', $tzfd_date );
        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
    }

    /** Active (not cancelled) appointments of one calendar on one date: booking_id, email, date, slot. */
    public static function appointments_on( $formid, $tzfd_date, $limit = 5000 ) {
        global $wpdb;
        if ( ! self::valid_date( $tzfd_date ) || ! self::positive_id( $formid ) ) { return array(); }
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
                $tzfd_local_rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT id, notifyto, posted_data FROM %i WHERE formid = %d AND company_id = %d ORDER BY id DESC LIMIT %d',
                    $wpdb->prefix . self::TABLE, $form_id, $company_id, $limit
                ) );
            } else {
                $tzfd_local_rows = $wpdb->get_results( $wpdb->prepare(
                    'SELECT id, notifyto, posted_data FROM %i WHERE formid = %d AND company_id = %d AND id < %d ORDER BY id DESC LIMIT %d',
                    $wpdb->prefix . self::TABLE, $form_id, $company_id, $before_id, $limit
                ) );
            }
            if ( ! is_array( $tzfd_local_rows ) || ! empty( $wpdb->last_error ) ) {
                throw new RuntimeException( 'Booking availability could not be verified.' );
            }
            $next_before = null;
            foreach ( $tzfd_local_rows as $row ) {
                $row_id = isset( $row->id ) && self::positive_id( $row->id ) ? (int) $row->id : 0;
                // Refuse malformed or non-descending cursors rather than returning
                // partial availability counts as if the scan were complete.
                if ( $row_id < 1 || ( null !== $before_id && $row_id >= $before_id ) ||
                    ( null !== $next_before && $row_id >= $next_before ) ) { throw new RuntimeException( 'Booking availability scan was invalid.' ); }
                $next_before = $row_id;
                if ( ! isset( $row->posted_data ) || ! is_string( $row->posted_data ) ) { continue; }
                $tzfd_data = TZFD_Stored_Data::decode_booking( $row->posted_data );
                if ( ! is_array( $tzfd_data ) || empty( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) ) { continue; }
                foreach ( $tzfd_data['apps'] as $tzfd_app ) {
                    if ( ! is_array( $tzfd_app ) || ! empty( $tzfd_app['cancelled'] ) ) { continue; }
                    if ( ( $tzfd_app['date'] ?? '' ) !== $tzfd_date ) { continue; }
                    $slot = $tzfd_app['slot'] ?? '';
                    if ( ! is_string( $slot ) ) { continue; }
                    $found[] = array( 'booking_id' => $row_id, 'email' => is_string( $row->notifyto ?? null ) ? $row->notifyto : '', 'date' => $tzfd_date, 'slot' => $slot );
                }
            }
            if ( count( $tzfd_local_rows ) < $limit ) { break; }
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
    public static function count_on( $formid, $tzfd_date, $slot = null ) {
        $tzfd_local_count = 0;
        foreach ( self::appointments_on( $formid, $tzfd_date ) as $tzfd_app ) {
            if ( null === $slot || $tzfd_app['slot'] === $slot ) { $tzfd_local_count++; }
        }
        return $tzfd_local_count;
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
    public static function submission_slots( $tzfd_local_params ) {
        $out = array();
        if ( ! is_array( $tzfd_local_params ) || ! isset( $tzfd_local_params['apps'] ) || ! is_array( $tzfd_local_params['apps'] ) ) { return $out; }
        foreach ( $tzfd_local_params['apps'] as $tzfd_app ) {
            if ( ! is_array( $tzfd_app ) ) { continue; }
            $tzfd_date = $tzfd_app['date'] ?? null;
            $tzfd_slot = $tzfd_app['slot'] ?? '';
            if ( self::valid_date( $tzfd_date ) && is_string( $tzfd_slot ) ) { $out[] = array( 'date' => $tzfd_date, 'slot' => $tzfd_slot ); }
        }
        return $out;
    }
}
