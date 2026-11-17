<?php
/**
 * Consented SMS reply events from the connected messaging provider (issue #31).
 *
 * Route: POST /titan-zero-front-desk/v1/messaging-events, signed with the same provider credentials as the
 * call-events adapter (X-TZFD-Key-Id, X-TZFD-Timestamp, X-TZFD-Signature = hex HMAC-SHA256 of "<ts>.<raw body>").
 * Event type: inbound_sms, with from, body, event_id and consent = customer_initiated.
 *
 * Order of checks for each reply:
 *  1. Signature, timestamp window and replay (claim via TZFD_Signed_Request, the replay guard interface).
 *  2. STOP and START keywords update the opt-out flag first. A STOP that cannot be saved returns 503.
 *  3. The event is claimed once. A repeated provider event sends nothing.
 *  4. Opt-out and consent are checked. Only then is a rule (or optional fallback) decision applied.
 *  5. An automated reply takes one guarded turn, at most 3 per conversation per window.
 *  6. Only fixed templates are queued, through the outbox, after a tenant check.
 * A cancellation is never performed from one text. A cancel text starts a two-step confirmation: a YES reply plus
 *  a one-time code emailed to the booking address (TZFD_SMS_Cancel_Confirm). Only that reply can cancel.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-rest-request-guard.php';
require_once __DIR__ . '/class-tzfd-signed-request.php';
require_once __DIR__ . '/class-tzfd-telephony-adapter.php';
require_once __DIR__ . '/class-tzfd-contact-rules.php';
require_once __DIR__ . '/class-tzfd-contact-actions.php';
require_once __DIR__ . '/class-tzfd-outbox.php';
require_once __DIR__ . '/class-tzfd-customer-cancellation.php';
require_once __DIR__ . '/class-tzfd-sms-cancel-confirm.php';

final class TZFD_Messaging_Replies {
    const ROUTE       = '/messaging-events';
    const EVENT       = 'inbound_sms';
    const MAX_BYTES   = 16384;
    const MAX_FIELDS  = 25;

    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'admin_init', array( 'TZFD_Contact_Actions', 'maybe_install' ) );
        TZFD_Outbox::boot();
    }

    public static function routes() {
        register_rest_route( 'titan-zero-front-desk/v1', self::ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'receive' ),
            'permission_callback' => array( __CLASS__, 'authorise' ),
        ) );
    }

    /** Permission step. Verifies the signature and claims it once. Fails closed without a credentials resolver. */
    public static function authorise( $request ) {
        $event = self::verified_event( $request, time() );
        if ( null === $event ) {
            return new WP_Error( 'tzfd_messaging_unauthorised', __( 'Invalid messaging request.', 'titan-zero-trades-front-desk' ), array( 'status' => 401 ) );
        }
        if ( ! TZFD_Signed_Request::claim_signature( (string) $request->get_header( 'x-tzfd-signature' ) ) ) {
            return new WP_Error( 'tzfd_messaging_replay', __( 'This request was already received.', 'titan-zero-trades-front-desk' ), array( 'status' => 401 ) );
        }
        return true;
    }

    public static function receive( $request ) {
        $event = self::verified_event( $request, time() );
        if ( null === $event ) {
            return new WP_Error( 'tzfd_messaging_unauthorised', __( 'Invalid messaging request.', 'titan-zero-trades-front-desk' ), array( 'status' => 401 ) );
        }
        $result = self::process( $event['company_id'], $event['provider'], $event['event_id'], $event['from'], $event['body'], $event['consent'] );
        if ( is_wp_error( $result ) ) { return $result; }
        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Verifies one provider event. Returns the event fields, with company taken only from the credentials.
     * Returns null for any failure. Pure apart from the credentials filter.
     */
    public static function verified_event( $request, $now ) {
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', self::MAX_BYTES, self::MAX_FIELDS ) ) { return null; }
        $raw       = $request->get_body();
        $key_id    = (string) $request->get_header( 'x-tzfd-key-id' );
        $timestamp = (string) $request->get_header( 'x-tzfd-timestamp' );
        $signature = (string) $request->get_header( 'x-tzfd-signature' );
        if ( ! is_string( $raw ) || strlen( $raw ) < 2 || ! preg_match( '/^[A-Za-z0-9_-]{1,80}$/D', $key_id ) ||
             ! preg_match( '/^[0-9]{10,12}$/D', $timestamp ) || abs( $now - (int) $timestamp ) > TZFD_Telephony_Adapter::WINDOW_SECONDS ||
             ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) {
            return null;
        }
        $creds = apply_filters( 'tzfd_telephony_provider_credentials_v1', null, $key_id );
        if ( ! is_array( $creds ) || empty( $creds['enabled'] ) || ! isset( $creds['company_id'], $creds['provider'], $creds['secret'] ) ||
             ! is_int( $creds['company_id'] ) || $creds['company_id'] < 1 ||
             ! is_string( $creds['provider'] ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $creds['provider'] ) ||
             ! is_string( $creds['secret'] ) || strlen( $creds['secret'] ) < 32 ) {
            return null;
        }
        if ( ! hash_equals( hash_hmac( 'sha256', $timestamp . '.' . $raw, $creds['secret'] ), $signature ) ) { return null; }

        $body = json_decode( $raw, true );
        if ( ! is_array( $body ) || ( $body['event'] ?? null ) !== self::EVENT ||
             ! isset( $body['event_id'], $body['from'], $body['body'], $body['consent'] ) ||
             ! is_string( $body['event_id'] ) || ! is_string( $body['from'] ) || ! is_string( $body['body'] ) || ! is_string( $body['consent'] ) ||
             strlen( $body['event_id'] ) < 1 || strlen( $body['event_id'] ) > 255 ||
             strlen( $body['from'] ) > 40 || strlen( $body['body'] ) > TZFD_Contact_Rules::MAX_REPLY_LENGTH || strlen( $body['consent'] ) > 40 ) {
            return null;
        }
        return array(
            'company_id' => $creds['company_id'],
            'provider'   => $creds['provider'],
            'event_id'   => $body['event_id'],
            'from'       => $body['from'],
            'body'       => $body['body'],
            'consent'    => $body['consent'],
        );
    }

    /**
     * Handles one verified inbound reply. Returns an array for the provider (no customer data), or a WP_Error
     * when a write failed and the provider should retry.
     */
    public static function process( $company_id, $provider, $event_id, $from_raw, $body, $consent ) {
        if ( ! TZFD_Contact_Rules::valid_identity( $company_id, $provider, $event_id ) ) {
            return new WP_Error( 'tzfd_messaging_invalid', __( 'Invalid messaging event.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        // Wrong-tenant denial happens before any write, so a mismatched credential creates no task, claim or opt-out.
        if ( ! self::same_tenant( $company_id ) ) {
            return new WP_Error( 'tzfd_messaging_tenant', __( 'This request is not for the active business.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        $e164 = TZFD_Contact_Rules::normalise_e164( $from_raw );
        if ( '' === $e164 ) { return array( 'ok' => true, 'action' => 'ignored_no_number' ); }

        $conv   = TZFD_Contact_Rules::conversation_key( $company_id, $e164 );
        $intent = TZFD_Contact_Rules::classify_reply( $body );

        if ( TZFD_Contact_Rules::INTENT_STOP === $intent ) {
            if ( ! TZFD_Contact_Actions::set_opt_out( $company_id, $conv, true ) ) {
                return new WP_Error( 'tzfd_messaging_optout_unsaved', __( 'Opt-out could not be saved. Please retry.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
            }
            // An open cancellation code must not survive an opt-out.
            TZFD_SMS_Cancel_Confirm::clear( $conv );
            // The shared outbox must honour the same opt-out. Never acknowledge a STOP it did not store.
            if ( ! TZFD_Outbox::opt_out( $e164 ) ) {
                return new WP_Error( 'tzfd_messaging_optout_unsaved', __( 'Opt-out could not be saved. Please retry.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
            }
            return array( 'ok' => true, 'action' => 'opt_out' );
        }
        if ( TZFD_Contact_Rules::INTENT_START === $intent ) {
            if ( ! TZFD_Contact_Actions::set_opt_out( $company_id, $conv, false ) ) {
                return new WP_Error( 'tzfd_messaging_optin_unsaved', __( 'Opt-in could not be saved. Please retry.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
            }
            return array( 'ok' => true, 'action' => 'opt_in' );
        }

        $event_key = TZFD_Contact_Rules::dedupe_key( $company_id, 'sms_reply', $provider . '|' . $event_id );
        $claim = TZFD_Contact_Actions::claim_action( $company_id, $event_key, 'reply', 'sms_reply', $e164, 'none', null, $intent, 'processing' );
        if ( 'error' === $claim ) { return self::retry_error(); }
        if ( 'duplicate' === $claim ) { return array( 'ok' => true, 'action' => 'duplicate' ); }

        $opted_out = TZFD_Contact_Actions::is_opted_out( $company_id, $conv );
        if ( $opted_out ) { TZFD_SMS_Cancel_Confirm::clear( $conv ); }
        // An open cancellation challenge takes the reply before intent rules. Only YES and the emailed code can act on it.
        if ( ! $opted_out && TZFD_Contact_Rules::CONSENT_CUSTOMER_INITIATED === $consent &&
             null !== TZFD_SMS_Cancel_Confirm::pending( $company_id, $conv ) ) {
            return self::cancel_reply( $company_id, $provider, $event_id, $event_key, $e164, $conv, $body );
        }
        $fallback  = null;
        if ( ( TZFD_Contact_Rules::INTENT_AMBIGUOUS === $intent || TZFD_Contact_Rules::INTENT_UNKNOWN === $intent ) &&
             TZFD_Contact_Rules::CONSENT_CUSTOMER_INITIATED === $consent && ! $opted_out ) {
            // Optional connector fallback. Default is none. Nothing is called unless an owner connects one.
            $fallback = apply_filters( 'tzfd_sms_reply_model_fallback_v1', null, $company_id, $body );
        }
        $decision = TZFD_Contact_Rules::decide_reply( $intent, array(
            'opted_out'       => $opted_out,
            'consent_basis'   => $consent,
            'turns_used'      => TZFD_Contact_Actions::turns_used( $company_id, $conv ),
            'fallback_intent' => is_string( $fallback ) ? $fallback : null,
        ) );

        // A cancel request never cancels from one text. It starts the two-step code confirmation.
        if ( TZFD_Contact_Rules::INTENT_CANCEL === $decision['intent'] && 'respond' === $decision['action'] ) {
            return self::cancel_request( $company_id, $provider, $event_id, $event_key, $e164, $conv );
        }

        if ( null !== $decision['task'] ) {
            $task_key = TZFD_Contact_Rules::dedupe_key( $company_id, 'sms_reply_task', $provider . '|' . $event_id . '|' . $decision['task'] );
            $task = TZFD_Contact_Actions::claim_action( $company_id, $task_key, $decision['task'], 'sms_reply', $e164, 'none', null, $decision['intent'], 'open' );
            if ( 'error' === $task ) {
                TZFD_Contact_Actions::release_action( $company_id, $event_key );
                return self::retry_error();
            }
        }

        $replied = false;
        $action  = $decision['action'];
        if ( 'respond' === $action ) {
            if ( ! TZFD_Contact_Actions::claim_turn( $company_id, $conv ) ) {
                $action = 'turn_limit';
            } else {
                $text = TZFD_Contact_Rules::template_text( $decision['template'], self::booking_link( $company_id ) );
                $ref  = TZFD_Outbox::queue( 'sms', $e164, $text, 0, 'sms_reply' );
                if ( '' === $ref ) {
                    TZFD_Contact_Actions::release_turn( $company_id, $conv );
                    TZFD_Contact_Actions::release_action( $company_id, $event_key );
                    return self::retry_error();
                }
                $replied = true;
            }
        }

        TZFD_Contact_Actions::set_action_state( $company_id, $event_key, 'done' );
        return array( 'ok' => true, 'action' => $action, 'reply' => $replied );
    }

    /** Cancel text with no open challenge: sends the code request, or a staff review when the booking is not unique. */
    private static function cancel_request( $company_id, $provider, $event_id, $event_key, $e164, $conv ) {
        if ( ! TZFD_Contact_Actions::claim_turn( $company_id, $conv ) ) {
            if ( ! self::claim_cancel_review( $company_id, $provider, $event_id, $e164 ) ) {
                TZFD_Contact_Actions::release_action( $company_id, $event_key );
                return self::retry_error();
            }
            TZFD_Contact_Actions::set_action_state( $company_id, $event_key, 'done' );
            return array( 'ok' => true, 'action' => 'turn_limit' );
        }
        $start     = TZFD_SMS_Cancel_Confirm::start( $company_id, $conv, $e164 );
        $code_sent = 'code_sent' === $start;
        if ( ! $code_sent && ! self::claim_cancel_review( $company_id, $provider, $event_id, $e164 ) ) {
            TZFD_Contact_Actions::release_turn( $company_id, $conv );
            TZFD_Contact_Actions::release_action( $company_id, $event_key );
            return self::retry_error();
        }
        return self::queue_reply( $company_id, $conv, $e164, $event_key, $code_sent ? 'cancel_code_sent' : 'cancel_received',
            $code_sent ? 'cancel_code_sent' : 'cancel_review', true, true );
    }

    /** Reply while a cancel challenge is open: checks YES and the code, and cancels only on a confirmed step. */
    private static function cancel_reply( $company_id, $provider, $event_id, $event_key, $e164, $conv, $body ) {
        $step = TZFD_SMS_Cancel_Confirm::reply( $company_id, $conv, $body );
        $review = in_array( $step, array( 'changed', 'failed', 'expired', 'none' ), true );
        if ( $review && ! self::claim_cancel_review( $company_id, $provider, $event_id, $e164 ) ) {
            TZFD_Contact_Actions::release_action( $company_id, $event_key );
            return self::retry_error();
        }
        $templates = array(
            'cancelled'  => 'cancel_done',
            'need_code'  => 'cancel_need_code',
            'need_yes'   => 'cancel_need_yes',
            'wrong'      => 'cancel_wrong_code',
            'locked'     => 'cancel_locked',
        );
        $template = isset( $templates[ $step ] ) ? $templates[ $step ] : 'cancel_received';
        // A confirmed cancellation is never blocked by the reply cap. Its confirmation text is sent only if a turn is free.
        $turned = TZFD_Contact_Actions::claim_turn( $company_id, $conv );
        if ( ! $turned ) {
            TZFD_Contact_Actions::set_action_state( $company_id, $event_key, 'done' );
            return array( 'ok' => true, 'action' => 'cancelled' === $step ? 'cancelled' : 'turn_limit', 'reply' => false );
        }
        return self::queue_reply( $company_id, $conv, $e164, $event_key, $template, $step, true, 'cancelled' !== $step );
    }

    /** Creates the staff review task for a cancel request. Returns false when the write failed. */
    private static function claim_cancel_review( $company_id, $provider, $event_id, $e164 ) {
        $key = TZFD_Contact_Rules::dedupe_key( $company_id, 'sms_reply_task', $provider . '|' . $event_id . '|cancel_review' );
        return 'error' !== TZFD_Contact_Actions::claim_action( $company_id, $key, 'cancel_review', 'sms_reply', $e164, 'none', null, TZFD_Contact_Rules::INTENT_CANCEL, 'open' );
    }

    /**
     * Queues one fixed template and marks the event done. When $must_send is false, a queue failure does not ask the
     * provider to retry, because the state change behind the reply has already been saved.
     */
    private static function queue_reply( $company_id, $conv, $e164, $event_key, $template, $action, $turned, $must_send ) {
        $text = TZFD_Contact_Rules::template_text( $template, self::booking_link( $company_id ) );
        $ref  = TZFD_Outbox::queue( 'sms', $e164, $text, 0, 'sms_reply' );
        if ( '' === $ref && $must_send ) {
            if ( $turned ) { TZFD_Contact_Actions::release_turn( $company_id, $conv ); }
            TZFD_Contact_Actions::release_action( $company_id, $event_key );
            return self::retry_error();
        }
        TZFD_Contact_Actions::set_action_state( $company_id, $event_key, 'done' );
        return array( 'ok' => true, 'action' => $action, 'reply' => '' !== $ref );
    }

    /** The outbox reads the company from the site context. Refuse when that does not match the verified company. */
    private static function same_tenant( $company_id ) {
        return class_exists( 'TZFD_Scoped_Store' ) && (int) TZFD_Scoped_Store::company_id() === (int) $company_id;
    }

    private static function booking_link( $company_id ) {
        $link = apply_filters( 'tzfd_sms_reply_booking_url_v1', home_url( '/' ), $company_id );
        return is_string( $link ) && 0 === strpos( $link, 'https://' ) ? $link : home_url( '/' );
    }

    private static function retry_error() {
        return new WP_Error( 'tzfd_messaging_retry', __( 'The message could not be processed. Please retry.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
    }
}
