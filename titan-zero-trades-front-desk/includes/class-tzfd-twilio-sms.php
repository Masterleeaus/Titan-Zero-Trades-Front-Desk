<?php
/** Optional Twilio SMS transport for approved callback and escalation notifications. Disabled by default. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Twilio_SMS {
    public static function boot() {
        add_filter( 'tzfd_telephone_deliver_v1', array( __CLASS__, 'deliver' ), 10, 2 );
    }
    /** Only administrator-provisioned constants activate transport. Never read credentials from payloads. */
    public static function configured() {
        return defined( 'TZFD_TWILIO_SMS_ENABLED' ) && true === TZFD_TWILIO_SMS_ENABLED &&
            defined( 'TZFD_TWILIO_ACCOUNT_SID' ) && is_string( TZFD_TWILIO_ACCOUNT_SID ) &&
            (bool) preg_match( '/^AC[a-f0-9]{32}$/iD', TZFD_TWILIO_ACCOUNT_SID ) &&
            defined( 'TZFD_TWILIO_AUTH_TOKEN' ) && is_string( TZFD_TWILIO_AUTH_TOKEN ) &&
            (bool) preg_match( '/^[A-Za-z0-9]{32,64}$/D', TZFD_TWILIO_AUTH_TOKEN ) &&
            defined( 'TZFD_TWILIO_FROM' ) && is_string( TZFD_TWILIO_FROM ) &&
            (bool) preg_match( '/^\+[1-9][0-9]{7,14}$/D', TZFD_TWILIO_FROM );
    }
    public static function validate_target( $target, $company, $recipient, $action ) {
        return is_array( $target ) && isset( $target['company_id'], $target['recipient_id'], $target['action'], $target['to'], $target['consent'] ) &&
            is_int( $target['company_id'] ) && $target['company_id'] === $company &&
            is_int( $target['recipient_id'] ) && $target['recipient_id'] === $recipient &&
            $target['action'] === $action && true === $target['consent'] && is_string( $target['to'] ) &&
            (bool) preg_match( '/^\+[1-9][0-9]{7,14}$/D', $target['to'] );
    }
    public static function validate_provider_response( $response, $company, $action, $delivery_id, $expected_to = null, $expected_from = null, $expected_account = null ) {
        if ( is_wp_error( $response ) || ! is_array( $response ) || 201 !== (int) wp_remote_retrieve_response_code( $response ) ) { return null; }
        $body = wp_remote_retrieve_body( $response );
        if ( ! is_string( $body ) || strlen( $body ) > 16384 ) { return null; }
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || ! isset( $data['sid'], $data['status'] ) || ! is_string( $data['sid'] ) ||
            ! preg_match( '/^SM[a-f0-9]{32}$/iD', $data['sid'] ) ||
            ! in_array( $data['status'], array( 'queued', 'accepted', 'sending', 'sent' ), true ) ) { return null; }
        // Once a request has been sent, the provider must echo the account, sender
        // and recipient that the trusted server resolved. Do not acknowledge a
        // successful HTTP response for a message addressed to someone else.
        if ( null !== $expected_to || null !== $expected_from || null !== $expected_account ) {
            if ( ! is_string( $expected_to ) || ! is_string( $expected_from ) || ! is_string( $expected_account ) ||
                 ! isset( $data['to'], $data['from'], $data['account_sid'] ) ||
                 ! is_string( $data['to'] ) || ! is_string( $data['from'] ) || ! is_string( $data['account_sid'] ) ||
                 ! hash_equals( $expected_to, $data['to'] ) ||
                 ! hash_equals( $expected_from, $data['from'] ) ||
                 ! hash_equals( $expected_account, $data['account_sid'] ) ) { return null; }
        }
        return array( 'ok' => true, 'company_id' => $company, 'action' => $action,
            'delivery_id' => $delivery_id, 'receipt_id' => $data['sid'] );
    }
    public static function deliver( $existing, $request ) {
        // Respect another adapter's receipt, and fail closed unless explicitly enabled.
        if ( null !== $existing || ! self::configured() || ! is_array( $request ) ) { return $existing; }
        foreach ( array( 'company_id', 'call_id', 'recipient_id', 'action', 'delivery_id' ) as $key ) {
            if ( ! isset( $request[ $key ] ) ) { return null; }
        }
        $company = $request['company_id']; $call = $request['call_id']; $recipient = $request['recipient_id'];
        $action = $request['action']; $delivery_id = $request['delivery_id'];
        if ( ! is_int( $company ) || $company < 1 || ! is_int( $call ) || $call < 1 ||
             ! is_int( $recipient ) || $recipient < 1 || ! in_array( $action, TZFD_Telephone_Delivery::ACTIONS, true ) ||
             ! is_string( $delivery_id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $delivery_id ) ) { return null; }
        // Trusted owner must resolve recipient, opt-in and human-approved copy on the server.
        $target = apply_filters( 'tzfd_twilio_sms_resolve_target_v1', null, $company, $call, $recipient, $action );
        if ( ! self::validate_target( $target, $company, $recipient, $action ) ||
             ! isset( $target['body'] ) || ! is_string( $target['body'] ) ||
             strlen( $target['body'] ) < 1 || strlen( $target['body'] ) > 1200 ) { return null; }
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TZFD_TWILIO_ACCOUNT_SID . '/Messages.json';
        $message = array( 'From' => TZFD_TWILIO_FROM, 'To' => $target['to'], 'Body' => $target['body'] );
        if ( class_exists( 'TZFD_Twilio_Status' ) && TZFD_Twilio_Status::configured_url() ) {
            $message['StatusCallback'] = TZFD_Twilio_Status::configured_url();
        }
        $response = wp_remote_post( $url, array(
            'timeout' => 12, 'redirection' => 0, 'sslverify' => true,
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( TZFD_TWILIO_ACCOUNT_SID . ':' . TZFD_TWILIO_AUTH_TOKEN ) ),
            'body' => $message,
        ) );
        return self::validate_provider_response( $response, $company, $action, $delivery_id, $target['to'], TZFD_TWILIO_FROM, TZFD_TWILIO_ACCOUNT_SID );
    }
}
