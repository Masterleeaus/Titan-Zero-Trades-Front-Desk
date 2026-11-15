<?php
/**
 * Titan Zero Front Desk - explainable no-show triage. GPL-2.0-only.
 * Read-only recommendations; this class does not deny bookings, request deposits,
 * send reminders or write customer data. Caller must supply trusted aggregates.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_No_Show_Risk {
    public static function evaluate($booking, $history, $config, $now_utc) {
        if (!is_array($booking) || !is_array($history) || !is_array($config) || !is_int($now_utc)) throw new InvalidArgumentException('Invalid risk inputs.');
        $company=$booking['company_id'] ?? null;
        if (!is_int($company) || $company < 1 || ($booking['verified_company_id'] ?? null) !== $company ||
            ($history['company_id'] ?? null) !== $company || ($config['company_id'] ?? null) !== $company ||
            empty($history['authoritative']) || empty($history['complete']) || empty($booking['authoritative'])) throw new InvalidArgumentException('Verified company-scoped inputs required.');
        $id=$booking['booking_id'] ?? null;
        $start=$booking['starts_at_utc'] ?? null;
        $created=$booking['created_at_utc'] ?? null;
        if ((!is_int($id) && !is_string($id)) || (string)$id === '' || !is_int($start) || !is_int($created) || $start <= $created || $start <= $now_utc) throw new InvalidArgumentException('Invalid booking timestamps or ID.');
        $completed=self::countValue($history,'attended_count');
        $missed=self::countValue($history,'no_show_count');
        $cancelled=self::countValue($history,'late_cancellation_count');
        if ($completed + $missed + $cancelled > 1000000) throw new InvalidArgumentException('History outside supported bounds.');
        $sample=$completed+$missed;
        $minimum=self::countValue($config,'min_history_count',3);
        if ($minimum > 1000) throw new InvalidArgumentException('Invalid sample threshold.');
        $lead_hours=($start-$created)/3600;
        $reasons=[]; $score=0;
        // The smoothed history rate avoids declaring a first-time customer high-risk.
        if ($sample >= $minimum && $sample > 0) {
            $rate=$missed/$sample;
            if ($rate >= .5) { $score+=45; $reasons[]='history_high_no_show_rate'; }
            elseif ($rate >= .2) { $score+=25; $reasons[]='history_elevated_no_show_rate'; }
        }
        if ($lead_hours >= 24*30) { $score+=15; $reasons[]='long_booking_lead_time'; }
        elseif ($lead_hours >= 24*14) { $score+=8; $reasons[]='extended_booking_lead_time'; }
        if ($sample >= $minimum && $cancelled >= 2) { $score+=10; $reasons[]='multiple_late_cancellations'; }
        $confirmed=$booking['customer_confirmed'] ?? null;
        if (!is_bool($confirmed)) throw new InvalidArgumentException('Confirmed state required.');
        if (!$confirmed && ($start-$now_utc) <= 48*3600) { $score+=20; $reasons[]='unconfirmed_within_48h'; }
        $score=min(100,$score);
        $medium=self::countValue($config,'medium_threshold',25);
        $high=self::countValue($config,'high_threshold',60);
        if ($medium < 1 || $high <= $medium || $high > 100) throw new InvalidArgumentException('Invalid risk thresholds.');
        $band=$score >= $high ? 'high' : ($score >= $medium ? 'medium' : 'low');
        $action=$band === 'high' ? 'manual_confirmation_review' : ($band === 'medium' ? 'additional_confirmation_reminder' : 'standard_reminder');
        return ['schema_version'=>1,'company_id'=>$company,'booking_id'=>$id,'read_only'=>true,'risk_score'=>$score,'risk_band'=>$band,
            'reasons'=>$reasons,'recommended_action'=>$action,'history_sample_size'=>$sample,
            'history_signal_used'=>$sample >= $minimum && $sample > 0,'no_auto_denial'=>true,'no_auto_deposit'=>true,
            'booking_mutated'=>false,'requires_human_approval_for_policy_change'=>true];
    }
    private static function countValue($input,$key,$default=null) {
        $value=$input[$key] ?? $default;
        if (!is_int($value) || $value < 0) throw new InvalidArgumentException('Invalid nonnegative booking-history count.');
        return $value;
    }
}
