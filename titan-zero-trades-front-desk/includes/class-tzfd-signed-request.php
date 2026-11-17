<?php
/**
 * Signed, timestamped inbound requests (missed-call webhook).
 *
 * The plugin generates the secret and shows it once. A sender signs each request:
 *   X-TZFD-Timestamp: <Unix seconds>
 *   X-TZFD-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" keyed with the secret>
 * Requests more than SIGNATURE_WINDOW seconds from the server clock are refused, and a
 * signature already seen within REPLAY_TTL seconds is refused as a replay.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Signed_Request {
    const SIGNATURE_WINDOW = 300; // seconds either side of the server clock
    const REPLAY_TTL       = 600; // seconds a used signature is remembered
    const MIN_SECRET       = 64;  // characters

    /** A new secret: 64 random hex characters. */
    public static function new_secret() {
        return bin2hex( random_bytes( 32 ) );
    }

    /** True when the timestamp and signature match the raw body. Fails closed on a short secret. */
    public static function signature_valid( $secret, $timestamp, $signature, $body, $now ) {
        if ( ! is_string( $secret ) || strlen( $secret ) < self::MIN_SECRET ) return false;
        if ( ! is_string( $timestamp ) || ! is_string( $signature ) || ! is_string( $body ) ) return false;
        if ( 1 !== preg_match( '/^[0-9]{1,11}$/D', $timestamp ) || strlen( $signature ) > 80 ) return false;
        if ( abs( (int) $now - (int) $timestamp ) > self::SIGNATURE_WINDOW ) return false;
        if ( 0 !== strpos( $signature, 'sha256=' ) ) return false;
        $expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
        return hash_equals( $expected, $signature );
    }

    /** Option-name prefix for claimed signatures. The name holds only a hash of the signature. */
    const CLAIM_PREFIX = 'tz_fd_sig_';

    /** Option name for a signature: prefix plus SHA-256 of the signature (never the raw value). */
    public static function claim_key( $signature ) {
        return self::CLAIM_PREFIX . hash( 'sha256', (string) $signature );
    }

    /**
     * Records a signature for REPLAY_TTL seconds. Returns true only for the single caller that
     * claims it; false when it was already claimed (or the store fails, so the request fails closed).
     *
     * Atomic: the claim is one SQL statement. wp_options.option_name is a UNIQUE key, so INSERT IGNORE
     * affects exactly one row for the first concurrent writer and zero rows for every other writer.
     * A claim older than REPLAY_TTL can be taken over by a single conditional UPDATE, which is also
     * atomic. The check is never a read followed by a write.
     *
     * @param string   $signature The X-TZFD-Signature header value.
     * @param int|null $now       Unix seconds; defaults to the current time.
     */
    public static function claim_signature( $signature, $now = null ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) return false;
        $now  = null === $now ? time() : (int) $now;
        $name = self::claim_key( $signature );

        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            (string) $now
        ) );
        if ( false === $inserted ) return false;
        if ( 1 === (int) $inserted ) {
            wp_cache_delete( $name, 'options' );
            self::purge_expired( $now );
            return true;
        }

        // Row already exists. Take it over only when its claim has expired.
        $taken = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d",
            (string) $now,
            $name,
            $now - self::REPLAY_TTL
        ) );
        wp_cache_delete( $name, 'options' );
        return 1 === (int) $taken;
    }

    /**
     * Drops a claim so the sender may retry. Used when the write behind a verified request failed, so the
     * provider's retry is processed rather than answered as a replay.
     */
    public static function release_signature( $signature ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) return false;
        $name = self::claim_key( $signature );
        $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        wp_cache_delete( $name, 'options' );
        return 1 === (int) $deleted;
    }

    /**
     * Removes claims older than REPLAY_TTL so the store stays bounded. Runs on about one claim in
     * twenty, which keeps the webhook path cheap.
     */
    public static function purge_expired( $now = null ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) return 0;
        if ( 1 !== mt_rand( 1, 20 ) ) return 0;
        $now = null === $now ? time() : (int) $now;
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d",
            $wpdb->esc_like( self::CLAIM_PREFIX ) . '%',
            $now - self::REPLAY_TTL
        ) );
        return false === $deleted ? 0 : (int) $deleted;
    }
}
