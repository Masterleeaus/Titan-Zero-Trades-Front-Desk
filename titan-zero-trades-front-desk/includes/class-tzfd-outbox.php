<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-addon-records.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';

/**
 * Outbound messages for the SMS and broadcast add-ons.
 *
 * Messages are queued as records, skip contacts who opted out, and are sent
 * through the connected channel adapter: tzfd_sms_send for SMS, and wp_mail
 * for email. A message with no connected sender stays queued, so nothing is
 * lost when a site has not connected a provider yet. Opt-outs are stored as
 * hashes, not as plain phone numbers or addresses.
 */
final class TZFD_Outbox {
    const ADDON        = 'outbox';
    const KIND_MESSAGE = 'message';
    const KIND_OPTOUT  = 'optout';
    const MAX_ATTEMPTS = 3;
    const MAX_SMS_LEN  = 1000;
    const STATUS_QUEUED = 'queued';
    const STATUS_SENT   = 'sent';
    const STATUS_FAILED = 'failed';

    /** Schedules the hourly send run once. Safe to call from every SMS add-on. */
    public static function boot() {
        static $booted = false;
        if ( $booted ) { return; }
        $booted = true;
        TZFD_Addon_Records::ensure_schema();
        add_action( 'titan_zero_front_desk_outbox_run', array( __CLASS__, 'process' ) );
        if ( ! wp_next_scheduled( 'titan_zero_front_desk_outbox_run' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'titan_zero_front_desk_outbox_run' );
        }
    }

    /** Digits and a leading plus, 6 to 15 digits in total. Empty string when invalid. */
    public static function normalize_phone( $raw ) {
        $number = preg_replace( '/[^\d+]/', '', (string) $raw );
        return 1 === preg_match( '/^\+?\d{6,15}$/', $number ) ? $number : '';
    }

    public static function normalize_contact( $channel, $contact ) {
        if ( 'sms' === $channel ) { return self::normalize_phone( $contact ); }
        $email = sanitize_email( (string) $contact );
        return ( '' !== $email && is_email( $email ) ) ? strtolower( $email ) : '';
    }

    private static function optout_ref( $contact ) {
        return hash( 'sha256', 'optout|' . strtolower( (string) $contact ) );
    }

    public static function is_opted_out( $contact ) {
        return null !== TZFD_Addon_Records::get( self::ADDON, self::KIND_OPTOUT, self::optout_ref( $contact ) );
    }

    public static function opt_out( $contact ) {
        return TZFD_Addon_Records::insert( self::ADDON, self::KIND_OPTOUT, self::optout_ref( $contact ), 0, array( 'at' => current_time( 'mysql' ) ) );
    }

    /** Recognises the standard opt-out keywords sent as a whole message. */
    public static function is_stop_keyword( $text ) {
        $text = strtoupper( trim( preg_replace( '/\s+/', ' ', (string) $text ) ) );
        return in_array( $text, array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPT OUT', 'OPTOUT' ), true );
    }

    /**
     * Queues one message. Returns the record reference, or '' when refused
     * (invalid contact, opted out, empty or too long).
     */
    public static function queue( $channel, $contact, $body, $booking_id = 0, $source = '' ) {
        $channel = in_array( $channel, array( 'sms', 'email' ), true ) ? $channel : '';
        $to      = $channel ? self::normalize_contact( $channel, $contact ) : '';
        $body    = trim( (string) $body );
        $limit   = ( 'sms' === $channel ) ? self::MAX_SMS_LEN : 10000;
        if ( '' === $to || '' === $body || strlen( $body ) > $limit ) { return ''; }
        if ( self::is_opted_out( $to ) ) { return ''; }

        $ref = bin2hex( random_bytes( 12 ) );
        $ok  = TZFD_Addon_Records::insert( self::ADDON, self::KIND_MESSAGE, $ref, $booking_id, array(
            'channel' => $channel, 'to' => $to, 'body' => $body, 'source' => sanitize_key( $source ),
            'status'  => self::STATUS_QUEUED, 'attempts' => 0, 'queued' => current_time( 'mysql' ),
        ) );
        return $ok ? $ref : '';
    }

    /**
     * Sends up to $limit queued messages. Returns counts: sent, failed, waiting
     * (no sender connected), and skipped (opted out since queuing).
     */
    public static function process( $limit = 25 ) {
        $counts = array( 'sent' => 0, 'failed' => 0, 'waiting' => 0, 'skipped' => 0 );
        foreach ( TZFD_Addon_Records::list_kind( self::ADDON, self::KIND_MESSAGE, 200 ) as $row ) {
            if ( $counts['sent'] + $counts['failed'] + $counts['waiting'] + $counts['skipped'] >= $limit ) { break; }
            $tzfd_message = $row['payload'];
            if ( self::STATUS_QUEUED !== ( $tzfd_message['status'] ?? '' ) ) { continue; }
            if ( self::is_opted_out( $tzfd_message['to'] ?? '' ) ) {
                self::update_status( $row, self::STATUS_FAILED, 'opted_out' );
                $counts['skipped']++;
                continue;
            }
            $result = self::send_one( $tzfd_message );
            if ( true === $result ) {
                self::update_status( $row, self::STATUS_SENT, '' );
                $counts['sent']++;
            } elseif ( null === $result ) {
                $counts['waiting']++; // no sender connected: leave queued
            } else {
                $attempts = (int) ( $tzfd_message['attempts'] ?? 0 ) + 1;
                self::update_status( $row, $attempts >= self::MAX_ATTEMPTS ? self::STATUS_FAILED : self::STATUS_QUEUED, 'send_failed', $attempts );
                $counts['failed']++;
            }
        }
        return $counts;
    }

    /** true = sent, false = sender reported a failure, null = no sender connected. */
    private static function send_one( array $tzfd_message ) {
        if ( 'email' === ( $tzfd_message['channel'] ?? '' ) ) {
            return (bool) wp_mail( $tzfd_message['to'], __( 'Message from us', 'titan-zero-trades-front-desk' ), $tzfd_message['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
        }
        $sent = apply_filters( 'tzfd_sms_send', null, array( 'to' => $tzfd_message['to'], 'body' => $tzfd_message['body'] ) );
        if ( null === $sent ) { return null; }
        return true === $sent;
    }

    private static function update_status( array $row, $status, $reason, $attempts = null ) {
        $payload = $row['payload'];
        $payload['status'] = $status;
        $payload['reason'] = $reason;
        $payload['attempts'] = null === $attempts ? (int) ( $payload['attempts'] ?? 0 ) : $attempts;
        if ( self::STATUS_SENT === $status ) {
            $payload['sent'] = current_time( 'mysql' );
            $payload['body'] = ''; // the text is not kept once it has gone out
        }
        return TZFD_Addon_Records::update( self::ADDON, self::KIND_MESSAGE, $row['ref'], $payload );
    }

    /** Queued and finished messages, most recent first, for the admin list. */
    public static function recent( $limit = 50 ) {
        $tzfd_rows = TZFD_Addon_Records::list_kind( self::ADDON, self::KIND_MESSAGE, 500 );
        return array_slice( array_reverse( $tzfd_rows ), 0, max( 1, min( 500, (int) $limit ) ) );
    }
}
