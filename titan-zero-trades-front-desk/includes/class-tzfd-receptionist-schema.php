<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tables for the AI Receptionist: the receptionist itself, its staff list,
 * and its knowledge-base (FAQ) answers.
 *
 * The tables are created on activation and again whenever the schema version
 * changes. Creating them through dbDelta keeps existing sites safe, because
 * dbDelta only adds missing columns and indexes and never drops data.
 */
if ( ! class_exists( 'TZFD_Receptionist_Schema' ) ) {
    class TZFD_Receptionist_Schema {
        const T_RECEPTIONISTS = 'tzfd_receptionists';
        const T_STAFF         = 'tzfd_receptionist_staff';
        const T_KB            = 'tzfd_receptionist_kb';

        const DB_VERSION      = '1';
        const OPTION          = 'tzfd_receptionist_db_version';

        /** Runs the schema only when its version changes, not on every page view. */
        public static function maybe_install() {
            if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
                self::install();
                update_option( self::OPTION, self::DB_VERSION, false );
            }
        }

        public static function install() {
            global $wpdb;
            $charset = $wpdb->get_charset_collate();
            $p       = $wpdb->prefix;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta( "CREATE TABLE {$p}" . self::T_RECEPTIONISTS . " (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                name varchar(190) DEFAULT '' NOT NULL,
                slug varchar(100) DEFAULT '' NOT NULL,
                voice varchar(60) DEFAULT '' NOT NULL,
                greeting text,
                active tinyint(1) DEFAULT 1 NOT NULL,
                language varchar(20) DEFAULT 'en-US' NOT NULL,
                timezone varchar(60) DEFAULT '' NOT NULL,
                formid int DEFAULT 0 NOT NULL,
                created datetime DEFAULT CURRENT_TIMESTAMP,
                updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY slug (slug)
            ) {$charset};" );

            dbDelta( "CREATE TABLE {$p}" . self::T_STAFF . " (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                receptionist_id mediumint(9) DEFAULT 0 NOT NULL,
                name varchar(190) DEFAULT '' NOT NULL,
                phone varchar(40) DEFAULT '' NOT NULL,
                email varchar(190) DEFAULT '' NOT NULL,
                skills varchar(500) DEFAULT '' NOT NULL,
                priority int DEFAULT 10 NOT NULL,
                available tinyint(1) DEFAULT 1 NOT NULL,
                created datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY receptionist_id (receptionist_id)
            ) {$charset};" );

            dbDelta( "CREATE TABLE {$p}" . self::T_KB . " (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                receptionist_id mediumint(9) DEFAULT 0 NOT NULL,
                question varchar(500) DEFAULT '' NOT NULL,
                answer text,
                keywords varchar(500) DEFAULT '' NOT NULL,
                priority int DEFAULT 10 NOT NULL,
                active tinyint(1) DEFAULT 1 NOT NULL,
                created datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY receptionist_active (receptionist_id, active)
            ) {$charset};" );
        }
    }
}
