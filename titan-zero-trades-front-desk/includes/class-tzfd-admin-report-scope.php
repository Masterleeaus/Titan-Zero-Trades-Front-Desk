<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Tenant boundary for legacy Front Desk admin read/export screens. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-scoped-store.php';

final class TZFD_Admin_Report_Scope {
    public static function company_id() {
        return TZFD_Scoped_Store::company_id();
    }

    public static function authorized( $calendar = 0 ) {
        $company = self::company_id();
        $calendar = absint( $calendar );
        if ( ! is_admin() || ! is_user_logged_in() || $company < 1 ) { return false; }
        if ( $calendar > 0 ) {
            if ( TZFD_Scoped_Store::company_for_form( $calendar ) !== $company ) { return false; }
        }
        // All-company calendar views require the administrative capability.
        if ( 0 === $calendar ) { return current_user_can( 'tz_fd_access' ); }
        global $wpdb;
        // $calendar (> 0) and $company (>= 1) are validated integers above, so prepare() cannot fail here.
        $access = $wpdb->get_var( $wpdb->prepare(
            'SELECT tz_fd_user_access FROM %i WHERE id=%d AND company_id=%d',
            $wpdb->prefix . TZFD_Tables::FORMS, $calendar, $company
        ) );
        if ( ! empty( $wpdb->last_error ) ) { return false; }
        $users = class_exists( 'TZFD_Access_List' ) ? TZFD_Access_List::decode( $access ?: '' ) : array();
        return current_user_can( 'tz_fd_access' ) || in_array( get_current_user_id(), $users, true );
    }

    public static function enforce( $calendar = 0 ) {
        if ( ! self::authorized( $calendar ) ) {
            wp_die( esc_html__( 'You cannot access this company calendar.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        return self::company_id();
    }
}
