<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
// Runs when the plugin is deleted from WordPress.
//
// Always:
//   - clears the plugin's scheduled events, and
//   - removes its transients: cached data, and the client-portal sign-in links and sessions, which hold a customer's email address for a short time.
//
// Only when the site owner adds this line to wp-config.php before deleting the plugin:
//   define( 'TZFD_DELETE_DATA_ON_UNINSTALL', true );
// it also drops the plugin's tables and deletes its settings.
// Always, it removes the chat photos and calendar invitation files the plugin wrote to the uploads folder.
//
// Without that line, bookings, invoices, payments, disputes and settings are kept, so deleting the
// plugin cannot remove customer records by accident.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

// 1. Scheduled events (same list the deactivation handler uses).
require_once __DIR__ . '/includes/tzfd-cron-clear.php';
$tzfd_hooks = include __DIR__ . '/includes/tzfd-cron-hooks.php';
foreach ( (array) $tzfd_hooks as $tzfd_hook ) {
    tzfd_unschedule_all_events( $tzfd_hook );
}

// Option-name prefixes the plugin owns.
$tzfd_prefixes = array( 'tzfd_', 'titan_zero_front_desk_' );

// 2. Transients: cache only, always removed.
foreach ( $tzfd_prefixes as $tzfd_prefix ) {
    $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' . $tzfd_prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $tzfd_prefix ) . '%') );
}

// 3. Data: only when the site owner has opted in.
if ( defined( 'TZFD_DELETE_DATA_ON_UNINSTALL' ) && TZFD_DELETE_DATA_ON_UNINSTALL === true ) {

    // Tables: every table with the plugin's prefix.
    $tzfd_tables = $wpdb->get_col( $wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like( $wpdb->prefix . 'tzfd_' ) . '%'
    ) );
    foreach ( (array) $tzfd_tables as $tzfd_table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping a plugin-owned table on uninstall is the purpose of this code; the identifier is bound with %i.
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tzfd_table ) );
    }

    // Settings, business profile, AI keys and add-on state.
    foreach ( $tzfd_prefixes as $tzfd_prefix ) {
        $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $tzfd_prefix ) . '%') );
    }

}

// Generated files: chat photos and invitation files are removed on every uninstall.
// Only the plugin's own folder in the uploads directory is read, plus the chat photos version 2.0.0 saved at the
// uploads root. Other files in the uploads folder are not touched.
$tzfd_uploads = wp_upload_dir();
$tzfd_base    = isset( $tzfd_uploads['basedir'] ) ? $tzfd_uploads['basedir'] : '';
if ( $tzfd_base ) {
    $tzfd_dir   = $tzfd_base . '/titan-zero-trades-front-desk';
    $tzfd_files = array_merge(
        (array) glob( $tzfd_dir . '/tzfd-booking_*.ics' ),
        (array) glob( $tzfd_dir . '/tzfd-ai-*' ),
        (array) glob( $tzfd_base . '/tzfd-ai-*' )
    );
    foreach ( $tzfd_files as $tzfd_file ) {
        if ( is_file( $tzfd_file ) ) { wp_delete_file( $tzfd_file ); }
    }
}
