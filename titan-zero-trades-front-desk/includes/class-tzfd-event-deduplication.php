<?php
/** Pass 34: read-only event deduplication planner; persistent unique index still required. */
if (!defined('ABSPATH')) exit;
final class TZFD_Event_Deduplication {
    public static function check($company_id,$event,$existing_ids,$context) {
        if(!is_int($company_id)||$company_id<1||!is_array($event)||!is_array($existing_ids)||!is_array($context)||
            ($context['verified_company_id']??null)!==$company_id || ($context['snapshot_authoritative']??null)!==true ||
            ($context['snapshot_complete']??null)!==true || ($event['company_id']??null)!==$company_id ||
            !in_array($event['domain']??null,['bookings','phone_calls'],true) ||
            !is_string($event['provider']??null) || !preg_match('/^[a-z0-9_-]{2,64}$/D',$event['provider']) ||
            !is_string($event['event_id']??null) || !preg_match('/^[A-Za-z0-9:_-]{8,128}$/D',$event['event_id']) || count($existing_ids)>10000)
            throw new InvalidArgumentException('Unverified event identity.');
        foreach($existing_ids as $id) if(!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id)) throw new InvalidArgumentException('Invalid dedup index snapshot.');
        $key=hash('sha256',$company_id.'|'.$event['domain'].'|'.$event['provider'].'|'.$event['event_id']);
        return ['decision'=>in_array($key,$existing_ids,true)?'duplicate':'new_event','event_key'=>$key,
            'requires_atomic_unique_insert'=>true,'can_commit'=>false,'smart_tasks_recorded'=>0];
    }
}
