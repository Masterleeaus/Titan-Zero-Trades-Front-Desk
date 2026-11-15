<?php
/** Pass 54: authorised due-reminder producer; never mutates a booking or sends messages itself. */
if (!defined('ABSPATH')) exit;
final class TZFD_Booking_Reminder_Bridge {
    /** Evaluate complete, authoritative booking facts. Fail closed on incomplete data. */
    public static function due($booking,$recipient,$minutes,$now,$grace=300) {
        if (!is_array($booking) || !is_int($recipient) || $recipient<1 || !is_int($minutes) ||
            $minutes<1 || $minutes>43200 || !is_int($now) || !is_int($grace) || $grace<0 || $grace>900 ||
            !is_int($booking['company_id']??null) || $booking['company_id']<1 ||
            ($booking['verified_company_id']??null)!==$booking['company_id'] ||
            true !== ($booking['authoritative'] ?? null) || !is_int($booking['booking_id']??null) || $booking['booking_id']<1 ||
            !is_int($booking['starts_at_utc']??null) || ($booking['status']??null)!=='approved' ||
            true !== ($booking['contact_opt_in'] ?? null) || ($booking['recipient_id'] ?? null) !== $recipient) return false;
        $at=$booking['starts_at_utc']-$minutes*60;
        if ($now<$at || $now>$at+$grace || $now>=$booking['starts_at_utc']) return false;
        return array('company_id'=>$booking['company_id'],'booking_id'=>$booking['booking_id'],
            'recipient_id'=>$recipient,'action'=>'reminder',
            'event'=>'reminder-v1:'.hash('sha256',implode('|',array($booking['company_id'],$booking['booking_id'],$booking['starts_at_utc'],$minutes,$recipient))));
    }
    /** Caller must use server-verified facts. Queue enforces ownership, recipient consent and policy again. */
    public static function queue_due($booking,$recipient,$minutes,$now,$grace=300) {
        $due=self::due($booking,$recipient,$minutes,$now,$grace);
        if (false===$due) return false;
        return TZFD_Booking_Action_Outbox::enqueue($due['company_id'],$due['booking_id'],
            $due['action'],$due['recipient_id'],$due['event']);
    }
}
