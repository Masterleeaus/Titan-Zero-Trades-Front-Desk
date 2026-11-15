<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-addon-records.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';

/**
 * Outbound messages for the SMS and broadcast add-ons.
 *
 * Messages are queued as records, skip contacts who opted out, and are sent
 * through the connected channel adapter: tz_fd_sms_send for SMS, and wp_mail
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
        add_action( 'titan_zero_front_desk_outbox_run', array( __CLASS__, 'process_background' ) );
        if ( ! wp_next_scheduled( 'titan_zero_front_desk_outbox_run' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'titan_zero_front_desk_outbox_run' );
        }
    }

    /** Digits and a leading plus, 6 to 15 digits in total. Empty string when invalid. */
    public static function normalize_phone( $raw ) {
        if ( ! is_string( $raw ) && ! is_int( $raw ) ) { return ''; }
        $number = preg_replace( '/[^\d+]/', '', (string) $raw );
        return 1 === preg_match( '/^\+?\d{6,15}$/', $number ) ? $number : '';
    }

    public static function normalize_contact( $channel, $contact ) {
        if ( ! is_string( $contact ) && ! is_int( $contact ) ) { return ''; }
        if ( 'sms' === $channel ) { return self::normalize_phone( $contact ); }
        $email = sanitize_email( (string) $contact );
        return ( '' !== $email && is_email( $email ) ) ? strtolower( $email ) : '';
    }

    private static function optout_ref( $contact ) {
        return hash( 'sha256', 'optout|' . strtolower( (string) $contact ) );
    }

    public static function is_opted_out( $contact ) {
        if ( ! is_string( $contact ) || '' === $contact ) { return true; }
        return TZFD_Addon_Records::contact_is_blocked( self::optout_ref( $contact ) );
    }

    public static function opt_out( $contact ) {
        if ( ! is_string( $contact ) || '' === $contact || ! TZFD_Addon_Records::has_company_context() ) { return false; }
        $ref = self::optout_ref( $contact );
        if ( TZFD_Addon_Records::insert( self::ADDON, self::KIND_OPTOUT, $ref, 0, array( 'at' => current_time( 'mysql' ) ) ) ) { return true; }
        // A duplicate opt-out is already successful; other storage errors are not.
        return null !== TZFD_Addon_Records::get( self::ADDON, self::KIND_OPTOUT, $ref );
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
        if ( ! is_string( $body ) ) { return ''; }
        $body    = trim( $body );
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
        $counts = array( 'sent' => 0, 'failed' => 0, 'waiting' => 0, 'skipped' => 0, 'blocked' => 0 );
        if ( ! TZFD_Addon_Records::has_company_context() ) {
            $counts['blocked'] = 1; // No implicit cross-tenant Cron fallback.
            return $counts;
        }
        foreach ( TZFD_Addon_Records::list_kind( self::ADDON, self::KIND_MESSAGE, 200 ) as $row ) {
            if ( $counts['sent'] + $counts['failed'] + $counts['waiting'] + $counts['skipped'] >= $limit ) { break; }
            $tz_message = $row['payload'];
            if ( self::STATUS_QUEUED !== ( $tz_message['status'] ?? '' ) ) { continue; }
            if ( self::is_opted_out( $tz_message['to'] ?? '' ) ) {
                self::update_status( $row, self::STATUS_FAILED, 'opted_out' );
                $counts['skipped']++;
                continue;
            }
            if ( ! is_string( $tz_message['channel'] ?? null ) || ! in_array( $tz_message['channel'], array( 'sms', 'email' ), true )
                || ! is_string( $tz_message['to'] ?? null ) || '' === self::normalize_contact( $tz_message['channel'], $tz_message['to'] )
                || ! is_string( $tz_message['body'] ?? null ) || '' === trim( $tz_message['body'] ) ) {
                self::update_status( $row, self::STATUS_FAILED, 'invalid_message' );
                $counts['failed']++;
                continue;
            }
            $result = self::send_one( $tz_message );
            if ( true === $result ) {
                self::update_status( $row, self::STATUS_SENT, '' );
                $counts['sent']++;
            } elseif ( null === $result ) {
                $counts['waiting']++; // no sender connected: leave queued
            } else {
                $attempts = (int) ( $tz_message['attempts'] ?? 0 ) + 1;
                self::update_status( $row, $attempts >= self::MAX_ATTEMPTS ? self::STATUS_FAILED : self::STATUS_QUEUED, 'send_failed', $attempts );
                $counts['failed']++;
            }
        }
        return $counts;
    }

    /**
     * Cron-safe, explicitly tenant-scoped delivery. Only plain email is enabled
     * here: existing SMS provider adapters read interactive company settings and
     * MUST NOT run under a fabricated Workspace identity. SMS remains queued.
     * Return blocked on a storage/consent failure, without sending anything.
     */
    public static function process_background( $limit = 25 ) {
        $counts = array( 'sent' => 0, 'failed' => 0, 'waiting' => 0, 'skipped' => 0, 'blocked' => 0 );
        $limit = max( 1, min( 100, (int) $limit ) );
        foreach ( TZFD_Addon_Records::queued_message_companies() as $company ) {
            foreach ( TZFD_Addon_Records::queued_messages_for_company( $company, 200 ) as $row ) {
                if ( $counts['sent'] + $counts['failed'] + $counts['waiting'] + $counts['skipped'] >= $limit ) { break 2; }
                if ( (int) ( $row['company_id'] ?? 0 ) !== $company || ! is_array( $row['payload'] ?? null ) ) { $counts['blocked']++; continue; }
                $message = $row['payload'];
                if ( self::STATUS_QUEUED !== ( $message['status'] ?? '' ) ) { continue; }
                // A provider's credentials must be resolved for this exact tenant.
                if ( 'sms' === ( $message['channel'] ?? '' ) ) { $counts['waiting']++; continue; }
                if ( 'email' !== ( $message['channel'] ?? '' ) || ! is_string( $message['to'] ?? null )
                    || '' === self::normalize_contact( 'email', $message['to'] )
                    || ! is_string( $message['body'] ?? null ) || '' === trim( $message['body'] )
                    || strlen( $message['body'] ) > 10000 ) { $counts['blocked']++; continue; }
                $ref = self::optout_ref( self::normalize_contact( 'email', $message['to'] ) );
                if ( TZFD_Addon_Records::contact_is_blocked_for_company( $company, $ref ) ) {
                    // Conservative: keep queued until consent state can be reviewed.
                    $counts['blocked']++; continue;
                }
                // Mark as processing BEFORE delivery to avoid duplicate Cron sends.
                // A crash leaves a reviewable processing record, never auto-retries.
                $message['status'] = 'processing';
                if ( ! TZFD_Addon_Records::claim_message_for_company( $company, $row, $message ) ) { $counts['blocked']++; continue; }
                // Save the claimed payload for an exact compare-and-swap
                // finalization. A transport exception means delivery is unknown:
                // never put the record back into the automatic send queue.
                $claimed = $message;
                try {
                    $sent = (bool) wp_mail( $message['to'], __( 'Message from us', 'titan-zero-trades-front-desk' ), $message['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
                    $message['status'] = $sent ? self::STATUS_SENT : self::STATUS_FAILED;
                    $message['reason'] = $sent ? '' : 'send_failed';
                } catch ( Throwable $delivery_error ) {
                    $sent = false;
                    $message['status'] = 'review';
                    $message['reason'] = 'transport_exception_unknown_delivery';
                }
                if ( $sent ) { $message['body'] = ''; $message['sent'] = current_time( 'mysql' ); }
                if ( ! TZFD_Addon_Records::finish_claimed_message_for_company( $company, $row, $claimed, $message ) ) {
                    $counts['blocked']++;
                    continue;
                }
                $counts[ $sent ? 'sent' : 'failed' ]++;
            }
        }
        return $counts;
    }

    /**
     * Inventory only. Operations teams must reconcile with the actual mail
     * transport before considering a re-send; this method never delivers mail.
     */
    public static function stalled_background_emails( $company, $older_than_seconds = 3600, $limit = 100 ) {
        if ( ! is_int( $company ) || $company < 1 || ! is_int( $older_than_seconds )
            || $older_than_seconds < 900 || $older_than_seconds > 2592000 ) { return array(); }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds );
        return array_values( array_filter( TZFD_Addon_Records::stalled_message_claims( $company, $cutoff, $limit ), static function ( $row ) {
            return 'email' === ( $row['payload']['channel'] ?? '' );
        } ) );
    }

    /** true = sent, false = sender reported a failure, null = no sender connected. */
    private static function send_one( array $tz_message ) {
        if ( 'email' === ( $tz_message['channel'] ?? '' ) ) {
            return (bool) wp_mail( $tz_message['to'], __( 'Message from us', 'titan-zero-trades-front-desk' ), $tz_message['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
        }
        $sent = apply_filters( 'tz_fd_sms_send', null, array( 'to' => $tz_message['to'], 'body' => $tz_message['body'] ) );
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
        $tz_rows = TZFD_Addon_Records::list_kind( self::ADDON, self::KIND_MESSAGE, 500 );
        return array_slice( array_reverse( $tz_rows ), 0, max( 1, min( 500, (int) $limit ) ) );
    }
}
