<?php
/** Pass 29: deterministic telephone identity, repetition and callback planning. GPL-2.0-only.
 * Decision-only: adapters must authenticate provider events, verify Workspace ownership,
 * persist dedupe keys, enforce consent and commit callbacks transactionally.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_Caller_Automation {
 private static function scope($company,$context) {
  if (!is_int($company)||$company<1||!is_array($context)||($context['verified_company_id']??null)!==$company||($context['snapshot_authoritative']??null)!==true||($context['snapshot_complete']??null)!==true||!is_int($context['now_utc']??null)||$context['now_utc']<1) throw new InvalidArgumentException('Verified company snapshot required.');
 }
 private static function key($key) {return is_string($key)&&preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$key);}
 /** Resolve only independently verified Workspace customer IDs. Never resolve identity merely by caller ID. */
 public static function identity($company,$call,$matches,$context) {
  self::scope($company,$context);
  if (!is_array($call)||($call['company_id']??null)!==$company||!self::key($call['call_key']??null)||!is_array($matches))throw new InvalidArgumentException('Invalid call scope.');
  $verified=[];
  foreach($matches as $m){
   if(!is_array($m)||($m['company_id']??null)!==$company||($m['owner_verified']??null)!==true||!is_int($m['workspace_customer_id']??null)||$m['workspace_customer_id']<1)throw new InvalidArgumentException('Unverified customer candidate.');
   // A phone-number match is a *candidate*, not an authentication factor.
   if(($m['identity_verified']??null)===true)$verified[$m['workspace_customer_id']]=true;
  }
  return ['company_id'=>$company,'call_key'=>$call['call_key'],'status'=>count($verified)===1?'verified':(count($verified)>1?'ambiguous':'unverified'),'workspace_customer_id'=>count($verified)===1?(int)array_key_first($verified):null,'read_only'=>true];
 }
 /** Detect repeat calls using authenticated provider-produced opaque caller key; no phone or transcript output. */
 public static function repeats($company,$callerKey,$events,$windowSeconds,$context){
  self::scope($company,$context);
  if(!self::key($callerKey)||!is_array($events)||!is_int($windowSeconds)||$windowSeconds<60||$windowSeconds>604800)throw new InvalidArgumentException('Invalid repeat-call parameters.');
  $seen=[];$now=$context['now_utc'];
  foreach($events as $e){
   if(!is_array($e)||($e['company_id']??null)!==$company||($e['owner_verified']??null)!==true)throw new InvalidArgumentException('Unverified call history.');
   if(!self::key($e['event_key']??null)||!self::key($e['caller_key']??null)||!is_int($e['occurred_at']??null))throw new InvalidArgumentException('Invalid call event.');
   if($e['caller_key']===$callerKey&&$e['occurred_at']<=$now&&$e['occurred_at']>=$now-$windowSeconds)$seen[$e['event_key']]=true;
  }
  return ['company_id'=>$company,'distinct_calls'=>count($seen),'repeat_caller'=>count($seen)>1,'dedupe_callback_recommended'=>count($seen)>1,'read_only'=>true];
 }
 /** Suggest next callback at/after now using trusted UTC availability intervals; never creates or delivers callback. */
 public static function callback($company,$call,$windows,$existing,$context){
  self::scope($company,$context);
  if(!is_array($call)||($call['company_id']??null)!==$company||!self::key($call['call_key']??null)||!in_array($call['status']??null,['missed','abandoned','callback_requested'],true)||!is_array($windows)||!is_array($existing))throw new InvalidArgumentException('Invalid callback request.');
  if(($call['contact_permitted']??null)!==true)return ['company_id'=>$company,'status'=>'blocked_consent','read_only'=>true];
  foreach($existing as $task){
   if(!is_array($task)||($task['company_id']??null)!==$company||($task['owner_verified']??null)!==true)throw new InvalidArgumentException('Unverified callback state.');
   if(($task['call_key']??null)===$call['call_key']&&in_array($task['status']??null,['queued','processing','completed'],true))return ['company_id'=>$company,'status'=>'existing_callback','read_only'=>true];
  }
  $next=null;
  foreach($windows as $w){
   if(!is_array($w)||($w['company_id']??null)!==$company||($w['owner_verified']??null)!==true||!is_int($w['start_utc']??null)||!is_int($w['end_utc']??null)||$w['end_utc']<=$w['start_utc'])throw new InvalidArgumentException('Unverified callback window.');
   $candidate=max($context['now_utc'],$w['start_utc']);
   if($candidate<$w['end_utc']&&($next===null||$candidate<$next))$next=$candidate;
  }
  if($next===null)return ['company_id'=>$company,'status'=>'no_window','read_only'=>true];
  return ['company_id'=>$company,'status'=>'proposed','callback_at_utc'=>$next,'dedupe_key'=>hash('sha256',$company.':'.$call['call_key'].':callback'),'requires_persistent_idempotent_commit'=>true,'read_only'=>true];
 }
}
