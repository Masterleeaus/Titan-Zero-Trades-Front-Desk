<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Durable, tenant-scoped customer booking lifecycle notifications. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Customer_Event_Outbox {
    const TABLE = 'tzfd_customer_event_outbox';
    const OPTION = 'tzfd_customer_event_outbox_v1';
    const HOOK = 'tzfd_customer_event_outbox_dispatch';
    const WAKE = 'tzfd_customer_event_outbox_wake';
    const MAX_ATTEMPTS = 8;
    const CURSOR_OPTION = 'tzfd_customer_event_company_cursor_v1';
    /** One limit for a queued payload: the encoded JSON, which contains the customer copy body. */
    const MAX_PAYLOAD_BYTES = 12000;

    private static function table() { global $wpdb; return $wpdb->prefix . self::TABLE; }
    public static function boot() {
        add_action( 'init', array( __CLASS__, 'install' ), 20 );
        add_action( self::HOOK, array( __CLASS__, 'dispatch_scheduled' ) );
        add_action( self::WAKE, array( __CLASS__, 'dispatch_scheduled' ) );
    }
    public static function install() {
        if ( get_option( self::OPTION ) !== '1' ) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = self::table();
            $collate = $wpdb->get_charset_collate();
            dbDelta( "CREATE TABLE {$table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id BIGINT(20) UNSIGNED NOT NULL,
                booking_id BIGINT(20) UNSIGNED NOT NULL,
                form_id BIGINT(20) UNSIGNED NOT NULL,
                event_type VARCHAR(20) NOT NULL,
                identity_hash CHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                state VARCHAR(12) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                lease_token CHAR(64) NOT NULL DEFAULT '',
                locked_until DATETIME DEFAULT NULL,
                available_at DATETIME NOT NULL,
                last_error VARCHAR(100) NOT NULL DEFAULT '',
                created DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY identity_hash (identity_hash),
                KEY ready (state,available_at),
                KEY company_booking (company_id,booking_id)
            ) ENGINE=InnoDB {$collate};" );
            if ( self::ready() ) { update_option( self::OPTION, '1', false ); }
        }
        if ( self::ready() && ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'hourly', self::HOOK );
        }
    }
    public static function transactional_tables_ready() {
        global $wpdb;
        foreach ( array( self::table(), $wpdb->prefix . TZFD_Tables::BOOKINGS ) as $table ) {
            $engine = $wpdb->get_var( $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ) );
            if ( ! is_string( $engine ) || strtoupper( $engine ) !== 'INNODB' ) { return false; }
        }
        return true;
    }
    public static function ready() {
        global $wpdb;
        try {
            $engine = $wpdb->get_var( $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', self::table()
            ) );
            return is_string( $engine ) && strtoupper( $engine ) === 'INNODB';
        } catch ( Throwable $error ) {
            return false;
        }
    }
    /** Only stage inside the caller's booking write transaction, before COMMIT. */
    public static function stage( $company, $booking, $form, $type, $version, $payload = array() ) {
        global $wpdb;
        if ( (int) $company < 1 || (int) $booking < 1 || (int) $form < 1 ||
            ! in_array( $type, array( 'cancelled', 'rescheduled', 'reschedule_email' ), true ) ||
            ! is_string( $version ) || ! preg_match( '/^[a-f0-9]{64}$/D', $version ) ||
            ! is_array( $payload ) ||
            ( 'reschedule_email' === $type && ! self::valid_reschedule_email( $payload ) ) ) { return false; }
        try {
            if ( ! self::transactional_tables_ready() ) { return false; }
        } catch ( Throwable $error ) { return false; }
        // Both booking and queue tables must participate in the same transaction.
        // An engine-query failure also prevents staging rather than escaping.
        // Defend at the write boundary too: a mis-scoped caller must not be
        // able to commit a durable event for an unrelated booking/form pair.
        // The caller must already be inside its guarded booking transaction.
        try {
            $booking_form = $wpdb->get_var( $wpdb->prepare(
                'SELECT formid FROM %i WHERE id=%d',
                $wpdb->prefix . TZFD_Tables::BOOKINGS, (int) $booking
            ) );
        } catch ( Throwable $error ) { return false; }
        if ( null === $booking_form || false === $booking_form ||
             (int) $booking_form !== (int) $form ) { return false; }
        // Staging must verify tenant ownership, not merely that the booking
        // matches the submitted form. Dispatch-time checks are too late: a
        // foreign-company queue record could otherwise be committed.
        if ( ! class_exists( 'TZFD_Scoped_Store' ) ||
             ! method_exists( 'TZFD_Scoped_Store', 'company_for_form' ) ) { return false; }
        try {
            $form_company = (int) TZFD_Scoped_Store::company_for_form( (int) $form );
        } catch ( Throwable $error ) { return false; }
        if ( $form_company < 1 || $form_company !== (int) $company ) { return false; }
        $identity = hash( 'sha256', $company . ':' . $booking . ':' . $type . ':' . $version );
        $json = wp_json_encode( $payload );
        if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) { return false; }
        return 1 === (int) $wpdb->insert( self::table(), array(
            'company_id' => (int) $company, 'booking_id' => (int) $booking, 'form_id' => (int) $form,
            'event_type' => $type, 'identity_hash' => $identity, 'payload' => $json,
            'state' => 'pending', 'available_at' => current_time( 'mysql', true ),
            'created' => current_time( 'mysql', true ),
        ) );
    }
    /** Validate the required confirmation before committing a customer reschedule. */
    public static function valid_reschedule_email( $payload ) {
        // The size check uses the same encoding stage() stores, so a payload that passes here is never refused by stage().
        $json = is_array( $payload ) ? wp_json_encode( $payload ) : false;
        return is_array( $payload ) &&
            isset( $payload['to'], $payload['subject'], $payload['body'], $payload['format'] ) &&
            is_string( $payload['to'] ) && (bool) is_email( $payload['to'] ) &&
            is_string( $payload['subject'] ) && strlen( $payload['subject'] ) <= 200 &&
            ! preg_match( '/[\x00-\x1F\x7F]/', $payload['subject'] ) &&
            is_string( $payload['body'] ) && strlen( $payload['body'] ) <= self::MAX_PAYLOAD_BYTES &&
            is_string( $json ) && strlen( $json ) <= self::MAX_PAYLOAD_BYTES &&
            in_array( $payload['format'], array( 'text', 'html' ), true );
    }

    /** A queue acknowledgement only means wp_mail accepted the message, not provider delivery. */
    public static function send_reschedule_email( $payload ) {
        if ( ! self::valid_reschedule_email( $payload ) ) { return false; }
        return (bool) wp_mail( $payload['to'], $payload['subject'], $payload['body'], array(
            'Content-Type: text/' . ( 'html' === $payload['format'] ? 'html' : 'plain' ) . '; charset=UTF-8'
        ) );
    }

    /** Schedule the earliest required retry without displacing an earlier wake. */
    public static function schedule_after( $seconds = 10 ) {
        $seconds = max( 10, min( 7200, (int) $seconds ) );
        $when = time() + $seconds;
        $existing = wp_next_scheduled( self::WAKE );
        if ( ! $existing ) {
            return (bool) wp_schedule_single_event( $when, self::WAKE );
        }
        if ( (int) $existing <= $when ) { return true; }
        // WordPress rejects near-duplicate single events. Remove the later
        // wake before scheduling an earlier one, restoring it on failure.
        // A failed removal must never be treated as a successful replacement.
        if ( ! wp_unschedule_event( (int) $existing, self::WAKE ) ) { return false; }
        if ( wp_schedule_single_event( $when, self::WAKE ) ) { return true; }
        wp_schedule_single_event( (int) $existing, self::WAKE );
        return false;
    }
    public static function schedule() { self::schedule_after( 10 ); }
    /** Interactive callers may only dispatch the company resolved by Workspace. */
    public static function dispatch( $limit = 20 ) {
        $company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
        return self::dispatch_company( $company, $limit, false );
    }

    /**
     * Trusted WP-Cron entry: enumerate only company IDs already present in the queue.
     * Never impersonate a logged-in user or set a global Workspace tenant context.
     * Every row is re-authorized against its form owner before invoking any hook.
     */
    public static function dispatch_scheduled() {
        global $wpdb;
        $totals = array( 'dispatched' => 0, 'retried' => 0, 'failed' => 0 );
        if ( ! self::ready() || ! class_exists( 'TZFD_Scoped_Store' ) ||
             ! method_exists( 'TZFD_Scoped_Store', 'company_for_form' ) ) { return $totals; }
        // Rotate the ready-company discovery cursor. The previous fixed ORDER BY
        // company_id LIMIT 51 could repeatedly service the same 50 active tenants
        // while higher IDs remained ready, even with continuation events.
        $cursor = max( 0, (int) get_option( self::CURSOR_OPTION, 0 ) );
        $page_size = 50;
        // The ready-state predicate is a constant with no user input, so it is written
        // inline in the prepared statement rather than interpolated from a variable.
        try {
            $after = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT company_id FROM %i WHERE company_id>%d AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP())) ORDER BY company_id LIMIT %d",
                self::table(), $cursor, $page_size + 1
            ) );
        } catch ( Throwable $error ) { self::schedule_lease_recovery(); return $totals; }
        if ( ! is_array( $after ) || ! empty( $wpdb->last_error ) ) { self::schedule_lease_recovery(); return $totals; }
        $companies = array_slice( $after, 0, $page_size + 1 );
        if ( count( $companies ) < $page_size + 1 && $cursor > 0 ) {
            // Wrap to the beginning of the company ID space. An extra result
            // detects a remaining page without processing more than 50 tenants.
            try {
                $before = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT company_id FROM %i WHERE company_id<=%d AND company_id>0 AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP())) ORDER BY company_id LIMIT %d",
                    self::table(), $cursor, $page_size + 1 - count( $companies )
                ) );
            } catch ( Throwable $error ) { self::schedule_lease_recovery(); return $totals; }
            if ( ! is_array( $before ) || ! empty( $wpdb->last_error ) ) { self::schedule_lease_recovery(); return $totals; }
            $companies = array_merge( $companies, $before );
        }
        $has_more_companies = count( $companies ) > $page_size;
        $processed = array_slice( $companies, 0, $page_size );
        foreach ( $processed as $company ) {
            $company = (int) $company;
            if ( $company < 1 ) { continue; }
            $counts = self::dispatch_company( $company, 20, true );
            foreach ( $totals as $key => $count ) { $totals[ $key ] += $counts[ $key ]; }
        }
        // Cursor is advisory, not a permission boundary. Every company/booking
        // is still checked for ownership at the point of notification delivery.
        if ( $processed ) {
            update_option( self::CURSOR_OPTION, max( 0, (int) end( $processed ) ), false );
        }
        if ( $has_more_companies ) { self::schedule(); }
        return $totals;
    }

    /** Wake only after an uncertain claim's 120-second lease has expired. */
    private static function schedule_lease_recovery() {
        try {
            self::schedule_after( 130 );
        } catch ( Throwable $error ) {
            // The hourly scheduled hook remains the secondary recovery path.
        }
    }

    private static function dispatch_company( $company, $limit, $background ) {
        global $wpdb;
        $counts = array( 'dispatched' => 0, 'retried' => 0, 'failed' => 0 );
        // Both manual and scheduled processing must have a verified form-owner
        // adapter. A missing Workspace adapter must never turn off tenancy checks.
        if ( $company < 1 || ! self::ready() ||
             ! class_exists( 'TZFD_Scoped_Store' ) ||
             ! method_exists( 'TZFD_Scoped_Store', 'company_for_form' ) ) { return $counts; }
        $table = self::table();
        try {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM %i WHERE company_id=%d AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP())) ORDER BY id LIMIT %d",
                $table, $company, max( 1, min( 50, (int) $limit ) )
            ) );
        } catch ( Throwable $error ) { self::schedule_lease_recovery(); return $counts; }
        if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) { self::schedule_lease_recovery(); return $counts; }
        foreach ( $ids as $id ) {
            // A database exception in one row must not halt the entire cron run.
            // Any acquired lease expires after 120 seconds and can be reclaimed.
            try {
                $token = bin2hex( random_bytes( 32 ) );
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE %i SET state='leased',lease_token=%s,locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),attempts=attempts+1 WHERE id=%d AND company_id=%d AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()))",
                $table, $token, (int) $id, $company
            ) );
            if ( 1 !== (int) $claimed ) { continue; }
            $row = $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM %i WHERE id=%d AND company_id=%d', $table, (int) $id, $company
            ), ARRAY_A );
            if ( ! is_array( $row ) || ! hash_equals( $token, (string) ( $row['lease_token'] ?? '' ) ) ) {
                // A claim succeeded but its acknowledgement token could not be read.
                // Leave the lease intact and wake after its expiry for safe recovery.
                self::schedule_lease_recovery();
                continue;
            }
            $error = '';
            // The authoritative owner lookup is allowed to fail during a DB outage.
            // A missing/unknown owner is NOT proof that another tenant owns the form.
            // Retry transient failures, but never dispatch while ownership is unknown.
            $owner = 0;
            try {
                $owner = (int) TZFD_Scoped_Store::company_for_form( (int) $row['form_id'] );
            } catch ( Throwable $exception ) {
                $error = 'owner_lookup_unavailable';
            }
            if ( '' === $error && $owner < 1 ) { $error = 'owner_lookup_unavailable'; }
            if ( '' === $error && $owner !== $company ) { $error = 'form_company_mismatch'; }
            // The owner adapter proves the form's tenant, not the queued
            // booking's relationship to that form. Verify both before hooks
            // capable of changing booking status are allowed to run.
            if ( '' === $error ) {
                try {
                    $booking_form = $wpdb->get_var( $wpdb->prepare(
                        'SELECT formid FROM %i WHERE id=%d',
                        $wpdb->prefix . TZFD_Tables::BOOKINGS, (int) $row['booking_id']
                    ) );
                    if ( null === $booking_form || false === $booking_form ) {
                        $error = 'booking_lookup_unavailable';
                    } elseif ( (int) $booking_form !== (int) $row['form_id'] ) {
                        $error = 'booking_form_mismatch';
                    }
                } catch ( Throwable $exception ) {
                    $error = 'booking_lookup_unavailable';
                }
            }
            if ( '' === $error && (int) $row['attempts'] > self::MAX_ATTEMPTS ) { $error = 'attempt_limit'; }
            if ( '' === $error ) {
                $payload = json_decode( $row['payload'], true );
                if ( ! is_array( $payload ) ) { $error = 'invalid_payload'; }
                else {
                    try {
                        if ( 'cancelled' === $row['event_type'] ) {
                            // do_action() succeeds silently if no listener is registered.
                            // Treat the missing integration as retryable, not as delivery.
                            if ( false === has_action( 'tz_fd_update_status' ) ) {
                                $error = 'hook_unavailable';
                            } else {
                                do_action( 'tz_fd_update_status', (int) $row['booking_id'], 'Cancelled by customer' );
                            }
                        } elseif ( 'rescheduled' === $row['event_type'] ) {
                            if ( false === has_action( 'titan_zero_front_desk_booking_rescheduled' ) ) {
                                $error = 'hook_unavailable';
                            } else {
                                do_action( 'titan_zero_front_desk_booking_rescheduled', (int) $row['booking_id'], (int) $row['form_id'], $payload );
                            }
                        } elseif ( 'reschedule_email' === $row['event_type'] ) {
                            if ( ! self::send_reschedule_email( $payload ) ) { $error = 'mail_not_accepted'; }
                        } else { $error = 'invalid_event'; }
                    } catch ( Throwable $exception ) { $error = 'hook_exception'; }
                }
            }
            if ( '' === $error ) {
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE %i SET state='dispatched',lease_token='',locked_until=NULL,last_error='' WHERE id=%d AND company_id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()",
                    $table, (int) $id, $company, $token
                ) );
                if ( 1 === (int) $updated ) { ++$counts['dispatched']; }
                else { self::schedule_lease_recovery(); }
            } else {
                $terminal = (int) $row['attempts'] >= self::MAX_ATTEMPTS || $error === 'invalid_event' || $error === 'invalid_payload' || $error === 'form_company_mismatch' || $error === 'booking_form_mismatch';
                $delay = min( 3600, 30 * ( 2 ** min( (int) $row['attempts'], 7 ) ) );
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE %i SET state=%s,lease_token='',locked_until=NULL,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),last_error=%s WHERE id=%d AND company_id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()",
                    $table, $terminal ? 'failed' : 'pending', $delay, $error, (int) $id, $company, $token
                ) );
                if ( 1 === (int) $updated ) {
                    ++$counts[ $terminal ? 'failed' : 'retried' ];
                    if ( ! $terminal ) { self::schedule_after( $delay + 1 ); }
                } else {
                    // A failed retry/quarantine write must not silently strand a lease.
                    self::schedule_lease_recovery();
                }
            }
            } catch ( Throwable $error ) {
                self::schedule_lease_recovery();
                continue;
            }
        }
        // A capped batch may leave ready work in this tenant. Promptly wake
        // the worker rather than waiting for the hourly recovery schedule.
        if ( count( (array) $ids ) >= max( 1, min( 50, (int) $limit ) ) ) {
            self::schedule();
        }
        return $counts;
    }
}

