<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Private local customer store; does not mutate Workspace-owned tz_customers. */
final class TZFD_Local_Customers {
    private static function table() { global $wpdb; return $wpdb->prefix . 'tzfd_local_customers'; }
    /** Active company from the shared resolver (TZFD_Scoped_Store::company_id); 0 when none. */
    private static function company() { return class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0; }
    public static function install() {
        global $wpdb;
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL DEFAULT 1,
            name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(60) NOT NULL DEFAULT '',
            address text NULL,
            notes text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY company_id (company_id),
            KEY email (email)
        ) {$charset};" );
    }
    public static function find( $id ) {
        global $wpdb; $id = absint( $id ); $company = self::company();
        if ( ! $id || $company < 1 ) { return null; }
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND company_id = %d', self::table(), $id, $company ), ARRAY_A );
    }
    public static function search( $needle = '', $args = array() ) {
        global $wpdb;
        $company = self::company();
        if ( $company < 1 ) { return array(); }
        $limit = max( 1, min( 200, absint( $args['limit'] ?? 50 ) ) );
        $like = '%' . $wpdb->esc_like( (string) $needle ) . '%';
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND (name LIKE %s OR email LIKE %s OR phone LIKE %s) ORDER BY id DESC LIMIT %d', self::table(), $company, $like, $like, $like, $limit ), ARRAY_A );
    }
    public static function update( $id, $fields ) {
        global $wpdb;
        $id = absint( $id );
        if ( ! $id || ! self::find( $id ) || ! is_array( $fields ) ) { return false; }
        $name = sanitize_text_field( (string) ( $fields['name'] ?? '' ) );
        $raw_email = (string) ( $fields['email'] ?? '' );
        $email = sanitize_email( $raw_email );
        if ( '' !== $raw_email && ! is_email( $raw_email ) ) { return false; }
        if ( '' === $name || strlen( $name ) > 190 || strlen( $email ) > 190 ||
            strlen( (string) ( $fields['phone'] ?? '' ) ) > 60 ||
            ( '' !== $email && ! is_email( $email ) ) ) { return false; }
        $address = sanitize_textarea_field( (string) ( $fields['address'] ?? '' ) );
        $notes = sanitize_textarea_field( (string) ( $fields['notes'] ?? '' ) );
        if ( strlen( $address ) > 2000 || strlen( $notes ) > 5000 ) { return false; }
        $result = $wpdb->update( self::table(), array(
            'name' => $name, 'email' => $email,
            'phone' => sanitize_text_field( (string) ( $fields['phone'] ?? '' ) ),
            'address' => $address, 'notes' => $notes,
        ), array( 'id' => $id, 'company_id' => self::company() ) );
        return false !== $result;
    }
    public static function create( $fields ) {
        global $wpdb;
        $company = self::company();
        if ( $company < 1 || ! is_array( $fields ) ) { return false; }
        $name = sanitize_text_field( (string) ( $fields['name'] ?? '' ) );
        $raw_email = (string) ( $fields['email'] ?? '' );
        $email = sanitize_email( $raw_email );
        if ( '' !== $raw_email && ! is_email( $raw_email ) ) { return false; }
        if ( '' === $name || strlen( $name ) > 190 || strlen( $email ) > 190 ||
            strlen( (string) ( $fields['phone'] ?? '' ) ) > 60 ||
            ( '' !== $email && ! is_email( $email ) ) ) { return false; }
        $address = sanitize_textarea_field( (string) ( $fields['address'] ?? '' ) );
        $notes = sanitize_textarea_field( (string) ( $fields['notes'] ?? '' ) );
        if ( strlen( $address ) > 2000 || strlen( $notes ) > 5000 ) { return false; }
        $ok = $wpdb->insert( self::table(), array( 'company_id' => $company, 'name' => $name, 'email' => $email, 'phone' => sanitize_text_field( (string) ( $fields['phone'] ?? '' ) ), 'address' => $address, 'notes' => $notes, 'created_at' => current_time( 'mysql', true ) ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }
}
