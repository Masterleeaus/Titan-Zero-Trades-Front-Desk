<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Front Desk-owned transactional audit journal: independent of Workspace. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Local_Audit {
    const TABLE = 'tz_fd_audit_log';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Called during activation and the versioned standalone upgrade. */
    public static function install() {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_charset_collate' ) || ! function_exists( 'dbDelta' ) ) { return false; }
        $name = self::table_name();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_type varchar(12) NOT NULL DEFAULT 'system',
            event_action varchar(80) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            summary varchar(250) NOT NULL DEFAULT '',
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY company_id (company_id),
            KEY entity_lookup (company_id,entity_type,entity_id)
        ) {$charset};" );
        // Validate the table rather than assuming dbDelta succeeded.
        return self::TABLE === substr( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), strlen( $wpdb->prefix ) );
    }

    /** Insert into the SAME wpdb connection and transaction as the source change. */
    public static function insert( $company, $actor, $actor_type, $event ) {
        global $wpdb;
        if ( ! is_array( $event ) || ! is_int( $company ) || $company < 1 || ! is_int( $actor ) || $actor < 0 ||
            ! in_array( $actor_type, array( 'user', 'system' ), true ) ) { return false; }
        foreach ( array( 'action', 'entity', 'entity_id', 'summary' ) as $key ) {
            if ( ! array_key_exists( $key, $event ) ) { return false; }
        }
        if ( ! is_string( $event['action'] ) || ! preg_match( '/^[a-z][a-z0-9._-]{0,79}$/D', $event['action'] ) ||
            ! is_string( $event['entity'] ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,49}$/D', $event['entity'] ) ||
            ! is_int( $event['entity_id'] ) || $event['entity_id'] < 1 ||
            ! is_string( $event['summary'] ) || strlen( $event['summary'] ) > 250 ||
            preg_match( '/[\x00-\x1F\x7F]/', $event['summary'] ) ) { return false; }
        try {
            $result = $wpdb->insert( self::table_name(), array(
                'company_id' => $company, 'actor_id' => $actor, 'actor_type' => $actor_type,
                'event_action' => $event['action'], 'entity_type' => $event['entity'],
                'entity_id' => $event['entity_id'], 'summary' => $event['summary'],
                'created_utc' => gmdate( 'Y-m-d H:i:s' ),
            ), array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
        } catch ( Throwable $error ) { return false; }
        return 1 === $result && empty( $wpdb->last_error );
    }
}
