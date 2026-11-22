<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/** Record-level authorization for the existing administrator booking actions. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-access-list.php';
require_once __DIR__ . '/class-tzfd-scoped-store.php';

final class TZFD_Admin_Booking_Access {
    /**
     * Validate every target before the dispatcher starts a bulk operation.
     * The existing nonce checks remain mandatory and are not replaced here.
     * Reading a calendar screen does not authorize arbitrary booking IDs.
     */
    public static function authorize_request( $request, $selected_calendar ) {
        if ( ! is_array( $request ) ) { return false; }
        $action = '';
        foreach ( array( 'statusmark', 'resend', 'delmark', 'del', 'lu', 'ld', 'ud' ) as $tzfd_key ) {
            if ( ! isset( $request[ $tzfd_key ] ) || '' === $request[ $tzfd_key ] ) { continue; }
            if ( 'del' === $tzfd_key && 'all' !== $request[ $tzfd_key ] ) { continue; }
            $action = $tzfd_key;
            break;
        }
        if ( '' === $action ) { return true; } // Plain list rendering does not need record lookups.
        if ( ! is_scalar( $request[ $action ] ) || ! function_exists( 'get_current_user_id' ) ||
            ! function_exists( 'current_user_can' ) ) { return false; }
        $user_id = self::positive_id( get_current_user_id() );
        $calendar = ( 0 === $selected_calendar || '0' === $selected_calendar ) ? 0 : self::positive_id( $selected_calendar );
        // Do not let a malformed `cal` become zero through the screen's legacy
        // intval coercion: zero is the explicit, administrator-only ALL scope.
        if ( array_key_exists( 'cal', $request ) ) {
            $raw_calendar = $request['cal'];
            $is_all = 0 === $raw_calendar || '0' === $raw_calendar;
            $requested_calendar = $is_all ? 0 : self::positive_id( $raw_calendar );
            if ( ( ! $is_all && ! $requested_calendar ) || $requested_calendar !== $calendar ) { return false; }
        }
        $administrator = current_user_can( 'tz_fd_access' );
        $company = ( class_exists( 'TZFD_Scoped_Store' ) ) ? TZFD_Scoped_Store::company_id() : 0;
        if ( $company < 1 ) { return false; }
        if ( $calendar > 0 && TZFD_Scoped_Store::company_for_form( $calendar ) !== $company ) { return false; }
        if ( ! $user_id || ( 0 === $calendar && ! $administrator ) ) { return false; }

        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ||
            ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) ||
            1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) ) { return false; }
        $forms = $wpdb->prefix . TZFD_Tables::FORMS;
        if ( 'del' === $action ) {
            if ( 0 === $calendar ) { return $administrator; }
            $row = $wpdb->get_row( $wpdb->prepare("SELECT id, tz_fd_user_access FROM %i WHERE id=%d AND company_id=%d", $forms, $calendar, $company) );
            return empty( $wpdb->last_error ) && is_object( $row ) && isset( $row->id ) &&
                self::positive_id( $row->id ) === $calendar && self::calendar_permission( $row, $user_id, $administrator );
        }

        $ids = array();
        if ( in_array( $action, array( 'statusmark', 'delmark' ), true ) ) {
            for ( $tzfd_i = 0; $tzfd_i <= 50; $tzfd_i++ ) {
                if ( isset( $request[ 'c' . $tzfd_i ] ) && '' !== $request[ 'c' . $tzfd_i ] ) {
                    $ids[] = $request[ 'c' . $tzfd_i ];
                }
            }
        } else { $ids[] = $request[ $action ]; }

        $messages = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        foreach ( $ids as $raw_id ) {
            $id = self::positive_id( $raw_id );
            if ( ! $id ) { return false; }
            $row = $wpdb->get_row( $wpdb->prepare("SELECT b.id, b.formid, f.tz_fd_user_access FROM %i b INNER JOIN %i f ON f.id=b.formid AND f.company_id=%d WHERE b.id=%d AND b.company_id=%d", $messages, $forms, $company, $id, $company) );
            if ( ! empty( $wpdb->last_error ) || ! is_object( $row ) || ! isset( $row->id, $row->formid ) ||
                self::positive_id( $row->id ) !== $id || ! self::positive_id( $row->formid ) ||
                ( $calendar > 0 && self::positive_id( $row->formid ) !== $calendar ) ||
                ! self::calendar_permission( $row, $user_id, $administrator ) ) { return false; }
        }
        return true;
    }

    private static function calendar_permission( $row, $user_id, $administrator ) {
        if ( $administrator ) { return true; }
        $access = isset( $row->tz_fd_user_access ) ? TZFD_Access_List::decode( $row->tz_fd_user_access ) : array();
        return in_array( $user_id, $access, true );
    }

    /** Reject PHP's permissive coercion of partial strings, booleans and arrays. */
    private static function positive_id( $tzfd_value ) {
        if ( is_int( $tzfd_value ) ) { return $tzfd_value > 0 ? $tzfd_value : 0; }
        if ( ! is_string( $tzfd_value ) || 1 !== preg_match( '/^[0-9]+$/D', $tzfd_value ) ) { return 0; }
        $digits = ltrim( $tzfd_value, '0' );
        $id = filter_var( $digits, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $id ? 0 : $id;
    }
}
