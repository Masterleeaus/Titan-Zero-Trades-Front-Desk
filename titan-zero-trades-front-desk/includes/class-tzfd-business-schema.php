<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Front Desk schema step, run by TZFD_Business_Schema::run() on activation.
 *
 * Step 600 is Front Desk's version block (Workspace reserves 1 to 99 for the
 * shell and 100 to 599 for other plugins; see shell/data/NOTES.md). It is
 * idempotent. It creates tz_enquiries and adds company_id to each Front Desk table
 * that predates it. Fresh installs already get company_id from the CREATE statements.
 * Quote and invoice tables moved to Titan Zero Quotes and Titan Zero Invoicing (19 Oct split);
 * this step no longer creates or alters them.
 */
final class TZFD_Business_Schema {

    const STEP = 600;

    /** Business tables that must carry company_id. */
    public static function business_tables() {
        return array(
            TZFD_Tables::BOOKINGS,
            TZFD_Tables::ENQUIRIES,
        );
    }

    /** Front Desk operational tables outside the contract list. They also carry company_id (19 Oct). */
    public static function operational_tables() {
        return array(
            TZFD_Tables::FORMS,
            TZFD_Tables::CACHE,
            TZFD_Tables::ICALEXPORT,
            TZFD_Tables::SINGLE_DAYS,
            TZFD_Tables::PIPELINE,
            TZFD_Tables::PIPELINE_EVENTS,
            TZFD_Tables::ADDON_RECORDS,
        );
    }

    /**
     * Adds company_id to one existing table when it is missing, then backfills the rows that have no company
     * (company_id 0) with the site's default company. Runs once per table, because it only acts when the column
     * is missing. With no default company, the rows stay at 0 and scoped reads do not show them (fail closed).
     *
     * @return bool True when the column was added.
     */
    public static function ensure_company_column( $bare, $default_company_id ) {
        global $wpdb;
        $table = TZFD_Tables::checked_name( $bare );
        if ( is_wp_error( $table ) ) { return false; }
        if ( ! self::table_exists( $table ) || self::has_column( $table, 'company_id' ) ) { return false; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed plugin table name from TZFD_Tables; adds the company scope column.
        $added = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN company_id bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY company_id (company_id)', $table ) );
        if ( false === $added ) { return false; }
        $default_company_id = absint( $default_company_id );
        if ( $default_company_id > 0 ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET company_id = %d WHERE company_id = 0', $table, $default_company_id ) );
        }
        return true;
    }

    /** True when the table exists in the current database. */
    private static function table_exists( $table ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /** @return true */
    public static function run() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $enq = TZFD_Tables::name( TZFD_Tables::ENQUIRIES );
        dbDelta( "CREATE TABLE {$enq} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(60) NOT NULL DEFAULT '',
            preferred_contact varchar(20) NOT NULL DEFAULT '',
            enquiry text NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
            campaign_title varchar(190) NOT NULL DEFAULT '',
            source varchar(60) NOT NULL DEFAULT '',
            received_utc datetime NOT NULL,
            privacy_consent_utc datetime NULL DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY company_id (company_id),
            KEY status (status)
        ) {$charset};" );

        // Default company is looked up only when a table actually needs the column.
        $default = null;
        foreach ( array_merge( self::business_tables(), self::operational_tables() ) as $bare ) {
            $table = TZFD_Tables::name( $bare );
            if ( ! self::table_exists( $table ) || self::has_column( $table, 'company_id' ) ) { continue; }
            if ( null === $default ) {
                $default = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::legacy_owner() : 0;
            }
            self::ensure_company_column( $bare, $default );
        }
        return true;
    }

    /** True when the column exists on the table. */
    private static function has_column( $table, $column ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
        return ! empty( $rows );
    }
}
