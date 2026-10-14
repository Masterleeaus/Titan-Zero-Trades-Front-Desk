<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-resource-catalog.php';

/**
 * Address and location for each service. Kept in their own option, not inside the calendar's
 * settings, so saving a calendar never overwrites them. Entries are keyed by calendar ID and
 * service name: renaming a service in its calendar starts its address again.
 */
final class TZFD_Service_Details {
    const OPTION      = 'titan_zero_front_desk_service_details_v1';
    const CATALOG     = 'titan_zero_front_desk_resource_catalog_v1';
    const NONCE       = 'tzfd_service_save';
    const MAX_ADDRESS = 300;

    public static function register() {
        add_action( 'admin_post_tzfd_service_save', array( __CLASS__, 'handle_save' ) );
    }

    /** Every saved entry: calendar ID => service name => address and location_id. */
    public static function all() {
        $value = get_option( self::OPTION, array() );
        return is_array( $value ) ? $value : array();
    }

    /** The saved address and location for one service. Empty values when nothing is saved. */
    public static function get( $calendar_id, $name ) {
        $all = self::all();
        $row = isset( $all[ (int) $calendar_id ][ (string) $name ] ) && is_array( $all[ (int) $calendar_id ][ (string) $name ] )
            ? $all[ (int) $calendar_id ][ (string) $name ] : array();
        return array(
            'address'     => isset( $row['address'] ) ? (string) $row['address'] : '',
            'location_id' => isset( $row['location_id'] ) ? (string) $row['location_id'] : '',
        );
    }

    public static function set( $calendar_id, $name, $address, $location_id ) {
        $all = self::all();
        $all[ (int) $calendar_id ][ (string) $name ] = array( 'address' => (string) $address, 'location_id' => (string) $location_id );
        return update_option( self::OPTION, $all, false );
    }

    /** Enabled locations from Workers and locations, as id => name. Empty when none are set up. */
    public static function location_options() {
        try {
            $catalog = TZFD_Resource_Catalog::validate( get_option( self::CATALOG, TZFD_Resource_Catalog::empty_catalog() ) );
        } catch ( Throwable $e ) {
            return array();
        }
        $out = array();
        foreach ( $catalog['locations'] as $location ) {
            if ( $location['enabled'] ) { $out[ $location['id'] ] = $location['name']; }
        }
        return $out;
    }

    /** Plain-text address, line breaks kept, capped in length. */
    public static function clean_address( $text ) {
        $text = sanitize_textarea_field( (string) $text );
        return mb_substr( trim( $text ), 0, self::MAX_ADDRESS );
    }

    /** Admin-post handler: saves the address and location of one service. */
    public static function handle_save() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::NONCE, 'tzfd_service_nonce' );

        $calendar_id = isset( $_POST['calendar'] ) ? absint( wp_unslash( $_POST['calendar'] ) ) : 0;
        $name        = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';
        $address     = isset( $_POST['address'] ) ? self::clean_address( sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) ) : '';
        $location    = isset( $_POST['location_id'] ) ? preg_replace( '/[^A-Za-z0-9_.:\-]/', '', sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) ) : '';
        if ( '' !== $location && ! array_key_exists( $location, self::location_options() ) ) { $location = ''; }

        $args = array( 'page' => TZFD_Admin_Menu::SERVICES );
        if ( $calendar_id < 1 || ! TZFD_Service_Details::service_exists( $calendar_id, $name ) ) {
            $args['error'] = 1;
        } else {
            self::set( $calendar_id, $name, $address, $location );
            $args['saved'] = 1;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** True when the calendar has a service with this name. */
    public static function service_exists( $calendar_id, $name ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read on save; values are bound.
        $structure = $wpdb->get_var( $wpdb->prepare( 'SELECT form_structure FROM %i WHERE id = %d', $wpdb->prefix . 'tzfd_forms', (int) $calendar_id ) );
        if ( null === $structure ) { return false; }
        foreach ( TZFD_Admin_Menu::services_in( (string) $structure ) as $service ) {
            if ( $service['name'] === (string) $name ) { return true; }
        }
        return false;
    }
}
