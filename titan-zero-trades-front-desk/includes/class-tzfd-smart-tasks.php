<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Front Desk completion reporter. Workspace is authoritative for all suite totals. */
final class TZFD_Smart_Tasks {
    const VERSION = 1;
    /**
     * Report one completed business outcome to Workspace. Never count intermediate
     * engine calls, proposals, retries, or AI invocations as separate Smart Tasks.
     * $task_key is stable across retries and unique within company and task type.
     */
    public static function report_completed( $company_id, $task_type, $task_key, $manual_seconds, $human_seconds = 0, $completed_at = '' ) {
        $company_id = filter_var( $company_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( false === $company_id || ! is_string( $task_type ) || ! preg_match( '/^[a-z][a-z0-9_.-]{2,63}$/', $task_type ) ||
             ! is_string( $task_key ) || ! preg_match( '/^[a-zA-Z0-9_.:-]{8,160}$/', $task_key ) ) {
            return array( 'ok' => false, 'error' => 'invalid_task_identity' );
        }
        $allowed = array( 'booking.confirmed', 'booking.rescheduled', 'booking.cancelled', 'booking.reminder_delivered',
            'call.callback_completed', 'call.recovery_delivered', 'call.routing_completed', 'booking.waitlist_filled' );
        if ( ! in_array( $task_type, $allowed, true ) ) { return array( 'ok' => false, 'error' => 'unsupported_task_type' ); }
        if ( ! is_numeric( $manual_seconds ) || ! is_numeric( $human_seconds ) ||
             ! is_finite( (float) $manual_seconds ) || ! is_finite( (float) $human_seconds ) ||
             (float) $manual_seconds < 0 || (float) $human_seconds < 0 || (float) $manual_seconds > 86400 || (float) $human_seconds > 86400 ) {
            return array( 'ok' => false, 'error' => 'invalid_time_estimate' );
        }
        // Owner must enforce uniqueness atomically; no local counter or success on absent owner.
        $occurred_at = '' === $completed_at ? gmdate( 'Y-m-d\TH:i:s\Z' ) : $completed_at;
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $occurred_at, new DateTimeZone( 'UTC' ) );
        if ( ! $date || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $occurred_at ) {
            return array( 'ok' => false, 'error' => 'invalid_completion_time' );
        }
        if ( ! class_exists( 'TZFD_Suite_Gateway' ) ) { return array( 'ok' => false, 'error' => 'workspace_gateway_unavailable' ); }
        $payload = array(
            'version' => self::VERSION, 'source_plugin' => 'front-desk', 'task_type' => $task_type,
            'idempotency_key' => hash( 'sha256', $company_id . ':' . $task_type . ':' . $task_key ),
            'completed_at' => $occurred_at,
            'estimated_seconds_saved' => max( 0, (int) round( (float) $manual_seconds - (float) $human_seconds ) ),
            'outcome' => 'completed',
        );
        $result = TZFD_Suite_Gateway::request( 'workspace', 'smart_task.record', $company_id, $payload );
        if ( ! isset( $result['ok'] ) || true !== $result['ok'] ) { return $result; }
        if ( ! isset( $result['data']['recorded'] ) || ! is_bool( $result['data']['recorded'] ) ) {
            return array( 'ok' => false, 'error' => 'unverified_owner_receipt' );
        }
        return array( 'ok' => true, 'recorded' => $result['data']['recorded'],
            'duplicate' => ! $result['data']['recorded'], 'seconds_saved' => $payload['estimated_seconds_saved'] );
    }
}
