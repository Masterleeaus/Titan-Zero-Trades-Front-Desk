<?php
/**
 * Deterministic spam and duplicate signals for enquiries and booking requests (issue #34).
 *
 * Pure functions only: no database, no network, no model output. The caller passes the current submission and the
 * prior submissions for the same identity (email or phone). The result is a list of reasons and a decision of 'ok'
 * or 'review'. Nothing here deletes a record, blocks a customer or refuses a booking. A 'review' result only groups
 * the submission for an operator. Shared IP addresses (NAT) are never an identity on their own; per-IP throttling
 * stays in the standard rate limit, which is separate from this class.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Enquiry_Spam_Signals {

    const AUTO_ACTION        = 'none';   // Fixed: this class never deletes, bans or refuses.
    const BURST_WINDOW       = 600;      // Seconds. Same window as the existing enquiry duplicate guard.
    const BURST_LIMIT        = 3;        // Submissions from one identity inside the window that count as a burst.
    const CLOCK_SKEW         = 60;       // Seconds a prior record may sit in the future before it is ignored.
    const SHINGLE_SIZE       = 3;        // Word shingle length for near-duplicate comparison.
    const NEAR_DUP_THRESHOLD = 0.7;      // Jaccard similarity at or above which two texts are near-duplicates (one-word edit of a 20-word message scores about 0.74).
    const MIN_TOKENS         = 4;        // Shorter messages ("please call me") are never near-duplicate flagged.

    /**
     * Normalises text so that cosmetic differences do not change the signature.
     * Returns '' for non-strings and for invalid UTF-8 (no signal can be drawn from them).
     */
    public static function normalize( $text ) {
        if ( ! is_string( $text ) || '' === $text || 1 !== preg_match( '//u', $text ) ) { return ''; }
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        // Zero-width and bidi control characters, soft hyphen and BOM are removed so they cannot split or mask a match.
        $stripped = preg_replace( '/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text );
        if ( null === $stripped ) { return ''; }
        $text = $stripped;
        if ( class_exists( 'Normalizer' ) ) {
            $composed = Normalizer::normalize( $text, Normalizer::FORM_KC );
            if ( false !== $composed ) { $text = $composed; }
        }
        $text    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $spaced  = preg_replace( '/\s+/u', ' ', $text );
        return null === $spaced ? '' : trim( $spaced );
    }

    /** Stable SHA-256 signature of the normalised text, or '' when there is no usable text. */
    public static function signature( $text ) {
        $norm = self::normalize( $text );
        return '' === $norm ? '' : hash( 'sha256', $norm );
    }

    /** Word tokens of the normalised text (letters and digits, any script). */
    public static function tokens( $text ) {
        $norm = self::normalize( $text );
        if ( '' === $norm ) { return array(); }
        $parts = preg_split( '/[^\p{L}\p{N}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? $parts : array();
    }

    /** Set of word shingles (keys) for near-duplicate comparison. */
    public static function shingles( $text ) {
        $tokens = self::tokens( $text );
        $size   = self::SHINGLE_SIZE;
        $count  = count( $tokens );
        $set    = array();
        if ( $count < $size ) {
            if ( $count > 0 ) { $set[ implode( ' ', $tokens ) ] = true; }
            return $set;
        }
        for ( $i = 0; $i <= $count - $size; $i++ ) {
            $set[ implode( ' ', array_slice( $tokens, $i, $size ) ) ] = true;
        }
        return $set;
    }

    /** Jaccard similarity of two texts' shingle sets, 0.0 to 1.0. */
    public static function similarity( $a, $b ) {
        $sa = self::shingles( $a );
        $sb = self::shingles( $b );
        if ( empty( $sa ) || empty( $sb ) ) { return 0.0; }
        $inter = count( array_intersect_key( $sa, $sb ) );
        $union = count( $sa ) + count( $sb ) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Evaluates one submission against prior submissions from the same identity.
     *
     * @param int    $company_id Company the submission belongs to. Prior records from other companies are ignored.
     * @param string $text       Enquiry or booking message text.
     * @param int    $now        Unix time of the current submission.
     * @param array  $prior      List of arrays with keys company_id (int), text (string), time (int Unix seconds).
     * @return array {decision: 'ok'|'review', reasons: string[], signature: string, group_key: string, auto_action: 'none'}
     */
    public static function evaluate( $company_id, $text, $now, $prior ) {
        $result = array(
            'decision'    => 'ok',
            'reasons'     => array(),
            'signature'   => self::signature( $text ),
            'group_key'   => '',
            'auto_action' => self::AUTO_ACTION,
        );
        if ( ! is_int( $company_id ) || $company_id < 1 || ! is_int( $now ) || ! is_array( $prior ) ) {
            return $result; // Invalid input never produces a review and never produces a refusal.
        }

        $window_start = $now - self::BURST_WINDOW;
        $window_end   = $now + self::CLOCK_SKEW;
        $in_window    = 0;
        $same_sig     = false;
        $near_dup     = false;
        $sig          = $result['signature'];

        foreach ( $prior as $record ) {
            if ( ! is_array( $record ) || ! isset( $record['company_id'], $record['text'], $record['time'] ) ) { continue; }
            if ( $record['company_id'] !== $company_id ) { continue; } // Tenant isolation: another company is never compared.
            if ( ! is_int( $record['time'] ) || $record['time'] < $window_start || $record['time'] > $window_end ) { continue; }
            $in_window++;
            if ( '' === $sig ) { continue; }
            if ( self::signature( $record['text'] ) === $sig ) {
                $same_sig = true;
                continue;
            }
            if ( count( self::tokens( $text ) ) >= self::MIN_TOKENS
                && self::similarity( $text, $record['text'] ) >= self::NEAR_DUP_THRESHOLD ) {
                $near_dup = true;
            }
        }

        // The current submission counts toward the burst limit.
        if ( $in_window + 1 >= self::BURST_LIMIT ) { $result['reasons'][] = 'burst'; }
        if ( $same_sig ) { $result['reasons'][] = 'repeat_signature'; }
        if ( $near_dup ) { $result['reasons'][] = 'near_duplicate'; }

        if ( ! empty( $result['reasons'] ) ) {
            $result['decision']  = 'review';
            $result['group_key'] = hash( 'sha256', $company_id . '|' . ( '' !== $sig ? $sig : 'no-text' ) );
        }
        return $result;
    }
}
