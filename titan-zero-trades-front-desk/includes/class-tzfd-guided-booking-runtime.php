<?php
/**
 * Read-only REST entry points for the guided booking step and the cancel/reschedule assistant (issue #336).
 *
 * Neither route writes, looks up a booking, or echoes the customer's text. Each returns fixed copy plus, for the
 * booking step, service IDs and names from the owner's service list. That list comes from the
 * `tzfd_guided_booking_services` filter. The default is empty, so the route shows the standard fallback and never
 * invents a service. Policy hours come from the `tzfd_cancel_policy` filter, with the same empty default.
 *
 * Pure helpers (guided_step, cancel_plan) carry the logic so unit tests can run without WordPress.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-guided-booking.php';
require_once __DIR__ . '/class-tzfd-cancel-reschedule-assistant.php';
require_once __DIR__ . '/class-tzfd-rest-request-guard.php';

final class TZFD_Guided_Booking_Runtime {
    const REST_NAMESPACE = 'tzfd/v1';
    const MAX_BYTES      = 2048;
    const MAX_FIELDS     = 5;

    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes() {
        if ( ! function_exists( 'register_rest_route' ) ) { return; }
        // Open to logged-out visitors, but not to anonymous callers: a valid REST nonce is required. The response
        // holds only owner-supplied service names and fixed copy.
        register_rest_route( self::REST_NAMESPACE, '/guided-booking/step', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_step' ),
            'permission_callback' => array( __CLASS__, 'nonce_permission' ),
        ) );
        register_rest_route( self::REST_NAMESPACE, '/cancel-assistant/plan', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_cancel_plan' ),
            'permission_callback' => array( __CLASS__, 'nonce_permission' ),
        ) );
    }

    /**
     * Permission check for both routes: the caller must send the X-WP-Nonce issued for the REST API. Logged-out
     * visitors receive that nonce too, so the public booking page can use the routes without a login.
     */
    public static function nonce_permission( $request ) {
        $nonce = is_object( $request ) && method_exists( $request, 'get_header' ) ? $request->get_header( 'x_wp_nonce' ) : '';
        return is_string( $nonce ) && '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * Services from the owner's list. An invalid list gives an empty result, which the caller turns into the fallback.
     *
     * @param mixed $raw Filter output.
     * @return array Normalised services.
     */
    public static function services_from_filter( $raw ) {
        try {
            return TZFD_Guided_Booking::normalise_services( $raw );
        } catch ( InvalidArgumentException $e ) {
            return array();
        }
    }

    /**
     * One guided-booking step. With no services configured, a booking request gets the fallback rather than an empty
     * "which service" prompt. A change request still gets the hand-off message.
     *
     * @param mixed      $text        Customer text.
     * @param mixed      $raw_services Owner's service list (filter output).
     * @param mixed|null $service_id  Service the customer picked, if any.
     * @return array Keys: step, service_ids, message.
     */
    public static function guided_step( $text, $raw_services, $service_id = null ) {
        $services = self::services_from_filter( $raw_services );
        $intent   = TZFD_Guided_Booking::detect_intent( $text );
        if ( array() === $services && TZFD_Guided_Booking::INTENT_CHANGE !== $intent ) {
            return array( 'step' => 'fallback', 'service_ids' => array(), 'message' => TZFD_Guided_Booking::FALLBACK_MESSAGE );
        }
        try {
            return TZFD_Guided_Booking::next_step( $text, $services, $service_id );
        } catch ( InvalidArgumentException $e ) {
            return array( 'step' => 'fallback', 'service_ids' => array(), 'message' => TZFD_Guided_Booking::FALLBACK_MESSAGE );
        }
    }

    /**
     * Cancel or reschedule guidance for one message. It never changes a booking.
     *
     * @param mixed $text       Customer text.
     * @param mixed $raw_policy Policy hours (filter output).
     * @return array Keys: intent, action, message, policy.
     */
    public static function cancel_plan( $text, $raw_policy ) {
        $policy = is_array( $raw_policy ) ? $raw_policy : array();
        return TZFD_Cancel_Reschedule_Assistant::plan_without_booking( $text, $policy );
    }

    /** REST callback for the guided booking step. */
    public static function rest_step( $request ) {
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', self::MAX_BYTES, self::MAX_FIELDS ) ) {
            return self::reply( array( 'step' => 'invalid_request' ), 400 );
        }
        $params     = $request->get_params();
        $text       = isset( $params['text'] ) ? $params['text'] : '';
        $service_id = isset( $params['service_id'] ) && '' !== $params['service_id'] ? $params['service_id'] : null;
        $services   = apply_filters( 'tzfd_guided_booking_services', array() );
        return self::reply( self::guided_step( $text, $services, $service_id ), 200 );
    }

    /** REST callback for the cancel/reschedule assistant. */
    public static function rest_cancel_plan( $request ) {
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', self::MAX_BYTES, self::MAX_FIELDS ) ) {
            return self::reply( array( 'action' => 'invalid_request' ), 400 );
        }
        $params = $request->get_params();
        $text   = isset( $params['text'] ) ? $params['text'] : '';
        $policy = apply_filters( 'tzfd_cancel_policy', array() );
        return self::reply( self::cancel_plan( $text, $policy ), 200 );
    }

    private static function reply( $data, $status ) {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'private, no-store' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }
}
