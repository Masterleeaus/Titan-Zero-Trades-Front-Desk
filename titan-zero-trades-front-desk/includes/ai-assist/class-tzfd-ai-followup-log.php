<?php
/**
 * Follow-up history: one entry per suggestion the owner acted on. Pure functions over an array, so the same code runs in
 * WordPress (stored in one option) and in fixture tests.
 *
 * Entries hold no contact details. The customer key is an HMAC of the lowercased email with a site secret, so an
 * export or erasure request matches entries by recomputing the key for the requesting address.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Followup_Log {
    const MAX_ENTRIES = 5000;
    const STATES      = array( 'suggested', 'sent_by_owner', 'withdrawn' );

    public static function customer_key( $email, $secret ) {
        return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), (string) $secret );
    }

    /** Appends an entry, dropping the oldest when over MAX_ENTRIES. Returns the new history. */
    public static function append( array $history, $enquiry_id, $customer_key, $state, $at_ts ) {
        if ( ! in_array( $state, self::STATES, true ) ) { return $history; }
        $history[] = array( 'enquiry_id' => (int) $enquiry_id, 'customer_key' => (string) $customer_key, 'state' => $state, 'at_ts' => (int) $at_ts );
        return count( $history ) > self::MAX_ENTRIES ? array_slice( $history, -self::MAX_ENTRIES ) : $history;
    }

    /** Rows for a data export: enquiry id, state and time only. */
    public static function export( array $history, $customer_key ) {
        $rows = array();
        foreach ( $history as $entry ) {
            if ( ( $entry['customer_key'] ?? '' ) === $customer_key ) {
                $rows[] = array( 'enquiry_id' => $entry['enquiry_id'], 'state' => $entry['state'], 'at_utc' => gmdate( 'Y-m-d H:i:s', (int) $entry['at_ts'] ) );
            }
        }
        return $rows;
    }

    /** Returns array( 'history' => remaining entries, 'removed' => count ). */
    public static function erase( array $history, $customer_key ) {
        $kept = array();
        $removed = 0;
        foreach ( $history as $entry ) {
            if ( ( $entry['customer_key'] ?? '' ) === $customer_key ) { ++$removed; continue; }
            $kept[] = $entry;
        }
        return array( 'history' => $kept, 'removed' => $removed );
    }
}
