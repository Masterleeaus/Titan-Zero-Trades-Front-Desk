<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-stored-data.php';


/**
 * Read-only, administrator-authenticated status lookup for one booking.
 * The response intentionally excludes submitted customer and appointment data.
 */
class TZFD_Booking_Status_API {
    const REST_NAMESPACE = 'titan-zero-front-desk/v1';
    const MAX_STATUS_BYTES = 250;

    public static function register() {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        if ( ! function_exists( 'register_rest_route' ) ) { return; }

        register_rest_route( self::REST_NAMESPACE, '/bookings/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'read_booking' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => array( __CLASS__, 'validate_booking_id' ),
                ),
            ),
        ) );
    }

    /** Only authenticated site administrators can query booking state. */
    public static function can_read( $request ) {
        return function_exists( 'current_user_can' ) && current_user_can( 'tz_fd_access' ) &&
            class_exists( 'TZFD_Scoped_Store' ) && TZFD_Scoped_Store::company_id() > 0;
    }

    public static function validate_booking_id( $tz_value ) {
        return self::normalize_booking_id( $tz_value ) > 0;
    }

    /** Return a minimized read of the state currently stored for one booking. */
    public static function read_booking( $request ) {
        require_once __DIR__ . '/class-tzfd-rest-request-guard.php';
        if ( ! TZFD_REST_Request_Guard::check( $request, 'GET', 1024, 12 ) ) {
            return self::error( 'titan_zero_front_desk_invalid_request', __( 'Invalid booking request.', 'titan-zero-trades-front-desk' ), 400 );
        }
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ||
            ! method_exists( $request, 'get_method' ) || 'GET' !== $request->get_method() ) {
            return self::error( 'titan_zero_front_desk_invalid_request', __( 'Invalid booking request.', 'titan-zero-trades-front-desk' ), 400 );
        }
        if ( ! self::can_read( $request ) ) {
            return self::error( 'titan_zero_front_desk_forbidden', __( 'You are not allowed to read booking status.', 'titan-zero-trades-front-desk' ), 403 );
        }

        $booking_id = self::normalize_booking_id( $request->get_param( 'id' ) );
        if ( $booking_id < 1 ) {
            return self::error( 'titan_zero_front_desk_invalid_request', __( 'Invalid booking request.', 'titan-zero-trades-front-desk' ), 400 );
        }

        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) || empty( $wpdb->prefix ) ) {
            return self::error( 'titan_zero_front_desk_unavailable', __( 'Booking data is unavailable.', 'titan-zero-trades-front-desk' ), 503 );
        }

        $tz_table_name = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $tz_table_name = esc_sql( $tz_table_name );
        // Company isolation: a booking outside the request's company reads as not found.
        $company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
        if ( $company < 1 ) {
            return self::error( 'titan_zero_front_desk_booking_not_found', __( 'Booking not found.', 'titan-zero-trades-front-desk' ), 404 );
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id, formid, time, posted_data FROM %i WHERE id = %d AND company_id = %d LIMIT 1", $tz_table_name, $booking_id, $company)
        );
        if ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) {
            return self::error( 'titan_zero_front_desk_unavailable', __( 'Booking data is unavailable.', 'titan-zero-trades-front-desk' ), 503 );
        }
        if ( ! is_object( $row ) ) {
            return self::error( 'titan_zero_front_desk_booking_not_found', __( 'Booking not found.', 'titan-zero-trades-front-desk' ), 404 );
        }

        $stored_id = self::normalize_booking_id( isset( $row->id ) ? $row->id : null );
        $form_id = self::normalize_booking_id( isset( $row->formid ) ? $row->formid : null );
        if ( $stored_id !== $booking_id || $form_id < 1 || ! isset( $row->time ) ||
            ! is_string( $row->time ) || strlen( $row->time ) > 64 ||
            preg_match( '/[\x00-\x1F\x7F]/', $row->time ) ) {
            return self::error( 'titan_zero_front_desk_invalid_record', __( 'Booking status is unavailable.', 'titan-zero-trades-front-desk' ), 500 );
        }

        $statuses = self::decode_statuses( isset( $row->posted_data ) ? $row->posted_data : null );
        if ( null === $statuses ) {
            return self::error( 'titan_zero_front_desk_invalid_record', __( 'Booking status is unavailable.', 'titan-zero-trades-front-desk' ), 500 );
        }

        $response = rest_ensure_response( array(
            'schema_version' => 1,
            'booking_id' => $stored_id,
            'form_id' => $form_id,
            'created_at' => $row->time,
            'appointment_statuses' => $statuses,
        ) );
        if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
            $response->header( 'Cache-Control', 'private, no-store' );
            $response->header( 'X-Content-Type-Options', 'nosniff' );
        }
        return $response;
    }

    /** Decode the legacy serialized row without restoring stored PHP objects. */
    private static function decode_statuses( $serialized ) {
        if ( ! is_string( $serialized ) || '' === $serialized ) { return null; }

        // The legacy column stores a serialized array. Restrict object restoration
        // even though only the scalar status fields below are exposed.
        $payload = TZFD_Stored_Data::project( $serialized, array( 'apps' ) );
        if ( ! is_array( $payload ) || ! isset( $payload['apps'] ) || ! is_array( $payload['apps'] ) ) { return null; }

        // Bound response cardinality independently of the generic serialized
        // value decoder: corrupt historical records should not overwhelm REST.
        if ( count( $payload['apps'] ) > 1000 ) { return null; }
        $statuses = array();
        foreach ( $payload['apps'] as $index => $appointment ) {
            if ( ! is_int( $index ) || $index < 0 || ! is_array( $appointment ) ) { return null; }
            $status = array_key_exists( 'cancelled', $appointment ) ? $appointment['cancelled'] : '';
            if ( ! is_string( $status ) || strlen( $status ) > self::MAX_STATUS_BYTES ) { return null; }
            if ( 1 !== preg_match( '//u', $status ) || preg_match( '/[\x00-\x1F\x7F]/', $status ) ) { return null; }

            $statuses[] = array( 'index' => $index, 'status' => $status );
        }

        return $statuses;
    }

    private static function normalize_booking_id( $tz_value ) {
        if ( is_int( $tz_value ) ) { return $tz_value > 0 ? $tz_value : 0; }
        if ( ! is_string( $tz_value ) || 1 !== preg_match( '/^[0-9]+$/D', $tz_value ) ) { return 0; }

        $digits = ltrim( $tz_value, '0' );
        if ( '' === $digits ) { return 0; }
        $validated = filter_var( $digits, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $validated ? 0 : (int) $validated;
    }

    private static function error( $code, $tz_message, $status ) {
        return new WP_Error( $code, $tz_message, array( 'status' => $status ) );
    }
}

TZFD_Booking_Status_API::register();
