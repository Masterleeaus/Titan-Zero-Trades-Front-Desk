<?php
/** Authenticated, provider-neutral call lifecycle webhook adapter (Pass 40). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-missed-call-tasks.php';
final class TZFD_Telephony_Adapter {
    const WINDOW_SECONDS = 300;
    /**
     * A verified signature is remembered for twice the timestamp window. A timestamp may be up to
     * WINDOW_SECONDS in the future, so a request stays valid for up to 2 x WINDOW_SECONDS after it was signed.
     */
    const REPLAY_TTL = 600;
    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        // Upgrade path: an administrator page load installs a missing call lifecycle schema. Activation also runs it.
        add_action( 'admin_init', array( 'TZFD_Call_Lifecycle', 'maybe_install' ) );
        add_action( 'admin_notices', array( 'TZFD_Call_Lifecycle', 'render_failure' ) );
    }
    public static function routes() {
        register_rest_route( 'titan-zero-front-desk/v1', '/call-events', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'receive' ),
            // The signed raw body must verify (against a server-side credential) before the callback runs.
            'permission_callback' => array( __CLASS__, 'authorise' ),
        ) );
    }
    /** Permission step: fails closed when no credentials resolver is installed or the signature does not verify. */
    public static function authorise( $request ) {
        require_once __DIR__ . '/class-tzfd-rest-request-guard.php';
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', 16384, 25 ) ) {
            return new WP_Error( 'tzfd_telephony_bad_request', __( 'Invalid telephony request.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        $key_id = (string) $request->get_header( 'x-tzfd-key-id' );
        $creds = apply_filters( 'tzfd_telephony_provider_credentials_v1', null, $key_id );
        $event = self::validate( $request->get_body(), $key_id, (string) $request->get_header( 'x-tzfd-timestamp' ),
            (string) $request->get_header( 'x-tzfd-signature' ), $creds );
        return null === $event ? new WP_Error( 'tzfd_telephony_unauthorised', __( 'Invalid telephony request.', 'titan-zero-trades-front-desk' ), array( 'status' => 401 ) ) : true;
    }
    /** Authentication does not trust the request body's company or provider field. */
    public static function validate( $raw, $key_id, $timestamp, $signature, $credentials, $now = null ) {
        $now = null === $now ? time() : $now;
        if ( ! is_string( $raw ) || strlen( $raw ) < 2 || strlen( $raw ) > 16384 ||
             ! is_string( $key_id ) || ! preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $key_id) ||
             ! is_string( $timestamp ) || ! preg_match('/^[0-9]{10,12}$/D', $timestamp) ||
             abs( $now - (int) $timestamp ) > self::WINDOW_SECONDS ||
             ! is_string( $signature ) || ! preg_match('/^[a-f0-9]{64}$/D', $signature) ||
             ! is_array( $credentials ) || empty( $credentials['enabled'] ) ||
             ! isset( $credentials['company_id'], $credentials['provider'], $credentials['secret'] ) ||
             ! is_int( $credentials['company_id'] ) || $credentials['company_id'] < 1 ||
             ! is_string( $credentials['provider'] ) || ! preg_match('/^[a-z0-9_-]{1,64}$/D', $credentials['provider']) ||
             ! is_string( $credentials['secret'] ) || strlen( $credentials['secret'] ) < 32 ) {
            return null;
        }
        $expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, $credentials['secret'] );
        if ( ! hash_equals( $expected, $signature ) ) { return null; }
        $event = json_decode( $raw, true );
        if ( ! is_array( $event ) || ! isset( $event['call_id'], $event['event_id'], $event['event'] ) ||
             ! is_string( $event['call_id'] ) || ! is_string( $event['event_id'] ) || ! is_string( $event['event'] ) ||
             strlen( $event['call_id'] ) < 1 || strlen( $event['call_id'] ) > 255 ||
             strlen( $event['event_id'] ) < 1 || strlen( $event['event_id'] ) > 255 ||
             ! in_array( $event['event'], TZFD_Call_Lifecycle::EVENTS, true ) ) {
            return null;
        }
        // The caller number is optional and only used for missed-call callback tasks. Anything malformed is dropped.
        $from = isset( $event['from'] ) && is_string( $event['from'] ) && strlen( $event['from'] ) <= 40 ? $event['from'] : '';
        return array( 'company_id' => $credentials['company_id'], 'provider' => $credentials['provider'],
            'call_id' => $event['call_id'], 'event_id' => $event['event_id'], 'event' => $event['event'], 'from' => $from );
    }
    public static function receive( $request ) {
        require_once __DIR__ . '/class-tzfd-rest-request-guard.php';
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', 16384, 25 ) ) {
            return new WP_Error( 'tzfd_telephony_bad_request', __( 'Invalid telephony request.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        $key_id = (string) $request->get_header( 'x-tzfd-key-id' );
        // A receiving owner must install this server-side credentials resolver; absent => fail closed.
        $creds = apply_filters( 'tzfd_telephony_provider_credentials_v1', null, $key_id );
        $signature = (string) $request->get_header( 'x-tzfd-signature' );
        $event = self::validate( $request->get_body(), $key_id,
            (string) $request->get_header( 'x-tzfd-timestamp' ),
            $signature, $creds );
        if ( null === $event ) {
            return new WP_Error( 'tzfd_telephony_unauthorised', __( 'Invalid telephony request.', 'titan-zero-trades-front-desk' ), array( 'status' => 401 ) );
        }
        // Replay window: an identical signed request inside the window is answered without a second write.
        $replay_key = 'tzfd_tel_sig_' . hash( 'sha256', $signature );
        if ( get_transient( $replay_key ) ) {
            return new WP_REST_Response( array( 'ok' => true, 'replayed' => true ), 200 );
        }
        $result = TZFD_Call_Lifecycle::record_event( $event['company_id'], $event['provider'],
            $event['call_id'], $event['event_id'], $event['event'], true );
        if ( is_wp_error( $result ) ) { return $result; }
        // A missed call gets one callback task. It is idempotent per call, so a redelivery never adds a second task.
        // A failed task write returns 503 and skips the replay marker, so the provider's retry is processed.
        if ( 'missed' === $event['event'] ) {
            $task = TZFD_Missed_Call_Tasks::record( $event['company_id'], $event['provider'], $event['call_id'], $event['from'] );
            if ( is_wp_error( $task ) ) { return $task; }
        }
        set_transient( $replay_key, 1, self::REPLAY_TTL );
        return new WP_REST_Response( $result, 200 );
    }
}
