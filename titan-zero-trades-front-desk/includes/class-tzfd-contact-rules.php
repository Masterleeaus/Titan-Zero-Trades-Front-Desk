<?php
/**
 * Pure rules for missed-call callback tasks and rule-based SMS replies (issues #30, #31).
 *
 * No WordPress or database calls live here, so the unit tests in tests/unit load this file directly.
 * Every method returns a decision. Callers persist the decision and send any template text.
 *
 * Decisions:
 * - A phone number match is a candidate, never authentication. Two or more matches are ambiguous.
 * - Numbers that cannot be normalised to E.164 are refused, not guessed (no country code = no guess).
 * - Replies are rule-based. A model fallback is optional and is counted as an automated turn.
 * - A cancellation is never performed from SMS. A cancel request becomes a staff review task.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Contact_Rules {
    /** Automated (outbound) replies allowed per conversation inside one window. */
    const MAX_REPLY_TURNS      = 3;
    /** Length of the conversation window in seconds, after which the turn count restarts. */
    const CONVERSATION_WINDOW  = 604800; // 7 days
    const MAX_REPLY_LENGTH     = 320;
    /** Country code used only for national numbers that start with a single trunk 0. Overridable by a filter. */
    const DEFAULT_COUNTRY_CODE = '61';

    const INTENT_BOOK      = 'book';
    const INTENT_CANCEL    = 'cancel';
    const INTENT_QUOTE     = 'quote';
    const INTENT_CALL_ME   = 'call_me';
    const INTENT_STOP      = 'stop';
    const INTENT_START     = 'start';
    const INTENT_AMBIGUOUS = 'ambiguous';
    const INTENT_UNKNOWN   = 'unknown';

    /** Intents a fallback connector may return. Anything else is refused. */
    const FALLBACK_INTENTS = array( 'book', 'cancel', 'quote', 'call_me' );

    /** Carrier opt-out keywords. CANCEL is a carrier keyword, so a bare CANCEL opts out. */
    const STOP_WORDS  = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'END', 'QUIT', 'CANCEL', 'OPTOUT', 'OPT OUT' );
    const START_WORDS = array( 'START', 'UNSTOP' );

    /** Consent basis the signed provider event must assert before any automated reply is sent. */
    const CONSENT_CUSTOMER_INITIATED = 'customer_initiated';

    /**
     * Normalises a provider caller or sender number to E.164. Returns '' when the number cannot be trusted.
     * Anonymous, restricted and withheld callers (letters) return ''. So do numbers with no country code.
     */
    public static function normalise_e164( $raw, $default_country = self::DEFAULT_COUNTRY_CODE ) {
        if ( ! is_string( $raw ) || strlen( $raw ) > 40 ) { return ''; }
        $raw = trim( $raw );
        if ( '' === $raw || preg_match( '/[A-Za-z]/', $raw ) ) { return ''; }
        if ( ! preg_match( '/^\+?[0-9 ()\-.]+$/', $raw ) ) { return ''; }
        $digits = preg_replace( '/\D+/', '', $raw );
        if ( '' === $digits ) { return ''; }
        if ( '+' === substr( $raw, 0, 1 ) ) {
            $e164 = '+' . $digits;
        } elseif ( 0 === strpos( $digits, '00' ) ) {
            $e164 = '+' . substr( $digits, 2 );
        } elseif ( '0' === substr( $digits, 0, 1 ) && ( 9 === strlen( $digits ) || 10 === strlen( $digits ) ) ) {
            $e164 = '+' . preg_replace( '/\D/', '', (string) $default_country ) . substr( $digits, 1 );
        } else {
            return '';
        }
        return preg_match( '/^\+[1-9][0-9]{7,14}$/', $e164 ) ? $e164 : '';
    }

    /**
     * Cautious contact matching. $candidates are owner-verified rows: array of
     * array( 'company_id' => int, 'customer_id' => int, 'phone' => string ).
     * Returns status: no_number | unmatched | candidate | ambiguous. Only a 'candidate' carries a customer id,
     * and it is not an authenticated identity.
     */
    public static function match_contacts( $company_id, $e164, array $candidates ) {
        if ( '' === $e164 ) { return array( 'status' => 'no_number', 'customer_id' => null ); }
        $found = array();
        foreach ( $candidates as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['company_id'], $row['customer_id'], $row['phone'] ) ||
                 $row['company_id'] !== $company_id || ! is_int( $row['customer_id'] ) || $row['customer_id'] < 1 ||
                 ! is_string( $row['phone'] ) ) {
                continue; // Rows from another company, or malformed rows, never match.
            }
            if ( self::normalise_e164( $row['phone'] ) === $e164 ) { $found[ $row['customer_id'] ] = true; }
        }
        $count = count( $found );
        if ( 0 === $count ) { return array( 'status' => 'unmatched', 'customer_id' => null ); }
        if ( 1 === $count ) { return array( 'status' => 'candidate', 'customer_id' => (int) array_key_first( $found ) ); }
        return array( 'status' => 'ambiguous', 'customer_id' => null );
    }

    /**
     * Deterministic idempotency key. Scoped to the company, so the same provider event cannot collide across tenants.
     */
    public static function dedupe_key( $company_id, $scope, $reference ) {
        return hash( 'sha256', $company_id . '|' . $scope . '|' . $reference );
    }

    /** Conversation key: one per company and caller number. Stored hashed, never as the raw number. */
    public static function conversation_key( $company_id, $e164 ) {
        return hash( 'sha256', 'conversation|' . $company_id . '|' . $e164 );
    }

    /** Returns true when the provider identity fields are safe to use as keys. */
    public static function valid_identity( $company_id, $provider, $event_id ) {
        return is_int( $company_id ) && $company_id > 0 &&
            is_string( $provider ) && 1 === preg_match( '/^[a-z0-9_-]{1,64}$/D', $provider ) &&
            is_string( $event_id ) && 1 <= strlen( $event_id ) && strlen( $event_id ) <= 255;
    }

    /**
     * Rule-based intent for one reply body. Carrier keywords come first, so opt-out is never missed.
     * Returns one of the INTENT_* constants. Two or more intents in one message are ambiguous.
     */
    public static function classify_reply( $body ) {
        if ( ! is_string( $body ) || strlen( $body ) > self::MAX_REPLY_LENGTH || 1 !== preg_match( '//u', $body ) ) {
            return self::INTENT_UNKNOWN;
        }
        $upper = strtoupper( trim( preg_replace( '/\s+/', ' ', $body ) ) );
        $bare  = trim( preg_replace( '/[^A-Z ]/', '', $upper ) );
        if ( in_array( $bare, self::STOP_WORDS, true ) ) { return self::INTENT_STOP; }
        if ( in_array( $bare, self::START_WORDS, true ) ) { return self::INTENT_START; }

        $text = strtolower( $body );
        $rules = array(
            self::INTENT_CANCEL  => '/\b(cancel|cancellation)\b/',
            self::INTENT_CALL_ME => '/\b(call me|call back|callback|ring me|phone me)\b/',
            self::INTENT_QUOTE   => '/\b(quote|quotes|quotation|estimate|price|prices|pricing|how much)\b/',
            self::INTENT_BOOK    => '/\b(book|booking|appointment|schedule|reschedule)\b/',
        );
        $hits = array();
        foreach ( $rules as $intent => $pattern ) {
            if ( 1 === preg_match( $pattern, $text ) ) { $hits[] = $intent; }
        }
        if ( 0 === count( $hits ) ) { return self::INTENT_UNKNOWN; }
        if ( count( $hits ) > 1 ) { return self::INTENT_AMBIGUOUS; }
        return $hits[0];
    }

    /**
     * Decides what to do with one classified reply.
     *
     * $context keys: opted_out (bool), consent_basis (string), turns_used (int), fallback_intent (string|null).
     * Returns: action (opt_out | opt_in | refuse | staff_review | respond | turn_limit),
     *          intent, task (callback | quote | cancel_review | staff_review | null),
     *          template (template key | null), uses_turn (bool).
     *
     * The outcome of a cancel request never cancels a booking. It can only create a staff review task.
     */
    public static function decide_reply( $intent, array $context ) {
        $opted_out  = ! empty( $context['opted_out'] );
        $consent    = isset( $context['consent_basis'] ) ? $context['consent_basis'] : '';
        $turns_used = isset( $context['turns_used'] ) && is_int( $context['turns_used'] ) ? $context['turns_used'] : 0;
        $fallback   = isset( $context['fallback_intent'] ) ? $context['fallback_intent'] : null;
        $base       = array( 'intent' => $intent, 'task' => null, 'template' => null, 'uses_turn' => false );

        if ( self::INTENT_STOP === $intent ) { return array_merge( $base, array( 'action' => 'opt_out' ) ); }
        if ( self::INTENT_START === $intent ) { return array_merge( $base, array( 'action' => 'opt_in' ) ); }
        if ( $opted_out ) { return array_merge( $base, array( 'action' => 'refuse' ) ); }
        if ( self::CONSENT_CUSTOMER_INITIATED !== $consent ) { return array_merge( $base, array( 'action' => 'refuse' ) ); }

        // Ambiguous or unknown text may use the optional fallback, which counts as a turn.
        if ( self::INTENT_AMBIGUOUS === $intent || self::INTENT_UNKNOWN === $intent ) {
            if ( null === $fallback || ! in_array( $fallback, self::FALLBACK_INTENTS, true ) || $turns_used >= self::MAX_REPLY_TURNS ) {
                return array_merge( $base, array( 'action' => 'staff_review', 'task' => 'staff_review' ) );
            }
            $intent = $fallback;
            $base['intent'] = $fallback;
        }

        $map = array(
            self::INTENT_BOOK    => array( 'task' => null,          'template' => 'book_link' ),
            self::INTENT_CANCEL  => array( 'task' => 'cancel_review', 'template' => 'cancel_received' ),
            self::INTENT_QUOTE   => array( 'task' => 'quote',       'template' => 'quote_received' ),
            self::INTENT_CALL_ME => array( 'task' => 'callback',    'template' => 'callback_received' ),
        );
        if ( ! isset( $map[ $intent ] ) ) { return array_merge( $base, array( 'action' => 'staff_review', 'task' => 'staff_review' ) ); }

        $base['task'] = $map[ $intent ]['task'];
        if ( $turns_used >= self::MAX_REPLY_TURNS ) {
            // The reply budget is spent, but the request itself is never dropped.
            return array_merge( $base, array( 'action' => 'turn_limit', 'task' => $base['task'] ? $base['task'] : 'staff_review' ) );
        }
        return array_merge( $base, array( 'action' => 'respond', 'template' => $map[ $intent ]['template'], 'uses_turn' => true ) );
    }

    /** Fixed reply texts. Contain no customer data. Keys are the only values passed around. */
    public static function template_text( $key, $booking_link ) {
        $texts = array(
            'book_link'         => 'You can book online here: {link}. Reply STOP to opt out.',
            'cancel_received'   => 'We have received your cancellation request. Our team will confirm it with you. Your booking has not changed. Reply STOP to opt out.',
            'quote_received'    => 'Thanks, we have passed your quote request to our team. Reply STOP to opt out.',
            'callback_received' => 'Thanks, we have asked our team to call you back. Reply STOP to opt out.',
        );
        if ( ! isset( $texts[ $key ] ) ) { return ''; }
        return str_replace( '{link}', (string) $booking_link, $texts[ $key ] );
    }
}
