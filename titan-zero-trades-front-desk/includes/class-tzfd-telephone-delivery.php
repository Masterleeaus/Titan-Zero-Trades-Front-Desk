<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Pass 47: durable, provider-neutral telephone action delivery. No vendor is enabled by default. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Telephone_Delivery {
    const HOOK = 'tzfd_telephone_delivery_dispatch_v1';
    const ACTIONS = array( 'callback', 'escalation' );
    public static function table() { return TZFD_Tables::checked_name( TZFD_Tables::TELEPHONE_DELIVERY ); }
    public static function installed() {
        global $wpdb; $t = self::table();
        return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
    }
    /** Explicit administrator-only schema operation. */
    public static function install() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return false; }
        if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $t = self::table(); $c = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            event_hash char(64) NOT NULL,
            call_id bigint(20) unsigned NOT NULL,
            action_type varchar(16) NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            state varchar(16) NOT NULL DEFAULT 'pending',
            attempts smallint unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            lease_token char(64) NOT NULL DEFAULT '',
            locked_until datetime DEFAULT NULL,
            receipt_id varchar(120) NOT NULL DEFAULT '',
            last_error varchar(80) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY company_event (company_id,event_hash),
            KEY receipt_lookup (receipt_id),
            KEY pending_due (state,available_at)
        ) $c;" );
        return self::schema_ready();
    }
    /** Verify required columns after dbDelta; table existence alone is not an upgrade success. */
    public static function schema_ready() {
        global $wpdb;
        if ( ! self::installed() ) { return false; }
        $table = self::table(); // Registered, checked table name; never caller-supplied.
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0  );
        if ( ! is_array( $columns ) ) { return false; }
        $actual = array_fill_keys( $columns, true );
        foreach ( array( 'company_id', 'event_hash', 'call_id', 'lease_token', 'locked_until', 'receipt_id', 'available_at' ) as $column ) {
            if ( ! isset( $actual[ $column ] ) ) { return false; }
        }
        return true;
    }
    public static function valid( $company, $call, $action, $recipient, $id ) {
        return is_int( $company ) && $company > 0 && is_int( $call ) && $call > 0 &&
            in_array( $action, self::ACTIONS, true ) && is_int( $recipient ) && $recipient > 0 &&
            is_string( $id ) && (bool) preg_match( '/^[A-Za-z0-9_.:-]{8,120}$/D', $id );
    }
    /** Called only by trusted server-side workflow owners, never by a public request body. */
    public static function enqueue( $company, $call, $action, $recipient, $id ) {
        global $wpdb;
        if ( ! self::valid( $company, $call, $action, $recipient, $id ) || ! self::installed() ) { return false; }
        // Require both a trusted call ownership result and recipient authorisation.
        if ( true !== apply_filters( 'tzfd_telephone_verify_call_owner_v1', false, $company, $call ) ||
             true !== apply_filters( 'tzfd_telephone_verify_recipient_v1', false, $company, $recipient, $action ) ) { return false; }
        $t = self::table(); $hash = hash( 'sha256', $company . ':' . $action . ':' . $id );
        $ok = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (company_id,event_hash,call_id,action_type,recipient_id,available_at) VALUES (%d,%s,%d,%s,%d,UTC_TIMESTAMP())", $t, $company, $hash, $call, $action, $recipient ) );
        $duplicate_error = false;
        // Do not treat ignored/truncated/failed writes as a successful duplicate.
        // Only MySQL's actual duplicate-key error (1062) starts idempotent verification.
        if ( false === $ok ) {
            if ( ! isset( $wpdb->dbh ) || ! ( $wpdb->dbh instanceof mysqli ) || 1062 !== (int) $wpdb->dbh->errno ) { return false; }
            $duplicate_error = true;
        } elseif ( 1 !== (int) $ok ) {
            return false;
        }
        if ( $duplicate_error ) {
            $existing = $wpdb->get_row( $wpdb->prepare("SELECT call_id,action_type,recipient_id FROM %i WHERE company_id=%d AND event_hash=%s", $t, $company, $hash), ARRAY_A );
            if ( ! is_array( $existing ) || ! isset( $existing['call_id'], $existing['action_type'], $existing['recipient_id'] ) ||
                (int) $existing['call_id'] !== $call || $existing['action_type'] !== $action ||
                (int) $existing['recipient_id'] !== $recipient ) { return false; }
        }
        self::schedule();
        return true; // Queued or identical retry; not delivered.
    }
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) { return wp_schedule_single_event( time() + 10, self::HOOK ); }
        return true;
    }
    /**
     * Schedule the next pending attempt, including retries not yet due and
     * leases that may need recovery after worker interruption.
     * Database DATETIME values are UTC; never parse them as the site timezone.
     */
    public static function next_due_timestamp( $value, $now = null ) {
        if ( ! is_string( $value ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value ) ) { return false; }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
        if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) { return false; }
        $now = null === $now ? time() : (int) $now;
        return max( $now + 10, $date->getTimestamp() );
    }
    public static function schedule_pending() {
        global $wpdb;
        $table = self::table();
        $next = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(CASE WHEN state='pending' THEN available_at WHEN state='leased' THEN locked_until ELSE NULL END) FROM %i WHERE state IN ('pending','leased')", $table ) );
        if ( null === $next ) { return false; }
        $when = self::next_due_timestamp( $next );
        if ( false === $when ) { return false; }
        // Do not replace an earlier job, and do not create duplicate cron events.
        $existing = wp_next_scheduled( self::HOOK );
        if ( $existing && $existing <= $when ) { return true; }
        if ( $existing ) {
            // Retain the existing event if WP cannot atomically remove it.
            if ( ! wp_unschedule_event( $existing, self::HOOK ) ) { return false; }
        }
        return (bool) wp_schedule_single_event( $when, self::HOOK );
    }
    public static function boot() { add_action( self::HOOK, array( __CLASS__, 'dispatch' ) ); }
    /** Return validated receipt only; transport plugin owns actual outbound provider API. */
    public static function valid_receipt( $receipt, $company, $action, $delivery_id = null ) {
        return is_array( $receipt ) && isset( $receipt['ok'], $receipt['company_id'], $receipt['action'], $receipt['receipt_id'] ) &&
            true === $receipt['ok'] && (int) $receipt['company_id'] === $company && $receipt['action'] === $action &&
            is_string( $receipt['receipt_id'] ) && (bool) preg_match( '/^[A-Za-z0-9_.:-]{8,120}$/D', $receipt['receipt_id'] ) &&
            ( null === $delivery_id || ( isset( $receipt['delivery_id'] ) && is_string( $receipt['delivery_id'] ) && hash_equals( $delivery_id, $receipt['delivery_id'] ) ) );
    }
    public static function dispatch( $limit = 10 ) {
        global $wpdb;
        if ( ! self::installed() ) { return array( 'accepted' => 0, 'retried' => 0 ); }
        $limit = max( 1, min( 25, (int) $limit ) ); $t = self::table();
        $ids = $wpdb->get_col( $wpdb->prepare("SELECT id FROM %i WHERE (state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()) ORDER BY id LIMIT %d", $t, $limit) );
        $result = array( 'accepted' => 0, 'retried' => 0 );
        foreach ( $ids as $id ) {
            try { $token = bin2hex( random_bytes( 32 ) ); } catch ( Throwable $e ) { continue; }
            $claimed = $wpdb->query( $wpdb->prepare("UPDATE %i SET state='leased',lease_token=%s,locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),attempts=attempts+1 WHERE id=%d AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()))", $t, $token, $id) );
            if ( 1 !== (int) $claimed ) { continue; }
            $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM %i WHERE id=%d", $t, $id), ARRAY_A );
            if ( ! is_array( $row ) || ! isset( $row['lease_token'] ) || ! is_string( $row['lease_token'] ) || ! hash_equals( $token, $row['lease_token'] ) ) { continue; }
            // No provider installed => null receipt and retry. Never claim delivery from a queued request.
            try { $receipt = apply_filters( 'tzfd_telephone_deliver_v1', null, array( 'company_id' => (int) $row['company_id'], 'call_id' => (int) $row['call_id'], 'action' => $row['action_type'], 'recipient_id' => (int) $row['recipient_id'], 'delivery_id' => $row['event_hash'] ) ); }
            catch ( Throwable $e ) { $receipt = null; }
            if ( self::valid_receipt( $receipt, (int) $row['company_id'], $row['action_type'], $row['event_hash'] ) ) {
                $changed = $wpdb->query( $wpdb->prepare("UPDATE %i SET state='accepted',receipt_id=%s,lease_token='',locked_until=NULL WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()", $t, $receipt['receipt_id'], $id, $token) );
                if ( 1 === (int) $changed ) { ++$result['accepted']; }
            } else {
                $delay = min( 3600, 30 * ( 2 ** min( 7, (int) $row['attempts'] ) ) );
                $changed = $wpdb->query( $wpdb->prepare("UPDATE %i SET state='pending',available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),lease_token='',locked_until=NULL,last_error='delivery_not_acknowledged' WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()", $t, $delay, $id, $token) );
                if ( 1 === (int) $changed ) { ++$result['retried']; }
            }
        }
        // Include future retries and expired-lease recovery, not only rows due now.
        // Otherwise a worker that finds no immediately due work strands the queue.
        self::schedule_pending();
        return $result;
    }
}
