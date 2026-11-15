<?php
/**
 * Connect -> Front Desk: opt-in, tenant-verified, idempotent enquiry handoff.
 * No guessed tenant-to-company mappings, customer identities or automatic sends.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Connect_Bridge {
    public static function register() {
        add_action( 'titan_zero_comms_customer_message_resolved', array( __CLASS__, 'receive' ), 10, 2 );
    }
    public static function receive( $event, $customer ) {
        if ( ! is_array( $event ) || ! is_array( $customer ) || ! class_exists( 'TZFD_Lead_Intake' ) ) { return; }
        $tenant = isset( $event['tenant_id'] ) && is_string( $event['tenant_id'] ) ? $event['tenant_id'] : '';
        $channel = isset( $event['channel'] ) && is_string( $event['channel'] ) ? sanitize_key( $event['channel'] ) : '';
        $message = isset( $event['message_id'] ) && is_scalar( $event['message_id'] ) ? (string) $event['message_id'] : '';
        $body = isset( $event['body'] ) && is_string( $event['body'] ) ? trim( $event['body'] ) : '';
        // A resolved customer must carry explicit provenance for this tenant.
        // Do not let an incorrectly configured resolver or approval filter bridge tenants.
        if ( ! isset( $customer['tenant_id'] ) || ! is_string( $customer['tenant_id'] ) || ! hash_equals( $tenant, $customer['tenant_id'] ) ) { return; }
        $customer_id = isset( $customer['customer_id'] ) ? absint( $customer['customer_id'] ) : 0;
        if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $tenant ) || ! in_array( $channel, array( 'sms', 'whatsapp', 'webchat' ), true ) || '' === $message || strlen( $message ) > 190 || ! $customer_id || '' === $body || strlen( $body ) > 65535 ) { return; }
        // A separate, trusted integration must explicitly authorize the mapping.
        // Fail closed if only a tenant string or unverified customer identifier exists.
        $company_id = absint( apply_filters( 'tzfd_connect_authorized_company', 0, $tenant, $customer_id, $event, $customer ) );
        if ( ! $company_id || true !== apply_filters( 'tzfd_connect_allow_enquiry', false, $company_id, $customer_id, $event ) ) { return; }
        // Atomic option claim prevents repeat delivery from creating duplicate leads.
        $key = 'tzfd_connect_' . hash( 'sha256', $tenant . "\0" . $channel . "\0" . $message );
        if ( ! add_option( $key, 'pending', '', false ) ) { return; }
        $payload = array(
            'company_id' => $company_id,
            'name' => isset( $customer['name'] ) && is_scalar( $customer['name'] ) ? (string) $customer['name'] : '',
            'email' => isset( $customer['email'] ) && is_scalar( $customer['email'] ) ? (string) $customer['email'] : '',
            'phone' => isset( $customer['phone'] ) && is_scalar( $customer['phone'] ) ? (string) $customer['phone'] : '',
            'enquiry' => $body,
            'source' => 'connect_' . $channel,
        );
        // Failure must release the claim so the same event can be retried safely.
        // Never expose message content or customer identifiers in error output.
        try {
            $id = TZFD_Lead_Intake::receive( $payload );
        } catch ( Throwable $e ) {
            delete_option( $key );
            do_action( 'tzfd_connect_enquiry_failed', $tenant, $channel );
            return;
        }
        if ( is_wp_error( $id ) || ! absint( $id ) ) { delete_option( $key ); return; }
        update_option( $key, (string) absint( $id ), false );
        do_action( 'tzfd_connect_enquiry_created', $id, $company_id, $customer_id, $event );
    }
}
