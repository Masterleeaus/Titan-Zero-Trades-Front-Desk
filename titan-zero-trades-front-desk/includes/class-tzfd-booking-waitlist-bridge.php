<?php
/** Pass 60: verified waitlist offer producer. Does not reserve a slot or confirm a booking. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Waitlist_Bridge {
    /** Slot facts must come from the authoritative server-side booking availability engine. */
    public static function offer( $waitlist, $slot, $recipient, $now ) {
        if ( ! is_array( $waitlist ) || ! is_array( $slot ) || ! is_int( $recipient ) || $recipient < 1 || ! is_int( $now ) || $now < 0 ) { return false; }
        $company = $waitlist['company_id'] ?? null;
        $request = $waitlist['booking_id'] ?? null;
        $slot_id = $slot['slot_id'] ?? null;
        $revision = $slot['revision'] ?? null;
        $expires = $slot['offer_expires_at_utc'] ?? null;
        if ( ! is_int( $company ) || $company < 1 || ! is_int( $request ) || $request < 1 ||
            ! is_int( $slot_id ) || $slot_id < 1 || ! is_int( $revision ) || $revision < 1 ||
            ! is_int( $expires ) || $expires <= $now || $expires - $now > 86400 ||
            ( $waitlist['verified_company_id'] ?? null ) !== $company ||
            ( $slot['verified_company_id'] ?? null ) !== $company ||
            ( $slot['company_id'] ?? null ) !== $company ||
            true !== ( $waitlist['authoritative'] ?? null ) || true !== ( $slot['authoritative'] ?? null ) ||
            ( $waitlist['status'] ?? null ) !== 'waiting' ||
            true !== ( $waitlist['contact_opt_in'] ?? null ) ||
            true !== ( $slot['available'] ?? null ) || true !== ( $slot['offer_approved'] ?? null ) ||
            ( $waitlist['recipient_id'] ?? null ) !== $recipient ) { return false; }
        // A stable event per offer version; retries do not create another notification.
        $identity = implode( '|', array( $company, $request, $slot_id, $revision, $expires, $recipient ) );
        return array(
            'company_id' => $company, 'booking_id' => $request,
            'recipient_id' => $recipient, 'action' => 'waitlist_offer',
            'event' => 'waitlist-v1:' . hash( 'sha256', $identity ),
            'slot_id' => $slot_id, 'revision' => $revision, 'expires_at_utc' => $expires,
            'booking_mutated' => false,
        );
    }
    /** Enqueue only after trusted availability and consent were independently established. */
    public static function queue_offer( $waitlist, $slot, $recipient, $now ) {
        $offer = self::offer( $waitlist, $slot, $recipient, $now );
        if ( false === $offer ) { return false; }
        // Outbox rechecks owner, recipient and action through trusted server-side policies.
        return TZFD_Booking_Action_Outbox::enqueue(
            $offer['company_id'], $offer['booking_id'], $offer['action'], $offer['recipient_id'], $offer['event']
        );
    }
}
