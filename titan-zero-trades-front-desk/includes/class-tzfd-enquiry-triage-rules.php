<?php
/**
 * Deterministic enquiry triage rules (intent, urgency, spam and duplicate signals, quote draft).
 *
 * Pure PHP: no WordPress calls, no network, no model. The same input always gives the same output, so the
 * rules run offline and are tested directly from tests/unit. Storage and admin screens live in
 * class-tzfd-enquiry-triage.php.
 *
 * Decisions (see the PR "Decisions for review"):
 * - Negation: a trigger word is ignored when one of the 3 tokens before it is a negator. Sentence breaks are not
 *   detected, so "no worries, book me" is read as negated. Documented limitation; admins can override.
 * - Dates: numeric dates are read day first (d/m/y), as used in Australia and the UK. Ambiguous dates are
 *   contradictory only when two different dates are named.
 * - Spam is never deletion. A held enquiry keeps its status and stays in the admin queue.
 */
if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) { exit; }

final class TZFD_Enquiry_Triage_Rules {

    const VERSION = 1;

    /** Highest priority first. The primary intent is the first one found. */
    const INTENT_ORDER = array( 'complaint', 'cancel', 'quote', 'booking', 'service_request', 'general' );

    /** Hold threshold: a score at or above this places the enquiry in the spam review queue. */
    const SPAM_HOLD_SCORE   = 3;
    const DUPLICATE_WINDOW  = 86400;
    const BURST_WINDOW      = 600;
    const BURST_MIN         = 5;
    const NEAR_DUP_SIMILAR  = 0.80;
    const NEGATION_WINDOW   = 3;
    const MAX_PRIORS        = 200;

    const NEGATORS = array( 'not', 'no', 'never', 'without', 'dont', "don't", 'cannot', "can't", 'nicht', 'kein', 'ohne', 'sin', 'nunca', 'pas', 'jamais', 'sem', 'nie', 'ne' );

    /** Intent phrases, English plus common Spanish, French, German and Portuguese terms. */
    public static function intent_terms() {
        return array(
            'complaint'       => array( 'complaint', 'complain', 'unhappy', 'disappointed', 'refund', 'damaged', 'rude', 'poor service', 'not happy', 'queja', 'reclamo', 'reclamacao', 'reclamação', 'réclamation', 'plainte', 'beschwerde' ),
            'cancel'          => array( 'cancel', 'cancellation', 'cancelling', 'call off', 'annul', 'cancelar', 'anular', 'annuler', 'stornieren', 'cancelamento' ),
            'quote'           => array( 'quote', 'quotation', 'estimate', 'how much', 'price', 'pricing', 'cost', 'budget', 'presupuesto', 'cotizacion', 'cotización', 'precio', 'devis', 'combien', 'angebot', 'orcamento', 'orçamento' ),
            'booking'         => array( 'book', 'booking', 'appointment', 'schedule', 'reserve', 'slot', 'availability', 'available on', 'come out', 'visit', 'reservar', 'cita', 'turno', 'rendez-vous', 'reserver', 'réserver', 'termin', 'buchen', 'agendar', 'marcar' ),
            'service_request' => array( 'repair', 'fix', 'broken', 'leak', 'leaking', 'install', 'replace', 'maintenance', 'unblock', 'not working', 'reparar', 'reparacion', 'reparación', 'arreglar', 'reparer', 'réparer', 'réparation', 'reparieren', 'reparo', 'consertar' ),
            'general'         => array( 'question', 'information', 'hours', 'opening hours', 'do you', 'pregunta', 'renseignement', 'frage' ),
        );
    }

    /** Urgency phrases. Each reason code maps to phrases; a negated phrase does not count. */
    public static function urgency_terms() {
        return array(
            'explicit_urgent' => array( 'urgent', 'urgently', 'emergency', 'asap', 'immediately', 'right away', 'right now', 'as soon as possible', 'today', 'tonight', 'flooding', 'burst pipe', 'gas leak', 'smell gas', 'sparks', 'sparking', 'no power', 'no electricity', 'urgente', 'urgence', 'dringend' ),
            'callback_request' => array( 'call me back', 'ring me back', 'callback', 'call back', 'phone me back', 'give me a call', 'call me', 'please call', 'llamame', 'rappelez', 'rappeler', 'rückruf' ),
        );
    }

    const EXPIRY_TERMS = array( 'expires', 'expiring', 'expire', 'valid until', 'valid till', 'last chance', 'deadline', 'offer ends', 'closing date' );

    /** Spam phrases. Only a signal on its own, never a hold alone. */
    const SPAM_PHRASES = array( 'casino', 'crypto', 'bitcoin', 'forex', 'loan approval', 'seo services', 'guest post', 'backlinks', 'cheap followers', 'earn money fast', 'viagra', 'telegram me' );

    /** Built-in service keywords for quote drafts. Callers may pass their own list. */
    const SERVICE_TERMS = array(
        'cleaning' => array( 'clean', 'cleaning', 'cleaner' ),
        'plumbing' => array( 'plumb', 'plumber', 'plumbing', 'drain', 'tap', 'toilet' ),
        'electrical' => array( 'electric', 'electrical', 'electrician', 'wiring', 'power point' ),
        'painting' => array( 'paint', 'painting', 'painter' ),
        'gardening' => array( 'garden', 'gardening', 'lawn', 'mowing', 'hedge' ),
        'pest' => array( 'pest', 'pests', 'termite', 'termites' ),
        'roofing' => array( 'roof', 'roofing', 'gutter', 'gutters' ),
    );

    /** Normalise text for comparison: NFKC, case fold, zero-width characters removed, apostrophes unified. */
    public static function normalize( $text ) {
        $text = (string) $text;
        // Invalid UTF-8 has no usable text for comparison. Returning '' keeps it out of every duplicate check.
        if ( 1 !== preg_match( '//u', $text ) ) { return ''; }
        if ( class_exists( 'Normalizer' ) ) {
            $nfkc = Normalizer::normalize( $text, Normalizer::FORM_KC );
            if ( false !== $nfkc ) { $text = $nfkc; }
        }
        $text = preg_replace( '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]/u', '', $text );
        $text = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98", '`' ), "'", $text );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( (string) $text );
    }

    /** Word tokens: letters, digits and apostrophes, Unicode aware. */
    public static function tokens( $text ) {
        $parts = preg_split( "/[^\\p{L}\\p{N}']+/u", self::normalize( $text ), -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? $parts : array();
    }

    /** Signature for identical-message checks: same tokens in the same order, whatever the case, punctuation or Unicode form. */
    public static function signature( $text ) {
        $tokens = self::tokens( $text );
        return $tokens ? hash( 'sha256', implode( ' ', $tokens ) ) : '';
    }

    /**
     * Returns the terms found in the tokens and not negated. Each term is a list of words.
     *
     * @param string[] $tokens
     * @param string[] $terms
     * @return string[]
     */
    public static function find_terms( array $tokens, array $terms ) {
        $found = array();
        $count = count( $tokens );
        foreach ( $terms as $term ) {
            $words = explode( ' ', $term );
            $len   = count( $words );
            for ( $i = 0; $i + $len <= $count; $i++ ) {
                if ( array_slice( $tokens, $i, $len ) !== $words ) { continue; }
                $negated = false;
                for ( $j = max( 0, $i - self::NEGATION_WINDOW ); $j < $i; $j++ ) {
                    if ( in_array( $tokens[ $j ], self::NEGATORS, true ) ) { $negated = true; break; }
                }
                if ( ! $negated ) { $found[] = $term; break; }
            }
        }
        return array_values( array_unique( $found ) );
    }

    /**
     * Intent classification.
     *
     * @return array{intents: string[], primary: string}
     */
    public static function classify_intents( $text ) {
        $tokens  = self::tokens( $text );
        $intents = array();
        foreach ( self::intent_terms() as $intent => $terms ) {
            if ( self::find_terms( $tokens, $terms ) ) { $intents[] = $intent; }
        }
        $primary = 'general';
        foreach ( self::INTENT_ORDER as $candidate ) {
            if ( in_array( $candidate, $intents, true ) ) { $primary = $candidate; break; }
        }
        if ( empty( $intents ) ) { $intents = array( 'general' ); }
        return array( 'intents' => array_values( $intents ), 'primary' => $primary );
    }

    /**
     * Urgency. A reason is any explicit urgent phrase, a complaint, a callback request, or an expiring date.
     * Nothing here scores revenue or predicts value.
     *
     * @param int|null $now Unix time, used for expiring dates. Defaults to the current time.
     * @return array{level: string, reasons: string[]}
     */
    public static function urgency( $text, $now = null ) {
        $now    = null === $now ? time() : (int) $now;
        $tokens = self::tokens( $text );
        $reasons = array();
        foreach ( self::urgency_terms() as $code => $terms ) {
            if ( self::find_terms( $tokens, $terms ) ) { $reasons[] = $code; }
        }
        if ( in_array( 'complaint', self::classify_intents( $text )['intents'], true ) ) { $reasons[] = 'complaint'; }
        if ( self::expiring_date_found( $text, $now ) ) { $reasons[] = 'expiring_date'; }
        $reasons = array_values( array_unique( $reasons ) );
        return array( 'level' => $reasons ? 'urgent' : 'normal', 'reasons' => $reasons );
    }

    /** True when an expiry phrase is present and a date named in the text falls within 14 days after $now. */
    public static function expiring_date_found( $text, $now ) {
        if ( ! self::find_terms( self::tokens( $text ), self::EXPIRY_TERMS ) ) { return false; }
        foreach ( self::dates_in( $text, (int) gmdate( 'Y', $now ) ) as $ymd ) {
            $ts = strtotime( $ymd . ' 00:00:00 UTC' );
            if ( false !== $ts && $ts >= $now - 86400 && $ts <= $now + 14 * 86400 ) { return true; }
        }
        return false;
    }

    /**
     * Dates named in the text as Y-m-d strings, de-duplicated and sorted. Numeric dates are day first (d/m/y).
     * Formats: 2026-11-20, 20/11/2026, 20-11-26, 20 Nov, Nov 20 2026. Invalid calendar dates are ignored.
     *
     * @return string[]
     */
    public static function dates_in( $text, $default_year ) {
        $text   = self::normalize( $text );
        $found  = array();
        $months = array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 );
        $add = function ( $y, $m, $d ) use ( &$found ) {
            if ( checkdate( (int) $m, (int) $d, (int) $y ) ) { $found[] = sprintf( '%04d-%02d-%02d', (int) $y, (int) $m, (int) $d ); }
        };
        if ( preg_match_all( '/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) { $add( $x[1], $x[2], $x[3] ); }
        }
        if ( preg_match_all( '/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})\b/', $text, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) {
                $year = 4 === strlen( $x[3] ) ? $x[3] : 2000 + (int) $x[3];
                $add( $year, $x[2], $x[1] );
            }
        }
        $month_re = '(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?';
        if ( preg_match_all( '/\b(\d{1,2})(?:st|nd|rd|th)?\s+' . $month_re . '(?:\s+(\d{4}))?\b/', $text, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) { $add( ! empty( $x[4] ) ? $x[4] : $default_year, $months[ substr( $x[2], 0, 3 ) ], $x[1] ); }
        }
        if ( preg_match_all( '/\b' . $month_re . '\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\b/', $text, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) { $add( ! empty( $x[3] ) ? $x[3] : $default_year, $months[ substr( $x[1], 0, 3 ) ], $x[2] ); }
        }
        $found = array_values( array_unique( $found ) );
        sort( $found );
        return $found;
    }

    /** Word n-grams (3 tokens) for similarity. Texts shorter than 3 tokens have none. */
    private static function shingles( array $tokens ) {
        $set = array();
        for ( $i = 0; $i + 3 <= count( $tokens ); $i++ ) { $set[ implode( ' ', array_slice( $tokens, $i, 3 ) ) ] = true; }
        return $set;
    }

    /** Jaccard similarity of two token lists, from 0 to 1. */
    public static function similarity( array $a, array $b ) {
        $sa = self::shingles( $a );
        $sb = self::shingles( $b );
        if ( empty( $sa ) || empty( $sb ) ) { return 0.0; }
        $inter = count( array_intersect_key( $sa, $sb ) );
        $union = count( $sa + $sb );
        return $union ? $inter / $union : 0.0;
    }

    /** Count of http, https and www links. */
    public static function link_count( $text ) {
        return (int) preg_match_all( '/(?:https?:\/\/|www\.)\S+/iu', (string) $text );
    }

    /**
     * Spam and duplicate signals for one new enquiry.
     *
     * $prior rows must already be limited to the same company by the caller. Each row: signature, tokens, email,
     * ip_hash, ts (Unix time). Rows older than the window are ignored, and the current enquiry is never in $priors.
     *
     * Weights: identical text 1, near duplicate 1 (3-word shingle similarity 0.80 or more; short texts under 4 words never match), repeat from the same contact 2, burst from one IP 1 (only as
     * part of a larger score), link volume (3 or more links) 2, spam phrase 2, mostly symbols 1.
     * A score at or above SPAM_HOLD_SCORE holds the enquiry for review. A burst from a shared IP (NAT) or a duplicate
     * alone never holds it.
     *
     * @return array{score: int, signals: string[], hold: bool, duplicate_of: string, dup_kind: string}
     */
    public static function spam_assessment( $text, $email, $ip_hash, $now, array $priors ) {
        $tokens    = self::tokens( $text );
        $signature = self::signature( $text );
        $email     = strtolower( trim( (string) $email ) );
        $signals   = array();
        $score     = 0;
        $dup_kind  = '';
        $same_contact = 0;
        $burst     = 0;
        $near      = false;
        $identical = false;

        $priors = array_slice( array_values( $priors ), -self::MAX_PRIORS );
        foreach ( $priors as $p ) {
            $ts = isset( $p['ts'] ) ? (int) $p['ts'] : 0;
            // Clock skew: a row up to 5 minutes in the future still counts. Older than the window does not.
            $age = $now - $ts;
            if ( $age > self::DUPLICATE_WINDOW || $age < -300 ) { continue; }
            $same_sig = '' !== $signature && isset( $p['signature'] ) && $p['signature'] === $signature;
            if ( $same_sig ) {
                $identical = true;
                if ( '' !== $email && strtolower( (string) ( $p['email'] ?? '' ) ) === $email ) { $same_contact++; }
            } elseif ( ! empty( $p['tokens'] ) && self::similarity( $tokens, (array) $p['tokens'] ) >= self::NEAR_DUP_SIMILAR ) {
                $near = true;
            }
            if ( '' !== (string) $ip_hash && isset( $p['ip_hash'] ) && $p['ip_hash'] === $ip_hash && $age <= self::BURST_WINDOW ) { $burst++; }
        }

        if ( $identical ) { $signals[] = 'identical_text'; $score += 1; $dup_kind = 'identical'; }
        if ( $near ) { $signals[] = 'near_duplicate'; $score += 1; if ( '' === $dup_kind ) { $dup_kind = 'near'; } }
        if ( $same_contact >= 2 ) { $signals[] = 'repeat_same_contact'; $score += 2; }
        if ( $burst >= self::BURST_MIN ) { $signals[] = 'burst_from_address'; $score += 1; }
        if ( self::link_count( $text ) >= 3 ) { $signals[] = 'link_volume'; $score += 2; }
        if ( self::find_terms( $tokens, self::SPAM_PHRASES ) ) { $signals[] = 'spam_phrase'; $score += 2; }
        $letters = preg_match_all( '/\p{L}/u', (string) $text );
        $chars   = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
        if ( $chars >= 20 && $letters / $chars < 0.4 ) { $signals[] = 'mostly_symbols'; $score += 1; }

        $dup_of = '';
        if ( 'identical' === $dup_kind ) { $dup_of = 'identical'; } elseif ( 'near' === $dup_kind ) { $dup_of = 'near'; }
        return array(
            'score'        => $score,
            'signals'      => $signals,
            'hold'         => $score >= self::SPAM_HOLD_SCORE,
            'duplicate_of' => $dup_of,
            'dup_kind'     => $dup_kind,
        );
    }

    /**
     * Full triage result for one enquiry. Pure: the caller supplies the time and any prior rows.
     *
     * @return array Keys: intents, primary_intent, urgency, urgency_reasons, spam (see spam_assessment), route, signature, quote_draft|null.
     */
    public static function evaluate( $text, $email, $ip_hash, $now, array $priors = array(), array $service_terms = null, $default_year = null ) {
        $now     = (int) $now;
        $intent  = self::classify_intents( $text );
        $urgency = self::urgency( $text, $now );
        $spam    = self::spam_assessment( $text, $email, $ip_hash, $now, $priors );
        $default_year = $default_year ? (int) $default_year : (int) gmdate( 'Y', $now );

        $primary = $spam['hold'] ? 'spam' : $intent['primary'];
        $intents = $spam['hold'] ? array_merge( array( 'spam' ), $intent['intents'] ) : $intent['intents'];

        $route = 'enquiry_queue';
        if ( $spam['hold'] ) { $route = 'spam_review'; }
        elseif ( 'quote' === $intent['primary'] ) { $route = 'quote_review'; }
        elseif ( 'complaint' === $intent['primary'] ) { $route = 'complaint_review'; }

        return array(
            'intents'          => array_values( array_unique( $intents ) ),
            'primary_intent'   => $primary,
            'urgency'          => $urgency['level'],
            'urgency_reasons'  => $urgency['reasons'],
            'spam'             => $spam,
            'route'            => $route,
            'signature'        => self::signature( $text ),
            'quote_draft'      => in_array( 'quote', $intent['intents'], true ) ? self::quote_draft( $text, $service_terms, $default_year ) : null,
        );
    }

    /**
     * Structured quote enquiry draft from explicitly supplied text. It never holds a price, a total, a labour or
     * materials figure, or an approval. Contact details are not copied: the enquiry row already holds them.
     *
     * @return array
     */
    public static function quote_draft( $text, array $service_terms = null, $default_year = null ) {
        $terms   = null === $service_terms ? self::SERVICE_TERMS : $service_terms;
        $tokens  = self::tokens( $text );
        $default_year = $default_year ? (int) $default_year : (int) gmdate( 'Y' );
        $service = '';
        foreach ( $terms as $label => $words ) {
            $list = array();
            foreach ( (array) $words as $w ) { $list[] = self::normalize( $w ); }
            if ( self::find_terms( $tokens, $list ) ) { $service = (string) $label; break; }
        }
        $dates = self::dates_in( $text, $default_year );
        $flags = array();
        $requested = '';
        if ( count( $dates ) > 1 ) { $flags[] = 'contradictory_dates'; } elseif ( 1 === count( $dates ) ) { $requested = $dates[0]; }

        $site = '';
        if ( preg_match( '/(?:^|\n)\s*(?:job\s+)?(?:site|address|location|property)\s*[:\-]\s*([^\r\n]{3,200})/iu', (string) $text, $m ) ) {
            $site = self::redact( trim( $m[1] ) );
        }

        $missing = array();
        if ( '' === $service ) { $missing[] = 'service_type'; }
        if ( '' === $requested && ! in_array( 'contradictory_dates', $flags, true ) ) { $missing[] = 'requested_date'; }
        if ( '' === $site ) { $missing[] = 'site'; }

        return array(
            'service_type'   => $service,
            'requested_date' => $requested,
            'site'           => $site,
            'missing'        => $missing,
            'flags'          => $flags,
            'binding'        => false,
            'source'         => 'rules',
            'corrected'      => array(),
        );
    }

    /**
     * Owner correction of a draft. Only whitelisted fields change. A corrected date clears the contradiction flag.
     * Prices and contact fields are not accepted.
     *
     * @return array|WP_Error-like array with 'error' key on refusal.
     */
    public static function apply_correction( array $draft, array $changes, $actor_id ) {
        $allowed = array( 'service_type', 'requested_date', 'site' );
        $out     = $draft;
        $fields  = array();
        foreach ( $changes as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) ) { return array( 'error' => 'field_not_allowed', 'field' => (string) $key ); }
            if ( 'requested_date' === $key ) {
                if ( '' !== (string) $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', (string) $value ) ) { return array( 'error' => 'bad_date' ); }
                $parts = explode( '-', (string) $value );
                if ( '' !== (string) $value && ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) { return array( 'error' => 'bad_date' ); }
                $out['flags'] = array_values( array_diff( $out['flags'], array( 'contradictory_dates' ) ) );
            }
            if ( 'site' === $key ) { $value = self::redact( mb_substr( trim( (string) $value ), 0, 200, 'UTF-8' ) ); }
            if ( 'service_type' === $key ) { $value = preg_replace( '/[^a-z_]/', '', strtolower( (string) $value ) ); }
            $out[ $key ] = (string) $value;
            $fields[]    = $key;
        }
        $out['missing'] = array_values( array_filter( $out['missing'], function ( $f ) use ( $out ) { return '' === (string) $out[ $f ]; } ) );
        if ( in_array( 'contradictory_dates', $out['flags'], true ) === false && '' === (string) $out['requested_date'] ) { $out['missing'][] = 'requested_date'; }
        $out['missing'] = array_values( array_unique( $out['missing'] ) );
        $out['corrected'] = array_values( array_unique( array_merge( (array) $out['corrected'], $fields ) ) );
        $out['corrected_by'] = (int) $actor_id;
        return $out;
    }

    /** Hides email addresses and phone-like numbers. Used for any text that leaves the enquiry row. */
    public static function redact( $text ) {
        $text = preg_replace( '/[^\s@<>()]+@[^\s@<>()]+\.[a-z]{2,}/iu', '[email]', (string) $text );
        return (string) preg_replace( '/(?<!\w)\+?\d[\d\s().-]{6,}\d(?!\w)/u', '[phone]', (string) $text );
    }
}
