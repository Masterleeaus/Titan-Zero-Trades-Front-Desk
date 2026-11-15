<?php
/** Pass 91: verified acceptance of a waitlist offer. Only the booking owner commits reservations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Waitlist_Acceptance {
    /** An acceptance identifies one previously generated offer; never accepts client-supplied booking authority. */
    public static function candidate( $offer, $company, $recipient, $now ) {
        if ( ! is_array( $offer ) || ! is_int( $company ) || $company < 1 ||
             ! is_int( $recipient ) || $recipient < 1 || ! is_int( $now ) || $now < 0 ||
             ( $offer['company_id'] ?? null ) !== $company ||
             ( $offer['recipient_id'] ?? null ) !== $recipient ||
             ( $offer['action'] ?? null ) !== 'waitlist_offer' ||
             ! is_int( $offer['booking_id'] ?? null ) || $offer['booking_id'] < 1 ||
             ! is_int( $offer['slot_id'] ?? null ) || $offer['slot_id'] < 1 ||
             ! is_int( $offer['revision'] ?? null ) || $offer['revision'] < 1 ||
             ! is_int( $offer['expires_at_utc'] ?? null ) || $now >= $offer['expires_at_utc'] ||
             ! is_string( $offer['event'] ?? null ) ||
             ! preg_match( '/\Awaitlist-v1:[a-f0-9]{64}\z/D', $offer['event'] ) ) { return false; }
        return array(
            'company_id' => $company, 'booking_id' => $offer['booking_id'],
            'recipient_id' => $recipient, 'slot_id' => $offer['slot_id'],
            'revision' => $offer['revision'], 'offer_event' => $offer['event'],
            'expires_at_utc' => $offer['expires_at_utc'], 'accepted_at_utc' => $now,
            'request_id' => 'accept-v1:' . hash( 'sha256', $offer['event'] . '|' . $recipient ),
        );
    }
    public static function valid_receipt( $receipt, $candidate ) {
        if ( ! is_array( $receipt ) || ! is_array( $candidate ) ||
             true !== ( $receipt['ok'] ?? null ) || true !== ( $receipt['committed'] ?? null ) ||
             ! is_string( $receipt['commit_id'] ?? null ) ||
             ! preg_match( '/\A[A-Za-z0-9_.:-]{8,120}\z/D', $receipt['commit_id'] ) ||
             ! is_int( $receipt['committed_at_utc'] ?? null ) ||
             $receipt['committed_at_utc'] < ( $candidate['accepted_at_utc'] ?? PHP_INT_MAX ) ||
             $receipt['committed_at_utc'] >= ( $candidate['expires_at_utc'] ?? 0 ) ) { return false; }
        foreach ( array( 'company_id', 'booking_id', 'recipient_id', 'slot_id', 'revision', 'offer_event', 'request_id', 'expires_at_utc', 'accepted_at_utc' ) as $key ) {
            if ( ! array_key_exists( $key, $receipt ) || $receipt[$key] !== $candidate[$key] ) { return false; }
        }
        return true;
    }
    /**
     * This is a trusted server-side integration entrypoint, NOT a public response endpoint.
     * The owner must independently load the offer, check live slot availability/expiry,
     * atomically claim capacity before expires_at_utc and idempotently commit by request_id.
     * Owner receipt must attest committed_at_utc using its authoritative clock.
     */
    public static function accept_verified( $offer, $company, $recipient, $now ) {
        $candidate = self::candidate( $offer, $company, $recipient, $now );
        if ( false === $candidate ) { return false; }
        try {
            if ( true !== apply_filters( 'tzfd_waitlist_accept_verify_owner_v1', false, $candidate ) ||
                 true !== apply_filters( 'tzfd_waitlist_accept_verify_recipient_v1', false, $candidate ) ||
                 true !== apply_filters( 'tzfd_waitlist_accept_offer_current_v1', false, $candidate, $now ) ) { return false; }
            $receipt = apply_filters( 'tzfd_waitlist_accept_owner_commit_v1', null, $candidate );
        } catch ( Throwable $exception ) { return false; }
        if ( ! self::valid_receipt( $receipt, $candidate ) ) { return false; }
        return array( 'committed' => true, 'commit_id' => $receipt['commit_id'], 'request_id' => $candidate['request_id'] );
    }
}
