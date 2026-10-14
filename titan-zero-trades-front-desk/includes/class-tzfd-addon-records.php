<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Record store shared by the Titan Zero Trades: Front Desk add-ons.
 *
 * One table holds add-on records (payments, outbound messages, redemptions,
 * sync jobs). A record is identified by add-on, kind and reference, and that
 * triple is unique, so a repeated webhook or a retried job cannot create a
 * second record. Payloads are stored as JSON and never unserialized.
 */
final class TZFD_Addon_Records {
    const TABLE          = 'tzfd_addon_records';
    const SCHEMA_OPTION  = 'titan_zero_front_desk_addon_records_schema';
    const SCHEMA_VERSION = '1';
    const MAX_REF_LENGTH = 190;
    const MAX_PAYLOAD    = 65536;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function ensure_schema() {
        if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            addon varchar(64) NOT NULL,
            kind varchar(64) NOT NULL,
            ref varchar(190) NOT NULL,
            booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext,
            created datetime DEFAULT CURRENT_TIMESTAMP,
            updated datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY addon_kind_ref (addon, kind, ref),
            KEY booking (booking_id),
            KEY addon_kind (addon, kind)
        ) {$charset};" );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
    }

    public static function clean_ref( $ref ) {
        $ref = is_scalar( $ref ) ? sanitize_text_field( (string) $ref ) : '';
        return substr( $ref, 0, self::MAX_REF_LENGTH );
    }

    /** Stores a new record. Returns false if the record already exists. */
    public static function insert( $addon, $kind, $ref, $booking_id, array $payload ) {
        global $wpdb;
        $ref     = self::clean_ref( $ref );
        $encoded = self::encode( $payload );
        if ( '' === $ref || null === $encoded ) { return false; }

        $suppress = $wpdb->suppress_errors( true ); // a duplicate key is an expected outcome here
        $result   = $wpdb->insert(
            self::table(),
            array( 'addon' => sanitize_key( $addon ), 'kind' => sanitize_key( $kind ), 'ref' => $ref,
                   'booking_id' => max( 0, (int) $booking_id ), 'payload' => $encoded, 'created' => current_time( 'mysql' ) ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );
        $wpdb->suppress_errors( $suppress );
        return false !== $result && 1 === (int) $result;
    }

    public static function get( $addon, $kind, $ref ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM %i WHERE addon = %s AND kind = %s AND ref = %s",
            self::table(), sanitize_key( $addon ), sanitize_key( $kind ), self::clean_ref( $ref )
        ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /** Replaces the payload of an existing record. Returns true when a row was changed. */
    public static function update( $addon, $kind, $ref, array $payload ) {
        global $wpdb;
        $encoded = self::encode( $payload );
        if ( null === $encoded ) { return false; }
        $result = $wpdb->update(
            self::table(),
            array( 'payload' => $encoded, 'updated' => current_time( 'mysql' ) ),
            array( 'addon' => sanitize_key( $addon ), 'kind' => sanitize_key( $kind ), 'ref' => self::clean_ref( $ref ) ),
            array( '%s', '%s' ),
            array( '%s', '%s', '%s' )
        );
        return false !== $result && (int) $result > 0;
    }

    /** Records of one kind linked to a booking, oldest first. */
    public static function for_booking( $addon, $kind, $booking_id ) {
        global $wpdb;
        $tzfd_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE addon = %s AND kind = %s AND booking_id = %d ORDER BY id ASC LIMIT 500",
            self::table(), sanitize_key( $addon ), sanitize_key( $kind ), (int) $booking_id
        ) );
        return array_map( array( __CLASS__, 'hydrate' ), (array) $tzfd_rows );
    }

    /** Records of one kind, oldest first, limited to $limit. Used by queues. */
    public static function list_kind( $addon, $kind, $limit = 50 ) {
        global $wpdb;
        $tzfd_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE addon = %s AND kind = %s ORDER BY id ASC LIMIT %d",
            self::table(), sanitize_key( $addon ), sanitize_key( $kind ), max( 1, min( 500, (int) $limit ) )
        ) );
        return array_map( array( __CLASS__, 'hydrate' ), (array) $tzfd_rows );
    }

    /** Records of one kind whose reference starts with $prefix, oldest first. */
    public static function list_prefix( $addon, $kind, $prefix, $limit = 500 ) {
        global $wpdb;
        $tzfd_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE addon = %s AND kind = %s AND ref LIKE %s ORDER BY id ASC LIMIT %d",
            self::table(), sanitize_key( $addon ), sanitize_key( $kind ), self::like( $prefix ) . '%', max( 1, min( 5000, (int) $limit ) )
        ) );
        return array_map( array( __CLASS__, 'hydrate' ), (array) $tzfd_rows );
    }

    /** Number of records of one kind whose reference starts with $prefix. */
    public static function count_prefix( $addon, $kind, $prefix ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE addon = %s AND kind = %s AND ref LIKE %s",
            self::table(), sanitize_key( $addon ), sanitize_key( $kind ), self::like( $prefix ) . '%'
        ) );
    }

    /** Escapes LIKE wildcards so a prefix matches literally. */
    private static function like( $text ) {
        return addcslashes( self::clean_ref( $text ), '_%\\' );
    }

    public static function encode( array $payload ) {
        $tzfd_json = wp_json_encode( $payload );
        if ( ! is_string( $tzfd_json ) || strlen( $tzfd_json ) > self::MAX_PAYLOAD ) { return null; }
        return $tzfd_json;
    }

    public static function hydrate( $row ) {
        $row     = (object) $row;
        $payload = json_decode( (string) ( $row->payload ?? '' ), true );
        return array(
            'id'         => (int) ( $row->id ?? 0 ),
            'addon'      => (string) ( $row->addon ?? '' ),
            'kind'       => (string) ( $row->kind ?? '' ),
            'ref'        => (string) ( $row->ref ?? '' ),
            'booking_id' => (int) ( $row->booking_id ?? 0 ),
            'payload'    => is_array( $payload ) ? $payload : array(),
            'created'    => (string) ( $row->created ?? '' ),
        );
    }
}
