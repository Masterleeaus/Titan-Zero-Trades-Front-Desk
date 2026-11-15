<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Front Desk's rule-based automation handlers (19 Oct).
 * Quote, invoice and payment intents moved to Titan Zero Quotes and Titan Zero Invoicing (19 Oct split).
 * Front Desk registers only the enquiry-to-booking intent.
 *
 * Each handler is deterministic: it checks the input with plain rules, makes one write through Front Desk's own
 * code, and returns an array. No handler calls an AI model. Fallback is 'none' for every intent, so a request the
 * rule cannot finish is refused and logged, and a person takes it.
 *
 * Each intent has an id, plugin, trigger, required fields, handler, role and fallback.
 *   - Handler: function ( array $context ): array|WP_Error. $context holds company_id, wp_user_id, level,
 *     trigger, intent_id and input. A WP_Error with code tz_not_handled means the rule cannot finish.
 *     Any other WP_Error is final.
 *   - role is a WordPress minimum: worker, manager or administrator.
 *
 * The pure check (enquiry_gap) holds the rule and needs no WordPress, so the tests call it directly.
 */
final class TZFD_Automation {

    const PLUGIN = 'titan-zero-front-desk';

    /** The intent Front Desk defines. No external router is loaded, so nothing registers it. */
    public static function intents() {
        return array(
            array(
                'id'       => 'frontdesk.booking_from_enquiry',
                'plugin'   => self::PLUGIN,
                'trigger'  => 'fd.enquiry.ready',
                'required' => array( 'formid', 'slot', 'name', 'enquiry' ),
                'handler'  => array( __CLASS__, 'booking_from_enquiry' ),
                'role'     => 'worker',
                'fallback' => 'none',
            ),
        );
    }

    /* ---------------------------------------------------------------
     * Pure rules (no WordPress calls, no writes)
     * ------------------------------------------------------------- */

    /**
     * An enquiry is complete when it has a form, a slot (Y-m-d H:i), a name, the enquiry text, and a valid
     * email or a phone number. Returns the first missing or invalid field name, or '' when complete.
     *
     * @param array $p Input.
     * @return string
     */
    public static function enquiry_gap( array $p ) {
        if ( empty( $p['formid'] ) || (int) $p['formid'] < 1 ) { return 'formid'; }
        $slot = isset( $p['slot'] ) ? trim( (string) $p['slot'] ) : '';
        if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $slot ) ) { return 'slot'; }
        if ( '' === trim( (string) ( $p['name'] ?? '' ) ) ) { return 'name'; }
        if ( '' === trim( (string) ( $p['enquiry'] ?? '' ) ) ) { return 'enquiry'; }
        // A booking without a service is escalated by the pipeline (no_service), so refuse it here instead.
        if ( '' === trim( (string) ( $p['service_type'] ?? '' ) ) ) { return 'service'; }
        $email = isset( $p['email'] ) ? trim( (string) $p['email'] ) : '';
        $phone = isset( $p['phone'] ) ? trim( (string) $p['phone'] ) : '';
        if ( '' !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) { return 'contact'; }
        if ( '' === $email && '' === $phone ) { return 'contact'; }
        return '';
    }

    /* ---------------------------------------------------------------
     * Handlers. Each receives the router's $context and returns an array or a WP_Error.
     * ------------------------------------------------------------- */

    /** Creates a booking from a complete enquiry through Front Desk's intake pipeline. */
    public static function booking_from_enquiry( $context ) {
        $p = self::input( $context );
        $gap = self::enquiry_gap( $p );
        if ( '' !== $gap ) {
            return new WP_Error( 'tz_not_handled', __( 'The enquiry is not complete.', 'titan-zero-trades-front-desk' ), array( 'status' => 422, 'field' => $gap ) );
        }
        // Refuse unless the router's company is set and owns the calendar. A mismatch is final, not a fallback.
        $company = self::company( $context );
        if ( $company < 1 || ! class_exists( 'TZFD_Scoped_Store' ) ||
             TZFD_Scoped_Store::company_for_form( (int) $p['formid'] ) !== $company ) {
            return new WP_Error( 'tz_forbidden', __( 'The enquiry does not belong to this company.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        if ( ! class_exists( 'TZFD_Pipeline' ) ) { return new WP_Error( 'tz_unavailable', __( 'Front Desk bookings are not available.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) ); }
        $draft = array(
            'slot'        => trim( (string) $p['slot'] ),
            'name'        => (string) $p['name'],
            'email'       => isset( $p['email'] ) ? (string) $p['email'] : '',
            'phone'       => isset( $p['phone'] ) ? (string) $p['phone'] : '',
            'description' => (string) $p['enquiry'],
            'urgency'     => isset( $p['urgency'] ) ? (string) $p['urgency'] : 'routine',
            'service_type' => (string) $p['service_type'],
        );
        $result = TZFD_Pipeline::submit( (int) $p['formid'], $draft, 'automation-rule' );
        if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
            $code = is_array( $result ) && isset( $result['error'] ) ? (string) $result['error'] : 'save_failed';
            return new WP_Error( 'tz_booking_failed', __( 'The booking could not be created.', 'titan-zero-trades-front-desk' ), array( 'status' => 409, 'reason' => $code ) );
        }
        return array( 'booking_id' => (int) ( $result['booking_id'] ?? 0 ), 'status' => (string) ( $result['status'] ?? '' ) );
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /** The router's input array, or an empty array. */
    private static function input( $context ) {
        return is_array( $context ) && isset( $context['input'] ) && is_array( $context['input'] ) ? $context['input'] : array();
    }

    /** Company id from the router's context, or 0 when absent. Handlers refuse when 0. */
    private static function company( $context ) {
        return is_array( $context ) ? absint( $context['company_id'] ?? 0 ) : 0;
    }
}
