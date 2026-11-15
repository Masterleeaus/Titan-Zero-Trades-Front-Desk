<?php
/**
 * Front Desk non-destructive, versioned migration coordinator.
 * No schema migration is implicitly registered in this release.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Migrations {
    const OPTION = 'tz_fd_migration_state_v1';
    const LOCK = 'tz_fd_migration_lock_v1';
    const LOCK_SECONDS = 300;

    /** Explicit ordered migration definitions, never inferred from files or hooks. */
    public static function definitions() {
        return array(); // Future migrations require reviewed callbacks and individual backups.
    }

    public static function plan() {
        $state = get_option( self::OPTION, array() );
        $completed = isset( $state['completed'] ) && is_array( $state['completed'] ) ? $state['completed'] : array();
        $steps = array();
        foreach ( self::definitions() as $version => $definition ) {
            $steps[] = array(
                'version' => (string) $version,
                'description' => isset( $definition['description'] ) ? (string) $definition['description'] : '',
                'status' => isset( $completed[ (string) $version ] ) ? 'completed' : 'pending',
            );
        }
        return array( 'mode' => 'dry_run', 'steps' => $steps, 'no_changes_made' => true );
    }

    /**
     * Run reviewed steps only when explicitly called by privileged maintenance code.
     * A durable lock prevents concurrent runs; completed versions are skipped.
     * Failures retain the lock until expiry to allow inspection and recovery.
     */
    public static function run( $authorized = false ) {
        if ( ! $authorized || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'tzfd_migration_forbidden', __( 'Migration requires explicit administrator authorization.', 'titan-zero-trades-front-desk' ) );
        }
        if ( ! add_option( self::LOCK, time(), '', false ) ) {
            $locked_at = (int) get_option( self::LOCK, 0 );
            if ( $locked_at < 1 || time() - $locked_at < self::LOCK_SECONDS ) {
                return new WP_Error( 'tzfd_migration_locked', __( 'Another migration is running or requires recovery.', 'titan-zero-trades-front-desk' ) );
            }
            // Expired locks are not silently taken over. An admin must inspect the prior run.
            return new WP_Error( 'tzfd_migration_stale_lock', __( 'Prior migration lock expired; inspect before manual recovery.', 'titan-zero-trades-front-desk' ) );
        }
        $state = get_option( self::OPTION, array() );
        if ( ! is_array( $state ) ) { $state = array(); }
        if ( ! isset( $state['completed'] ) || ! is_array( $state['completed'] ) ) { $state['completed'] = array(); }
        foreach ( self::definitions() as $version => $definition ) {
            $version = (string) $version;
            if ( isset( $state['completed'][ $version ] ) ) { continue; }
            if ( empty( $definition['callback'] ) || ! is_callable( $definition['callback'] ) ) {
                update_option( self::OPTION, array_merge( $state, array( 'failed_at' => $version ) ), false );
                return new WP_Error( 'tzfd_migration_invalid_step', __( 'Migration callback unavailable; lock retained for inspection.', 'titan-zero-trades-front-desk' ) );
            }
            $result = call_user_func( $definition['callback'] );
            if ( is_wp_error( $result ) || true !== $result ) {
                $state['failed_at'] = $version;
                update_option( self::OPTION, $state, false );
                return is_wp_error( $result ) ? $result : new WP_Error( 'tzfd_migration_failed', __( 'Migration failed; lock retained for inspection.', 'titan-zero-trades-front-desk' ) );
            }
            $state['completed'][ $version ] = gmdate( 'c' );
            unset( $state['failed_at'] );
            update_option( self::OPTION, $state, false );
        }
        delete_option( self::LOCK );
        return array( 'status' => 'complete', 'completed' => array_keys( $state['completed'] ) );
    }

    /** Lock release only after manual audit; never reset completed step markers. */
    public static function clear_lock_after_review( $authorized = false ) {
        if ( ! $authorized || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'tzfd_migration_forbidden', __( 'Administrator approval required.', 'titan-zero-trades-front-desk' ) );
        }
        return delete_option( self::LOCK );
    }
}
