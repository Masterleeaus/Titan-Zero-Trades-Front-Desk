<?php
/** Pass 34: deterministic, read-only workflow transition planner. No side effects. */
if (!defined('ABSPATH')) exit;
final class TZFD_Workflow_Orchestrator {
    private const MAP = [
        'received'=>['validated','rejected'],
        'validated'=>['queued','human_review','rejected'],
        'queued'=>['processing','cancelled'],
        'processing'=>['awaiting_confirmation','completed','failed'],
        'awaiting_confirmation'=>['processing','completed','failed','cancelled'],
        'failed'=>['queued','human_review'],
        'human_review'=>['queued','cancelled'],
        'rejected'=>[], 'cancelled'=>[], 'completed'=>[]
    ];
    public static function plan($company_id,$workflow,$request,$context) {
        if (!is_int($company_id) || $company_id<1 || !is_array($workflow) || !is_array($request) || !is_array($context) ||
            ($context['verified_company_id']??null)!==$company_id || ($context['snapshot_authoritative']??null)!==true ||
            ($workflow['company_id']??null)!==$company_id || ($request['company_id']??null)!==$company_id ||
            !in_array($workflow['domain']??null,['bookings','phone_calls'],true) ||
            ($request['domain']??null)!==($workflow['domain']??null) ||
            !is_string($workflow['workflow_id']??null) || !preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D',$workflow['workflow_id']) ||
            !is_int($workflow['version']??null) || $workflow['version']<0 ||
            !array_key_exists($workflow['state']??'',self::MAP) ||
            !is_string($request['event_id']??null) || !preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D',$request['event_id']) ||
            !is_int($request['expected_version']??null) || !is_string($request['next_state']??null)) {
            throw new InvalidArgumentException('Invalid or unverified workflow input.');
        }
        if ($request['expected_version']!==$workflow['version']) return ['decision'=>'stale_version','can_commit'=>false];
        $seen=$context['processed_event_ids']??null;
        if (!is_array($seen) || count($seen)>10000) throw new InvalidArgumentException('Authoritative event snapshot required.');
        foreach($seen as $event_id) if(!is_string($event_id)) throw new InvalidArgumentException('Invalid event snapshot.');
        if(in_array($request['event_id'],$seen,true)) return ['decision'=>'duplicate','can_commit'=>false];
        if(!in_array($request['next_state'],self::MAP[$workflow['state']],true)) return ['decision'=>'invalid_transition','can_commit'=>false];
        // The coordinator never commits, delivers, or reports a completed Smart Task.
        return ['decision'=>'transition_proposed','can_commit'=>false,'requires_atomic_compare_and_swap'=>true,
            'company_id'=>$company_id,'workflow_id'=>$workflow['workflow_id'],'domain'=>$workflow['domain'],
            'event_id'=>$request['event_id'],'from_state'=>$workflow['state'],'to_state'=>$request['next_state'],
            'expected_version'=>$workflow['version'],'next_version'=>$workflow['version']+1,'smart_tasks_recorded'=>0];
    }
}
