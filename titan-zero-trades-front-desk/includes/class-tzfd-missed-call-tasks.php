<?php
/**
 * Callback tasks for missed calls (issue #30).
 *
 * Called by TZFD_Telephony_Adapter::receive() for every verified 'missed' event, after the call lifecycle has
 * recorded it. The company id comes only from the verified provider credentials, never from the request body.
 * The task is created locally, so it does not depend on any SMS or telephony service being online.
 *
 * Idempotency: one callback task per company, provider and call. A redelivered event finds the task and does
 * nothing. A failed write returns an error, so the provider retries the whole event.
 *
 * Privacy: the response to the provider says only whether a task was created. It never returns the matched
 * customer or the number. A phone match is a candidate, not authentication.
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-contact-rules.php';
require_once __DIR__ . '/class-tzfd-contact-actions.php';

final class TZFD_Missed_Call_Tasks {
    /**
     * Creates or finds the callback task for one missed call.
     * Returns array( 'ok' => true, 'task' => 'created'|'duplicate' ), or a WP_Error when the write fails.
     */
    public static function record( $company_id, $provider, $call_id, $from_raw ) {
        if ( ! TZFD_Contact_Rules::valid_identity( $company_id, $provider, $call_id ) ) {
            return new WP_Error( 'tzfd_missed_task_invalid', __( 'Invalid missed-call identity.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        // Wrong-tenant denial: a credential for another business never creates a task on this site.
        if ( ! class_exists( 'TZFD_Scoped_Store' ) || (int) TZFD_Scoped_Store::company_id() !== (int) $company_id ) {
            return new WP_Error( 'tzfd_missed_task_tenant', __( 'This request is not for the active business.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        $e164 = TZFD_Contact_Rules::normalise_e164( $from_raw );
        // Owner-verified candidate rows only. The filter is the owner's responsibility; default is no candidates.
        $candidates = apply_filters( 'tzfd_missed_call_contact_candidates_v1', array(), $company_id, $e164 );
        $match      = TZFD_Contact_Rules::match_contacts( $company_id, $e164, is_array( $candidates ) ? $candidates : array() );

        $result = TZFD_Contact_Actions::claim_action(
            $company_id,
            TZFD_Contact_Rules::dedupe_key( $company_id, 'missed_call', $provider . '|' . $call_id ),
            'callback',
            'missed_call',
            $e164,
            $match['status'],
            $match['customer_id'],
            'missed_call',
            '' === $e164 ? 'no_callback_number' : 'open'
        );
        if ( 'error' === $result ) {
            return new WP_Error( 'tzfd_missed_task_unavailable', __( 'The callback task could not be saved. Please retry.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        return array( 'ok' => true, 'task' => $result );
    }
}
