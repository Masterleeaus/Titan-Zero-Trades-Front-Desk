<?php
/** Pass 31: deterministic, read-only call outcome, screening and continuity decisions. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
final class TZFD_Call_Reliability {
 private static function scope($company,$ctx) {
  if(!is_int($company)||$company<1||!is_array($ctx)||($ctx['verified_company_id']??null)!==$company||($ctx['snapshot_authoritative']??null)!==true||($ctx['snapshot_complete']??null)!==true||!is_int($ctx['now_utc']??null)||$ctx['now_utc']<1)throw new InvalidArgumentException('Verified call snapshot required.');
 }
 private static function owned($company,$r){if(!is_array($r)||($r['company_id']??null)!==$company||($r['owner_verified']??null)!==true)throw new InvalidArgumentException('Unverified company record.');}
 private static function key($v){return is_string($v)&&preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$v)===1;}
 /** Provider status is evidence, never a claim that a queue or send operation succeeded. */
 public static function outcome($company,$call,$events,$ctx){
  self::scope($company,$ctx);self::owned($company,$call);
  if(!self::key($call['call_key']??null)||!is_array($events)||count($events)>500)throw new InvalidArgumentException('Invalid call events.');
  $allowed=['received','ringing','answered','missed','abandoned','completed','failed'];$unique=[];$last=null;
  foreach($events as $e){self::owned($company,$e);
   if(($e['call_key']??null)!==$call['call_key']||!self::key($e['event_key']??null)||($e['provider_authenticated']??null)!==true||!in_array($e['state']??null,$allowed,true)||!is_int($e['occurred_at_utc']??null)||$e['occurred_at_utc']<1||$e['occurred_at_utc']>$ctx['now_utc'])throw new InvalidArgumentException('Unverified provider event.');
   if(isset($unique[$e['event_key']])){if($unique[$e['event_key']]!==[$e['state'],$e['occurred_at_utc']])throw new InvalidArgumentException('Conflicting duplicate event.');continue;}
   $unique[$e['event_key']]=[$e['state'],$e['occurred_at_utc']];
   if($last===null||$e['occurred_at_utc']>$last['time']||($e['occurred_at_utc']===$last['time']&&strcmp($e['event_key'],$last['key'])>0))$last=['state'=>$e['state'],'time'=>$e['occurred_at_utc'],'key'=>$e['event_key']];
  }
  $status=$last['state']??'unknown';$terminal=in_array($status,['missed','abandoned','completed','failed'],true);
  return ['company_id'=>$company,'call_key'=>$call['call_key'],'observed_outcome'=>$status,'terminal_observed'=>$terminal,'verified_events'=>count($unique),'read_only'=>true];
 }
 /** Risk signals must be computed server-side from authenticated provider metadata. This never blocks emergency routing. */
 public static function screen($company,$call,$signals,$policy,$ctx){
  self::scope($company,$ctx);self::owned($company,$call);self::owned($company,$signals);self::owned($company,$policy);
  if(!self::key($call['call_key']??null)||($signals['provider_authenticated']??null)!==true||($policy['approved']??null)!==true)throw new InvalidArgumentException('Unverified screening source.');
  foreach(['calls_last_hour','duplicate_attempts'] as $k)if(!is_int($signals[$k]??null)||$signals[$k]<0||$signals[$k]>100000)throw new InvalidArgumentException('Invalid signal.');
  foreach(['max_calls_per_hour','max_duplicate_attempts'] as $k)if(!is_int($policy[$k]??null)||$policy[$k]<1||$policy[$k]>100000)throw new InvalidArgumentException('Invalid screening rule.');
  if(!in_array($call['operational_category']??null,['emergency','booking','callback','routine','unknown'],true)||($call['category_verified']??null)!==true)throw new InvalidArgumentException('Unverified call category.');
  $flags=[];if($signals['calls_last_hour']>$policy['max_calls_per_hour'])$flags[]='call_rate';if($signals['duplicate_attempts']>$policy['max_duplicate_attempts'])$flags[]='repeated_attempts';
  $action=$call['operational_category']==='emergency'?'prioritise_human_escalation':($flags?'propose_screening_review':'normal_route');
  return ['company_id'=>$company,'call_key'=>$call['call_key'],'action'=>$action,'flags'=>$flags,'blocking_authorised'=>false,'read_only'=>true];
 }
 /** Creates a minimal transfer/reconnect summary from an approved verified call snapshot, never raw transcripts. */
 public static function continuity($company,$call,$handoff,$ctx){
  self::scope($company,$ctx);self::owned($company,$call);self::owned($company,$handoff);
  if(!self::key($call['call_key']??null)||($handoff['call_key']??null)!==$call['call_key']||($handoff['transfer_authorised']??null)!==true||!self::key($handoff['handoff_key']??null)||!in_array($handoff['intent']??null,['new_booking','reschedule','cancel','callback','unknown'],true)||!in_array($handoff['channel']??null,['transfer','reconnect'],true)||!is_int($handoff['expires_at_utc']??null)||$handoff['expires_at_utc']<=$ctx['now_utc']||$handoff['expires_at_utc']>$ctx['now_utc']+3600)throw new InvalidArgumentException('Invalid handoff.');
  $booking=null;if(array_key_exists('booking_id',$handoff)){if(!is_int($handoff['booking_id'])||$handoff['booking_id']<1||($handoff['booking_owner_verified']??null)!==true)throw new InvalidArgumentException('Unverified booking reference.');$booking=$handoff['booking_id'];}
  return ['company_id'=>$company,'call_key'=>$call['call_key'],'handoff_key'=>$handoff['handoff_key'],'intent'=>$handoff['intent'],'channel'=>$handoff['channel'],'booking_id'=>$booking,'expires_at_utc'=>$handoff['expires_at_utc'],'read_only'=>true,'requires_authenticated_receiver'=>true];
 }
}
