<?php
/**
 * Language tags, UTF-8 and RTL basics for the AI assist features. Pure functions, no WordPress calls.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Locale {
    const MAX_TEXT_CHARS = 2000;
    const RTL_LANGUAGES  = array( 'ar', 'fa', 'he', 'ur' );

    /** Canonical tag such as "en-AU" or "es", or null when the value is not a plausible BCP 47 tag. */
    public static function normalise_tag( $tag ) {
        if ( ! is_string( $tag ) ) { return null; }
        $tag = trim( str_replace( '_', '-', $tag ) );
        if ( ! preg_match( '/^([a-zA-Z]{2,3})(?:-([a-zA-Z]{2}))?$/D', $tag, $m ) ) { return null; }
        $canonical = strtolower( $m[1] );
        if ( isset( $m[2] ) ) { $canonical .= '-' . strtoupper( $m[2] ); }
        return $canonical;
    }

    public static function primary( $canonical ) {
        return strtolower( explode( '-', (string) $canonical )[0] );
    }

    public static function is_rtl( $canonical ) {
        return in_array( self::primary( $canonical ), self::RTL_LANGUAGES, true );
    }

    /** Valid UTF-8 without NUL bytes. Invalid byte sequences are refused rather than repaired. */
    public static function is_valid_utf8( $text ) {
        return is_string( $text ) && mb_check_encoding( $text, 'UTF-8' ) && false === strpos( $text, "\0" );
    }

    /**
     * Returns trimmed text with control characters removed (tabs and newlines kept), or null when the input is not
     * valid UTF-8, is empty, or exceeds MAX_TEXT_CHARS characters.
     */
    public static function clean_text( $text ) {
        if ( ! self::is_valid_utf8( $text ) ) { return null; }
        $clean = preg_replace( '/[^\P{C}\t\n\r]/u', '', $text );
        if ( null === $clean ) { return null; }
        $clean = trim( $clean );
        if ( '' === $clean || mb_strlen( $clean, 'UTF-8' ) > self::MAX_TEXT_CHARS ) { return null; }
        return $clean;
    }

    /**
     * Decide how to handle a requested language against the administrator's configured list.
     *
     * Returns array( 'locale' => canonical|null, 'action' => use|clarify|handoff, 'dir' => ltr|rtl ).
     * - No request: the first configured locale is used.
     * - Exact match or same primary language as a configured locale: used.
     * - Anything else: clarify in the first configured locale, or hand off when nothing is configured.
     */
    public static function resolve( $requested, array $configured ) {
        $allowed = array();
        foreach ( $configured as $tag ) {
            $canonical = self::normalise_tag( $tag );
            if ( null !== $canonical ) { $allowed[] = $canonical; }
        }
        $allowed = array_values( array_unique( $allowed ) );
        if ( array() === $allowed ) {
            return array( 'locale' => null, 'action' => 'handoff', 'dir' => 'ltr' );
        }
        $default = $allowed[0];
        if ( null === $requested || '' === trim( (string) $requested ) ) {
            return array( 'locale' => $default, 'action' => 'use', 'dir' => self::dir( $default ) );
        }
        $canonical = self::normalise_tag( $requested );
        if ( null !== $canonical ) {
            if ( in_array( $canonical, $allowed, true ) ) {
                return array( 'locale' => $canonical, 'action' => 'use', 'dir' => self::dir( $canonical ) );
            }
            foreach ( $allowed as $candidate ) {
                if ( self::primary( $candidate ) === self::primary( $canonical ) ) {
                    return array( 'locale' => $candidate, 'action' => 'use', 'dir' => self::dir( $candidate ) );
                }
            }
        }
        return array( 'locale' => $default, 'action' => 'clarify', 'dir' => self::dir( $default ) );
    }

    private static function dir( $canonical ) {
        return self::is_rtl( $canonical ) ? 'rtl' : 'ltr';
    }
}
