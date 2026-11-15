<?php
/**
 * Booking-only two-way reminder and rescheduling decision engine.
 * Pure decision code: NO messages are sent and NO booking state is mutated.
 * The caller must supply authoritative, complete company-scoped booking facts.
 * GPL-2.0-only.
 */
if (!defined('ABSPATH')) exit;
final class TZFD_Two_Way_Booking {
    const REPLIES = ['confirm'=>'confirmed','yes'=>'confirmed','1'=>'confirmed','cancel'=>'cancel_requested','no'=>'cancel_requested','2'=>'cancel_requested','reschedule'=>'reschedule_requested','change'=>'reschedule_requested','3'=>'reschedule_requested'];
    private static function scope($booking) {
        if (!is_array($booking) || !isset($booking['company_id']) || !is_int($booking['company_id']) || $booking['company_id'] < 1 ||
            ($booking['verified_company_id'] ?? null) !== $booking['company_id'] || empty($booking['authoritative']) ||
            !isset($booking['booking_id']) || !is_int($booking['booking_id']) || $booking['booking_id'] < 1 ||
            !isset($booking['starts_at_utc']) || !is_int($booking['starts_at_utc']) ||
            !in_array($booking['status'] ?? null, ['approved','pending','cancelled','completed'], true)) {
            throw new InvalidArgumentException('Authoritative, company-scoped booking facts required.');
        }
    }
    public static function reminders($booking, $policy, $now) {
        self::scope($booking);
        if (!is_array($policy) || !is_int($now) || ($policy['company_id'] ?? null) !== $booking['company_id']) throw new InvalidArgumentException('Reminder policy scope invalid.');
        $offsets=$policy['minutes_before'] ?? [];
        if (!is_array($offsets) || count($offsets)>12) throw new InvalidArgumentException('Invalid reminder offsets.');
        $result=[];
        if ($booking['status'] !== 'approved' || empty($booking['contact_opt_in'])) return $result;
        foreach ($offsets as $minutes) {
            if (!is_int($minutes) || $minutes < 1 || $minutes > 43200) throw new InvalidArgumentException('Invalid reminder offset.');
            $at=$booking['starts_at_utc']-$minutes*60;
            if ($at <= $now) continue;
            $key=hash('sha256', implode('|',['tzfd-reminder-v1',$booking['company_id'],$booking['booking_id'],$booking['starts_at_utc'],$minutes]));
            $result[$key]=['idempotency_key'=>$key,'company_id'=>$booking['company_id'],'booking_id'=>$booking['booking_id'],'scheduled_at_utc'=>$at,'minutes_before'=>$minutes,'status'=>'proposed','requires_delivery_confirmation'=>true];
        }
        return array_values($result);
    }
    public static function inbound_reply($booking, $reply, $facts) {
        self::scope($booking);
        if (!is_string($reply) || strlen($reply)>100 || !is_array($facts) ||
            ($facts['company_id'] ?? null) !== $booking['company_id'] || empty($facts['sender_verified']) ||
            empty($facts['complete']) || !is_int($facts['now_utc'] ?? null)) throw new InvalidArgumentException('Verified sender and complete context required.');
        $normalized=strtolower(trim($reply));
        $action=self::REPLIES[$normalized] ?? 'human_review';
        if ($booking['status'] !== 'approved' || $booking['starts_at_utc'] <= $facts['now_utc']) $action='human_review';
        $eventId=$facts['provider_event_id'] ?? null;
        if (!is_string($eventId) || strlen($eventId)<1 || strlen($eventId)>200) throw new InvalidArgumentException('Provider event ID required.');
        return ['company_id'=>$booking['company_id'],'booking_id'=>$booking['booking_id'],
            'idempotency_key'=>hash('sha256', 'tzfd-reply-v1|'.$booking['company_id'].'|'.$eventId),
            'decision'=>$action,'booking_mutated'=>false,'message_sent'=>false,
            'requires_authoritative_booking_action'=>in_array($action,['cancel_requested','reschedule_requested'],true),
            'requires_existing_signed_reschedule_flow'=>$action==='reschedule_requested',
            'requires_authenticated_event_persistence'=>true];
    }
}
