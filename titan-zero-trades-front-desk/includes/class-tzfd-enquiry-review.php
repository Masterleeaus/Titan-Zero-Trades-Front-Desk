<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-request-value.php';
require_once __DIR__ . '/class-tzfd-transaction-storage.php';
require_once __DIR__ . '/class-tzfd-local-audit.php';

/**
 * Enquiry review: a minimal list and status view for tz_enquiries (Business Builder leads).
 *
 * Front Desk owns tz_enquiries. Every read and write is company-scoped (TZFD_Scoped_Store::company_id()). A status change is one
 * UPDATE with company_id in the WHERE, and its Front Desk audit record (TZFD_Local_Audit::insert) commits in the same transaction.
 * Statuses: new (set on intake), reviewed, closed. Turning an enquiry into a booking is not done here.
 */
final class TZFD_Enquiry_Review {

    const ACTION      = 'tz_fd_enquiry_status';
    const NONCE       = 'tz_fd_enquiry_status';
    const NONCE_FIELD = 'tz_fd_enquiry_nonce';
    const PAGE_LIMIT  = 100;
    const STATUSES    = array( 'new', 'reviewed', 'closed' );

    public static function register() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_status' ) );
    }

    /** Enquiries for one company, newest first. Optional status filter. Empty on failure (fail closed). */
    public static function list_for_company( $company_id, $status = '', $limit = self::PAGE_LIMIT ) {
        global $wpdb;
        $company_id = TZFD_Request_Value::id( $company_id );
        if ( ! $company_id ) { return array(); }
        $limit = max( 1, min( self::PAGE_LIMIT, (int) $limit ) );
        $table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
        if ( '' !== $status && in_array( $status, self::STATUSES, true ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM %i WHERE company_id = %d AND status = %s ORDER BY id DESC LIMIT %d', $table, $company_id, $status, $limit
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM %i WHERE company_id = %d ORDER BY id DESC LIMIT %d', $table, $company_id, $limit
            ) );
        }
        return ( is_array( $rows ) && empty( $wpdb->last_error ) ) ? $rows : array();
    }

    /**
     * Sets the status of one enquiry in a company. Returns true, or WP_Error with data['status'] 400, 403, 404, 500, 503.
     */
    public static function set_status( $enquiry_id, $status, $company_id ) {
        global $wpdb;
        $enquiry_id = TZFD_Request_Value::id( $enquiry_id );
        $company_id = TZFD_Request_Value::id( $company_id );
        if ( ! $enquiry_id || ! in_array( $status, self::STATUSES, true ) ) {
            return new WP_Error( 'tzfd_bad_input', __( 'Choose a valid enquiry and status.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        if ( ! $company_id ) {
            return new WP_Error( 'tzfd_no_company', __( 'No company is selected.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        if ( ! class_exists( 'TZFD_Local_Audit' ) || ! method_exists( 'TZFD_Local_Audit', 'insert' ) ) {
            return new WP_Error( 'tzfd_unavailable', __( 'The audit log is not available.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        $table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
        // Front Desk owns its local audit journal, Front Desk owns tz_enquiries. Both writes must roll back together.
        if ( ! TZFD_Transaction_Storage::require_innodb( array( $table, TZFD_Local_Audit::table_name() ) ) ) {
            return new WP_Error( 'tzfd_transaction_storage_required', __( 'Enquiry and audit tables must use InnoDB.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        try {
            $started = $wpdb->query( 'START TRANSACTION' );
        } catch ( Throwable $error ) {
            $started = false;
        }
        if ( false === $started ) {
            return new WP_Error( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        try {
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM %i WHERE id = %d AND company_id = %d FOR UPDATE', $table, $enquiry_id, $company_id ) );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( ! empty( $wpdb->last_error ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) ); }
        if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_not_found', __( 'Enquiry not found.', 'titan-zero-trades-front-desk' ), array( 'status' => 404 ) ); }
        if ( $row->status === $status ) { $wpdb->query( 'ROLLBACK' ); return true; }
        try {
            $update = $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $enquiry_id, 'company_id' => $company_id ), array( '%s' ), array( '%d', '%d' ) );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( false === $update ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) ); }
        $actor = (int) get_current_user_id();
        try {
            $audit = TZFD_Local_Audit::insert( $company_id, $actor, $actor ? 'user' : 'system', array(
                'plugin' => 'titan-zero-front-desk', 'action' => 'enquiry.status', 'entity' => 'enquiry', 'entity_id' => $enquiry_id,
                'summary' => __( 'Enquiry status set to ', 'titan-zero-trades-front-desk' ) . $status,
            ) );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_audit', __( 'The enquiry change could not be audited and was rolled back.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( true !== $audit ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_audit', __( 'The change could not be recorded, so it was not saved.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) ); }
        // COMMIT acknowledgement failure leaves the outcome unknown; an attempted
        // ROLLBACK cannot safely certify that the status and audit were not saved.
        try {
            $commit_result = $wpdb->query( 'COMMIT' );
        } catch ( Throwable $error ) {
            $commit_result = false;
        }
        if ( false === $commit_result ) {
            return new WP_Error( 'tzfd_commit_outcome_unknown', __( 'Enquiry update outcome is uncertain. Refresh the enquiry before retrying.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        return true;
    }

    /** Admin-post: changes one enquiry's status. Capability, nonce and company are checked. */
    public static function handle_status() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::NONCE, self::NONCE_FIELD );
        $id     = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_POST['enquiry'] ?? '' ) ) );
        $status = TZFD_Request_Value::key( sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) ) );
        $result = self::set_status( $id, $status, (int) TZFD_Scoped_Store::company_id() );
        $error_data = is_wp_error( $result ) ? $result->get_error_data() : null;
        $error_status = is_array( $error_data ) && isset( $error_data['status'] ) ? absint( $error_data['status'] ) : 500;
        $args = is_wp_error( $result ) ? array( 'enquiry_error' => $error_status ) : array( 'enquiry_saved' => 1 );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ENQUIRIES ) ) );
        exit;
    }

    /** Status badge image for an enquiry status. Only 'new' has a badge; other statuses return an empty string. */
    private static function status_badge_html( $status ) {
        if ( 'new' !== $status ) { return ''; }
        $url = plugins_url( 'images/brand/ui-kit/status-badges/new.svg', dirname( __DIR__ ) . '/titan-zero-front-desk.php' );
        return '<img class="tzfd-status-badge" src="' . esc_url( $url ) . '" alt="" width="18" height="18" loading="lazy" decoding="async" />';
    }

    /** Admin page: enquiry list with a status filter and a status change per row. */
    public static function page() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter and notices.
        $filter = TZFD_Request_Value::key( sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ) );
        $saved  = isset( $_GET['enquiry_saved'] );
        $error  = isset( $_GET['enquiry_error'] );
        $triage_saved = isset( $_GET['triage_saved'] );
        $triage_error = isset( $_GET['triage_error'] );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $company = (int) TZFD_Scoped_Store::company_id();
        echo '<div class="wrap tzfd-admin-page"><h1>' . wp_kses( TZFD_UI_Graphics::brand_html( 'inbox' ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Enquiries', 'titan-zero-trades-front-desk' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Front Desk receives enquiries from your website directly. Review each request, follow up with the customer and update its status here. Create appointments from Bookings.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<div class="tzfd-enquiry-publish"><h2>' . esc_html__( 'Add an enquiry form to your website', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p>' . esc_html__( 'Create or edit a WordPress page and insert this shortcode. Enquiries are saved directly to Front Desk.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<code>[tzfd_enquiry_form]</code> <a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=page' ) ) . '">' . esc_html__( 'Add enquiry form to a page', 'titan-zero-trades-front-desk' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=page' ) ) . '">' . esc_html__( 'Manage pages', 'titan-zero-trades-front-desk' ) . '</a></div>';
        if ( $saved ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Enquiry status saved.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( $error ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The enquiry status could not be saved. Reload the page and try again.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( $triage_saved ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Triage change saved.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( $triage_error ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The triage change could not be saved. Reload the page and try again.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( ! $company ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No company is selected, so no enquiries can be shown.', 'titan-zero-trades-front-desk' ) . '</p></div></div>';
            return;
        }
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( TZFD_Admin_Menu::ENQUIRIES ) . '" />';
        echo '<label for="tz_fd_enquiry_filter">' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</label> <select id="tz_fd_enquiry_filter" name="status"><option value="">' . esc_html__( 'All', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( self::STATUSES as $s ) { echo '<option value="' . esc_attr( $s ) . '"' . selected( $filter, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>'; }
        echo '</select> <button class="button" type="submit">' . esc_html__( 'Filter', 'titan-zero-trades-front-desk' ) . '</button></form>';
        $rows = self::list_for_company( $company, $filter );
        $triage_map = class_exists( 'TZFD_Enquiry_Triage' ) ? TZFD_Enquiry_Triage::map_for_company( $company, array_map( static function ( $r ) { return (int) $r->id; }, $rows ) ) : array();
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Received', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Contact', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Enquiry', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Source', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Change', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
        if ( empty( $rows ) ) { echo '<tr><td colspan="7">' . esc_html__( 'No enquiries.', 'titan-zero-trades-front-desk' ) . '</td></tr>'; }
        foreach ( $rows as $r ) {
            $contact = trim( (string) $r->email . ' ' . (string) $r->phone );
            echo '<tr><td>' . esc_html( (string) $r->received_utc ) . ' UTC</td><td>' . esc_html( (string) $r->name ) . '</td><td>' . esc_html( $contact ) . '</td>';
            $triage_row = $triage_map[ (int) $r->id ] ?? null;
            $triage_html = class_exists( 'TZFD_Enquiry_Triage' ) ? TZFD_Enquiry_Triage::row_html( (int) $r->id, $triage_row ) : '';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- row_html() escapes every stored value it prints with esc_html() and returns trusted markup.
            echo '<td>' . esc_html( (string) $r->enquiry ) . $triage_html . '</td><td>' . esc_html( (string) $r->source ) . '</td><td>' . wp_kses( self::status_badge_html( (string) $r->status ), TZFD_UI_Graphics::allowed_html() ) . esc_html( ucfirst( (string) $r->status ) ) . '</td><td>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="enquiry" value="' . (int) $r->id . '" />';
            wp_nonce_field( self::NONCE, self::NONCE_FIELD );
            echo '<select name="status" aria-label="' . esc_attr__( 'New status', 'titan-zero-trades-front-desk' ) . '">';
            foreach ( self::STATUSES as $s ) { echo '<option value="' . esc_attr( $s ) . '"' . selected( (string) $r->status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>'; }
            echo '</select> <button class="button" type="submit">' . esc_html__( 'Save', 'titan-zero-trades-front-desk' ) . '</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
