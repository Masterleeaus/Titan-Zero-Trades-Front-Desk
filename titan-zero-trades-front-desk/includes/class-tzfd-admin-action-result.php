<?php
/** Report booking-list mutation outcomes without claiming failed changes succeeded. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Admin_Action_Result {
    public static function bulk_status_message( $selected, $updated, $failed ) {
        $selected = max( 0, (int) $selected );
        $updated  = max( 0, (int) $updated );
        $failed   = max( 0, (int) $failed );
        if ( 0 === $selected ) {
            return __( 'No bookings selected; no status changes were made.', 'titan-zero-trades-front-desk' );
        }
        if ( $failed > 0 || $updated !== $selected ) {
            return sprintf(
                /* translators: 1: bookings changed, 2: bookings selected. */
                __( 'Updated %1$d of %2$d selected bookings. Some records were not changed.', 'titan-zero-trades-front-desk' ),
                $updated,
                $selected
            );
        }
        return __( 'Selected booking statuses updated.', 'titan-zero-trades-front-desk' );
    }
    public static function bulk_delete_message( $result ) {
        $selected = max( 0, (int) ( $result['selected'] ?? 0 ) );
        $deleted  = max( 0, (int) ( $result['deleted'] ?? 0 ) );
        $failed   = max( 0, (int) ( $result['failed'] ?? 0 ) );
        if ( $selected === 0 && $failed === 0 ) {
            return __( 'No bookings selected; nothing was deleted.', 'titan-zero-trades-front-desk' );
        }
        if ( $failed > 0 || $deleted !== $selected ) {
            return sprintf(
                /* translators: 1: booking deletions confirmed, 2: bookings selected. */
                __( 'Deleted %1$d of %2$d selected bookings. Some could not be deleted.', 'titan-zero-trades-front-desk' ),
                $deleted, $selected
            );
        }
        return __( 'Selected bookings deleted.', 'titan-zero-trades-front-desk' );
    }

    public static function single_delete_message( $result ) {
        return 1 === (int) ( $result['deleted'] ?? 0 ) && 0 === (int) ( $result['failed'] ?? 0 )
            ? __( 'Booking deleted.', 'titan-zero-trades-front-desk' )
            : __( 'No booking was deleted; verify business ownership and try again.', 'titan-zero-trades-front-desk' );
    }
}
