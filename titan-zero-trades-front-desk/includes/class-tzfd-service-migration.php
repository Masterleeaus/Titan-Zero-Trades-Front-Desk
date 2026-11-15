<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Non-destructive legacy-option -> SQL service migration, scoped to the resolved business. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Service_Migration {
    const MARKER = 'tzfd_native_services_sql_migrated_v1';
    private static $attempted = array();

    private static function eligible() {
        return class_exists( 'TZFD_Local_Services', false ) && class_exists( 'TZFD_Scoped_Store', false );
    }

    /** Check an existing canonical row before treating a legacy ID as migrated.
     * Archived state is included: an active legacy record must not be silently
     * replaced by an unrelated archived SQL record sharing the same ID.
     */
    private static function same_record( $legacy, $sql ) {
        if ( ! is_array( $legacy ) || ! is_array( $sql ) ) { return false; }
        if ( ! isset( $legacy['name'], $legacy['price'], $legacy['duration_minutes'], $sql['name'], $sql['price'], $sql['duration_minutes'] ) ) { return false; }
        $money = static function ( $value ) {
            if ( ! is_scalar( $value ) || ! preg_match( '/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', (string) $value ) ) { return null; }
            return number_format( (float) $value, 2, '.', '' );
        };
        return (string) sanitize_text_field( $legacy['name'] ) === (string) $sql['name']
            && null !== $money( $legacy['price'] ) && $money( $legacy['price'] ) === $money( $sql['price'] )
            && (int) $legacy['duration_minutes'] === (int) $sql['duration_minutes']
            && (string) sanitize_textarea_field( $legacy['description'] ?? '' ) === (string) ( $sql['description'] ?? '' )
            && (int) ! empty( $legacy['gst_applies'] ) === (int) ! empty( $sql['gst_applies'] )
            && (int) ! empty( $legacy['archived'] ) === (int) ! empty( $sql['archived'] );
    }

    /**
     * Detect an unresolved same-ID collision before exposing or mutating a service.
     * Only applies to the standalone SQL provider while the per-company migration
     * marker is unset. Once migrated, the SQL row is canonical and may legitimately
     * diverge from the retained legacy rollback copy.
     *
     * A failed SQL read is treated as a conflict rather than permitting access to
     * a potentially unrelated same-ID record.
     */
    public static function unresolved_collision( $id ) {
        global $wpdb;
        if ( ! self::eligible() ) { return false; }
        $company = (int) TZFD_Scoped_Store::company_id();
        if ( $company < 1 ) { return true; }
        if ( 1 === (int) TZFD_Scoped_Store::get( self::MARKER, 0, $company ) ) { return false; }
        if ( ! ( is_int( $id ) || ( is_string( $id ) && preg_match( '/\A[1-9][0-9]*\z/D', $id ) ) ) ||
             (int) $id < TZFD_Native_Services::FIRST_ID || (string) (int) $id !== (string) $id ) { return false; }
        $legacy = TZFD_Scoped_Store::get( TZFD_Native_Services::OPTION, array(), $company );
        if ( ! is_array( $legacy ) ) { return true; }
        $key = (string) $id;
        if ( ! array_key_exists( $key, $legacy ) ) { return false; }
        $row = $legacy[ $key ];
        if ( ! is_array( $row ) || (int) ( $row['company_id'] ?? 0 ) !== $company ||
             (string) ( $row['id'] ?? '' ) !== $key ) { return true; }
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ||
             ! method_exists( $wpdb, 'get_row' ) || empty( $wpdb->prefix ) ) { return true; }
        $saved = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'tzfd_local_services', (int) $id
        ), ARRAY_A );
        if ( ! empty( $wpdb->last_error ) ) { return true; }
        // A missing SQL row is still a legitimate unmigrated legacy service.
        if ( null === $saved ) { return false; }
        return ! is_array( $saved ) || (int) ( $saved['company_id'] ?? 0 ) !== $company ||
            ! self::same_record( $row, $saved );
    }

    /** Idempotent and fail-closed. Originals are retained for rollback and partial failures. */
    public static function run() {
        global $wpdb;
        if ( ! self::eligible() ) { return false; }
        $company = (int) TZFD_Scoped_Store::company_id();
        if ( $company < 1 ) { return false; }
        if ( isset( self::$attempted[ $company ] ) ) { return self::$attempted[ $company ]; }
        if ( 1 === (int) TZFD_Scoped_Store::get( self::MARKER, 0, $company ) ) {
            self::$attempted[ $company ] = true;
            return true;
        }
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ||
             ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'insert' ) || empty( $wpdb->prefix ) ) { return false; }
        $lock = 'tzfd_migrate_' . substr( hash( 'sha256', $company . ':' . TZFD_Native_Services::OPTION ), 0, 40 );
        $granted = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 3 ) );
        if ( '1' !== (string) $granted || ! empty( $wpdb->last_error ) ) { return false; }
        try {
            if ( 1 === (int) TZFD_Scoped_Store::get( self::MARKER, 0, $company ) ) {
                self::$attempted[ $company ] = true;
                return true;
            }
            $rows = TZFD_Scoped_Store::get( TZFD_Native_Services::OPTION, array(), $company );
            if ( ! is_array( $rows ) || count( $rows ) > TZFD_Native_Services::MAX_ROWS ) { return false; }
            $table = $wpdb->prefix . 'tzfd_local_services';
            // Preflight all ownership collisions BEFORE the first insert. This avoids
            // an avoidable partial import when a later legacy ID belongs to another tenant.
            // The second pass still rechecks ownership: preflight is not a substitute for
            // a unique database key or the per-company advisory lock.
            foreach ( $rows as $key => $row ) {
                if ( ! is_array( $row ) || ! ctype_digit( (string) $key ) ||
                     (string) (int) $key !== (string) $key ||
                     ! isset( $row['id'], $row['company_id'] ) ||
                     (string) $row['id'] !== (string) $key ||
                     (int) $row['company_id'] !== $company ||
                     (int) $key < TZFD_Native_Services::FIRST_ID ||
                     ! isset( $row['name'], $row['price'], $row['duration_minutes'] ) ||
                     ! is_string( $row['name'] ) || '' === trim( $row['name'] ) || strlen( $row['name'] ) > 190 ||
                     ! is_scalar( $row['price'] ) ||
                     ! preg_match( '/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', (string) $row['price'] ) ||
                     ( ! is_int( $row['duration_minutes'] ) &&
                       ! ( is_string( $row['duration_minutes'] ) && preg_match( '/\A[0-9]{1,4}\z/D', $row['duration_minutes'] ) ) ) ||
                     (int) $row['duration_minutes'] < 1 || (int) $row['duration_minutes'] > 1440 ||
                     ( isset( $row['description'] ) && ( ! is_string( $row['description'] ) || strlen( $row['description'] ) > 10000 ) ) ||
                     ( isset( $row['gst_applies'] ) && ! in_array( $row['gst_applies'], array( 0, 1, '0', '1', false, true ), true ) ) ) { return false; }
                $owner = $wpdb->get_row( $wpdb->prepare(
                    'SELECT id, company_id FROM %i WHERE id = %d', $table, (int) $key
                ), ARRAY_A );
                if ( ! empty( $wpdb->last_error ) ||
                     ( null !== $owner && ( ! is_array( $owner ) ||
                       (int) ( $owner['company_id'] ?? 0 ) !== $company ) ) ) { return false; }
                // An identical numeric ID in our own SQL catalogue is not proof that
                // the legacy record was imported. Never silently discard its fields.
                if ( null !== $owner && ! self::same_record( $row, $wpdb->get_row( $wpdb->prepare(
                    'SELECT * FROM %i WHERE id = %d AND company_id = %d', $table, (int) $key, $company
                ), ARRAY_A ) ) ) { return false; }
                if ( ! empty( $wpdb->last_error ) ) { return false; }
            }
            foreach ( $rows as $key => $row ) {
                if ( ! is_array( $row ) || ! isset( $row['id'], $row['company_id'] ) ||
                     (string) $row['id'] !== (string) $key || (int) $row['company_id'] !== $company ||
                     ! ctype_digit( (string) $key ) || (int) $key < TZFD_Native_Services::FIRST_ID ||
                     (string) (int) $key !== (string) $key || ! isset( $row['name'], $row['price'], $row['duration_minutes'] ) ||
                     ! is_string( $row['name'] ) || '' === trim( $row['name'] ) || strlen( $row['name'] ) > 190 ||
                     ! is_scalar( $row['price'] ) || ! preg_match( '/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', (string) $row['price'] ) ||
                     ( ! is_int( $row['duration_minutes'] ) &&
                       ! ( is_string( $row['duration_minutes'] ) && preg_match( '/\A[0-9]{1,4}\z/D', $row['duration_minutes'] ) ) ) ||
                     (int) $row['duration_minutes'] < 1 ||
                     (int) $row['duration_minutes'] > 1440 ||
                     ( isset( $row['description'] ) && ( ! is_string( $row['description'] ) || strlen( $row['description'] ) > 10000 ) ) ||
                     ( isset( $row['gst_applies'] ) && ! in_array( $row['gst_applies'], array( 0, 1, '0', '1', false, true ), true ) ) ) { return false; }
                $id = (int) $key;
                // The SQL primary key is global. A collision from another business must
                // never be overwritten or silently reassigned (calendar links use this ID).
                $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, company_id FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
                if ( ! empty( $wpdb->last_error ) ) { return false; }
                if ( null !== $existing ) {
                    if ( ! is_array( $existing ) || (int) ( $existing['company_id'] ?? 0 ) !== $company ||
                         ! self::same_record( $row, $wpdb->get_row( $wpdb->prepare(
                             'SELECT * FROM %i WHERE id = %d AND company_id = %d', $table, $id, $company
                         ), ARRAY_A ) ) || ! empty( $wpdb->last_error ) ) { return false; }
                    continue; // Verified SQL record is canonical; never overwrite it.
                }
                $saved = $wpdb->insert( $table, array(
                    'id' => $id, 'company_id' => $company,
                    'name' => sanitize_text_field( $row['name'] ),
                    'description' => sanitize_textarea_field( $row['description'] ?? '' ),
                    'price' => number_format( (float) $row['price'], 2, '.', '' ),
                    'duration_minutes' => (int) $row['duration_minutes'],
                    'gst_applies' => (int) ! empty( $row['gst_applies'] ),
                    'archived' => (int) ! empty( $row['archived'] ),
                ) );
                if ( 1 !== $saved || ! is_array( TZFD_Local_Services::find( $id ) ) ) { return false; }
            }
            $written = TZFD_Scoped_Store::update( self::MARKER, 1, $company );
            if ( ! $written && 1 !== (int) TZFD_Scoped_Store::get( self::MARKER, 0, $company ) ) { return false; }
            self::$attempted[ $company ] = true;
            return true;
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }
}
