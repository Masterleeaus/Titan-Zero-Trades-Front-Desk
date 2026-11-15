<?php
/**
 * Booking pipeline rules: states, allowed moves, and the checks run by the booking manager
 * and the jobs check. Pure PHP with no WordPress calls, so the rules can be tested without a database.
 *
 * Flow for every booking the assistant creates:
 *   intake (booking agent) -> processing -> booking manager checks -> processed
 *   -> jobs check (slot still free) -> approved (automatic, or by staff)
 * A failed check, or a booking stuck too long, is escalated to staff. Staff may approve,
 * reject, or send it back for another check.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Pipeline_Rules {

    /** Waiting for the booking manager, or being checked by it. */
    const PROCESSING = 'processing';
    /** Booking manager passed. Waiting for the jobs check and then approval. */
    const PROCESSED  = 'processed';
    /** Confirmed in the calendar. Final. */
    const APPROVED   = 'approved';
    /** A check failed or the booking timed out. Staff decide. */
    const ESCALATED  = 'escalated';
    /** Rejected by staff. Final. */
    const REJECTED   = 'rejected';

    const DEFAULT_DURATION = 60;
    const MIN_DURATION     = 5;
    const MAX_DURATION     = 720;
    /** Seconds a booking may sit in processing before the supervisor escalates it. */
    const STUCK_AFTER      = 1800;
    /** Seconds a booking must sit before the supervisor retries it. */
    const RETRY_AFTER      = 120;

    public static function states() {
        return array( self::PROCESSING, self::PROCESSED, self::APPROVED, self::ESCALATED, self::REJECTED );
    }

    /** Allowed moves between states. Anything not listed is refused. */
    public static function moves() {
        return array(
            self::PROCESSING => array( self::PROCESSED, self::ESCALATED, self::REJECTED ),
            self::PROCESSED  => array( self::APPROVED, self::ESCALATED, self::REJECTED ),
            self::ESCALATED  => array( self::APPROVED, self::PROCESSING, self::REJECTED ),
            self::APPROVED   => array(),
            self::REJECTED   => array(),
        );
    }

    public static function can_move( $from, $to ) {
        $moves = self::moves();
        return isset( $moves[ (string) $from ] ) && in_array( (string) $to, $moves[ (string) $from ], true );
    }

    public static function is_final( $state ) {
        return in_array( (string) $state, array( self::APPROVED, self::REJECTED ), true );
    }

    /** True when the supervisor should look at a booking that has been in this state too long. */
    public static function is_stuck( $state, $age_seconds, $limit = self::STUCK_AFTER ) {
        return in_array( (string) $state, array( self::PROCESSING, self::PROCESSED ), true ) && (int) $age_seconds >= (int) $limit;
    }

    public static function valid_date( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return false; }
        $parts = explode( '-', $value );
        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
    }

    /** 24-hour HH:MM. */
    public static function valid_time( $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) { return false; }
        return true;
    }

    public static function minutes_of( $hhmm ) {
        return ( (int) substr( $hhmm, 0, 2 ) ) * 60 + (int) substr( $hhmm, 3, 2 );
    }

    /**
     * Stored slot string used by the calendar: "HH:MM/HH:MM" (start and end).
     * Returns an empty string when the start time or duration is not usable.
     */
    public static function slot_string( $start, $duration ) {
        if ( ! self::valid_time( $start ) || (int) $duration < self::MIN_DURATION || (int) $duration > self::MAX_DURATION ) {
            return '';
        }
        $end = ( self::minutes_of( $start ) + (int) $duration ) % 1440;
        return $start . '/' . sprintf( '%02d:%02d', intdiv( $end, 60 ), $end % 60 );
    }

    /**
     * Splits a stored slot into start and end. Returns null if the slot is not in the calendar's format.
     * @return array|null  keys: start, end
     */
    public static function split_slot( $slot ) {
        if ( ! is_string( $slot ) || 1 !== preg_match( '/^(\d{2}:\d{2})\/(\d{2}:\d{2})$/', $slot, $m ) ) { return null; }
        if ( ! self::valid_time( $m[1] ) || ! self::valid_time( $m[2] ) ) { return null; }
        return array( 'start' => $m[1], 'end' => $m[2] );
    }

    /** Minutes between start and end. Null when the end is not after the start (for example past midnight). */
    public static function duration_minutes( $start, $end ) {
        if ( ! self::valid_time( $start ) || ! self::valid_time( $end ) ) { return null; }
        $diff = self::minutes_of( $end ) - self::minutes_of( $start );
        return $diff > 0 ? $diff : null;
    }

    /**
     * Booking manager checks: is this booking complete and sensible?
     *
     * @param array  $b    keys: date (Y-m-d), start (HH:MM), duration (minutes), service, name, email, phone.
     * @param string $now  Current site time as Y-m-d H:i.
     * @return array       keys: ok (bool), reasons (string codes; empty when ok).
     */
    public static function compliance( array $b, $now ) {
        $reasons  = array();
        $date     = isset( $b['date'] ) ? (string) $b['date'] : '';
        $start    = isset( $b['start'] ) ? (string) $b['start'] : '';
        $duration = isset( $b['duration'] ) ? (int) $b['duration'] : 0;

        $date_ok = self::valid_date( $date );
        $time_ok = self::valid_time( $start );
        if ( ! $date_ok ) { $reasons[] = 'invalid_date'; }
        if ( ! $time_ok ) { $reasons[] = 'invalid_time'; }
        if ( $duration < self::MIN_DURATION || $duration > self::MAX_DURATION ) {
            $reasons[] = 'invalid_duration';
        } elseif ( $time_ok && self::minutes_of( $start ) + $duration > 1440 ) {
            $reasons[] = 'ends_after_midnight';
        }
        if ( $date_ok && $time_ok && (string) $now !== '' && ( $date . ' ' . $start ) < (string) $now ) {
            $reasons[] = 'in_past';
        }

        if ( '' === trim( (string) ( $b['service'] ?? '' ) ) ) { $reasons[] = 'no_service'; }
        if ( '' === trim( (string) ( $b['name'] ?? '' ) ) ) { $reasons[] = 'no_name'; }

        $email = trim( (string) ( $b['email'] ?? '' ) );
        $phone = preg_replace( '/\D/', '', (string) ( $b['phone'] ?? '' ) );
        $email_ok = '' !== $email && false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
        if ( '' !== $email && ! $email_ok ) { $reasons[] = 'invalid_email'; }
        if ( ! $email_ok && strlen( (string) $phone ) < 6 ) { $reasons[] = 'no_contact'; }

        return array( 'ok' => empty( $reasons ), 'reasons' => array_values( array_unique( $reasons ) ) );
    }

    /**
     * Jobs check: is the slot still free for this booking?
     *
     * @param array $f keys: occupied (other active bookings in the slot), capacity (places per slot).
     * @return array   keys: ok (bool), reasons (string codes).
     */
    public static function fulfilment( array $f ) {
        $occupied = max( 0, (int) ( $f['occupied'] ?? 0 ) );
        $capacity = max( 1, (int) ( $f['capacity'] ?? 1 ) );
        $reasons  = array();
        if ( $occupied >= $capacity ) { $reasons[] = 'slot_taken'; }
        return array( 'ok' => empty( $reasons ), 'reasons' => $reasons );
    }

    /** Merges reason lists, keeping order and removing duplicates. */
    public static function merge_reasons( array $a, array $b ) {
        return array_values( array_unique( array_merge( $a, $b ) ) );
    }
}
