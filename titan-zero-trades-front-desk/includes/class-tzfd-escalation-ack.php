<?php
/** Pass 80: authenticated staff acknowledgements; never confuse SMS delivery with a human response. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Escalation_Ack {
    /** All state and receipts must originate from trusted server-side owners, not a public payload. */
    public static function acknowledge( $company, $call, $contact, $request_id ) {
        if ( ! is_int( $company ) || $company < 1 || ! is_int( $call ) || $call < 1 ||
             ! is_int( $contact ) || $contact < 1 || ! is_string( $request_id ) ||
             ! preg_match( '/^[A-Za-z0-9_.:-]{8,120}$/D', $request_id ) ) { return array( 'ok'=>false, 'reason'=>'invalid_request' ); }
        if ( true !== apply_filters( 'tzfd_escalation_verify_actor_v1', false, $company, $call, $contact, $request_id ) ) {
            return array( 'ok'=>false, 'reason'=>'actor_not_authorized' );
        }
        $state = apply_filters( 'tzfd_escalation_load_state_v1', null, $company, $call );
        if ( ! is_array( $state ) || ! isset( $state['company_id'], $state['call_id'] ) ||
             $state['company_id'] !== $company || $state['call_id'] !== $call ) { return array( 'ok'=>false, 'reason'=>'state_unavailable' ); }
        $transition = TZFD_Call_Routing::advance( $state, time(), $contact );
        if ( empty( $transition['ok'] ) || 'acknowledged' !== ( $transition['event'] ?? '' ) ) {
            return array( 'ok'=>false, 'reason'=>'not_current_recipient' );
        }
        // Owner performs compare-and-swap + idempotency in one transaction. A filter's
        // truthy value alone cannot establish that the state was actually committed.
        $receipt = apply_filters( 'tzfd_escalation_commit_ack_v1', null, $company, $call, $contact, $request_id, $state, $transition['state'] );
        if ( ! is_array( $receipt ) || true !== ( $receipt['committed'] ?? null ) ||
             ( $receipt['company_id'] ?? null ) !== $company || ( $receipt['call_id'] ?? null ) !== $call ||
             ( $receipt['contact_id'] ?? null ) !== $contact ||
             ! is_string( $receipt['request_id'] ?? null ) ||
             ! hash_equals( $request_id, $receipt['request_id'] ) ) {
            return array( 'ok'=>false, 'reason'=>'commit_not_verified' );
        }
        return array( 'ok'=>true, 'event'=>'acknowledged', 'call_id'=>$call, 'contact_id'=>$contact );
    }
}
