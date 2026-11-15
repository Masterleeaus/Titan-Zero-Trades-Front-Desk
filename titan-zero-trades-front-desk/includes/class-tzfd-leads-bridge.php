<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Titan Zero Leads -> Front Desk qualified enquiry intake.
 * Runs only after Leads resolves company scope on the server. Never reads
 * submitted tenant IDs or creates a competing CRM/lead record.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Leads_Bridge {
    /** A claim written as 'pending:<unix time>' older than this is stale (its request died before finishing). */
    const CLAIM_PREFIX       = 'pending:';
    const STALE_CLAIM_SECONDS = 900;
    /** A second enquiry from the same email in the same company inside this window is a duplicate. */
    const DEDUPE_WINDOW_SECONDS = 86400;

    public static function register() {
        add_action( 'titan_zero_leads_workspace_resolved', array( __CLASS__, 'receive' ), 20, 2 );
    }

    /**
     * Takes the submission claim. A fresh claim held by another request returns false. A stale claim
     * (left by a fatal between the claim and the insert) is taken over with a compare-and-swap on the
     * exact stale value, so only one retry wins.
     */
    private static function claim( $key ) {
        if ( add_option( $key, self::CLAIM_PREFIX . time(), '', false ) ) { return true; }
        $current = get_option( $key, false );
        if ( ! is_string( $current ) || 0 !== strpos( $current, self::CLAIM_PREFIX ) ) { return false; }
        $claimed_at = (int) substr( $current, strlen( self::CLAIM_PREFIX ) );
        if ( $claimed_at < 1 || time() - $claimed_at < self::STALE_CLAIM_SECONDS ) { return false; }
        global $wpdb;
        $swapped = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
            $wpdb->options, self::CLAIM_PREFIX . time(), $key, $current
        ) );
        if ( 1 !== (int) $swapped ) { return false; }
        wp_cache_delete( $key, 'options' );
        return true;
    }

    /** Returns the id of a recent enquiry from this email in this company, or 0. Fails open on a read error. */
    private static function recent_duplicate( $company_id, $email ) {
        global $wpdb;
        $table = TZFD_Tables::name( TZFD_Tables::ENQUIRIES );
        $since = wp_date( 'Y-m-d H:i:s', time() - self::DEDUPE_WINDOW_SECONDS );
        $id    = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE company_id = %d AND LOWER(email) = LOWER(%s) AND created >= %s ORDER BY id DESC LIMIT 1',
            $table, $company_id, $email, $since
        ) );
        return absint( $id );
    }

    public static function receive( $event, $operator_id ) {
        if ( ! is_array( $event ) || ! class_exists( 'TZFD_Lead_Intake' ) ) { return; }
        $company_id = absint( $operator_id );
        if ( ! $company_id || $company_id !== absint( $event['operator_id'] ?? 0 )
            || 'resolved' !== ( $event['workspace_status'] ?? '' )
            || 'titan-leads' !== ( $event['source'] ?? '' )
            || 'lead.converted' !== ( $event['event_type'] ?? '' ) ) { return; }
        // Only a *trusted* form-to-company mapping and explicit integration policy
        // may approve intake. Admin-configured approval defaults to deny.
        if ( true !== apply_filters( 'tzfd_leads_allow_qualified_intake', false, $company_id, $event ) ) { return; }
        $qualification = $event['qualification'] ?? array();
        if ( ! is_array( $qualification ) || 'qualified' !== ( $qualification['status'] ?? '' ) ) { return; }
        $submission_id = isset( $event['submission_id'] ) && is_scalar( $event['submission_id'] ) ? (string) $event['submission_id'] : '';
        if ( '' === $submission_id || strlen( $submission_id ) > 190 ) { return; }
        $email = isset( $event['email'] ) && is_scalar( $event['email'] ) ? sanitize_email( (string) $event['email'] ) : '';
        if ( ! is_email( $email ) ) { return; }
        // An option claim is atomic on WordPress's options unique key. It must
        // remain after success, and be released after a failed insert. A claim
        // left 'pending' by a fatal is taken over once it is stale (see claim()).
        $key = 'tzfd_leads_' . hash( 'sha256', $company_id . "\0" . $submission_id );
        if ( ! self::claim( $key ) ) { return; }
        // Secondary dedupe on email within the company and window. The claim above keeps the submission_id check.
        // A duplicate is recorded against the existing enquiry so a retry never inserts it again.
        $existing = self::recent_duplicate( $company_id, $email );
        if ( $existing > 0 ) {
            update_option( $key, (string) $existing, false );
            return;
        }
        $payload = array(
            'company_id' => $company_id,
            'email' => $email,
            /* translators: 1: Qualified Leads group ID, 2: form ID. */
            'enquiry' => sprintf( __( 'Qualified Leads form conversion (group %1$d, form %2$d).', 'titan-zero-trades-front-desk' ), absint( $event['group_id'] ?? 0 ), absint( $event['form_id'] ?? 0 ) ),
            'source' => 'titan_leads',
            'campaign_id' => absint( $event['group_id'] ?? 0 ),
        );
        try {
            $id = TZFD_Lead_Intake::receive( $payload );
        } catch ( Throwable $e ) {
            delete_option( $key );
            do_action( 'tzfd_leads_intake_failed', $company_id, $submission_id );
            return;
        }
        if ( is_wp_error( $id ) || ! absint( $id ) ) {
            delete_option( $key );
            do_action( 'tzfd_leads_intake_failed', $company_id, $submission_id );
            return;
        }
        update_option( $key, (string) absint( $id ), false );
        do_action( 'tzfd_leads_enquiry_created', absint( $id ), $company_id, $submission_id );
    }
}
