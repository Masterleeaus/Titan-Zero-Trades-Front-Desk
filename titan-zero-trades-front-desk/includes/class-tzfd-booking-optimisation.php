<?php
/** Pass 26: pure deterministic scheduling recommendations; no booking side effects. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
final class TZFD_Booking_Optimisation {
 private static function scope($company,$context) {
  if (!is_int($company) || $company<1 || !is_array($context) || ($context['verified_company_id']??null)!==$company || ($context['snapshot_authoritative']??false)!==true || ($context['snapshot_complete']??false)!==true) throw new InvalidArgumentException('Authoritative, complete, company-scoped snapshot required.');
 }
 private static function instant($s) {
  if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$s)) throw new InvalidArgumentException('UTC timestamp required.');
  $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$s,new DateTimeZone('UTC'));
  if (!$d || $d->format('Y-m-d\TH:i:s\Z')!==$s) throw new InvalidArgumentException('Invalid UTC timestamp.');
  return $d->getTimestamp();
 }
 private static function row($company,$row) {
  if (!is_array($row) || ($row['company_id']??null)!==$company || ($row['owner_verified']??null)!==true) throw new InvalidArgumentException('Unverified or cross-company record.');
 }
 /** Produce a bounded UTC recurring schedule; each occurrence requires authoritative validation and confirmation. */
 public static function recurring($company,$request,$context) {
  self::scope($company,$context);self::row($company,$request);
  $interval=$request['interval_days']??null;$count=$request['occurrences']??null;
  if (!is_int($interval)||$interval<1||$interval>365||!is_int($count)||$count<1||$count>52) throw new InvalidArgumentException('Invalid recurrence configuration.');
  $start=self::instant($request['start_utc']??null);
  $duration=$request['duration_minutes']??null;
  if (!is_int($duration)||$duration<1||$duration>1440) throw new InvalidArgumentException('Invalid duration.');
  $exceptions=$request['excluded_dates_utc']??[];
  if (!is_array($exceptions)||count($exceptions)>366) throw new InvalidArgumentException('Invalid exclusions.');
  $excluded=[];foreach($exceptions as $date){if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||gmdate('Y-m-d',strtotime($date.' UTC'))!==$date)throw new InvalidArgumentException('Invalid excluded date.');$excluded[$date]=true;}
  $out=[];for($i=0;$i<$count;$i++){
   $at=$start+$interval*86400*$i;
   if($at<$start||$at>$start+366*86400)throw new InvalidArgumentException('Recurrence exceeds one-year horizon.');
   $date=gmdate('Y-m-d',$at);if(isset($excluded[$date]))continue;
   $out[]=['start_utc'=>gmdate('Y-m-d\TH:i:s\Z',$at),'end_utc'=>gmdate('Y-m-d\TH:i:s\Z',$at+$duration*60),'requires_availability_check'=>true];
  }
  return ['company_id'=>$company,'proposed_occurrences'=>$out,'read_only'=>true,'committed'=>false,'timezone_policy'=>'UTC fixed interval; local-wall-clock recurrences require owner calendar adapter'];
 }
 /** Rank owner-verified available slots by remaining neighboring free gap, never creates bookings. */
 public static function gaps($company,$slots,$context) {
  self::scope($company,$context);
  if (!is_array($slots)||count($slots)>200)throw new InvalidArgumentException('Invalid slot set.');
  $out=[];$seen=[];
  foreach($slots as $slot){
   self::row($company,$slot);
   if(($slot['available']??null)!==true)throw new InvalidArgumentException('Slot not available.');
   $id=$slot['slot_id']??null;
   if(!is_string($id)||$id===''||isset($seen[$id]))throw new InvalidArgumentException('Duplicate slot.');$seen[$id]=true;
   $start=self::instant($slot['start_utc']??null);$end=self::instant($slot['end_utc']??null);
   $left=self::instant($slot['window_start_utc']??null);$right=self::instant($slot['window_end_utc']??null);
   if($left>$start||$end>$right||$end<=$start||$right-$left>86400)throw new InvalidArgumentException('Invalid free window.');
   $waste=($start-$left)+($right-$end);
   $out[]=['slot_id'=>$id,'start_utc'=>$slot['start_utc'],'unfilled_gap_minutes'=>intdiv($waste,60),'requires_revalidation'=>true];
  }
  usort($out,static fn($a,$b)=>($a['unfilled_gap_minutes']<=>$b['unfilled_gap_minutes'])?:strcmp($a['start_utc'],$b['start_utc'])?:strcmp($a['slot_id'],$b['slot_id']));
  return ['company_id'=>$company,'ranked_slots'=>$out,'read_only'=>true];
 }
 /** Check explicit booking prerequisites against owner-verified fulfilment, never collects payments. */
 public static function dependencies($company,$service,$fulfilled,$context) {
  self::scope($company,$context);self::row($company,$service);
  $required=$service['required_service_ids']??null;
  if(!is_array($required)||count($required)>100||!is_array($fulfilled)||count($fulfilled)>1000)throw new InvalidArgumentException('Invalid dependency snapshot.');
  $have=[];foreach($fulfilled as $record){self::row($company,$record);if(!is_int($record['service_id']??null)||$record['service_id']<1||($record['completed']??null)!==true)throw new InvalidArgumentException('Unverified completion.');$have[$record['service_id']]=true;}
  $missing=[];$seen=[];
  foreach($required as $id){if(!is_int($id)||$id<1||isset($seen[$id]))throw new InvalidArgumentException('Invalid dependency ID.');$seen[$id]=true;if(!isset($have[$id]))$missing[]=$id;}
  return ['company_id'=>$company,'eligible'=>count($missing)===0,'missing_service_ids'=>$missing,'read_only'=>true,'requires_policy_revalidation'=>true];
 }
}
