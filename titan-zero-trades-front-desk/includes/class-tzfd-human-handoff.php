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
defined( 'ABSPATH' ) || exit;

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

    /**
     * Phrases in which the customer asks for a person or a callback. Matched as whole-word sequences.
     * A phrase preceded by a negator inside NEGATION_WINDOW words is not a request ("no need to speak to someone").
     */
    const HUMAN_REQUEST_TERMS = array(
        'speak to a person', 'speak to someone', 'speak to a human', 'speak to an agent', 'talk to a person',
        'talk to someone', 'talk to a human', 'real person', 'real human', 'human agent', 'call me back',
        'ring me back', 'callback please',
    );

    const NEGATION_WINDOW = 3;

    const NEGATORS = array( 'not', 'no', 'dont', "don't", 'never', 'without', 'cannot', "can't", 'wont', "won't" );

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

        if ( self::asks_for_person( $text ) ) {
            $reasons[] = 'customer_requested';
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

    /** True when the text asks for a person or a callback and the request is not negated. */
    public static function asks_for_person( $text ) {
        if ( ! is_string( $text ) || '' === trim( $text ) ) { return false; }
        $tokens = preg_split( '/[^a-z\']+/', strtolower( str_replace( "\xE2\x80\x99", "'", $text ) ), -1, PREG_SPLIT_NO_EMPTY );
        $count  = count( $tokens );
        foreach ( self::HUMAN_REQUEST_TERMS as $term ) {
            $words = explode( ' ', $term );
            $n     = count( $words );
            for ( $i = 0; $i + $n <= $count; $i++ ) {
                if ( array_slice( $tokens, $i, $n ) !== $words ) { continue; }
                $before = array_slice( $tokens, max( 0, $i - self::NEGATION_WINDOW ), min( $i, self::NEGATION_WINDOW ) );
                if ( array_intersect( $before, self::NEGATORS ) ) { continue; }
                return true;
            }
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
            array( 'policy_sensitive', 'customer_requested', 'low_confidence', 'ai_unavailable', 'allowance_exhausted' ),
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

    const SHORTCODE = 'tzfd_callback_form';
    const ACTION    = 'tzfd_handoff_request';
    const NONCE     = 'tzfd_handoff_request';
    const NONCE_FLD = '_tzfd_handoff_nonce';

    /** WordPress wiring: registers the callback form shortcode and its public POST handler. */
    public static function register() {
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_request' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_request' ) );
    }

    /** Callback form. Shown only for the current company; the company is never taken from the request. */
    public static function shortcode() {
        if ( ! class_exists( 'TZFD_Scoped_Store' ) || (int) TZFD_Scoped_Store::company_id() < 1 ) { return ''; }
        require_once __DIR__ . '/class-tzfd-public-form-assets.php';
        TZFD_Public_Form_Assets::enqueue();
        $req = TZFD_Public_Form_Assets::required_marker();
        $out  = '<form class="tzfd-callback-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        $out .= wp_nonce_field( self::NONCE, self::NONCE_FLD, true, false );
        $out .= '<label>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . ' ' . $req . ' <input required aria-required="true" maxlength="190" name="name" autocomplete="name"></label>';
        $out .= '<label>' . esc_html__( 'Email', 'titan-zero-trades-front-desk' ) . ' <input type="email" maxlength="190" name="email" autocomplete="email"></label>';
        $out .= '<label>' . esc_html__( 'Phone', 'titan-zero-trades-front-desk' ) . ' <input maxlength="60" name="phone" autocomplete="tel"></label>';
        $out .= '<label>' . esc_html__( 'What would you like us to call you about?', 'titan-zero-trades-front-desk' ) . ' <textarea maxlength="5000" name="message"></textarea></label>';
        $out .= '<label><input type="checkbox" required aria-required="true" name="consent" value="1"> ' . esc_html__( 'I agree that this business may contact me about this enquiry using the details I have given.', 'titan-zero-trades-front-desk' ) . ' ' . $req . '</label>';
        $out .= '<div class="tzfd-hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>';
        $out .= '<button type="submit">' . esc_html__( 'Request a callback', 'titan-zero-trades-front-desk' ) . '</button></form>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirmation flag; no state change.
        if ( isset( $_GET['tzfd_callback_sent'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['tzfd_callback_sent'] ) ) ) {
            $out = '<p role="status">' . esc_html__( 'Thank you. Your callback request has been received.', 'titan-zero-trades-front-desk' ) . '</p>' . $out;
        }
        return $out;
    }

    /** Public POST handler. Fails closed on nonce, honeypot, company, consent, contact details and rate limit. */
    public static function handle_request() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { wp_die( esc_html__( 'Method not allowed', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) ); }
        if ( ! isset( $_POST[ self::NONCE_FLD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FLD ] ) ), self::NONCE ) ) { wp_die( esc_html__( 'Invalid form submission', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- honeypot presence check only; the raw value is never stored.
        if ( ! empty( wp_unslash( $_POST['website'] ?? '' ) ) ) { wp_die( esc_html__( 'Invalid submission', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        $company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
        if ( $company < 1 ) { wp_die( esc_html__( 'Callback form unavailable', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) ); }

        $consent = isset( $_POST['consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['consent'] ) );
        $contact = array(
            'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
            'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
        );
        $name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        if ( '' === trim( $name ) ) { wp_die( esc_html__( 'Please complete all required fields', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        if ( '' !== $contact['email'] && ! is_email( $contact['email'] ) ) { wp_die( esc_html__( 'Please check your email address', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        $gate = self::contact_gate( $contact, $consent );
        if ( 'no_contact_details' === $gate ) { wp_die( esc_html__( 'Please give an email address or phone number so we can call you back', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        if ( 'consent_required' === $gate ) { wp_die( esc_html__( 'Please tick the consent box so we can contact you', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }

        // Atomic claim: a read followed by a separate write lets parallel posts from one address all pass.
        require_once __DIR__ . '/class-tzfd-rate-limit.php';
        $key = 'handoff|' . $company . '|' . sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        if ( ! TZFD_Rate_Limit::allow( $key, self::RATE_LIMIT, self::RATE_WINDOW ) ) { wp_die( esc_html__( 'Please wait before sending another callback request', 'titan-zero-trades-front-desk' ), '', array( 'response' => 429 ) ); }

        $decision = self::decide( array( 'text' => $message ) );
        $built    = self::build_enquiry( array(
            'text' => $message, 'source' => 'website-form', 'intent' => 'callback', 'confidence' => null,
            'reasons' => array_merge( array( 'customer_requested' ), $decision['reasons'] ), 'consent' => $consent,
            'name' => $name, 'email' => $contact['email'], 'phone' => $contact['phone'],
        ) );
        if ( empty( $built['ok'] ) ) { wp_die( esc_html__( 'Please complete all required fields', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }

        $id = self::store( $company, $built['enquiry'] );
        if ( $id < 1 ) { wp_die( esc_html__( 'Your request could not be saved. Please contact us directly.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) ); }

        $referer = wp_get_referer();
        wp_safe_redirect( add_query_arg( 'tzfd_callback_sent', '1', $referer ? $referer : home_url( '/' ) ) );
        exit;
    }

    /**
     * Stores one structured handoff in Front Desk's own enquiry table, stamped with the company.
     * The structured fields are written as labelled lines in the enquiry text, so no schema change is needed.
     * Returns the row id, or 0 when refused.
     */
    public static function store( $company, array $enquiry ) {
        if ( ! class_exists( 'TZFD_Lead_Intake' ) || (int) $company < 1 ) { return 0; }
        $lines = array(
            'Handoff summary: ' . $enquiry['summary'],
            'Intent: ' . $enquiry['intent'],
            'Confidence: ' . ( null === $enquiry['confidence'] ? 'not scored' : number_format( (float) $enquiry['confidence'], 2, '.', '' ) ),
            'Reasons: ' . ( empty( $enquiry['reasons'] ) ? 'none' : implode( ', ', $enquiry['reasons'] ) ),
            'Consent: given (recorded ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC)',
        );
        return (int) TZFD_Lead_Intake::receive( array(
            'company_id' => (int) $company,
            'name'       => $enquiry['name'],
            'email'      => $enquiry['email'],
            'phone'      => $enquiry['phone'],
            'enquiry'    => implode( "\n", $lines ),
            'source'     => $enquiry['source'],
        ) );
    }
}
