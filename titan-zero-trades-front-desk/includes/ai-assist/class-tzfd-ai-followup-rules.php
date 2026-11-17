<?php
/**
 * Follow-up suggestions for enquiries. Pure rules: nothing is sent here.
 *
 * An enquiry is flagged when it is unanswered for UNANSWERED_HOURS, or abandoned (the customer started but did not finish).
 * Each flag is a suggestion for the owner with a proposed local time and a fixed response template. Suppression is checked
 * in this order: closed, no recorded consent, opt-out, unknown opt-out state (fail closed), duplicate, per-customer throttle.
 * Suggested times fall inside business hours in the site timezone and never in quiet hours.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Followup_Rules {
    const UNANSWERED_HOURS      = 24;
    const ABANDONED_HOURS       = 6;
    const DUPLICATE_HOURS       = 24;
    const THROTTLE_DAYS         = 7;
    const THROTTLE_MAX          = 2;
    const BUSINESS_START_HOUR   = 9;
    const BUSINESS_END_HOUR     = 17;

    /** Templates are keys; the owner sees the text, the rules never generate it. */
    const TEMPLATES = array(
        'unanswered' => 'followup_unanswered',
        'abandoned'  => 'followup_abandoned',
    );

    /**
     * @param array    $enquiry  id, email, status (new|reviewed|closed), received_utc (Y-m-d H:i:s, UTC), replied (bool),
     *                           abandoned (bool), contact_consent (bool, must be true to suggest anything).
     * @param int      $now_ts   Unix time.
     * @param string   $timezone Site timezone identifier, for example Australia/Melbourne.
     * @param array    $history  Entries: enquiry_id, customer_key, state, at_ts.
     * @param callable $optout   fn( string $email ): ?bool. true = opted out, false = not opted out, null = unknown.
     * @param string   $customer_key Stable key for the customer (for throttling). Use the keyed hash, not the raw address.
     * @return array { category, template, state: suggested|suppressed, suppressed_by, suggest_at_local, suggest_at_ts }
     */
    public static function evaluate( array $enquiry, $now_ts, $timezone, array $history, $optout, $customer_key ) {
        $none = array( 'category' => null, 'template' => null, 'state' => 'suppressed', 'suppressed_by' => null, 'suggest_at_local' => null, 'suggest_at_ts' => null );
        $category = self::category( $enquiry, (int) $now_ts );
        if ( null === $category ) { return $none; }
        $none['category'] = $category;
        $none['template'] = self::TEMPLATES[ $category ];

        $reason = self::suppression( $enquiry, (int) $now_ts, $history, $optout, $customer_key, $category );
        if ( null !== $reason ) {
            $none['suppressed_by'] = $reason;
            return $none;
        }
        $when = self::next_business_time( (int) $now_ts, (string) $timezone );
        if ( null === $when ) {
            $none['suppressed_by'] = 'bad_timezone';
            return $none;
        }
        return array(
            'category' => $category, 'template' => self::TEMPLATES[ $category ], 'state' => 'suggested', 'suppressed_by' => null,
            'suggest_at_local' => $when['local'], 'suggest_at_ts' => $when['ts'],
        );
    }

    /** Category by age and status, or null when no follow-up applies. */
    public static function category( array $enquiry, $now_ts ) {
        if ( ! empty( $enquiry['replied'] ) ) { return null; }
        $received = strtotime( (string) ( $enquiry['received_utc'] ?? '' ) . ' UTC' );
        if ( false === $received || $now_ts < $received ) { return null; }
        $age_hours = ( $now_ts - $received ) / 3600;
        if ( ! empty( $enquiry['abandoned'] ) && $age_hours >= self::ABANDONED_HOURS ) { return 'abandoned'; }
        if ( $age_hours >= self::UNANSWERED_HOURS ) { return 'unanswered'; }
        return null;
    }

    /** Reason a suggestion is suppressed, or null when it may be suggested. */
    public static function suppression( array $enquiry, $now_ts, array $history, $optout, $customer_key, $category ) {
        if ( ( $enquiry['status'] ?? '' ) === 'closed' ) { return 'closed'; }
        if ( empty( $enquiry['contact_consent'] ) ) { return 'no_consent'; }
        $email = strtolower( trim( (string) ( $enquiry['email'] ?? '' ) ) );
        $state = is_callable( $optout ) ? call_user_func( $optout, $email ) : null;
        if ( true === $state ) { return 'opt_out'; }
        if ( null === $state ) { return 'consent_unknown'; }
        foreach ( $history as $entry ) {
            if ( (int) ( $entry['enquiry_id'] ?? 0 ) === (int) ( $enquiry['id'] ?? -1 ) && ( $now_ts - (int) $entry['at_ts'] ) < self::DUPLICATE_HOURS * 3600 ) {
                return 'duplicate';
            }
        }
        $window = self::THROTTLE_DAYS * 86400;
        $recent = 0;
        foreach ( $history as $entry ) {
            if ( ( $entry['customer_key'] ?? '' ) === $customer_key && ( $now_ts - (int) $entry['at_ts'] ) < $window && ( $entry['state'] ?? '' ) !== 'withdrawn' ) {
                ++$recent;
            }
        }
        if ( $recent >= self::THROTTLE_MAX ) { return 'throttle'; }
        return null;
    }

    /**
     * Earliest moment at or after now inside business hours (BUSINESS_START_HOUR to BUSINESS_END_HOUR, local time).
     * Returns array( 'ts' => int, 'local' => 'Y-m-d H:i' ) or null for an unknown timezone.
     */
    public static function next_business_time( $now_ts, $timezone ) {
        try {
            $tz = new DateTimeZone( $timezone );
        } catch ( Exception $e ) {
            return null;
        }
        $local = ( new DateTimeImmutable( '@' . (int) $now_ts ) )->setTimezone( $tz );
        $hour  = (int) $local->format( 'G' );
        if ( $hour >= self::BUSINESS_START_HOUR && $hour < self::BUSINESS_END_HOUR ) {
            $target = $local;
        } elseif ( $hour < self::BUSINESS_START_HOUR ) {
            $target = $local->setTime( self::BUSINESS_START_HOUR, 0, 0 );
        } else {
            $target = $local->modify( '+1 day' )->setTime( self::BUSINESS_START_HOUR, 0, 0 );
        }
        return array( 'ts' => $target->getTimestamp(), 'local' => $target->format( 'Y-m-d H:i' ) );
    }
}
