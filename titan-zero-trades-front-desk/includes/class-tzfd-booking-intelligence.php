<?php
/** Pass 27: explainable booking recommendations, no persistent side effects. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
final class TZFD_Booking_Intelligence {
 private static function scope($company,$context) {
  if (!is_int($company)||$company<1||!is_array($context)||($context['verified_company_id']??null)!==$company||($context['snapshot_authoritative']??null)!==true||($context['snapshot_complete']??null)!==true) throw new InvalidArgumentException('Verified complete company snapshot required.');
 }
 private static function row($company,$row) {
  if (!is_array($row)||($row['company_id']??null)!==$company||($row['owner_verified']??null)!==true)throw new InvalidArgumentException('Unverified or cross-company record.');
 }
 private static function time($value) {
  if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value))throw new InvalidArgumentException('UTC timestamp required.');
  $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
  if(!$d||$d->format('Y-m-d\TH:i:s\Z')!==$value)throw new InvalidArgumentException('Invalid timestamp.');
  return $d->getTimestamp();
 }
 /** Median actual duration of service- and site-matched, owner-verified completed appointments. */
 public static function duration($company,$serviceId,$siteId,$history,$context) {
  self::scope($company,$context);
  if(!is_int($serviceId)||$serviceId<1||!is_int($siteId)||$siteId<1||!is_array($history)||count($history)>2000)throw new InvalidArgumentException('Invalid duration input.');
  $minutes=[];$seen=[];
  foreach($history as $record){self::row($company,$record);$id=$record['booking_id']??null;if(!is_string($id)&&!is_int($id))throw new InvalidArgumentException('Missing booking identifier.');$id=(string)$id;if($id===''||isset($seen[$id]))throw new InvalidArgumentException('Duplicate booking history.');$seen[$id]=true;
   if(($record['service_id']??null)!==$serviceId||($record['site_id']??null)!==$siteId||($record['completed']??null)!==true)continue;
   $n=$record['actual_duration_minutes']??null;if(!is_int($n)||$n<1||$n>1440)throw new InvalidArgumentException('Invalid recorded duration.');$minutes[]=$n;
  }
  sort($minutes,SORT_NUMERIC);$count=count($minutes);
  if($count<5)return ['company_id'=>$company,'recommendation_available'=>false,'sample_size'=>$count,'reason'=>'insufficient_matching_history','read_only'=>true];
  $middle=intdiv($count,2);$median=$count%2?$minutes[$middle]:intdiv($minutes[$middle-1]+$minutes[$middle],2);
  return ['company_id'=>$company,'recommendation_available'=>true,'suggested_duration_minutes'=>$median,'sample_size'=>$count,'method'=>'historical_median','requires_policy_approval'=>true,'read_only'=>true];
 }
 /** Detect conflicts in a complete snapshot. Does not select a winner or override holds. */
 public static function conflicts($company,$candidate,$reservations,$context) {
  self::scope($company,$context);self::row($company,$candidate);
  if(!is_array($reservations)||count($reservations)>2000)throw new InvalidArgumentException('Invalid reservation snapshot.');
  $start=self::time($candidate['start_utc']??null);$end=self::time($candidate['end_utc']??null);
  if($end<=$start||$end-$start>86400||!is_int($candidate['staff_id']??null)||!is_int($candidate['site_id']??null)||!is_int($candidate['resource_id']??null))throw new InvalidArgumentException('Invalid candidate.');
  $hits=[];$seen=[];
  foreach($reservations as $r){self::row($company,$r);$id=$r['reservation_id']??null;if(!is_string($id)||$id===''||isset($seen[$id]))throw new InvalidArgumentException('Duplicate reservation identifier.');$seen[$id]=true;
   $a=self::time($r['start_utc']??null);$b=self::time($r['end_utc']??null);if($b<=$a)throw new InvalidArgumentException('Invalid reservation interval.');
   if(!in_array($r['status']??null,['confirmed','active_hold'],true))continue;
   if($start<$b&&$a<$end){$reasons=[];if(($r['staff_id']??null)===$candidate['staff_id'])$reasons[]='staff_overlap';if(($r['site_id']??null)===$candidate['site_id']&&($r['resource_id']??null)===$candidate['resource_id'])$reasons[]='resource_overlap';if($reasons)$hits[]=['reservation_id'=>$id,'reasons'=>$reasons];}
  }
  return ['company_id'=>$company,'conflict_free'=>count($hits)===0,'conflicts'=>$hits,'requires_atomic_revalidation'=>true,'read_only'=>true];
 }
 /** Rank prevalidated slots against explicitly consented preferences. */
 public static function preferences($company,$customer,$slots,$context) {
  self::scope($company,$context);self::row($company,$customer);
  if(!is_array($slots)||count($slots)>200)throw new InvalidArgumentException('Invalid slots.');
  if(($customer['preference_consent']??false)!==true)return ['company_id'=>$company,'ranked_slots'=>[],'reason'=>'no_preference_consent','read_only'=>true];
  $staff=$customer['preferred_staff_id']??null;$site=$customer['preferred_site_id']??null;
  if($staff!==null&&(!is_int($staff)||$staff<1)||$site!==null&&(!is_int($site)||$site<1))throw new InvalidArgumentException('Invalid preferences.');
  $out=[];$seen=[];
  foreach($slots as $slot){self::row($company,$slot);if(($slot['available']??null)!==true)throw new InvalidArgumentException('Non-available slot.');$id=$slot['slot_id']??null;if(!is_string($id)||$id===''||isset($seen[$id]))throw new InvalidArgumentException('Invalid slot ID.');$seen[$id]=true;self::time($slot['start_utc']??null);
   $score=0;$reasons=[];if($staff!==null&&($slot['staff_id']??null)===$staff){$score+=2;$reasons[]='preferred_staff';}if($site!==null&&($slot['site_id']??null)===$site){$score++;$reasons[]='preferred_site';}
   $out[]=['slot_id'=>$id,'start_utc'=>$slot['start_utc'],'match_score'=>$score,'reasons'=>$reasons,'requires_revalidation'=>true];
  }
  usort($out,static fn($a,$b)=>($b['match_score']<=>$a['match_score'])?:strcmp($a['start_utc'],$b['start_utc'])?:strcmp($a['slot_id'],$b['slot_id']));
  return ['company_id'=>$company,'ranked_slots'=>$out,'read_only'=>true];
 }
}
