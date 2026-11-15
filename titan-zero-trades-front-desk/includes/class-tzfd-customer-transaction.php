<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Safe customer booking transaction control, with explicit unknown-COMMIT semantics. */
if (!defined('ABSPATH')) { exit; }
final class TZFD_Customer_Transaction {
    /** A rejected START is a definite failure; a thrown START does not authorize writes. */
    public static function begin($wpdb) {
        try {
            if (!TZFD_Customer_Event_Outbox::transactional_tables_ready()) { return false; }
            return false !== $wpdb->query('START TRANSACTION');
        } catch (Throwable $error) { return false; }
    }

    /** A lost COMMIT acknowledgment MUST NOT be treated as proof of rollback. */
    public static function commit($wpdb) {
        try { return false !== $wpdb->query('COMMIT') ? 'committed' : 'unknown'; }
        catch (Throwable $error) { return 'unknown'; }
    }

    /** Rollback is best effort and must never mask the original failure. */
    public static function rollback($wpdb) {
        try { return false !== $wpdb->query('ROLLBACK'); }
        catch (Throwable $error) { return false; }
    }
}
