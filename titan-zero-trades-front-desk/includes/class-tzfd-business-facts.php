<?php
/**
 * Read-only, allowlisted business facts for the Free knowledge answerer (#22).
 *
 * Rules:
 *  - Only the keys in ALLOWLIST can ever be read. Staff notes, secrets, customer records and anything not listed
 *    here are unreachable from this class.
 *  - A fact is served only when a manager has published it AND its current value still matches the fingerprint
 *    recorded at publication. Any later edit makes the fact 'stale' until it is published again.
 *  - Values are returned as plain text (tags and control bytes removed, length capped). Nothing here calls a
 *    model, and fact text is never concatenated into a prompt. If a value contains instruction-like wording, it
 *    is shown as data only.
 *  - Every fact read is for one company (tenant). Reads and writes go through TZFD_Tenant_Storage.
 *  - Opening hours are not in the allowlist: no verified WordPress setting holds them (they live in per-form
 *    open-hours data). Adding them needs a verified source first.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Business_Facts {

    /** Tenant-scoped map: fact key => ['fingerprint' => sha256 at publication, 'published' => unix time]. */
    const PUBLISHED_KEY = 'tz_fd_ai_facts_published_v1';

    const MAX_VALUE = 500;

    /**
     * Allowlist. 'key' is the legacy scoped option name. 'intents' lists the intent categories this fact answers.
     * Every entry was verified against a setting that the plugin already reads or writes (see includes/class-tzfd-scoped-store.php
     * KEYS and addons/core/vertical-packs.addon.php).
     */
    const ALLOWLIST = array(
        'service_area'  => array( 'label' => 'Service area',  'key' => 'tz_fd_ai_service_area',  'intents' => array( 'area' ) ),
        'services'      => array( 'label' => 'Services',      'key' => 'tz_fd_ai_services',      'intents' => array( 'services' ) ),
        'price_ranges'  => array( 'label' => 'Price ranges',  'key' => 'tz_fd_ai_price_ranges',  'intents' => array( 'price' ) ),
        'emergency_fee' => array( 'label' => 'Emergency fee', 'key' => 'tz_fd_ai_emergency_fee', 'intents' => array( 'emergency' ) ),
    );

    /** @var TZFD_Tenant_Storage */
    private $storage;

    /** @var int */
    private $company;

    /** @var callable(): bool */
    private $can_manage;

    /** @var callable(): int */
    private $now;

    public function __construct( TZFD_Tenant_Storage $storage, $company, $can_manage = null, $now = null ) {
        $this->storage    = $storage;
        $this->company    = (int) $company;
        $this->can_manage = $can_manage ? $can_manage : static function () {
            return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
        };
        $this->now = $now ? $now : static function () {
            return time();
        };
    }

    /** Allowlisted fact keys. */
    public static function keys() {
        return array_keys( self::ALLOWLIST );
    }

    /** The fact that answers an intent category, or null. Only allowlisted facts are returned. */
    public static function fact_for_intent( $intent ) {
        foreach ( self::ALLOWLIST as $fact => $def ) {
            if ( in_array( $intent, $def['intents'], true ) ) { return $fact; }
        }
        return null;
    }

    /** Current value from the company's own setting, sanitised. Null when empty or not allowlisted. */
    public function current_value( $fact ) {
        if ( ! isset( self::ALLOWLIST[ $fact ] ) || $this->company < 1 ) { return null; }
        $raw = $this->storage->read( self::ALLOWLIST[ $fact ]['key'], $this->company, null );
        if ( is_array( $raw ) ) {
            $raw = implode( ', ', array_filter( array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) ) );
        }
        if ( ! is_scalar( $raw ) ) { return null; }
        $value = self::clean( (string) $raw );
        return '' === $value ? null : $value;
    }

    /**
     * Read one fact for the public answer path.
     *
     * @return array{status:string,fact?:string,label?:string,value?:string,fingerprint?:string}
     *         status: ok | not_allowlisted | empty | unpublished | stale
     */
    public function get( $fact ) {
        if ( ! isset( self::ALLOWLIST[ $fact ] ) ) { return array( 'status' => 'not_allowlisted' ); }
        $value = $this->current_value( $fact );
        if ( null === $value ) { return array( 'status' => 'empty', 'fact' => $fact ); }

        $record = $this->published()[ $fact ] ?? null;
        if ( null === $record ) { return array( 'status' => 'unpublished', 'fact' => $fact ); }

        $fp = self::value_fingerprint( $value );
        if ( ! hash_equals( (string) $record['fingerprint'], $fp ) ) {
            return array( 'status' => 'stale', 'fact' => $fact );
        }
        return array(
            'status'      => 'ok',
            'fact'        => $fact,
            'label'       => self::ALLOWLIST[ $fact ]['label'],
            'value'       => $value,
            'fingerprint' => $fp,
        );
    }

    /**
     * Publishes the current value of a fact so it can be served. Manager only.
     *
     * @return array{ok:bool,code:string,message?:string}
     */
    public function publish( $fact ) {
        if ( ! $this->allowed() ) { return array( 'ok' => false, 'code' => 'forbidden', 'message' => 'A manager is required.' ); }
        if ( ! isset( self::ALLOWLIST[ $fact ] ) ) { return array( 'ok' => false, 'code' => 'not_allowlisted' ); }
        $value = $this->current_value( $fact );
        if ( null === $value ) { return array( 'ok' => false, 'code' => 'empty', 'message' => 'Nothing to publish: the setting is empty.' ); }

        $records          = $this->published();
        $records[ $fact ] = array( 'fingerprint' => self::value_fingerprint( $value ), 'published' => (int) call_user_func( $this->now ) );
        if ( ! $this->storage->write( self::PUBLISHED_KEY, $this->company, $records ) ) {
            return array( 'ok' => false, 'code' => 'storage', 'message' => 'The fact could not be published.' );
        }
        return array( 'ok' => true, 'code' => 'published' );
    }

    /** Withdraws a published fact. Manager only. */
    public function unpublish( $fact ) {
        if ( ! $this->allowed() ) { return array( 'ok' => false, 'code' => 'forbidden', 'message' => 'A manager is required.' ); }
        $records = $this->published();
        unset( $records[ $fact ] );
        if ( ! $this->storage->write( self::PUBLISHED_KEY, $this->company, $records ) ) {
            return array( 'ok' => false, 'code' => 'storage', 'message' => 'The fact could not be updated.' );
        }
        return array( 'ok' => true, 'code' => 'unpublished' );
    }

    /**
     * Fingerprint of everything currently servable. Changes when a served fact is edited, published or withdrawn,
     * so it can take part in the cache key. Facts that are not servable do not affect it.
     */
    public function fingerprint() {
        $parts = array();
        foreach ( self::keys() as $fact ) {
            $get = $this->get( $fact );
            if ( 'ok' === $get['status'] ) { $parts[] = $fact . '=' . $get['fingerprint']; }
        }
        sort( $parts );
        return substr( hash( 'sha256', implode( '|', $parts ) ), 0, 12 );
    }

    /* ----- internals ----- */

    private static function value_fingerprint( $value ) {
        return hash( 'sha256', $value );
    }

    /** @return array<string,array{fingerprint:string,published:int}> */
    private function published() {
        if ( $this->company < 1 ) { return array(); }
        $records = $this->storage->read( self::PUBLISHED_KEY, $this->company, array() );
        return is_array( $records ) ? $records : array();
    }

    private function allowed() {
        return $this->company > 0 && (bool) call_user_func( $this->can_manage );
    }

    public static function clean( $value ) {
        $value = wp_strip_all_tags( (string) $value );
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value );
        $value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
        if ( mb_strlen( $value ) > self::MAX_VALUE ) {
            $value = rtrim( mb_substr( $value, 0, self::MAX_VALUE ) );
        }
        return $value;
    }
}
