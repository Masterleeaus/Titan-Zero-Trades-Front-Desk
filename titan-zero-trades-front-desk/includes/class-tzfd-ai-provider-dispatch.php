<?php
/**
 * Fail-closed admission boundary immediately before every AI provider call.
 * The trusted Workspace budget-owner MUST atomically reserve the estimated
 * tokens before returning a receipt. This component does not itself reserve,
 * settle or refund tokens: it requires trusted owner receipts for both steps.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_AI_Provider_Dispatch {
    /**
     * The WordPress AI Client uses the site owner's own connector and key, so the site pays its own provider.
     * The budget gate applies only once a budget owner is registered (Workspace); with none, this path is allowed.
     * Custom providers always go through reserve() and settle().
     */
    public static function site_pays_provider($provider_id) {
        return 'wordpress-ai-client' === $provider_id && !has_filter('tzfd_ai_reserve_provider_budget_v1');
    }

    public static function reserve($company_id, $provider_id, $request_id, $expected_tokens) {
        if (!is_int($company_id) || $company_id < 1 || !is_string($provider_id) ||
            !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $provider_id) ||
            !is_string($request_id) || !preg_match('/^[a-zA-Z0-9-]{8,100}$/D', $request_id) ||
            !is_int($expected_tokens) || $expected_tokens < 1 || $expected_tokens > 32768) {
            return array('allowed'=>false, 'reason'=>'invalid_dispatch_context');
        }
        // False by default. Must be supplied by trusted server-side Workspace
        // budget ownership code, never by the request or the AI provider.
        $receipt = apply_filters('tzfd_ai_reserve_provider_budget_v1', false,
            $company_id, $provider_id, $request_id, $expected_tokens);
        return self::verify($receipt, $company_id, $provider_id, $request_id, $expected_tokens);
    }
    /**
     * The authoritative budget owner records actual usage exactly once, refunds
     * unused reservations and flags over-budget responses. A missing owner fails
     * closed; never use a provider's own data as an owner acknowledgement.
     */
    public static function settle($company_id, $provider_id, $request_id, $reservation_id, $prompt_tokens, $completion_tokens, $provider_failed = false, $reserved_tokens = null) {
        if (!is_int($company_id) || $company_id < 1 ||
            !is_string($provider_id) || !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $provider_id) ||
            !is_string($request_id) || !preg_match('/^[a-zA-Z0-9-]{8,100}$/D', $request_id) ||
            !is_string($reservation_id) || !preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/D', $reservation_id) ||
            !is_int($prompt_tokens) || $prompt_tokens < 0 ||
            !is_int($completion_tokens) || $completion_tokens < 0 ||
            !is_bool($provider_failed) ||
            ($reserved_tokens !== null && (!is_int($reserved_tokens) || $reserved_tokens < 1 || $reserved_tokens > 32768)) ||
            $prompt_tokens > PHP_INT_MAX - $completion_tokens) {
            return array('settled'=>false, 'reason'=>'invalid_settlement_context');
        }
        $used = $prompt_tokens + $completion_tokens;
        $receipt = apply_filters('tzfd_ai_settle_provider_budget_v1', false,
            $company_id, $provider_id, $request_id, $reservation_id,
            $prompt_tokens, $completion_tokens, $provider_failed);
        $valid = is_array($receipt) && ($receipt['settled'] ?? null) === true &&
            ($receipt['company_id'] ?? null) === $company_id &&
            ($receipt['provider_id'] ?? null) === $provider_id &&
            ($receipt['request_id'] ?? null) === $request_id &&
            ($receipt['reservation_id'] ?? null) === $reservation_id &&
            ($receipt['used_tokens'] ?? null) === $used &&
            ($receipt['provider_failed'] ?? null) === $provider_failed &&
            ($receipt['over_budget'] ?? null) === false &&
            ($reserved_tokens === null || (
                ($receipt['reserved_tokens'] ?? null) === $reserved_tokens &&
                $used <= $reserved_tokens
            ));
        return $valid ? array('settled'=>true, 'reason'=>'verified')
            : array('settled'=>false, 'reason'=>'budget_settlement_unverified');
    }
    /**
     * Mark an uncertain provider outcome for independent owner reconciliation.
     * Never release budget based on an exception, timeout or missing usage.
     * Owner must persist a pending-reconciliation state idempotently and return
     * a matching acknowledgement. No response content becomes trusted here.
     */
    public static function report_uncertain($company_id, $provider_id, $request_id, $reservation_id, $reason) {
        if (!is_int($company_id) || $company_id < 1 ||
            !is_string($provider_id) || !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $provider_id) ||
            !is_string($request_id) || !preg_match('/^[a-zA-Z0-9-]{8,100}$/D', $request_id) ||
            !is_string($reservation_id) || !preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/D', $reservation_id) ||
            !in_array($reason, array('unknown_usage', 'provider_failure', 'settlement_unverified'), true)) {
            return array('recorded'=>false, 'reason'=>'invalid_uncertain_context');
        }
        $receipt = apply_filters('tzfd_ai_budget_uncertain_usage_v1', false,
            $company_id, $provider_id, $request_id, $reservation_id, $reason);
        $valid = is_array($receipt) && ($receipt['recorded'] ?? null) === true &&
            ($receipt['company_id'] ?? null) === $company_id &&
            ($receipt['provider_id'] ?? null) === $provider_id &&
            ($receipt['request_id'] ?? null) === $request_id &&
            ($receipt['reservation_id'] ?? null) === $reservation_id &&
            ($receipt['uncertain_reason'] ?? null) === $reason &&
            ($receipt['status'] ?? null) === 'pending_reconciliation';
        return $valid ? array('recorded'=>true, 'reason'=>'pending_reconciliation') :
            array('recorded'=>false, 'reason'=>'uncertain_usage_unverified');
    }
    public static function verify($receipt, $company_id, $provider_id, $request_id, $expected_tokens) {
        $valid = is_array($receipt) && ($receipt['reserved'] ?? null) === true &&
            ($receipt['company_id'] ?? null) === $company_id &&
            ($receipt['provider_id'] ?? null) === $provider_id &&
            ($receipt['request_id'] ?? null) === $request_id &&
            ($receipt['reserved_tokens'] ?? null) === $expected_tokens &&
            is_string($receipt['reservation_id'] ?? null) &&
            preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/D', $receipt['reservation_id']) &&
            is_int($receipt['expires_at'] ?? null) && $receipt['expires_at'] > time() &&
            $receipt['expires_at'] <= time() + 600;
        return $valid ? array('allowed'=>true, 'reason'=>'reserved', 'reservation_id'=>$receipt['reservation_id'], 'request_id'=>$request_id, 'reserved_tokens'=>$expected_tokens)
            : array('allowed'=>false, 'reason'=>'budget_reservation_unverified');
    }
}
