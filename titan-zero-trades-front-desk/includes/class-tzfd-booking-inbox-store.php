<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Persistent, provider-neutral booking inbox boundary. No automatic booking mutations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Inbox_Store {
    public static function table() { return TZFD_Tables::checked_name( TZFD_Tables::BOOKING_INBOX ); }
    public static function installed() {
        global $wpdb; $table = self::table();
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }
    /** Explicit privileged operation; do not install during ordinary page loads. */
    public static function install() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return false; }
        if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $table = self::table(); $collate = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            event_hash char(64) NOT NULL,
            channel varchar(20) NOT NULL,
            intent varchar(32) NOT NULL,
            priority varchar(20) NOT NULL,
            booking_id bigint(20) unsigned DEFAULT NULL,
            customer_id bigint(20) unsigned DEFAULT NULL,
            state varchar(24) NOT NULL DEFAULT 'validated',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY company_event (company_id,event_hash),
            KEY company_state (company_id,state,created_at)
        ) $collate;" );
        return self::installed();
    }
    /** Caller supplies facts from an authenticated channel adapter, never raw HTTP fields. */
    public static function receive( $company_id, $event, $verified = false ) {
        global $wpdb;
        $item = TZFD_Booking_Inbox::normalize( $company_id, $event, $verified );
        if ( empty( $item['ok'] ) || ! self::installed() ) { return array( 'ok' => false, 'code' => 'invalid_or_uninstalled' ); }
        $table = self::table();
        $changed = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (company_id,event_hash,channel,intent,priority,booking_id,customer_id,state,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,NULLIF(%d,0),NULLIF(%d,0),'validated',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            $table, $company_id, $item['dedupe_key'], $item['channel'], $item['intent'], $item['priority'],
            null === $item['booking_id'] ? 0 : $item['booking_id'],
            null === $item['workspace_customer_id'] ? 0 : $item['workspace_customer_id'] ) );
        if ( 1 === (int) $changed && false !== $changed ) {
            return array( 'ok' => true, 'inserted' => true, 'deduplicated' => false,
                'event_hash' => $item['dedupe_key'], 'booking_mutated' => false, 'message_sent' => false );
        }
        // A genuine duplicate-key error may be an idempotent retry, but no other
        // SQL failure, warning, or unverified zero-row write may be acknowledged.
        $error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
        if ( false === $changed && ! preg_match( '/(?:Duplicate entry|duplicate key)/i', $error ) ) {
            return array( 'ok' => false, 'code' => 'database_error' );
        }
        if ( false !== $changed ) { return array( 'ok' => false, 'code' => 'unexpected_write_result' ); }
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT channel,intent,priority,booking_id,customer_id FROM %i WHERE company_id=%d AND event_hash=%s",
            $table, $company_id, $item['dedupe_key'] ), ARRAY_A );
        if ( ! is_array( $existing ) || (string) ( $existing['channel'] ?? '' ) !== $item['channel'] ||
            (string) ( $existing['intent'] ?? '' ) !== $item['intent'] ||
            (string) ( $existing['priority'] ?? '' ) !== $item['priority'] ||
            self::same_nullable_id( $existing['booking_id'] ?? null, $item['booking_id'] ) === false ||
            self::same_nullable_id( $existing['customer_id'] ?? null, $item['workspace_customer_id'] ) === false ) {
            return array( 'ok' => false, 'code' => 'conflicting_duplicate' );
        }
        return array( 'ok' => true, 'inserted' => false, 'deduplicated' => true,
            'event_hash' => $item['dedupe_key'], 'booking_mutated' => false, 'message_sent' => false );
    }
    private static function same_nullable_id( $a, $b ) {
        // Older %s inserts converted absent nullable bigint IDs to zero on MySQL.
        // Zero is never a valid owner ID; accept that historical empty representation only.
        if ( null === $b && ( null === $a || 0 === $a || '0' === $a ) ) { return true; }
        if ( null === $a || null === $b ) { return false; }
        return ctype_digit( (string) $a ) && ctype_digit( (string) $b ) &&
            (string) (int) $a === (string) (int) $b;
    }

    /** Read company-scoped minimal inbox metadata. Auth is mandatory. */
    public static function recent( $company_id, $authorized = false, $limit = 50 ) {
        global $wpdb;
        if ( true !== $authorized || ! is_int( $company_id ) || $company_id < 1 || ! self::installed() ) { return array( 'ok' => false ); }
        $limit = max( 1, min( 100, (int) $limit ) ); $table = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare("SELECT id,channel,intent,priority,booking_id,customer_id,state,created_at FROM %i WHERE company_id=%d ORDER BY id DESC LIMIT %d", $table, $company_id, $limit), ARRAY_A );
        return array( 'ok' => is_array( $rows ), 'items' => is_array( $rows ) ? $rows : array() );
    }
}
