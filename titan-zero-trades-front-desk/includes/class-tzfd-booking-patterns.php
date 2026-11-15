<?php
/**
 * Titan Zero Front Desk - Pass 19 Booking Pattern Engine.
 * Read-only, explainable booking suggestions from server-verified historical decisions.
 * It does not train an LLM, alter appointments, or activate policies automatically.
 * GPL-2.0-only.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_Booking_Patterns {
    /** Rows must be approved, finalised booking decisions, not customer supplied data. */
    public static function recommend($company_id, $service_id, $location_id, $history, $options = []) {
        if (!is_int($company_id) || $company_id < 1 || !is_int($service_id) || $service_id < 1 ||
            !is_int($location_id) || $location_id < 1 || !is_array($history) || !is_array($options)) {
            throw new InvalidArgumentException('Invalid context.');
        }
        if (($options['verified_company_id'] ?? null) !== $company_id || ($options['history_complete'] ?? null) !== true ||
            ($options['history_authoritative'] ?? null) !== true) throw new InvalidArgumentException('Verified complete history required.');
        $min = $options['min_samples'] ?? 5;
        $threshold = $options['min_support'] ?? 0.7;
        if (!is_int($min) || $min < 3 || $min > 1000 || !is_numeric($threshold) || $threshold < 0.5 || $threshold > 1) throw new InvalidArgumentException('Invalid thresholds.');
        if (count($history) > 10000) throw new InvalidArgumentException('Too many rows.');
        $counts = []; $usable = 0; $seen = [];
        foreach ($history as $row) {
            if (!is_array($row) || ($row['company_id'] ?? null) !== $company_id || !isset($row['decision_id']) ||
                !is_scalar($row['decision_id']) || (string)$row['decision_id'] === '' ||
                ($row['authoritative'] ?? null) !== true || ($row['approved'] ?? null) !== true ||
                ($row['finalized'] ?? null) !== true) throw new InvalidArgumentException('Untrusted historical row.');
            $id=(string)$row['decision_id'];
            if (isset($seen[$id])) continue;
            $seen[$id]=true;
            if (($row['service_id'] ?? null) !== $service_id || ($row['location_id'] ?? null) !== $location_id) continue;
            $duration=$row['duration_minutes'] ?? null;
            if (!is_int($duration) || $duration < 5 || $duration > 1440 || $duration % 5 !== 0) throw new InvalidArgumentException('Invalid duration.');
            $staff=$row['staff_id'] ?? null;
            if (!is_int($staff) || $staff < 1) throw new InvalidArgumentException('Invalid staff ID.');
            $usable++;
            $key=(string)$duration;
            $counts[$key]=($counts[$key] ?? 0)+1;
        }
        $result=['schema_version'=>1,'company_id'=>$company_id,'service_id'=>$service_id,'location_id'=>$location_id,
            'read_only'=>true,'sample_size'=>$usable,'recommendation'=>null,'support'=>0.0,
            'reasons'=>[],'requires_human_approval'=>true,'policy_mutated'=>false,'booking_mutated'=>false];
        if ($usable < $min) { $result['reasons'][]='insufficient_verified_history'; return $result; }
        arsort($counts,SORT_NUMERIC);
        $highest=max($counts);
        $candidates=array_map('intval',array_keys(array_filter($counts,static function($n) use ($highest) {return $n===$highest;})));
        sort($candidates,SORT_NUMERIC);
        $duration=$candidates[0]; $support=$highest/$usable;
        $result['support']=round($support,4);
        if ($support < $threshold) { $result['reasons'][]='low_consensus'; return $result; }
        $result['recommendation']=['duration_minutes'=>$duration,'kind'=>'historical_duration_suggestion'];
        $result['reasons'][]='approved_history_consensus';
        return $result;
    }
}
