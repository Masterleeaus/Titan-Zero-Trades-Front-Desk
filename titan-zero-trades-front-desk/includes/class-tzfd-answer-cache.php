<?php
/**
 * Exact-match answer cache for the rules-first FAQ path (#42).
 *
 * Key = sha256 over: site ID | company | knowledge version | language | normalised query.
 *  - Site and company keep tenants apart, so one business never receives another's cached answer.
 *  - The knowledge version changes on every FAQ or fact edit, so a changed answer is never served from cache.
 *  - The query is stored only as a hash, and the value holds only approved FAQ or fact text. No customer data is cached.
 *  - Only 'answered' results are stored. Clarifications and refusals are recomputed each time, so a negative
 *    answer never outlives the knowledge that produced it.
 * Entries also expire after a TTL (default six hours) and are rechecked against the version on every read.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface TZFD_Cache_Backend {
    public function get( $key );
    public function set( $key, $value, $ttl );
    public function delete( $key );
}

/** Production backend: WordPress transients (per site, never shared across sites). */
final class TZFD_Transient_Cache_Backend implements TZFD_Cache_Backend {
    public function get( $key ) {
        return function_exists( 'get_transient' ) ? get_transient( $key ) : false;
    }
    public function set( $key, $value, $ttl ) {
        return function_exists( 'set_transient' ) ? (bool) set_transient( $key, $value, $ttl ) : false;
    }
    public function delete( $key ) {
        if ( function_exists( 'delete_transient' ) ) { delete_transient( $key ); }
    }
}

final class TZFD_Answer_Cache {

    const DEFAULT_TTL = 21600; // six hours
    const KEY_PREFIX  = 'tzfd_aq_';

    /** @var TZFD_Cache_Backend */
    private $backend;

    /** @var int */
    private $ttl;

    /** @var callable(): int */
    private $now;

    public function __construct( TZFD_Cache_Backend $backend = null, $ttl = self::DEFAULT_TTL, $now = null ) {
        $this->backend = $backend ? $backend : new TZFD_Transient_Cache_Backend();
        $this->ttl     = max( 1, (int) $ttl );
        $this->now     = $now ? $now : static function () {
            return time();
        };
    }

    /** Site identifier. Multisite sites get their own blog ID. Single installs use 1. */
    public static function site_id() {
        return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
    }

    /**
     * Cache key. Input text is normalised first. Inputs are joined with a separator that cannot appear in
     * the normalised form, so two different tuples cannot produce the same string.
     */
    public static function key( $site_id, $company, $knowledge_version, $lang, $query ) {
        $parts = array(
            'tzfd-ai-v1',
            (string) (int) $site_id,
            (string) (int) $company,
            (string) $knowledge_version,
            strtolower( (string) $lang ),
            TZFD_Intent_Engine::normalise( $query ),
        );
        return self::KEY_PREFIX . substr( hash( 'sha256', implode( "\x1f", $parts ) ), 0, 40 );
    }

    /**
     * Returns a cached answer or null. A hit is only served when its stored knowledge version matches
     * $knowledge_version and it is inside the TTL.
     */
    public function get( $site_id, $company, $knowledge_version, $lang, $query ) {
        $key   = self::key( $site_id, $company, $knowledge_version, $lang, $query );
        $entry = $this->backend->get( $key );
        if ( ! is_array( $entry ) || ! isset( $entry['answer'], $entry['stored_at'], $entry['knowledge_version'] ) ) {
            return null;
        }
        $age_ok = ( call_user_func( $this->now ) - (int) $entry['stored_at'] ) < $this->ttl;
        if ( ! $age_ok || (string) $entry['knowledge_version'] !== (string) $knowledge_version ) {
            $this->backend->delete( $key );
            return null;
        }
        return $entry['answer'];
    }

    /**
     * Stores an answer. Returns false when the answer is not a grounded positive answer (only 'answered' with a
     * faq or fact source is cached).
     */
    public function put( $site_id, $company, $knowledge_version, $lang, $query, array $answer ) {
        if ( ! isset( $answer['status'], $answer['source']['type'] ) || 'answered' !== $answer['status'] ) { return false; }
        if ( ! in_array( $answer['source']['type'], array( 'faq', 'fact' ), true ) ) { return false; }
        $key = self::key( $site_id, $company, $knowledge_version, $lang, $query );
        return $this->backend->set( $key, array(
            'answer'            => $answer,
            'knowledge_version' => (string) $knowledge_version,
            'stored_at'         => (int) call_user_func( $this->now ),
        ), $this->ttl );
    }

    /** Removes one cached answer. Version changes already make old keys unreachable, so this is rarely needed. */
    public function forget( $site_id, $company, $knowledge_version, $lang, $query ) {
        $this->backend->delete( self::key( $site_id, $company, $knowledge_version, $lang, $query ) );
    }
}
