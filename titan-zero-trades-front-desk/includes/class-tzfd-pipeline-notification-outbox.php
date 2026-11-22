<?php
/** Durable, at-least-once pipeline decision hook dispatcher (not a provider delivery receipt). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Dedicated, uncached plugin-owned queue.
final class TZFD_Pipeline_Notification_Outbox {
    const TABLE = 'tz_pipeline_notification_outbox';
    const OPTION = 'tzfd_pipeline_notification_outbox_db_v1';
    const HOOK = 'tzfd_pipeline_notifications_dispatch_v1';
    const WAKE = 'tzfd_pipeline_notifications_wake_v1';
    const MAX_ATTEMPTS = 8;

    private static function table() { global $wpdb; return $wpdb->prefix . self::TABLE; }

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'install' ), 20 );
        add_action( self::HOOK, array( __CLASS__, 'dispatch' ) );
        add_action( self::WAKE, array( __CLASS__, 'dispatch' ) );
    }

    public static function install() {
        // The stored schema version is the marker. The information_schema check runs only while it is behind.
        $installed = get_option( self::OPTION ) === '1';
        if ( ! $installed ) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = self::table();
            $collate = $wpdb->get_charset_collate();
            dbDelta( "CREATE TABLE {$table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id BIGINT(20) UNSIGNED NOT NULL,
                booking_id BIGINT(20) UNSIGNED NOT NULL,
                identity_hash CHAR(64) NOT NULL,
                from_state VARCHAR(20) NOT NULL,
                to_state VARCHAR(20) NOT NULL,
                stage VARCHAR(20) NOT NULL,
                booking_status VARCHAR(30) NOT NULL,
                reason VARCHAR(500) NOT NULL DEFAULT '',
                state VARCHAR(12) NOT NULL DEFAULT 'pending',
                attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                lease_token CHAR(64) NOT NULL DEFAULT '',
                locked_until DATETIME DEFAULT NULL,
                available_at DATETIME NOT NULL,
                last_error VARCHAR(100) NOT NULL DEFAULT '',
                created DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY identity_hash (identity_hash),
                KEY ready (state, available_at),
                KEY company_booking (company_id, booking_id)
            ) ENGINE=InnoDB {$collate};" );
            $installed = self::ready();
            if ( $installed ) { update_option( self::OPTION, '1', false ); }
        }
        if ( $installed && ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'hourly', self::HOOK );
        }
    }

    public static function ready() {
        global $wpdb;
        try {
            $engine = $wpdb->get_var( $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', self::table()
            ) );
            return is_string( $engine ) && 'INNODB' === strtoupper( $engine );
        } catch ( Throwable $exception ) {
            return false;
        }
    }

    /** Call *inside* the booking decision transaction; caller must roll back on false. */
    public static function stage( $company, $booking, $from, $to, $stage, $status, $reason ) {
        global $wpdb;
        if ( $company < 1 || $booking < 1 || ! self::ready() ) { return false; }
        // Repeated transitions to the same state can occur after a future escalation;
        // include a state-event identifier supplied by the caller in the identity.
        $last_event = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE company_id=%d AND booking_id=%d ORDER BY id DESC LIMIT 1',
            $wpdb->prefix . TZFD_Tables::PIPELINE_EVENTS, $company, $booking
        ) );
        if ( ! $last_event ) { return false; }
        $identity = hash( 'sha256', $company . ':' . $booking . ':' . (int) $last_event );
        return 1 === (int) $wpdb->insert( self::table(), array(
            'company_id' => $company, 'booking_id' => $booking, 'identity_hash' => $identity,
            'from_state' => substr( $from, 0, 20 ), 'to_state' => substr( $to, 0, 20 ),
            'stage' => substr( $stage, 0, 20 ), 'booking_status' => substr( $status, 0, 30 ),
            'reason' => substr( sanitize_text_field( $reason ), 0, 500 ),
            'state' => 'pending', 'available_at' => current_time( 'mysql', true ),
            'created' => current_time( 'mysql', true ),
        ) );
    }

    /** Replace a later one-off wake with an earlier retry, preserving the prior wake on failure. */
    public static function schedule_after( $seconds = 10 ) {
        $when = time() + max( 10, min( 7200, (int) $seconds ) );
        $existing = wp_next_scheduled( self::WAKE );
        if ( ! $existing ) { return (bool) wp_schedule_single_event( $when, self::WAKE ); }
        if ( (int) $existing <= $when ) { return true; }
        if ( ! wp_unschedule_event( (int) $existing, self::WAKE ) ) { return false; }
        if ( wp_schedule_single_event( $when, self::WAKE ) ) { return true; }
        wp_schedule_single_event( (int) $existing, self::WAKE );
        return false;
    }
    public static function schedule() { return self::schedule_after( 10 ); }

    /** Final failures remain inspectable; automatic workers must not retry forever. */
    public static function retry_disposition( $attempts ) {
        $attempts = max( 1, (int) $attempts );
        return $attempts >= self::MAX_ATTEMPTS ? array( 'failed', 0 )
            : array( 'pending', min( 3600, 30 * ( 2 ** min( $attempts, 7 ) ) ) );
    }

    /** Scoped operational counts for a business. Never expose another company's queue. */
    public static function failure_counts( $company ) {
        global $wpdb;
        $company = (int) $company;
        if ( $company < 1 || ! self::ready() ) { return array( 'failed' => 0, 'pending' => 0 ); }
        $table = self::table();
        $counts = array( 'failed' => 0, 'pending' => 0 );
        foreach ( array_keys( $counts ) as $state ) {
            $counts[ $state ] = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE company_id=%d AND state=%s', $table, $company, $state
            ) );
        }
        return $counts;
    }

    /** Explicit operator recovery; no public request handler is registered here. */
    public static function retry_failed( $company, $id ) {
        global $wpdb;
        $company = (int) $company;
        $id = (int) $id;
        if ( $company < 1 || $id < 1 || ! function_exists( 'current_user_can' ) ||
            ! current_user_can( 'manage_options' ) ||
            ! class_exists( 'TZFD_Scoped_Store' ) ||
            TZFD_Scoped_Store::company_id() !== $company || ! self::ready() ) {
            return false;
        }
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET state='pending',attempts=0,lease_token='',locked_until=NULL,available_at=UTC_TIMESTAMP(),last_error='' WHERE id=%d AND company_id=%d AND state='failed'",
            self::table(), $id, $company
        ) );
        if ( 1 !== (int) $updated ) { return false; }
        self::schedule();
        return true;
    }

    /** Confirm the persisted booking belongs to the queued company before invoking hooks. */
    private static function booking_owner_error( $row ) {
        global $wpdb;
        if ( ! class_exists( 'TZFD_Scoped_Store' ) ||
            ! method_exists( 'TZFD_Scoped_Store', 'company_for_form' ) ||
            ! class_exists( 'TZFD_Tables' ) ||
            ! isset( $row['company_id'], $row['booking_id'] ) ||
            (int) $row['company_id'] < 1 || (int) $row['booking_id'] < 1 ) {
            return 'owner_lookup_unavailable';
        }
        try {
            $form = $wpdb->get_var( $wpdb->prepare(
                'SELECT formid FROM %i WHERE id=%d',
                $wpdb->prefix . TZFD_Tables::BOOKINGS, (int) $row['booking_id']
            ) );
            if ( null === $form || false === $form || (int) $form < 1 ) {
                return 'booking_lookup_unavailable';
            }
            $owner = (int) TZFD_Scoped_Store::company_for_form( (int) $form );
            if ( $owner < 1 ) { return 'owner_lookup_unavailable'; }
            return $owner === (int) $row['company_id'] ? '' : 'booking_company_mismatch';
        } catch ( Throwable $exception ) {
            return 'owner_lookup_unavailable';
        }
    }

    /** Claimed rows are retried after crash/timeout; hook consumers MUST be idempotent. */
    public static function dispatch( $limit = 20 ) {
        global $wpdb;
        if ( ! self::ready() ) { return array( 'dispatched' => 0, 'retried' => 0, 'failed' => 0 ); }
        $table = self::table();
        $counts = array( 'dispatched' => 0, 'retried' => 0, 'failed' => 0 );
        $batch_limit = max( 1, min( 50, (int) $limit ) );
        try {
            // Fetch one extra ID to detect backlog without exceeding the processing budget.
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM %i WHERE (state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()) ORDER BY id LIMIT %d",
                $table, $batch_limit + 1
            ) );
        } catch ( Throwable $exception ) {
            // Database outages are temporary; retain all queued rows for a later sweep.
            self::schedule_recovery_after_exception();
            return $counts;
        }
        if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) {
            // A failed ready-row query is not an empty queue. Retry after the
            // longest active lease so potentially claimed rows can recover.
            self::schedule_recovery_after_exception();
            return $counts;
        }
        $ready_ids = $ids;
        $has_backlog = count( $ready_ids ) > $batch_limit;
        foreach ( array_slice( $ready_ids, 0, $batch_limit ) as $id ) {
            try {
            $token = bin2hex( random_bytes( 32 ) );
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE %i SET state='leased',lease_token=%s,locked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND),attempts=attempts+1 WHERE id=%d AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()))",
                $table, $token, (int) $id
            ) );
            if ( 1 !== (int) $claimed ) { continue; }
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, (int) $id ), ARRAY_A );
            if ( ! is_array( $row ) || ! hash_equals( $token, (string) ( $row['lease_token'] ?? '' ) ) ) {
                // A successful claim followed by a lost read cannot be treated
                // as normal queue exhaustion. Its lease needs a recovery wake.
                self::schedule_recovery_after_exception();
                continue;
            }
            // A crashed worker can have consumed the last allowed attempt before
            // losing its lease. Quarantine that row without repeating its hooks.
            if ( (int) $row['attempts'] > self::MAX_ATTEMPTS ) {
                if ( self::quarantine( $table, (int) $id, $token, 'attempt_limit' ) ) { ++$counts['failed']; }
                else { self::schedule_recovery_after_exception(); }
                continue;
            }
            $error = self::booking_owner_error( $row );
            try {
                // A do_action without listeners is a no-op. Never acknowledge it as delivery.
                if ( '' !== $error ) {
                    // Unknown ownership is retryable; confirmed mismatches are terminal.
                } else {
                $hooks = array(
                    'tzfd_update_status',
                    'tzfd_booking_status_persisted',
                    'tzfd_pipeline_moved',
                    // Legacy names: listeners registered on them still run through the alias calls below.
                    'tz_fd_update_status',
                    'titan_zero_front_desk_booking_status_persisted',
                    'tz_fd_pipeline_moved',
                );
                $listeners = false;
                foreach ( $hooks as $hook ) {
                    if ( false !== has_action( $hook ) ) { $listeners = true; break; }
                }
                if ( ! $listeners ) {
                    $error = 'hook_unavailable';
                } else {
                    // Acknowledgement means hooks ran without exceptions, not external delivery.
                    do_action( 'tzfd_update_status', (int) $row['booking_id'], $row['booking_status'] );
                    do_action( 'tz_fd_update_status', (int) $row['booking_id'], $row['booking_status'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy alias: fires the pre-rename hook name for one release
                    do_action( 'tzfd_booking_status_persisted', (int) $row['booking_id'], $row['booking_status'], '-1' );
                    do_action( 'titan_zero_front_desk_booking_status_persisted', (int) $row['booking_id'], $row['booking_status'], '-1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy alias: fires the pre-rename hook name for one release
                    do_action( 'tzfd_pipeline_moved', (int) $row['booking_id'], $row['from_state'], $row['to_state'], $row['stage'], $row['reason'] );
                    do_action( 'tz_fd_pipeline_moved', (int) $row['booking_id'], $row['from_state'], $row['to_state'], $row['stage'], $row['reason'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy alias: fires the pre-rename hook name for one release
                }
                }
            } catch ( Throwable $e ) { $error = 'hook_exception'; }
            if ( '' === $error ) {
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE %i SET state='dispatched',lease_token='',locked_until=NULL,last_error='' WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()",
                    $table, (int) $id, $token
                ) );
                if ( 1 === (int) $updated ) { ++$counts['dispatched']; }
                else {
                    // Hook execution may have happened; do not invent an ACK.
                    // Retry only after the lease expires (at-least-once semantics).
                    self::schedule_recovery_after_exception();
                }
            } else {
                list( $next, $delay ) = self::retry_disposition( $row['attempts'] );
                if ( 'booking_company_mismatch' === $error ) { $next = 'failed'; }
                if ( 'failed' === $next ) {
                    if ( self::quarantine( $table, (int) $id, $token, $error ) ) { ++$counts['failed']; }
                    else { self::schedule_recovery_after_exception(); }
                } else {
                    $updated = $wpdb->query( $wpdb->prepare(
                        "UPDATE %i SET state='pending',lease_token='',locked_until=NULL,available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),last_error=%s WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()",
                        $table, $delay, $error, (int) $id, $token
                    ) );
                    if ( 1 === (int) $updated ) { ++$counts['retried']; self::schedule_after( $delay ); }
                    else { self::schedule_recovery_after_exception(); }
                }
            }
            } catch ( Throwable $exception ) {
                // If the row was leased, its 120-second lease will expire naturally.
                // Never acknowledge an uncertain write or stop processing other rows.
                self::schedule_recovery_after_exception();
                continue;
            }
        }
        // Don't leave the next ready batch waiting for the hourly safety sweep.
        // A later cron event may already exist; schedule_after retains the earlier one.
        if ( $has_backlog ) {
            try { self::schedule_after( 10 ); } catch ( Throwable $exception ) {
                // The hourly recovery hook remains the fallback.
            }
        }
        return $counts;
    }
    private static function schedule_recovery_after_exception() {
        try {
            // Beyond the 120-second lease, so interrupted claims can be recovered.
            self::schedule_after( 130 );
        } catch ( Throwable $exception ) {
            // The hourly recovery event remains the fallback if cron itself is unavailable.
        }
    }
    private static function quarantine( $table, $id, $token, $reason ) {
        global $wpdb;
        return 1 === (int) $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET state='failed',lease_token='',locked_until=NULL,last_error=%s WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()",
            $table, $reason, $id, $token
        ) );
    }

}
