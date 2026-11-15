<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Native, company-isolated service catalogue for standalone WordPress installs. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Native_Services {
    const OPTION = 'titan_zero_front_desk_native_services_v1';
    const FIRST_ID = 1000000000;
    const MAX_ROWS = 10000;

    private static function company() {
        return class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
    }
    private static function rows() {
        if ( self::company() < 1 ) { return array(); }
        $rows = TZFD_Scoped_Store::get( self::OPTION, array() );
        return is_array( $rows ) ? $rows : array();
    }
    private static function id( $id ) {
        if ( ! is_int( $id ) && ! ( is_string( $id ) && preg_match( '/\A[1-9][0-9]*\z/D', $id ) ) ) { return 0; }
        $number = (int) $id;
        return $number >= self::FIRST_ID && (string) $number === (string) $id ? $number : 0;
    }
    private static function valid_fields( $fields, $previous = null ) {
        if ( ! is_array( $fields ) || array_diff( array_keys( $fields ), array( 'name', 'price', 'duration_minutes', 'description', 'gst_applies' ) ) ) { return false; }
        $name = isset( $fields['name'] ) ? $fields['name'] : ( $previous['name'] ?? null );
        $price = isset( $fields['price'] ) ? $fields['price'] : ( $previous['price'] ?? null );
        $duration = isset( $fields['duration_minutes'] ) ? $fields['duration_minutes'] : ( $previous['duration_minutes'] ?? null );
        if ( ! is_string( $name ) || '' === trim( $name ) || strlen( $name ) > 500 || ! is_scalar( $price ) ||
             ! preg_match( '/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', (string) $price ) ||
             ( ! is_int( $duration ) && ! ( is_string( $duration ) && ctype_digit( $duration ) ) ) ||
             (int) $duration < 1 || (int) $duration > 1440 ) { return false; }
        if ( isset( $fields['description'] ) && ( ! is_string( $fields['description'] ) || strlen( $fields['description'] ) > 10000 ) ) { return false; }
        if ( isset( $fields['gst_applies'] ) && ! in_array( $fields['gst_applies'], array( 0, 1, '0', '1', false, true ), true ) ) { return false; }
        return array(
            'name' => sanitize_text_field( $name ),
            'price' => number_format( (float) $price, 2, '.', '' ),
            'duration_minutes' => (int) $duration,
            'description' => isset( $fields['description'] ) && is_string( $fields['description'] ) ? sanitize_textarea_field( $fields['description'] ) : ( $previous['description'] ?? '' ),
            'gst_applies' => isset( $fields['gst_applies'] ) ? (int) (bool) $fields['gst_applies'] : ( $previous['gst_applies'] ?? 0 ),
        );
    }
    /**
     * Serialise legacy option read-modify-write operations across PHP workers.
     * A database named lock is connection-scoped, preventing lost creations even
     * when the WordPress object cache is shared between application instances.
     * Never fall back to an unlocked write when locking is unavailable.
     */
    private static function mutate( $operation ) {
        global $wpdb;
        $company = self::company();
        if ( $company < 1 || ! is_callable( $operation ) || ! isset( $wpdb ) ||
             ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) { return false; }
        $lock = 'tzfd_native_' . substr( hash( 'sha256', (string) $company . ':' . self::OPTION ), 0, 40 );
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );
        if ( '1' !== (string) $acquired || ! empty( $wpdb->last_error ) ) { return false; }
        try {
            // Clear the options cache after acquiring the lock so each mutation
            // works from the value committed by the previous lock holder.
            $name = TZFD_Scoped_Store::option_name( self::OPTION, $company );
            wp_cache_delete( $name, 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            return call_user_func( $operation );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }
    private static function save( $rows ) {
        if ( self::company() < 1 || count( $rows ) > self::MAX_ROWS ) { return false; }
        $result = TZFD_Scoped_Store::update( self::OPTION, $rows );
        // WordPress update_option returns false on a no-op; check the persisted value.
        return true === $result || self::rows() === $rows;
    }
    public static function create( array $fields ) {
        $clean = self::valid_fields( $fields );
        if ( false === $clean ) { return false; }
        return self::mutate( function () use ( $clean ) {
            $company = self::company();
            $rows = self::rows();
            if ( count( $rows ) >= self::MAX_ROWS ) { return false; }
            $max = self::FIRST_ID - 1;
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! self::id( $row['id'] ) ) { return false; }
                $max = max( $max, self::id( $row['id'] ) );
            }
            if ( $max >= PHP_INT_MAX - 1 ) { return false; }
            $id = $max + 1;
            $rows[ (string) $id ] = array_merge( $clean, array( 'id' => $id, 'company_id' => $company, 'archived' => 0 ) );
            return self::save( $rows ) ? $id : false;
        } );
    }
    public static function find( $id ) {
        $id = self::id( $id );
        if ( ! $id ) { return null; }
        $row = self::rows()[ (string) $id ] ?? null;
        return is_array( $row ) && (int) ( $row['company_id'] ?? 0 ) === self::company() ? $row : null;
    }
    public static function update( $id, array $fields ) {
        $id = self::id( $id );
        if ( ! $id ) { return false; }
        return self::mutate( function () use ( $id, $fields ) {
            $rows = self::rows();
            $row = $rows[ (string) $id ] ?? null;
            if ( ! is_array( $row ) || (int) ( $row['company_id'] ?? 0 ) !== self::company() || ! empty( $row['archived'] ) ) { return false; }
            $clean = self::valid_fields( $fields, $row );
            if ( false === $clean ) { return false; }
            $rows[ (string) $id ] = array_merge( $row, $clean );
            return self::save( $rows );
        } );
    }
    public static function archive( $id ) {
        $id = self::id( $id );
        if ( ! $id ) { return false; }
        return self::mutate( function () use ( $id ) {
            $rows = self::rows();
            $row = $rows[ (string) $id ] ?? null;
            if ( ! is_array( $row ) || (int) ( $row['company_id'] ?? 0 ) !== self::company() ) { return false; }
            $rows[ (string) $id ]['archived'] = 1;
            return self::save( $rows );
        } );
    }
    public static function listing( $limit = 200 ) {
        $out = array();
        foreach ( self::rows() as $row ) {
            if ( is_array( $row ) && empty( $row['archived'] ) && (int) ( $row['company_id'] ?? 0 ) === self::company() ) {
                $out[] = $row;
                if ( count( $out ) >= $limit ) { break; }
            }
        }
        return $out;
    }
}
