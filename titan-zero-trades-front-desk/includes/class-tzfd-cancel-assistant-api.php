<?php
/**
 * Runtime entry point for the cancel/reschedule assistant (issues #25, #270, #336).
 *
 * POST titan-zero-front-desk/v1/cancel-assistant with the customer's message, booking_id, email,
 * expires and token (the signed link from the booking email). It classifies the message, checks the
 * signed link against the stored booking version, and returns only the next step and fixed policy text.
 * It never changes a booking, never sends mail and never echoes booking or customer data. A phone
 * number is not an input, so it cannot authorise a change.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-tables.php';
require_once __DIR__ . '/class-tzfd-customer-cancellation.php';
require_once __DIR__ . '/class-tzfd-cancel-reschedule-assistant.php';
require_once __DIR__ . '/class-tzfd-rest-request-guard.php';

final class TZFD_Cancel_Assistant_API {
    const REST_NAMESPACE = 'titan-zero-front-desk/v1';
    const ROUTE          = '/cancel-assistant';
    const POLICY_FILTER  = 'tzfd_cancel_policy_hours';
    const MAX_BYTES      = 2048;

    public static function register() {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        if ( ! function_exists( 'register_rest_route' ) ) { return; }
        register_rest_route( self::REST_NAMESPACE, self::ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle' ),
            'permission_callback' => array( __CLASS__, 'authorise' ),
        ) );
    }

    /** Public route: the request must be a bounded POST. Ownership is proved by the signed link in handle(). */
    public static function authorise( $request ) {
        return TZFD_REST_Request_Guard::check( $request, 'POST', self::MAX_BYTES, 12 );
    }

    /**
     * Decide the next step for one message. Returns the decision and fixed text only.
     *
     * @param WP_REST_Request $request Request with message, booking_id, email, expires, token.
     * @return array
     */
    public static function handle( $request ) {
        $params     = $request->get_params();
        $message    = isset( $params['message'] ) && is_string( $params['message'] ) ? $params['message'] : '';
        $booking_id = TZFD_Customer_Cancellation::normalize_booking_id( isset( $params['booking_id'] ) ? $params['booking_id'] : 0 );
        $email      = isset( $params['email'] ) && is_string( $params['email'] ) ? $params['email'] : '';
        $token      = isset( $params['token'] ) && is_string( $params['token'] ) ? $params['token'] : '';
        $expires    = TZFD_Customer_Cancellation::normalize_expiry( isset( $params['expires'] ) ? $params['expires'] : 0 );

        $intent = TZFD_Cancel_Reschedule_Assistant::classify( $message );
        $facts  = array(
            'booking_found'      => false,
            'booking_company_id' => 0,
            'request_company_id' => 0,
            'ownership_verified' => false,
        );

        if ( $booking_id ) {
            global $wpdb;
            $lookup = TZFD_Customer_Cancellation::public_booking_lookup( $wpdb, $booking_id );
            $row    = 'found' === $lookup['status'] ? $lookup['row'] : null;
            if ( is_object( $row ) ) {
                $owner = TZFD_Customer_Cancellation::public_form_ownership( $row );
                $company = ( isset( $owner['status'] ) && 'matched' === $owner['status'] ) ? (int) $owner['company'] : 0;
                $facts['booking_found']      = true;
                $facts['booking_company_id'] = $company;
                // The public route has no workspace session, so the request is scoped to the booking's own owner.
                $facts['request_company_id'] = $company;
                $facts['ownership_verified'] = TZFD_Cancel_Reschedule_Assistant::ownership_verified( array(
                    'booking_id' => $booking_id,
                    'email'      => $email,
                    'token'      => $token,
                    'secret'     => wp_salt( 'auth' ),
                    'version'    => TZFD_Customer_Cancellation::row_version( $row ),
                    'expires'    => $expires,
                ) );
                // A link is bound to the email on the booking. Any other email fails closed.
                if ( TZFD_Customer_Cancellation::normalize_email( $email ) !== TZFD_Customer_Cancellation::normalize_email( isset( $row->notifyto ) ? $row->notifyto : '' ) ) {
                    $facts['ownership_verified'] = false;
                }
            }
        }

        $action = TZFD_Cancel_Reschedule_Assistant::decide( $intent, $facts );

        return array(
            'intent'  => $intent,
            'action'  => $action,
            'message' => self::reply_text( $action ),
            'policy'  => self::policy_text(),
        );
    }

    /** Fixed reply per action. Never includes booking or customer data. */
    public static function reply_text( $action ) {
        switch ( $action ) {
            case TZFD_Cancel_Reschedule_Assistant::ACTION_LINK_CANCEL:
                return 'Your link is valid. Open the cancellation page from your booking email to confirm the cancellation.';
            case TZFD_Cancel_Reschedule_Assistant::ACTION_LINK_RESCHEDULE:
                return 'Your link is valid. Open the reschedule page from your booking email to choose a new time.';
            case TZFD_Cancel_Reschedule_Assistant::ACTION_VERIFY:
                return 'To change this booking, open the cancel or reschedule link from your booking email. The assistant cannot change a booking from chat.';
            case TZFD_Cancel_Reschedule_Assistant::ACTION_HANDOFF:
                return 'I cannot make that change from chat. Please contact the business directly, and staff will help.';
            default:
                return 'We could not find a booking for that link. Check the link in your booking email or contact the business.';
        }
    }

    /** Policy text from numbers the owner sets through the filter. Falls back to "contact us" when unset. */
    public static function policy_text() {
        $hours = apply_filters( self::POLICY_FILTER, array() );
        if ( ! is_array( $hours ) ) { $hours = array(); }
        return TZFD_Cancel_Reschedule_Assistant::policy_summary( $hours );
    }
}
