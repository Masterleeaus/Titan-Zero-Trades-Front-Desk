<?php
/** Pass 70: authoritative booking commit handoff. No direct booking or customer writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Commit_Handoff {
    const OPERATIONS = array( 'reschedule', 'cancel', 'confirm' );
    /** A verified inbox event remains a REQUEST until the owner confirms the state transition. */
    public static function candidate( $item, $company_id, $customer_id ) {
        $request = TZFD_Booking_Inbox_Dispatch_Bridge::candidate( $item, $company_id, $customer_id );
        if ( false === $request || ! in_array( $item['intent'], self::OPERATIONS, true ) ) { return false; }
        return array(
            'company_id' => $company_id,
            'booking_id' => $request['booking_id'],
            'customer_id' => $customer_id,
            'operation' => $item['intent'],
            'request_id' => $request['event'],
        );
    }
    public static function valid_receipt( $receipt, $candidate ) {
        if ( ! is_array( $candidate ) || ! is_array( $receipt ) || true !== ( $receipt['ok'] ?? null ) ||
             true !== ( $receipt['committed'] ?? null ) ||
             ( $receipt['company_id'] ?? null ) !== $candidate['company_id'] ||
             ( $receipt['booking_id'] ?? null ) !== $candidate['booking_id'] ||
             ( $receipt['customer_id'] ?? null ) !== $candidate['customer_id'] ||
             ( $receipt['operation'] ?? null ) !== $candidate['operation'] ||
             ( $receipt['request_id'] ?? null ) !== $candidate['request_id'] ||
             ! is_string( $receipt['commit_id'] ?? null ) ||
             ! preg_match( '/\A[A-Za-z0-9_.:-]{8,120}\z/D', $receipt['commit_id'] ) ) { return false; }
        return true;
    }
    /**
     * Trusted server-side boundary only. The receiving booking owner must atomically
     * deduplicate request_id and commit the transition before returning its receipt.
     * Never infer a booking change from an SMS, inbox or queue delivery receipt.
     */
    public static function commit_verified( $item, $company_id, $customer_id ) {
        $candidate = self::candidate( $item, $company_id, $customer_id );
        if ( false === $candidate ) { return false; }
        if ( true !== apply_filters( 'tzfd_booking_verify_owner_v1', false, $company_id, $candidate['booking_id'] ) ||
             true !== apply_filters( 'tzfd_booking_verify_recipient_v1', false, $company_id, $customer_id, 'inbox_dispatch' ) ||
             true !== apply_filters( 'tzfd_booking_authorise_commit_v1', false, $candidate ) ) { return false; }
        try {
            $receipt = apply_filters( 'tzfd_booking_owner_commit_v1', null, $candidate );
        } catch ( Throwable $exception ) {
            return false;
        }
        if ( ! self::valid_receipt( $receipt, $candidate ) ) { return false; }
        return array( 'committed' => true, 'commit_id' => $receipt['commit_id'], 'request_id' => $candidate['request_id'] );
    }
}
