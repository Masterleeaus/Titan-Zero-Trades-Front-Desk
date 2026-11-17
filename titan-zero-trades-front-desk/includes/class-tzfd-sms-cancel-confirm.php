<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned bookings table, read once per inbound reply. Values are bound with prepare(), and a cache would serve stale bookings.
/**
 * Two-step cancellation by SMS.
 *
 * A cancel text never cancels a booking on its own. It finds the one cancellable booking that matches the caller's
 * number, emails a one-time 6-digit code to that booking's e-mail address, and asks the customer to reply YES.
 * A reply of YES with the code, within 15 minutes, cancels the booking through the signed cancellation path that
 * the customer link uses (create_token / verify_token, row version, compare-and-swap, customer event outbox).
 *
 * Rules:
 * - The phone number only selects a candidate. Two or more candidate bookings, or none, never produce a code.
 * - The code is stored as a keyed hash, never in clear, and is bound to the conversation and the booking.
 * - A challenge is single use. Five wrong codes lock it until its 15-minute window ends.
 * - Every code submission counts as an attempt, whether or not YES is present.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-customer-cancellation.php';
require_once __DIR__ . '/class-tzfd-stored-data.php';
require_once __DIR__ . '/class-tzfd-contact-rules.php';

final class TZFD_SMS_Cancel_Confirm {
    /** Validity of the code and of the YES reply, in seconds from issue. */
    const CODE_TTL      = 900; // 15 minutes
    /** Wrong codes allowed before the challenge locks. */
    const MAX_WRONG     = 5;
    /** Booking rows scanned for a phone match. Bounds the work done inside one inbound event. */
    const SCAN_LIMIT    = 500;
    const OPTION_PREFIX = 'tzfd_sms_cancel_';

    /* ----------------------------- Pure rules (no WordPress calls) ----------------------------- */

    /** The one 6-digit code in a reply, or '' when there is none. Longer digit runs are not codes. */
    public static function extract_code( $body ) {
        if ( ! is_string( $body ) ) { return ''; }
        return 1 === preg_match( '/(?<!\d)(\d{6})(?!\d)/', $body, $m ) ? $m[1] : '';
    }

    /** True when the reply contains the word YES, in any case. */
    public static function says_yes( $body ) {
        return is_string( $body ) && 1 === preg_match( '/\byes\b/i', $body );
    }

    /** Builds a new challenge record. The code itself is never part of it. */
    public static function new_challenge( $company_id, $booking_id, $version, $code_digest, $now ) {
        return array(
            'company_id' => (int) $company_id,
            'booking_id' => (int) $booking_id,
            'version'    => (string) $version,
            'digest'     => (string) $code_digest,
            'issued'     => (int) $now,
            'expires'    => (int) $now + self::CODE_TTL,
            'wrong'      => 0,
        );
    }

    /**
     * Keyed digest of one code, bound to the conversation and the booking. $secret is the site auth salt.
     * Returns '' when the code is not six digits, so no caller can compare against an empty digest.
     */
    public static function digest( $code, $conversation_key, $booking_id, $secret ) {
        if ( ! is_string( $code ) || 1 !== preg_match( '/^[0-9]{6}$/D', $code ) || ! is_string( $secret ) || '' === $secret ) { return ''; }
        return hash_hmac( 'sha256', 'tzfd-sms-cancel-code/v1|' . $conversation_key . '|' . (int) $booking_id . '|' . $code, $secret );
    }

    /**
     * Applies one reply to an open challenge. $code_digest is '' when the reply has no code.
     * Steps: expired, locked, need_code, wrong, need_yes, confirmed.
     * Returns array( 'step' => string, 'challenge' => array|null ). A null challenge means it is cleared.
     */
    public static function evaluate( array $challenge, $code_digest, $yes, $now ) {
        if ( (int) $challenge['expires'] < (int) $now ) {
            return array( 'step' => 'expired', 'challenge' => null );
        }
        if ( (int) $challenge['wrong'] >= self::MAX_WRONG ) {
            return array( 'step' => 'locked', 'challenge' => $challenge );
        }
        if ( '' === $code_digest ) {
            return array( 'step' => 'need_code', 'challenge' => $challenge );
        }
        if ( ! hash_equals( (string) $challenge['digest'], (string) $code_digest ) ) {
            $challenge['wrong'] = (int) $challenge['wrong'] + 1;
            return array(
                'step'      => $challenge['wrong'] >= self::MAX_WRONG ? 'locked' : 'wrong',
                'challenge' => $challenge,
            );
        }
        if ( ! $yes ) {
            return array( 'step' => 'need_yes', 'challenge' => $challenge );
        }
        return array( 'step' => 'confirmed', 'challenge' => null );
    }

    /** True when any scalar string in decoded booking data normalises to the caller's E.164 number. */
    public static function data_has_phone( $value, $e164, $depth = 0 ) {
        if ( $depth > 32 ) { return false; }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( self::data_has_phone( $item, $e164, $depth + 1 ) ) { return true; }
            }
            return false;
        }
        return is_string( $value ) && '' !== $e164 && TZFD_Contact_Rules::normalise_e164( $value ) === $e164;
    }

    /* ----------------------------- Storage and WordPress calls ----------------------------- */

    private static function option_name( $conversation_key ) {
        return self::OPTION_PREFIX . substr( hash( 'sha256', (string) $conversation_key ), 0, 40 );
    }

    private static function save( $conversation_key, array $challenge ) {
        $name = self::option_name( $conversation_key );
        return add_option( $name, $challenge, '', false ) || update_option( $name, $challenge, false );
    }

    /** Removes the challenge. Called on opt-out, on use, on expiry and on any failure that must not leave it open. */
    public static function clear( $conversation_key ) {
        delete_option( self::option_name( $conversation_key ) );
    }

    /** The open challenge for a conversation, or null. An expired challenge is removed and reported as none. */
    public static function pending( $company_id, $conversation_key ) {
        $challenge = get_option( self::option_name( $conversation_key ), null );
        if ( ! is_array( $challenge ) || ! isset( $challenge['company_id'], $challenge['booking_id'], $challenge['version'],
            $challenge['digest'], $challenge['expires'], $challenge['wrong'] ) ) {
            return null;
        }
        if ( (int) $challenge['company_id'] !== (int) $company_id || (int) $challenge['expires'] < time() ) {
            self::clear( $conversation_key );
            return null;
        }
        return $challenge;
    }

    /**
     * Finds the cancellable bookings whose stored data holds this phone number and whose form belongs to this company.
     * Returns the rows with their e-mail address, or null when the lookup itself failed.
     */
    public static function candidates( $company_id, $e164 ) {
        global $wpdb;
        if ( '' === $e164 ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE posted_data LIKE %s ORDER BY id DESC LIMIT %d',
            $wpdb->prefix . TZFD_Tables::BOOKINGS, '%' . $wpdb->esc_like( substr( $e164, -6 ) ) . '%', self::SCAN_LIMIT
        ) );
        if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) { return null; }
        $found = array();
        foreach ( $rows as $row ) {
            try { $data = TZFD_Stored_Data::decode( (string) $row->posted_data ); }
            catch ( Throwable $error ) { continue; }
            if ( ! is_array( $data ) || ! TZFD_Customer_Cancellation::is_cancelable_data( $data ) || ! self::data_has_phone( $data, $e164 ) ) { continue; }
            $owner = TZFD_Customer_Cancellation::public_form_ownership( $row );
            if ( 'unavailable' === $owner['status'] ) { return null; }
            if ( 'matched' !== $owner['status'] || (int) $owner['company'] !== (int) $company_id ) { continue; }
            $email = TZFD_Customer_Cancellation::normalize_email( $row->notifyto );
            if ( '' === $email ) { continue; }
            $found[] = array( 'row' => $row, 'email' => $email );
        }
        return $found;
    }

    /**
     * Starts the challenge for a cancel text. Returns 'code_sent' when exactly one booking matched and the code
     * was emailed, or 'review' when nothing was sent (no match, several matches, or a failure).
     */
    public static function start( $company_id, $conversation_key, $e164 ) {
        $found = self::candidates( $company_id, $e164 );
        if ( ! is_array( $found ) || 1 !== count( $found ) ) { return 'review'; }
        $row    = $found[0]['row'];
        $secret = wp_salt( 'auth' );
        $code   = sprintf( '%06d', random_int( 0, 999999 ) );
        $challenge = self::new_challenge( $company_id, $row->id, TZFD_Customer_Cancellation::row_version( $row ),
            self::digest( $code, $conversation_key, $row->id, $secret ), time() );
        if ( ! self::save( $conversation_key, $challenge ) ) { return 'review'; }
        $body = sprintf(
            /* translators: 1: booking reference, 2: six-digit code, 3: validity in minutes */
            __( "Your code to cancel booking #%1\$d is %2\$s. It works for %3\$d minutes and can be used once. If you did not ask to cancel, ignore this e-mail.", 'titan-zero-trades-front-desk' ),
            (int) $row->id, $code, (int) ( self::CODE_TTL / 60 )
        );
        $sent = wp_mail( $found[0]['email'], __( 'Your booking cancellation code', 'titan-zero-trades-front-desk' ), $body,
            array( 'Content-Type: text/plain; charset=UTF-8' ) );
        if ( ! $sent ) {
            self::clear( $conversation_key );
            return 'review';
        }
        return 'code_sent';
    }

    /**
     * Applies one reply to the open challenge. Returns the step from evaluate(), or 'none' when no challenge is open.
     * A confirmed step cancels the booking, after the challenge has been cleared (single use).
     */
    public static function reply( $company_id, $conversation_key, $body ) {
        $challenge = self::pending( $company_id, $conversation_key );
        if ( null === $challenge ) { return 'none'; }
        $code   = self::extract_code( $body );
        $digest = '' === $code ? '' : self::digest( $code, $conversation_key, $challenge['booking_id'], wp_salt( 'auth' ) );
        $result = self::evaluate( $challenge, $digest, self::says_yes( $body ), time() );
        if ( 'confirmed' === $result['step'] ) {
            self::clear( $conversation_key );
            return self::cancel( $challenge );
        }
        if ( null === $result['challenge'] ) { self::clear( $conversation_key ); }
        else { self::save( $conversation_key, $result['challenge'] ); }
        return $result['step'];
    }

    /**
     * Cancels the verified booking through the signed cancellation path. The booking must still be the version the
     * code was sent for. Returns 'cancelled', 'changed' or 'failed'.
     */
    private static function cancel( array $challenge ) {
        global $wpdb;
        $lookup = TZFD_Customer_Cancellation::public_booking_lookup( $wpdb, (int) $challenge['booking_id'] );
        if ( 'found' !== $lookup['status'] ) { return 'failed'; }
        $row = $lookup['row'];
        if ( TZFD_Customer_Cancellation::row_version( $row ) !== $challenge['version'] ) { return 'changed'; }
        $email = TZFD_Customer_Cancellation::normalize_email( $row->notifyto );
        if ( '' === $email ) { return 'failed'; }
        $owner = TZFD_Customer_Cancellation::public_form_ownership( $row );
        if ( 'matched' !== $owner['status'] || (int) $owner['company'] !== (int) $challenge['company_id'] ) { return 'failed'; }
        try { $data = TZFD_Stored_Data::decode( (string) $row->posted_data ); }
        catch ( Throwable $error ) { return 'failed'; }
        if ( ! is_array( $data ) || ! TZFD_Customer_Cancellation::is_cancelable_data( $data ) ) { return 'changed'; }

        // The same signed check as the customer link: booking, e-mail, version and expiry must all verify.
        $secret  = wp_salt( 'auth' );
        $expires = TZFD_Customer_Cancellation::new_expiry();
        $token   = TZFD_Customer_Cancellation::create_token( $row->id, $email, $secret, $challenge['version'], $expires );
        if ( ! TZFD_Customer_Cancellation::verify_token( $row->id, $email, $token, $secret, $challenge['version'], $expires ) ) {
            return 'failed';
        }
        $outcome = TZFD_Customer_Cancellation::commit_verified( $row, (int) $row->id, (int) $challenge['company_id'] );
        if ( 'ok' === $outcome ) { return 'cancelled'; }
        return 'conflict' === $outcome ? 'changed' : 'failed';
    }
}
