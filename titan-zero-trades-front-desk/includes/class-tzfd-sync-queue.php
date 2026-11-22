<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-addon-records.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';

/**
 * Background sync jobs for the CRM and calendar add-ons (Mailchimp, Salesforce, Zoom, Google Calendar).
 *
 * A job is queued once per add-on and booking. The connected adapter tz_fd_{addon}_sync performs the
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
        $counts = array( 'done' => 0, 'failed' => 0, 'waiting' => 0, 'blocked' => 0 );
        if ( ! TZFD_Addon_Records::has_company_context() ) {
            $counts['blocked'] = 1; // No implicit cross-company Cron fallback.
            return $counts;
        }
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
                $job['result'] = array( 'remote_id' => is_array( $result ) ? substr( sanitize_text_field( is_string( $result['remote_id'] ?? null ) ? $result['remote_id'] : '' ), 0, 190 ) : '' );
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

    /**
     * Cron route for integrations explicitly designed to receive verified tenant
     * identity. No legacy `tz_fd_{addon}_sync` adapter is called from Cron.
     * Implementers MUST resolve provider settings for `$args['company_id']`
     * from authoritative tenant storage; the worker does not impersonate users.
     * A callback must be deliberately registered as `tz_fd_{addon}_sync_for_company`.
     * Failed/exceptional external calls enter review (no unsafe auto-retry).
     */
    public static function process_background( $addon, $limit = 25 ) {
        $counts = array( 'done' => 0, 'review' => 0, 'waiting' => 0, 'blocked' => 0 );
        if ( ! is_string( $addon ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $addon )
            || ! is_int( $limit ) || $limit < 1 ) { $counts['blocked']++; return $counts; }
        $limit = min( 100, $limit );
        $hook = 'tz_fd_' . $addon . '_sync_for_company';
        if ( false === has_filter( $hook ) ) { $counts['waiting']++; return $counts; }
        $processed = 0;
        foreach ( TZFD_Addon_Records::queued_sync_companies( $addon ) as $company ) {
            foreach ( TZFD_Addon_Records::queued_sync_for_company( $company, $addon, 200 ) as $row ) {
                if ( $processed >= $limit ) { break 2; }
                if ( ! is_array( $row ) || ( $row['company_id'] ?? null ) !== $company
                    || ( $row['addon'] ?? null ) !== $addon || ( $row['kind'] ?? null ) !== self::KIND
                    || ! is_array( $row['payload'] ?? null ) ) { $counts['blocked']++; continue; }
                $job = $row['payload'];
                if ( 'queued' !== ( $job['status'] ?? '' ) ) { continue; }
                if ( ! is_array( $job['data'] ?? null ) || ! is_int( $row['booking_id'] ?? null )
                    || ! TZFD_Addon_Records::booking_belongs_to_company( $company, $row['booking_id'] ) ) {
                    $counts['blocked']++; continue;
                }
                $processing = $job;
                $processing['status'] = 'processing';
                if ( ! TZFD_Addon_Records::transition_sync_for_company( $company, $addon, $row, $job, $processing ) ) {
                    $counts['blocked']++; continue;
                }
                $processed++;
                $args = array( 'company_id' => $company, 'booking_id' => $row['booking_id'], 'data' => $job['data'] );
                try {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Sanitized, strictly prefixed explicit-company integration hook.
                    $result = apply_filters( $hook, null, $args );
                    $ok = true === $result || ( is_array( $result ) && true === ( $result['ok'] ?? null ) );
                } catch ( Throwable $error ) {
                    $result = null;
                    $ok = false;
                }
                $final = $processing;
                $final['status'] = $ok ? 'done' : 'review';
                $final['reason'] = $ok ? '' : 'external_outcome_unverified';
                if ( $ok ) {
                    $final['data'] = array();
                    $final['result'] = array( 'remote_id' => is_array( $result ) && is_string( $result['remote_id'] ?? null )
                        ? substr( sanitize_text_field( $result['remote_id'] ), 0, 190 ) : '' );
                }
                if ( ! TZFD_Addon_Records::transition_sync_for_company( $company, $addon, $row, $processing, $final ) ) {
                    $counts['blocked']++;
                    continue;
                }
                $counts[ $ok ? 'done' : 'review' ]++;
                if ( $ok ) {
                    do_action( 'titan_zero_front_desk_sync_done', $addon, $row['booking_id'], is_array( $result ) ? $result : array(), $job['data'] );
                }
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
