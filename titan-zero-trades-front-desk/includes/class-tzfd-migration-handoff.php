<?php
/**
 * Pass 42: fail-closed non-destructive owner handoff planner.
 * It never transfers, drops, marks migrated or modifies source data.
 */
if ( ! defined('ABSPATH') ) { exit; }
final class TZFD_Migration_Handoff {
    /** Owner approval must originate from the destination, not request input. */
    public static function plan($source, $company_id, $source_count, $owner_receipt = null) {
        $id = filter_var($company_id, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1)));
        if (!$id || !is_int($source_count) || $source_count < 0) {
            return new WP_Error('tzfd_handoff_invalid_input',__( 'Invalid company or source count.', 'titan-zero-trades-front-desk' ));
        }
        $entry = null;
        foreach (TZFD_History_Reconciliation::manifest() as $item) {
            if ($item['source'] === $source) { $entry = $item; break; }
        }
        if (!$entry) return new WP_Error('tzfd_handoff_unknown_source',__( 'Source is not allowlisted.', 'titan-zero-trades-front-desk' ));
        $result = array('source'=>$source, 'company_id'=>(int)$id, 'source_count'=>$source_count,
            'destination'=>$entry['destination'], 'decision'=>$entry['decision'],
            'mode'=>'read_only', 'no_changes_made'=>true, 'ready'=>false,
            'reason'=>'receiving_owner_contract_unverified');
        if ($entry['destination'] === 'undecided' || $entry['destination'] === 'front_desk' || strpos($entry['decision'],'split_') === 0) {
            $result['reason']='classification_or_split_required';
            return $result;
        }
        if (!is_array($owner_receipt) || !function_exists('apply_filters') || true !== apply_filters('tzfd_verify_migration_owner_receipt_v1', false, $owner_receipt, $entry) ||
            ($owner_receipt['source']??null)!==$source || ($owner_receipt['destination']??null)!==$entry['destination'] ||
            ($owner_receipt['company_id']??null)!==(int)$id || ($owner_receipt['contract_version']??null)!==1 ||
            !is_int($owner_receipt['imported']??null) || !is_int($owner_receipt['duplicates']??null) ||
            !is_int($owner_receipt['rejected']??null)) return $result;
        $check=TZFD_History_Reconciliation::reconcile_counts($source_count,$owner_receipt['imported'], $owner_receipt['rejected'],$owner_receipt['duplicates']);
        if (is_wp_error($check) || !$check['matched']) {
            $result['reason']='row_counts_not_reconciled'; return $result;
        }
        // Counts are insufficient without a trusted per-company source snapshot.
        // Never promote count-only reconciliation into an owner handoff status.
        $result['partitions_reconciled'] = false;
        if (!array_key_exists('company_partitions', $owner_receipt) ||
            !is_array($owner_receipt['company_partitions'])) {
            $result['reason']='company_partitions_required'; return $result;
        }
        $source_partitions = apply_filters('tzfd_verified_source_company_partitions_v1', null, $source, (int)$id);
        if (!is_array($source_partitions) || count($source_partitions) !== 1 ||
            !array_key_exists((int)$id, $source_partitions) || $source_partitions[(int)$id] !== $source_count) {
            $result['reason']='source_company_snapshot_unverified'; return $result;
        }
        $partition_check = TZFD_History_Reconciliation::reconcile_company_partitions($source_partitions, $owner_receipt['company_partitions']);
        if (is_wp_error($partition_check) || !$partition_check['matched']) {
            $result['reason']='company_partitions_not_reconciled'; return $result;
        }
        // For this single-company handoff, the per-company destination counts
        // must also be exactly the totals signed by the receiving owner.
        $receipt_partition = $owner_receipt['company_partitions'][(int)$id] ?? null;
        if (!is_array($receipt_partition) ||
            $receipt_partition['imported'] !== $owner_receipt['imported'] ||
            $receipt_partition['duplicates'] !== $owner_receipt['duplicates'] ||
            $receipt_partition['rejected'] !== $owner_receipt['rejected']) {
            $result['reason']='receipt_partition_totals_conflict'; return $result;
        }
        $result['partitions_reconciled'] = true;
        $result['reason']='verified_counts_only_manual_signoff_still_required';
        $result['counts_reconciled']=true;
        $result['requires_owner_acknowledgement']=true;
        return $result;
    }
}
