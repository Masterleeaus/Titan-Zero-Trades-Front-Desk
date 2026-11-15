<?php
/**
 * Tenant-scoped booking lookup for confirmation and notification rendering.
 * A booking identifier is not proof of permission to read its customer data.
 *
 * @package TitanZeroFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Confirmation_Lookup {
    public static function booking( $database, $booking_id, $company_id ) {
        if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) ||
             ! method_exists( $database, 'get_row' ) || ! isset( $database->prefix ) ||
             ! is_string( $database->prefix ) || ! class_exists( 'TZFD_Tables' ) ||
             ! is_int( $booking_id ) || $booking_id < 1 ||
             ! is_int( $company_id ) || $company_id < 1 ) {
            return null;
        }
        $query = $database->prepare(
            'SELECT * FROM %i WHERE id = %d AND company_id = %d LIMIT 1',
            $database->prefix . TZFD_Tables::BOOKINGS,
            $booking_id,
            $company_id
        );
        if ( ! is_string( $query ) || '' === $query ) { return null; }
        $row = $database->get_row( $query );
        if ( ! empty( $database->last_error ) || ! is_object( $row ) ||
             ! isset( $row->id, $row->company_id, $row->posted_data, $row->formid ) ||
             (int) $row->id !== $booking_id || (int) $row->company_id !== $company_id ) {
            return null;
        }
        return $row;
    }
}
