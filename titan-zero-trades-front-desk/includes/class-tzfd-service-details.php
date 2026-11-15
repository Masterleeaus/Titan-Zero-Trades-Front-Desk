<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-request-value.php';


require_once __DIR__ . '/class-tzfd-resource-catalog.php';

/**
 * Address and location for each service. Kept in their own option, not inside the calendar's
 * settings, so saving a calendar never overwrites them. Entries are keyed by calendar ID and
 * service name: renaming a service in its calendar starts its address again.
 */
final class TZFD_Service_Details {
    const OPTION      = 'titan_zero_front_desk_service_details_v1';
    const CATALOG     = 'titan_zero_front_desk_resource_catalog_v1';
    const NONCE       = 'tz_fd_service_save';
    const MAX_ADDRESS = 300;

    public static function register() {
        add_action( 'admin_post_tz_fd_service_save', array( __CLASS__, 'handle_save' ) );
    }

    /** Every saved entry: calendar ID => service name => address and location_id. */
    public static function all() {
        $value = TZFD_Scoped_Store::get( self::OPTION, array() );
        return is_array( $value ) ? $value : array();
    }

    /**
     * Calendar IDs are globally unique, but an option is now scoped by company.
     * Verify the calendar owner at each operation, including non-HTTP callers.
     */
    private static function owns_calendar( $calendar_id ) {
        $company = TZFD_Scoped_Store::company_id();
        $calendar_id = TZFD_Request_Value::id( $calendar_id );
        return $company > 0 && $calendar_id > 0
            && $company === TZFD_Scoped_Store::company_for_form( $calendar_id );
    }

    /** The saved address and location for one service. Empty values when nothing is saved. */
    public static function get( $calendar_id, $name ) {
        if ( ! self::owns_calendar( $calendar_id ) || ! is_string( $name ) ) { return array( 'address' => '', 'location_id' => '' ); }
        $all = self::all();
        $row = isset( $all[ (int) $calendar_id ][ (string) $name ] ) && is_array( $all[ (int) $calendar_id ][ (string) $name ] )
            ? $all[ (int) $calendar_id ][ (string) $name ] : array();
        return array(
            'address'     => isset( $row['address'] ) && is_string( $row['address'] ) ? $row['address'] : '',
            'location_id' => isset( $row['location_id'] ) && is_string( $row['location_id'] ) ? $row['location_id'] : '',
        );
    }

    /** Workspace catalogue id (tz_services) for a calendar service, or 0 when not linked. */
    public static function catalog_id( $calendar_id, $name ) {
        if ( ! self::owns_calendar( $calendar_id ) || ! is_string( $name ) ) { return 0; }
        $all = self::all();
        $row = isset( $all[ (int) $calendar_id ][ (string) $name ] ) && is_array( $all[ (int) $calendar_id ][ (string) $name ] )
            ? $all[ (int) $calendar_id ][ (string) $name ] : array();
        $id = self::catalog_input_id( $row['catalog_id'] ?? 0 );
        return null !== $id && $id > 0 && self::owns_catalog_service( $id ) ? $id : 0;
    }

    /** Strict numeric reference; zero is allowed only to deliberately unlink. */
    private static function catalog_input_id( $value ) {
        if ( 0 === $value || '0' === $value ) { return 0; }
        $id = TZFD_Request_Value::id( $value );
        return $id > 0 && (string) $id === (string) $value ? $id : null;
    }

    /** A catalogue reference must resolve to a service owned by the current business. */
    private static function owns_catalog_service( $id ) {
        $id = TZFD_Request_Value::id( $id );
        if ( $id < 1 || ! class_exists( 'TZFD_Shared_Store' ) ) { return false; }
        $service = TZFD_Shared_Store::find_service( $id );
        return is_array( $service ) && isset( $service['company_id'] ) &&
            (int) $service['company_id'] === (int) TZFD_Scoped_Store::company_id();
    }

    /** A no-op update may return false. Only report success for persisted, scoped data. */
    private static function persist( $all ) {
        if ( ! is_array( $all ) || TZFD_Scoped_Store::company_id() < 1 ) { return false; }
        $saved = TZFD_Scoped_Store::update( self::OPTION, $all );
        return true === $saved || self::all() === $all;
    }

    /** Links a calendar service to its Workspace catalogue row. Keeps the saved address and location. */
    public static function link_catalog( $calendar_id, $name, $catalog_id ) {
        $catalog_id = self::catalog_input_id( $catalog_id );
        if ( null === $catalog_id || ! self::owns_calendar( $calendar_id ) || ! is_string( $name ) || '' === trim( $name ) ||
             ( $catalog_id > 0 && ! self::owns_catalog_service( $catalog_id ) ) ) { return false; }
        $all = self::all();
        $key = (int) $calendar_id;
        $row = isset( $all[ $key ][ (string) $name ] ) && is_array( $all[ $key ][ (string) $name ] ) ? $all[ $key ][ (string) $name ] : array();
        $row['catalog_id'] = absint( $catalog_id );
        $all[ $key ][ (string) $name ] = $row;
        return self::persist( $all );
    }

    /** True when the calendar already has a saved address entry under this exact service name. */
    public static function has_entry( $calendar_id, $name ) {
        if ( ! self::owns_calendar( $calendar_id ) || ! is_string( $name ) ) { return false; }
        $all = self::all();
        return isset( $all[ (int) $calendar_id ][ (string) $name ] );
    }

    /** Moves the saved address, location and catalogue link to a renamed service. */
    public static function rename( $calendar_id, $old_name, $new_name, $catalog_id ) {
        $catalog_id = self::catalog_input_id( $catalog_id );
        if ( null === $catalog_id || ! self::owns_calendar( $calendar_id ) || ! is_string( $old_name ) || ! is_string( $new_name ) || '' === trim( $new_name ) ||
             ( $catalog_id > 0 && ! self::owns_catalog_service( $catalog_id ) ) ) { return false; }
        $all = self::all();
        $key = (int) $calendar_id;
        $row = isset( $all[ $key ][ (string) $old_name ] ) && is_array( $all[ $key ][ (string) $old_name ] ) ? $all[ $key ][ (string) $old_name ] : array();
        if ( $old_name !== $new_name && isset( $all[ $key ][ (string) $new_name ] ) ) { return false; }
        unset( $all[ $key ][ (string) $old_name ] );
        $row['catalog_id'] = absint( $catalog_id );
        $all[ $key ][ (string) $new_name ] = $row;
        return self::persist( $all );
    }

    public static function set( $calendar_id, $name, $address, $location_id ) {
        if ( ! self::owns_calendar( $calendar_id ) || ! is_string( $name ) || '' === trim( $name ) || ! is_string( $address ) || ! is_string( $location_id ) ) { return false; }
        $all = self::all();
        $previous = isset( $all[ (int) $calendar_id ][ (string) $name ] ) && is_array( $all[ (int) $calendar_id ][ (string) $name ] ) ? $all[ (int) $calendar_id ][ (string) $name ] : array();
        $address = self::clean_address( $address );
        if ( '' !== $location_id && ! array_key_exists( $location_id, self::location_options() ) ) { return false; }
        $all[ (int) $calendar_id ][ (string) $name ] = array( 'address' => $address, 'location_id' => $location_id );
        if ( isset( $previous['catalog_id'] ) ) { $all[ (int) $calendar_id ][ (string) $name ]['catalog_id'] = (int) $previous['catalog_id']; }
        return self::persist( $all );
    }

    /** Enabled locations from Workers and locations, as id => name. Empty when none are set up. */
    public static function location_options() {
        try {
            $catalog = TZFD_Resource_Catalog::validate( TZFD_Scoped_Store::get( self::CATALOG, TZFD_Resource_Catalog::empty_catalog() ) );
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
        $text = sanitize_textarea_field( is_string( $text ) ? $text : '' );
        return TZFD_Legacy_Compat::substr( trim( $text ), 0, self::MAX_ADDRESS );
    }

    /** Admin-post handler: saves the address and location of one service. */
    public static function handle_save() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 40, 8192 );
        check_admin_referer( self::NONCE, 'tz_fd_service_nonce' );

        $calendar_id = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_POST['calendar'] ?? '' ) ) );
        $name        = is_string( $_POST['service'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';
        $address     = is_string( $_POST['address'] ?? null ) ? self::clean_address( sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) ) : '';
        $location    = preg_replace( '/[^A-Za-z0-9_.:\-]/', '', is_string( $_POST['location_id'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '' );
        $valid_location = '' === $location || array_key_exists( $location, self::location_options() );

        $args = array( 'page' => TZFD_Admin_Menu::SERVICES );
        if ( ! $valid_location || $calendar_id < 1 || ! TZFD_Admin_Menu::can_manage_calendar( $calendar_id ) || ! TZFD_Service_Details::service_exists( $calendar_id, $name ) ) {
            $args['error'] = 1;
        } else {
            if ( self::set( $calendar_id, $name, $address, $location ) ) {
                $args['saved'] = 1;
            } else {
                $args['error'] = 1;
            }
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** True when the calendar has a service with this name. */
    public static function service_exists( $calendar_id, $name ) {
        if ( ! self::owns_calendar( $calendar_id ) ) { return false; }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read on save; values are bound.
        $structure = $wpdb->get_var( $wpdb->prepare( 'SELECT form_structure FROM %i WHERE id = %d AND company_id = %d', $wpdb->prefix . TZFD_Tables::FORMS, (int) $calendar_id, TZFD_Scoped_Store::company_id() ) );
        if ( null === $structure ) { return false; }
        foreach ( TZFD_Admin_Menu::services_in( (string) $structure ) as $service ) {
            if ( $service['name'] === (string) $name ) { return true; }
        }
        return false;
    }
}
