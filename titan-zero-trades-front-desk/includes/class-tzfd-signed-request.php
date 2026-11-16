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

    /**
     * Records a signature for REPLAY_TTL seconds. Returns false when it was already seen.
     * add_option() is an atomic insert on the unique option_name, so two concurrent deliveries
     * of the same signed request cannot both claim it (a get-then-set transient could).
     * The stored value is a zero-padded timestamp so expired claims can be purged by comparison.
     */
    public static function claim_signature( $signature ) {
        global $wpdb;
        $key = 'tz_fd_sig_' . hash( 'sha256', (string) $signature );
        $now = time();
        if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'esc_like' ) && ! empty( $wpdb->options ) ) {
            $like   = $wpdb->esc_like( 'tz_fd_sig_' ) . '%';
            $cutoff = sprintf( '%012d', $now - self::REPLAY_TTL );
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s", $like, $cutoff ) );
        }
        return (bool) add_option( $key, sprintf( '%012d', $now ), '', 'no' );
    }
}
