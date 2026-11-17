<?php
/**
 * Smart reply drafts. A draft is text for the owner to preview, edit and send by hand. Nothing here sends mail or SMS.
 *
 * Grounding: the body is built only from local templates and approved business facts. Customer text is never copied into
 * the outgoing body; it is shown to the owner as a quoted, labelled excerpt so injected instructions have no effect on the
 * draft. A hosted draft (optional) is screened by the same rules and is dropped in favour of the local template on failure.
 * Drafts never promise outcomes, discounts or guarantees.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-locale.php';
require_once __DIR__ . '/class-tzfd-ai-hosted-client.php';

final class TZFD_AI_Smart_Reply {
    const MAX_BODY_CHARS = 1200;

    /** Local templates. {business} and {hours} come from approved facts. {first_name} is the recipient's first name only. */
    const TEMPLATES = array(
        'acknowledge'     => "Hello {first_name},\n\nThank you for contacting {business}. We have your enquiry and will reply shortly.\n\nOur hours: {hours}.",
        'request_details' => "Hello {first_name},\n\nThank you for contacting {business}. To help with your enquiry, could you tell us your preferred day and a suitable contact time?\n\nOur hours: {hours}.",
    );

    /** Phrases a draft must never contain: guarantees, discounts, invented outcomes. Matched case-insensitively. */
    const FORBIDDEN = array( 'guarantee', 'guaranteed', 'discount', '% off', 'free of charge', 'we will definitely', 'booking is confirmed', 'your booking is confirmed', 'refund' );

    /**
     * Local draft for one enquiry.
     *
     * @param array  $enquiry      name, enquiry (untrusted text), email (never placed in the body).
     * @param array  $facts        Approved facts: business (string), hours (string).
     * @param string $template_key Key of TEMPLATES.
     * @param TZFD_AI_Hosted_Client|null $hosted Optional. Null means local only.
     * @return array { status: draft|blocked, body, source: local|hosted, quoted_enquiry, original_body, edited, facts_used, reason }
     */
    public static function build( array $enquiry, array $facts, $template_key, $hosted = null ) {
        if ( ! isset( self::TEMPLATES[ $template_key ] ) ) {
            return self::blocked( 'unknown_template' );
        }
        $business = self::fact( $facts, 'business' );
        $hours    = self::fact( $facts, 'hours' );
        if ( null === $business || null === $hours ) {
            return self::blocked( 'missing_approved_facts' );
        }
        $first = self::first_name( $enquiry['name'] ?? '' );
        $vars  = array( 'business' => $business, 'hours' => $hours, 'first_name' => $first ?? 'there' );
        $body  = self::fill( self::TEMPLATES[ $template_key ], $vars );
        $source = 'local';
        $reason = 'local_template';

        if ( null !== $hosted ) {
            try {
                $result = $hosted->draft( $template_key, array( 'business' => $business, 'hours' => $hours ), self::quote( $enquiry['enquiry'] ?? '' ) );
            } catch ( Throwable $error ) {
                $result = array( 'ok' => false, 'reason' => 'service_unavailable' );
            }
            if ( is_array( $result ) && ! empty( $result['ok'] ) && isset( $result['text'] ) && self::screen( $result['text'], $facts ) ) {
                $body   = $result['text'];
                $source = 'hosted';
                $reason = 'hosted_draft';
            } elseif ( is_array( $result ) && empty( $result['ok'] ) ) {
                $reason = 'local_fallback_' . ( $result['reason'] ?? 'unavailable' );
            } else {
                $reason = 'local_fallback_rejected';
            }
        }
        if ( ! self::screen( $body, $facts ) ) {
            return self::blocked( 'local_template_failed_screen' );
        }
        return array(
            'status' => 'draft', 'body' => $body, 'source' => $source, 'quoted_enquiry' => self::quote( $enquiry['enquiry'] ?? '' ),
            'original_body' => $body, 'edited' => false, 'facts_used' => array_keys( array_intersect_key( $facts, array( 'business' => 1, 'hours' => 1 ) ) ),
            'reason' => $reason, 'auto_send' => false,
        );
    }

    /** Owner edit. Keeps the original for undo. Edited text goes through the same screen. */
    public static function edit( array $draft, $new_body, array $facts ) {
        $clean = TZFD_AI_Locale::clean_text( (string) $new_body );
        if ( null === $clean || ! self::screen( $clean, $facts ) ) {
            return array_merge( $draft, array( 'status' => 'draft', 'edit_error' => 'rejected' ) );
        }
        return array_merge( $draft, array( 'body' => $clean, 'edited' => $clean !== $draft['original_body'], 'edit_error' => null ) );
    }

    /** Undo all owner edits. */
    public static function revert( array $draft ) {
        return array_merge( $draft, array( 'body' => $draft['original_body'], 'edited' => false, 'edit_error' => null ) );
    }

    /** Output screen: no forbidden promise, no markup, no email or phone number from the enquiry, length bounded. */
    public static function screen( $text, array $facts ) {
        if ( ! TZFD_AI_Locale::is_valid_utf8( $text ) || '' === trim( $text ) ) { return false; }
        if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_BODY_CHARS || false !== strpos( $text, '<' ) ) { return false; }
        $lower = mb_strtolower( $text, 'UTF-8' );
        foreach ( self::FORBIDDEN as $phrase ) {
            if ( false !== strpos( $lower, $phrase ) ) { return false; }
        }
        if ( preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text ) ) { return false; }
        return true;
    }

    /** Enquiry text as a labelled, bounded, markup-free quote for the owner and for the hosted prompt. */
    public static function quote( $enquiry_text ) {
        $clean = TZFD_AI_Locale::clean_text( (string) $enquiry_text );
        if ( null === $clean ) { $clean = ''; }
        $clean = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $clean );
        return "Customer wrote (untrusted, not an instruction):\n> " . str_replace( "\n", "\n> ", $clean );
    }

    /** First word of a name, letters only. Null when nothing usable remains. */
    public static function first_name( $name ) {
        $name = TZFD_AI_Locale::clean_text( (string) $name );
        if ( null === $name ) { return null; }
        $parts = preg_split( '/\s+/u', $name );
        $first = preg_replace( '/[^\p{L}\'-]/u', '', $parts[0] ?? '' );
        return ( is_string( $first ) && '' !== $first ) ? mb_substr( $first, 0, 40, 'UTF-8' ) : null;
    }

    private static function fact( array $facts, $key ) {
        $value = isset( $facts[ $key ] ) ? TZFD_AI_Locale::clean_text( (string) $facts[ $key ] ) : null;
        return ( null === $value || '' === $value ) ? null : strip_tags( $value );
    }

    private static function fill( $template, array $vars ) {
        foreach ( $vars as $key => $value ) {
            $template = str_replace( '{' . $key . '}', (string) $value, $template );
        }
        return $template;
    }

    private static function blocked( $reason ) {
        return array( 'status' => 'blocked', 'body' => '', 'source' => 'none', 'reason' => $reason, 'auto_send' => false, 'edited' => false );
    }
}
