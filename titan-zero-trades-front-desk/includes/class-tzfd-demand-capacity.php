<?php
/** Pass 32: deterministic, read-only demand forecasting and capacity balancing. */
if (!defined('ABSPATH')) exit;
final class TZFD_Demand_Capacity {
 private static function scope($company,$ctx){
  if (!is_int($company)||$company<1||!is_array($ctx)||($ctx['verified_company_id']??null)!==$company||($ctx['snapshot_authoritative']??null)!==true||($ctx['snapshot_complete']??null)!==true) throw new InvalidArgumentException('Authoritative company scope required.');
 }
 private static function records($company,$records,$limit=2000){
  if (!is_array($records)||count($records)>$limit)throw new InvalidArgumentException('Invalid dataset size.');
  $seen=[];$out=[];
  foreach ($records as $r){
   if(!is_array($r)||($r['company_id']??null)!==$company||($r['owner_verified']??null)!==true||!is_string($r['period_key']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}$/D',$r['period_key'])||!is_string($r['record_key']??null)||!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$r['record_key'])||!is_int($r['count']??null)||$r['count']<0||$r['count']>100000)throw new InvalidArgumentException('Invalid or unverified history.');
   if(isset($seen[$r['record_key']]))throw new InvalidArgumentException('Duplicate history record.');
   $seen[$r['record_key']]=true;$out[]=$r;
  }
  return $out;
 }
 /** Forecast hourly demand from bounded, verified same-hour history. Explicitly no invented confidence. */
 public static function forecast($company,$kind,$records,$period,$ctx){
  self::scope($company,$ctx);
  if(!in_array($kind,['bookings','calls'],true)||!is_string($period)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}$/D',$period))throw new InvalidArgumentException('Invalid forecast request.');
  $history=self::records($company,$records);$values=[];
  foreach($history as $r){
   if(($r['kind']??null)!==$kind)throw new InvalidArgumentException('Mixed kind dataset.');
   if(substr($r['period_key'],11,2)===substr($period,11,2)&&$r['period_key']<$period)$values[]=$r['count'];
  }
  if(count($values)<3)return ['company_id'=>$company,'kind'=>$kind,'period_key'=>$period,'status'=>'insufficient_history','observations'=>count($values),'read_only'=>true];
  $avg=array_sum($values)/count($values);
  return ['company_id'=>$company,'kind'=>$kind,'period_key'=>$period,'status'=>'forecast','expected_count'=>(int)round($avg),'observations'=>count($values),'method'=>'same_hour_arithmetic_mean','read_only'=>true];
 }
 /** Rank capacity only; never mutate staff roster, booking or business hours. */
 public static function balance($company,$slots,$ctx){
  self::scope($company,$ctx);
  if(!is_array($slots)||count($slots)>500)throw new InvalidArgumentException('Invalid capacity snapshot.');
  $seen=[];$ranked=[];
  foreach($slots as $s){
   if(!is_array($s)||($s['company_id']??null)!==$company||($s['owner_verified']??null)!==true||($s['availability_verified']??null)!==true||!is_string($s['slot_key']??null)||!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$s['slot_key'])||!is_int($s['capacity']??null)||$s['capacity']<1||$s['capacity']>100000||!is_int($s['assigned']??null)||$s['assigned']<0||$s['assigned']>$s['capacity']||!is_int($s['forecast_demand']??null)||$s['forecast_demand']<0||$s['forecast_demand']>100000)throw new InvalidArgumentException('Unverified capacity.');
   if(isset($seen[$s['slot_key']]))throw new InvalidArgumentException('Duplicate slot.');$seen[$s['slot_key']]=true;
   $free=$s['capacity']-$s['assigned'];$short=max(0,$s['forecast_demand']-$free);
   $ranked[]=['slot_key'=>$s['slot_key'],'free_capacity'=>$free,'forecast_shortfall'=>$short,'utilisation'=>round($s['assigned']/$s['capacity'],4)];
  }
  usort($ranked,static function($a,$b){return ($b['forecast_shortfall']<=>$a['forecast_shortfall'])?:strcmp($a['slot_key'],$b['slot_key']);});
  return ['company_id'=>$company,'ranked_capacity'=>$ranked,'read_only'=>true,'requires_human_roster_approval'=>true];
 }
}
