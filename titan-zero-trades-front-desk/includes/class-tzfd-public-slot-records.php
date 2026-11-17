<?php
/** Hardened public calendar-feed result checks. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Public_Slot_Records {
    /** Only a successful, complete database result may drive public availability. */
    public static function valid_query_result( $rows, $last_error ) {
        if ( ! is_array( $rows ) || ! is_string( $last_error ) || '' !== $last_error ) { return false; }
        foreach ( $rows as $row ) {
            if ( ! is_object( $row ) || ! isset( $row->posted_data ) || ! is_string( $row->posted_data ) ) {
                return false;
            }
        }
        return true;
    }
    /** An unreadable booking cannot be treated as an empty slot inventory. */
    public static function valid_booking_payload( $payload ) {
        if ( ! is_array( $payload ) || ! isset( $payload['apps'] ) || ! is_array( $payload['apps'] ) ) { return false; }
        foreach ( $payload['apps'] as $appointment ) {
            if ( ! self::valid_appointment( $appointment ) ) { return false; }
        }
        return true;
    }
    /** A historical record with missing slot metadata must not reach slot construction. */
    public static function valid_appointment( $appointment ) {
        if ( ! is_array( $appointment ) || ! isset( $appointment['date'], $appointment['slot'] ) ||
            ! is_string( $appointment['date'] ) || ! is_string( $appointment['slot'] ) ) { return false; }
        $date = $appointment['date'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ) { return false; }
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
        if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) { return false; }
        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d[\/-]([01]\d|2[0-3]):[0-5]\d$/D', $appointment['slot'] ) ) { return false; }
        $start = substr( $appointment['slot'], 0, 5 );
        $end = substr( $appointment['slot'], 6, 5 );
        if ( $end <= $start ) { return false; }
        foreach ( array( 'field', 'cancelled' ) as $key ) {
            if ( isset( $appointment[$key] ) && ! is_string( $appointment[$key] ) ) { return false; }
        }
        foreach ( array( 'quant', 'serviceindex', 'sid' ) as $key ) {
            if ( isset( $appointment[$key] ) && ! is_scalar( $appointment[$key] ) ) { return false; }
        }
        return true;
    }
    /** Option that lists, per calendar, the stored booking rows the public feed skipped (#183). */
    const SKIPPED_OPTION = 'tzfd_public_feed_skipped_rows';

    /**
     * Stored booking rows whose payload cannot drive slot construction. Returns their ids. The feed skips these rows
     * (owner decision, #183) and flags them; it does not block the calendar.
     */
    public static function unreadable_row_ids( $rows ) {
        $ids = array();
        if ( ! is_array( $rows ) ) { return $ids; }
        foreach ( $rows as $row ) {
            if ( ! is_object( $row ) || ! isset( $row->posted_data ) || ! is_string( $row->posted_data ) ) { continue; }
            if ( ! self::valid_booking_payload( TZFD_Stored_Data::decode_booking( $row->posted_data ) ) ) {
                $ids[] = isset( $row->id ) ? (int) $row->id : 0;
            }
        }
        return $ids;
    }

    /**
     * Replace the calendar's flagged rows with the rows skipped in this feed build, and log each one. Stored per calendar,
     * so a row that is repaired or removed drops out of the flag on the next build.
     */
    public static function record_skipped_rows( $calendar_id, array $booking_ids ) {
        $calendar_id = absint( $calendar_id );
        $booking_ids = array_values( array_unique( array_filter( array_map( 'absint', $booking_ids ) ) ) );
        $all         = get_option( self::SKIPPED_OPTION, array() );
        $all         = is_array( $all ) ? $all : array();
        $before      = isset( $all[ $calendar_id ] ) ? $all[ $calendar_id ] : array();
        if ( $booking_ids ) { $all[ $calendar_id ] = $booking_ids; } else { unset( $all[ $calendar_id ] ); }
        if ( $before !== ( $booking_ids ? $booking_ids : array() ) ) {
            update_option( self::SKIPPED_OPTION, $all, false );
        }
        foreach ( $booking_ids as $booking_id ) {
            error_log( sprintf( 'Titan Zero Front Desk: skipped stored booking %d of calendar %d in public availability (unreadable payload).', $booking_id, $calendar_id ) );
        }
    }

    /** Admin notice: lists every skipped row, so the problem stays visible until the row is fixed or removed. */
    public static function admin_notice() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { return; }
        $all = get_option( self::SKIPPED_OPTION, array() );
        if ( ! is_array( $all ) || ! $all ) { return; }
        $items = array();
        foreach ( $all as $calendar_id => $booking_ids ) {
            if ( is_array( $booking_ids ) && $booking_ids ) {
                $items[] = sprintf( /* translators: 1: calendar id, 2: comma-separated booking ids */ __( 'calendar %1$d: booking %2$s', 'titan-zero-trades-front-desk' ), (int) $calendar_id, implode( ', ', array_map( 'absint', $booking_ids ) ) );
            }
        }
        if ( ! $items ) { return; }
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Titan Zero Front Desk skipped stored bookings when it built public availability. Public booking still works, but the slots those bookings hold may be offered as free. Repair or remove them under Bookings.', 'titan-zero-trades-front-desk' ) . '</p><p>' . esc_html( implode( '; ', $items ) ) . '</p></div>';
    }
}
