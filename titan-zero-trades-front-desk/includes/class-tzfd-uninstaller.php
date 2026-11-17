<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall touches plugin-owned tables and options directly. Identifiers are bound with %i or come from fixed lists.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Data lifecycle for uninstall.php. Loaded only when the plugin is deleted.
 *
 * Every uninstall, on every site of a network:
 *   - removes the plugin's scheduled events,
 *   - removes its transients (cache, captcha, rate limits, short-lived sign-in tokens),
 *   - removes the calendar invitation files it wrote to its uploads folder.
 *
 * Only when wp-config.php defines TZ_DELETE_DATA_ON_UNINSTALL as true, on every site:
 *   - drops the plugin's own tables (TZFD_Tables::owned() plus the standalone and legacy tables below),
 *   - deletes the plugin's options: settings, business profile, add-on state, and credentials.
 *
 * Never, under any setting: Workspace-owned tables (tz_customers, tz_sites, tz_services, tz_staff, tz_audit),
 * see TZFD_Tables::is_external_owner(). The legacy name tzfd_customers maps to tz_customers and is excluded.
 */
final class TZFD_Uninstaller {

    /**
     * Option-name prefixes the plugin owns. tz_fd_ and titan_zero_front_desk_ are the settings families.
     * tzfd_ covers the AI connector and its secret, outbox state, pipeline labels, schema versions and
     * local business settings.
     */
    const OPTION_PREFIXES = array( 'tz_fd_', 'titan_zero_front_desk_', 'tzfd_' );

    /** Transient-name prefixes. tzfd- is the captcha form. */
    const TRANSIENT_PREFIXES = array( 'tz_fd_', 'titan_zero_front_desk_', 'tzfd_', 'tzfd-' );

    /** Plugin-owned tables outside TZFD_Tables::owned(). Bare names. */
    const EXTRA_TABLES = array(
        'tzfd_local_customers',
        'tzfd_local_services',
        'tzfd_customer_event_outbox',
    );

    /**
     * Earlier table names from before the tz_ rename. Read from TZFD_Tables::MIGRATION, but tzfd_customers
     * is the shell's customers table, so it is never listed here.
     */
    const LEGACY_TABLES = array(
        'tzfd_forms',
        'tzfd_messages',
        'tzfd_cache',
        'tzfd_icalexport',
        'tzfd_SingleDaysSelection',
        'tzfd_pipeline',
        'tzfd_pipeline_events',
        'tzfd_addon_records',
    );

    /** Option that stores the cursor of a background worker. Always removed. */
    const CURSOR_OPTION = 'tzfd_customer_event_company_cursor_v1';

    /** Subfolder of the uploads directory that holds the plugin's generated files. */
    const UPLOAD_SUBDIR = 'titan-zero-trades-front-desk';

    /** Pattern of the calendar invitation files the plugin writes. */
    const INVITATION_GLOB = 'tzfd-booking_*.ics';

    /**
     * Bare names of every table opt-in uninstall drops, after the Workspace guard.
     *
     * @return string[]
     */
    public static function tables_to_drop() {
        $names = array_merge( TZFD_Tables::owned(), self::EXTRA_TABLES, self::LEGACY_TABLES );
        $names = array_values( array_unique( $names ) );
        $out   = array();
        foreach ( $names as $bare ) {
            if ( TZFD_Tables::is_external_owner( $bare ) ) { continue; }
            if ( 1 !== preg_match( '/^(tz|tzfd)_[A-Za-z0-9_]+$/D', $bare ) ) { continue; }
            $out[] = $bare;
        }
        return $out;
    }

    /**
     * Run the plan for every site in the network, or for the single site.
     * WordPress runs uninstall.php once per plugin, so a network uninstall must visit each blog.
     *
     * @param bool $opt_in True only when TZ_DELETE_DATA_ON_UNINSTALL is defined as true.
     */
    public static function uninstall_network( $opt_in ) {
        if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
            self::uninstall_site( (bool) $opt_in );
            return;
        }
        $ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        if ( ! is_array( $ids ) || empty( $ids ) ) {
            $ids = array( get_current_blog_id() );
        }
        foreach ( $ids as $blog_id ) {
            switch_to_blog( (int) $blog_id );
            try {
                self::uninstall_site( (bool) $opt_in );
            } finally {
                restore_current_blog();
            }
        }
    }

    /**
     * Run the plan for the current blog. Expects the blog context to be set by the caller.
     *
     * @param bool $opt_in True only when the owner opted in to data erasure.
     */
    public static function uninstall_site( $opt_in ) {
        global $wpdb;

        // Always: scheduled events, transients, generated files.
        self::clear_scheduled_events();
        self::delete_transients( $wpdb );
        delete_option( self::CURSOR_OPTION );
        self::delete_invitation_files();

        if ( true !== $opt_in ) { return; }

        // Opt-in: tables first, then options. Dropping tables does not depend on the options.
        foreach ( self::tables_to_drop() as $bare ) {
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $bare ) );
        }
        self::delete_options( $wpdb );
    }

    /** Remove every queued event for each hook the plugin schedules. */
    private static function clear_scheduled_events() {
        require_once __DIR__ . '/tzfd-cron-clear.php';
        $hooks = include __DIR__ . '/tzfd-cron-hooks.php';
        foreach ( (array) $hooks as $hook ) {
            tz_fd_unschedule_all_events( $hook );
        }
    }

    /** Delete the plugin's transients and their timeout rows. */
    private static function delete_transients( $wpdb ) {
        foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $prefix ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
            ) );
        }
    }

    /** Delete every option whose name starts with one of the plugin's prefixes. */
    private static function delete_options( $wpdb ) {
        foreach ( self::OPTION_PREFIXES as $prefix ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            ) );
        }
    }

    /** Remove the calendar invitation files from the plugin's uploads folder only. */
    private static function delete_invitation_files() {
        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
        $base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
        if ( '' === $base ) { return; }
        $files = glob( rtrim( $base, '/\\' ) . '/' . self::UPLOAD_SUBDIR . '/' . self::INVITATION_GLOB );
        foreach ( (array) $files as $file ) {
            if ( is_file( $file ) ) { wp_delete_file( $file ); }
        }
    }
}
