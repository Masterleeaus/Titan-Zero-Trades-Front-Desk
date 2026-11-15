<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Company-scoped store for Front Desk vertical settings and installed-pack state (19 Oct round 3).
 *
 * Before this, the pack and vertical settings were single site-wide options, so one business could see
 * or overwrite another business's pack state. Each value is now kept per company, in an option named
 * tzfd_c{company}_{legacy name}, with autoload off.
 *
 * Reads with no company context (company 0) return the caller's default. Writes with no company are refused.
 *
 * Migration: the site's existing global values belong to the default company (TZFD_Local_Business::default_id()),
 * or to the current company when that class is absent. The first time that company is seen, each legacy
 * value is copied to its scoped option if the scoped option is still empty. The legacy options are left in
 * place so a rollback still has the data. A marker per company makes the migration run once. It is safe
 * to run again: it copies nothing that is already scoped.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Scoped_Store {

    /** Legacy (pre-scope) option names this store takes over. */
    const KEYS = array(
        'tz_fd_installed_packs',
        'tz_fd_pack_vars',
        'tz_fd_pack_edits',
        'tz_fd_pack_kb',
        'tz_fd_user_packs',
        'tz_fd_business_profile',
        'tz_fd_ai_services',
        'tz_fd_ai_system_prompt',
        'tz_fd_ai_service_area',
        'tz_fd_ai_greeting',
        'tz_fd_ai_emergency_fee',
        'tz_fd_ai_price_ranges',
        'tz_fd_addons_active_list',
        'tz_fd_setup_draft',
        'tz_fd_setup_done',
        'titan_zero_front_desk_intake_default',
        'tz_fd_dashboard_maxitems',
        'tz_fd_dashboard_columns',
        'tz_fd_dashboard_columnlabels',
        'tz_fd_dashboard_capability',
        'tz_fd_dashboard_compactview',
        // Legacy site-wide service and resource settings, migrated only to the default company.
        'titan_zero_front_desk_resource_catalog_v1',
        'titan_zero_front_desk_service_details_v1',
    );

    /** Per-process cache of companies already checked for migration. */
    private static $checked = array();

    /** The company the current request works for, or 0 when there is none. */
    public static function company_id() {
        return class_exists( 'TZFD_Local_Business', false ) ? absint( TZFD_Local_Business::id() ) : 0;
    }

    /** The company that owns the site's existing global values. */
    public static function legacy_owner() {
        $owner = class_exists( 'TZFD_Local_Business', false ) ? absint( TZFD_Local_Business::default_id() ) : 0;
        return $owner > 0 ? $owner : self::company_id();
    }

    /** Option name for one company's value. */
    public static function option_name( $legacy, $company ) {
        return 'tzfd_c' . absint( $company ) . '_' . $legacy;
    }

    /**
     * Reads a company-scoped value. $company defaults to the request's company; pass one explicitly on public
     * paths that resolved it from a form. Returns $default when the company is 0 or has no value.
     */
    public static function get( $legacy, $default = array(), $company = null ) {
        $company = null === $company ? self::company_id() : absint( $company );
        if ( $company < 1 ) { return $default; }
        self::migrate_company( $company );
        $value = get_option( self::option_name( $legacy, $company ), null );
        return null === $value ? $default : $value;
    }

    /** Writes a company-scoped value. Returns false when the company is 0. */
    public static function update( $legacy, $value, $company = null ) {
        $company = null === $company ? self::company_id() : absint( $company );
        if ( $company < 1 ) { return false; }
        self::migrate_company( $company );
        return update_option( self::option_name( $legacy, $company ), $value, false );
    }

    /** Removes a company's own value so the default applies again. Returns false when the company is 0. */
    public static function delete( $legacy, $company = null ) {
        $company = null === $company ? self::company_id() : absint( $company );
        if ( $company < 1 ) { return false; }
        self::migrate_company( $company );
        return delete_option( self::option_name( $legacy, $company ) );
    }

    /**
     * Read-only value for code that runs before a company is known (plugin boot, add-on loading).
     * With a request company it returns that company's value. With no company (for example a public request
     * that Workspace has not resolved) it returns $default only. No company settings are read, so no other
     * company's add-on list can leak. It never writes.
     */
    public static function get_for_boot( $legacy, $default = array() ) {
        $company = self::company_id();
        if ( $company < 1 ) { return $default; }
        self::migrate_company( $company );
        $value = get_option( self::option_name( $legacy, $company ), null );
        return null === $value ? $default : $value;
    }

    /** The company that owns a calendar (tz_forms row). Returns 0 when the form is unknown. */
    public static function company_for_form( $formid ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ||
             ! method_exists( $wpdb, 'get_var' ) || empty( $wpdb->prefix ) ||
             ( ! is_int( $formid ) && ! is_string( $formid ) ) ||
             ! preg_match( '/^[1-9][0-9]*$/D', (string) $formid ) ) { return 0; }
        $id = filter_var( (string) $formid, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( false === $id ) { return 0; }
        $company = $wpdb->get_var( $wpdb->prepare(
            'SELECT company_id FROM %i WHERE id = %d',
            $wpdb->prefix . TZFD_Tables::FORMS, $id
        ) );
        // Unknown and failed owner lookups must never become a usable tenant.
        if ( ! empty( $wpdb->last_error ) || ( ! is_int( $company ) && ! is_string( $company ) ) ||
             ! preg_match( '/^[1-9][0-9]*$/D', (string) $company ) ) { return 0; }
        $owner = filter_var( (string) $company, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $owner ? 0 : $owner;
    }

    /**
     * Copies the legacy values into the company's scoped options, once. Only the legacy owner receives them.
     * Returns the legacy names that were copied on this call.
     */
    public static function migrate_company( $company ) {
        $company = absint( $company );
        if ( $company < 1 || $company !== self::legacy_owner() ) { return array(); }
        if ( isset( self::$checked[ $company ] ) ) { return array(); }

        $marker = 'tzfd_c' . $company . '_scope_migrated';
        if ( get_option( $marker, false ) ) { self::$checked[ $company ] = true; return array(); }

        $copied = array();
        $failed = false;
        foreach ( self::KEYS as $legacy ) {
            $legacy_value = get_option( $legacy, null );
            if ( null === $legacy_value ) { continue; }
            $scoped_name = self::option_name( $legacy, $company );
            if ( null !== get_option( $scoped_name, null ) ) { continue; }
            // Never mark the migration complete when persistence fails. On the
            // next request, already copied keys are skipped and missing keys retry.
            update_option( $scoped_name, $legacy_value, false );
            if ( get_option( $scoped_name, null ) !== $legacy_value ) {
                $failed = true;
                continue;
            }
            $copied[] = $legacy;
        }
        if ( ! $failed ) {
            update_option( $marker, 1, false );
            if ( get_option( $marker, false ) ) { self::$checked[ $company ] = true; }
        }
        return $copied;
    }

    /** Test helper: forget the per-process migration cache. */
    public static function reset_cache() {
        self::$checked = array();
    }
}
