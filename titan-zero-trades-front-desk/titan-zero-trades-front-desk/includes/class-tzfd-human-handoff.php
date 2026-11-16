<?php
/**
 * Human handoff and escalation (issue #27).
 *
 * Decides when an enquiry must go to a person, and builds the structured enquiry that a person reviews.
 * This class is deliberately free of WordPress calls in its decision and payload functions so that it can be
 * unit-tested from the command line. Storage and notification are separate, WordPress-only steps.
 *
 * Free-plan boundaries: no staff availability or response time is promised here, no outbound messages are sent,
 * and no hosted AI call is made. Confidence is an input supplied by whichever engine produced the reply; when no
 * engine supplied one, the enquiry is treated as low confidence.
 */
if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) { exit; }

final class TZFD_Human_Handoff {

    const CONFIDENCE_FLOOR = 0.6;
    const SUMMARY_MAX      = 500;
    const RATE_LIMIT       = 3;   // handoff records per company and source address per 10 minutes.
    const RATE_WINDOW      = 600;

    /** Terms that always route to a person, whatever the confidence. Matched case-insensitively as whole words. */
    const POLICY_TERMS = array(
        'refund', 'chargeback', 'complaint', 'compensation', 'lawyer', 'solicitor', 'legal', 'sue', 'lawsuit',
        'injury', 'injured', 'insurance claim', 'gas leak', 'electrical fault', 'fire', 'asbestos',
    );

    /** Intents a structured enquiry may carry. Anything else is stored as 'other'. */
    const INTENTS = array( 'booking', 'quote', 'complaint', 'policy', 'callback', 'other' );

    /** Sources a structured enquiry may carry. */
    const SOURCES = array( 'website-form', 'ai-assistant', 'rules-faq', 'ai-handoff' );

    /**
     * Pure decision. Input keys (all optional):
     *  - text (string): the customer's message
     *  - confidence (float|null): 0..1 from the answering engine, null when none was produced
     *  - needs_ai (bool): the answer genuinely needs the AI engine (rules could not answer)
     *  - ai_available (bool): hosted engine is enabled and reachable
     *  - allowance_left (int|null): remaining metered AI requests, null when not metered
     *
     * Returns array( 'escalate' => bool, 'reasons' => string[] ), reasons in a fixed order.
     */
    public static function decide( array $turn ) {
        $reasons = array();
        $text    = isset( $turn['text'] ) && is_string( $turn['text'] ) ? $turn['text'] : '';

        if ( self::touches_policy( $text ) ) {
            $reasons[] = 'policy_sensitive';
        }

        if ( ! empty( $turn['needs_ai'] ) ) {
            if ( empty( $turn['ai_available'] ) ) {
                $reasons[] = 'ai_unavailable';
            } elseif ( isset( $turn['allowance_left'] ) && (int) $turn['allowance_left'] < 1 ) {
                $reasons[] = 'allowance_exhausted';
            } elseif ( ! isset( $turn['confidence'] ) || ! is_numeric( $turn['confidence'] ) || (float) $turn['confidence'] < self::CONFIDENCE_FLOOR ) {
                $reasons[] = 'low_confidence';
            }
        }

        return array( 'escalate' => ! empty( $reasons ), 'reasons' => $reasons );
    }

    /** True when the text contains a policy-sensitive term as a whole word or phrase. */
    public static function touches_policy( $text ) {
        if ( ! is_string( $text ) || '' === trim( $text ) ) { return false; }
        $haystack = ' ' . strtolower( preg_replace( '/\s+/', ' ', $text ) ) . ' ';
        foreach ( self::POLICY_TERMS as $term ) {
            if ( false !== strpos( $haystack, ' ' . $term . ' ' ) ) { return true; }
            if ( false !== strpos( $haystack, ' ' . $term . 's ' ) ) { return true; }
        }
        return false;
    }

    /**
     * Gate before any personal data is stored. A callback needs a way to reach the customer and recorded consent.
     * Returns 'ok', 'no_contact_details' or 'consent_required'.
     */
    public static function contact_gate( array $contact, $consent ) {
        $email = isset( $contact['email'] ) && is_string( $contact['email'] ) ? trim( $contact['email'] ) : '';
        $phone = isset( $contact['phone'] ) && is_string( $contact['phone'] ) ? trim( $contact['phone'] ) : '';
        if ( '' === $email && '' === $phone ) { return 'no_contact_details'; }
        if ( true !== $consent ) { return 'consent_required'; }
        return 'ok';
    }

    /** Removes email addresses and phone-like digit runs from text, then truncates. Used for the permitted summary. */
    public static function permitted_summary( $text ) {
        $text = is_string( $text ) ? $text : '';
        $text = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email removed]', $text );
        $text = preg_replace( '/(\+?\d[\d\s().\-]{6,}\d)/', '[number removed]', $text );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::SUMMARY_MAX ) : substr( $text, 0, self::SUMMARY_MAX );
    }

    /**
     * Builds the structured enquiry. Returns array( 'ok' => true, 'enquiry' => array ) or
     * array( 'ok' => false, 'reason' => string ). Never stores or returns the raw message text.
     *
     * Required keys: text, source, intent, confidence, reasons (from decide()), consent (bool), name, email, phone.
     */
    public static function build_enquiry( array $in ) {
        $gate = self::contact_gate(
            array( 'email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? '' ),
            $in['consent'] ?? false
        );
        if ( 'ok' !== $gate ) { return array( 'ok' => false, 'reason' => $gate ); }

        $source = in_array( $in['source'] ?? '', self::SOURCES, true ) ? $in['source'] : 'ai-handoff';
        $intent = in_array( $in['intent'] ?? '', self::INTENTS, true ) ? $in['intent'] : 'other';

        $confidence = null;
        if ( isset( $in['confidence'] ) && is_numeric( $in['confidence'] ) ) {
            $confidence = max( 0.0, min( 1.0, (float) $in['confidence'] ) );
        }

        $reasons = array_values( array_intersect(
            array( 'policy_sensitive', 'low_confidence', 'ai_unavailable', 'allowance_exhausted', 'customer_requested' ),
            (array) ( $in['reasons'] ?? array() )
        ) );

        return array(
            'ok'      => true,
            'enquiry' => array(
                'source'     => $source,
                'intent'     => $intent,
                'confidence' => $confidence,
                'reasons'    => $reasons,
                'summary'    => self::permitted_summary( $in['text'] ?? '' ),
                'name'       => is_string( $in['name'] ?? null ) ? substr( trim( $in['name'] ), 0, 190 ) : '',
                'email'      => is_string( $in['email'] ?? null ) ? trim( $in['email'] ) : '',
                'phone'      => is_string( $in['phone'] ?? null ) ? trim( $in['phone'] ) : '',
            ),
        );
    }

    /** Pure rate-limit check. True when another record may be created. */
    public static function within_rate_limit( $recent_count ) {
        return (int) $recent_count < self::RATE_LIMIT;
    }
}
