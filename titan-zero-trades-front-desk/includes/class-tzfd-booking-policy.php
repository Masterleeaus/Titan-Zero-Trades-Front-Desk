<?php
/**
 * Titan Zero Front Desk booking policy decision engine.
 * Pure, fail-closed, read-only decisions. It never confirms bookings or charges deposits.
 * GPL-2.0-only.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_Booking_Policy {
    public static function evaluate($request, $policy, $facts) {
        if (!is_array($request) || !is_array($policy) || !is_array($facts)) throw new InvalidArgumentException('Policy inputs must be arrays.');
        $company = $request['company_id'] ?? null;
        if (!is_int($company) || $company < 1 || ($request['verified_company_id'] ?? null) !== $company ||
            ($policy['company_id'] ?? null) !== $company || ($facts['company_id'] ?? null) !== $company ||
            empty($facts['verified']) || empty($facts['complete'])) throw new InvalidArgumentException('Verified, complete company-scoped policy facts required.');
        foreach (['service_id','site_id'] as $key) {
            if (!isset($request[$key]) || !is_string($request[$key]) || $request[$key] === '') throw new InvalidArgumentException('Missing booking identifier.');
        }
        if (($policy['service_id'] ?? null) !== $request['service_id'] || ($policy['site_id'] ?? null) !== $request['site_id']) throw new InvalidArgumentException('Policy scope mismatch.');
        $start = $request['start_utc'] ?? null;
        $end = $request['end_utc'] ?? null;
        $now = $facts['now_utc'] ?? null;
        if (!is_int($start) || !is_int($end) || !is_int($now) || $end <= $start) throw new InvalidArgumentException('Invalid appointment times.');
        foreach (['booking_count','capacity_remaining'] as $key) {
            if (!isset($facts[$key]) || !is_int($facts[$key]) || $facts[$key] < 0) throw new InvalidArgumentException('Invalid booking facts.');
        }
        $reasons=[];
        if (empty($policy['enabled'])) $reasons[]='service_disabled';
        $minNotice = self::nonnegative($policy,'min_notice_minutes',0);
        $maxAdvance = self::nonnegative($policy,'max_advance_days',365);
        $duration = self::nonnegative($policy,'duration_minutes',0);
        $buffer = self::nonnegative($policy,'buffer_minutes',0);
        $maxBookings = self::nonnegative($policy,'max_bookings_per_customer',0);
        if ($start < $now || ($start-$now) < $minNotice*60) $reasons[]='insufficient_notice';
        if ($start-$now > $maxAdvance*86400) $reasons[]='too_far_ahead';
        if ($duration > 0 && $end-$start !== ($duration+$buffer)*60) $reasons[]='invalid_duration';
        if ($maxBookings > 0 && $facts['booking_count'] >= $maxBookings) $reasons[]='booking_limit';
        if ($facts['capacity_remaining'] < 1) $reasons[]='capacity_unavailable';
        $required = $policy['required_prerequisites'] ?? [];
        $satisfied = $facts['satisfied_prerequisites'] ?? [];
        if (!is_array($required) || !is_array($satisfied) || count(array_filter($required,'is_string')) !== count($required) || count(array_filter($satisfied,'is_string')) !== count($satisfied)) throw new InvalidArgumentException('Invalid prerequisites.');
        if (count(array_diff($required,$satisfied))) $reasons[]='prerequisite_missing';
        $approval = !empty($policy['requires_approval']);
        $deposit = !empty($policy['requires_deposit']);
        $allowed = count($reasons) === 0;
        return [
            'schema_version'=>1,'company_id'=>$company,'read_only'=>true,'booking_committed'=>false,
            'allowed'=>$allowed,'decision'=>$allowed ? ($approval ? 'approval_required' : 'eligible') : 'rejected',
            'reasons'=>array_values(array_unique($reasons)),
            'requires_approval'=>$allowed && $approval,
            'deposit_handoff_required'=>$allowed && $deposit,
            'deposit_owner'=>'invoicing',
            'requires_authoritative_commit_revalidation'=>true,
        ];
    }
    private static function nonnegative($source,$key,$default) {
        $value=$source[$key] ?? $default;
        if (!is_int($value) || $value < 0) throw new InvalidArgumentException('Invalid nonnegative booking-policy parameter.');
        return $value;
    }
}
