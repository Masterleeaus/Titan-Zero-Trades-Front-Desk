<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Validates booking status values submitted through the Titan Zero Trades: Front Desk admin UI.
 *
 * This remains independent of WordPress so the accepted status contract can be
 * tested without bootstrapping the plugin or a database.
 */
final class TZFD_Lifecycle_Rules {
    /**
     * Build the stored status values exposed by the current admin selector.
     *
     * The UI displays "Approved" but stores that state as an empty string.
     * Extensions may add statuses through the filter; core statuses remain
     * available even if a filter returns an incomplete list.
     *
     * @param mixed $configured_statuses Values returned by the plugin status list.
     * @return array
     */
    public static function allowed_admin_statuses( $configured_statuses ) {
        $allowed = array( '' );

        if ( is_array( $configured_statuses ) ) {
            foreach ( $configured_statuses as $status ) {
                if ( ! is_string( $status ) ) {
                    continue;
                }

                if ( 'Approved' === $status ) {
                    $status = '';
                }

                if ( ! in_array( $status, $allowed, true ) ) {
                    $allowed[] = $status;
                }
            }
        }

        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'titan_zero_front_desk_allowed_booking_statuses', $allowed );
            if ( is_array( $filtered ) ) {
                foreach ( $filtered as $status ) {
                    if ( is_string( $status ) && ! in_array( $status, $allowed, true ) ) {
                        $allowed[] = $status;
                    }
                }
            }
        }

        return array_values( $allowed );
    }

    /**
     * Check one request value against the UI's current stored status values.
     *
     * @param mixed $status Submitted status.
     * @param mixed $configured_statuses Values returned by the plugin status list.
     * @return bool
     */
    public static function is_allowed_admin_status( $status, $configured_statuses ) {
        if ( ! is_string( $status ) ) {
            return false;
        }

        return in_array( $status, self::allowed_admin_statuses( $configured_statuses ), true );
    }
}
