<?php
/** Pass 65: server-verified booking inbox event to durable action queue. Never commits a reservation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Inbox_Dispatch_Bridge {
    public static function candidate( $item, $verified_company, $verified_recipient ) {
        if ( ! is_array( $item ) || true !== ( $item['ok'] ?? null ) ||
             ! is_int( $verified_company ) || $verified_company < 1 ||
             ! is_int( $verified_recipient ) || $verified_recipient < 1 ||
             ( $item['company_id'] ?? null ) !== $verified_company ||
             ( $item['workspace_customer_id'] ?? null ) !== $verified_recipient ||
             ! is_int( $item['booking_id'] ?? null ) || $item['booking_id'] < 1 ||
             ! in_array( $item['intent'] ?? null, array( 'reschedule', 'cancel', 'confirm' ), true ) ||
             ! in_array( $item['channel'] ?? null, TZFD_Booking_Inbox::CHANNELS, true ) ||
             ! is_string( $item['dedupe_key'] ?? null ) ||
             ! preg_match( '/\A[a-f0-9]{64}\z/D', $item['dedupe_key'] ) ) { return false; }
        return array( 'company_id' => $verified_company, 'booking_id' => $item['booking_id'],
            'recipient_id' => $verified_recipient, 'action' => 'inbox_dispatch',
            'event' => 'inbox-v1:' . $item['dedupe_key'], 'booking_mutated' => false );
    }
    /** Additional owner, recipient consent and policy checks run in the action outbox. */
    public static function enqueue_verified( $item, $verified_company, $verified_recipient ) {
        $candidate = self::candidate( $item, $verified_company, $verified_recipient );
        if ( false === $candidate ) { return false; }
        return TZFD_Booking_Action_Outbox::enqueue( $candidate['company_id'], $candidate['booking_id'],
            $candidate['action'], $candidate['recipient_id'], $candidate['event'] );
    }
}
