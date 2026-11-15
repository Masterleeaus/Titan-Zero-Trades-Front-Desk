<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Verify all tables in a cross-component write are transactional BEFORE START TRANSACTION.
 * Unknown/missing storage engines are not safe; never silently fall back to MyISAM.
 */
final class TZFD_Transaction_Storage {
    public static function require_innodb( array $tables, $connection = null ) {
        global $wpdb;
        // Use the transaction's own connection when provided; checking another
        // connection can accept the wrong database/table engine.
        $db = null === $connection ? $wpdb : $connection;
        if ( empty( $tables ) || ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_var' ) ) {
            return false;
        }
        foreach ( $tables as $table ) {
            if ( ! is_string( $table ) || '' === $table || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) {
                return false;
            }
            try {
                $engine = $db->get_var( $db->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $table
                ) );
            } catch ( Throwable $error ) {
                return false;
            }
            if ( ! is_string( $engine ) || 0 !== strcasecmp( $engine, 'InnoDB' ) || ! empty( $db->last_error ) ) {
                return false;
            }
        }
        return true;
    }
}
