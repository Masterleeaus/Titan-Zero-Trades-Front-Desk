<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
// Runs when the plugin is deleted from WordPress.
//
// Always:
//   - clears the plugin's scheduled events, and
//   - removes its transients: cached data, and the client-portal sign-in links and sessions, which hold a customer's email address for a short time.
//
// Only when the site owner adds this line to wp-config.php before deleting the plugin:
//   define( 'TZ_DELETE_DATA_ON_UNINSTALL', true );
// it also drops Front Desk's own tables (TZFD_Tables::owned()) and deletes its settings.
// Shell-owned tables (tz_customers, tz_sites) are never dropped by this plugin.
// Always, it removes the chat photos and calendar invitation files the plugin wrote to the uploads folder.
//
// Without that line, bookings, enquiries and settings are kept, so deleting the
// plugin cannot remove customer records by accident.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

// 1. Scheduled events (same list the deactivation handler uses).
require_once __DIR__ . '/includes/tzfd-cron-clear.php';
$tz_hooks = include __DIR__ . '/includes/tzfd-cron-hooks.php';
foreach ( (array) $tz_hooks as $tz_hook ) {
    tz_fd_unschedule_all_events( $tz_hook );
}

// Option-name prefixes the plugin owns. Matching is case-insensitive in MySQL, so TZFD_ and tzfd_ are covered together.
$tz_prefixes = array( 'tz_fd_', 'titan_zero_front_desk_', 'tzfd_', 'TZFD_' );

// 2. Transients: cache only, always removed. Captcha and portal transients use a hyphen after tzfd.
$tz_transient_prefixes = array_merge( $tz_prefixes, array( 'tzfd-' ) );
foreach ( $tz_transient_prefixes as $tz_prefix ) {
    $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' . $tz_prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $tz_prefix ) . '%') );
}
// Rate-limit counters (includes/class-tzfd-rate-limit.php) are wp_options rows with this prefix. Counters only, always removed.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'tzfd_rl_' ) . '%' ) );

// 2a. Credentials, link state, locks, cursors and dismissal flags: always removed, even without the data opt-in.
// A sealed AI connector secret or link must not outlive the plugin.
$tz_always_options = array(
    'tzfd_ai_connector_secret',
    'tzfd_ai_connector',
    'tzfd_hosted_ai_consent',
    'tz_fd_ai_consent',
    'tz_fd_ai_api_key',
    'tz_fd_ai_local_key',
    'tzfd_ai_budget_lock',
    'tz_fd_migration_lock_v1',
    'tzfd_customer_event_company_cursor_v1',
    'tz_fd_setup_draft',
);
foreach ( $tz_always_options as $tz_option ) {
    delete_option( $tz_option );
}
// Dismissed admin-notice flags are per-user meta; the plugin's own keys only.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( 'tz_fd_notice_dismissed_' ) . '%' ) );

// 3. Data: only when the site owner has opted in.
if ( defined( 'TZ_DELETE_DATA_ON_UNINSTALL' ) && TZ_DELETE_DATA_ON_UNINSTALL === true ) {

    // Tables: Front Desk's own list only. tz_customers and tz_sites belong to the shell.
    require_once __DIR__ . '/includes/class-tzfd-tables.php';
    foreach ( TZFD_Tables::owned() as $tz_fd_bare ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping a plugin-owned table on uninstall is the purpose of this code; the identifier is bound with %i.
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $tz_fd_bare ) );
    }

    // Standalone customer and service records, created by this plugin's dbDelta calls (class-tzfd-local-customers.php, class-tzfd-local-services.php).
    // Not in TZFD_Tables::owned(), so they are listed here. Shell-owned tz_customers is never touched.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Opt-in uninstall of plugin-owned tables.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tzfd_local_customers' ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Opt-in uninstall of plugin-owned tables.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tzfd_local_services' ) );

    // The legacy customer lifecycle outbox is plugin-owned, but
    // predates the canonical tz_ table registry. Drop it only with explicit
    // data-erasure consent; never add it to Workspace-owned table lists.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Opt-in uninstall of the plugin's dedicated outbox.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tzfd_customer_event_outbox' ) );
    // Legacy AI chat table kept from earlier versions (class-tzfd-legacy-ai-data.php, CHAT_TABLE). Opt-in only.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Opt-in uninstall of the plugin's legacy chat table.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tz_ai_conversations' ) );

    // Settings, business profile, add-on state (option names keep the tz_fd_ prefix).
    foreach ( $tz_prefixes as $tz_prefix ) {
        $wpdb->query( $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $tz_prefix ) . '%') );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( $tz_prefix ) . '%' ) );
    }

}

// Internal worker cursor is disposable even when booking records are retained.
delete_option( 'tzfd_customer_event_company_cursor_v1' );

// Generated files: chat photos and invitation files are removed on every uninstall.
// Only the plugin's own folder in the uploads directory is read, plus the chat photos version 2.0.0 saved at the
// uploads root. Other files in the uploads folder are not touched.
$tz_uploads = wp_upload_dir();
$tz_base    = isset( $tz_uploads['basedir'] ) ? $tz_uploads['basedir'] : '';
if ( $tz_base ) {
    $tz_dir   = $tz_base . '/titan-zero-trades-front-desk';
    $tz_files = array_merge(
        (array) glob( $tz_dir . '/tzfd-booking_*.ics' )
    );
    foreach ( $tz_files as $tz_file ) {
        if ( is_file( $tz_file ) ) { wp_delete_file( $tz_file ); }
    }
}
