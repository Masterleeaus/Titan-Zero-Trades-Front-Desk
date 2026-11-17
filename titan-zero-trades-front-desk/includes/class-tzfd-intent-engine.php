<?php
/**
 * Deterministic intent classifier for Free enquiries (#42).
 *
 * Pure PHP, no network and no model. Used by the rules-first FAQ and knowledge answerer (#21, #22) and by later
 * enquiry lanes. It only labels a message. It never books, cancels or changes anything, and it never decides
 * permissions. Booking authority stays with the deterministic booking engine.
 *
 * Method: lower-case, strip punctuation, fold apostrophes, apply phrase rules, map each word through a synonym
 * table to a canonical word, then score intent categories by keyword hits. A keyword is negated when a negation
 * word appears within the three words before it ("I do not want to cancel" does not score cancel).
 *
 * Intent is returned as one of the category names below, or 'unknown'. Only English rules ship in this change.
 * Other languages are accepted by the cache and FAQ store but receive no rule-based intent until rules are added.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Intent_Engine {

    const UNKNOWN = 'unknown';

    /** Priority order used only to break ties. Emergency wins so urgent wording is never buried. */
    const PRIORITY = array( 'emergency', 'cancel', 'booking', 'price', 'hours', 'area', 'services', 'contact', 'policy' );

    /** Words that flip the meaning of the next few keywords. */
    const NEGATIONS = array(
        'not', 'no', 'never', 'dont', 'doesnt', 'didnt', 'cant', 'cannot', 'wont', 'isnt', 'arent',
        'wasnt', 'werent', 'without', 'none', 'nothing', 'nope',
    );

    /** How many preceding words are searched for a negation. */
    const NEGATION_WINDOW = 3;

    /** Multi-word phrases rewritten to a single canonical word before tokenising. Keys are normalised text. */
    const PHRASES = array(
        'how much'        => 'price',
        'what time'       => 'hours',
        'what hours'      => 'hours',
        'are you open'    => 'hours',
        'open late'       => 'hours',
        'after hours'     => 'hours',
        'service area'    => 'area',
        'service areas'   => 'area',
        'where do you'    => 'area',
        'come out to'     => 'area',
        'do you come'     => 'area',
        'in an emergency' => 'emergency',
        'right away'      => 'asap',
        'as soon as'      => 'asap',
    );

    /** Canonical word for each keyword. The category a canonical word belongs to is in CATEGORY_WORDS. */
    const SYNONYMS = array(
        'cost' => 'price', 'costs' => 'price', 'prices' => 'price', 'pricing' => 'price', 'priced' => 'price',
        'quote' => 'price', 'quotes' => 'price', 'estimate' => 'price', 'estimates' => 'price', 'fee' => 'price',
        'fees' => 'price', 'charge' => 'price', 'charges' => 'price', 'rate' => 'price', 'rates' => 'price',
        'expensive' => 'price', 'cheap' => 'price', 'asap' => 'asap',

        'hour' => 'hours', 'opening' => 'hours', 'open' => 'hours', 'opens' => 'hours', 'closed' => 'hours',
        'close' => 'hours', 'closing' => 'hours', 'weekend' => 'hours', 'weekends' => 'hours', 'saturday' => 'hours',
        'sunday' => 'hours', 'holiday' => 'hours', 'holidays' => 'hours', 'timetable' => 'hours', 'late' => 'hours',

        'areas' => 'area', 'suburb' => 'area', 'suburbs' => 'area', 'postcode' => 'area', 'postcodes' => 'area',
        'location' => 'area', 'locations' => 'area', 'where' => 'area', 'cover' => 'area', 'covers' => 'area',
        'coverage' => 'area', 'travel' => 'area', 'region' => 'area', 'zone' => 'area',

        'services' => 'service', 'offer' => 'service', 'offers' => 'service', 'provide' => 'service',
        'provides' => 'service', 'repair' => 'service', 'repairs' => 'service', 'install' => 'service',
        'installation' => 'service', 'clean' => 'service', 'cleaning' => 'service',

        'books' => 'book', 'booking' => 'book', 'bookings' => 'book', 'appointment' => 'book',
        'appointments' => 'book', 'slot' => 'book', 'slots' => 'book', 'availability' => 'book',
        'available' => 'book', 'reserve' => 'book', 'reservation' => 'book', 'schedule' => 'book',

        'cancels' => 'cancel', 'cancelled' => 'cancel', 'canceled' => 'cancel', 'cancellation' => 'cancel',
        'reschedule' => 'cancel', 'rescheduling' => 'cancel', 'postpone' => 'cancel', 'move' => 'cancel',

        'emergencies' => 'emergency', 'urgent' => 'emergency', 'urgently' => 'emergency', 'flood' => 'emergency',
        'flooding' => 'emergency', 'burst' => 'emergency', 'emergency' => 'emergency',

        'phone' => 'contact', 'call' => 'contact', 'number' => 'contact', 'email' => 'contact',
        'contact' => 'contact', 'reach' => 'contact', 'ring' => 'contact', 'whatsapp' => 'contact',

        'policy' => 'policy', 'policies' => 'policy', 'refund' => 'policy', 'refunds' => 'policy',
        'guarantee' => 'policy', 'guarantees' => 'policy', 'warranty' => 'policy', 'insurance' => 'policy',
        'terms' => 'policy', 'deposit' => 'policy', 'deposits' => 'policy', 'pay' => 'policy',
        'payment' => 'policy', 'payments' => 'policy',
    );

    /** Which canonical words count as evidence for each intent category. */
    const CATEGORY_WORDS = array(
        'price'     => array( 'price' ),
        'hours'     => array( 'hours' ),
        'area'      => array( 'area' ),
        'services'  => array( 'service' ),
        'booking'   => array( 'book' ),
        'cancel'    => array( 'cancel' ),
        'emergency' => array( 'emergency', 'asap' ),
        'contact'   => array( 'contact' ),
        'policy'    => array( 'policy' ),
    );

    /** Words that carry no meaning for matching. Negations are never stop words. */
    const STOP_WORDS = array(
        'a', 'an', 'the', 'is', 'are', 'am', 'was', 'were', 'do', 'does', 'did', 'you', 'your', 'yours', 'i', 'my',
        'me', 'we', 'our', 'us', 'to', 'for', 'of', 'in', 'on', 'at', 'it', 'its', 'be', 'can', 'could', 'would',
        'will', 'what', 'how', 'there', 'this', 'that', 'please', 'any', 'with', 'and', 'or', 'if', 'so', 'some',
        'have', 'has', 'get', 'want', 'like', 'know', 'tell', 'by', 'from', 'about', 'they', 'them', 'their',
        'redacted', 'email', 'number',
    );

    /**
     * Lower-case, fold apostrophes, drop punctuation, collapse spaces.
     * Digits are kept so postcodes and phone numbers survive normalisation.
     */
    public static function normalise( $text ) {
        $text = is_string( $text ) ? $text : '';
        $text = str_replace( array( "\u{2019}", "\u{2018}", '`', "\u{00B4}" ), "'", $text );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $text = str_replace( "'", '', $text );                       // don't -> dont
        $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );     // punctuation and symbols become spaces
        $text = preg_replace( '/\s+/u', ' ', (string) $text );
        return trim( (string) $text );
    }

    /**
     * Word list after phrase rewriting and synonym mapping, keeping negations and stop words.
     * Used for intent scoring. Use content_tokens() for matching.
     */
    public static function canonical_tokens( $text ) {
        $norm = self::normalise( $text );
        foreach ( self::PHRASES as $phrase => $replacement ) {
            $norm = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/u', $replacement, $norm );
        }
        if ( '' === $norm ) { return array(); }
        $out = array();
        foreach ( explode( ' ', $norm ) as $word ) {
            $out[] = isset( self::SYNONYMS[ $word ] ) ? self::SYNONYMS[ $word ] : $word;
        }
        return $out;
    }

    /**
     * Unique meaningful words for matching: synonyms applied, stop words removed, negations kept.
     * Negations are kept as their own tokens so that matching can compare polarity.
     */
    public static function content_tokens( $text ) {
        $out = array();
        foreach ( self::canonical_tokens( $text ) as $word ) {
            if ( in_array( $word, self::STOP_WORDS, true ) ) { continue; }
            $out[ $word ] = true;
        }
        return array_keys( $out );
    }

    /** True when the text contains a negation word. */
    public static function has_negation( $text ) {
        foreach ( self::canonical_tokens( $text ) as $word ) {
            if ( in_array( $word, self::NEGATIONS, true ) ) { return true; }
        }
        return false;
    }

    /**
     * Classify one message.
     *
     * @return array{intent:string,confidence:float,ambiguous:bool,scores:array<string,int>,negated:string[]}
     */
    public static function classify( $text ) {
        $tokens   = self::canonical_tokens( $text );
        $scores   = array_fill_keys( array_keys( self::CATEGORY_WORDS ), 0 );
        $negated  = array();
        $wordToCat = array();
        foreach ( self::CATEGORY_WORDS as $cat => $words ) {
            foreach ( $words as $w ) { $wordToCat[ $w ] = $cat; }
        }

        foreach ( $tokens as $i => $word ) {
            if ( ! isset( $wordToCat[ $word ] ) ) { continue; }
            $cat    = $wordToCat[ $word ];
            $start  = max( 0, $i - self::NEGATION_WINDOW );
            $is_neg = false;
            for ( $j = $start; $j < $i; $j++ ) {
                if ( in_array( $tokens[ $j ], self::NEGATIONS, true ) ) { $is_neg = true; break; }
            }
            if ( $is_neg ) {
                $negated[] = $cat;
            } else {
                $scores[ $cat ]++;
            }
        }

        $best = 0;
        foreach ( $scores as $s ) { $best = max( $best, $s ); }
        if ( 0 === $best ) {
            return array( 'intent' => self::UNKNOWN, 'confidence' => 0.0, 'ambiguous' => false, 'scores' => $scores, 'negated' => array_values( array_unique( $negated ) ) );
        }

        $leaders = array();
        foreach ( $scores as $cat => $s ) {
            if ( $s === $best ) { $leaders[] = $cat; }
        }
        $ambiguous = count( $leaders ) > 1;
        $intent    = self::UNKNOWN;
        foreach ( self::PRIORITY as $cat ) {
            if ( in_array( $cat, $leaders, true ) ) { $intent = $cat; break; }
        }

        // One keyword is weak evidence and two or more is firm. Ties halve the confidence.
        $confidence = min( 1.0, $best / 2 );
        if ( $ambiguous ) { $confidence *= 0.5; }

        return array(
            'intent'     => $intent,
            'confidence' => round( $confidence, 3 ),
            'ambiguous'  => $ambiguous,
            'scores'     => $scores,
            'negated'    => array_values( array_unique( $negated ) ),
        );
    }

    /** Intent label only, with a confidence floor. Returns 'unknown' when evidence is weak or tied. */
    public static function confident_intent( $text, $floor = 0.5 ) {
        $result = self::classify( $text );
        if ( $result['ambiguous'] || $result['confidence'] < $floor ) { return self::UNKNOWN; }
        return $result['intent'];
    }
}
