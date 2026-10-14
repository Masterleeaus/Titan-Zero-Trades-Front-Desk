<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-addon-records.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';

/**
 * Background sync jobs for the CRM and calendar add-ons (Mailchimp, Salesforce, Zoom, Google Calendar).
 *
 * A job is queued once per add-on and booking. The connected adapter tzfd_{addon}_sync performs the
 * vendor call and returns:
 *   true, or an array with 'ok' => true  -> done;
 *   false, or an array without 'ok'      -> failed, retried up to MAX_ATTEMPTS times;
 *   null                                 -> no adapter connected, the job waits.
 * Contact details in a job are removed once it succeeds. Only a 'remote_id' from the adapter result is kept.
 * Listeners get the full result through the titan_zero_front_desk_sync_done action.
 */
final class TZFD_Sync_Queue {
    const KIND         = 'sync';
    const MAX_ATTEMPTS = 3;

    /** Queues a job for one booking. Returns false when the job already exists or the input is invalid. */
    public static function enqueue( $addon, $booking_id, array $tzfd_data ) {
        $addon      = sanitize_key( $addon );
        $booking_id = TZFD_Extension_Core::booking_id( $booking_id );
        if ( '' === $addon || 0 === $booking_id ) { return false; }
        return TZFD_Addon_Records::insert( $addon, self::KIND, 'booking:' . $booking_id, $booking_id, array(
            'status' => 'queued', 'attempts' => 0, 'data' => $tzfd_data, 'queued' => current_time( 'mysql' ),
        ) );
    }

    /** Runs up to $limit queued jobs for one add-on. Returns counts: done, failed, waiting. */
    public static function process( $addon, $limit = 25 ) {
        $addon  = sanitize_key( $addon );
        $counts = array( 'done' => 0, 'failed' => 0, 'waiting' => 0 );
        foreach ( TZFD_Addon_Records::list_kind( $addon, self::KIND, 200 ) as $row ) {
            if ( array_sum( $counts ) >= max( 1, (int) $limit ) ) { break; }
            $job = $row['payload'];
            if ( 'queued' !== ( $job['status'] ?? '' ) ) { continue; }

            $tzfd_data   = is_array( $job['data'] ?? null ) ? $job['data'] : array();
            $result = TZFD_Extension_Core::adapter( $addon, 'sync', array( 'booking_id' => $row['booking_id'], 'data' => $tzfd_data ) );
            if ( null === $result ) { $counts['waiting']++; continue; }

            $ok = ( true === $result ) || ( is_array( $result ) && ! empty( $result['ok'] ) );
            if ( $ok ) {
                $job['status'] = 'done';
                $job['data']   = array();
                $job['result'] = array( 'remote_id' => is_array( $result ) ? substr( sanitize_text_field( (string) ( $result['remote_id'] ?? '' ) ), 0, 190 ) : '' );
                $counts['done']++;
                TZFD_Addon_Records::update( $addon, self::KIND, $row['ref'], $job );
                do_action( 'titan_zero_front_desk_sync_done', $addon, $row['booking_id'], is_array( $result ) ? $result : array(), $tzfd_data );
            } else {
                $job['attempts'] = (int) ( $job['attempts'] ?? 0 ) + 1;
                $job['status']   = $job['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'queued';
                $counts['failed']++;
                TZFD_Addon_Records::update( $addon, self::KIND, $row['ref'], $job );
            }
        }
        return $counts;
    }

    /** Status of the job for a booking: queued, done, failed, or '' when none exists. */
    public static function status_for( $addon, $booking_id ) {
        $row = TZFD_Addon_Records::get( sanitize_key( $addon ), self::KIND, 'booking:' . (int) $booking_id );
        return $row ? (string) ( $row['payload']['status'] ?? '' ) : '';
    }
}
