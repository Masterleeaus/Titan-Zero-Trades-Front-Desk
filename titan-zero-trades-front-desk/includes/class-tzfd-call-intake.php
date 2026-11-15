<?php
/**
 * Titan Zero Front Desk: structured call intake and attribution decision engine.
 * No persistent writes or PII emissions. The authenticated adapter must verify
 * company ownership and persist accepted results through an approved owner API.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Call_Intake {
    const VERSION = 1;
    const INTENTS = array( 'new_booking', 'reschedule', 'cancel', 'callback', 'general', 'urgent' );
    const STATES = array( 'captured', 'validated', 'booking_linked', 'closed' );

    private static function positive_id( $value ) {
        return is_int( $value ) && $value > 0;
    }

    private static function safe_text( $value, $limit ) {
        if ( ! is_string( $value ) || strlen( $value ) > $limit || ! preg_match( '//u', $value ) ) { return null; }
        $value = trim( $value );
        return '' !== $value && ! preg_match( '/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value ) ? $value : null;
    }

    /**
     * Validates supplied booking-specific intake without inventing missing facts.
     * Caller identity is a verified Workspace owner reference, not raw contact data.
     */
    public static function validate( $company_id, $data, $authenticated = false ) {
        if ( true !== $authenticated || ! self::positive_id( $company_id ) ) {
            return array( 'ok' => false, 'code' => 'unauthorised_scope' );
        }
        if ( ! is_array( $data ) || ! isset( $data['company_id'] ) || $data['company_id'] !== $company_id ) {
            return array( 'ok' => false, 'code' => 'company_mismatch' );
        }
        $call_id = isset( $data['call_id'] ) ? $data['call_id'] : null;
        $intent = isset( $data['intent'] ) ? $data['intent'] : null;
        if ( ! self::positive_id( $call_id ) || ! is_string( $intent ) || ! in_array( $intent, self::INTENTS, true ) ) {
            return array( 'ok' => false, 'code' => 'invalid_intake' );
        }
        $customer_id = isset( $data['workspace_customer_id'] ) ? $data['workspace_customer_id'] : null;
        if ( null !== $customer_id && ! self::positive_id( $customer_id ) ) {
            return array( 'ok' => false, 'code' => 'invalid_customer_reference' );
        }
        $site_id = isset( $data['workspace_site_id'] ) ? $data['workspace_site_id'] : null;
        if ( null !== $site_id && ! self::positive_id( $site_id ) ) {
            return array( 'ok' => false, 'code' => 'invalid_site_reference' );
        }
        $requested_date = isset( $data['requested_date'] ) ? $data['requested_date'] : null;
        if ( null !== $requested_date ) {
            if ( ! is_string( $requested_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $requested_date ) ) {
                return array( 'ok' => false, 'code' => 'invalid_date' );
            }
            $parts = array_map( 'intval', explode( '-', $requested_date ) );
            if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) { return array( 'ok' => false, 'code' => 'invalid_date' ); }
        }
        $request_key = isset( $data['request_key'] ) ? self::safe_text( $data['request_key'], 128 ) : null;
        if ( null === $request_key || ! preg_match( '/^[A-Za-z0-9_.:-]+$/D', $request_key ) ) {
            return array( 'ok' => false, 'code' => 'invalid_request_key' );
        }
        // Whitelisted structured fields only: no transcript, DOB, notes, email or phone.
        return array( 'ok' => true, 'schema_version' => self::VERSION, 'company_id' => $company_id,
            'call_id' => $call_id, 'intent' => $intent, 'workspace_customer_id' => $customer_id,
            'workspace_site_id' => $site_id, 'requested_date' => $requested_date,
            'request_key' => $request_key, 'state' => 'validated' );
    }

    /**
     * Requires the caller to have independently verified the booking and call
     * belong to this company. Produces an attribution proposal, not a booking.
     */
    public static function propose_attribution( $intake, $booking_id, $verified_company_id, $verified_booking_owner = false ) {
        if ( ! is_array( $intake ) || empty( $intake['ok'] ) || ! self::positive_id( $verified_company_id ) ||
            ! isset( $intake['company_id'] ) || $intake['company_id'] !== $verified_company_id ||
            ! self::positive_id( $booking_id ) || true !== $verified_booking_owner ||
            ! isset( $intake['intent'] ) || ! in_array( $intake['intent'], array( 'new_booking', 'reschedule' ), true ) ) {
            return array( 'ok' => false, 'code' => 'attribution_not_verified' );
        }
        return array( 'ok' => true, 'company_id' => $verified_company_id,
            'call_id' => $intake['call_id'], 'booking_id' => $booking_id,
            'source' => 'telephone', 'type' => 'booking_association',
            'dedupe_key' => hash( 'sha256', implode( ':', array( $verified_company_id, $intake['call_id'], $booking_id ) ) ) );
    }

    /** Returns only observed funnel counts, without speculative revenue values. */
    public static function summarize( $records, $company_id ) {
        if ( ! self::positive_id( $company_id ) || ! is_array( $records ) ) { return array( 'ok' => false, 'code' => 'invalid_scope' ); }
        $calls = array(); $bookings = array();
        foreach ( $records as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['company_id'] ) || $row['company_id'] !== $company_id ||
                ! isset( $row['call_id'] ) || ! self::positive_id( $row['call_id'] ) ) { continue; }
            $calls[$row['call_id']] = true;
            if ( isset( $row['booking_id'] ) && self::positive_id( $row['booking_id'] ) && ! empty( $row['verified'] ) ) {
                $bookings[$row['booking_id']] = true;
            }
        }
        return array( 'ok' => true, 'company_id' => $company_id, 'distinct_calls' => count( $calls ),
            'verified_linked_bookings' => count( $bookings ) );
    }
}
