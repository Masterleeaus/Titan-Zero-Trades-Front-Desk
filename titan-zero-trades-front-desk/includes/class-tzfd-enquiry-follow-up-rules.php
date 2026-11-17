<?php
/**
 * Local follow-up rules for open enquiries (issue #33).
 *
 * Pure PHP: no WordPress calls, no database, no network, no sending. The caller supplies the enquiry, the triage spam
 * hold, the opt-out state, the prior suggestions and the clock. The result is either a suggestion (a proposed time and
 * a short draft for the owner to approve) or a suppression with reasons. Nothing here sends a message. Every suggestion
 * is for owner approval (auto_send is always false).
 *
 * A hosted draft is optional. It is passed in as a callable by the caller. If it throws, returns nothing, or returns
 * text that breaks the rules (a currency amount, or too long), the local template is used, so a proxy outage never
 * blocks the suggestion.
 *
 * Owner decisions in this file (see the PR for review): the business window, the cooldowns, that a 'reviewed' enquiry
 * is treated as handled, and that the enquiry itself counts as the customer's consent for one reply (an opt-out still
 * wins). 'Abandoned' enquiries (started but never submitted) are not detected: there is no draft-form data to read.
 */
if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) { exit; }

final class TZFD_Enquiry_Follow_Up_Rules {

    const VERSION           = 1;
    const UNANSWERED_AFTER  = 7200;    // Seconds with no recorded reply before an enquiry counts as unanswered (2 hours).
    const STALE_AFTER       = 259200;  // Seconds after which an open enquiry is only highlighted, never suggested (72 hours).
    const CONTACT_COOLDOWN  = 86400;   // One suggestion per contact in any 24 hours.
    const CONTACT_WEEK_MAX  = 2;       // Suggestions per contact in any 7 days.
    const WEEK              = 604800;
    const WINDOW_START_HOUR = 9;       // Local business window, Monday to Friday, start (inclusive).
    const WINDOW_END_HOUR   = 17;      // Local business window end (exclusive).
    const MAX_TEXT          = 320;     // Characters in one draft, including the opt-out line.
    const OPT_OUT_LINE      = 'Reply STOP to opt out.';

    /** Suggestion states that count toward the per-contact throttle. Dismissed suggestions do not. */
    const THROTTLE_STATES = array( 'proposed', 'edited', 'approved', 'sent' );

    /** Allowed owner moves. A sent or dismissed suggestion is final. */
    const MOVES = array(
        'proposed' => array( 'edited', 'approved', 'dismissed' ),
        'edited'   => array( 'approved', 'dismissed' ),
        'approved' => array( 'sent', 'dismissed' ),
        'sent'     => array(),
        'dismissed' => array(),
    );

    /**
     * Age band of an enquiry. 'closed' covers closed and reviewed enquiries and any unknown status (fails closed).
     *
     * @return string new|unanswered|stale|closed
     */
    public static function classify( $status, $age ) {
        if ( 'new' !== $status ) { return 'closed'; }
        if ( $age < self::UNANSWERED_AFTER ) { return 'new'; }
        if ( $age < self::STALE_AFTER ) { return 'unanswered'; }
        return 'stale';
    }

    /**
     * Stable per-contact key. Email is preferred. Phone digits are used only when there is no email.
     * Returns '' when the enquiry has no usable contact.
     */
    public static function contact_key( $email, $phone ) {
        $email = strtolower( trim( (string) $email ) );
        if ( '' !== $email ) { return 'e:' . hash( 'sha256', $email ); }
        $digits = preg_replace( '/\D+/', '', (string) $phone );
        return ( null === $digits || '' === $digits ) ? '' : 'p:' . hash( 'sha256', $digits );
    }

    /**
     * Earliest moment at or after $now inside the local business window (Mon to Fri, WINDOW_START_HOUR to
     * WINDOW_END_HOUR). Returns 0 when the timezone is not valid.
     */
    public static function next_window( $now, $tz_name ) {
        try {
            $tz = new DateTimeZone( (string) $tz_name );
        } catch ( Exception $e ) {
            return 0;
        }
        $t    = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
        $dow  = (int) $t->format( 'N' );
        $hour = (int) $t->format( 'G' );
        if ( $dow <= 5 && $hour >= self::WINDOW_START_HOUR && $hour < self::WINDOW_END_HOUR ) { return (int) $now; }
        if ( $dow <= 5 && $hour < self::WINDOW_START_HOUR ) {
            return $t->setTime( self::WINDOW_START_HOUR, 0, 0 )->getTimestamp();
        }
        for ( $i = 1; $i <= 7; $i++ ) {
            $candidate = $t->modify( '+' . $i . ' day' )->setTime( self::WINDOW_START_HOUR, 0, 0 );
            if ( (int) $candidate->format( 'N' ) <= 5 ) { return $candidate->getTimestamp(); }
        }
        return 0;
    }

    /**
     * Decides whether one open enquiry gets a suggestion.
     *
     * $enq keys: company_id (int), enquiry_id (int), status (string), received (int Unix), name (string),
     *            email (string), phone (string), spam_held (bool), opted_out (bool).
     * $ctx keys: now (int Unix), tz (IANA name), suggestions (rows: company_id, enquiry_id, state),
     *            contact_history (rows: company_id, contact_key, ts, state), hosted (optional callable).
     *
     * @return array {action: 'suggest'|'none', state: string, reasons: string[], propose_at: int, text: string,
     *                text_source: 'local'|'hosted'|'', auto_send: false}
     */
    public static function evaluate( array $enq, array $ctx ) {
        $out = array(
            'action'      => 'none',
            'state'       => '',
            'reasons'     => array(),
            'propose_at'  => 0,
            'text'        => '',
            'text_source' => '',
            'auto_send'   => false,
        );
        $company  = isset( $enq['company_id'] ) && is_int( $enq['company_id'] ) ? $enq['company_id'] : 0;
        $enq_id   = isset( $enq['enquiry_id'] ) && is_int( $enq['enquiry_id'] ) ? $enq['enquiry_id'] : 0;
        $now      = isset( $ctx['now'] ) && is_int( $ctx['now'] ) ? $ctx['now'] : 0;
        $received = isset( $enq['received'] ) && is_int( $enq['received'] ) ? $enq['received'] : 0;
        if ( $company < 1 || $enq_id < 1 || $now < 1 || $received < 1 ) {
            $out['reasons'][] = 'invalid_input';
            return $out;
        }

        $state        = self::classify( isset( $enq['status'] ) ? (string) $enq['status'] : '', $now - $received );
        $out['state'] = $state;
        if ( 'closed' === $state ) { $out['reasons'][] = 'not_open'; return $out; }
        if ( ! empty( $enq['spam_held'] ) ) { $out['reasons'][] = 'spam_held'; return $out; }
        if ( ! empty( $enq['opted_out'] ) ) { $out['reasons'][] = 'opted_out'; return $out; }

        $key = self::contact_key( isset( $enq['email'] ) ? $enq['email'] : '', isset( $enq['phone'] ) ? $enq['phone'] : '' );
        if ( '' === $key ) { $out['reasons'][] = 'no_contact'; return $out; }

        // Duplicate prevention: one suggestion per enquiry, whatever its state (sent and dismissed included).
        foreach ( (array) ( isset( $ctx['suggestions'] ) ? $ctx['suggestions'] : array() ) as $s ) {
            if ( ! is_array( $s ) || (int) ( $s['company_id'] ?? 0 ) !== $company ) { continue; } // Tenant scope.
            if ( (int) ( $s['enquiry_id'] ?? 0 ) === $enq_id ) { $out['reasons'][] = 'already_suggested'; return $out; }
        }

        if ( 'new' === $state ) { $out['reasons'][] = 'too_recent'; return $out; }
        if ( 'stale' === $state ) { $out['reasons'][] = 'stale'; return $out; }

        // Per-customer throttle, scoped to this company.
        $in_day  = 0;
        $in_week = 0;
        foreach ( (array) ( isset( $ctx['contact_history'] ) ? $ctx['contact_history'] : array() ) as $h ) {
            if ( ! is_array( $h ) || (int) ( $h['company_id'] ?? 0 ) !== $company ) { continue; }
            if ( ( $h['contact_key'] ?? '' ) !== $key || ! in_array( $h['state'] ?? '', self::THROTTLE_STATES, true ) ) { continue; }
            $age = $now - (int) ( $h['ts'] ?? 0 );
            if ( $age < 0 || $age > self::WEEK ) { continue; }
            $in_week++;
            if ( $age < self::CONTACT_COOLDOWN ) { $in_day++; }
        }
        if ( $in_day > 0 ) { $out['reasons'][] = 'contact_cooldown'; return $out; }
        if ( $in_week >= self::CONTACT_WEEK_MAX ) { $out['reasons'][] = 'contact_weekly_limit'; return $out; }

        $tz      = isset( $ctx['tz'] ) ? (string) $ctx['tz'] : '';
        $propose = self::next_window( $now, $tz );
        if ( 0 === $propose ) { $out['reasons'][] = 'bad_timezone'; return $out; }

        $draft = self::draft( isset( $enq['name'] ) ? $enq['name'] : '', isset( $ctx['hosted'] ) ? $ctx['hosted'] : null );

        $out['action']      = 'suggest';
        $out['propose_at']  = $propose;
        $out['text']        = $draft['text'];
        $out['text_source'] = $draft['source'];
        if ( '' !== $draft['reason'] ) { $out['reasons'][] = $draft['reason']; }
        return $out;
    }

    /**
     * Local draft, or the hosted draft when it passes the rules. Returns text, source and a reason when a hosted
     * draft was not used.
     *
     * @return array {text: string, source: 'local'|'hosted', reason: string}
     */
    public static function draft( $first_name, $hosted = null ) {
        $local = self::local_template( $first_name );
        if ( ! is_callable( $hosted ) ) {
            return array( 'text' => $local, 'source' => 'local', 'reason' => '' );
        }
        try {
            $raw = call_user_func( $hosted, self::first_name( $first_name ) );
        } catch ( Throwable $e ) {
            return array( 'text' => $local, 'source' => 'local', 'reason' => 'hosted_unavailable' );
        }
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return array( 'text' => $local, 'source' => 'local', 'reason' => 'hosted_unavailable' );
        }
        if ( ! self::hosted_text_ok( $raw ) ) {
            return array( 'text' => $local, 'source' => 'local', 'reason' => 'hosted_rejected' );
        }
        return array( 'text' => self::finish( $raw ), 'source' => 'hosted', 'reason' => '' );
    }

    /** Short template with no price, no date promise and no booking commitment. */
    public static function local_template( $first_name ) {
        $name = self::first_name( $first_name );
        $hi   = '' === $name ? 'Hi there,' : 'Hi ' . $name . ',';
        return self::finish( $hi . ' thanks for your enquiry. We wanted to check whether you still need help. Reply here or call us and we will arrange a time.' );
    }

    /** First name only, letters and apostrophes or hyphens, so no markup or control characters reach a message. */
    public static function first_name( $raw ) {
        $clean = preg_replace( '/[^\p{L}\p{M}\'\-\s]+/u', ' ', (string) $raw );
        if ( null === $clean ) { return ''; }
        $parts = preg_split( '/\s+/u', trim( $clean ), -1, PREG_SPLIT_NO_EMPTY );
        $first = ( is_array( $parts ) && isset( $parts[0] ) ) ? $parts[0] : '';
        return self::cut( $first, 30 );
    }

    /**
     * Normalises a draft: single spaces, the opt-out line once at the end, and the length limit applied to the body
     * so the opt-out line is never cut off.
     */
    public static function finish( $text ) {
        $text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
        $text = trim( str_ireplace( self::OPT_OUT_LINE, '', $text ) );
        $body = self::cut( $text, self::MAX_TEXT - strlen( self::OPT_OUT_LINE ) - 1 );
        return trim( $body . ' ' . self::OPT_OUT_LINE );
    }

    /** Owner-edited text. Same finish rules. Refused for a sent or dismissed suggestion, or another company. */
    public static function apply_owner_edit( array $suggestion, $text, $company_id ) {
        $company_id = is_int( $company_id ) ? $company_id : 0;
        if ( $company_id < 1 || (int) ( $suggestion['company_id'] ?? 0 ) !== $company_id ) { return array( 'error' => 'tenant_mismatch' ); }
        $state = (string) ( $suggestion['state'] ?? '' );
        if ( ! in_array( 'edited', self::MOVES[ $state ] ?? array(), true ) ) { return array( 'error' => 'not_editable' ); }
        if ( '' === trim( (string) $text ) ) { return array( 'error' => 'empty_text' ); }
        $suggestion['text']  = self::finish( $text );
        $suggestion['state'] = 'edited';
        return $suggestion;
    }

    /** Owner move from one state to another. Returns the new state, or '' when the move is not allowed. */
    public static function move( $from, $to ) {
        return in_array( $to, self::MOVES[ $from ] ?? array(), true ) ? $to : '';
    }

    /** A hosted draft may not contain a currency amount or a date promise, and must fit the length limit. */
    private static function hosted_text_ok( $text ) {
        if ( mb_strlen( trim( $text ), 'UTF-8' ) > self::MAX_TEXT ) { return false; }
        return 0 === preg_match( '/[$€£]\s*\d|\d+\s*(?:aud|usd|dollars)\b/iu', $text );
    }

    private static function cut( $text, $max ) {
        if ( mb_strlen( $text, 'UTF-8' ) <= $max ) { return $text; }
        return rtrim( mb_substr( $text, 0, $max, 'UTF-8' ) );
    }
}
