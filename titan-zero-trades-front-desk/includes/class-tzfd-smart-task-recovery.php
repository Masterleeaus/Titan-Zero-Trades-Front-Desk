<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Explicit, bounded reconciliation for historic committed call completions. */
final class TZFD_Smart_Task_Recovery {
    public static function reconcile_calls( $company_id, $after_id = 0, $limit = 100 ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) || ! is_int( $company_id ) || $company_id < 1 ||
             ! is_int( $after_id ) || $after_id < 0 || ! is_int( $limit ) || $limit < 1 || $limit > 200 ||
             ! TZFD_Smart_Task_Outbox::installed() ) { return false; }
        // This gate must be supplied by a trusted Workspace adapter; it defaults closed.
        if ( true !== apply_filters( 'tzfd_smart_tasks_can_reconcile_company_v1', false, $company_id ) ) { return false; }
        $tables = TZFD_Call_Lifecycle::tables();
        // Both historical event types must exist. A terminal state alone isn't enough.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.id FROM %i c WHERE c.company_id=%d AND c.id>%d AND c.state='completed'
             AND EXISTS (SELECT 1 FROM %i e WHERE e.company_id=c.company_id AND e.call_id=c.id AND e.event_type='answered')
             AND EXISTS (SELECT 1 FROM %i e WHERE e.company_id=c.company_id AND e.call_id=c.id AND e.event_type='completed')
             ORDER BY c.id ASC LIMIT %d", $tables['calls'], $company_id, $after_id, $tables['events'], $tables['events'], $limit ) );
        if ( ! is_array( $ids ) ) { return false; }
        $result = array( 'examined' => 0, 'queued_or_existing' => 0, 'failed' => 0, 'next_after_id' => $after_id );
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id <= $result['next_after_id'] ) { continue; }
            $result['next_after_id'] = $id;
            ++$result['examined'];
            // Stable identity prevents duplicate counts in both the outbox and Workspace.
            if ( TZFD_Smart_Task_Outbox::enqueue( $company_id, 'call.routing_completed', 'call:completed:' . $id ) ) {
                ++$result['queued_or_existing'];
            } else { ++$result['failed']; }
        }
        return $result;
    }
}
