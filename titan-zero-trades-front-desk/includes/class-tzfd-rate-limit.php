<?php
/**
 * Atomic fixed-window rate limit for public, unauthenticated entry points.
 *
 * Each key is one wp_options row (autoload 'no') holding "<window_end>:<count>". Every decision is a single
 * conditional SQL statement, so two concurrent requests cannot both pass a check that was read before either
 * one wrote. wp_options.option_name is a UNIQUE key: the first INSERT for a key creates the row, and every
 * other concurrent INSERT affects zero rows. The check is never a read followed by a write.
 *
 * Expired rows are reused in place by the next request for the same key. purge_expired() removes the rest so
 * the table stays bounded. Transients are not used because get_transient() followed by set_transient() is a
 * race under parallel requests.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Rate_Limit {

    const OPTION_PREFIX = 'tzfd_rl_';
    /** Purge expired rows on about one call in this many, which keeps the hot path cheap. */
    const PURGE_ONE_IN  = 20;

    /**
     * Records one hit against $key. Returns true when the hit is within $limit hits per $window seconds.
     * Returns false when the limit is reached, and also when the store fails, so the request fails closed.
     *
     * @param string   $key    Caller's key. It is hashed, so the option name never holds the raw key.
     * @param int      $limit  Maximum hits allowed in one window.
     * @param int      $window Window length in seconds.
     * @param int|null $now    Unix seconds; defaults to the current time.
     * @return bool
     */
    public static function allow( $key, $limit, $window, $now = null ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
        $limit  = max( 1, (int) $limit );
        $window = max( 1, (int) $window );
        $now    = null === $now ? time() : (int) $now;
        $name   = self::option_name( $key );

        // A bounded loop covers the one case where the row disappears between statements (a concurrent purge).
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            // 1. No row yet: the first hit in a new window creates it. Exactly one concurrent caller inserts.
            $created = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                ( $now + $window ) . ':1'
            ) );
            if ( false === $created ) { return false; }
            if ( 1 === (int) $created ) {
                self::flush( $name );
                if ( 0 === random_int( 0, self::PURGE_ONE_IN - 1 ) ) { self::purge_expired( $now ); }
                return true;
            }

            // 2. Row exists in its window and below the limit: count this hit. The WHERE clause is the check.
            $counted = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, ':', 1), ':', CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) + 1) WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) > %d AND CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) < %d",
                $name,
                $now,
                $limit
            ) );
            if ( false === $counted ) { return false; }
            self::flush( $name );
            if ( 1 === (int) $counted ) { return true; }

            // 3. Window expired: start a new one. Only the expired row matches, so one concurrent caller wins.
            $reset = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
                ( $now + $window ) . ':1',
                $name,
                $now
            ) );
            if ( false === $reset ) { return false; }
            self::flush( $name );
            if ( 1 === (int) $reset ) { return true; }

            // 4. Nothing matched. If the row still exists, the window is full: deny. If it vanished, try again.
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $name ) );
            if ( null !== $exists ) { return false; }
        }
        return false;
    }

    /**
     * Removes the hit count for $key so the next call starts a fresh window. Used when a request was refused
     * for a reason the visitor did not cause, so that refusal does not count against them.
     *
     * @param string $key Caller's key, as passed to allow().
     * @return bool
     */
    public static function release( $key ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
        $name = self::option_name( $key );
        $gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        self::flush( $name );
        return false !== $gone;
    }

    /** Deletes rows whose window ended at or before $now. Returns the number of rows removed, or 0 on failure. */
    public static function purge_expired( $now = null ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
        $now  = null === $now ? time() : (int) $now;
        $like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        $n    = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
            $like,
            $now
        ) );
        return false === $n ? 0 : (int) $n;
    }

    /**
     * Option name for a caller key. Keyed with the site salt, so it is bounded in length and an address stored
     * in a key (for example a client IP) cannot be recovered from the option table by hashing guessed addresses.
     */
    public static function option_name( $key ) {
        $salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'tzfd-rate-limit';
        return self::OPTION_PREFIX . hash_hmac( 'sha256', (string) $key, (string) $salt );
    }

    private static function flush( $name ) {
        if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $name, 'options' ); }
    }
}
