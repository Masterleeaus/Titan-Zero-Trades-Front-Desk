<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Idempotency and turn counts must be read live; values are bound with prepare() or %i placeholders.
/**
 * Persistence for missed-call callback tasks and SMS reply actions (issues #30, #31).
 *
 * Two plugin-owned tables, both scoped by company_id:
 * - tz_contact_actions: one row per callback or staff task, and one row per processed reply event.
 *   UNIQUE (company_id, dedupe_key) makes a repeated provider event create nothing new.
 * - tz_sms_conversations: one row per company and caller conversation. It holds the automated turn count,
 *   the opt-out flag, and a hashed key only. The raw number is never a key.
 *
 * The schema is an explicit, administrator-run install (maybe_install on admin_init), like the call lifecycle.
 * Webhooks never install schema. A missing schema makes the webhook fail with 503, so the provider retries.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Contact_Actions {
    const SCHEMA_VERSION = '1';
    const SCHEMA_OPTION  = 'tz_fd_contact_actions_schema';

    public static function actions_table() { return TZFD_Tables::name( TZFD_Tables::CONTACT_ACTIONS ); }
    public static function conversations_table() { return TZFD_Tables::name( TZFD_Tables::SMS_CONVERSATIONS ); }

    /** Both tables exist and the installed schema version is current. */
    public static function installed() {
        global $wpdb;
        if ( self::SCHEMA_VERSION !== get_option( self::SCHEMA_OPTION, '' ) ) { return false; }
        foreach ( array( self::actions_table(), self::conversations_table() ) as $table ) {
            if ( is_wp_error( $table ) || $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { return false; }
        }
        return true;
    }

    /** Administrator-only install. Returns true only when both tables are visible. */
    public static function install() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return false; }
        if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $collate = $wpdb->get_charset_collate();
        $actions = self::actions_table();
        $conversations = self::conversations_table();
        if ( is_wp_error( $actions ) || is_wp_error( $conversations ) ) { return false; }
        dbDelta( "CREATE TABLE $actions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            dedupe_key char(64) NOT NULL,
            kind varchar(32) NOT NULL,
            source varchar(32) NOT NULL,
            phone varchar(16) NOT NULL DEFAULT '',
            match_status varchar(16) NOT NULL DEFAULT 'none',
            customer_id bigint(20) unsigned DEFAULT NULL,
            reason varchar(32) NOT NULL DEFAULT '',
            state varchar(20) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY company_dedupe (company_id,dedupe_key),
            KEY company_state (company_id,state,created_at)
        ) $collate;" );
        dbDelta( "CREATE TABLE $conversations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            conversation_key char(64) NOT NULL,
            turns tinyint(3) unsigned NOT NULL DEFAULT 0,
            window_started datetime NOT NULL,
            opted_out tinyint(1) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY company_conversation (company_id,conversation_key)
        ) $collate;" );
        if ( ! self::installed_tables_visible() ) { return false; }
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
        return true;
    }

    private static function installed_tables_visible() {
        global $wpdb;
        foreach ( array( self::actions_table(), self::conversations_table() ) as $table ) {
            if ( is_wp_error( $table ) || $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { return false; }
        }
        return true;
    }

    /** Admin page loads install a missing schema. Webhooks never do. */
    public static function maybe_install() {
        if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_OPTION, '' ) ) { return; }
        if ( current_user_can( 'manage_options' ) ) { self::install(); }
    }

    /**
     * Claims one action. Returns 'created' when the row is new, 'duplicate' when the key already exists,
     * and 'error' when the write failed or the schema is missing. Never returns success for a failed write.
     */
    public static function claim_action( $company_id, $dedupe_key, $kind, $source, $phone, $match_status, $customer_id, $reason, $state ) {
        global $wpdb;
        if ( ! self::installed() ) { return 'error'; }
        $now = gmdate( 'Y-m-d H:i:s' );
        $result = $wpdb->query( $wpdb->prepare(
            'INSERT INTO %i (company_id, dedupe_key, kind, source, phone, match_status, customer_id, reason, state, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id',
            self::actions_table(), $company_id, $dedupe_key, $kind, $source, $phone, $match_status,
            null === $customer_id ? 0 : (int) $customer_id, $reason, $state, $now, $now
        ) );
        if ( false === $result ) { return 'error'; }
        return 1 === (int) $result ? 'created' : 'duplicate';
    }

    /** Moves an action to a new state, scoped to its company. */
    public static function set_action_state( $company_id, $dedupe_key, $state ) {
        global $wpdb;
        $result = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET state = %s, updated_at = %s WHERE company_id = %d AND dedupe_key = %s',
            self::actions_table(), $state, gmdate( 'Y-m-d H:i:s' ), $company_id, $dedupe_key
        ) );
        return false !== $result;
    }

    /** Removes a claim that did not finish, so a provider retry can process the event again. */
    public static function release_action( $company_id, $dedupe_key ) {
        global $wpdb;
        $result = $wpdb->query( $wpdb->prepare(
            'DELETE FROM %i WHERE company_id = %d AND dedupe_key = %s AND state = %s',
            self::actions_table(), $company_id, $dedupe_key, 'processing'
        ) );
        return false !== $result;
    }

    /** Creates the conversation row when missing, and restarts the window when it has expired. */
    private static function ensure_conversation( $company_id, $conversation_key ) {
        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - TZFD_Contact_Rules::CONVERSATION_WINDOW );
        $table = self::conversations_table();
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO %i (company_id, conversation_key, turns, window_started, opted_out, updated_at)
             VALUES (%d, %s, 0, %s, 0, %s) ON DUPLICATE KEY UPDATE id = id',
            $table, $company_id, $conversation_key, $now, $now
        ) );
        $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET turns = 0, window_started = %s, updated_at = %s
             WHERE company_id = %d AND conversation_key = %s AND window_started < %s',
            $table, $now, $now, $company_id, $conversation_key, $cutoff
        ) );
    }

    /**
     * Takes one automated turn if the conversation still has one. The increment is a single guarded UPDATE,
     * so two concurrent replies cannot both take the last turn. Returns false when the cap is reached or on error.
     */
    public static function claim_turn( $company_id, $conversation_key ) {
        global $wpdb;
        if ( ! self::installed() ) { return false; }
        self::ensure_conversation( $company_id, $conversation_key );
        $result = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET turns = turns + 1, updated_at = %s
             WHERE company_id = %d AND conversation_key = %s AND turns < %d',
            self::conversations_table(), gmdate( 'Y-m-d H:i:s' ), $company_id, $conversation_key, TZFD_Contact_Rules::MAX_REPLY_TURNS
        ) );
        return 1 === (int) $result;
    }

    /** Gives back a turn whose message could not be queued. */
    public static function release_turn( $company_id, $conversation_key ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET turns = turns - 1, updated_at = %s WHERE company_id = %d AND conversation_key = %s AND turns > 0',
            self::conversations_table(), gmdate( 'Y-m-d H:i:s' ), $company_id, $conversation_key
        ) );
    }

    /** Turns used in the current window. Returns the cap on any read error, so the caller fails closed. */
    public static function turns_used( $company_id, $conversation_key ) {
        global $wpdb;
        if ( ! self::installed() ) { return TZFD_Contact_Rules::MAX_REPLY_TURNS; }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - TZFD_Contact_Rules::CONVERSATION_WINDOW );
        $turns = $wpdb->get_var( $wpdb->prepare(
            'SELECT turns FROM %i WHERE company_id = %d AND conversation_key = %s AND window_started >= %s',
            self::conversations_table(), $company_id, $conversation_key, $cutoff
        ) );
        return null === $turns ? 0 : (int) $turns;
    }

    public static function is_opted_out( $company_id, $conversation_key ) {
        global $wpdb;
        if ( ! self::installed() ) { return true; } // Fail closed: unknown opt-out state means no automated reply.
        $flag = $wpdb->get_var( $wpdb->prepare(
            'SELECT opted_out FROM %i WHERE company_id = %d AND conversation_key = %s',
            self::conversations_table(), $company_id, $conversation_key
        ) );
        return null !== $flag && 1 === (int) $flag;
    }

    /** Sets or clears the opt-out flag. Returns false when the write fails, so a STOP is never acknowledged unsaved. */
    public static function set_opt_out( $company_id, $conversation_key, $on ) {
        global $wpdb;
        if ( ! self::installed() ) { return false; }
        self::ensure_conversation( $company_id, $conversation_key );
        $result = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET opted_out = %d, updated_at = %s WHERE company_id = %d AND conversation_key = %s',
            self::conversations_table(), $on ? 1 : 0, gmdate( 'Y-m-d H:i:s' ), $company_id, $conversation_key
        ) );
        return false !== $result;
    }

    /** Recent actions for one company. The number is masked to its last four digits. No customer names. */
    public static function recent( $company_id, $limit = 50 ) {
        global $wpdb;
        if ( ! self::installed() || ! is_int( $company_id ) || $company_id < 1 ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT kind, source, match_status, customer_id, reason, state, phone, created_at FROM %i
             WHERE company_id = %d ORDER BY id DESC LIMIT %d',
            self::actions_table(), $company_id, max( 1, min( 200, (int) $limit ) )
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) { return array(); }
        foreach ( $rows as &$row ) {
            $phone = (string) $row['phone'];
            $row['phone'] = '' === $phone ? '' : '***' . substr( $phone, -4 );
        }
        unset( $row );
        return $rows;
    }
}
