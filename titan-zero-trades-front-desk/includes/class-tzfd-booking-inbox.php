<?php
/**
 * Front Desk booking-only inbox decision engine.
 * Pure decisions: authenticated channel adapters own persistence, delivery,
 * booking commits, and customer identity verification.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Inbox {
    const VERSION = 1;
    const CHANNELS = array( 'telephone', 'web', 'sms', 'whatsapp', 'walk_in' );
    const INTENTS = array( 'new_booking', 'reschedule', 'cancel', 'confirm', 'callback' );
    const STATES = array( 'received', 'validated', 'awaiting_customer', 'awaiting_staff', 'booking_linked', 'closed' );

    private static function positive( $v ) { return is_int( $v ) && $v > 0; }
    private static function token( $v ) { return is_string( $v ) && strlen( $v ) > 0 && strlen( $v ) <= 128 && preg_match( '/\A[A-Za-z0-9_.:\-]+\z/D', $v ); }

    /** Accept a pre-authenticated, verified company-scoped booking-channel event. */
    public static function normalize( $company_id, $event, $verified = false ) {
        if ( true !== $verified || ! self::positive( $company_id ) || ! is_array( $event ) ||
            ! isset( $event['company_id'] ) || $event['company_id'] !== $company_id ||
            empty( $event['authoritative'] ) || true !== $event['authoritative'] ) {
            return array( 'ok' => false, 'code' => 'unverified_company_scope' );
        }
        $channel = isset( $event['channel'] ) ? $event['channel'] : null;
        $intent = isset( $event['intent'] ) ? $event['intent'] : null;
        $event_id = isset( $event['provider_event_id'] ) ? $event['provider_event_id'] : null;
        if ( ! in_array( $channel, self::CHANNELS, true ) || ! in_array( $intent, self::INTENTS, true ) || ! self::token( $event_id ) ) {
            return array( 'ok' => false, 'code' => 'invalid_booking_event' );
        }
        $customer_id = isset( $event['workspace_customer_id'] ) ? $event['workspace_customer_id'] : null;
        $booking_id = isset( $event['booking_id'] ) ? $event['booking_id'] : null;
        if ( ( null !== $customer_id && ! self::positive( $customer_id ) ) ||
             ( null !== $booking_id && ! self::positive( $booking_id ) ) ||
             ( null !== $booking_id && true !== ( isset( $event['booking_owner_verified'] ) ? $event['booking_owner_verified'] : false ) ) ) {
            return array( 'ok' => false, 'code' => 'unverified_owner_reference' );
        }
        $priority = 'normal';
        if ( 'callback' === $intent ) { $priority = 'callback'; }
        if ( 'cancel' === $intent || 'reschedule' === $intent ) { $priority = 'time_sensitive'; }
        $key = hash( 'sha256', implode( ':', array( $company_id, $channel, $event_id ) ) );
        return array( 'ok' => true, 'schema_version' => self::VERSION, 'company_id' => $company_id,
            'channel' => $channel, 'intent' => $intent, 'workspace_customer_id' => $customer_id,
            'booking_id' => $booking_id, 'priority' => $priority, 'state' => 'validated',
            'dedupe_key' => $key );
    }

    /** Merge a bounded complete, trusted inbox snapshot without inventing missing events. */
    public static function consolidate( $company_id, $items, $complete = false ) {
        if ( ! self::positive( $company_id ) || true !== $complete || ! is_array( $items ) || count( $items ) > 5000 ) {
            return array( 'ok' => false, 'code' => 'incomplete_or_invalid_snapshot' );
        }
        $unique = array(); $duplicates = 0;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || true !== ( isset( $item['ok'] ) ? $item['ok'] : false ) ||
                ! isset( $item['company_id'], $item['dedupe_key'], $item['priority'] ) ||
                $item['company_id'] !== $company_id || ! preg_match( '/\A[a-f0-9]{64}\z/D', $item['dedupe_key'] ) ||
                ! in_array( $item['priority'], array( 'normal', 'callback', 'time_sensitive' ), true ) ) {
                return array( 'ok' => false, 'code' => 'invalid_snapshot_item' );
            }
            if ( isset( $unique[$item['dedupe_key']] ) ) { $duplicates++; continue; }
            $unique[$item['dedupe_key']] = $item;
        }
        $rank = array( 'time_sensitive' => 0, 'callback' => 1, 'normal' => 2 );
        $result = array_values( $unique );
        usort( $result, static function ( $a, $b ) use ( $rank ) {
            $compare = $rank[$a['priority']] <=> $rank[$b['priority']];
            return 0 !== $compare ? $compare : strcmp( $a['dedupe_key'], $b['dedupe_key'] );
        } );
        return array( 'ok' => true, 'company_id' => $company_id, 'items' => $result,
            'unique_count' => count( $result ), 'duplicate_count' => $duplicates,
            'note' => 'read_only_snapshot_no_booking_commit' );
    }
}
