<?php
/** Deterministic, provider-neutral call routing and escalation decisions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Call_Routing {
    const CATEGORIES = array( 'emergency', 'new_booking', 'reschedule', 'routine', 'unknown' );
    const ACTIONS = array( 'escalate', 'booking', 'callback', 'staff_review' );

    /** Explicit company-bound rules; never use another company's defaults. */
    public static function route( $company_id, $category, $rules ) {
        if ( ! is_int( $company_id ) || $company_id < 1 || ! is_string( $category ) ||
             ! in_array( $category, self::CATEGORIES, true ) || ! is_array( $rules ) ||
             ! isset( $rules['company_id'] ) || (int) $rules['company_id'] !== $company_id ) {
            return array( 'ok' => false, 'reason' => 'invalid_scope_or_category' );
        }
        $allowed = array(
            'emergency' => 'escalate', 'new_booking' => 'booking',
            'reschedule' => 'booking', 'routine' => 'callback', 'unknown' => 'staff_review',
        );
        $action = isset( $rules['routes'][$category] ) ? $rules['routes'][$category] : $allowed[$category];
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            return array( 'ok' => false, 'reason' => 'invalid_action' );
        }
        // Safety-critical categories may never be silently downgraded to booking/callback.
        if ( 'emergency' === $category && 'escalate' !== $action ) {
            return array( 'ok' => false, 'reason' => 'emergency_must_escalate' );
        }
        return array( 'ok' => true, 'company_id' => $company_id, 'category' => $category, 'action' => $action );
    }

    /** Pure state initializer; contact IDs come from the company's approved staff directory. */
    public static function create_escalation( $company_id, $call_id, $contacts, $created_at, $interval_seconds = 120 ) {
        if ( ! is_int( $company_id ) || $company_id < 1 || ! is_int( $call_id ) || $call_id < 1 ||
             ! is_array( $contacts ) || ! count( $contacts ) || count( $contacts ) > 20 ||
             ! is_int( $created_at ) || $created_at < 1 || ! is_int( $interval_seconds ) ||
             $interval_seconds < 30 || $interval_seconds > 3600 ) {
            return array( 'ok' => false, 'reason' => 'invalid_escalation' );
        }
        $seen = array();
        foreach ( $contacts as $contact ) {
            if ( ! is_int( $contact ) || $contact < 1 || isset( $seen[$contact] ) ) {
                return array( 'ok' => false, 'reason' => 'invalid_contact' );
            }
            $seen[$contact] = true;
        }
        return array( 'ok' => true, 'company_id' => $company_id, 'call_id' => $call_id,
            'contacts' => array_values( $contacts ), 'index' => 0, 'status' => 'pending',
            'created_at' => $created_at, 'due_at' => $created_at,
            'interval_seconds' => $interval_seconds, 'acknowledged_by' => null );
    }

    /** The caller persists returned state atomically before performing any external delivery. */
    public static function advance( $state, $now, $ack_contact_id = null ) {
        if ( ! self::valid_state( $state ) || ! is_int( $now ) || $now < 1 ) {
            return array( 'ok' => false, 'reason' => 'invalid_state' );
        }
        if ( 'acknowledged' === $state['status'] || 'exhausted' === $state['status'] ) {
            return array( 'ok' => true, 'state' => $state, 'event' => 'none' );
        }
        if ( null !== $ack_contact_id ) {
            // Only the currently contacted recipient may acknowledge an awaiting response.
            if ( 'awaiting_ack' !== $state['status'] || $state['contacts'][$state['index']] !== $ack_contact_id ||
                 $now > $state['due_at'] ) {
                return array( 'ok' => false, 'reason' => 'unauthorised_ack' );
            }
            $state['status'] = 'acknowledged';
            $state['acknowledged_by'] = $ack_contact_id;
            return array( 'ok' => true, 'state' => $state, 'event' => 'acknowledged' );
        }
        if ( $now < $state['due_at'] ) {
            return array( 'ok' => true, 'state' => $state, 'event' => 'none' );
        }
        if ( 'awaiting_ack' === $state['status'] ) {
            ++$state['index'];
            if ( $state['index'] >= count( $state['contacts'] ) ) {
                $state['status'] = 'exhausted';
                return array( 'ok' => true, 'state' => $state, 'event' => 'escalation_exhausted' );
            }
        }
        $state['status'] = 'awaiting_ack';
        $state['due_at'] = $now + $state['interval_seconds'];
        return array( 'ok' => true, 'state' => $state, 'event' => 'contact_requested',
            'contact_id' => $state['contacts'][$state['index']] );
    }

    private static function valid_state( $state ) {
        if ( ! is_array( $state ) || empty( $state['ok'] ) || ! isset( $state['company_id'], $state['call_id'], $state['contacts'],
             $state['index'], $state['status'], $state['due_at'], $state['interval_seconds'] ) ||
             ! is_int( $state['company_id'] ) || $state['company_id'] < 1 ||
             ! is_int( $state['call_id'] ) || $state['call_id'] < 1 ||
             ! is_array( $state['contacts'] ) || ! count( $state['contacts'] ) ||
             ! is_int( $state['index'] ) || $state['index'] < 0 ||
             ! in_array( $state['status'], array( 'pending', 'awaiting_ack', 'acknowledged', 'exhausted' ), true ) ||
             ( 'exhausted' !== $state['status'] && $state['index'] >= count( $state['contacts'] ) ) ||
             ! is_int( $state['due_at'] ) || ! is_int( $state['interval_seconds'] ) ) { return false; }
        return true;
    }
}
