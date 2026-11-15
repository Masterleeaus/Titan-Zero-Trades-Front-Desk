<?php
/**
 * Company boundary and safe range validation for the legacy public booking-slot feed.
 * This endpoint remains anonymous because the booking widget needs availability before login.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Public_Slot_Scope {
    /** Resolve a calendar ID to an authoritative tenant, not the request's tenant. */
    public static function owner_for_form( $form_id ) {
        $form_id = absint( $form_id );
        if ( $form_id < 1 || ! class_exists( 'TZFD_Scoped_Store' ) ) { return 0; }
        return absint( TZFD_Scoped_Store::company_for_form( $form_id ) );
    }

    /** An admin's privilege in one business does not authorize another business's calendar. */
    public static function staff_can_view( $owner_company ) {
        return $owner_company > 0 && class_exists( 'TZFD_Scoped_Store' ) &&
            TZFD_Scoped_Store::company_id() === absint( $owner_company ) &&
            is_user_logged_in() && current_user_can( 'tz_fd_access' );
    }

    /**
     * Both dates are optional for legacy clients. When present, require real YYYY-MM-DD
     * dates, chronological order and a bounded search to avoid full-calendar scans.
     */
    public static function valid_date_range( $start, $end ) {
        if ( '' === $start && '' === $end ) { return true; }
        if ( ! is_string( $start ) || ! is_string( $end ) ||
            ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $start ) ||
            ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $end ) ) { return false; }
        $from = DateTimeImmutable::createFromFormat( '!Y-m-d', $start, new DateTimeZone( 'UTC' ) );
        $to   = DateTimeImmutable::createFromFormat( '!Y-m-d', $end, new DateTimeZone( 'UTC' ) );
        if ( ! $from || ! $to || $from->format( 'Y-m-d' ) !== $start ||
             $to->format( 'Y-m-d' ) !== $end || $from > $to ) { return false; }
        return $from->diff( $to )->days <= 366;
    }
}
