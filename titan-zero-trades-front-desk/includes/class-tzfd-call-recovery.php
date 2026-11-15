<?php
/** Booking/call scoped recovery decisions. No outbound delivery or AI inference. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Call_Recovery {
    /** Settings are interpreted in the WordPress site's timezone. */
    public static function is_after_hours( $schedule, $timestamp = null, $timezone = null ) {
        $timezone = $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone( 'UTC' );
        $at = new DateTimeImmutable( '@' . ( $timestamp === null ? time() : (int) $timestamp ) );
        $at = $at->setTimezone( $timezone );
        $day = strtolower( $at->format( 'D' ) );
        $now = $at->format( 'H:i' );
        $previous = strtolower( $at->modify( '-1 day' )->format( 'D' ) );
        $prior = $schedule[$previous] ?? null;
        if ( is_array( $prior ) && count( $prior ) === 2 && self::valid_time( $prior[0] ) && self::valid_time( $prior[1] ) && $prior[0] > $prior[1] && $now < $prior[1] ) { return false; }
        $hours = $schedule[$day] ?? null;
        if ( ! is_array( $hours ) || count( $hours ) !== 2 || ! self::valid_time( $hours[0] ) || ! self::valid_time( $hours[1] ) || $hours[0] === $hours[1] ) { return true; }
        return !( $now >= $hours[0] && ( $hours[0] > $hours[1] || $now < $hours[1] ) );
    }
    private static function valid_time( $v ) { return is_string( $v ) && (bool) preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $v ); }
    public static function outcome( $event, $status, $opted_out, $within_limit, $configured ) {
        if ( $event !== 'missed_call' || ! in_array( $status, array( 'missed','busy','no-answer' ), true ) ) { return 'ignored'; }
        if ( $opted_out ) { return 'opted_out'; }
        if ( ! $within_limit ) { return 'rate_limited'; }
        if ( ! $configured ) { return 'not_configured'; }
        return 'eligible';
    }
}
