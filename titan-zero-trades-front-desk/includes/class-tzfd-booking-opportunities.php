<?php
/** Pass 25 deterministic booking opportunities. Decisions only; never commits reservations. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
final class TZFD_Booking_Opportunities {
    private static function scope($company, $context) {
        if (!is_int($company) || $company<1 || !is_array($context) || ($context['verified_company_id'] ?? null)!==$company || empty($context['snapshot_complete']) || empty($context['snapshot_authoritative'])) {
            throw new InvalidArgumentException('Verified company scope and authoritative complete snapshot required.');
        }
    }
    private static function id($value) { return is_int($value) && $value>0; }
    /** Structured intent only. Free text is never guessed as a confirmed instruction. */
    public static function intent($company, $request, $context) {
        self::scope($company,$context);
        if (!is_array($request) || ($request['company_id'] ?? null)!==$company) throw new InvalidArgumentException('Invalid intent request.');
        $actions=['new_booking','reschedule','cancel','waitlist','check_availability'];
        $action=$request['action'] ?? null;
        $recognized=is_string($action) && in_array($action,$actions,true) && !empty($request['verified_structured_input']);
        if ($recognized && in_array($action,['reschedule','cancel'],true) && !self::id($request['verified_booking_id'] ?? null)) $recognized=false;
        return ['company_id'=>$company,'intent'=>$recognized?$action:'manual_review','confidence'=>$recognized?1.0:0.0,'read_only'=>true,'requires_confirmation'=>true];
    }
    /** Deterministically sorts owner-verified available slots. */
    public static function rank_slots($company,$slots,$context) {
        self::scope($company,$context);
        if (!is_array($slots) || count($slots)>100) throw new InvalidArgumentException('Invalid slot snapshot.');
        $out=[];$seen=[];
        foreach($slots as $slot) {
            if (!is_array($slot) || ($slot['company_id']??null)!==$company || empty($slot['owner_verified']) || ($slot['available']??null)!==true || !isset($slot['start']) || !is_string($slot['start'])) throw new InvalidArgumentException('Unverified slot.');
            $at=strtotime($slot['start']);
            if ($at===false || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',$slot['start']) || gmdate('Y-m-d\TH:i:s\Z',$at)!==$slot['start']) throw new InvalidArgumentException('Invalid UTC slot.');
            $id=$slot['slot_id']??null;
            if (!is_string($id) || $id==='' || isset($seen[$id])) throw new InvalidArgumentException('Duplicate or invalid slot ID.');
            $seen[$id]=true;
            $util=$slot['utilisation_score']??0;
            if (!is_int($util) || $util<0 || $util>100) throw new InvalidArgumentException('Invalid utilisation score.');
            $slot['rank_score']=$util; $out[]=$slot;
        }
        usort($out,static function($a,$b){$cmp=$b['rank_score']<=>$a['rank_score']; if($cmp)return $cmp; $cmp=strcmp($a['start'],$b['start']);return $cmp?:strcmp($a['slot_id'],$b['slot_id']);});
        return ['company_id'=>$company,'slots'=>$out,'read_only'=>true,'requires_revalidation'=>true];
    }
    /** Release opportunities on verified cancellation, then recommend eligible waitlist entries without sending messages. */
    public static function cancellation_recovery($company,$cancellation,$waitlist,$context) {
        self::scope($company,$context);
        if (!is_array($cancellation) || ($cancellation['company_id']??null)!==$company || empty($cancellation['owner_verified']) || ($cancellation['status']??null)!=='cancelled' || !self::id($cancellation['booking_id']??null) || !self::id($cancellation['service_id']??null) || !self::id($cancellation['location_id']??null)) throw new InvalidArgumentException('Invalid cancellation.');
        if (!is_array($waitlist) || count($waitlist)>1000) throw new InvalidArgumentException('Invalid waitlist snapshot.');
        $out=[];$seen=[];
        foreach($waitlist as $entry) {
            if (!is_array($entry) || ($entry['company_id']??null)!==$company || empty($entry['owner_verified']) || !self::id($entry['entry_id']??null)) throw new InvalidArgumentException('Unverified waitlist entry.');
            $id=$entry['entry_id'];if(isset($seen[$id]))throw new InvalidArgumentException('Duplicate waitlist entry.');$seen[$id]=true;
            if (($entry['status']??null)!=='waiting' || empty($entry['contact_opt_in']) || ($entry['service_id']??null)!==$cancellation['service_id'] || ($entry['location_id']??null)!==$cancellation['location_id'])continue;
            $priority=$entry['priority']??0;if(!is_int($priority)||$priority<0||$priority>100)throw new InvalidArgumentException('Invalid waitlist priority.');
            $out[]=['entry_id'=>$id,'priority'=>$priority,'joined_at'=>$entry['joined_at']??''];
        }
        usort($out,static function($a,$b){$c=$b['priority']<=>$a['priority'];return $c?:strcmp($a['joined_at'],$b['joined_at']);});
        return ['company_id'=>$company,'booking_id'=>$cancellation['booking_id'],'eligible_waitlist'=>$out,'read_only'=>true,'offers_sent'=>false,'reservation_created'=>false,'requires_atomic_hold'=>true];
    }
}
