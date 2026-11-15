<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Durable delivery queue for *completed* Smart Tasks. Workspace remains the ledger owner. */
final class TZFD_Smart_Task_Outbox {
    const HOOK = 'tzfd_smart_task_outbox_dispatch_v1';
    public static function table() { return TZFD_Tables::checked_name( TZFD_Tables::SMART_TASK_OUTBOX ); }
    /** Explicit schema operation; call only after an authorised upgrade and backup. */
    public static function install() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return false; }
        if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $collate = $wpdb->get_charset_collate();
        $sql = 'CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            identity_hash char(64) NOT NULL,
            task_type varchar(64) NOT NULL,
            task_key varchar(160) NOT NULL,
            manual_seconds int unsigned NOT NULL DEFAULT 0,
            human_seconds int unsigned NOT NULL DEFAULT 0,
            completed_at varchar(20) NOT NULL,
            state varchar(16) NOT NULL DEFAULT 'pending',
            attempts smallint unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            locked_until datetime DEFAULT NULL,
            lease_token char(64) NOT NULL DEFAULT '',
            last_error varchar(100) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY company_identity (company_id,identity_hash),
            KEY pending_due (state,available_at)
        ) $collate;";
        dbDelta( $sql );
        return self::schema_ready();
    }
    public static function installed() {
        global $wpdb;
        $table = self::table();
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }
    /** Verify required columns after dbDelta; table existence alone is not an upgrade success. */
    public static function schema_ready() {
        global $wpdb;
        if ( ! self::installed() ) { return false; }
        $table = self::table(); // Registered, checked table name; never caller-supplied.
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0  );
        if ( ! is_array( $columns ) ) { return false; }
        $actual = array_fill_keys( $columns, true );
        foreach ( array( 'company_id', 'identity_hash', 'lease_token', 'locked_until', 'available_at', 'manual_seconds', 'human_seconds' ) as $column ) {
            if ( ! isset( $actual[ $column ] ) ) { return false; }
        }
        return true;
    }
    public static function valid( $company, $type, $key, $manual, $human, $at ) {
        return is_int( $company ) && $company > 0 && is_string( $type ) &&
            in_array( $type, array( 'booking.confirmed', 'booking.rescheduled', 'booking.cancelled', 'booking.reminder_delivered', 'call.callback_completed', 'call.recovery_delivered', 'call.routing_completed', 'booking.waitlist_filled' ), true ) &&
            is_string( $key ) && (bool) preg_match( '/^[a-zA-Z0-9_.:-]{8,160}$/D', $key ) &&
            is_int( $manual ) && $manual >= 0 && $manual <= 86400 &&
            is_int( $human ) && $human >= 0 && $human <= 86400 &&
            is_string( $at ) && (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $at ) &&
            ($date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $at, new DateTimeZone( 'UTC' ) )) && $date->format( 'Y-m-d\TH:i:s\Z' ) === $at;
    }
    public static function enqueue( $company, $type, $key, $manual = 0, $human = 0, $at = '', $schedule = true ) {
        global $wpdb;
        if ( '' === $at ) { $at = gmdate( 'Y-m-d\TH:i:s\Z' ); }
        if ( ! self::valid( $company, $type, $key, $manual, $human, $at ) || ! self::installed() ) { return false; }
        $identity = hash( 'sha256', $company . ':' . $type . ':' . $key );
        $table = self::table();
        // Do not use INSERT IGNORE: MySQL can downgrade non-duplicate errors to warnings.
        // A duplicate identity is successful only when its immutable payload matches.
        $result = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (company_id,identity_hash,task_type,task_key,manual_seconds,human_seconds,completed_at,state,available_at) VALUES (%d,%s,%s,%s,%d,%d,%s,'pending',UTC_TIMESTAMP())", $table, $company, $identity, $type, $key, $manual, $human, $at ) );
        if ( 1 === (int) $result ) {
            if ( $schedule ) { self::schedule_dispatch(); }
            return true;
        }
        // The only acceptable failure is a duplicate of the unique company+identity key.
        // wpdb's last_error is not a safe discriminator across database drivers;
        // inspect the MySQL error code if available, and never accept other failures.
        $duplicate = false;
        if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) {
            $duplicate = 1062 === (int) $wpdb->dbh->errno;
        }
        if ( ! $duplicate ) { return false; }
        $stored = $wpdb->get_row( $wpdb->prepare("SELECT company_id,identity_hash,task_type,task_key,manual_seconds,human_seconds,completed_at FROM %i WHERE company_id=%d AND identity_hash=%s LIMIT 1", $table, $company, $identity), ARRAY_A );
        if ( ! is_array( $stored ) ||
            (int) ( $stored['company_id'] ?? 0 ) !== $company ||
            ! hash_equals( $identity, (string) ( $stored['identity_hash'] ?? '' ) ) ||
            (string) ( $stored['task_type'] ?? '' ) !== $type ||
            (string) ( $stored['task_key'] ?? '' ) !== $key ||
            (int) ( $stored['manual_seconds'] ?? -1 ) !== $manual ||
            (int) ( $stored['human_seconds'] ?? -1 ) !== $human ||
            (string) ( $stored['completed_at'] ?? '' ) !== $at ) { return false; }
        if ( $schedule ) { self::schedule_dispatch(); }
        return true;
    }

    public static function schedule_dispatch() {
        if ( ! wp_next_scheduled( self::HOOK ) ) { return wp_schedule_single_event( time() + 10, self::HOOK ); }
        return true;
    }
    public static function boot() { add_action( self::HOOK, array( __CLASS__, 'dispatch' ) ); }
    public static function dispatch( $limit = 25 ) {
        global $wpdb;
        if ( ! self::installed() ) { return array( 'accepted' => 0, 'retried' => 0 ); }
        $table = self::table();
        $limit = max( 1, min( 50, (int) $limit ) );
        // Atomic claim with lease; crashed workers become eligible again after lease expiry.
        $ids = $wpdb->get_col( $wpdb->prepare("SELECT id FROM %i WHERE (state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()) ORDER BY id ASC LIMIT %d", $table, $limit) );
        $counts = array( 'accepted' => 0, 'retried' => 0 );
        foreach ( $ids as $id ) {
            // Fence late workers: a previous lease may expire during provider delivery.
            try { $lease_token = bin2hex( random_bytes( 32 ) ); }
            catch ( Throwable $e ) { continue; }
            $claimed = $wpdb->query( $wpdb->prepare("UPDATE %i SET state='leased',lease_token=%s,locked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND),attempts=attempts+1 WHERE id=%d AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()))", $table, $lease_token, $id) );
            if ( 1 !== (int) $claimed ) { continue; }
            $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM %i WHERE id=%d", $table, $id), ARRAY_A );
            if ( ! is_array( $row ) || ! isset( $row['lease_token'] ) || ! is_string( $row['lease_token'] ) || ! hash_equals( $lease_token, $row['lease_token'] ) ) { continue; }
            try {
                $receipt = TZFD_Smart_Tasks::report_completed( (int) $row['company_id'], $row['task_type'], $row['task_key'], (int) $row['manual_seconds'], (int) $row['human_seconds'], $row['completed_at'] );
            } catch ( Throwable $e ) { $receipt = array( 'ok' => false, 'error' => 'dispatch_exception' ); }
            if ( is_array( $receipt ) && true === ( $receipt['ok'] ?? false ) && isset( $receipt['recorded'] ) && is_bool( $receipt['recorded'] ) ) {
                $accepted = $wpdb->query( $wpdb->prepare("UPDATE %i SET state='accepted',lease_token='',locked_until=NULL,last_error='' WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()", $table, $id, $lease_token) );
                if ( 1 === (int) $accepted ) { $counts['accepted']++; }
            } else {
                $attempts = (int) $row['attempts'];
                $delay = min( 3600, 30 * ( 2 ** min( $attempts, 7 ) ) );
                $error = sanitize_key( (string) ( $receipt['error'] ?? 'unverified_receipt' ) );
                $wpdb->query( $wpdb->prepare("UPDATE %i SET state='pending',lease_token='',locked_until=NULL,available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),last_error=%s WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()", $table, $delay, substr( $error, 0, 100 ), $id, $lease_token) );
                if ( 1 === (int) $wpdb->rows_affected ) { $counts['retried']++; }
            }
        }
        // WP-Cron alone is traffic-dependent. Add a real cron driver in production.
        $pending = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE state IN ('pending','leased') LIMIT 1", $table ) );
        if ( $pending && ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_single_event( time() + 120, self::HOOK ); }
        return $counts;
    }
}
