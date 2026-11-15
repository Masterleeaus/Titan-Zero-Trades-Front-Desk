<?php
/**
 * Atomic creation of a Front Desk booking and its final stored payload.
 * No post-save actions are invoked by this component.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Booking_Create_Transaction {
    /**
     * Result is success, failed or unknown. An unknown COMMIT acknowledgement
     * is not proof of a rollback and MUST NOT invite an automatic retry.
     */
    public static function create( $db, $company_id, array $initial_row, array $params, $intake_mode ) {
        if ( ! is_object( $db ) || ! method_exists( $db, 'query' ) || ! method_exists( $db, 'insert' ) ||
             ! isset( $db->prefix ) || ! is_string( $db->prefix ) ||
             ! is_int( $company_id ) || $company_id < 1 ||
             ! class_exists( 'TZFD_Tables' ) || ! class_exists( 'TZFD_Final_Booking_Write' ) ||
             ! class_exists( 'TZFD_Transaction_Storage' ) ) {
            return array( 'status' => 'failed', 'id' => 0 );
        }
        $table = $db->prefix . TZFD_Tables::BOOKINGS;
        if ( ! TZFD_Transaction_Storage::require_innodb( array( $table ), $db ) ) {
            return array( 'status' => 'failed', 'id' => 0 );
        }
        $started = false;
        $commit_attempted = false;
        try {
            $started = false !== $db->query( 'START TRANSACTION' ) && empty( $db->last_error );
            if ( ! $started ) { return array( 'status' => 'failed', 'id' => 0 ); }
            $initial_row['company_id'] = $company_id;
            if ( false === $db->insert( $table, $initial_row ) || empty( $db->insert_id ) || ! empty( $db->last_error ) ) {
                return array( 'status' => 'failed', 'id' => 0 );
            }
            $id = (int) $db->insert_id;
            if ( $id < 1 ) { return array( 'status' => 'failed', 'id' => 0 ); }
            $params['itemnumber'] = $id;
            if ( TZFD_Intake_Mode::MODE_REQUEST === $intake_mode ) {
                $params = TZFD_Intake_Mode::request_payload( $params );
            }
            if ( ! TZFD_Final_Booking_Write::persist( $db, $id, $company_id, serialize( $params ) ) ) {
                return array( 'status' => 'failed', 'id' => 0 );
            }
            // The acknowledgement can be lost even if the database committed.
            $commit_attempted = true;
            $committed = $db->query( 'COMMIT' );
            if ( false === $committed || ! empty( $db->last_error ) ) {
                return array( 'status' => 'unknown', 'id' => $id );
            }
            return array( 'status' => 'success', 'id' => $id, 'params' => $params );
        } catch ( Throwable $exception ) {
            return array( 'status' => $commit_attempted ? 'unknown' : 'failed', 'id' => 0 );
        } finally {
            if ( $started && ! $commit_attempted ) {
                try { $db->query( 'ROLLBACK' ); } catch ( Throwable $ignored ) { /* Preserve original failure. */ }
            }
        }
    }
}
