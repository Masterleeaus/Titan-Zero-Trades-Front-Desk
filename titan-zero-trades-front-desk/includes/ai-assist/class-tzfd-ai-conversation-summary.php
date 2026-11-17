<?php
/**
 * Owner-visible conversation summaries. Non-authoritative drafts; the transcript remains the record.
 *
 * - Consent first: a thread without recorded contact consent produces no summary.
 * - Bounded: at most MAX_MESSAGES messages and MAX_TOTAL_CHARS characters are read. Longer threads keep the first and last
 *   messages and say how many were omitted.
 * - Redacted: email addresses and phone numbers are replaced before any text is kept.
 * - Grounded: every field lists the message ids it came from.
 * - Fabrication check: a claimed action (booked, confirmed, refunded, paid, cancelled, rescheduled) with no matching record
 *   status is flagged in the uncertainty list.
 * - Role-gated: the read helper needs the owner capability passed in by the caller.
 * Summaries are generated on demand and are not stored, so there is no summary table to export or erase.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-locale.php';

final class TZFD_AI_Conversation_Summary {
    const MAX_MESSAGES     = 30;
    const KEEP_HEAD        = 5;
    const MAX_TOTAL_CHARS  = 8000;
    const READ_CAPABILITY  = 'tz_fd_access';

    /** Claimed action words and the record status that would support them. */
    const ACTION_CLAIMS = array(
        'booked'      => array( 'confirmed', 'booked' ),
        'confirmed'   => array( 'confirmed', 'booked' ),
        'cancelled'   => array( 'cancelled' ),
        'rescheduled' => array( 'rescheduled' ),
        'refunded'    => array( 'refunded' ),
        'paid'        => array( 'paid' ),
    );

    /** Role gate for reading summaries. */
    public static function can_read( array $capabilities ) {
        return ! empty( $capabilities[ self::READ_CAPABILITY ] );
    }

    /**
     * @param array $thread   Messages: id, from (customer|owner), text, at_utc.
     * @param bool  $consent  Recorded contact consent for this customer.
     * @param array $records  Record facts: status values such as confirmed, cancelled, paid, refunded, rescheduled.
     * @param array $caps     Capabilities of the person asking to read the summary.
     * @return array status: summary|blocked|forbidden, plus fields when a summary exists.
     */
    public static function summarise( array $thread, $consent, array $records, array $caps ) {
        if ( ! self::can_read( $caps ) ) { return array( 'status' => 'forbidden' ); }
        if ( true !== $consent ) { return array( 'status' => 'blocked', 'reason' => 'no_consent' ); }

        $bounded   = self::bound( $thread );
        $messages  = $bounded['messages'];
        $customer  = array_values( array_filter( $messages, function ( $m ) { return ( $m['from'] ?? '' ) === 'customer'; } ) );
        $owner_ids = array();
        $all_text  = array();
        $redacted  = false;
        foreach ( $messages as $m ) {
            $clean = TZFD_AI_Locale::clean_text( (string) ( $m['text'] ?? '' ) );
            if ( null === $clean ) { continue; }
            $safe = self::redact( $clean );
            $redacted = $redacted || $safe !== $clean;
            $all_text[ $m['id'] ] = array( 'from' => $m['from'] ?? '', 'text' => $safe );
            if ( ( $m['from'] ?? '' ) === 'owner' ) { $owner_ids[] = $m['id']; }
        }

        $stated = null;
        $stated_id = null;
        foreach ( $customer as $m ) {
            if ( isset( $all_text[ $m['id'] ] ) ) { $stated = self::first_sentence( $all_text[ $m['id'] ]['text'] ); $stated_id = $m['id']; break; }
        }

        $outstanding = array();
        $last_owner  = 0;
        foreach ( $messages as $index => $m ) { if ( ( $m['from'] ?? '' ) === 'owner' ) { $last_owner = $index; } }
        foreach ( $messages as $index => $m ) {
            if ( ( $m['from'] ?? '' ) !== 'customer' || $index < $last_owner || ! isset( $all_text[ $m['id'] ] ) ) { continue; }
            foreach ( self::sentences( $all_text[ $m['id'] ]['text'] ) as $sentence ) {
                if ( false !== strpos( $sentence, '?' ) ) { $outstanding[] = array( 'text' => $sentence, 'source' => $m['id'] ); }
            }
        }

        $uncertainty = array();
        if ( $bounded['truncated'] ) { $uncertainty[] = sprintf( 'Thread truncated: %d message(s) not read.', $bounded['omitted'] ); }
        if ( null === $stated ) { $uncertainty[] = 'No customer request could be identified from the text.'; }

        $summary_text = implode( ' ', array_map( function ( $row ) { return $row['text']; }, $all_text ) );
        foreach ( self::fabricated_claims( $summary_text, $records ) as $claim ) {
            $uncertainty[] = 'Unsupported claim in thread: "' . $claim . '" has no matching record.';
        }

        return array(
            'status'           => 'summary',
            'non_authoritative' => true,
            'stated_request'   => $stated,
            'stated_request_source' => $stated_id,
            'outstanding'      => $outstanding,
            'next_step'        => empty( $outstanding ) ? 'No open question from the customer; check the transcript before acting.' : 'Answer the open question(s) above.',
            'uncertainty'      => $uncertainty,
            'source_message_ids' => array_keys( $all_text ),
            'redacted'         => $redacted,
            'truncated'        => $bounded['truncated'],
        );
    }

    /** Keep the first KEEP_HEAD and the most recent messages up to MAX_MESSAGES and MAX_TOTAL_CHARS. */
    public static function bound( array $thread ) {
        $total = count( $thread );
        $list  = $thread;
        if ( $total > self::MAX_MESSAGES ) {
            $head = array_slice( $list, 0, self::KEEP_HEAD );
            $tail = array_slice( $list, - ( self::MAX_MESSAGES - self::KEEP_HEAD ) );
            $list = array_merge( $head, $tail );
        }
        $chars = 0;
        $kept  = array();
        foreach ( $list as $m ) {
            $len = mb_strlen( (string) ( $m['text'] ?? '' ), 'UTF-8' );
            if ( $chars + $len > self::MAX_TOTAL_CHARS ) { break; }
            $chars += $len;
            $kept[] = $m;
        }
        return array( 'messages' => $kept, 'truncated' => count( $kept ) < $total, 'omitted' => $total - count( $kept ) );
    }

    /** Replace email addresses and phone-like numbers. */
    public static function redact( $text ) {
        $text = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text );
        return preg_replace( '/(?<!\w)(?:\+?\d[\d \-()]{7,}\d)(?!\w)/', '[phone]', $text );
    }

    /** Action claims in the summary text that no record supports. Returns the claimed words. */
    public static function fabricated_claims( $summary_text, array $records ) {
        $found = array();
        $lower = mb_strtolower( (string) $summary_text, 'UTF-8' );
        foreach ( self::ACTION_CLAIMS as $word => $supported_by ) {
            if ( ! preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/u', $lower ) ) { continue; }
            $ok = false;
            foreach ( $supported_by as $status ) {
                if ( ! empty( $records[ $status ] ) ) { $ok = true; break; }
            }
            if ( ! $ok ) { $found[] = $word; }
        }
        return $found;
    }

    private static function sentences( $text ) {
        $parts = preg_split( '/(?<=[.!?])\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? array_map( 'trim', $parts ) : array( (string) $text );
    }

    private static function first_sentence( $text ) {
        $parts = self::sentences( $text );
        return mb_substr( (string) ( $parts[0] ?? '' ), 0, 300, 'UTF-8' );
    }
}
