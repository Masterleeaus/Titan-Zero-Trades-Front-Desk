<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Pass 64: signed Twilio SMS delivery-status ingestion; never treats this as a human acknowledgement. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Twilio_Status {
    const ROUTE = 'tzfd/v1/twilio-status';
    // Replays are detected by TZFD_Signed_Request::claim_signature (the primitive the signed missed-call and call-event
    // endpoints use). Twilio's X-Twilio-Signature carries no timestamp, so the claim is held for REPLAY_TTL (600 s).
    public static function boot() { add_action( 'rest_api_init', array( __CLASS__, 'register' ) ); }
    public static function register() {
        register_rest_route( 'tzfd/v1', '/twilio-status', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'receive' ),
            // Public by necessity (Twilio calls it), but the X-Twilio-Signature must verify before the callback runs.
            'permission_callback' => array( __CLASS__, 'authorise' ),
        ) );
    }
    /** Permission step: a request without a valid Twilio signature never reaches receive(). Fails closed. */
    public static function authorise( $request ) {
        require_once __DIR__ . '/class-tzfd-rest-request-guard.php';
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', 4096, 30, 'application/x-www-form-urlencoded' ) ) {
            return new WP_Error( 'tzfd_twilio_invalid_payload', __( 'Invalid status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        $url = self::configured_url();
        $raw_body = $request->get_body();
        if ( ! is_string( $raw_body ) || strlen( $raw_body ) > 4096 ) {
            return new WP_Error( 'tzfd_twilio_invalid_payload', __( 'Invalid status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 413 ) );
        }
        $params = $request->get_body_params();
        $signature = $request->get_header( 'x-twilio-signature' );
        if ( $url && self::valid_payload( $params ) && self::verified_signature( $url, $params, $signature ) ) { return true; }
        return new WP_Error( 'tzfd_twilio_not_authorized', __( 'Invalid status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
    }
    public static function configured_url() {
        if ( ! TZFD_Twilio_SMS::configured() || ! defined( 'TZFD_TWILIO_STATUS_CALLBACK_URL' ) ||
             ! is_string( TZFD_TWILIO_STATUS_CALLBACK_URL ) ) { return false; }
        $url = TZFD_TWILIO_STATUS_CALLBACK_URL;
        // Never trust arbitrary supplied hosts/URLs for HMAC verification.
        return preg_match( '~^https://[^?#]+/wp-json/tzfd/v1/twilio-status/?$~D', $url ) &&
            hash_equals( $url, rest_url( 'tzfd/v1/twilio-status' ) ) ? $url : false;
    }
    public static function verified_signature( $url, $params, $provided ) {
        if ( ! is_string( $url ) || ! is_array( $params ) || ! is_string( $provided ) ||
             ! preg_match( '~^[A-Za-z0-9+/]{27}=*$~D', $provided ) ) { return false; }
        $parts = $params;
        foreach ( $parts as $key => $value ) {
            if ( ! is_string( $key ) || ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $key ) ) { return false; }
        }
        ksort( $parts, SORT_STRING ); $input = $url;
        foreach ( $parts as $key => $value ) { $input .= $key . $value; }
        $expected = base64_encode( hash_hmac( 'sha1', $input, TZFD_TWILIO_AUTH_TOKEN, true ) );
        return hash_equals( $expected, $provided );
    }
    public static function valid_payload( $params ) {
        return is_array( $params ) && count( $params ) <= 30 && isset( $params['MessageSid'], $params['MessageStatus'] ) &&
            is_string( $params['MessageSid'] ) && preg_match( '/^SM[a-f0-9]{32}$/iD', $params['MessageSid'] ) &&
            is_string( $params['MessageStatus'] ) &&
            in_array( $params['MessageStatus'], array( 'delivered', 'undelivered', 'failed' ), true );
    }
    public static function receive( $request ) {
        require_once __DIR__ . '/class-tzfd-rest-request-guard.php';
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', 4096, 30, 'application/x-www-form-urlencoded' ) ) {
            return new WP_Error( 'tzfd_twilio_invalid_payload', __( 'Invalid status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        $raw_body = $request->get_body();
        if ( ! is_string( $raw_body ) || strlen( $raw_body ) > 4096 ) {
            return new WP_Error( 'tzfd_twilio_invalid_payload', __( 'Invalid status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 413 ) );
        }
        $url = self::configured_url();
        $params = $request->get_body_params();
        $signature = $request->get_header( 'x-twilio-signature' );
        if ( ! $url || ! self::valid_payload( $params ) || ! self::verified_signature( $url, $params, $signature ) ) {
            return new WP_Error( 'tzfd_twilio_not_authorized', __( 'Invalid status callback', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        // Replay check, atomic: the signature is claimed before any read or write, so of several identical requests that
        // arrive together only one proceeds and the rest are answered as replays. A request that fails after the claim
        // releases it, so the provider's retry is processed. Same primitive as the missed-call endpoint.
        require_once __DIR__ . '/class-tzfd-signed-request.php';
        if ( ! TZFD_Signed_Request::claim_signature( $signature ) ) { return array( 'ok' => true, 'state' => 'replayed' ); }
        $result = self::apply_status( $params );
        if ( is_wp_error( $result ) ) { TZFD_Signed_Request::release_signature( $signature ); }
        return $result;
    }

    /** Applies one verified, claimed status to its delivery row. Returns a WP_Error for any refusal. */
    private static function apply_status( $params ) {
        if ( ! class_exists( 'TZFD_Telephone_Delivery' ) || ! TZFD_Telephone_Delivery::installed() ) {
            return new WP_Error( 'tzfd_telephone_queue_missing', __( 'Delivery queue unavailable', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        global $wpdb;
        $table = TZFD_Telephone_Delivery::table();
        $sid = $params['MessageSid'];
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,company_id,state,receipt_id FROM %i WHERE receipt_id=%s LIMIT 1', $table, $sid ), ARRAY_A );
        if ( ! empty( $wpdb->last_error ) ) {
            return new WP_Error( 'tzfd_telephone_database_unavailable', __( 'Delivery status unavailable', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        if ( ! is_array( $row ) || ! isset( $row['id'], $row['company_id'], $row['state'] ) ||
            ! is_numeric( $row['id'] ) || (int) $row['id'] < 1 ||
            ! is_numeric( $row['company_id'] ) || (int) $row['company_id'] < 1 ||
            ! is_string( $row['state'] ) || ! isset( $row['receipt_id'] ) || ! is_string( $row['receipt_id'] ) || ! hash_equals( $row['receipt_id'], $sid ) ) {
            return new WP_Error( 'tzfd_telephone_unknown_receipt', __( 'Unknown delivery receipt', 'titan-zero-trades-front-desk' ), array( 'status' => 404 ) );
        }
        $status = $params['MessageStatus'];
        $target = 'delivered' === $status ? 'delivered' : 'failed';
        if ( $row['state'] === $target ) {
            return array( 'ok' => true, 'state' => $target );
        }
        // Do not regress delivered messages or override an unrelated state. A terminal
        // failure is recorded, not automatically resent (avoids ambiguous duplicate SMS).
        if ( 'accepted' !== $row['state'] ) {
            return new WP_Error( 'tzfd_telephone_invalid_transition', __( 'Invalid delivery transition', 'titan-zero-trades-front-desk' ), array( 'status' => 409 ) );
        }
        $updated = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET state=%s WHERE id=%d AND company_id=%d AND receipt_id=%s AND state=\'accepted\'',
            $table, $target, (int) $row['id'], (int) $row['company_id'], $sid
        ) );
        if ( 1 !== (int) $updated ) {
            return new WP_Error( 'tzfd_telephone_status_conflict', __( 'Unable to update delivery status', 'titan-zero-trades-front-desk' ), array( 'status' => 409 ) );
        }
        return array( 'ok' => true, 'state' => $target );
    }
}
