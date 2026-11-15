<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compatibility helpers for the WordPress AI Client migration.
 * Provider credentials and model routing are managed by WordPress Connectors.
 */
final class TZFD_AI_Options {
    public static function available() {
        return function_exists( 'wp_ai_client_prompt' ) && ( ! function_exists( 'wp_supports_ai' ) || wp_supports_ai() );
    }

    public static function connectors_url() {
        return admin_url( 'options-connectors.php' );
    }

    /**
     * Remove credentials saved by pre-Connectors releases once. They cannot be
     * migrated automatically because WordPress Connectors owns its own secrets.
     */
    public static function cleanup_legacy_settings() {
        // admin_init also runs for anonymous admin-ajax and admin-post requests, so only an administrator may trigger the one-time deletion.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( 'tz_fd_ai_connector_migration_20261015', false ) ) {
            return;
        }
        foreach ( array(
            'tz_fd_ai_api_key', 'tz_fd_ai_api_base', 'tz_fd_ai_model', 'tz_fd_ai_provider',
            'tz_fd_ai_local_key', 'tz_fd_ai_local_base', 'tz_fd_ai_local_model',
            'tz_fd_ai_local_models_cache', 'tz_fd_ai_local_kind', 'tz_fd_ai_mode',
        ) as $option_name ) {
            delete_option( $option_name );
        }
        update_option( 'tz_fd_ai_connector_migration_20261015', 1, false );
    }

    /** Legacy upgrade-card helpers remain empty for older integrations. */
    public static function upgrade_url() { return ''; }
    public static function price() { return ''; }
}

add_action( 'admin_init', array( 'TZFD_AI_Options', 'cleanup_legacy_settings' ) );
