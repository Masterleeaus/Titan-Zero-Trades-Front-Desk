<?php
/**
 * Vertical registry for Front Desk. The built-in packs (includes/class-tzfd-builtin-verticals.php, data/verticals/)
 * supply the vertical list and each pack (service types, staff skills, urgency signals, reference answers, receptionist text).
 * The company's selection is kept in a local option (tz_fd_vertical_selection, company scoped).
 * Custom packs registered or uploaded by the site are still added on top.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Vertical_Registry {

    const MAX_TRADES   = 5;
    const LOCAL_OPTION = 'tz_fd_vertical_selection';

    /** True when the built-in packs are loaded. */
    public static function available() {
        return TZFD_Builtin_Verticals::available();
    }

    /** Registry ids, sorted. The built-in list. */
    public static function ids() {
        return TZFD_Builtin_Verticals::ids();
    }

    /** The Front Desk pack for one vertical, or null. The built-in pack. */
    public static function pack( $id ) {
        return TZFD_Builtin_Verticals::pack( $id );
    }

    /**
     * The built-in verticals for the setup wizard: category id => array of [ id, name, icon, tagline ].
     * Uses the built-in pack data, whether or not Workspace is present. Category labels come from
     * TZFD_Builtin_Verticals::categories().
     */
    public static function builtin_list_for_ui() {
        $out = array();
        foreach ( TZFD_Builtin_Verticals::ids_by_category() as $category => $ids ) {
            foreach ( $ids as $id ) {
                $pack = TZFD_Builtin_Verticals::pack( $id );
                if ( ! $pack ) { continue; }
                $out[ $category ][] = array(
                    'id'      => $pack['id'],
                    'name'    => $pack['name'],
                    'icon'    => $pack['icon'],
                    'tagline' => $pack['tagline'],
                );
            }
        }
        return $out;
    }

    /**
     * Maps a format 3 registry pack to the shape Front Desk's pack code uses.
     * Text is cleaned here; the registry is trusted for structure only.
     */
    public static function to_pack( array $p ) {
        $id = self::key( $p['id'] );
        return array(
            'id'              => $id,
            'name'            => self::text( $p['name'] ?? $id ),
            'icon'            => self::text( $p['icon'] ?? '📦' ),
            'tagline'         => self::text( $p['tagline'] ?? '' ),
            'service_types'   => self::texts( $p['service_types'] ?? array() ),
            'staff_skills'    => self::texts( $p['staff_skills'] ?? array() ),
            'urgency_signals' => self::signals( $p['urgency_signals'] ?? array() ),
            'extra_prompt'    => self::block( $p['extra_prompt'] ?? '' ),
            'kb'              => self::qa( $p['kb'] ?? array() ),
            'catalog'         => is_array( $p['catalog'] ?? null ) ? $p['catalog'] : array(),
            'source'          => 'builtin',
        );
    }

    /** Staff skills for the given verticals, merged and unique. Currently used as receptionist prompt context. */
    public static function staff_skills( array $trades ) {
        $out = array();
        foreach ( $trades as $id ) {
            $pack = self::pack( $id );
            if ( $pack ) { $out = array_merge( $out, $pack['staff_skills'] ); }
        }
        return array_values( array_unique( $out ) );
    }

    /** Urgency signals for the given verticals, grouped by level and unique. */
    public static function urgency_signals( array $trades ) {
        $out = array( 'emergency' => array(), 'urgent' => array(), 'routine' => array() );
        foreach ( $trades as $id ) {
            $pack = self::pack( $id );
            if ( ! $pack ) { continue; }
            foreach ( array_keys( $out ) as $level ) {
                $out[ $level ] = array_values( array_unique( array_merge( $out[ $level ], (array) ( $pack['urgency_signals'][ $level ] ?? array() ) ) ) );
            }
        }
        return $out;
    }

    /**
     * Match the strongest vertical urgency indicator in text. Generic safety cues remain in the assistant rules.
     * Returns emergency, urgent or an empty string when no pack signal matches.
     */
    public static function classify_urgency( $text, array $trades ) {
        $text    = is_string( $text ) ? $text : '';
        $signals = self::urgency_signals( $trades );
        foreach ( array( 'emergency', 'urgent' ) as $level ) {
            foreach ( $signals[ $level ] as $signal ) {
                if ( self::contains_phrase( $text, $signal ) ) { return $level; }
            }
        }
        return '';
    }

    /** @return bool */
    private static function contains_phrase( $text, $signal ) {
        $signal = trim( (string) $signal );
        if ( '' === $signal ) { return false; }
        $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote( $signal, '/' ) . '(?![\\p{L}\\p{N}])/iu';
        return 1 === preg_match( $pattern, (string) $text );
    }

    /**
     * The company's selection, read only. Returns array( primary, verticals, status ). status is 'ok', 'none' or
     * 'unavailable'. $company_id 0 means the current user's company.
     */
    public static function selection( $company_id = 0 ) {
        if ( ! self::available() ) { return array( 'primary' => null, 'trades' => array(), 'status' => 'unavailable' ); }
        return self::local_selection( $company_id );
    }

    /**
     * Records onboarding's vertical pick in the local selection, keeping the company's other verticals.
     * Returns true, or an error code string.
     */
    public static function choose_primary( $id ) {
        if ( ! self::available() ) { return 'unavailable'; }
        $id = (string) $id;
        if ( ! in_array( $id, self::ids(), true ) ) { return 'tz_vertical_unknown'; }
        $sel    = self::selection();
        $trades = 'ok' === $sel['status'] ? $sel['trades'] : array();
        if ( ! in_array( $id, $trades, true ) ) {
            if ( count( $trades ) >= self::MAX_TRADES ) { return 'tz_vertical_too_many'; }
            $trades[] = $id;
        }
        $selection = array( 'primary' => $id, 'trades' => $trades );
        return self::save_local_selection( $selection );
    }

    /**
     * Saves the company's full vertical list when Workspace is absent. $selection is array( primary, trades ). The
     * primary is always stored first in trades; the other trades are validated against ids() and capped at MAX_TRADES.
     * Returns true, or 'tz_vertical_unknown', 'tz_vertical_too_many' or 'tz_vertical_save_failed'. Writes only the
     * company-scoped option; the caller checks the user's capability.
     */
    public static function save_local_selection( array $selection ) {
        $ids     = self::known_ids();
        $primary = self::key_of( $selection['primary'] ?? '' );
        if ( '' === $primary || ! in_array( $primary, $ids, true ) ) { return 'tz_vertical_unknown'; }

        $extras = array();
        foreach ( (array) ( $selection['trades'] ?? array() ) as $item ) {
            $key = self::key_of( $item );
            if ( '' === $key ) { continue; }
            if ( ! in_array( $key, $ids, true ) ) { return 'tz_vertical_unknown'; }
            if ( $key !== $primary && ! in_array( $key, $extras, true ) ) { $extras[] = $key; }
        }
        if ( 1 + count( $extras ) > self::MAX_TRADES ) { return 'tz_vertical_too_many'; }

        $value = array( 'primary' => $primary, 'trades' => array_merge( array( $primary ), $extras ) );
        $saved = TZFD_Scoped_Store::update( self::LOCAL_OPTION, $value );
        // update_option() returns false when the value is unchanged, so compare with what is stored.
        if ( false === $saved && TZFD_Scoped_Store::get( self::LOCAL_OPTION, array() ) !== $value ) {
            return 'tz_vertical_save_failed';
        }
        return true;
    }

    /**
     * Ids a standalone selection may use: the built-in verticals plus any custom packs the add-on has loaded.
     * Workspace installs never reach this, because they save through the adapter.
     */
    private static function known_ids() {
        $ids = self::ids();
        if ( class_exists( 'TZFD_Vertical_Packs' ) && method_exists( 'TZFD_Vertical_Packs', 'available' ) ) {
            $ids = array_merge( $ids, array_map( 'strval', array_keys( (array) TZFD_Vertical_Packs::available() ) ) );
        }
        return array_values( array_unique( $ids ) );
    }

    /** The local selection for a company (0 means the request's company), validated against ids(). */
    private static function local_selection( $company_id ) {
        $company_id = absint( $company_id );
        $stored     = TZFD_Scoped_Store::get( self::LOCAL_OPTION, array(), $company_id > 0 ? $company_id : null );
        $ids        = self::known_ids();
        $primary    = '';
        $trades     = array();
        if ( is_array( $stored ) ) {
            $candidate = self::key_of( $stored['primary'] ?? '' );
            if ( in_array( $candidate, $ids, true ) ) { $primary = $candidate; }
            foreach ( (array) ( $stored['trades'] ?? array() ) as $item ) {
                $key = self::key_of( $item );
                if ( in_array( $key, $ids, true ) && ! in_array( $key, $trades, true ) ) { $trades[] = $key; }
            }
        }
        if ( '' === $primary ) { return array( 'primary' => null, 'trades' => array(), 'status' => 'none' ); }
        $trades = array_values( array_diff( $trades, array( $primary ) ) );
        array_unshift( $trades, $primary );
        return array(
            'primary' => $primary,
            'trades'  => array_slice( $trades, 0, self::MAX_TRADES ),
            'status'  => 'ok',
        );
    }

    /** A sanitised id from a scalar value, or '' for anything else. */
    private static function key_of( $v ) {
        return is_scalar( $v ) ? sanitize_key( (string) $v ) : '';
    }

    /** A plain-language message for a choose_primary() error code, for the onboarding wizard. */
    public static function message( $code ) {
        $messages = array(
            'tz_forbidden'         => 'Your vertical was not saved. Only a manager or supervisor can change the business verticals in Titan Zero Trades: Front Desk. Ask one to set it, then try this step again.',
            'tz_vertical_too_many' => 'Your vertical was not saved. This business already has five verticals. Remove one in Titan Zero Trades: Front Desk first.',
            'tz_vertical_unknown'  => 'Your vertical was not saved. That vertical is not in the Titan Zero Trades: Front Desk list.',
            'unavailable'          => 'Your vertical was not saved. Titan Zero Trades: Front Desk is not available right now.',
        );
        return isset( $messages[ (string) $code ] ) ? $messages[ (string) $code ] : 'Your vertical was not saved in Titan Zero Trades: Front Desk. Try again, or ask a manager to set it.';
    }

    /* ---------- cleaning ---------- */

    private static function key( $v ) {
        return function_exists( 'sanitize_key' ) ? sanitize_key( (string) $v ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) );
    }

    private static function text( $v ) {
        $v = is_scalar( $v ) ? (string) $v : '';
        return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $v ) : trim( wp_strip_all_tags( $v ) );
    }

    private static function block( $v ) {
        $v = is_scalar( $v ) ? (string) $v : '';
        return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $v ) : trim( wp_strip_all_tags( $v ) );
    }

    private static function texts( $list ) {
        $out = array();
        foreach ( (array) $list as $item ) {
            $t = self::text( $item );
            if ( '' !== $t ) { $out[] = $t; }
        }
        return array_values( array_unique( $out ) );
    }

    private static function signals( $raw ) {
        $raw = (array) $raw;
        $out = array( 'emergency' => array(), 'urgent' => array(), 'routine' => array() );
        foreach ( array_keys( $out ) as $level ) {
            $out[ $level ] = self::texts( $raw[ $level ] ?? array() );
        }
        return $out;
    }

    private static function qa( $list ) {
        $out = array();
        foreach ( (array) $list as $qa ) {
            if ( ! is_array( $qa ) ) { continue; }
            $q = self::text( $qa['q'] ?? '' );
            if ( '' === $q ) { continue; }
            $out[] = array(
                'q'        => $q,
                'a'        => function_exists( 'wp_kses_post' ) ? wp_kses_post( is_scalar( $qa['a'] ?? null ) ? (string) $qa['a'] : '' ) : trim( wp_strip_all_tags( is_scalar( $qa['a'] ?? null ) ? (string) $qa['a'] : '' ) ),
                'keywords' => self::text( $qa['keywords'] ?? '' ),
                'priority' => isset( $qa['priority'] ) ? (int) $qa['priority'] : 10,
            );
        }
        return $out;
    }
}
