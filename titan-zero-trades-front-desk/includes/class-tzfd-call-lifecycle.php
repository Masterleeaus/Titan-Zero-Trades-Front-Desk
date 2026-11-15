<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Titan Zero Front Desk: provider-neutral call lifecycle, v1.
 * Explicit installer; no schema changes on ordinary plugin load.
 * Provider adapters MUST authenticate webhooks before calling record_event().
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Call_Lifecycle {
    const SCHEMA_VERSION = '1';
    const VERSION_OPTION = 'tz_fd_call_lifecycle_schema';
    const FAILURE_OPTION = 'tz_fd_call_lifecycle_install_error';
    const RETRY_ACTION = 'tzfd_call_lifecycle_retry';
    const STATES = array( 'ringing', 'answered', 'missed', 'abandoned', 'completed', 'failed' );
    const EVENTS = array( 'received', 'answered', 'missed', 'abandoned', 'completed', 'failed' );

    public static function tables() {
        return array( 'calls' => TZFD_Tables::name( TZFD_Tables::CALLS ), 'events' => TZFD_Tables::name( TZFD_Tables::CALL_EVENTS ) );
    }

    /** This is a reviewed opt-in schema step, not an automatic migration. */
    public static function install( $authorized = false ) {
        if ( ! $authorized || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'tzfd_call_install_forbidden', __( 'Administrator authorisation required.', 'titan-zero-trades-front-desk' ) );
        }
        global $wpdb;
        if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $tables = self::tables();
        $collate = $wpdb->get_charset_collate();
        $calls = "CREATE TABLE {$tables['calls']} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 company_id bigint(20) unsigned NOT NULL,
 provider varchar(64) NOT NULL,
 external_call_hash char(64) NOT NULL,
 state varchar(20) NOT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY call_identity (company_id,provider,external_call_hash),
 KEY company_state (company_id,state)
) ENGINE=InnoDB $collate;";
        $events = "CREATE TABLE {$tables['events']} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 company_id bigint(20) unsigned NOT NULL,
 call_id bigint(20) unsigned NOT NULL,
 provider varchar(64) NOT NULL,
 external_event_hash char(64) NOT NULL,
 event_type varchar(20) NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY provider_event (company_id,provider,external_event_hash),
 KEY call_history (company_id,call_id)
) ENGINE=InnoDB $collate;";
        dbDelta( $calls );
        dbDelta( $events );
        // Only assert success when both tables are actually visible.
        foreach ( $tables as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $exists !== $table ) { return new WP_Error( 'tzfd_call_schema_missing', __( 'Call lifecycle table was not created.', 'titan-zero-trades-front-desk' ) ); }
        }
        if ( ! self::transactional_storage_ready() ) { return new WP_Error( 'tzfd_call_transaction_storage_required', __( 'Call lifecycle requires InnoDB tables.', 'titan-zero-trades-front-desk' ) ); }
        update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
        return true;
    }

    /**
     * Runs the installer for an administrator request when the schema is missing. A failure is stored and shown
     * as an admin notice; it is not retried on every page load. Activation retries it.
     */
    public static function maybe_install() {
        if ( self::SCHEMA_VERSION === get_option( self::VERSION_OPTION, '' ) ) { return; }
        if ( '' !== get_option( self::FAILURE_OPTION, '' ) || ! current_user_can( 'manage_options' ) ) { return; }
        self::run_install();
    }

    /** Explicit, authorised install. Records the failure code for the admin notice, or clears it on success. */
    public static function run_install() {
        $result = self::install( true );
        if ( is_wp_error( $result ) ) {
            update_option( self::FAILURE_OPTION, $result->get_error_code(), false );
            return false;
        }
        delete_option( self::FAILURE_OPTION );
        return true;
    }

    public static function render_failure() {
        $code = get_option( self::FAILURE_OPTION, '' );
        if ( ! is_string( $code ) || '' === $code || ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Titan Zero Front Desk could not set up call event storage, so provider call events are not recorded. Check that the database supports InnoDB tables, then use the retry button to try again.', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( $code ) . '</code></p><p><a class="button" href="' . esc_url( self::retry_url() ) . '">' . esc_html__( 'Retry call event storage setup', 'titan-zero-trades-front-desk' ) . '</a></p></div>';
    }

    /** Nonce-protected admin-post link. Only administrators can use it. */
    public static function retry_url() {
        return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RETRY_ACTION ), self::RETRY_ACTION );
    }

    /** Admin retry: clears the stored failure, runs the authorised install again, then returns to the referring screen. */
    public static function handle_retry() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Administrator authorisation required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::RETRY_ACTION );
        delete_option( self::FAILURE_OPTION );
        self::run_install();
        $target = wp_get_referer();
        wp_safe_redirect( $target ? $target : admin_url() );
        exit;
    }

    /** A successful START TRANSACTION does not make MyISAM writes transactional. */
    private static function transactional_storage_ready() {
        global $wpdb;
        $tables = array_values( self::tables() );
        if ( class_exists( 'TZFD_Smart_Task_Outbox' ) && TZFD_Smart_Task_Outbox::installed() ) {
            $tables[] = TZFD_Smart_Task_Outbox::table();
        }
        foreach ( $tables as $table ) {
            $engine = $wpdb->get_var( $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ) );
            if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) { return false; }
        }
        return true;
    }

    /** Pure transition logic. Out-of-order provider callbacks never revert terminal calls. */
    public static function next_state( $current, $event ) {
        $edges = array(
            ''          => array( 'received' => 'ringing', 'answered' => 'answered', 'missed' => 'missed', 'abandoned' => 'abandoned', 'failed' => 'failed', 'completed' => 'completed' ),
            'ringing'   => array( 'answered' => 'answered', 'missed' => 'missed', 'abandoned' => 'abandoned', 'failed' => 'failed', 'completed' => 'completed' ),
            'answered'  => array( 'completed' => 'completed', 'failed' => 'failed' ),
            'missed'    => array(), 'abandoned' => array(), 'completed' => array(), 'failed' => array(),
        );
        return isset( $edges[$current][$event] ) ? $edges[$current][$event] : null;
    }

    /**
     * Called only by a verified provider adapter or authorised internal process.
     * Uses DB uniqueness and a transaction to deduplicate atomically.
     * The caller must provide company scope verified by the adapter/owner.
     */
    public static function record_event( $company_id, $provider, $external_call_id, $external_event_id, $event, $authenticated = false ) {
        if ( true !== $authenticated ) { return new WP_Error( 'tzfd_call_unauthorised', __( 'Verified provider authentication is required.', 'titan-zero-trades-front-desk' ) ); }
        if ( ! is_int( $company_id ) || $company_id < 1 || ! is_string( $provider ) ||
             ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $provider ) ||
             ! is_string( $external_call_id ) || strlen( $external_call_id ) < 1 || strlen( $external_call_id ) > 255 ||
             ! is_string( $external_event_id ) || strlen( $external_event_id ) < 1 || strlen( $external_event_id ) > 255 ||
             ! in_array( $event, self::EVENTS, true ) ) {
            return new WP_Error( 'tzfd_call_invalid_event', __( 'Invalid company, provider or call event.', 'titan-zero-trades-front-desk' ) );
        }
        if ( self::SCHEMA_VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
            return new WP_Error( 'tzfd_call_schema_unavailable', __( 'Call lifecycle schema has not been installed.', 'titan-zero-trades-front-desk' ) );
        }
        global $wpdb;
        $tables = self::tables();
        $call_hash = hash( 'sha256', $external_call_id );
        $event_hash = hash( 'sha256', $external_event_id );
        $now = gmdate( 'Y-m-d H:i:s' );
        if ( ! self::transactional_storage_ready() ) {
            return new WP_Error( 'tzfd_call_transaction_storage_required', __( 'Call lifecycle requires transactional tables; no event was written.', 'titan-zero-trades-front-desk' ) );
        }
        // Never perform multi-table lifecycle writes outside an active transaction.
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_Error( 'tzfd_call_transaction_unavailable', __( 'Call lifecycle transaction could not start.', 'titan-zero-trades-front-desk' ) );
        }
        try {
            // Call identity is unique per company and provider.
            $insert = $wpdb->query( $wpdb->prepare("INSERT INTO %i (company_id,provider,external_call_hash,state,created_at,updated_at)
                 VALUES (%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", $tables['calls'], $company_id, $provider, $call_hash, 'ringing', $now, $now) );
            if ( false === $insert ) { throw new RuntimeException( 'call_insert_failed' ); }
            $call_id = (int) $wpdb->insert_id;
            if ( $call_id < 1 ) { throw new RuntimeException( 'call_id_unavailable' ); }
            // Strict inserts: INSERT IGNORE can suppress database failures as warnings.
            $seen = $wpdb->query( $wpdb->prepare("INSERT INTO %i (company_id,call_id,provider,external_event_hash,event_type,created_at)
                 VALUES (%d,%d,%s,%s,%s,%s)", $tables['events'], $company_id, $call_id, $provider, $event_hash, $event, $now) );
            if ( false === $seen ) {
                if ( ! isset( $wpdb->dbh ) || ! ( $wpdb->dbh instanceof mysqli ) || 1062 !== (int) $wpdb->dbh->errno ) { throw new RuntimeException( 'event_insert_failed' ); }
                // A reused provider-event identity must describe exactly the same call/event.
                $existing = $wpdb->get_row( $wpdb->prepare("SELECT call_id,event_type FROM %i WHERE company_id=%d AND provider=%s AND external_event_hash=%s", $tables['events'], $company_id, $provider, $event_hash), ARRAY_A );
                if ( ! is_array( $existing ) || ! isset( $existing['call_id'], $existing['event_type'] ) ||
                     (int) $existing['call_id'] !== $call_id || $existing['event_type'] !== $event ) {
                    throw new RuntimeException( 'conflicting_provider_event' );
                }
                if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'duplicate_commit_failed' ); }
                return array( 'ok' => true, 'duplicate' => true, 'transitioned' => false, 'call_id' => $call_id );
            }
            if ( 1 !== (int) $seen ) { throw new RuntimeException( 'unexpected_event_insert_result' ); }
            $current = $wpdb->get_var( $wpdb->prepare("SELECT state FROM %i WHERE id=%d AND company_id=%d FOR UPDATE", $tables['calls'], $call_id, $company_id) );
            if ( ! is_string( $current ) ) { throw new RuntimeException( 'call_not_found' ); }
            $next = self::next_state( $current, $event );
            $changed = null !== $next && $next !== $current;
            if ( $changed ) {
                $updated = $wpdb->query( $wpdb->prepare("UPDATE %i SET state=%s, updated_at=%s WHERE id=%d AND company_id=%d", $tables['calls'], $next, $now, $call_id, $company_id) );
                if ( false === $updated ) { throw new RuntimeException( 'call_update_failed' ); }
            }
            // When the outbox is installed, record the completed outcome in this same
            // InnoDB transaction. A failed outbox write rolls back the call transition.
            // Legacy sites without the optional outbox retain the old behaviour and
            // must use the reconciliation scanner after installing its schema.
            $task_staged = false;
            if ( $changed && $current === 'answered' && $next === 'completed' &&
                 class_exists( 'TZFD_Smart_Task_Outbox' ) && TZFD_Smart_Task_Outbox::installed() ) {
                $task_staged = TZFD_Smart_Task_Outbox::enqueue( $company_id, 'call.routing_completed',
                    'call:completed:' . $call_id, 0, 0, '', false );
                if ( ! $task_staged ) { throw new RuntimeException( 'completion_outbox_write_failed' ); }
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'call_commit_failed' ); }
            if ( $task_staged ) { TZFD_Smart_Task_Outbox::schedule_dispatch(); }
            // Provider events are recorded even if an out-of-order transition is rejected.
            if ( $changed && function_exists( 'do_action' ) ) {
                do_action( 'tzfd_call_lifecycle_transition_v1', array(
                    'company_id' => $company_id, 'call_id' => $call_id,
                    'previous' => $current, 'state' => $next, 'event' => $event, 'provider' => $provider,
                ) );
            }
            return array( 'ok' => true, 'duplicate' => false, 'transitioned' => $changed,
                'call_id' => $call_id, 'state' => $changed ? $next : $current );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_call_write_failed', __( 'Call lifecycle event could not be persisted.', 'titan-zero-trades-front-desk' ) );
        }
    }
}

add_action( 'admin_post_' . TZFD_Call_Lifecycle::RETRY_ACTION, array( 'TZFD_Call_Lifecycle', 'handle_retry' ) );
