<?php
/**
 * Versioned, tenant-scoped registry of approved FAQ entries (#42).
 *
 * - Every successful write bumps the registry version. Cache keys include that version, so edited or deleted
 *   answers stop being served at once.
 * - Only entries with status "approved" are visible to the public answer path. Drafts are visible to managers only.
 * - Writes need a manager. The check is injected, so it is `manage_options` in WordPress and a closure in tests.
 * - Optional expected_version gives optimistic concurrency. A stale writer gets 'conflict' and nothing is written.
 *   Limitation: the check and the write are two separate option operations, so two requests that land in the same
 *   instant can still both pass. The conflict guard catches sequential stale edits, not a true race. Documented in
 *   the PR under "Decisions for review".
 * - No local cap on entry count. The 100-entry figure in #21/#42 is a hosted pilot sizing target only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Knowledge_Store {

    /** Legacy option key, scoped per company by TZFD_Scoped_Store. */
    const KEY = 'tz_fd_ai_faq_v1';

    const STATUS_APPROVED = 'approved';
    const STATUS_DRAFT    = 'draft';

    const MAX_QUESTION = 300;
    const MAX_ANSWER   = 1000;
    const MAX_KEYWORDS = 20;
    const MAX_KEYWORD  = 40;

    /** @var TZFD_Tenant_Storage */
    private $storage;

    /** @var int */
    private $company;

    /** @var callable(): bool */
    private $can_manage;

    /**
     * @param TZFD_Tenant_Storage $storage
     * @param int                 $company    Tenant ID. Zero or less refuses every operation.
     * @param callable|null       $can_manage Returns true when the current actor may edit. Defaults to manage_options.
     */
    public function __construct( TZFD_Tenant_Storage $storage, $company, $can_manage = null ) {
        $this->storage    = $storage;
        $this->company    = (int) $company;
        $this->can_manage = $can_manage ? $can_manage : static function () {
            return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
        };
    }

    public function company() {
        return $this->company;
    }

    /** Current registry version. Zero means nothing has been written yet. */
    public function version() {
        $doc = $this->document();
        return (int) $doc['version'];
    }

    /**
     * Entries for the public path (approved only) or, when $include_drafts is true, every entry. Drafts require a manager.
     *
     * @return array<string,array>
     */
    public function entries( $include_drafts = false ) {
        if ( $this->company < 1 ) { return array(); }
        $all = $this->document()['entries'];
        if ( $include_drafts && $this->allowed() ) { return $all; }
        return array_filter( $all, static function ( $e ) {
            return self::STATUS_APPROVED === $e['status'];
        } );
    }

    /** Approved entries in one language. Language is an exact tag match such as 'en'. */
    public function approved( $lang = 'en' ) {
        $out = array();
        foreach ( $this->entries( false ) as $id => $e ) {
            if ( $e['lang'] === $lang ) { $out[ $id ] = $e; }
        }
        return $out;
    }

    /**
     * Creates or updates one entry.
     *
     * @param array    $input            Keys: id (optional, for update), question, answer, keywords (string[]), lang, status.
     * @param int|null $expected_version Registry version the caller last read, or null to skip the check.
     * @return array{ok:bool,code:string,id?:string,version?:int,message?:string}
     */
    public function save_entry( array $input, $expected_version = null ) {
        if ( ! $this->allowed() ) { return self::fail( 'forbidden', 'A manager is required to change FAQ entries.' ); }
        $doc = $this->document();
        if ( null !== $expected_version && (int) $expected_version !== (int) $doc['version'] ) {
            return self::fail( 'conflict', 'The FAQ changed since you loaded it. Reload and try again.' );
        }

        $clean = self::sanitise( $input );
        if ( isset( $clean['error'] ) ) { return self::fail( 'invalid', $clean['error'] ); }

        $id = isset( $input['id'] ) && '' !== (string) $input['id'] ? self::clean_id( (string) $input['id'] ) : '';
        if ( '' === $id ) {
            $id = 'faq_' . bin2hex( random_bytes( 6 ) );
        } elseif ( ! isset( $doc['entries'][ $id ] ) ) {
            return self::fail( 'not_found', 'That FAQ entry does not exist.' );
        }

        $now = self::now();
        $doc['entries'][ $id ] = array_merge(
            $clean,
            array(
                'id'          => $id,
                'answer_hash' => hash( 'sha256', self::fold( $clean['answer'] ) ),
                'updated'     => $now,
            )
        );
        $doc['version'] = (int) $doc['version'] + 1;

        if ( ! $this->storage->write( self::KEY, $this->company, $doc ) ) {
            return self::fail( 'storage', 'The FAQ could not be saved.' );
        }
        return array( 'ok' => true, 'code' => 'saved', 'id' => $id, 'version' => $doc['version'] );
    }

    /**
     * Deletes one entry. Its answers stop being served as soon as the version bumps.
     *
     * @return array{ok:bool,code:string,version?:int,message?:string}
     */
    public function delete_entry( $id, $expected_version = null ) {
        if ( ! $this->allowed() ) { return self::fail( 'forbidden', 'A manager is required to change FAQ entries.' ); }
        $doc = $this->document();
        if ( null !== $expected_version && (int) $expected_version !== (int) $doc['version'] ) {
            return self::fail( 'conflict', 'The FAQ changed since you loaded it. Reload and try again.' );
        }
        $id = self::clean_id( (string) $id );
        if ( '' === $id || ! isset( $doc['entries'][ $id ] ) ) { return self::fail( 'not_found', 'That FAQ entry does not exist.' ); }

        unset( $doc['entries'][ $id ] );
        $doc['version'] = (int) $doc['version'] + 1;
        if ( ! $this->storage->write( self::KEY, $this->company, $doc ) ) {
            return self::fail( 'storage', 'The FAQ could not be saved.' );
        }
        return array( 'ok' => true, 'code' => 'deleted', 'version' => $doc['version'] );
    }

    /** Validates and normalises one entry. Returns the clean fields, or ['error' => message]. */
    public static function sanitise( array $input ) {
        $question = self::clean_text( isset( $input['question'] ) ? $input['question'] : '' );
        $answer   = self::clean_text( isset( $input['answer'] ) ? $input['answer'] : '' );
        $lang     = isset( $input['lang'] ) ? strtolower( trim( (string) $input['lang'] ) ) : 'en';
        $status   = isset( $input['status'] ) ? (string) $input['status'] : self::STATUS_DRAFT;

        if ( '' === $question || mb_strlen( $question ) > self::MAX_QUESTION ) {
            return array( 'error' => 'The question must be between 1 and ' . self::MAX_QUESTION . ' characters.' );
        }
        if ( '' === $answer || mb_strlen( $answer ) > self::MAX_ANSWER ) {
            return array( 'error' => 'The answer must be between 1 and ' . self::MAX_ANSWER . ' characters.' );
        }
        if ( ! preg_match( '/^[a-z]{2}(-[a-z]{2})?$/', $lang ) ) {
            return array( 'error' => 'The language must be a tag such as en.' );
        }
        if ( ! in_array( $status, array( self::STATUS_APPROVED, self::STATUS_DRAFT ), true ) ) {
            return array( 'error' => 'Status must be approved or draft.' );
        }

        $keywords = array();
        $raw      = isset( $input['keywords'] ) && is_array( $input['keywords'] ) ? $input['keywords'] : array();
        if ( count( $raw ) > self::MAX_KEYWORDS ) {
            return array( 'error' => 'At most ' . self::MAX_KEYWORDS . ' keywords are allowed.' );
        }
        foreach ( $raw as $kw ) {
            $kw = self::clean_text( $kw );
            if ( '' === $kw ) { continue; }
            if ( mb_strlen( $kw ) > self::MAX_KEYWORD ) {
                return array( 'error' => 'Each keyword must be at most ' . self::MAX_KEYWORD . ' characters.' );
            }
            $keywords[] = $kw;
        }

        return array(
            'question' => $question,
            'answer'   => $answer,
            'keywords' => array_values( array_unique( $keywords ) ),
            'lang'     => $lang,
            'status'   => $status,
        );
    }

    /** Lower-case and whitespace-folded form used for dedupe hashes. */
    public static function fold( $text ) {
        return TZFD_Intent_Engine::normalise( $text );
    }

    /* ----- internals ----- */

    private function allowed() {
        return $this->company > 0 && (bool) call_user_func( $this->can_manage );
    }

    /** @return array{version:int,entries:array} */
    private function document() {
        if ( $this->company < 1 ) { return array( 'version' => 0, 'entries' => array() ); }
        $doc = $this->storage->read( self::KEY, $this->company, null );
        if ( ! is_array( $doc ) || ! isset( $doc['entries'] ) || ! is_array( $doc['entries'] ) ) {
            return array( 'version' => 0, 'entries' => array() );
        }
        return array( 'version' => (int) ( $doc['version'] ?? 0 ), 'entries' => $doc['entries'] );
    }

    private static function clean_text( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
        $value = preg_replace( '/\s+/u', ' ', (string) $value );
        return trim( (string) $value );
    }

    private static function clean_id( $id ) {
        return preg_match( '/^[a-z0-9_]{3,64}$/', $id ) ? $id : '';
    }

    private static function now() {
        return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
    }

    private static function fail( $code, $message ) {
        return array( 'ok' => false, 'code' => $code, 'message' => $message );
    }
}
