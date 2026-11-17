<?php
/**
 * Source-grounded FAQ and fact answers for the Free tier (#21 semantic FAQ search, #22 knowledge assistant).
 *
 * Order of work for each enquiry:
 *   1. Exact answer cache (keyed by site, company, knowledge version, language, redacted normalised query).
 *   2. Deterministic FAQ match on approved entries: keywords, synonyms, negation polarity, intent agreement.
 *   3. Published business fact for a confident intent (allowlisted, fingerprint-checked).
 *   4. Clarification when several approved answers are close, or when the best match is weak.
 *   5. Only then, the optional hosted query embedding and cosine ranking against the approved entry index.
 *   6. Otherwise a refusal with a manual fallback. Nothing is invented.
 *
 * Steps 1 to 4 make no network call and no model call. A known, answered request reports model_calls = 0.
 *
 * Thresholds (document-only constants, see PR "Decisions for review"):
 *   ACCEPT 0.55 / CLARIFY_FLOOR 0.40 / COVERAGE_MIN 0.60 / TIE_GAP 0.05 for deterministic matches.
 *   EMBED_ACCEPT 0.82 / EMBED_CLARIFY 0.70 for embeddings. EMBED_MONTHLY_LIMIT 800 per site per month (contract figure).
 *
 * Privacy: the query is redacted (emails and phone-like numbers) before any hosted call. It is never stored in
 * clear, only as a hash in the audit log. No customer records are embedded or cached.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_FAQ_Search {

    const ACCEPT          = 0.55;
    const CLARIFY_FLOOR   = 0.40;
    const COVERAGE_MIN    = 0.60;
    const TIE_GAP         = 0.05;
    const EMBED_ACCEPT    = 0.82;
    const EMBED_CLARIFY   = 0.70;
    const EMBED_MONTHLY_LIMIT = 800;
    const MAX_CLARIFY     = 3;

    const BUDGET_KEY = 'tz_fd_ai_embed_budget_v1';
    const VECTOR_KEY = 'tz_fd_ai_faq_vectors_v1';
    const AUDIT_KEY  = 'tz_fd_ai_audit_v1';
    const AUDIT_MAX  = 200;

    /** @var TZFD_Knowledge_Store */
    private $faq;
    /** @var TZFD_Business_Facts */
    private $facts;
    /** @var TZFD_Answer_Cache */
    private $cache;
    /** @var TZFD_Tenant_Storage */
    private $storage;
    /** @var TZFD_Embedding_Provider */
    private $provider;
    /** @var callable(): int */
    private $now;
    /** @var callable(): bool */
    private $can_manage;
    /** @var int */
    private $site;

    /**
     * @param array $options Optional: now (callable), can_manage (callable), site (int).
     */
    public function __construct( TZFD_Knowledge_Store $faq, TZFD_Business_Facts $facts, TZFD_Answer_Cache $cache, TZFD_Tenant_Storage $storage, TZFD_Embedding_Provider $provider = null, array $options = array() ) {
        $this->faq      = $faq;
        $this->facts    = $facts;
        $this->cache    = $cache;
        $this->storage  = $storage;
        $this->provider = $provider ? $provider : new TZFD_Embedding_Provider_Stub();
        $this->now      = isset( $options['now'] ) ? $options['now'] : static function () { return time(); };
        $this->can_manage = isset( $options['can_manage'] ) ? $options['can_manage'] : static function () {
            return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
        };
        $this->site     = isset( $options['site'] ) ? (int) $options['site'] : TZFD_Answer_Cache::site_id();
    }

    /**
     * Knowledge version used for cache keys: registry version, fingerprint of servable facts, and the
     * AI knowledge builder version (#39). A builder publish, edit or rollback therefore changes the key,
     * so cached answers and the vector index never outlive the text they were built from.
     */
    public function knowledge_version() {
        return 'r' . $this->faq->version() . '.f' . $this->facts->fingerprint() . '.k' . $this->builder_version();
    }

    /** Version of the builder's published text for this company. 0 when the builder is not loaded. */
    private function builder_version() {
        return class_exists( 'TZFD_Ai_Knowledge_Builder', false ) ? (int) TZFD_Ai_Knowledge_Builder::version( $this->faq->company() ) : 0;
    }

    /**
     * Published builder entries (#39), shaped like FAQ entries. Only approved, published text is included.
     * The builder has no language field, so its entries are served for the default language only.
     */
    private function builder_entries( $lang ) {
        if ( 'en' !== $lang || ! class_exists( 'TZFD_Ai_Knowledge_Builder', false ) ) { return array(); }
        $out = array();
        foreach ( TZFD_Ai_Knowledge_Builder::published( $this->faq->company() ) as $e ) {
            $out[ (string) $e['id'] ] = array( 'question' => $e['question'], 'answer' => $e['answer'], 'keywords' => array(), 'lang' => 'en' );
        }
        return $out;
    }

    /**
     * Answers one enquiry. See the class comment for the order of work.
     *
     * @return array{status:string,answer:?string,clarify_options:array,source:?array,method:?string,confidence:float,reason:string,fallback:?string,model_calls:int,cache:string}
     */
    public function answer( $query, $lang = 'en' ) {
        $redacted = self::redact( $query );
        $norm     = TZFD_Intent_Engine::normalise( $redacted );
        $kv       = $this->knowledge_version();
        $company  = $this->faq->company();

        if ( '' === $norm || $company < 1 ) {
            return $this->finish( $this->result( 'no_answer', 'empty_query' ), $norm, $kv, 'skip' );
        }

        $cached = $this->cache->get( $this->site, $company, $kv, $lang, $norm );
        if ( is_array( $cached ) ) {
            $cached['cache']       = 'hit';
            $cached['model_calls'] = 0;
            $this->audit( $cached, $norm, $kv );
            return $cached;
        }

        $result = $this->compute( $query, $redacted, $lang );
        $result['cache'] = 'miss';
        $this->cache->put( $this->site, $company, $kv, $lang, $norm, $result );
        return $this->finish( $result, $norm, $kv, 'miss' );
    }

    /**
     * Builds the approved-entry vector index with the hosted provider. Manager only. Entries whose text has not
     * changed since the last run are skipped. This is the only path that calls the provider for FAQ text.
     *
     * @return array{ok:bool,code:string,embedded:int}
     */
    public function index_entries() {
        if ( ! $this->allowed() ) { return array( 'ok' => false, 'code' => 'forbidden', 'embedded' => 0 ); }
        $index    = $this->read_index();
        $embedded = 0;
        foreach ( $this->faq->approved( 'en' ) as $id => $e ) {
            $hash = self::entry_hash( $e );
            if ( isset( $index[ $id ]['hash'] ) && hash_equals( $index[ $id ]['hash'], $hash ) ) { continue; }
            try {
                $vec = $this->provider->embed( self::redact( $e['question'] . ' ' . implode( ' ', $e['keywords'] ) ) );
            } catch ( \Throwable $t ) {
                $vec = null;
            }
            if ( ! is_array( $vec ) || ! $vec ) { return array( 'ok' => false, 'code' => 'proxy_unavailable', 'embedded' => $embedded ); }
            $index[ $id ] = array( 'hash' => $hash, 'vector' => array_map( 'floatval', $vec ) );
            $embedded++;
        }
        $this->storage->write( self::VECTOR_KEY, $this->faq->company(), $index );
        return array( 'ok' => true, 'code' => 'indexed', 'embedded' => $embedded );
    }

    /** Removes personal data from text before any hosted call: email addresses and phone-like numbers. */
    public static function redact( $text ) {
        $text = is_string( $text ) ? $text : '';
        $text = preg_replace( '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u', '[redacted]', $text );
        $text = preg_replace( '/\+?\d[\d\s().-]{6,}\d/', '[redacted]', (string) $text );
        return (string) $text;
    }

    /* ----- core ----- */

    private function compute( $query, $redacted, $lang ) {
        $q_tokens = self::meaningful( $redacted );
        $q_neg    = TZFD_Intent_Engine::has_negation( $redacted );
        $q_intent = TZFD_Intent_Engine::confident_intent( $redacted );

        $scored = array();
        foreach ( $this->faq->approved( $lang ) + $this->builder_entries( $lang ) as $id => $e ) {
            $s = $this->score( $q_tokens, $q_neg, $q_intent, $e );
            if ( $s >= self::CLARIFY_FLOOR ) {
                $scored[] = array( 'id' => $id, 'score' => $s, 'entry' => $e );
            }
        }
        usort( $scored, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );

        $accepted = array_values( array_filter( $scored, static function ( $c ) { return $c['score'] >= self::ACCEPT; } ) );
        if ( $accepted ) {
            $top      = $accepted[0];
            $distinct = array();
            foreach ( $accepted as $c ) {
                if ( $c['score'] < $top['score'] - self::TIE_GAP ) { break; }
                $distinct[ TZFD_Knowledge_Store::fold( $c['entry']['answer'] ) ] = $c;
            }
            if ( 1 === count( $distinct ) ) {
                return $this->result( 'answered', 'faq_match', $top['entry']['answer'], array( 'type' => 'faq', 'id' => $top['id'], 'knowledge_version' => $this->knowledge_version() ), 'rules', $top['score'] );
            }
            return $this->clarify( array_values( $distinct ), 'tied_matches' );
        }

        $fact_key = TZFD_Business_Facts::fact_for_intent( TZFD_Intent_Engine::confident_intent( $redacted ) );
        $fact_reason = 'no_match';
        if ( null !== $fact_key ) {
            $fact = $this->facts->get( $fact_key );
            if ( 'ok' === $fact['status'] ) {
                return $this->result( 'answered', 'fact', $fact['label'] . ': ' . $fact['value'], array( 'type' => 'fact', 'id' => $fact_key, 'knowledge_version' => $this->knowledge_version() ), 'fact', 0.8 );
            }
            $fact_reason = 'fact_' . $fact['status'];
        }

        $band = array_values( array_filter( $scored, static function ( $c ) { return $c['score'] < self::ACCEPT; } ) );
        if ( $band ) {
            return $this->clarify( array_slice( $band, 0, self::MAX_CLARIFY ), 'weak_match' );
        }

        return $this->semantic( $redacted, $lang, $fact_reason );
    }

    /** Hosted similarity stage. Runs only when deterministic rules found nothing usable. */
    private function semantic( $redacted, $lang, $fallback_reason ) {
        // The vector index covers English entries only (see index_entries). Other languages stop at rules.
        if ( 'en' !== $lang ) { return $this->result( 'no_answer', 'language_not_indexed' ); }
        $index = $this->read_index();
        $live  = array();
        foreach ( $this->faq->approved( 'en' ) as $id => $e ) {
            if ( isset( $index[ $id ]['hash'], $index[ $id ]['vector'] ) && hash_equals( $index[ $id ]['hash'], self::entry_hash( $e ) ) ) {
                $live[ $id ] = $index[ $id ]['vector'];
            }
        }
        if ( ! $live ) { return $this->result( 'no_answer', $fallback_reason ); }
        if ( ! $this->budget_available() ) { return $this->result( 'no_answer', 'budget_exhausted' ); }

        $this->spend_budget();
        try {
            $vec = $this->provider->embed( $redacted );
        } catch ( \Throwable $t ) {
            $vec = null;
        }
        if ( ! is_array( $vec ) || ! $vec ) { return $this->result( 'no_answer', 'proxy_unavailable', 1 ); }

        $ranked = array();
        foreach ( $live as $id => $v ) {
            $sim = self::cosine( $vec, $v );
            if ( null !== $sim ) { $ranked[] = array( 'id' => $id, 'score' => $sim, 'entry' => $this->faq->approved( 'en' )[ $id ] ); }
        }
        usort( $ranked, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
        if ( ! $ranked ) { return $this->result( 'no_answer', 'index_mismatch', 1 ); }

        $top = $ranked[0];
        if ( $top['score'] >= self::EMBED_ACCEPT ) {
            return $this->result( 'answered', 'embedding_match', $top['entry']['answer'], array( 'type' => 'faq', 'id' => $top['id'], 'knowledge_version' => $this->knowledge_version() ), 'embedding', round( $top['score'], 4 ), 1 );
        }
        $near = array_values( array_filter( $ranked, static function ( $c ) { return $c['score'] >= self::EMBED_CLARIFY; } ) );
        if ( $near ) {
            return $this->clarify( array_slice( $near, 0, self::MAX_CLARIFY ), 'weak_semantic', 1 );
        }
        return $this->result( 'no_answer', 'low_similarity', 1 );
    }

    /**
     * Deterministic score for one entry, 0.0 when the entry is ruled out.
     * Rules out: negation polarity differs; both sides have a confident intent and they differ;
     * fewer than COVERAGE_MIN of the query's words are present in the entry.
     */
    private function score( array $q_tokens, $q_neg, $q_intent, array $entry ) {
        if ( ! $q_tokens ) { return 0.0; }
        if ( TZFD_Intent_Engine::has_negation( $entry['question'] ) !== $q_neg ) { return 0.0; }
        $e_intent = TZFD_Intent_Engine::confident_intent( $entry['question'] );
        if ( TZFD_Intent_Engine::UNKNOWN !== $q_intent && TZFD_Intent_Engine::UNKNOWN !== $e_intent && $q_intent !== $e_intent ) {
            return 0.0;
        }
        $e_tokens = self::meaningful( $entry['question'] . ' ' . implode( ' ', $entry['keywords'] ) );
        if ( ! $e_tokens ) { return 0.0; }
        $common   = count( array_intersect( $q_tokens, $e_tokens ) );
        $coverage = $common / count( $q_tokens );
        if ( $coverage < self::COVERAGE_MIN ) { return 0.0; }
        $precision = $common / count( $e_tokens );
        return round( 0.7 * $coverage + 0.3 * $precision, 4 );
    }

    /** Matchable words: synonyms applied, stop words removed, negation words removed (polarity is checked separately). */
    private static function meaningful( $text ) {
        $words = array_diff( TZFD_Intent_Engine::content_tokens( $text ), TZFD_Intent_Engine::NEGATIONS );
        return array_values( $words );
    }

    private static function cosine( array $a, array $b ) {
        if ( count( $a ) !== count( $b ) || ! $a ) { return null; }
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        foreach ( $a as $i => $x ) {
            $y    = (float) $b[ $i ];
            $dot += (float) $x * $y;
            $na  += (float) $x * (float) $x;
            $nb  += $y * $y;
        }
        if ( $na <= 0.0 || $nb <= 0.0 ) { return null; }
        return $dot / ( sqrt( $na ) * sqrt( $nb ) );
    }

    private static function entry_hash( array $e ) {
        return hash( 'sha256', TZFD_Intent_Engine::normalise( $e['question'] ) . '|' . implode( ',', array_map( array( 'TZFD_Intent_Engine', 'normalise' ), $e['keywords'] ) ) );
    }

    /* ----- result shaping ----- */

    private function clarify( array $candidates, $reason, $calls = 0 ) {
        $options = array();
        foreach ( $candidates as $c ) {
            $options[] = array( 'id' => $c['id'], 'question' => $c['entry']['question'] );
        }
        $r = $this->result( 'clarify', $reason, null, null, 'rules', 0.0, $calls );
        $r['clarify_options'] = $options;
        return $r;
    }

    private function result( $status, $reason, $answer = null, $source = null, $method = null, $confidence = 0.0, $calls = 0 ) {
        return array(
            'status'          => $status,
            'answer'          => 'answered' === $status ? $answer : null,
            'clarify_options' => array(),
            'source'          => 'answered' === $status ? $source : null,
            'method'          => 'answered' === $status ? $method : null,
            'confidence'      => (float) $confidence,
            'reason'          => $reason,
            'fallback'        => 'answered' === $status ? null : 'manual',
            'model_calls'     => (int) $calls,
            'cache'           => 'skip',
        );
    }

    private function finish( array $result, $norm, $kv, $cache_state ) {
        $result['cache'] = $cache_state;
        $this->audit( $result, $norm, $kv );
        return $result;
    }

    /* ----- budget, index and audit storage ----- */

    private function budget_month() {
        return gmdate( 'Y-m', (int) call_user_func( $this->now ) );
    }

    private function budget_available() {
        $b = $this->storage->read( self::BUDGET_KEY, $this->faq->company(), array() );
        $used = ( is_array( $b ) && ( $b['month'] ?? '' ) === $this->budget_month() ) ? (int) $b['count'] : 0;
        return $used < self::EMBED_MONTHLY_LIMIT;
    }

    private function spend_budget() {
        $b = $this->storage->read( self::BUDGET_KEY, $this->faq->company(), array() );
        $month = $this->budget_month();
        $used  = ( is_array( $b ) && ( $b['month'] ?? '' ) === $month ) ? (int) $b['count'] : 0;
        $this->storage->write( self::BUDGET_KEY, $this->faq->company(), array( 'month' => $month, 'count' => $used + 1 ) );
    }

    private function read_index() {
        $i = $this->storage->read( self::VECTOR_KEY, $this->faq->company(), array() );
        return is_array( $i ) ? $i : array();
    }

    private function audit( array $result, $norm, $kv ) {
        $log   = $this->storage->read( self::AUDIT_KEY, $this->faq->company(), array() );
        $log   = is_array( $log ) ? $log : array();
        $log[] = array(
            't'           => (int) call_user_func( $this->now ),
            'status'      => $result['status'],
            'reason'      => $result['reason'],
            'source_type' => $result['source']['type'] ?? null,
            'source_id'   => $result['source']['id'] ?? null,
            'kv'          => $kv,
            'query'       => substr( hash( 'sha256', $norm ), 0, 16 ),
            'model_calls' => (int) $result['model_calls'],
            'cache'       => $result['cache'],
        );
        if ( count( $log ) > self::AUDIT_MAX ) { $log = array_slice( $log, -self::AUDIT_MAX ); }
        $this->storage->write( self::AUDIT_KEY, $this->faq->company(), $log );
    }

    private function allowed() {
        return $this->faq->company() > 0 && (bool) call_user_func( $this->can_manage );
    }
}
