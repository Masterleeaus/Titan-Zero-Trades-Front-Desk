<?php
/**
 * Approved FAQ retrieval for the free AI base (issue #21).
 *
 * Order of work for one enquiry, stopping at the first confident answer:
 *   1. Normalise the query (case, punctuation, plural stemming, synonyms, stop words).
 *   2. Deterministic keyword scoring against the tenant's APPROVED entries only.
 *   3. Tenant-local cached result (transient keyed by company, index version and normalised query).
 *   4. Only if the keyword score is not confident and the owner has consented: one hosted
 *      query-embedding call through the `tzfd_faq_embedding_provider` filter, subject to a monthly cap.
 *   5. Otherwise ask the visitor to clarify. No model generates the answer text: answers are the owner's approved text.
 *
 * Tenant rules: every read and write takes an explicit company id through TZFD_Scoped_Store. A company id below 1
 * returns no answer. Before any hosted call, emails and 8+ digit runs are redacted to placeholders. The hosted call
 * is made only with recorded consent, the free_ai entitlement and the monthly cap; otherwise nothing is sent.
 *
 * Index version: a per-company integer bumped on every save or delete. It is part of the cache key, so an update or
 * delete makes older cached results unreachable at once. The cache TTL bounds any remaining staleness.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_FAQ_Retrieval {

    /** Scoped (per-company) option names, read and written through TZFD_Scoped_Store. */
    const OPTION_ENTRIES     = 'tz_fd_faq_entries';
    const OPTION_VERSION     = 'tz_fd_faq_index_version';
    const OPTION_CONSENT     = 'tz_fd_faq_embedding_consent';
    const OPTION_QUOTA       = 'tz_fd_faq_embed_quota_'; // Suffix: YYYYMM.

    /** Sizing goal for the pilot. A goal for the pilot, not a code limit. */
    const PILOT_ENTRY_GOAL = 100;
    /** Pilot cap on new hosted query embeddings per site per month. Cost guard, not a licence check. */
    const EMBED_MONTHLY_LIMIT = 200;

    const MAX_QUERY_CHARS    = 300;
    const MAX_ANSWER_CHARS   = 2000;
    const MAX_QUESTION_CHARS = 300;
    const MAX_TERMS_PER_ENTRY = 30;
    const CACHE_TTL          = 3600;

    /** Placeholders that replace private detail before any text is sent to the hosted provider. */
    const REDACT_EMAIL  = '[email]';
    const REDACT_NUMBER = '[number]';

    /** Confidence thresholds, as a share of normalised query tokens covered by an entry. */
    const CONFIDENCE_ANSWER  = 0.6;
    const CONFIDENCE_CLARIFY = 0.3;
    /** Cosine similarity needed before an embedding match is trusted. */
    const EMBED_ANSWER_SIMILARITY = 0.8;

    const STATUS_ANSWER  = 'answer';
    const STATUS_CLARIFY = 'clarify';
    const STATUS_NONE    = 'none';

    const PATH_KEYWORD   = 'keyword';
    const PATH_CACHE     = 'cache';
    const PATH_EMBEDDING = 'embedding';
    const PATH_NONE      = 'none';

    /** Words that carry no meaning for matching. Negations are deliberately NOT here. */
    const STOP_WORDS = array(
        'a', 'an', 'the', 'is', 'are', 'am', 'be', 'do', 'does', 'you', 'your', 'yours', 'i', 'me', 'my',
        'we', 'our', 'it', 'its', 'to', 'of', 'in', 'on', 'at', 'for', 'and', 'or', 'any', 'there', 'this',
        'that', 'what', 'how', 'can', 'could', 'would', 'will', 'please', 'hi', 'hello', 'hey', 'if', 'so',
    );

    /** Negations kept as tokens so a negated query does not match an affirmative entry. */
    const NEGATIONS = array( 'not', 'no', 'never', 'without', 'dont', 'doesnt', 'cant', 'wont' );

    /** Canonical forms for common variants (applied after stemming). */
    const SYNONYMS = array(
        'price' => 'cost', 'pricing' => 'cost', 'fee' => 'cost', 'charge' => 'cost', 'rate' => 'cost',
        'quote' => 'cost', 'estimate' => 'cost', 'payment' => 'cost',
        'hour' => 'hours', 'open' => 'hours', 'opening' => 'hours', 'time' => 'hours',
        'cancelling' => 'cancel', 'cancellation' => 'cancel', 'cancelled' => 'cancel', 'cancelation' => 'cancel',
        'reschedule' => 'reschedule', 'rebook' => 'reschedule', 'moved' => 'reschedule',
        'emergency' => 'urgent', 'urgent' => 'urgent', 'asap' => 'urgent',
        'weekend' => 'weekend', 'saturday' => 'weekend', 'sunday' => 'weekend',
        'area' => 'area', 'suburb' => 'area', 'location' => 'area', 'region' => 'area',
    );

    /* ------------------------------------------------------------------ *
     * Normalisation
     * ------------------------------------------------------------------ */

    /**
     * Lower-case, strip tags, control characters and punctuation, collapse whitespace, cap length.
     * Returns '' for input that carries no words. Never returns markup.
     */
    public static function normalize( $text ) {
        if ( ! is_string( $text ) ) { return ''; }
        $text = str_replace( array( "\0", "\r", "\n", "\t" ), ' ', $text );
        $text = wp_strip_tags_compat( $text );
        if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_QUERY_CHARS ) {
            $text = mb_substr( $text, 0, self::MAX_QUERY_CHARS, 'UTF-8' );
        }
        $text = mb_strtolower( $text, 'UTF-8' );
        $text = str_replace( array( "'", '’' ), '', $text ); // don't -> dont
        $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
    }

    /**
     * Turns a normalised string into a unique list of matching tokens: stop words removed, naive plural
     * stemming, then synonym mapping.
     *
     * @return string[]
     */
    public static function tokens( $normalized ) {
        if ( '' === $normalized ) { return array(); }
        $out = array();
        foreach ( explode( ' ', $normalized ) as $word ) {
            if ( '' === $word ) { continue; }
            if ( in_array( $word, self::NEGATIONS, true ) ) { $out[] = 'not'; continue; }
            if ( in_array( $word, self::STOP_WORDS, true ) ) { continue; }
            if ( mb_strlen( $word, 'UTF-8' ) > 3 && substr( $word, -1 ) === 's' && substr( $word, -2 ) !== 'ss' ) {
                $word = substr( $word, 0, -1 );
            }
            $out[] = self::SYNONYMS[ $word ] ?? $word;
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Replaces every email address with [email] and every run of 8 or more digits (phone-like, separators allowed
     * between digits) with [number]. Returns null when the pattern engine fails, so callers fail closed.
     * Redacted text is what the hosted embedding provider receives. Raw text never leaves the site.
     */
    public static function redact_private_detail( $text ) {
        if ( ! is_string( $text ) ) { return ''; }
        $text = preg_replace( '/[^\s@]+@[^\s@]+\.[^\s@]+/', self::REDACT_EMAIL, $text );
        if ( null === $text ) { return null; }
        return preg_replace( '/\+?\d(?:[\s\-().]?\d){7,}/', self::REDACT_NUMBER, $text );
    }

    /** True when redaction would change the text: it holds an email address or an 8+ digit run. */
    public static function contains_private_detail( $text ) {
        $redacted = self::redact_private_detail( $text );
        return null === $redacted || $redacted !== (string) $text;
    }

    /* ------------------------------------------------------------------ *
     * Tenant-scoped index (owner-approved entries)
     * ------------------------------------------------------------------ */

    /** Current index version for a company. 0 when nothing has been saved. */
    public static function index_version( $company ) {
        return (int) TZFD_Scoped_Store::get( self::OPTION_VERSION, 0, self::require_company( $company ) );
    }

    /** All stored entries for a company, approved or not. */
    public static function all_entries( $company ) {
        $stored = TZFD_Scoped_Store::get( self::OPTION_ENTRIES, array(), self::require_company( $company ) );
        return is_array( $stored ) ? $stored : array();
    }

    /** Only entries the owner has approved. Unapproved entries are never answered from. */
    public static function approved_entries( $company ) {
        return array_values( array_filter( self::all_entries( $company ), static function ( $e ) {
            return ! empty( $e['approved'] );
        } ) );
    }

    /**
     * Replaces a company's index with sanitised entries and bumps the index version.
     * Returns the number of entries saved, or false when the company is invalid.
     */
    public static function save_entries( $company, array $entries ) {
        $company = absint( $company );
        if ( $company < 1 ) { return false; }
        $clean = array();
        $seen  = array();
        foreach ( $entries as $raw ) {
            $entry = self::sanitize_entry( $raw );
            if ( null === $entry || isset( $seen[ $entry['id'] ] ) ) { continue; }
            $seen[ $entry['id'] ] = true;
            $clean[] = $entry;
        }
        TZFD_Scoped_Store::update( self::OPTION_ENTRIES, $clean, $company );
        self::bump_version( $company );
        return count( $clean );
    }

    /** Removes one entry by id. Returns true when something was removed. */
    public static function delete_entry( $company, $id ) {
        $company = absint( $company );
        if ( $company < 1 ) { return false; }
        $id      = self::clean_id( (string) $id );
        $entries = self::all_entries( $company );
        $kept    = array_values( array_filter( $entries, static function ( $e ) use ( $id ) {
            return $e['id'] !== $id;
        } ) );
        if ( count( $kept ) === count( $entries ) ) { return false; }
        TZFD_Scoped_Store::update( self::OPTION_ENTRIES, $kept, $company );
        self::bump_version( $company );
        return true;
    }

    /** Owner consent for hosted query embeddings. Off by default. */
    public static function set_embedding_consent( $company, $consent ) {
        $company = absint( $company );
        if ( $company < 1 ) { return false; }
        $saved = TZFD_Scoped_Store::update( self::OPTION_CONSENT, (bool) $consent, $company );
        // Bump the index version so no cached answer that relied on the old consent state is served.
        self::bump_version( $company );
        return $saved;
    }

    public static function has_embedding_consent( $company ) {
        return true === TZFD_Scoped_Store::get( self::OPTION_CONSENT, false, self::require_company( $company ) );
    }

    /** Hosted embedding calls counted for the current month for this company. */
    public static function embedding_calls_this_month( $company ) {
        $key = self::OPTION_QUOTA . gmdate( 'Ym' );
        return (int) TZFD_Scoped_Store::get( $key, 0, self::require_company( $company ) );
    }

    /* ------------------------------------------------------------------ *
     * Retrieval
     * ------------------------------------------------------------------ */

    /**
     * Answers an enquiry from approved FAQ text.
     *
     * @return array{status:string,path:string,answer:string,entry_id:string,score:float,reason:string,suggestions:string[],index_version:int}
     */
    public static function answer( $company, $query ) {
        $company = absint( $company );
        if ( $company < 1 ) {
            return self::result( self::STATUS_NONE, self::PATH_NONE, 'no_tenant' );
        }
        $raw = is_string( $query ) ? $query : '';
        if ( mb_strlen( $raw, 'UTF-8' ) > self::MAX_QUERY_CHARS ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'query_too_long', $company );
        }

        $norm = self::normalize( $raw );
        if ( '' === $norm ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'empty_query', $company );
        }

        $version = self::index_version( $company );
        $key     = self::cache_key( $company, $version, $norm );
        $cached  = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['status'] ) ) {
            $cached['path'] = self::PATH_CACHE;
            return $cached;
        }

        $entries = self::approved_entries( $company );
        $tokens  = self::tokens( $norm );
        $ranked  = self::rank_keywords( $tokens, $norm, $entries );
        $best    = $ranked[0] ?? null;

        if ( null !== $best && $best['score'] >= self::CONFIDENCE_ANSWER ) {
            $result = self::answer_result( $best, $version, self::PATH_KEYWORD, $company );
        } else {
            $result = self::embedding_or_clarify( $company, $raw, $norm, $entries, $ranked, $version );
        }

        // Only answers are cached. Clarifications depend on consent, the monthly cap and provider health,
        // so they are recomputed each time and cannot go stale.
        if ( self::STATUS_ANSWER === $result['status'] ) {
            set_transient( $key, $result, self::CACHE_TTL );
        }
        return $result;
    }

    /**
     * Scores each approved entry by the share of query tokens it covers. Negated queries only match entries that
     * contain the same negation. An entry is excluded when a term in its exclude list appears in the query.
     *
     * @return array<int,array{entry:array,score:float}> Sorted best first.
     */
    public static function rank_keywords( array $tokens, $norm, array $entries ) {
        if ( empty( $tokens ) ) { return array(); }
        $query_set = array_flip( $tokens );
        $ranked = array();
        foreach ( $entries as $entry ) {
            if ( array_intersect( (array) ( $entry['exclude'] ?? array() ), $tokens ) ) { continue; }
            $vocab = array_flip( array_merge(
                self::tokens( self::normalize( (string) ( $entry['question'] ?? '' ) ) ),
                (array) ( $entry['keywords'] ?? array() )
            ) );
            // A negated query ("do not ...") never matches an entry that does not itself carry the negation.
            if ( isset( $query_set['not'] ) && ! isset( $vocab['not'] ) ) { continue; }
            $hits = 0;
            foreach ( array_keys( $query_set ) as $token ) {
                if ( isset( $vocab[ $token ] ) ) { $hits++; }
            }
            $score = $hits / count( $query_set );
            if ( $score > 0 ) {
                $ranked[] = array( 'entry' => $entry, 'score' => round( $score, 4 ) );
            }
        }
        usort( $ranked, static function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );
        return $ranked;
    }

    /**
     * Second stage. Runs only when keywords are not confident. Requires owner consent, the free_ai entitlement and the
     * monthly cap not reached. Query and questions are redacted before the provider sees them. Any failure ends in
     * clarification.
     */
    private static function embedding_or_clarify( $company, $raw, $norm, array $entries, array $ranked, $version ) {
        $suggestions = self::suggestions( $ranked );
        if ( empty( $entries ) ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'no_approved_entries', $company, $version, $suggestions );
        }
        if ( ! self::has_embedding_consent( $company ) ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'low_confidence', $company, $version, $suggestions );
        }
        if ( ! self::hosted_entitlement_allowed() ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'hosted_ai_not_entitled', $company, $version, $suggestions );
        }
        if ( self::embedding_calls_this_month( $company ) >= self::EMBED_MONTHLY_LIMIT ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'monthly_embedding_cap', $company, $version, $suggestions );
        }

        // Redact before anything is sent: emails and 8+ digit runs become placeholders. The provider gets only
        // redacted text, for the query and for every approved question. Redaction failure ends in clarification.
        $safe_raw = self::redact_private_detail( $raw );
        if ( null === $safe_raw ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'redaction_failed', $company, $version, $suggestions );
        }
        $send_norm = self::normalize( $safe_raw );
        $questions = array();
        foreach ( $entries as $e ) {
            $safe_question = self::redact_private_detail( (string) $e['question'] );
            if ( null === $safe_question ) {
                return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'redaction_failed', $company, $version, $suggestions );
            }
            $questions[] = $safe_question;
        }

        self::count_embedding_call( $company );
        try {
            $vectors = apply_filters( 'tzfd_faq_embedding_provider', null, $send_norm, $questions );
        } catch ( \Throwable $e ) {
            $vectors = null;
        }
        if ( ! is_array( $vectors ) || ! isset( $vectors['query'], $vectors['entries'] ) || ! is_array( $vectors['entries'] )
            || count( $vectors['entries'] ) !== count( $entries ) ) {
            return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'proxy_unavailable', $company, $version, $suggestions );
        }

        $best = null;
        foreach ( $entries as $i => $entry ) {
            $sim = self::cosine( $vectors['query'], $vectors['entries'][ $i ] ?? array() );
            if ( null !== $sim && ( null === $best || $sim > $best['score'] ) ) {
                $best = array( 'entry' => $entry, 'score' => round( $sim, 4 ) );
            }
        }
        if ( null !== $best && $best['score'] >= self::EMBED_ANSWER_SIMILARITY ) {
            return self::answer_result( $best, $version, self::PATH_EMBEDDING, $company );
        }
        return self::result( self::STATUS_CLARIFY, self::PATH_NONE, 'low_confidence', $company, $version, $suggestions );
    }

    /** Cosine similarity of two numeric vectors, or null when either is unusable. */
    public static function cosine( $a, $b ) {
        if ( ! is_array( $a ) || ! is_array( $b ) || empty( $a ) || count( $a ) !== count( $b ) ) { return null; }
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        foreach ( $a as $i => $x ) {
            if ( ! is_numeric( $x ) || ! is_numeric( $b[ $i ] ?? null ) ) { return null; }
            $y = (float) $b[ $i ];
            $dot += (float) $x * $y; $na += (float) $x * (float) $x; $nb += $y * $y;
        }
        if ( $na <= 0.0 || $nb <= 0.0 ) { return null; }
        return $dot / ( sqrt( $na ) * sqrt( $nb ) );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Entitlement gate for hosted calls, read from the single AI connector without sending anything. Requires a
     * configured connection, recorded connector consent, the free_ai tier and the faq.read scope. Fails closed when
     * the connector is not loaded.
     */
    private static function hosted_entitlement_allowed() {
        if ( ! class_exists( 'TZFD_AI_Connector' ) ) { return false; }
        $status = TZFD_AI_Connector::status();
        return ! empty( $status['connected'] )
            && ! empty( $status['consent'] )
            && in_array( 'free_ai', (array) ( $status['entitlements'] ?? array() ), true )
            && in_array( 'faq.read', (array) ( $status['scopes'] ?? array() ), true );
    }

    private static function answer_result( array $best, $version, $path, $company ) {
        $entry = $best['entry'];
        return self::result( self::STATUS_ANSWER, $path, '', $company, $version, array(), (string) $entry['answer'], (string) $entry['id'], (float) $best['score'] );
    }

    private static function result( $status, $path, $reason, $company = 0, $version = 0, array $suggestions = array(), $answer = '', $entry_id = '', $score = 0.0 ) {
        return array(
            'status'        => $status,
            'path'          => $path,
            'answer'        => $answer,
            'entry_id'      => $entry_id,
            'score'         => (float) $score,
            'reason'        => $reason,
            'suggestions'   => $suggestions,
            'index_version' => (int) $version,
        );
    }

    private static function suggestions( array $ranked ) {
        $out = array();
        foreach ( array_slice( $ranked, 0, 3 ) as $row ) {
            $out[] = (string) $row['entry']['question'];
        }
        return $out;
    }

    private static function cache_key( $company, $version, $norm ) {
        return 'tzfd_faq_' . md5( $company . '|' . $version . '|' . $norm );
    }

    private static function bump_version( $company ) {
        $next = self::index_version( $company ) + 1;
        TZFD_Scoped_Store::update( self::OPTION_VERSION, $next, $company );
    }

    private static function count_embedding_call( $company ) {
        $key = self::OPTION_QUOTA . gmdate( 'Ym' );
        TZFD_Scoped_Store::update( $key, self::embedding_calls_this_month( $company ) + 1, $company );
    }

    private static function require_company( $company ) {
        $company = absint( $company );
        if ( $company < 1 ) {
            throw new \InvalidArgumentException( 'FAQ retrieval needs a company id.' );
        }
        return $company;
    }

    /** Sanitises one owner-supplied entry. Returns null when it has no usable question or answer. */
    private static function sanitize_entry( $raw ) {
        if ( ! is_array( $raw ) ) { return null; }
        $question = self::plain_text( $raw['question'] ?? '', self::MAX_QUESTION_CHARS );
        $answer   = self::plain_text( $raw['answer'] ?? '', self::MAX_ANSWER_CHARS );
        if ( '' === $question || '' === $answer ) { return null; }
        $id = self::clean_id( (string) ( $raw['id'] ?? '' ) );
        if ( '' === $id ) { $id = 'faq-' . substr( md5( $question ), 0, 12 ); }
        return array(
            'id'       => $id,
            'question' => $question,
            'answer'   => $answer,
            'keywords' => self::term_list( $raw['keywords'] ?? array() ),
            'exclude'  => self::term_list( $raw['exclude'] ?? array() ),
            'approved' => in_array( $raw['approved'] ?? false, array( true, 1, '1', 'true', 'on' ), true ),
        );
    }

    private static function term_list( $value ) {
        if ( is_string( $value ) ) { $value = explode( ',', $value ); }
        if ( ! is_array( $value ) ) { return array(); }
        $terms = array();
        foreach ( $value as $item ) {
            if ( ! is_string( $item ) ) { continue; }
            foreach ( self::tokens( self::normalize( $item ) ) as $t ) { $terms[] = $t; }
        }
        return array_slice( array_values( array_unique( $terms ) ), 0, self::MAX_TERMS_PER_ENTRY );
    }

    private static function plain_text( $value, $max ) {
        if ( ! is_string( $value ) ) { return ''; }
        $value = trim( preg_replace( '/\s+/u', ' ', wp_strip_tags_compat( str_replace( "\0", '', $value ) ) ) );
        return mb_strlen( $value, 'UTF-8' ) > $max ? mb_substr( $value, 0, $max, 'UTF-8' ) : $value;
    }

    private static function clean_id( $id ) {
        return substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $id ) ), 0, 64 );
    }
}

/**
 * Tag stripper that works without WordPress loaded (tests). Uses wp_strip_all_tags when present.
 */
if ( ! function_exists( 'wp_strip_tags_compat' ) ) {
    function wp_strip_tags_compat( $text ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- fallback only when WordPress is not loaded (unit tests).
        return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
    }
}
