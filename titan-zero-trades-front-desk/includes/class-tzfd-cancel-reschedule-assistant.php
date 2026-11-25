<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Pure decision helper: performs no queries and no writes.

if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly
require_once __DIR__ . '/class-tzfd-customer-cancellation.php';

/**
 * Free, model-free cancel/reschedule assistant decisions.
 *
 * It classifies a customer message, explains policy, and decides where the
 * customer is sent. It never changes a booking: every change still goes through
 * the existing signed-link flows (TZFD_Customer_Cancellation and
 * TZFD_Customer_Rescheduling), which keep their own receipts and capacity locks.
 *
 * A caller's phone number is not an input here, so it can never authorise a change.
 */
if ( ! class_exists( 'TZFD_Cancel_Reschedule_Assistant' ) ) {
    final class TZFD_Cancel_Reschedule_Assistant {
        const INTENT_CANCEL     = 'cancel';
        const INTENT_RESCHEDULE = 'reschedule';
        const INTENT_UNCERTAIN  = 'uncertain';
        const INTENT_NONE       = 'none';

        const ACTION_LINK_CANCEL     = 'send_cancel_link';
        const ACTION_LINK_RESCHEDULE = 'send_reschedule_link';
        const ACTION_VERIFY          = 'request_verified_link';
        const ACTION_HANDOFF         = 'handoff_to_staff';
        const ACTION_REFUSE          = 'refuse';

        const MAX_MESSAGE_LENGTH = 1000;

        /** Classify a customer message into cancel, reschedule, uncertain or none. */
        public static function classify( $text ) {
            if ( ! is_string( $text ) || '' === trim( $text ) ) {
                return self::INTENT_NONE;
            }
            if ( strlen( $text ) > self::MAX_MESSAGE_LENGTH ) {
                return self::INTENT_UNCERTAIN;
            }
            $normalized = strtolower( preg_replace( '/\s+/', ' ', trim( $text ) ) );
            $cancel     = (bool) preg_match( '/\b(cancel|cancellation|call it off|no longer need)\b/', $normalized );
            $reschedule = (bool) preg_match(
                '/\b(reschedul\w*|rebook\w*|postpone|push (it |my |the )?back|move (it|my|the) (booking|appointment|time|day|visit)|(another|different|new) (time|day|slot))\b/',
                $normalized
            );
            if ( $cancel && $reschedule ) {
                return self::INTENT_UNCERTAIN; // e.g. "cancel and rebook": needs a person.
            }
            if ( $cancel ) {
                return self::INTENT_CANCEL;
            }
            if ( $reschedule ) {
                return self::INTENT_RESCHEDULE;
            }
            return self::INTENT_NONE;
        }

        /**
         * True only when the caller presented a signed link that verifies for this booking,
         * this company and the current booking version. $ctx keys: booking_id, email, token,
         * secret, version, expires, now. Any other key (including a phone number) is ignored.
         */
        public static function ownership_verified( array $ctx ) {
            return TZFD_Customer_Cancellation::verify_token(
                isset( $ctx['booking_id'] ) ? $ctx['booking_id'] : 0,
                isset( $ctx['email'] ) ? $ctx['email'] : '',
                isset( $ctx['token'] ) ? $ctx['token'] : '',
                isset( $ctx['secret'] ) ? $ctx['secret'] : '',
                isset( $ctx['version'] ) ? $ctx['version'] : '',
                isset( $ctx['expires'] ) ? $ctx['expires'] : 0,
                isset( $ctx['now'] ) ? $ctx['now'] : null
            );
        }

        /**
         * Decide the next step. Never returns a change action.
         *
         * $facts: booking_found (bool), booking_company_id (int), request_company_id (int),
         * ownership_verified (bool, from ownership_verified()).
         */
        public static function decide( $intent, array $facts ) {
            if ( empty( $facts['booking_found'] ) ) {
                return self::ACTION_REFUSE; // Same answer for missing and foreign bookings.
            }
            $booking_company = isset( $facts['booking_company_id'] ) ? $facts['booking_company_id'] : 0;
            $request_company = isset( $facts['request_company_id'] ) ? $facts['request_company_id'] : 0;
            if ( ! is_int( $booking_company ) || ! is_int( $request_company ) || $booking_company < 1 ||
                 $booking_company !== $request_company ) {
                return self::ACTION_REFUSE; // Cross-tenant access.
            }
            if ( self::INTENT_CANCEL !== $intent && self::INTENT_RESCHEDULE !== $intent ) {
                return self::ACTION_HANDOFF; // Uncertain, none, or any unknown intent (e.g. a model's guess).
            }
            if ( empty( $facts['ownership_verified'] ) ) {
                return self::ACTION_VERIFY;
            }
            return self::INTENT_CANCEL === $intent ? self::ACTION_LINK_CANCEL : self::ACTION_LINK_RESCHEDULE;
        }

        /** Customer-facing copy for each action. Fixed strings only; nothing here is copied from the message. */
        const MESSAGES = array(
            self::ACTION_LINK_CANCEL     => 'You can cancel with the link we emailed you. Please use it to confirm.',
            self::ACTION_LINK_RESCHEDULE => 'You can change the time with the link we emailed you. Please use it to pick a new time.',
            self::ACTION_VERIFY          => 'To change this booking, please use the secure link in your booking email. We cannot change a booking from a phone number alone.',
            self::ACTION_HANDOFF         => 'We need a person to help with this one. Please contact our staff.',
            self::ACTION_REFUSE          =>'We could not find a booking matching these details. Please contact our staff.',
        );

        /**
         * Entry point for one customer message. Composes classify(), decide() and policy_summary(). It never changes a
         * booking and takes no phone number. Plain messages with no change request return not_applicable, so the
         * caller can continue with the normal enquiry flow.
         *
         * $facts as for decide(), plus ownership_verified, which the caller sets from ownership_verified().
         * $policy as for policy_summary().
         *
         * @return array Keys: intent, action, message, policy.
         */
        public static function plan( $text, array $facts, array $policy ) {
            $intent = self::classify( $text );
            $policy_text = self::policy_summary( $policy );
            if ( self::INTENT_NONE === $intent ) {
                return array( 'intent' => $intent, 'action' => 'not_applicable', 'message' => '', 'policy' => $policy_text );
            }
            $action = self::decide( $intent, $facts );
            return array(
                'intent'  => $intent,
                'action'  => $action,
                'message' => isset( self::MESSAGES[ $action ] ) ? self::MESSAGES[ $action ] : self::MESSAGES[ self::ACTION_HANDOFF ],
                'policy'  => $policy_text,
            );
        }

        /**
         * Runtime entry point for a message with no booking context (the public assistant route). It can only
         * classify, explain policy and point to the signed link. It never looks up a booking, never returns a
         * change action, and a clear cancel or reschedule message always gets the verify step.
         *
         * @return array Keys: intent, action, message, policy.
         */
        public static function plan_without_booking( $text, array $policy ) {
            $intent      = self::classify( $text );
            $policy_text = self::policy_summary( $policy );
            if ( self::INTENT_NONE === $intent ) {
                return array( 'intent' => $intent, 'action' => 'not_applicable', 'message' => '', 'policy' => $policy_text );
            }
            $action = self::INTENT_UNCERTAIN === $intent ? self::ACTION_HANDOFF : self::ACTION_VERIFY;
            return array(
                'intent'  => $intent,
                'action'  => $action,
                'message' => self::MESSAGES[ $action ],
                'policy'  => $policy_text,
            );
        }

        /**
         * Plain-language policy text from fixed, validated numbers only.
         * $policy keys: cancel_notice_hours, reschedule_notice_hours (non-negative ints, max 8760).
         */
        public static function policy_summary( array $policy ) {
            $cancel     = self::hours( $policy, 'cancel_notice_hours' );
            $reschedule = self::hours( $policy, 'reschedule_notice_hours' );
            if ( null === $cancel || null === $reschedule ) {
                return 'Please contact us to confirm our cancellation and change policy.';
            }
            return sprintf(
                'Changes need at least %d hours notice, and cancellations need at least %d hours notice. '
                . 'You can make either change yourself with the link we emailed you. Staff can help with anything else.',
                $reschedule,
                $cancel
            );
        }

        private static function hours( array $policy, $key ) {
            if ( ! isset( $policy[ $key ] ) || ! is_int( $policy[ $key ] ) || $policy[ $key ] < 0 || $policy[ $key ] > 8760 ) {
                return null;
            }
            return $policy[ $key ];
        }
    }
}
