<?php
/**
 * Built-in vertical packs for Front Desk.
 *
 * Ships the vertical registry (format 2) and the 65 vertical packs (format 3) inside this plugin, under
 * data/verticals/. Standalone installs use them when Titan Zero Trades: Front Desk is absent, so the setup wizard can offer
 * a vertical. Read only: nothing here writes to the database, makes a network request or runs pack code.
 * TZFD_Vertical_Registry::to_pack() maps each pack to the Front Desk shape and drops automation_rules.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Builtin_Verticals {

    const REGISTRY_FORMAT = 2;
    const PACK_FORMAT     = 3;
    const MAX_BYTES       = 262144;

    /** @var array|null Decoded registry, read once per request. */
    private static $registry_cache = null;

    /** @var array|null Sorted ids that have a pack file, read once per request. */
    private static $ids_cache = null;

    /** @var array<string, array|null> Front Desk packs by id, read once per request. */
    private static $pack_cache = array();

    /** True when the registry and at least one pack load. */
    public static function available() {
        if ( array() === self::registry() ) { return false; }
        foreach ( self::ids() as $id ) {
            if ( null !== self::pack( $id ) ) { return true; }
        }
        return false;
    }

    /** The decoded registry (format 2), or an empty array. */
    public static function registry() {
        if ( null !== self::$registry_cache ) { return self::$registry_cache; }
        self::$registry_cache = array();
        $data = self::read_json( self::data_dir() . 'registry.json' );
        if ( is_array( $data )
            && (int) ( $data['format'] ?? 0 ) === self::REGISTRY_FORMAT
            && is_array( $data['categories'] ?? null )
            && is_array( $data['category_members'] ?? null ) ) {
            self::$registry_cache = $data;
        }
        return self::$registry_cache;
    }

    /** Category id => label, in registry order. */
    public static function categories() {
        $out = array();
        foreach ( (array) ( self::registry()['categories'] ?? array() ) as $id => $category ) {
            $key = sanitize_key( (string) $id );
            if ( '' === $key ) { continue; }
            $label = is_array( $category ) ? (string) ( $category['label'] ?? $key ) : $key;
            $out[ $key ] = sanitize_text_field( $label );
        }
        return $out;
    }

    /** Vertical ids, sorted, from the registry members that have a pack file. */
    public static function ids() {
        if ( null !== self::$ids_cache ) { return self::$ids_cache; }
        $ids = array();
        foreach ( (array) ( self::registry()['category_members'] ?? array() ) as $members ) {
            foreach ( (array) $members as $member ) {
                $key = sanitize_key( (string) $member );
                if ( '' !== $key && is_file( self::pack_path( $key ) ) ) { $ids[] = $key; }
            }
        }
        $ids = array_values( array_unique( $ids ) );
        sort( $ids, SORT_STRING );
        self::$ids_cache = $ids;
        return self::$ids_cache;
    }

    /** Category id => ids in registry member order. Empty categories are skipped. */
    public static function ids_by_category() {
        $ids     = self::ids();
        $members = (array) ( self::registry()['category_members'] ?? array() );
        $out     = array();
        foreach ( array_keys( self::categories() ) as $category ) {
            $list = array();
            foreach ( (array) ( $members[ $category ] ?? array() ) as $member ) {
                $key = sanitize_key( (string) $member );
                if ( in_array( $key, $ids, true ) && ! in_array( $key, $list, true ) ) { $list[] = $key; }
            }
            if ( array() !== $list ) { $out[ $category ] = $list; }
        }
        return $out;
    }

    /** The Front Desk pack for one vertical, or null when it is missing, invalid or not format 3. */
    public static function pack( $id ) {
        $key = sanitize_key( (string) $id );
        if ( '' === $key || ! in_array( $key, self::ids(), true ) ) { return null; }
        if ( ! array_key_exists( $key, self::$pack_cache ) ) {
            $pack = null;
            $data = self::read_json( self::pack_path( $key ) );
            if ( is_array( $data )
                && (int) ( $data['format'] ?? 0 ) === self::PACK_FORMAT
                && isset( $data['id'] ) && is_string( $data['id'] ) && $key === $data['id'] ) {
                $pack = TZFD_Vertical_Registry::to_pack( $data );
            }
            self::$pack_cache[ $key ] = $pack;
        }
        return self::$pack_cache[ $key ];
    }

    /* ---------- internals ---------- */

    /** Front Desk plugin folder with a trailing slash, data/verticals/ below it. */
    private static function data_dir() {
        return plugin_dir_path( dirname( __DIR__ ) . '/titan-zero-front-desk.php' ) . 'data/verticals/';
    }

    private static function pack_path( $key ) {
        return self::data_dir() . 'packs/' . $key . '.json';
    }

    /** Reads a local JSON file and decodes it as data. Returns null on any error. */
    private static function read_json( $path ) {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) { return null; }
        $size = filesize( $path );
        if ( false === $size || $size > self::MAX_BYTES ) { return null; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file, size-checked and decoded as data; nothing is fetched or executed.
        $raw = file_get_contents( $path );
        if ( ! is_string( $raw ) || '' === $raw ) { return null; }
        $data = json_decode( $raw, true, 32 );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) { return null; }
        return $data;
    }
}
