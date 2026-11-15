<?php
/** Pass 33: explainable, read-only discovery of repeatable booking/call routing rules. */
if (!defined('ABSPATH')) exit;
final class TZFD_Automation_Pattern_Discovery {
    private const INTENTS = ['new_booking','reschedule','cancel','confirm','callback','availability','waitlist'];
    private const ACTIONS = ['booking_intake','signed_reschedule','signed_cancel','signed_confirm','callback_queue','slot_search','waitlist_review','human_review'];
    /** Proposals are advisory; NEVER execute, store, approve, or activate rules here. */
    public static function discover($company_id, $events, $context = []) {
        if (!is_int($company_id) || $company_id < 1 || !is_array($events) || !is_array($context) ||
            ($context['verified_company_id'] ?? null) !== $company_id ||
            ($context['snapshot_authoritative'] ?? null) !== true ||
            ($context['snapshot_complete'] ?? null) !== true) {
            throw new InvalidArgumentException('Authoritative, complete company snapshot required.');
        }
        $min = $context['min_samples'] ?? 6;
        $threshold = $context['min_support'] ?? 0.8;
        $min_validation = $context['min_validation_samples'] ?? 2;
        if (!is_int($min) || $min < 4 || $min > 500 || !is_numeric($threshold) || $threshold < 0.6 || $threshold > 1 ||
            !is_int($min_validation) || $min_validation < 1 || $min_validation > 500 || count($events) > 10000) {
            throw new InvalidArgumentException('Invalid discovery parameters.');
        }
        $seen=[]; $groups=[];
        foreach ($events as $event) {
            if (!is_array($event) || ($event['company_id'] ?? null) !== $company_id ||
                ($event['authoritative'] ?? null) !== true || ($event['approved'] ?? null) !== true ||
                ($event['finalized'] ?? null) !== true ||
                !is_string($event['event_id'] ?? null) || !preg_match('/^[A-Za-z0-9_.:-]{6,128}$/D',$event['event_id']) ||
                !in_array($event['domain'] ?? null, ['bookings','phone_calls'], true) ||
                !in_array($event['intent'] ?? null, self::INTENTS, true) ||
                !in_array($event['action'] ?? null, self::ACTIONS, true) ||
                !in_array($event['partition'] ?? null, ['training','validation'], true)) {
                throw new InvalidArgumentException('Untrusted pattern evidence.');
            }
            if (isset($seen[$event['event_id']])) throw new InvalidArgumentException('Repeated event identity.');
            $seen[$event['event_id']]=true;
            $domain=$event['domain']; $intent=$event['intent']; $action=$event['action']; $partition=$event['partition'];
            // Explicitly reject cross-domain and high-risk action mappings.
            if (($domain === 'phone_calls' && !in_array($action,['booking_intake','callback_queue','human_review'],true)) ||
                ($domain === 'bookings' && $action === 'callback_queue')) throw new InvalidArgumentException('Out-of-scope action.');
            $key=$domain.'|'.$intent;
            if (!isset($groups[$key])) $groups[$key]=['domain'=>$domain,'intent'=>$intent,'training'=>[],'validation'=>[]];
            $groups[$key][$partition][$action]=($groups[$key][$partition][$action]??0)+1;
        }
        ksort($groups,SORT_STRING);$proposals=[];$skipped=[];
        foreach ($groups as $key=>$g) {
            $training=array_sum($g['training']); $validation=array_sum($g['validation']);
            if ($training < $min || $validation < $min_validation) { $skipped[$key]='insufficient_evidence';continue; }
            arsort($g['training'],SORT_NUMERIC);$top=max($g['training']);
            $winners=array_keys(array_filter($g['training'],static fn($n)=>$n===$top));
            if (count($winners)!==1) { $skipped[$key]='tied_actions';continue; }
            $action=$winners[0];
            // No automatic proposals for review-only outcomes or risky intents.
            if ($action==='human_review' || ($g['intent']==='cancel' && $action!=='signed_cancel') ||
                ($g['intent']==='reschedule' && $action!=='signed_reschedule')) {$skipped[$key]='manual_review_required';continue;}
            $train_support=$top/$training; $validation_support=($g['validation'][$action]??0)/$validation;
            if ($train_support < $threshold || $validation_support < $threshold) {$skipped[$key]='insufficient_holdout_consensus';continue;}
            $proposals[]=[
                'proposal_id'=>hash('sha256',$company_id.'|'.$key.'|'.$action),
                'domain'=>$g['domain'],'intent'=>$g['intent'],'suggested_action'=>$action,
                'training_samples'=>$training,'validation_samples'=>$validation,
                'training_support'=>round($train_support,4),'validation_support'=>round($validation_support,4),
                'reason'=>'repeated_approved_outcomes_with_holdout_agreement',
                'status'=>'proposed','requires_human_approval'=>true,'activation_allowed'=>false,
                'requires_policy_replay'=>true,'requires_owner_validation'=>true,
            ];
        }
        return ['company_id'=>$company_id,'schema_version'=>1,'read_only'=>true,
            'event_count'=>count($events),'proposals'=>$proposals,'skipped'=>$skipped,
            'rules_activated'=>0,'smart_tasks_recorded'=>0];
    }
}
