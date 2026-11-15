<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** SQL-backed standalone services; never exposes records from a different company. */
final class TZFD_Local_Services {
    private static function table() { global $wpdb; return $wpdb->prefix . 'tzfd_local_services'; }
    /** Standalone mode resolves to local company 1; optional integrations may resolve other tenants. */
    private static function company() {
        return class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
    }
    private static function id( $value ) {
        if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/\A[1-9][0-9]*\z/D', $value ) ) ) { return 0; }
        $id = filter_var( (string) $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $id ? 0 : $id;
    }
    public static function install() {
        global $wpdb;
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL DEFAULT 1,
            name varchar(190) NOT NULL DEFAULT '',
            description text NOT NULL,
            price decimal(12,2) NOT NULL DEFAULT 0.00,
            duration_minutes int(11) NOT NULL DEFAULT 60,
            gst_applies tinyint(1) NOT NULL DEFAULT 0,
            archived tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY company_id (company_id),
            KEY archived (archived)
        ) {$charset};" );
    }
    private static function fields( $input ) {
        if ( ! is_array( $input ) ) { return false; }
        $out = array();
        if ( array_key_exists( 'name', $input ) ) {
            if ( ! is_string( $input['name'] ) || strlen( $input['name'] ) > 1000 ) { return false; }
            $name = sanitize_text_field( $input['name'] );
            if ( '' === trim( $name ) || strlen( $name ) > 190 ) { return false; }
            $out['name'] = $name;
        }
        if ( array_key_exists( 'description', $input ) ) {
            if ( ! is_string( $input['description'] ) || strlen( $input['description'] ) > 10000 ) { return false; }
            $out['description'] = sanitize_textarea_field( $input['description'] );
        }
        if ( array_key_exists( 'price', $input ) ) {
            if ( ! is_int( $input['price'] ) && ! is_float( $input['price'] ) && ! is_string( $input['price'] ) ) { return false; }
            if ( is_float( $input['price'] ) && ! is_finite( $input['price'] ) ) { return false; }
            if ( strlen( (string) $input['price'] ) > 32 ) { return false; }
            $price = TZFD_Local_Money::parse_amount( $input['price'] );
            if ( null === $price || ! is_finite( (float) $price ) || $price < 0 || $price > 9999999999.99 ) { return false; }
            $out['price'] = number_format( $price, 2, '.', '' );
        }
        if ( array_key_exists( 'duration_minutes', $input ) ) {
            $duration = $input['duration_minutes'];
            if ( ! is_int( $duration ) && ! ( is_string( $duration ) && preg_match( '/\A[0-9]{1,4}\z/D', $duration ) ) ) { return false; }
            if ( (int) $duration < 1 || (int) $duration > 1440 ) { return false; }
            $out['duration_minutes'] = (int) $duration;
        }
        if ( array_key_exists( 'gst_applies', $input ) ) {
            if ( ! in_array( $input['gst_applies'], array( 0, 1, '0', '1', false, true ), true ) ) { return false; }
            $out['gst_applies'] = (int) (bool) $input['gst_applies'];
        }
        return $out;
    }
    public static function create( $fields ) {
        global $wpdb;
        $company = self::company();
        if ( $company < 1 ) { return false; }
        $data = self::fields( $fields );
        if ( false === $data || empty( $data['name'] ) ) { return false; }
        $data['company_id'] = $company;
        $result = $wpdb->insert( self::table(), $data );
        return 1 === $result && self::id( $wpdb->insert_id ) ? (int) $wpdb->insert_id : false;
    }
    public static function find( $id ) {
        global $wpdb;
        $company = self::company(); $id = self::id( $id );
        if ( $company < 1 || $id < 1 ||
             ( class_exists( 'TZFD_Service_Migration', false ) && TZFD_Service_Migration::unresolved_collision( $id ) ) ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND id = %d', self::table(), $company, $id ), ARRAY_A );
        if ( ! empty( $wpdb->last_error ) || ! is_array( $row ) || ! isset( $row['company_id'] ) || (int) $row['company_id'] !== $company ) { return null; }
        return $row;
    }
    public static function update( $id, $fields ) {
        global $wpdb;
        $company = self::company(); $id = self::id( $id );
        if ( $company < 1 || $id < 1 || null === self::find( $id ) ) { return false; }
        $data = self::fields( $fields );
        if ( ! is_array( $data ) || empty( $data ) ) { return false; }
        $result = $wpdb->update( self::table(), $data, array( 'id' => $id, 'company_id' => $company ) );
        if ( false === $result ) { return false; }
        // Zero rows affected can be a no-op; require a fresh, company-owned matching record.
        $saved = self::find( $id );
        if ( ! is_array( $saved ) ) { return false; }
        foreach ( $data as $key => $value ) { if ( ! array_key_exists( $key, $saved ) || (string) $saved[ $key ] !== (string) $value ) { return false; } }
        return true;
    }
    public static function archive( $id ) {
        global $wpdb;
        $company = self::company(); $id = self::id( $id );
        if ( $company < 1 || $id < 1 || null === self::find( $id ) ) { return false; }
        $result = $wpdb->update( self::table(), array( 'archived' => 1 ), array( 'id' => $id, 'company_id' => $company ) );
        $saved = false === $result ? null : self::find( $id );
        return is_array( $saved ) && 1 === (int) ( $saved['archived'] ?? 0 );
    }
    public static function list( $args = array() ) {
        global $wpdb;
        $company = self::company();
        if ( $company < 1 || ! is_array( $args ) ) { return array(); }
        $raw = $args['limit'] ?? 50;
        $limit = self::id( $raw );
        if ( $limit < 1 ) { return array(); }
        $limit = min( 200, $limit );
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND archived = 0 ORDER BY id DESC LIMIT %d', self::table(), $company, $limit ), ARRAY_A );
        if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) { return array(); }
        return array_values( array_filter( $rows, function ( $row ) use ( $company ) {
            return is_array( $row ) && isset( $row['company_id'] ) && (int) $row['company_id'] === $company && empty( $row['archived'] ) &&
                ( ! class_exists( 'TZFD_Service_Migration', false ) || ! TZFD_Service_Migration::unresolved_collision( $row['id'] ?? 0 ) );
        } ) );
    }
}
