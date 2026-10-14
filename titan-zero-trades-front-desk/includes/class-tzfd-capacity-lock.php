<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/** Per-form advisory mutex for supported Titan Zero Trades: Front Desk capacity write paths. GPL-2.0-only. */
if (!defined('ABSPATH')) { exit; }

final class TZFD_Capacity_Lock {
    const DEFAULT_TIMEOUT = 5;

    /** Prevent a nested booking callback from re-entering the same connection lock. */
    private static $held = array();

    public static function name($wpdb, $form_id) {
        if (!is_object($wpdb) || !isset($wpdb->dbname, $wpdb->prefix)) { return ''; }
        $id = filter_var($form_id, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1)));
        $database = is_string($wpdb->dbname) ? $wpdb->dbname : '';
        $prefix = is_string($wpdb->prefix) ? $wpdb->prefix : '';
        if ($id === false || $database === '' || $prefix === '') { return ''; }

        // MySQL lock names are limited to 64 characters. Hash the database,
        // site table prefix and calendar ID so names stay isolated and bounded.
        return 'tzfd-capacity-'.substr(hash('sha256', $database.'|'.$prefix.'|'.$id), 0, 52);
    }

    /** Return an opaque release handle, or false when a lock cannot be acquired. */
    public static function acquire($wpdb, $form_id, $timeout = self::DEFAULT_TIMEOUT) {
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') ||
            !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'get_row')) { return false; }
        $name = self::name($wpdb, $form_id);
        if ($name === '') { return false; }
        $wait = filter_var($timeout, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>0, 'max_range'=>30)));
        if ($wait === false) { $wait = self::DEFAULT_TIMEOUT; }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- CONNECTION_ID is required to prevent same-connection re-entry.
        $connection_id = $wpdb->get_var('SELECT CONNECTION_ID()');
        if (!is_scalar($connection_id) || (int)$connection_id < 1 || !empty($wpdb->last_error)) { return false; }
        $tzfd_key = (string)$connection_id.'|'.$name;
        if (isset(self::$held[$tzfd_key])) { return false; }

        try { $token = bin2hex(random_bytes(16)); } catch (Throwable $error) { return false; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- GET_LOCK is the cross-request per-calendar mutex; the values are bound by prepare().
        $row = $wpdb->get_row($wpdb->prepare('SELECT CONNECTION_ID() AS connection_id, GET_LOCK(%s,%d) AS acquired', $name, $wait));
        if (!is_object($row) || !isset($row->connection_id, $row->acquired) ||
            (string)$row->connection_id !== (string)$connection_id ||
            (string)$row->acquired !== '1' || !empty($wpdb->last_error)) {
            if (is_object($row) && isset($row->acquired) && (string)$row->acquired === '1') {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
            return false;
        }

        self::$held[$tzfd_key] = $token;
        return array('name'=>$name, 'connection_id'=>(string)$connection_id, 'token'=>$token);
    }

    /** Release only this acquisition, on the connection that actually owns it. */
    public static function release($wpdb, $handle) {
        if (!is_array($handle) || !isset($handle['name'], $handle['connection_id'], $handle['token']) ||
            !is_string($handle['name']) || !is_string($handle['connection_id']) || !is_string($handle['token']) ||
            !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) { return false; }
        $tzfd_key = $handle['connection_id'].'|'.$handle['name'];
        // A reused handle must not unlock a later reservation on the same form.
        if (!isset(self::$held[$tzfd_key]) || !hash_equals(self::$held[$tzfd_key], $handle['token'])) { return false; }
        $connection_id = $wpdb->get_var('SELECT CONNECTION_ID()');
        if (!is_scalar($connection_id) || (string)$connection_id !== $handle['connection_id'] ||
            !empty($wpdb->last_error)) { return false; }
        $result = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $handle['name']));
        if ((string)$result !== '1' || !empty($wpdb->last_error)) { return false; }
        // Failed/foreign releases must not erase the same-connection reentry guard.
        unset(self::$held[$tzfd_key]);
        return true;
    }
}
