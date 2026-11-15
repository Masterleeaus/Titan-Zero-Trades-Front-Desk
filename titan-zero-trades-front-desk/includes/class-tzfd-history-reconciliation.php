<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Pass 22: read-only migration inventory and reconciliation planner.
 * No rows are copied, deleted, or modified. Receiving owners must supply
 * signed-off import contracts before executable migration steps are registered.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_History_Reconciliation {
    public static function manifest() {
        return array(
            array('source'=>'tz_enquiries', 'destination'=>'workspace', 'domain'=>'crm_leads', 'decision'=>'move_after_owner_contract'),
            array('source'=>'tz_pipeline', 'destination'=>'workspace', 'domain'=>'mixed_pipeline', 'decision'=>'split_booking_approval_from_crm'),
            array('source'=>'tz_pipeline_events', 'destination'=>'workspace', 'domain'=>'mixed_pipeline_history', 'decision'=>'split_and_reconcile'),
            array('source'=>'tz_ai_conversations', 'destination'=>'undecided', 'domain'=>'conversation_history', 'decision'=>'classify_before_migration'),
            array('source'=>'tz_addon_records', 'destination'=>'undecided', 'domain'=>'mixed_addon_records', 'decision'=>'retain_read_only_until_all_owners_reconcile'),
        );
    }

    /**
     * Inventory exact allowlisted table names only; avoids assumptions about
     * legacy schemas and never queries personal rows. Requires explicit admin.
     */
    public static function inventory( $authorized = false ) {
        if ( ! $authorized || ! function_exists('current_user_can') || ! current_user_can('manage_options') ) {
            return new WP_Error('tzfd_inventory_forbidden', __( 'Administrator authorisation required.', 'titan-zero-trades-front-desk' ));
        }
        global $wpdb;
        if ( ! isset($wpdb) || ! method_exists($wpdb, 'prepare') ) {
            return new WP_Error('tzfd_inventory_unavailable', __( 'Database is unavailable.', 'titan-zero-trades-front-desk' ));
        }
        $result = array();
        foreach ( self::manifest() as $entry ) {
            $bare = $entry['source'];
            if ( ! TZFD_Tables::is_canonical($bare) || ! in_array($bare, TZFD_Tables::owned(), true) ) {
                return new WP_Error('tzfd_inventory_invalid_table', __( 'Source is not allowlisted.', 'titan-zero-trades-front-desk' ));
            }
            $table = $wpdb->prefix . $bare;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            $entry['exists'] = ($exists === $table);
            $entry['rows'] = $entry['exists'] ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : null;
            $entry['company_breakdown'] = 'not_verified';
            $entry['migration_status'] = 'not_started';
            $result[] = $entry;
        }
        return array('mode'=>'read_only', 'rows'=>$result, 'no_changes_made'=>true,
            'limitations'=>array('Schema-specific company-id counts require verified table layouts.', 'Destination row counts and per-row checksums require receiving-owner contracts.', 'No migrations are registered or executed.'));
    }

    /**
     * Reconcile explicit company partitions without reading customer data.
     * Source: company_id => original row count.
     * Receipt: company_id => imported/rejected/duplicates counts.
     * Reject unknown companies, absent companies, and cross-company allocations.
     * Only evidence supplied by a trusted server-side source may be passed here;
     * this calculation alone never authorises a data transfer or deletion.
     */
    public static function reconcile_company_partitions( $sources, $receipts ) {
        if ( ! is_array( $sources ) || ! is_array( $receipts ) || ! $sources ) {
            return new WP_Error('tzfd_partitions_invalid', __( 'Company partitions are required.', 'titan-zero-trades-front-desk' ));
        }
        $normal = array();
        foreach ( $sources as $company => $count ) {
            $id = filter_var($company, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1)));
            if ( false === $id || !is_int($count) || $count < 0 || isset($normal[$id]) ) {
                return new WP_Error('tzfd_partitions_invalid', __( 'Invalid source company partition.', 'titan-zero-trades-front-desk' ));
            }
            $normal[$id] = $count;
        }
        $received = array();
        foreach ( $receipts as $company => $counts ) {
            $id = filter_var($company, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1)));
            if ( false === $id || !isset($normal[$id]) || isset($received[$id]) || !is_array($counts) ||
                array_keys($counts) !== array('imported','rejected','duplicates') ) {
                return new WP_Error('tzfd_partitions_invalid', __( 'Invalid or unknown destination company partition.', 'titan-zero-trades-front-desk' ));
            }
            $check = self::reconcile_counts($normal[$id], $counts['imported'], $counts['rejected'], $counts['duplicates']);
            if ( is_wp_error($check) ) { return $check; }
            $received[$id] = $check;
        }
        if (count($normal) !== count($received)) {
            return new WP_Error('tzfd_partitions_missing', __( 'Destination company partitions are incomplete.', 'titan-zero-trades-front-desk' ));
        }
        foreach ($received as $check) {
            if (!$check['matched']) {
                return array('matched'=>false, 'requires_owner_acknowledgement'=>true, 'no_changes_made'=>true);
            }
        }
        return array('matched'=>true, 'companies_checked'=>count($received),
            'requires_owner_acknowledgement'=>true, 'no_changes_made'=>true);
    }

    /** Compare explicit numeric source/destination counts only, never infer a successful migration. */
    public static function reconcile_counts( $source, $destination, $rejected, $duplicates ) {
        foreach (array($source,$destination,$rejected,$duplicates) as $count) {
            if (!is_int($count) || $count < 0) { return new WP_Error('tzfd_invalid_reconciliation', __( 'Counts must be non-negative integers.', 'titan-zero-trades-front-desk' )); }
        }
        // Avoid integer overflow: adding untrusted counts can wrap or become a
        // float and must never result in an apparent successful migration.
        // Subtract only after checking each non-negative component fits.
        $remaining = $source;
        $matched = true;
        foreach (array($destination, $rejected, $duplicates) as $component) {
            if ($component > $remaining) {
                $matched = false;
                break;
            }
            $remaining -= $component;
        }
        if ($remaining !== 0) { $matched = false; }
        return array('matched'=>$matched,
            'source'=>$source, 'imported'=>$destination, 'rejected'=>$rejected,
            'duplicates'=>$duplicates, 'requires_owner_acknowledgement'=>true);
    }
}
