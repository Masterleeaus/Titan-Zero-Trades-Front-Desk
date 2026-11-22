<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Versioned completion-event bridge. Never counts attempts or intermediate processing. */
final class TZFD_Smart_Task_Events {
    public static function register() {
        add_action( 'tzfd_call_lifecycle_transition_v1', array( __CLASS__, 'on_call_transition' ), 20, 1 );
        add_action( 'tzfd_booking_rescheduled', array( __CLASS__, 'on_booking_rescheduled' ), 20, 3 );
    }
    /** The lifecycle source emits this only after a committed, authenticated transition. */
    public static function on_call_transition( $event ) {
        if ( ! is_array( $event ) || ( $event['state'] ?? null ) !== 'completed' ||
             ( $event['event'] ?? null ) !== 'completed' || ( $event['previous'] ?? null ) !== 'answered' ||
             ! is_int( $event['company_id'] ?? null ) || $event['company_id'] < 1 ||
             ! is_int( $event['call_id'] ?? null ) || $event['call_id'] < 1 ) { return false; }
        // Completion of an answered call is a call-handling outcome, not a claimed booking conversion.
        // Idempotent compatibility fallback: the lifecycle normally staged this
        // before commit. INSERT IGNORE ensures the post-commit hook cannot double count.
        return TZFD_Smart_Task_Outbox::enqueue( $event['company_id'], 'call.routing_completed',
            'call:completed:' . $event['call_id'], 0 );
    }
    /** Never trust unscoped legacy booking hooks. Resolve scope through a verified server-side owner. */
    public static function on_booking_rescheduled( $booking_id, $form_id, $context ) {
        if ( ! is_numeric( $booking_id ) || (int) $booking_id < 1 ||
             ! is_numeric( $form_id ) || (int) $form_id < 1 || ! is_array( $context ) ||
             empty( $context['date'] ) || empty( $context['slot'] ) ) { return false; }
        $scope = apply_filters( 'tzfd_smart_tasks_verified_booking_scope_v1', null, (int) $booking_id, (int) $form_id );
        if ( ! is_array( $scope ) || ( $scope['verified'] ?? false ) !== true ||
             ! is_int( $scope['company_id'] ?? null ) || $scope['company_id'] < 1 ||
             ( $scope['booking_id'] ?? null ) !== (int) $booking_id ||
             ( $scope['form_id'] ?? null ) !== (int) $form_id ||
             ! is_string( $scope['revision_id'] ?? null ) ||
             ! preg_match( '/^[a-zA-Z0-9_.:-]{8,120}$/D', $scope['revision_id'] ) ) { return false; }
        return TZFD_Smart_Task_Outbox::enqueue( $scope['company_id'], 'booking.rescheduled',
            'booking:rescheduled:' . $booking_id . ':' . $scope['revision_id'], 0 );
    }
}
