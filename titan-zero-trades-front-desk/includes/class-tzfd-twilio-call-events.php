<?php
/**
 * Signed Twilio voice call-status ingestion for missed-call callback tasks (issue #357).
 *
 * Owner decision (#357): the approved missed-call provider is Twilio, and a missed-call event MAY create a
 * callback task automatically. Twilio posts a call status callback signed with X-Twilio-Signature. A call that
 * ended with no-answer or busy is recorded as a missed call and creates one callback task for the active
 * business. Nothing else happens: no SMS is sent, no provider API is called and no customer is contacted.
 *
 * The route is off until the administrator defines TZFD_TWILIO_CALL_STATUS_CALLBACK_URL (the exact public URL
 * of this route) together with the existing Twilio transport constants.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Twilio_Call_Events {
    const ROUTE         = 'tzfd/v1/twilio-call-status';
    const PROVIDER      = 'twilio';
    const MAX_BYTES     = 4096;
    const MAX_FIELDS    = 40;
    const MAX_FROM      = 40;
    const MISSED        = array( 'no-answer', 'busy' );
    const NOT_MISSED    = array( 'queued', 'ringing', 'in-progress', 'completed', 'failed', 'canceled' );

    public static function boot() { add_action( 'rest_api_init', array( __CLASS__, 'register' ) ); }

    public static function register() {
        register_rest_route( 'tzfd/v1', '/twilio-call-status', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'receive' ),
            // Public by necessity (Twilio calls it). The signature must verify before the callback runs.
            'permission_callback' => array( __CLASS__, 'authorise' ),
        ) );
    }

    /** Permission step: a request without a valid Twilio signature never reaches receive(). Fails closed. */
    public static function authorise( $request ) {
        $checked = self::verify( $request );
        return is_wp_error( $checked ) ? $checked : true;
    }

    /**
     * Shared transport, payload and signature checks. Returns the event array for a verified request, or a
     * WP_Error. Used by both the permission step and receive(), so a request is verified the same way twice.
     */
    private static function verify( $request ) {
        require_once __DIR__ . '/class-tzfd-rest-request-guard.php';
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', self::MAX_BYTES, self::MAX_FIELDS, 'application/x-www-form-urlencoded' ) ) {
            return new WP_Error( 'tzfd_twilio_call_invalid', __( 'Invalid call status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        $raw_body = $request->get_body();
        if ( ! is_string( $raw_body ) || strlen( $raw_body ) > self::MAX_BYTES ) {
            return new WP_Error( 'tzfd_twilio_call_invalid', __( 'Invalid call status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 413 ) );
        }
        $url       = self::configured_url();
        $params    = $request->get_body_params();
        $signature = $request->get_header( 'x-twilio-signature' );
        $event     = self::event_for( $params );
        if ( ! $url || null === $event || ! TZFD_Twilio_Status::verified_signature( $url, $params, $signature ) ) {
            return new WP_Error( 'tzfd_twilio_not_authorized', __( 'Invalid call status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        return $event;
    }

    public static function receive( $request ) {
        $event = self::verify( $request );
        if ( is_wp_error( $event ) ) { return $event; }
        if ( 'missed' !== $event['event'] ) {
            return array( 'ok' => true, 'state' => 'ignored' );
        }
        // The business is the active one on this site. A verified request never chooses the company.
        $company_id = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
        if ( $company_id < 1 ) {
            return new WP_Error( 'tzfd_missed_task_tenant', __( 'This request is not for the active business.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        $signature = (string) $request->get_header( 'x-twilio-signature' );
        // Replay: the signature is claimed atomically before any write. A failed write releases it so the retry runs.
        require_once __DIR__ . '/class-tzfd-signed-request.php';
        if ( ! TZFD_Signed_Request::claim_signature( $signature ) ) {
            return array( 'ok' => true, 'state' => 'replayed' );
        }
        $recorded = TZFD_Call_Lifecycle::record_event( $company_id, self::PROVIDER, $event['call_id'], $event['event_id'], 'missed', true );
        if ( is_wp_error( $recorded ) ) {
            TZFD_Signed_Request::release_signature( $signature );
            return $recorded;
        }
        // One callback task per call. A redelivered call adds no second task.
        $task = TZFD_Missed_Call_Tasks::record( $company_id, self::PROVIDER, $event['call_id'], $event['from'] );
        if ( is_wp_error( $task ) ) {
            TZFD_Signed_Request::release_signature( $signature );
            return $task;
        }
        return array( 'ok' => true, 'state' => 'recorded', 'task' => $task['task'] );
    }

    /** The configured callback URL, or false. Only a full https URL to this exact route is accepted. */
    public static function configured_url() {
        if ( ! class_exists( 'TZFD_Twilio_SMS' ) || ! TZFD_Twilio_SMS::configured() ||
             ! defined( 'TZFD_TWILIO_CALL_STATUS_CALLBACK_URL' ) || ! is_string( TZFD_TWILIO_CALL_STATUS_CALLBACK_URL ) ) {
            return false;
        }
        return self::valid_callback_url( TZFD_TWILIO_CALL_STATUS_CALLBACK_URL );
    }

    /** Pure check: the URL must be https, end with this route and equal the site's own REST URL for it. */
    public static function valid_callback_url( $url ) {
        if ( ! is_string( $url ) || ! preg_match( '~^https://[^?#]+/wp-json/tzfd/v1/twilio-call-status$~D', $url ) ) {
            return false;
        }
        return hash_equals( $url, rest_url( self::ROUTE ) ) ? $url : false;
    }

    /** Pure check of the form fields Twilio sends for a voice status callback. */
    public static function valid_payload( $params ) {
        if ( ! is_array( $params ) || count( $params ) > self::MAX_FIELDS ||
             ! isset( $params['CallSid'], $params['CallStatus'], $params['AccountSid'] ) ) {
            return false;
        }
        foreach ( array( 'CallSid', 'CallStatus', 'AccountSid' ) as $key ) {
            if ( ! is_string( $params[ $key ] ) ) { return false; }
        }
        if ( ! preg_match( '/^CA[a-f0-9]{32}$/D', $params['CallSid'] ) ) { return false; }
        // Only calls from this site's own Twilio account count.
        if ( ! defined( 'TZFD_TWILIO_ACCOUNT_SID' ) || ! is_string( TZFD_TWILIO_ACCOUNT_SID ) ||
             ! hash_equals( TZFD_TWILIO_ACCOUNT_SID, $params['AccountSid'] ) ) {
            return false;
        }
        return in_array( $params['CallStatus'], array_merge( self::MISSED, self::NOT_MISSED ), true );
    }

    /**
     * Maps a valid payload to an event. Returns null when the payload is refused, or an array whose 'event' is
     * 'missed' or 'ignored'. The event id includes the status, so the no-answer and later completed callbacks for
     * one call stay distinct, while a repeated identical callback maps to the same id.
     */
    public static function event_for( $params ) {
        if ( ! self::valid_payload( $params ) ) { return null; }
        $status = $params['CallStatus'];
        $from   = isset( $params['From'] ) && is_string( $params['From'] ) && strlen( $params['From'] ) <= self::MAX_FROM ? $params['From'] : '';
        return array(
            'event'    => in_array( $status, self::MISSED, true ) ? 'missed' : 'ignored',
            'call_id'  => $params['CallSid'],
            'event_id' => $params['CallSid'] . ':' . $status,
            'from'     => $from,
        );
    }
}
