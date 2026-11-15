<?php
/** Reliable, business-scoped booking deletion for authorised admin requests. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Booking_Delete_Service {
    /**
     * Caller must independently verify the booking-action nonce, staff ACL and target IDs.
     * This guard still scopes the actual SQL deletion against business and selected calendar.
     * A failed write never generates a deleted event.
     */
    public static function delete_selected( $ids, $calendar, $company, $database ) {
        $result = array( 'selected' => 0, 'deleted' => 0, 'failed' => 0 );
        if ( ! is_array( $ids ) || count( $ids ) > 51 ) {
            $result['failed'] = 1;
            return $result;
        }
        $result['selected'] = count( $ids );
        if ( ! is_object( $database ) || ! method_exists( $database, 'delete' ) ||
            ! isset( $database->prefix ) || ! is_string( $database->prefix ) ||
            1 !== preg_match( '/^[a-zA-Z0-9_]+$/D', $database->prefix ) ||
            ! is_int( $company ) || $company < 1 ||
            ! is_int( $calendar ) && ! is_string( $calendar ) ) {
            $result['failed'] = count( $ids ) ?: 1;
            return $result;
        }
        $calendar_id = self::nonnegative_id( $calendar );
        if ( null === $calendar_id ) {
            $result['failed'] = count( $ids ) ?: 1;
            return $result;
        }
        $table = $database->prefix . TZFD_Tables::BOOKINGS;
        $seen = array();
        foreach ( $ids as $raw_id ) {
            $id = self::positive_id( $raw_id );
            if ( null === $id || isset( $seen[ $id ] ) ) {
                ++$result['failed'];
                continue;
            }
            $seen[ $id ] = true;
            $where = array( 'id' => $id, 'company_id' => $company );
            $formats = array( '%d', '%d' );
            if ( $calendar_id > 0 ) {
                $where['formid'] = $calendar_id;
                $formats[] = '%d';
            }
            // Clear stale driver errors before evaluating this operation.
            if ( property_exists( $database, 'last_error' ) ) { $database->last_error = ''; }
            $deleted = $database->delete( $table, $where, $formats );
            if ( 1 !== $deleted || ! empty( $database->last_error ) ) {
                ++$result['failed'];
                continue;
            }
            ++$result['deleted'];
            do_action( 'tz_fd_item_deleted', $id );
        }
        return $result;
    }

    private static function nonnegative_id( $value ) {
        if ( 0 === $value || '0' === $value ) { return 0; }
        return self::positive_id( $value );
    }

    private static function positive_id( $value ) {
        if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
        if ( ! preg_match( '/^[0-9]{1,19}$/D', (string) $value ) ) { return null; }
        $clean = ltrim( (string) $value, '0' );
        if ( '' === $clean ) { return null; }
        $valid = filter_var( $clean, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $valid ? null : $valid;
    }
}
