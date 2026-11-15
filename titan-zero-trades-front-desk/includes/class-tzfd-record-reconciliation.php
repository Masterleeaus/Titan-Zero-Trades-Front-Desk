<?php
/**
 * Pass 72: read-only record identity coverage audit for a single company.
 * Digests MUST be opaque keyed fingerprints independently calculated by
 * source and receiving owner, never hashes of unkeyed PII.
 */
if ( ! defined('ABSPATH') ) { exit; }
final class TZFD_Record_Reconciliation {
    const MAX_RECORDS = 10000;

    /**
     * Verify every source fingerprint appears exactly once in one disposition.
     * Source evidence must come from a trusted, server-side independent owner.
     * This does not approve migration or prove destination field correctness.
     */
    public static function audit($source, $company_id, $source_count, $receipt) {
        $plan = TZFD_Migration_Handoff::plan($source, $company_id, $source_count, $receipt);
        if (is_wp_error($plan)) return $plan;
        $plan['records_reconciled'] = false;
        if (empty($plan['partitions_reconciled']) || empty($plan['counts_reconciled'])) return $plan;
        $plan['reason'] = 'record_evidence_missing_or_unverified';
        if ($source_count > self::MAX_RECORDS || !is_array($receipt) || !isset($receipt['record_fingerprints']) ||
            !is_array($receipt['record_fingerprints']) || !function_exists('apply_filters')) return $plan;
        $owner = $receipt['record_fingerprints'];
        if (array_keys($owner) !== array('imported','rejected','duplicates')) return $plan;
        $source_records = apply_filters('tzfd_verified_source_record_fingerprints_v1', null, $source, (int)$company_id);
        if (!is_array($source_records) || count($source_records) !== $source_count) return $plan;
        $expected = array();
        foreach ($source_records as $digest) {
            if (!self::valid_digest($digest) || isset($expected[$digest])) return $plan;
            $expected[$digest] = true;
        }
        $seen = array();
        foreach (array('imported','rejected','duplicates') as $status) {
            if (!is_array($owner[$status]) || count($owner[$status]) !== $receipt[$status]) return $plan;
            foreach ($owner[$status] as $digest) {
                if (!self::valid_digest($digest) || !isset($expected[$digest]) || isset($seen[$digest])) return $plan;
                $seen[$digest] = true;
            }
        }
        if (count($seen) !== count($expected)) return $plan;
        $plan['records_reconciled'] = true;
        $plan['records_checked'] = count($seen);
        $plan['reason'] = 'record_fingerprints_match_manual_signoff_and_field_validation_required';
        // No transfer, deletion, automatic approval or rollback is performed.
        return $plan;
    }

    /**
     * Compare destination imported field fingerprints against an independent
     * trusted source manifest. Both manifests use the same canonical,
     * versioned HMAC construction; no customer fields are passed in here.
     * Rejected/duplicate dispositions require separate owner review.
     * This method performs no write and never authorises a migration.
     */
    public static function audit_imported_fields($source, $company_id, $source_count, $receipt) {
        $plan = self::audit($source, $company_id, $source_count, $receipt);
        if (is_wp_error($plan)) return $plan;
        $plan['fields_reconciled'] = false;
        if (empty($plan['records_reconciled'])) {
            $plan['reason'] = 'record_reconciliation_required_before_field_audit';
            return $plan;
        }
        $plan['reason'] = 'imported_field_evidence_missing_or_unverified';
        if (!is_array($receipt) || !isset($receipt['imported_field_fingerprints']) ||
            !is_array($receipt['imported_field_fingerprints']) || !function_exists('apply_filters')) return $plan;
        $destination = $receipt['imported_field_fingerprints'];
        $source_fields = apply_filters('tzfd_verified_source_field_fingerprints_v1', null, $source, (int)$company_id);
        if (!is_array($source_fields) || count($source_fields) !== $source_count ||
            count($destination) !== count($receipt['record_fingerprints']['imported'])) return $plan;
        // The independently sourced manifest must cover all source identities,
        // including rejected records, so missing source evidence fails closed.
        $identities = array();
        foreach ($receipt['record_fingerprints'] as $status => $records) {
            foreach ($records as $identity) $identities[$identity] = true;
        }
        foreach ($source_fields as $identity => $fingerprint) {
            if (!self::valid_digest($identity) || !self::valid_digest($fingerprint) ||
                !isset($identities[$identity])) return $plan;
        }
        foreach ($identities as $identity => $_) {
            if (!array_key_exists($identity, $source_fields)) return $plan;
        }
        foreach ($receipt['record_fingerprints']['imported'] as $identity) {
            if (!array_key_exists($identity, $destination) ||
                !self::valid_digest($destination[$identity]) ||
                !hash_equals($source_fields[$identity], $destination[$identity])) return $plan;
        }
        foreach ($destination as $identity => $digest) {
            if (!self::valid_digest($identity) || !isset($identities[$identity]) ||
                !in_array($identity, $receipt['record_fingerprints']['imported'], true)) return $plan;
        }
        $plan['fields_reconciled'] = true;
        $plan['fields_checked'] = count($destination);
        $plan['reason'] = 'imported_field_fingerprints_match_manual_signoff_and_rollback_rehearsal_required';
        // Explicitly never approve, mutate, transfer or delete data.
        $plan['ready'] = false;
        return $plan;
    }

    /**
     * Read-only rollback readiness assessment. A backup reference supplied by
     * the caller is never accepted as independent evidence. A trusted backup
     * owner must attest that a restorable snapshot and a completed rehearsal
     * correspond to this exact company, source and destination.
     * This never executes restoration or approves migration.
     */
    public static function audit_rollback_readiness($source, $company_id, $source_count, $receipt) {
        $plan = self::audit_imported_fields($source, $company_id, $source_count, $receipt);
        if (is_wp_error($plan)) return $plan;
        $plan['rollback_rehearsed'] = false;
        $plan['ready'] = false;
        $plan['reason'] = 'field_reconciliation_required_before_rollback_audit';
        if (empty($plan['fields_reconciled'])) return $plan;
        $plan['reason'] = 'trusted_rollback_evidence_missing';
        if (!function_exists('apply_filters')) return $plan;
        $evidence = apply_filters('tzfd_verified_rollback_rehearsal_v1', null,
            $source, (int)$company_id, $plan['destination']);
        if (!is_array($evidence) || array_keys($evidence) !== array(
            'source', 'destination', 'company_id', 'snapshot_digest',
            'restored_snapshot_digest', 'rehearsal_completed_at', 'result',
            'source_count', 'manifest_digest')) return $plan;
        if ($evidence['source'] !== $source || $evidence['destination'] !== $plan['destination'] ||
            $evidence['company_id'] !== (int)$company_id ||
            $evidence['source_count'] !== $source_count ||
            !self::valid_digest($evidence['manifest_digest']) ||
            !hash_equals(self::rollback_manifest_digest($receipt), $evidence['manifest_digest']) ||
            !self::valid_digest($evidence['snapshot_digest']) ||
            !self::valid_digest($evidence['restored_snapshot_digest']) ||
            !hash_equals($evidence['snapshot_digest'], $evidence['restored_snapshot_digest']) ||
            $evidence['result'] !== 'restored_and_verified') return $plan;
        // Require an actual UTC timestamp not in the future; use strict parsing.
        $stamp = $evidence['rehearsal_completed_at'];
        if (!is_string($stamp) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $stamp)) return $plan;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $stamp, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d\TH:i:s\Z') !== $stamp ||
            $dt->getTimestamp() > time()) return $plan;
        $plan['rollback_rehearsed'] = true;
        $plan['reason'] = 'rollback_evidence_verified_manual_owner_signoff_required';
        // A verified rehearsal is evidence, never permission to migrate or delete.
        return $plan;
    }

    /**
     * Versioned commitment to the EXACT reconciled manifest (not raw customer
     * information). Sorting prevents receipt ordering from changing identity.
     * A trusted restore owner must independently attest to this commitment.
     */
    public static function rollback_manifest_digest($receipt) {
        $rows = array();
        if (!is_array($receipt) || !isset($receipt['record_fingerprints'], $receipt['imported_field_fingerprints'])) return '';
        foreach (array('imported','rejected','duplicates') as $status) {
            if (!isset($receipt['record_fingerprints'][$status]) || !is_array($receipt['record_fingerprints'][$status])) return '';
            foreach ($receipt['record_fingerprints'][$status] as $identity) {
                if (!self::valid_digest($identity)) return '';
                $field = $status === 'imported' ? ($receipt['imported_field_fingerprints'][$identity] ?? '') : '';
                if ($status === 'imported' && !self::valid_digest($field)) return '';
                $rows[] = array($identity, $status, $field);
            }
        }
        usort($rows, static function($a,$b) { return strcmp($a[0], $b[0]); });
        return hash('sha256', "tzfd-migration-manifest-v1\n" . wp_json_encode($rows, JSON_UNESCAPED_SLASHES));
    }

    private static function valid_digest($digest) {
        return is_string($digest) && preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1;
    }
}
