<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-native-services.php';
require_once __DIR__ . '/class-tzfd-service-migration.php';

/** Company-bound service catalogue: the local SQL catalogue, with the legacy option catalogue as fallback. */
final class TZFD_Shared_Store {
    /** No company means no access, including when called outside an HTTP handler. */
    private static function company_id() {
        return class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
    }

    /** Migrate eligible standalone legacy records before reading or mutating catalogues. */
    private static function migrate_legacy() { TZFD_Service_Migration::run(); }

    /** Require an authoritative company identifier on every Workspace service row. */
    private static function owned_row( $row, $company ) {
        if ( is_object( $row ) ) { $row = (array) $row; }
        return is_array( $row ) && isset( $row['company_id'] )
            && ( is_int( $row['company_id'] ) || ( is_string( $row['company_id'] ) && ctype_digit( $row['company_id'] ) ) )
            && (int) $row['company_id'] === $company;
    }

    private static function valid_id( $id ) {
        if ( ! is_int( $id ) && ! ( is_string( $id ) && preg_match( '/\A[1-9][0-9]*\z/D', $id ) ) ) { return 0; }
        $value = (int) $id;
        return $value > 0 && (string) $value === (string) $id ? $value : 0;
    }

    /** Create in the local catalogue; never allow a companyless background request to create site-wide records. */
    public static function create_service( array $fields ) {
        if ( self::company_id() < 1 ) { return false; }
        self::migrate_legacy();
        // The local catalogue owns creation and its company assignment; never accept a caller-supplied owner override.
        unset( $fields['company_id'] );
        $id = TZFD_Local_Services::create( $fields );
        if ( is_wp_error( $id ) || ! self::valid_id( $id ) ) { return false; }
        // A create response is not proof of ownership.
        // Do not expose an ID unless its persisted record belongs to this tenant.
        $saved = TZFD_Local_Services::find( self::valid_id( $id ) );
        return self::owned_row( $saved, self::company_id() ) ? self::valid_id( $id ) : false;
    }

    /** Only company-owned rows can be returned to a caller. */
    public static function find_service( $id ) {
        $company = self::company_id();
        $id = self::valid_id( $id );
        if ( $company < 1 || $id < 1 ) { return null; }
        self::migrate_legacy();
        if ( TZFD_Service_Migration::unresolved_collision( $id ) ) { return null; }
        $row = TZFD_Local_Services::find( $id );
        if ( self::owned_row( $row, $company ) ) { return (array) $row; }
        // Legacy fallback is retained if migration cannot safely complete.
        return TZFD_Native_Services::find( $id );
    }

    /** No cross-company updates, even when a foreign catalogue ID is stored in a local calendar. */
    public static function update_service( $id, array $fields ) {
        if ( null === self::find_service( $id ) ) { return false; }
        // Once SQL owns this ID, do not mutate a stale legacy option copy.
        if ( TZFD_Local_Services::find( $id ) ) {
            return TZFD_Local_Services::update( $id, $fields );
        }
        if ( TZFD_Native_Services::find( $id ) ) { return TZFD_Native_Services::update( $id, $fields ); }
        unset( $fields['company_id'] );
        return true === TZFD_Local_Services::update( self::valid_id( $id ), $fields );
    }

    public static function archive_service( $id ) {
        if ( null === self::find_service( $id ) ) { return false; }
        if ( TZFD_Local_Services::find( $id ) ) {
            return TZFD_Local_Services::archive( $id );
        }
        if ( TZFD_Native_Services::find( $id ) ) { return TZFD_Native_Services::archive( $id ); }
        return true === TZFD_Local_Services::archive( self::valid_id( $id ) );
    }

    /** Filter the local catalogue result by authoritative company ownership. */
    public static function services( $limit = 200 ) {
        $company = self::company_id();
        if ( $company < 1 ) { return array(); }
        self::migrate_legacy();
        $limit = is_int( $limit ) || ( is_string( $limit ) && ctype_digit( $limit ) ) ? (int) $limit : 200;
        $limit = max( 1, min( 200, $limit ) );
        // The catalogue might return a set wider than this company; never trust it as an authorization boundary.
        $rows = TZFD_Local_Services::list( array( 'limit' => $limit, 'company_id' => $company ) );
        if ( ! is_array( $rows ) ) { $rows = array(); }
        $result = array();
        foreach ( $rows as $row ) {
            if ( ! self::owned_row( $row, $company ) ) { continue; }
            $value = (array) $row;
            $id = self::valid_id( $value['id'] ?? null );
            if ( $id < 1 || ! empty( $value['archived'] ) || isset( $result[ $id ] ) ||
                 TZFD_Service_Migration::unresolved_collision( $id ) ) { continue; }
            // Never let a malformed optional provider row hide a valid legacy ID.
            $value['id'] = $id;
            $result[ $id ] = (object) $value;
        }
        $result = array_values( $result );
        $seen = array();
        foreach ( $result as $row ) { if ( isset( $row->id ) ) { $seen[ (string) $row->id ] = true; } }
        foreach ( TZFD_Native_Services::listing( $limit ) as $native ) {
            $native_id = self::valid_id( $native['id'] ?? null );
            if ( $native_id < 1 || isset( $seen[ (string) $native_id ] ) ||
                 TZFD_Service_Migration::unresolved_collision( $native_id ) ) { continue; }
            $seen[ (string) $native_id ] = true;
            $result[] = (object) $native;
        }
        return array_slice( $result, 0, $limit );
    }

    public static function render_panel() {
        if ( self::company_id() < 1 ) { return; }
        // The local catalogue is always available.
        $rows = self::services( 200 );
        echo '<h2>' . esc_html__( 'Service catalogue', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Price', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="2">';
            if ( class_exists( 'TZFD_Local_Brand' ) ) { TZFD_Local_Brand::empty_state( 'services', __( 'No services yet. Create a service using the calendar settings.', 'titan-zero-trades-front-desk' ) ); } else { echo esc_html__( 'No services yet. Create a service using the calendar settings.', 'titan-zero-trades-front-desk' ); }
            echo '</td></tr>';
        }
        foreach ( $rows as $row ) {
            $name  = isset( $row->name ) && is_scalar( $row->name ) ? (string) $row->name : '';
            $price = isset( $row->price ) && is_scalar( $row->price ) ? (string) $row->price : '';
            echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $price ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
