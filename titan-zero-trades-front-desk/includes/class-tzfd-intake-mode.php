<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Vertical-neutral form intake mode contract.
 *
 * Existing forms remain instant-confirm by default. Request-mode submissions
 * use the legacy Pending appointment status so existing capacity and lifecycle
 * code continues to treat them as occupied without presenting them as approved.
 */
final class TZFD_Intake_Mode {
    const MODE_INSTANT = 'instant';
    const MODE_REQUEST = 'request';

    public static function normalize( $tzfd_value ) {
        return self::MODE_REQUEST === $tzfd_value ? self::MODE_REQUEST : self::MODE_INSTANT;
    }

    public static function is_valid( $tzfd_value ) {
        return in_array( $tzfd_value, array( self::MODE_INSTANT, self::MODE_REQUEST ), true );
    }

    /** Internal trusted-PHP configuration API; no public write endpoint. */
    public static function set_for_form( $form_id, $mode ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'intake_forbidden', __( 'Administrator access required.', 'titan-zero-trades-front-desk' ) );
        }
        if ( ! is_int( $form_id ) || $form_id < 1 || ! self::is_valid( $mode ) ) {
            return new WP_Error( 'intake_invalid', __( 'A positive form ID and a supported intake mode are required.', 'titan-zero-trades-front-desk' ) );
        }
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'tzfd_forms WHERE id=%d', $form_id ) ) ) {
            return new WP_Error( 'intake_missing_form', __( 'Booking form not found.', 'titan-zero-trades-front-desk' ) );
        }
        $tzfd_key = 'titan_zero_front_desk_intake_mode_' . $form_id;
        update_option( $tzfd_key, $mode, false );
        if ( get_option( $tzfd_key ) !== $mode ) {
            return new WP_Error( 'intake_save_failed', __( 'The intake mode could not be saved.', 'titan-zero-trades-front-desk' ) );
        }
        return true;
    }

    /** A calendar's own mode, or the site default when it has never been set. */
    public static function get_for_form( $form_id ) {
        if ( ! is_int( $form_id ) || $form_id < 1 ) {
            return self::MODE_INSTANT;
        }
        $own = get_option( 'titan_zero_front_desk_intake_mode_' . $form_id, null );
        if ( null !== $own ) {
            return self::normalize( $own );
        }
        return self::default_mode();
    }

    /** Site-wide default for calendars with no mode of their own. Instant unless changed. */
    public static function default_mode() {
        return self::normalize( get_option( 'titan_zero_front_desk_intake_default', self::MODE_INSTANT ) );
    }

    /** Administrators only. Saves the site-wide default used by new and unset calendars. */
    public static function set_default( $mode ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'intake_forbidden', __( 'Administrator access required.', 'titan-zero-trades-front-desk' ) );
        }
        if ( ! self::is_valid( $mode ) ) {
            return new WP_Error( 'intake_invalid', __( 'A supported intake mode is required.', 'titan-zero-trades-front-desk' ) );
        }
        update_option( 'titan_zero_front_desk_intake_default', $mode, false );
        return true;
    }

    /** Apply the intake invariant again after legacy processing extensions run. */
    public static function request_payload( $tzfd_params ) {
        $tzfd_params['titan_zero_front_desk_intake_mode'] = self::MODE_REQUEST;
        $tzfd_params['apps'] = self::apply_to_appointments( $tzfd_params['apps'], self::MODE_REQUEST );
        foreach ( $tzfd_params['apps'] as $appointment ) {
            if ( is_array( $appointment ) && isset( $appointment['id'] ) ) {
                $tzfd_params['app_status_' . $appointment['id']] = 'Pending';
            }
        }
        return $tzfd_params;
    }

    public static function is_pending_request( $tzfd_params ) {
        if ( ! is_array( $tzfd_params ) || ! isset( $tzfd_params['titan_zero_front_desk_intake_mode'], $tzfd_params['apps'] ) ||
             self::MODE_REQUEST !== $tzfd_params['titan_zero_front_desk_intake_mode'] || ! is_array( $tzfd_params['apps'] ) ) {
            return false;
        }
        // Conditional/optional appointment fields may all be omitted in a valid intake.
        if ( empty( $tzfd_params['apps'] ) ) {
            return true;
        }
        foreach ( $tzfd_params['apps'] as $appointment ) {
            if ( isset( $appointment['cancelled'] ) && 'Pending' === $appointment['cancelled'] ) {
                return true;
            }
        }
        return false;
    }

    /** A completed but not wholly approved request must not reuse confirmation copy. */
    public static function settled_request_summary( $tzfd_params ) {
        if ( ! is_array( $tzfd_params ) || ! isset( $tzfd_params['titan_zero_front_desk_intake_mode'], $tzfd_params['apps'] ) ||
             self::MODE_REQUEST !== $tzfd_params['titan_zero_front_desk_intake_mode'] || ! is_array( $tzfd_params['apps'] ) ||
             self::is_pending_request( $tzfd_params ) ) {
            return null;
        }
        $lines = array();
        $all_approved = true;
        foreach ( $tzfd_params['apps'] as $appointment ) {
            $status = is_array( $appointment ) && isset( $appointment['cancelled'] ) && is_string( $appointment['cancelled'] )
                ? $appointment['cancelled'] : 'Unknown';
            if ( '' !== $status ) {
                $all_approved = false;
            }
            $lines[] = 'Appointment #' . ( count( $lines ) + 1 ) . ': ' . ( '' === $status ? 'Approved' : $status );
        }
        return $all_approved ? null : implode( "\n", $lines );
    }

    /** Preserve the normalized event schema and explicit non-status enrichment. */
    public static function request_event_payload( $payload ) {
        if ( is_array( $payload ) && isset( $payload['appointments'] ) && is_array( $payload['appointments'] ) ) {
            foreach ( $payload['appointments'] as $index => $appointment ) {
                if ( is_array( $appointment ) ) {
                    $payload['appointments'][$index]['status'] = 'Pending';
                }
            }
        }
        return $payload;
    }

    public static function acknowledgment() {
        return __( 'Your booking request has been received and is pending review. This is not a booking confirmation.', 'titan-zero-trades-front-desk' );
    }

    public static function apply_to_appointments( $appointments, $mode ) {
        if ( ! is_array( $appointments ) ) {
            return array();
        }
        $mode = self::normalize( $mode );
        if ( self::MODE_REQUEST !== $mode ) {
            return $appointments;
        }
        foreach ( $appointments as $index => $appointment ) {
            if ( ! is_array( $appointment ) ) {
                continue;
            }
            $appointments[ $index ]['cancelled'] = 'Pending';
        }
        return $appointments;
    }
}
