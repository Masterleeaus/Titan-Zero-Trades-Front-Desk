<?php
/**
 * Pass 20: deterministic-first, company-scoped AI decision governor.
 * Pure decision component: does not call providers, spend tokens or change bookings.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_AI_Governor {
    public static function decide($company_id, $request, $policy, $usage) {
        if (!is_int($company_id) || $company_id < 1 || !is_array($request) || !is_array($policy) || !is_array($usage)) {
            throw new InvalidArgumentException('Invalid AI governance context.');
        }
        foreach ([$request, $policy, $usage] as $record) {
            if (($record['company_id'] ?? null) !== $company_id || ($record['authoritative'] ?? null) !== true) {
                throw new InvalidArgumentException('Verified company-scoped inputs required.');
            }
        }
        $domain = $request['domain'] ?? null;
        if (!in_array($domain, ['booking', 'phone_call'], true)) return self::result('human_review', 'unsupported_domain');
        if (($request['structured'] ?? false) === true && ($request['validated'] ?? false) === true) {
            return self::result('deterministic', 'validated_structured_intent');
        }
        if (($policy['ai_enabled'] ?? false) !== true) return self::result('human_review', 'ai_disabled');
        if (($request['consent_for_ai'] ?? false) !== true) return self::result('human_review', 'ai_consent_missing');
        if (($request['emergency'] ?? false) === true || ($request['sensitive'] ?? false) === true) {
            return self::result('human_review', 'safety_or_sensitive_escalation');
        }
        if (($request['model_interpretation_needed'] ?? false) !== true) return self::result('human_review', 'no_supported_interpretation_path');
        foreach (['monthly_limit', 'per_request_token_limit'] as $field) {
            if (!isset($policy[$field]) || !is_int($policy[$field]) || $policy[$field] < 1) throw new InvalidArgumentException('Invalid AI budget policy.');
        }
        foreach (['monthly_used', 'reserved_tokens', 'expected_tokens'] as $field) {
            if (!isset($usage[$field]) || !is_int($usage[$field]) || $usage[$field] < 0) throw new InvalidArgumentException('Invalid AI usage snapshot.');
        }
        if (($usage['snapshot_complete'] ?? false) !== true) return self::result('human_review', 'incomplete_budget_snapshot');
        $estimate = $usage['expected_tokens'];
        if ($estimate < 1 || $estimate > $policy['per_request_token_limit']) return self::result('human_review', 'request_budget_exceeded');
        // Avoid integer overflow: never add attacker-influenced usage counters.
        // Evaluate the remaining budget with subtraction after non-negative validation.
        $remaining = $policy['monthly_limit'];
        if ($usage['monthly_used'] > $remaining) return self::result('human_review', 'monthly_budget_exceeded');
        $remaining -= $usage['monthly_used'];
        if ($usage['reserved_tokens'] > $remaining) return self::result('human_review', 'monthly_budget_exceeded');
        $remaining -= $usage['reserved_tokens'];
        if ($estimate > $remaining) return self::result('human_review', 'monthly_budget_exceeded');
        $allowed = $policy['allowed_models'] ?? [];
        if (!is_array($allowed) || !$allowed || count($allowed) > 20) return self::result('human_review', 'no_approved_models');
        $models = [];
        foreach ($allowed as $model) {
            if (!is_array($model) || !isset($model['id'], $model['rank']) || !is_string($model['id']) ||
                !preg_match('/^[a-zA-Z0-9_.:\/-]{1,100}$/', $model['id']) || !is_int($model['rank']) || $model['rank'] < 0) {
                throw new InvalidArgumentException('Invalid approved model.');
            }
            $models[] = $model;
        }
        usort($models, static function($a, $b) { return ($a['rank'] <=> $b['rank']) ?: strcmp($a['id'], $b['id']); });
        return self::result('ai_interpretation', 'approved_budgeted_interpretation', $models[0]['id']);
    }
    private static function result($route, $reason, $model = null) {
        return ['schema_version'=>1, 'route'=>$route, 'reason'=>$reason, 'model'=>$model,
            'read_only'=>true, 'provider_called'=>false, 'booking_mutated'=>false,
            'requires_authoritative_commit'=>true];
    }
}
