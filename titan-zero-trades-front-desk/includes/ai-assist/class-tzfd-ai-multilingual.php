<?php
/**
 * Basic multilingual replies: fixed, reviewed intent texts first, then a short hosted translation of approved content.
 *
 * Service names and dates are passed through verbatim, never translated. A hosted translation is accepted only when it
 * keeps every protected term, contains no markup and stays within the length limit; otherwise the English approved text
 * is returned with a handoff action.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-locale.php';
require_once __DIR__ . '/class-tzfd-ai-hosted-client.php';

final class TZFD_AI_Multilingual {
    const MAX_HOSTED_CHARS = 600;
    const OPTION           = 'tzfd_ai_languages';
    const MAX_LOCALES      = 5;
    const SAVE_CAPABILITY  = 'manage_options';

    /** Administrator-configured locales (canonical tags, first entry is the default). Empty until an administrator saves a list. */
    public static function configured_locales() {
        $stored = get_option( self::OPTION, array() );
        return self::clean_locales( is_array( $stored ) ? $stored : array() );
    }

    /**
     * Saves the administrator's language list. Only administrators may change it. Invalid tags are dropped, duplicates
     * removed and the list capped at MAX_LOCALES. Returns the saved list, or false when the caller is not allowed.
     */
    public static function save_configured_locales( array $tags ) {
        if ( ! current_user_can( self::SAVE_CAPABILITY ) ) {
            return false;
        }
        $clean = self::clean_locales( $tags );
        update_option( self::OPTION, $clean, false );
        return $clean;
    }

    /** Reply using the saved language list and the hosted translation connector. Consent is enforced by the connector. */
    public static function reply_configured( $intent, $requested, array $vars = array() ) {
        return self::reply( $intent, $requested, self::configured_locales(), new TZFD_AI_Hosted_Connector_Client(), $vars );
    }

    /** Keeps valid tags only, in their given order, without duplicates, up to MAX_LOCALES. */
    public static function clean_locales( array $tags ) {
        $out = array();
        foreach ( $tags as $tag ) {
            $canonical = TZFD_AI_Locale::normalise_tag( $tag );
            if ( null !== $canonical && ! in_array( $canonical, $out, true ) ) {
                $out[] = $canonical;
            }
            if ( count( $out ) >= self::MAX_LOCALES ) {
                break;
            }
        }
        return $out;
    }

    /**
     * Approved English intent texts. Placeholders are {service} and {date}, filled from validated values only.
     * Translations in FIXED_TEXT were written as fixtures and must be reviewed by a native speaker before release.
     */
    const APPROVED_EN = array(
        'booking_confirm_request' => 'Please reply to confirm your booking for {service} on {date}.',
        'opening_hours'           => 'Our office hours are shared on our booking page.',
        'contact_human'           => 'A team member will reply to you personally.',
    );

    const FIXED_TEXT = array(
        'en-AU' => array(
            'booking_confirm_request' => 'Please reply to confirm your booking for {service} on {date}.',
            'opening_hours'           => 'Our office hours are shared on our booking page.',
            'contact_human'           => 'A team member will reply to you personally.',
        ),
        'es' => array(
            'booking_confirm_request' => 'Responda para confirmar su cita de {service} el {date}.',
            'contact_human'           => 'Un miembro de nuestro equipo le responderá personalmente.',
        ),
        'fr' => array(
            'booking_confirm_request' => 'Veuillez répondre pour confirmer votre rendez-vous pour {service} le {date}.',
            'opening_hours'           => 'Nos horaires d’ouverture figurent sur notre page de réservation.',
        ),
    );

    /**
     * Build the reply for one intent.
     *
     * @param string $intent        Key of APPROVED_EN.
     * @param mixed  $requested     Language tag from the customer or null.
     * @param array  $configured    Administrator-configured locales, first entry is the default.
     * @param TZFD_AI_Hosted_Client $hosted Hosted client (default: unavailable).
     * @param array  $vars          Placeholder values: service (protected term) and date (already formatted in the site timezone).
     * @return array { action: use|clarify|handoff, locale, dir, text, source: fixed|hosted|english|none, reason }
     */
    public static function reply( $intent, $requested, array $configured, TZFD_AI_Hosted_Client $hosted, array $vars = array() ) {
        $route = TZFD_AI_Locale::resolve( $requested, $configured );
        if ( null === $route['locale'] || ! isset( self::APPROVED_EN[ $intent ] ) ) {
            return array( 'action' => 'handoff', 'locale' => null, 'dir' => 'ltr', 'text' => '', 'source' => 'none', 'reason' => 'no_locale_or_intent' );
        }
        $locale   = $route['locale'];
        $protect  = self::protected_terms( $vars );
        $fixed    = self::fixed_for( $locale, $intent );
        $english  = self::fill( self::APPROVED_EN[ $intent ], $vars );

        if ( null !== $fixed ) {
            $text   = self::fill( $fixed, $vars );
            $source = 'fixed';
            $reason = 'fixed_translation';
        } else {
            $text   = null;
            $source = 'english';
            $reason = 'missing_translation';
            if ( self::is_hosted_candidate( $english ) ) {
                $result = self::safe_translate( $hosted, $english, $locale );
                if ( is_array( $result ) && ! empty( $result['ok'] ) && isset( $result['text'] ) && self::accept_hosted( $result['text'], $protect ) ) {
                    $text   = $result['text'];
                    $source = 'hosted';
                    $reason = 'hosted_translation';
                } else {
                    $reason = is_array( $result ) && isset( $result['reason'] ) ? 'hosted_' . $result['reason'] : 'hosted_rejected';
                }
            }
        }
        if ( null === $text ) {
            // Gracefully missing translation: approved English plus a handoff, never an invented translation.
            return array( 'action' => 'handoff', 'locale' => $locale, 'dir' => 'ltr', 'text' => $english, 'source' => 'english', 'reason' => $reason );
        }
        return array( 'action' => $route['action'] === 'clarify' ? 'clarify' : 'use', 'locale' => $locale, 'dir' => $route['dir'], 'text' => $text, 'source' => $source, 'reason' => $reason );
    }

    /** A hosted outage (exception or bad result) is reported as unavailable, never as a fatal error. */
    private static function safe_translate( TZFD_AI_Hosted_Client $hosted, $english, $locale ) {
        try {
            return $hosted->translate( $english, 'en', $locale );
        } catch ( Throwable $error ) {
            return array( 'ok' => false, 'reason' => 'service_unavailable' );
        }
    }

    /** Fixed translation for a locale, falling back to a same-language tag, or null. */
    private static function fixed_for( $locale, $intent ) {
        if ( isset( self::FIXED_TEXT[ $locale ][ $intent ] ) ) { return self::FIXED_TEXT[ $locale ][ $intent ]; }
        $primary = TZFD_AI_Locale::primary( $locale );
        if ( isset( self::FIXED_TEXT[ $primary ][ $intent ] ) ) { return self::FIXED_TEXT[ $primary ][ $intent ]; }
        return null;
    }

    /** Only approved English text goes to the hosted service, and only when it is short. */
    private static function is_hosted_candidate( $english ) {
        return mb_strlen( $english, 'UTF-8' ) <= self::MAX_HOSTED_CHARS;
    }

    /** Hosted output is accepted only if it is clean UTF-8, markup-free, bounded and keeps every protected term. */
    public static function accept_hosted( $candidate, array $protected ) {
        if ( ! TZFD_AI_Locale::is_valid_utf8( $candidate ) || '' === trim( $candidate ) ) { return false; }
        if ( mb_strlen( $candidate, 'UTF-8' ) > self::MAX_HOSTED_CHARS ) { return false; }
        if ( $candidate !== strip_tags( $candidate ) || false !== strpos( $candidate, '<' ) ) { return false; }
        foreach ( $protected as $term ) {
            if ( '' !== $term && false === strpos( $candidate, $term ) ) { return false; }
        }
        return true;
    }

    /** Protected terms are the values that must survive translation unchanged: service names and formatted dates. */
    private static function protected_terms( array $vars ) {
        $terms = array();
        foreach ( array( 'service', 'date' ) as $key ) {
            if ( isset( $vars[ $key ] ) && is_string( $vars[ $key ] ) && '' !== $vars[ $key ] ) { $terms[] = $vars[ $key ]; }
        }
        return $terms;
    }

    /** Fill {placeholders} with plain text values. Values are stripped of markup and control characters first. */
    private static function fill( $template, array $vars ) {
        $out = $template;
        foreach ( array( 'service', 'date' ) as $key ) {
            if ( isset( $vars[ $key ] ) ) {
                $value = TZFD_AI_Locale::clean_text( (string) $vars[ $key ] );
                $value = null === $value ? '' : strip_tags( $value );
                $out   = str_replace( '{' . $key . '}', $value, $out );
            }
        }
        return $out;
    }
}
