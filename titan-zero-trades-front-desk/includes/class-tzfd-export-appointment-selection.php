<?php
/**
 * Convert historical booking payloads into safe, correctly scoped export rows.
 *
 * @package TitanZeroFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Export_Appointment_Selection {
    /**
     * @param object $row Booking row with posted_data, notifyto and data.
     * @param string $from Inclusive YYYY-MM-DD lower bound.
     * @param string $to Inclusive YYYY-MM-DD upper bound.
     * @param mixed $paid_only Filter for paid bookings.
     * @param string $status Booking status filter.
     * @return array<int,array<int,mixed>> Normalized legacy CSV selection rows.
     */
    public static function from_row( $row, $from, $to, $paid_only, $status ) {
        if ( ! is_object( $row ) || ! isset( $row->posted_data ) || ! is_string( $row->posted_data ) ) {
            return array();
        }
        $booking = TZFD_Stored_Data::decode_booking( $row->posted_data );
        if ( ! is_array( $booking ) || ! isset( $booking['apps'] ) || ! is_array( $booking['apps'] ) ) {
            return array();
        }
        if ( $paid_only && empty( $booking['paid'] ) ) {
            return array();
        }
        $result = array();
        foreach ( $booking['apps'] as $app ) {
            if ( ! is_array( $app ) || ! isset( $app['date'], $app['slot'], $app['service'] ) ||
                ! is_string( $app['date'] ) || ! is_string( $app['slot'] ) ||
                ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $app['date'] ) ||
                ! checkdate( (int) substr( $app['date'], 5, 2 ), (int) substr( $app['date'], 8, 2 ), (int) substr( $app['date'], 0, 4 ) ) ||
                $app['date'] < $from || $app['date'] > $to ) {
                continue;
            }
            $cancelled = isset( $app['cancelled'] ) && is_scalar( $app['cancelled'] ) ? (string) $app['cancelled'] : '';
            if ( ! in_array( (string) $status, array( 'all', '-1' ), true ) && (string) $status !== $cancelled ) {
                continue;
            }
            $service = is_scalar( $app['service'] ) ? (string) $app['service'] : '';
            $slot = (string) $app['slot'];
            if ( '' === trim( $slot ) || '' === $service ) { continue; }
            $result[] = array(
                $app['date'] . ' ' . $slot, $app['date'], $slot, $booking,
                sanitize_email( isset( $row->notifyto ) && is_scalar( $row->notifyto ) ? (string) $row->notifyto : '' ),
                isset( $row->data ) && is_scalar( $row->data ) ? (string) $row->data : '',
                $cancelled, $service,
                isset( $app['quant'] ) && is_numeric( $app['quant'] ) ? $app['quant'] : 1,
            );
        }
        return $result;
    }
}
