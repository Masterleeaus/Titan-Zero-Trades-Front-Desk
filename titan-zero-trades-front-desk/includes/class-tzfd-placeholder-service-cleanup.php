<?php
/**
 * One-time upgrade: remove the default placeholder service "Service 1" from existing calendars.
 * Only calendars whose only service is the untouched placeholder (name "Service 1", price 1, duration 60)
 * are changed, and only when the calendar has no bookings at all. Booking payloads reference services by
 * name or index, so "no bookings" is the only safe test. Calendars with bookings keep the placeholder.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Placeholder_Service_Cleanup {
    const OPTION  = 'tzfd_placeholder_service_cleanup';
    const VERSION = '1';

    public static function boot() {
        add_action( 'admin_init', array( __CLASS__, 'run' ), 5 );
    }

    /** Returns the calendar ids changed, kept (with bookings), or failed. Used by the runtime checks. */
    public static function run() {
        if ( ! current_user_can( 'manage_options' ) ) { return array(); }
        if ( get_option( self::OPTION ) === self::VERSION ) { return array(); }
        global $wpdb;
        $forms    = $wpdb->prefix . TZFD_Tables::FORMS;
        $bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT id, form_structure FROM %i', $forms ), ARRAY_A );
        if ( ! is_array( $rows ) ) { return array(); } // Query failed: do not mark the upgrade as done.

        $report = array( 'changed' => array(), 'kept' => array(), 'failed' => array() );
        foreach ( $rows as $row ) {
            $id        = (int) $row['id'];
            $structure = json_decode( (string) $row['form_structure'] );
            if ( ! is_array( $structure ) || ! isset( $structure[0] ) || ! is_array( $structure[0] ) ) { continue; }
            $changed = false;
            foreach ( $structure[0] as $field ) {
                if ( ! is_object( $field ) || ! isset( $field->ftype ) || 'fapp' !== $field->ftype ) { continue; }
                if ( ! self::is_placeholder_only( $field ) ) { continue; }
                $field->services = array();
                $changed = true;
            }
            if ( ! $changed ) { continue; }

            $used = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE formid = %d', $bookings, $id ) );
            if ( null === $used ) { $report['failed'][] = $id; continue; }
            if ( (int) $used > 0 ) { $report['kept'][] = $id; continue; }

            $json = wp_json_encode( $structure );
            if ( false === $json ) { $report['failed'][] = $id; continue; }
            $ok = $wpdb->update( $forms, array( 'form_structure' => $json ), array( 'id' => $id ) );
            if ( false === $ok ) { $report['failed'][] = $id; } else { $report['changed'][] = $id; }
        }

        if ( empty( $report['failed'] ) ) {
            update_option( self::OPTION, self::VERSION, false );
        }
        return $report;
    }

    /** True only for a booking field whose single service is the untouched default placeholder. */
    public static function is_placeholder_only( $field ) {
        if ( ! isset( $field->services ) || ! is_array( $field->services ) || 1 !== count( $field->services ) ) { return false; }
        $service = $field->services[0];
        return is_object( $service )
            && isset( $service->name, $service->price, $service->duration )
            && 'Service 1' === $service->name
            && 1 == $service->price
            && 60 == $service->duration;
    }
}
