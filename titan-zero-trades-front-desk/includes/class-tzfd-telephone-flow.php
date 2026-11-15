<?php
/** Pass 30: deterministic telephone queue, overflow, IVR and failure decisions. GPL-2.0-only.
 * Read-only decisions. Never claims a transfer, answered call or message delivery happened.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_Telephone_Flow {
 private static function scope($company,$context) {
  if(!is_int($company)||$company<1||!is_array($context)||($context['verified_company_id']??null)!==$company||($context['snapshot_authoritative']??null)!==true||($context['snapshot_complete']??null)!==true||!is_int($context['now_utc']??null)||$context['now_utc']<1)throw new InvalidArgumentException('Verified telephone snapshot required.');
 }
 private static function row($company,$row) {
  if(!is_array($row)||($row['company_id']??null)!==$company||($row['owner_verified']??null)!==true)throw new InvalidArgumentException('Unverified company record.');
 }
 private static function key($v){return is_string($v)&&preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$v);}
 /** Priority is explicitly provided by authenticated business routing rules, never caller-supplied value. */
 public static function queue($company,$calls,$context){
  self::scope($company,$context);if(!is_array($calls)||count($calls)>1000)throw new InvalidArgumentException('Queue input invalid.');$result=[];$seen=[];
  foreach($calls as $c){self::row($company,$c);
   if(!self::key($c['call_key']??null)||!in_array($c['status']??null,['waiting','ringing'],true)||!is_int($c['queued_at_utc']??null)||$c['queued_at_utc']>$context['now_utc']||!is_int($c['policy_priority']??null)||$c['policy_priority']<0||$c['policy_priority']>100||($c['priority_verified']??null)!==true)throw new InvalidArgumentException('Invalid call queue state.');
   if(isset($seen[$c['call_key']]))throw new InvalidArgumentException('Duplicate call key.');$seen[$c['call_key']]=true;
   $result[]=['call_key'=>$c['call_key'],'priority'=>$c['policy_priority'],'queued_at_utc'=>$c['queued_at_utc']];
  }
  usort($result,static function($a,$b){return ($b['priority']<=>$a['priority']) ?: ($a['queued_at_utc']<=>$b['queued_at_utc']) ?: strcmp($a['call_key'],$b['call_key']);});
  return ['company_id'=>$company,'queue'=>$result,'read_only'=>true];
 }
 /** Overflow is only a routing suggestion; delivery must be confirmed by the provider. */
 public static function overflow($company,$call,$capacity,$targets,$context){
  self::scope($company,$context);self::row($company,$call);
  if(!self::key($call['call_key']??null)||!in_array($call['status']??null,['waiting','ringing'],true)||!is_int($call['wait_seconds']??null)||$call['wait_seconds']<0||$call['wait_seconds']>86400||!is_array($capacity)||!is_int($capacity['available_agents']??null)||$capacity['available_agents']<0||!is_int($capacity['overflow_after_seconds']??null)||$capacity['overflow_after_seconds']<0||!is_array($targets))throw new InvalidArgumentException('Invalid overflow input.');
  if($capacity['available_agents']>0&&$call['wait_seconds']<$capacity['overflow_after_seconds'])return ['company_id'=>$company,'action'=>'stay_in_queue','read_only'=>true];
  foreach($targets as $t){self::row($company,$t);if(!in_array($t['kind']??null,['callback','voicemail','approved_provider','human_operator'],true)||!self::key($t['target_key']??null)||!is_bool($t['enabled']??null))throw new InvalidArgumentException('Invalid overflow target.');if($t['enabled'])return ['company_id'=>$company,'action'=>'propose_overflow','target_kind'=>$t['kind'],'target_key'=>$t['target_key'],'requires_provider_confirmation'=>true,'read_only'=>true];}
  return ['company_id'=>$company,'action'=>'manual_review','read_only'=>true];
 }
 /** Simple DTMF menu lookup. Menus originate from approved, company-scoped configuration. */
 public static function ivr($company,$menu,$digit,$context){
  self::scope($company,$context);self::row($company,$menu);
  if(($menu['approved']??null)!==true||!is_array($menu['choices']??null)||count($menu['choices'])>12||!is_string($digit)||!preg_match('/^[0-9*#]$/D',$digit))throw new InvalidArgumentException('Invalid IVR input.');
  $choices=[];foreach($menu['choices'] as $key=>$choice){if(!is_string($key)&&!is_int($key))throw new InvalidArgumentException('Invalid option.');$key=(string)$key;if(!preg_match('/^[0-9*#]$/D',$key)||!in_array($choice,['new_booking','reschedule','cancel','callback','human_operator','voicemail'],true))throw new InvalidArgumentException('Unapproved IVR option.');$choices[$key]=$choice;}
  return ['company_id'=>$company,'action'=>$choices[$digit]??'invalid_selection','read_only'=>true];
 }
 /** Classifies provider-confirmed failure codes into bounded retry or human follow-up proposals. */
 public static function recover($company,$event,$context){
  self::scope($company,$context);self::row($company,$event);
  if(!self::key($event['event_key']??null)||($event['provider_authenticated']??null)!==true||!in_array($event['failure_code']??null,['timeout','temporary_unavailable','rate_limited','invalid_destination','authentication_failed','rejected'],true)||!is_int($event['attempt']??null)||$event['attempt']<1||$event['attempt']>100)throw new InvalidArgumentException('Unverified failure.');
  $transient=in_array($event['failure_code'],['timeout','temporary_unavailable','rate_limited'],true);
  $retry=$transient&&$event['attempt']<3;
  return ['company_id'=>$company,'action'=>$retry?'propose_retry':'manual_review','retry_after_seconds'=>$retry?min(900,30*(2**($event['attempt']-1))):null,'dedupe_key'=>hash('sha256',$company.':'.$event['event_key'].':'.$event['attempt']),'requires_persistent_idempotency'=>true,'read_only'=>true];
 }
}
