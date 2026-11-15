<?php
/** Pass 28: booking draft validation and signed recovery decisions. No database writes. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
final class TZFD_Booking_Validation_Recovery {
 private static function scope($company, $context) {
  if (!is_int($company) || $company < 1 || !is_array($context) || ($context['verified_company_id'] ?? null) !== $company || ($context['snapshot_authoritative'] ?? null) !== true)
   throw new InvalidArgumentException('Verified company context required.');
 }
 private static function timestamp($value) {
  if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) throw new InvalidArgumentException('UTC timestamp required.');
  $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
  if (!$dt || $dt->format('Y-m-d\TH:i:s\Z') !== $value) throw new InvalidArgumentException('Invalid UTC timestamp.');
  return $dt->getTimestamp();
 }
 /** Validate a draft against a server-owned policy snapshot; never commits a booking. */
 public static function validate($company, $draft, $policy, $context) {
  self::scope($company,$context);
  if (!is_array($draft) || !is_array($policy) || ($policy['company_id'] ?? null) !== $company || ($policy['owner_verified'] ?? null) !== true || ($context['snapshot_complete'] ?? null) !== true) throw new InvalidArgumentException('Authoritative policy snapshot required.');
  $allowed=['company_id','service_id','site_id','staff_id','start_utc','end_utc','request_id'];
  if (array_diff(array_keys($draft),$allowed)) throw new InvalidArgumentException('Unexpected draft fields.');
  if (($draft['company_id'] ?? null) !== $company) throw new InvalidArgumentException('Cross-company booking rejected.');
  foreach (['service_id','site_id'] as $k) if (!is_int($draft[$k] ?? null) || $draft[$k] < 1) throw new InvalidArgumentException('Invalid booking identifier.');
  if (isset($draft['staff_id']) && (!is_int($draft['staff_id']) || $draft['staff_id'] < 1)) throw new InvalidArgumentException('Invalid staff identifier.');
  $id=$draft['request_id']??null;
  if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{8,96}$/D',$id)) throw new InvalidArgumentException('Invalid request ID.');
  $a=self::timestamp($draft['start_utc'] ?? null);$b=self::timestamp($draft['end_utc'] ?? null);
  if ($b<=$a || $b-$a>86400) throw new InvalidArgumentException('Invalid booking interval.');
  $reasons=[];
  if (!in_array($draft['service_id'],$policy['service_ids']??[],true))$reasons[]='service_unavailable';
  if (!in_array($draft['site_id'],$policy['site_ids']??[],true))$reasons[]='site_unavailable';
  if (($policy['minimum_notice_seconds']??0)>0 && $a < ($context['now_utc']??0)+$policy['minimum_notice_seconds'])$reasons[]='insufficient_notice';
  if (($policy['maximum_duration_minutes']??1440)*60 < $b-$a)$reasons[]='duration_exceeds_policy';
  if (($context['now_utc']??null)===null || !is_int($context['now_utc'])) throw new InvalidArgumentException('Server time required.');
  return ['company_id'=>$company,'request_id'=>$id,'valid'=>!$reasons,'reasons'=>$reasons,'requires_atomic_revalidation'=>true,'read_only'=>true];
 }
 /** Produce signed opaque recovery proof. Storage and token delivery are separate responsibilities. */
 public static function issue($company,$sessionId,$draftHash,$now,$ttl,$secret,$context) {
  self::scope($company,$context);
  if (!is_string($sessionId)||!preg_match('/^[a-zA-Z0-9_-]{16,128}$/D',$sessionId)||!is_string($draftHash)||!preg_match('/^[a-f0-9]{64}$/D',$draftHash)||!is_int($now)||$now<1||!is_int($ttl)||$ttl<60||$ttl>3600||!is_string($secret)||strlen($secret)<32)throw new InvalidArgumentException('Invalid session token parameters.');
  $expires=$now+$ttl;
  $payload=$company.':'.$sessionId.':'.$draftHash.':'.$expires;
  return ['token'=>rtrim(strtr(base64_encode($payload),'+/','-_'),'=').'.'.hash_hmac('sha256',$payload,$secret),'expires_at'=>$expires,'read_only'=>true];
 }
 /** Require an authenticated server-side draft snapshot, matching signature/hash/company and unexpired token. */
 public static function recover($company,$token,$stored,$now,$secret,$context) {
  self::scope($company,$context);
  if (!is_string($token)||strlen($token)>512||!is_array($stored)||($stored['company_id']??null)!==$company||($stored['owner_verified']??null)!==true||!is_int($now)||!is_string($secret)||strlen($secret)<32)throw new InvalidArgumentException('Invalid recovery inputs.');
  $parts=explode('.',$token);
  if (count($parts)!==2||!preg_match('/^[A-Za-z0-9_-]+$/D',$parts[0])||!preg_match('/^[a-f0-9]{64}$/D',$parts[1]))throw new InvalidArgumentException('Invalid recovery token.');
  $raw=base64_decode(strtr($parts[0],'-_','+/'),true);
  if ($raw===false||!hash_equals(hash_hmac('sha256',$raw,$secret),$parts[1]))throw new InvalidArgumentException('Invalid recovery signature.');
  $values=explode(':',$raw);
  if(count($values)!==4||!ctype_digit($values[0])||(int)$values[0]!==$company||!ctype_digit($values[3])||$now>=(int)$values[3]||($stored['session_id']??null)!==$values[1]||($stored['draft_hash']??null)!==$values[2])throw new InvalidArgumentException('Expired or mismatched session.');
  if (($stored['expires_at']??null)!==(int)$values[3]||($stored['revoked']??false)===true)throw new InvalidArgumentException('Session revoked or stale.');
  $draft=$stored['draft']??null;
  if (!is_array($draft) || ($draft['company_id']??null)!==$company)throw new InvalidArgumentException('Draft scope invalid.');
  return ['company_id'=>$company,'draft'=>$draft,'requires_fresh_policy_and_availability_validation'=>true,'booking_confirmed'=>false,'read_only'=>true];
 }
}
