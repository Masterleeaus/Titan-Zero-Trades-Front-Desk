<?php
/**
 * Quote request classifier (rules only, no hosted AI, no WordPress calls).
 *
 * Reads one stored enquiry and returns a structured quote enquiry DRAFT. It extracts only what the customer
 * explicitly wrote: request intent, a named service term, a requested date, a stated site and the contact fields
 * the form already captured. It never produces a price, a labour or materials estimate, a quote acceptance or a
 * booking commitment. Every draft is routed to owner review. Owner corrections are validated against the same rules.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Quote_Request_Classifier {

    const VERSION      = 'quote-intake-1';
    const ROUTE        = 'owner_review';
    const STATUS       = 'draft';
    const MAX_TEXT     = 5000;
    const MAX_SITE     = 190;

    /** Words that mark a request for a quote, estimate or price. Matched on word boundaries, case-insensitive. */
    const INTENT_TERMS = array( 'quote', 'quotes', 'quotation', 'estimate', 'how much', 'price', 'pricing', 'cost' );

    /** Caller-independent pattern for an explicitly labelled site, address or location line. */
    const SITE_PATTERN = '/(?:^|\n)\s*(?:site|address|location|property)\s*[:\-]\s*([^\r\n]{3,190})/iu';

    /**
     * Classifies one enquiry.
     *
     * @param array  $enquiry        Keys: company_id, name, email, phone, enquiry (text).
     * @param array  $service_terms  Service labels from the business catalogue. Only an exact term written by the customer counts.
     * @param string $today          Y-m-d reference date, used to refuse dates in the past. Empty disables the check.
     * @return array Draft array, or an array with an 'error' key when refused.
     */
    public static function classify( $enquiry, $service_terms = array(), $today = '' ) {
        if ( ! is_array( $enquiry ) ) { return self::refuse( 'bad_input' ); }
        $company_id = self::positive_int( isset( $enquiry['company_id'] ) ? $enquiry['company_id'] : 0 );
        if ( 0 === $company_id ) { return self::refuse( 'no_company' ); }

        $text = self::scalar_text( isset( $enquiry['enquiry'] ) ? $enquiry['enquiry'] : '', self::MAX_TEXT );
        $lower = mb_strtolower( $text, 'UTF-8' );

        $intent = self::has_term( $lower, self::INTENT_TERMS ) ? 'quote_request' : 'other';

        $service = '';
        $service_matches = array();
        foreach ( (array) $service_terms as $term ) {
            $term = self::scalar_text( $term, 60 );
            if ( '' !== $term && self::has_term( $lower, array( mb_strtolower( $term, 'UTF-8' ) ) ) ) {
                $service_matches[ $term ] = true;
            }
        }
        if ( 1 === count( $service_matches ) ) { $service = (string) array_key_first( $service_matches ); }

        $dates = self::explicit_dates( $text );
        $date_conflict = count( $dates ) > 1;
        $requested_date = '';
        if ( ! $date_conflict && 1 === count( $dates ) ) {
            $candidate = $dates[0];
            if ( '' === $today || $candidate >= $today ) { $requested_date = $candidate; }
        }

        $site = '';
        if ( preg_match( self::SITE_PATTERN, $text, $m ) ) { $site = self::scalar_text( $m[1], self::MAX_SITE ); }

        $email = self::form_email( isset( $enquiry['email'] ) ? $enquiry['email'] : '' );
        $phone = self::form_phone( isset( $enquiry['phone'] ) ? $enquiry['phone'] : '' );
        $name  = self::scalar_text( isset( $enquiry['name'] ) ? $enquiry['name'] : '', 190 );

        $missing = array();
        if ( 'quote_request' === $intent && '' === $service ) { $missing[] = 'service'; }
        if ( 'quote_request' === $intent && '' === $requested_date ) { $missing[] = 'requested_date'; }
        if ( 'quote_request' === $intent && '' === $site ) { $missing[] = 'site'; }
        if ( '' === $email && '' === $phone ) { $missing[] = 'contact'; }

        $flags = array();
        if ( $date_conflict ) { $flags[] = 'contradictory_dates'; }
        if ( self::contains_price( $lower ) ) { $flags[] = 'customer_mentioned_price_ignored'; }
        if ( '' !== $email && '' === $phone ) { $flags[] = 'phone_not_given'; }

        return array(
            'company_id'     => $company_id,
            'version'        => self::VERSION,
            'status'         => self::STATUS,
            'route'          => self::ROUTE,
            'intent'         => $intent,
            'name'           => $name,
            'service'        => $service,
            'requested_date' => $requested_date,
            'site'           => $site,
            'contact'        => array( 'email' => $email, 'phone' => $phone ),
            'missing'        => $missing,
            'flags'          => $flags,
            'summary'        => self::redact( $text ),
            // Hard rules: no binding price, no approval, no invented estimate.
            'price'          => null,
            'approved'       => false,
            'binding'        => false,
            'sources'        => array( 'service' => $service ? 'customer_text' : '', 'requested_date' => $requested_date ? 'customer_text' : '', 'site' => $site ? 'customer_text' : 'missing', 'contact' => 'form_fields' ),
        );
    }

    /**
     * Applies an owner correction to a draft. Only the editable fields below are accepted. The draft must belong to
     * $company_id (tenant check). Prices, approval and binding flags can never be set by a correction.
     *
     * @return array|array with 'error' key
     */
    public static function apply_owner_correction( $draft, $corrections, $company_id ) {
        if ( ! is_array( $draft ) || ! is_array( $corrections ) ) { return self::refuse( 'bad_input' ); }
        $company_id = self::positive_int( $company_id );
        if ( 0 === $company_id || (int) $draft['company_id'] !== $company_id ) { return self::refuse( 'tenant_mismatch' ); }

        $allowed = array( 'service', 'requested_date', 'site' );
        foreach ( array_keys( $corrections ) as $key ) {
            if ( ! in_array( $key, $allowed, true ) && ! in_array( $key, array( 'email', 'phone' ), true ) ) {
                return self::refuse( 'field_not_editable' );
            }
        }
        if ( array_key_exists( 'requested_date', $corrections ) ) {
            $value = (string) $corrections['requested_date'];
            if ( '' !== $value && ! self::valid_iso_date( $value ) ) { return self::refuse( 'bad_date' ); }
            $draft['requested_date'] = $value;
        }
        if ( array_key_exists( 'service', $corrections ) ) { $draft['service'] = self::scalar_text( $corrections['service'], 60 ); }
        if ( array_key_exists( 'site', $corrections ) ) { $draft['site'] = self::scalar_text( $corrections['site'], self::MAX_SITE ); }
        if ( array_key_exists( 'email', $corrections ) ) { $draft['contact']['email'] = self::form_email( $corrections['email'] ); }
        if ( array_key_exists( 'phone', $corrections ) ) { $draft['contact']['phone'] = self::form_phone( $corrections['phone'] ); }

        $draft['missing'] = array_values( array_filter( $draft['missing'], function ( $field ) use ( $draft ) {
            if ( 'service' === $field ) { return '' === $draft['service']; }
            if ( 'requested_date' === $field ) { return '' === $draft['requested_date']; }
            if ( 'site' === $field ) { return '' === $draft['site']; }
            if ( 'contact' === $field ) { return '' === $draft['contact']['email'] && '' === $draft['contact']['phone']; }
            return true;
        } ) );
        $draft['sources']['owner_corrected'] = array_keys( $corrections );
        $draft['price'] = null;
        $draft['approved'] = false;
        $draft['binding'] = false;
        return $draft;
    }

    /** Replaces email addresses and phone numbers in free text with placeholders, for logs and list views. */
    public static function redact( $text ) {
        $text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', (string) $text );
        return (string) preg_replace( '/(?<![\p{L}\p{N}])\+?\d[\d \-()]{7,}\d(?![\p{L}\p{N}])/u', '[phone]', $text );
    }

    /** Explicit calendar dates: ISO Y-m-d or d/m/Y (Australian order). Invalid calendar dates are dropped. */
    private static function explicit_dates( $text ) {
        $found = array();
        if ( preg_match_all( '/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $text, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) { if ( checkdate( (int) $x[2], (int) $x[3], (int) $x[1] ) ) { $found[] = $x[1] . '-' . $x[2] . '-' . $x[3]; } }
        }
        if ( preg_match_all( '/(?<!\d)(\d{1,2})\/(\d{1,2})\/(\d{4})(?!\d)/', $text, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) {
                if ( checkdate( (int) $x[2], (int) $x[1], (int) $x[3] ) ) { $found[] = sprintf( '%04d-%02d-%02d', (int) $x[3], (int) $x[2], (int) $x[1] ); }
            }
        }
        return array_values( array_unique( $found ) );
    }

    private static function valid_iso_date( $value ) {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) { return false; }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    private static function has_term( $lower, $terms ) {
        foreach ( $terms as $term ) {
            if ( '' !== $term && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/u', $lower ) ) { return true; }
        }
        return false;
    }

    private static function contains_price( $lower ) {
        return (bool) preg_match( '/[$£€]\s*\d|\d\s*(?:dollars|aud|usd)\b/iu', $lower );
    }

    private static function form_email( $value ) {
        $value = self::scalar_text( $value, 190 );
        return ( '' !== $value && false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ) ? $value : '';
    }

    private static function form_phone( $value ) {
        $value = self::scalar_text( $value, 60 );
        if ( '' === $value ) { return ''; }
        $digits = preg_replace( '/\D/', '', $value );
        return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 && preg_match( '/^\+?[\d \-()]+$/', $value ) ) ? $value : '';
    }

    private static function scalar_text( $value, $max ) {
        if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) { return ''; }
        $value = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value ) );
        return mb_substr( $value, 0, $max, 'UTF-8' );
    }

    private static function positive_int( $value ) {
        return ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) && (int) $value > 0 ? (int) $value : 0;
    }

    private static function refuse( $code ) {
        return array( 'error' => $code );
    }
}
