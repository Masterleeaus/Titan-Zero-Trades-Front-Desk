<?php
/**
 * Verify the authoritative, tenant-scoped final booking write before confirmation.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Final_Booking_Write {
    /**
     * The update can legitimately affect zero rows if the same data is already stored.
     * A read-back is required in every case: zero also means the row was deleted.
     */
    public static function persist( $database, $booking_id, $company_id, $payload ) {
        if ( ! is_object( $database ) || ! method_exists( $database, 'update' ) ||
             ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ||
             ! isset( $database->prefix ) || ! is_string( $database->prefix ) ||
             ! defined( 'ABSPATH' ) || ! class_exists( 'TZFD_Tables' ) ||
             ! is_int( $booking_id ) || $booking_id < 1 ||
             ! is_int( $company_id ) || $company_id < 1 ||
             ! is_string( $payload ) || '' === $payload ) {
            return false;
        }
        $table = $database->prefix . TZFD_Tables::BOOKINGS;
        // The update always includes the company predicate. No other tenant can be modified.
        $updated = $database->update(
            $table,
            array( 'posted_data' => $payload ),
            array( 'id' => $booking_id, 'company_id' => $company_id ),
            array( '%s' ),
            array( '%d', '%d' )
        );
        if ( false === $updated || ( isset( $database->last_error ) && '' !== (string) $database->last_error ) ) {
            return false;
        }
        // Verify the row rather than interpreting zero affected rows as success.
        $stored = $database->get_row(
            $database->prepare(
                'SELECT id, posted_data FROM %i WHERE id = %d AND company_id = %d LIMIT 1',
                $table, $booking_id, $company_id
            )
        );
        if ( ( isset( $database->last_error ) && '' !== (string) $database->last_error ) ||
             ! is_object( $stored ) || ! isset( $stored->id, $stored->posted_data ) ) {
            return false;
        }
        return (string) $stored->id === (string) $booking_id &&
            is_string( $stored->posted_data ) && hash_equals( $payload, $stored->posted_data );
    }
}
