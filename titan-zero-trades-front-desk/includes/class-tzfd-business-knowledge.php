<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads go through the scoped store, the services table and the audit journal; no direct SQL here.
/**
 * Free business knowledge adapter (issue #22). Rules-first and source-grounded.
 *
 * A question is answered only from the company's own verified settings (its non-archived services, with the
 * allowlisted fields name, description and duration) or from knowledge items an administrator approved. Items
 * edited after approval, expired items, unapproved items and items that look like instructions to an assistant
 * are never used. Knowledge text is returned as quoted data. It is not executed, not sent to a hosted model and
 * never changes a booking. Every cited answer is written to the audit journal before it is returned.
 * GPL-2.0-or-later.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-scoped-store.php';
require_once __DIR__ . '/class-tzfd-local-audit.php';
require_once __DIR__ . '/class-tzfd-rest-request-guard.php';

final class TZFD_Business_Knowledge {
    const OPTION         = 'tzfd_business_knowledge_v1';
    const REST_NAMESPACE = 'titan-zero-front-desk/v1';
    const MAX_ITEMS      = 100;
    const MAX_TITLE      = 120;
    const MAX_TEXT       = 1000;
    const MAX_QUESTION   = 200;
    const MAX_ANSWER     = 300;
    const REFUSAL_TEXT   = 'There is no approved answer for that question. Please contact the business directly.';

    const CATEGORIES = array( 'services', 'locations', 'hours', 'coverage', 'terms', 'policies' );

    /** Question words that point at a category. A match adds weight; it never answers on its own. */
    const TRIGGERS = array(
        'services'  => array( 'service', 'services', 'offer', 'offers', 'provide', 'provides', 'do', 'clean', 'cleaning', 'repair', 'repairs' ),
        'locations' => array( 'address', 'location', 'locations', 'where', 'located', 'office', 'directions' ),
        'hours'     => array( 'hour', 'hours', 'open', 'opening', 'close', 'closing', 'closed', 'time', 'times' ),
        'coverage'  => array( 'area', 'areas', 'suburb', 'suburbs', 'cover', 'covered', 'coverage', 'travel', 'region', 'postcode' ),
        'terms'     => array( 'term', 'terms', 'condition', 'conditions', 'payment', 'deposit', 'pay', 'invoice' ),
        'policies'  => array( 'policy', 'policies', 'cancel', 'cancellation', 'refund', 'reschedule', 'rescheduling', 'late', 'warranty' ),
    );

    const STOP = array( 'the', 'a', 'an', 'is', 'are', 'of', 'to', 'in', 'on', 'for', 'and', 'or', 'i', 'my', 'we', 'it', 'can', 'you', 'your', 'what', 'whats', 'be', 'at', 'with', 'which' );

    /** Text that tries to steer an assistant. Such text is refused on save and never used on read. */
    const INJECTION_PATTERN = '/(ignore\s+(all\s+|any\s+|the\s+)?(previous|prior|above|earlier)\s+(instructions?|prompts?|rules)|system\s+prompt|developer\s+message|you\s+are\s+now|disregard\s+(the\s+)?(rules|instructions)|<\s*\/?\s*script)/i';

    public static function register() {
        if ( ! function_exists( 'add_action' ) ) { return; }
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        if ( ! function_exists( 'register_rest_route' ) ) { return; }
        register_rest_route( self::REST_NAMESPACE, '/knowledge/answer', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_answer' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
        ) );
        register_rest_route( self::REST_NAMESPACE, '/knowledge/items', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_save' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
    }

    /** Staff who can use the front desk, for the current company only. */
    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'tz_fd_access' ) && self::company() > 0;
    }

    /** Only site administrators can approve or change knowledge. */
    public static function can_manage() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) && self::company() > 0;
    }

    /** The company comes only from the request context, never from request parameters. */
    private static function company() {
        return TZFD_Scoped_Store::company_id();
    }

    /** Answer one question. Returns status answered (with quoted source) or refused (with a reason). */
    public static function answer( $question, $now = null ) {
        $now = null === $now ? time() : (int) $now;
        $company = self::company();
        if ( $company < 1 ) { return self::refuse( 'no_company' ); }

        $question = self::clean( $question, self::MAX_QUESTION );
        if ( null === $question ) { return self::refuse( 'invalid_question' ); }
        $terms = self::tokens( $question );
        if ( empty( $terms ) ) { return self::refuse( 'invalid_question' ); }

        $best = null;
        $best_score = 0;
        foreach ( self::candidates( $now ) as $candidate ) {
            $score = self::score( $terms, $candidate );
            if ( $score > $best_score ) { $best = $candidate; $best_score = $score; }
        }
        if ( null === $best || $best_score < 1 ) { return self::refuse( 'no_approved_source' ); }

        $actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $logged = TZFD_Local_Audit::insert( $company, $actor, $actor > 0 ? 'user' : 'system', array(
            'action'    => 'knowledge.cited',
            'entity'    => 'local_service' === $best['source'] ? 'local_service' : 'knowledge_item',
            'entity_id' => (int) $best['id'],
            'summary'   => 'Answered a visitor question from an approved ' . $best['category'] . ' source',
        ) );
        // The citation must be journaled before the answer leaves the server. If it cannot be, refuse.
        if ( ! $logged ) { return self::refuse( 'audit_unavailable' ); }

        return array(
            'status' => 'answered',
            'answer' => self::excerpt( $best['text'] ),
            'quoted' => true,
            'source' => array(
                'type'        => $best['source'],
                'id'          => (int) $best['id'],
                'category'    => $best['category'],
                'title'       => $best['title'],
                'updated_utc' => $best['updated_utc'],
            ),
        );
    }

    /**
     * Save an item. Writes need manage_options. Without approve=true the item stays unpublished. Editing the
     * title or text of an approved item clears approval, so the new text must be approved again.
     */
    public static function save_item( $input, $now = null ) {
        $now = null === $now ? time() : (int) $now;
        if ( ! self::can_manage() ) { return array( 'ok' => false, 'reason' => 'forbidden' ); }
        if ( ! is_array( $input ) ) { return array( 'ok' => false, 'reason' => 'invalid_input' ); }

        $category = $input['category'] ?? '';
        if ( ! is_string( $category ) || ! in_array( $category, self::CATEGORIES, true ) ) {
            return array( 'ok' => false, 'reason' => 'invalid_category' );
        }
        $title = self::clean( $input['title'] ?? null, self::MAX_TITLE );
        $text  = self::clean( $input['text'] ?? null, self::MAX_TEXT );
        if ( null === $title || null === $text ) { return array( 'ok' => false, 'reason' => 'invalid_text' ); }
        if ( self::looks_like_instruction( $title . ' ' . $text ) ) { return array( 'ok' => false, 'reason' => 'unsafe_text' ); }

        $approve = self::flag( $input['approve'] ?? false );
        $review_by = (string) ( $input['review_by'] ?? '' );
        if ( '' !== $review_by && ! self::valid_date( $review_by ) ) { return array( 'ok' => false, 'reason' => 'invalid_review_by' ); }
        if ( null === $approve ) { return array( 'ok' => false, 'reason' => 'invalid_approve' ); }

        $items = self::items();
        $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
        if ( $id > 0 ) {
            if ( ! isset( $items[ $id ] ) ) { return array( 'ok' => false, 'reason' => 'not_found' ); }
        } else {
            if ( count( $items ) >= self::MAX_ITEMS ) { return array( 'ok' => false, 'reason' => 'limit_reached' ); }
            $id = empty( $items ) ? 1 : max( array_keys( $items ) ) + 1;
        }

        $hash = self::hash( $title, $text );
        $items[ $id ] = array(
            'id'            => $id,
            'category'      => $category,
            'title'         => $title,
            'text'          => $text,
            'review_by'     => $review_by,
            'approved'      => $approve,
            'approved_hash' => $approve ? $hash : '',
            'approved_by'   => $approve && function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'approved_utc'  => $approve ? gmdate( 'Y-m-d H:i:s', $now ) : '',
            'updated_utc'   => gmdate( 'Y-m-d H:i:s', $now ),
        );
        if ( ! TZFD_Scoped_Store::update( self::OPTION, $items, self::company() ) ) {
            return array( 'ok' => false, 'reason' => 'save_failed' );
        }
        return array( 'ok' => true, 'id' => $id, 'approved' => $approve && self::usable( $items[ $id ], $now ) );
    }

    public static function rest_answer( $request ) {
        // The shared guard treats a null body as invalid, and WordPress gives null for a GET with no body.
        // Read-only checks are done here instead: no payload, and a bounded parameter count.
        $body = is_object( $request ) && method_exists( $request, 'get_body' ) ? $request->get_body() : null;
        $params = is_object( $request ) && method_exists( $request, 'get_params' ) ? $request->get_params() : null;
        if ( ( null !== $body && '' !== $body ) || ! is_array( $params ) || count( $params ) > 12 ) {
            return self::error( 'titan_zero_front_desk_invalid_request', 400 );
        }
        return self::answer( $request->get_param( 'q' ) );
    }

    public static function rest_save( $request ) {
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', 8192, 12, 'application/json' ) ) {
            return self::error( 'titan_zero_front_desk_invalid_request', 400 );
        }
        $result = self::save_item( (array) $request->get_params() );
        return $result['ok'] ? $result : self::error( 'titan_zero_front_desk_' . $result['reason'], 400 );
    }

    /** Rules-only, read-only sources for the current company. Stale, unpublished or unsafe items are excluded. */
    private static function candidates( $now ) {
        $out = array();
        foreach ( self::items() as $item ) {
            if ( ! self::usable( $item, $now ) ) { continue; }
            $out[] = array(
                'source' => 'knowledge_item', 'id' => (int) $item['id'], 'category' => $item['category'],
                'title' => $item['title'], 'text' => $item['text'], 'updated_utc' => $item['updated_utc'],
            );
        }
        // Verified settings: the company's own live services, using only allowlisted fields. Price is not read.
        $services = class_exists( 'TZFD_Local_Services', false ) ? TZFD_Local_Services::list( array( 'limit' => 200 ) ) : array();
        foreach ( $services as $service ) {
            $name = self::clean( $service['name'] ?? null, self::MAX_TITLE );
            $desc = self::clean( (string) ( $service['description'] ?? '' ), self::MAX_TEXT, true );
            if ( null === $name || null === $desc || self::looks_like_instruction( $name . ' ' . $desc ) ) { continue; }
            $duration = (int) ( $service['duration_minutes'] ?? 0 );
            $text = trim( $desc . ' Duration: ' . $duration . ' minutes.' );
            $out[] = array(
                'source' => 'local_service', 'id' => (int) ( $service['id'] ?? 0 ), 'category' => 'services',
                'title' => $name, 'text' => $text, 'updated_utc' => '',
            );
        }
        return array_values( array_filter( $out, function ( $c ) { return $c['id'] > 0; } ) );
    }

    /**
     * A candidate must share a topic word with the question (a word that is not a category trigger). Category
     * words only add weight, or decide a question that has no topic word at all (for example "what are your hours").
     * Zero means no match.
     */
    private static function score( $terms, $candidate ) {
        $trigger_words = array();
        foreach ( self::TRIGGERS as $words ) { $trigger_words = array_merge( $trigger_words, $words ); }
        $topic = array_values( array_diff( $terms, $trigger_words ) );
        $category_words = self::TRIGGERS[ $candidate['category'] ] ?? array();
        $trigger = array_intersect( $terms, $category_words ) ? 1 : 0;
        if ( empty( $topic ) ) { return $trigger; }
        $doc = self::tokens( $candidate['title'] . ' ' . $candidate['text'] );
        $overlap = count( array_intersect( $topic, $doc ) );
        return $overlap > 0 ? 2 * $overlap + $trigger : 0;
    }

    /** An item may be used only if it is approved, its text is unchanged since approval, it has not expired and it is not unsafe. */
    private static function usable( $item, $now ) {
        if ( ! is_array( $item ) || empty( $item['approved'] ) ) { return false; }
        if ( ! isset( $item['title'], $item['text'], $item['category'], $item['id'] ) ) { return false; }
        if ( ! in_array( $item['category'], self::CATEGORIES, true ) ) { return false; }
        if ( ! hash_equals( (string) ( $item['approved_hash'] ?? '' ), self::hash( $item['title'], $item['text'] ) ) ) { return false; }
        if ( ! empty( $item['review_by'] ) && (string) $item['review_by'] < gmdate( 'Y-m-d', $now ) ) { return false; }
        return ! self::looks_like_instruction( $item['title'] . ' ' . $item['text'] );
    }

    private static function items() {
        $raw = TZFD_Scoped_Store::get( self::OPTION, array(), self::company() );
        if ( ! is_array( $raw ) ) { return array(); }
        $out = array();
        foreach ( $raw as $key => $item ) {
            if ( is_array( $item ) && isset( $item['id'] ) && (int) $item['id'] === (int) $key ) { $out[ (int) $key ] = $item; }
        }
        return $out;
    }

    private static function refuse( $reason ) {
        return array( 'status' => 'refused', 'reason' => $reason, 'answer' => self::REFUSAL_TEXT );
    }

    /** Plain text only: tags and control characters removed, whitespace collapsed. Null when empty or too long. */
    private static function clean( $value, $max, $allow_empty = false ) {
        if ( ! is_string( $value ) ) { return null; }
        $value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
        $value = trim( preg_replace( '/\s+/', ' ', $value ) );
        if ( '' === $value ) { return $allow_empty ? '' : null; }
        return strlen( $value ) > $max ? null : $value;
    }

    private static function tokens( $text ) {
        $parts = preg_split( '/[^a-z0-9]+/', strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
        return array_values( array_diff( array_unique( $parts ), self::STOP ) );
    }

    private static function excerpt( $text ) {
        if ( strlen( $text ) <= self::MAX_ANSWER ) { return $text; }
        $cut = preg_replace( '/\s+\S*$/', '', substr( $text, 0, self::MAX_ANSWER ) );
        return $cut . '...';
    }

    private static function looks_like_instruction( $text ) {
        return 1 === preg_match( self::INJECTION_PATTERN, (string) $text );
    }

    private static function hash( $title, $text ) {
        return hash( 'sha256', $title . "\n" . $text );
    }

    private static function valid_date( $value ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return false; }
        $date = DateTime::createFromFormat( 'Y-m-d', $value, new DateTimeZone( 'UTC' ) );
        return false !== $date && $date->format( 'Y-m-d' ) === $value;
    }

    /** Strict boolean read: true/false, 1/0 or their string forms. Null for anything else. */
    private static function flag( $value ) {
        if ( is_bool( $value ) ) { return $value; }
        if ( in_array( $value, array( 1, '1', 'true' ), true ) ) { return true; }
        if ( in_array( $value, array( 0, '0', 'false' ), true ) ) { return false; }
        return null;
    }

    private static function error( $code, $status ) {
        return new WP_Error( $code, __( 'Invalid knowledge request.', 'titan-zero-trades-front-desk' ), array( 'status' => $status ) );
    }
}

TZFD_Business_Knowledge::register();
