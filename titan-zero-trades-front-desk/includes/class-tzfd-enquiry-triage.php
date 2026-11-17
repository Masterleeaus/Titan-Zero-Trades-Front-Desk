<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-enquiry-triage-rules.php';
require_once __DIR__ . '/class-tzfd-request-value.php';
require_once __DIR__ . '/class-tzfd-transaction-storage.php';
require_once __DIR__ . '/class-tzfd-local-audit.php';

/**
 * Stores and shows the deterministic triage result for each enquiry (tz_enquiry_triage).
 *
 * The enquiry row itself is never changed or deleted by triage. A spam hold is a review flag: the enquiry keeps its
 * status and stays in the Enquiries list. Admins restore or confirm it there. Every write is company scoped, and the
 * admin changes are audited in the same transaction. Failures to triage never block an enquiry from being saved.
 */
final class TZFD_Enquiry_Triage {

    const ACTION      = 'tz_fd_enquiry_triage';
    const NONCE       = 'tz_fd_enquiry_triage';
    const NONCE_FIELD = 'tz_fd_enquiry_triage_nonce';
    const SCHEMA_OPTION = 'tzfd_enquiry_triage_schema';
    const SCHEMA_VERSION = '1';
    const OPS         = array( 'restore_spam', 'confirm_spam', 'override_urgent', 'override_normal', 'clear_override' );

    const SETTING_ACTION      = 'tz_fd_urgent_alert_setting';
    const SETTING_NONCE       = 'tz_fd_urgent_alert_setting';
    const SETTING_NONCE_FIELD = 'tz_fd_urgent_alert_setting_nonce';

    public static function register() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
        add_action( 'admin_post_' . self::SETTING_ACTION, array( __CLASS__, 'handle_alert_setting' ) );
    }

    /** Admin-post: saves the urgent-alert opt-in. Capability, nonce and POST are all checked. Off unless ticked. */
    public static function handle_alert_setting() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::SETTING_NONCE, self::SETTING_NONCE_FIELD );
        $value = TZFD_Enquiry_Triage_Rules::alert_setting_from_request( wp_unslash( $_POST['urgent_alert'] ?? '0' ) );
        update_option( self::ALERT_OPTION, $value, false );
        wp_safe_redirect( add_query_arg( array( 'alert_setting_saved' => 1 ), admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ENQUIRIES ) ) );
        exit;
    }

    /** Checkbox form for the urgent-alert opt-in. Escapes all output. */
    public static function alert_setting_html() {
        $on = '1' === (string) get_option( self::ALERT_OPTION, '0' );
        return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tzfd-urgent-alert-setting">'
            . '<input type="hidden" name="action" value="' . esc_attr( self::SETTING_ACTION ) . '" />'
            . wp_nonce_field( self::SETTING_NONCE, self::SETTING_NONCE_FIELD, true, false )
            . '<input type="hidden" name="urgent_alert" value="0" />'
            . '<label><input type="checkbox" name="urgent_alert" value="1"' . checked( $on, true, false ) . ' /> '
            . esc_html__( 'Email the site admin when an enquiry is marked urgent by rule (at most one email every 15 minutes). The email gives the enquiry id and reasons only.', 'titan-zero-trades-front-desk' )
            . '</label> <button class="button" type="submit">' . esc_html__( 'Save alert setting', 'titan-zero-trades-front-desk' ) . '</button></form>';
    }

    private static function table() {
        return TZFD_Tables::name( TZFD_Tables::ENQUIRY_TRIAGE );
    }

    private static function enquiries_table() {
        return TZFD_Tables::name( TZFD_Tables::ENQUIRIES );
    }

    /** Creates tz_enquiry_triage once per site. Idempotent, so it also covers installs that predate this version. */
    public static function ensure_table() {
        if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_OPTION ) ) { return true; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table   = self::table();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            enquiry_id bigint(20) unsigned NOT NULL DEFAULT 0,
            primary_intent varchar(20) NOT NULL DEFAULT 'general',
            intents varchar(190) NOT NULL DEFAULT '',
            urgency varchar(10) NOT NULL DEFAULT 'normal',
            urgency_reasons varchar(190) NOT NULL DEFAULT '',
            urgency_override varchar(10) NOT NULL DEFAULT '',
            spam_state varchar(12) NOT NULL DEFAULT 'none',
            spam_score smallint(5) unsigned NOT NULL DEFAULT 0,
            spam_signals varchar(190) NOT NULL DEFAULT '',
            duplicate_of varchar(20) NOT NULL DEFAULT '',
            signature char(64) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            route varchar(30) NOT NULL DEFAULT '',
            quote_draft text NULL,
            state_by bigint(20) unsigned NOT NULL DEFAULT 0,
            state_at datetime NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY company_enquiry (company_id, enquiry_id),
            KEY company_signature (company_id, signature),
            KEY company_ip (company_id, ip_hash)
        ) {$charset};" );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
        return true;
    }

    /**
     * Triage one newly stored enquiry. Never throws. Returns the triage array, or false when triage was not stored.
     * The enquiry row is already saved, so a failure here only leaves the enquiry untriaged.
     */
    public static function record_safely( $company_id, $enquiry_id, $text, $email, $ip_hash ) {
        try {
            return self::record( $company_id, $enquiry_id, $text, $email, $ip_hash );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    public static function record( $company_id, $enquiry_id, $text, $email, $ip_hash ) {
        global $wpdb;
        $company_id = TZFD_Request_Value::id( $company_id );
        $enquiry_id = TZFD_Request_Value::id( $enquiry_id );
        if ( ! $company_id || ! $enquiry_id ) { return false; }
        self::ensure_table();
        $now = time();
        $ip_hash = preg_match( '/^[a-f0-9]{64}$/D', (string) $ip_hash ) ? (string) $ip_hash : '';

        // Priors: this company's enquiries in the last 24 hours, excluding this one. Tenant scope is in the WHERE clause.
        $since = wp_date( 'Y-m-d H:i:s', $now - TZFD_Enquiry_Triage_Rules::DUPLICATE_WINDOW );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            'SELECT e.id, e.email, e.enquiry, e.created, t.ip_hash FROM %i e LEFT JOIN %i t ON t.enquiry_id = e.id AND t.company_id = e.company_id WHERE e.company_id = %d AND e.id <> %d AND e.created >= %s ORDER BY e.id DESC LIMIT %d',
            self::enquiries_table(), self::table(), $company_id, $enquiry_id, $since, TZFD_Enquiry_Triage_Rules::MAX_PRIORS
        ) );
        $tz     = wp_timezone();
        $priors = array();
        foreach ( (array) $rows as $r ) {
            $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $r->created, $tz );
            $priors[] = array(
                'signature' => TZFD_Enquiry_Triage_Rules::signature( (string) $r->enquiry ),
                'tokens'    => TZFD_Enquiry_Triage_Rules::tokens( (string) $r->enquiry ),
                'email'     => (string) $r->email,
                'ip_hash'   => (string) $r->ip_hash,
                'ts'        => $dt ? $dt->getTimestamp() : 0,
            );
        }

        $result = TZFD_Enquiry_Triage_Rules::evaluate( (string) $text, (string) $email, $ip_hash, $now, $priors, null, (int) wp_date( 'Y', $now ) );
        $draft  = $result['quote_draft'];
        $spam   = $result['spam'];

        $ok = $wpdb->insert( self::table(), array(
            'company_id'       => $company_id,
            'enquiry_id'       => $enquiry_id,
            'primary_intent'   => $result['primary_intent'],
            'intents'          => substr( implode( ',', $result['intents'] ), 0, 190 ),
            'urgency'          => $result['urgency'],
            'urgency_reasons'  => substr( implode( ',', $result['urgency_reasons'] ), 0, 190 ),
            'urgency_override' => '',
            'spam_state'       => $spam['hold'] ? 'held' : 'none',
            'spam_score'       => (int) $spam['score'],
            'spam_signals'     => substr( implode( ',', $spam['signals'] ), 0, 190 ),
            'duplicate_of'     => (string) $spam['duplicate_of'],
            'signature'        => (string) $result['signature'],
            'ip_hash'          => $ip_hash,
            'route'            => $result['route'],
            'quote_draft'      => null === $draft ? null : wp_json_encode( $draft ),
            'state_by'         => 0,
            'state_at'         => null,
            'created'          => current_time( 'mysql' ),
        ) );
        if ( ! $ok ) { return false; }

        // Audit trace for rule outcomes that change what an admin sees. The system is the actor; no text is written.
        if ( $spam['hold'] ) { self::audit( $company_id, $enquiry_id, 0, 'system', 'enquiry.spam_held', 'Held for spam review: ' . implode( ', ', $spam['signals'] ) ); }
        if ( 'urgent' === $result['urgency'] ) { self::audit( $company_id, $enquiry_id, 0, 'system', 'enquiry.urgent', 'Urgent by rule: ' . implode( ', ', $result['urgency_reasons'] ) ); }
        self::maybe_alert( $company_id, $enquiry_id, $result['urgency'], $result['urgency_reasons'], $now );
        return $result;
    }

    /** Option holding the administrator opt-in for urgent alert email. Off unless set to '1'. */
    const ALERT_OPTION = 'tz_fd_urgent_alert_enabled';

    /**
     * Sends one admin email for an urgent enquiry when the administrator has opted in and the window allows it.
     * The email names the enquiry id and reasons only. It never repeats the enquiry text or contact details.
     */
    private static function maybe_alert( $company_id, $enquiry_id, $urgency, array $reasons, $now ) {
        $option_key = 'tz_fd_urgent_alert_last_' . (int) $company_id;
        $last       = get_option( $option_key, null );
        $decision   = TZFD_Enquiry_Triage_Rules::alert_decision( '1' === (string) get_option( self::ALERT_OPTION, '0' ), $urgency, null === $last ? null : (int) $last, $now );
        if ( ! $decision['send'] ) { return false; }
        $to = sanitize_email( (string) get_option( 'admin_email', '' ) );
        if ( '' === $to || ! is_email( $to ) ) { return false; }
        $subject = __( 'Urgent enquiry received', 'titan-zero-trades-front-desk' );
        $body    = sprintf(
            /* translators: 1: enquiry id, 2: comma separated reasons. */
            __( "An enquiry (id %1\$d) was marked urgent by rule: %2\$s.\nOpen the Enquiries screen to review it.", 'titan-zero-trades-front-desk' ),
            (int) $enquiry_id,
            implode( ', ', $reasons )
        );
        $sent = wp_mail( $to, $subject, $body );
        if ( $sent ) { update_option( $option_key, (int) $now, false ); }
        return (bool) $sent;
    }

    /** Writes one audit row. Returns false when the audit could not be written. */
    private static function audit( $company_id, $enquiry_id, $actor, $actor_type, $action, $summary ) {
        if ( ! class_exists( 'TZFD_Local_Audit' ) || ! method_exists( 'TZFD_Local_Audit', 'insert' ) ) { return false; }
        try {
            return true === TZFD_Local_Audit::insert( (int) $company_id, (int) $actor, $actor_type, array(
                'plugin' => 'titan-zero-front-desk', 'action' => $action, 'entity' => 'enquiry', 'entity_id' => (int) $enquiry_id,
                'summary' => substr( $summary, 0, 250 ),
            ) );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /** Triage rows for the given enquiries, keyed by enquiry id. Company scoped; empty on failure. */
    public static function map_for_company( $company_id, array $enquiry_ids ) {
        global $wpdb;
        $company_id = TZFD_Request_Value::id( $company_id );
        $ids = array_values( array_filter( array_map( array( 'TZFD_Request_Value', 'id' ), $enquiry_ids ) ) );
        if ( ! $company_id || ! $ids ) { return array(); }
        self::ensure_table();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders holds only '%d' tokens, one per id, so the IN list stays prepared.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE company_id = %d AND enquiry_id IN ({$placeholders})",
            array_merge( array( self::table(), $company_id ), $ids )
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $map = array();
        foreach ( (array) $rows as $r ) { $map[ (int) $r->enquiry_id ] = $r; }
        return $map;
    }

    /** Human label for the primary intent. */
    public static function intent_label( $intent ) {
        $labels = array(
            'spam'            => __( 'Possible spam', 'titan-zero-trades-front-desk' ),
            'complaint'       => __( 'Complaint', 'titan-zero-trades-front-desk' ),
            'cancel'          => __( 'Cancellation', 'titan-zero-trades-front-desk' ),
            'quote'           => __( 'Quote request', 'titan-zero-trades-front-desk' ),
            'booking'         => __( 'Booking', 'titan-zero-trades-front-desk' ),
            'service_request' => __( 'Service request', 'titan-zero-trades-front-desk' ),
            'general'         => __( 'General', 'titan-zero-trades-front-desk' ),
        );
        return isset( $labels[ $intent ] ) ? $labels[ $intent ] : $labels['general'];
    }

    /**
     * Triage summary and admin actions for one enquiry row, for the existing Enquiries list.
     * Returns HTML that is already escaped. Empty string when the enquiry has no triage row.
     */
    public static function row_html( $enquiry_id, $triage ) {
        if ( ! $triage ) { return '<div class="tzfd-triage"><small>' . esc_html__( 'Not triaged', 'titan-zero-trades-front-desk' ) . '</small></div>'; }
        $override = (string) $triage->urgency_override;
        $urgent   = '' !== $override ? 'override_' . $override : (string) $triage->urgency;
        $effective = 'override_urgent' === $urgent ? 'urgent' : ( 'override_normal' === $urgent ? 'normal' : $urgent );
        $out = '<div class="tzfd-triage"><strong>' . esc_html( self::intent_label( (string) $triage->primary_intent ) ) . '</strong>';
        if ( 'urgent' === $effective ) {
            $reasons = array_filter( explode( ',', (string) $triage->urgency_reasons ) );
            $out .= ' <span class="tzfd-urgent">' . esc_html__( 'Urgent', 'titan-zero-trades-front-desk' ) . '</span>';
            if ( $reasons ) { $out .= ' <small>' . esc_html( implode( ', ', $reasons ) ) . '</small>'; }
        }
        if ( '' !== $override ) { $out .= ' <small>' . esc_html__( 'set by admin', 'titan-zero-trades-front-desk' ) . '</small>'; }
        if ( 'held' === $triage->spam_state ) {
            $out .= '<div class="notice notice-warning inline"><p>' . esc_html__( 'Held for spam review. Nothing is deleted. Restore it or confirm spam.', 'titan-zero-trades-front-desk' ) . ' <small>' . esc_html( (string) $triage->spam_signals ) . '</small></p></div>';
        } elseif ( 'restored' === $triage->spam_state ) {
            $out .= ' <small>' . esc_html__( 'Spam hold restored by admin', 'titan-zero-trades-front-desk' ) . '</small>';
        } elseif ( 'confirmed' === $triage->spam_state ) {
            $out .= ' <small>' . esc_html__( 'Confirmed as spam by admin', 'titan-zero-trades-front-desk' ) . '</small>';
        }
        if ( '' !== (string) $triage->duplicate_of ) { $out .= ' <small>' . esc_html__( 'Repeats an earlier message', 'titan-zero-trades-front-desk' ) . '</small>'; }
        $draft = null !== $triage->quote_draft ? json_decode( (string) $triage->quote_draft, true ) : null;
        if ( is_array( $draft ) ) {
            $missing = empty( $draft['missing'] ) ? '' : implode( ', ', array_map( 'sanitize_key', (array) $draft['missing'] ) );
            $out .= '<details><summary>' . esc_html__( 'Quote draft (no prices)', 'titan-zero-trades-front-desk' ) . '</summary><ul>';
            $out .= '<li>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . ': ' . esc_html( (string) ( $draft['service_type'] ?? '' ) ) . '</li>';
            $out .= '<li>' . esc_html__( 'Requested date', 'titan-zero-trades-front-desk' ) . ': ' . esc_html( (string) ( $draft['requested_date'] ?? '' ) ) . '</li>';
            $out .= '<li>' . esc_html__( 'Site', 'titan-zero-trades-front-desk' ) . ': ' . esc_html( (string) ( $draft['site'] ?? '' ) ) . '</li>';
            if ( in_array( 'contradictory_dates', (array) ( $draft['flags'] ?? array() ), true ) ) { $out .= '<li>' . esc_html__( 'Two different dates were named. Correct the date before quoting.', 'titan-zero-trades-front-desk' ) . '</li>'; }
            if ( '' !== $missing ) { $out .= '<li>' . esc_html__( 'Missing', 'titan-zero-trades-front-desk' ) . ': ' . esc_html( $missing ) . '</li>'; }
            $out .= '</ul></details>';
        }
        $out .= '</div>';
        $out .= self::action_forms_html( (int) $enquiry_id, $triage, $effective );
        return $out;
    }

    /** Admin forms for restore, confirm and urgency override. Each POST has its own nonce and enquiry id. */
    private static function action_forms_html( $enquiry_id, $triage, $effective ) {
        $buttons = array();
        if ( 'held' === $triage->spam_state ) {
            $buttons[] = array( 'restore_spam', __( 'Restore (not spam)', 'titan-zero-trades-front-desk' ) );
            $buttons[] = array( 'confirm_spam', __( 'Confirm spam', 'titan-zero-trades-front-desk' ) );
        } elseif ( 'confirmed' === $triage->spam_state ) {
            $buttons[] = array( 'restore_spam', __( 'Restore (not spam)', 'titan-zero-trades-front-desk' ) );
        }
        if ( 'urgent' === $effective ) { $buttons[] = array( 'override_normal', __( 'Mark not urgent', 'titan-zero-trades-front-desk' ) ); }
        else { $buttons[] = array( 'override_urgent', __( 'Mark urgent', 'titan-zero-trades-front-desk' ) ); }
        if ( '' !== (string) $triage->urgency_override ) { $buttons[] = array( 'clear_override', __( 'Use rule result', 'titan-zero-trades-front-desk' ) ); }
        $out = '';
        foreach ( $buttons as $b ) {
            $out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tzfd-triage-action" style="display:inline">';
            $out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
            $out .= '<input type="hidden" name="enquiry" value="' . (int) $enquiry_id . '" />';
            $out .= '<input type="hidden" name="op" value="' . esc_attr( $b[0] ) . '" />';
            wp_nonce_field( self::NONCE, self::NONCE_FIELD );
            $out .= '<button class="button" type="submit">' . esc_html( $b[1] ) . '</button></form> ';
        }
        return $out;
    }

    /** Admin-post: applies one review action. Capability, nonce, company and state are all checked. */
    public static function handle_action() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::NONCE, self::NONCE_FIELD );
        $id = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_POST['enquiry'] ?? '' ) ) );
        $op = TZFD_Request_Value::key( sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) ) );
        $result = self::apply( $id, $op, (int) TZFD_Scoped_Store::company_id(), (int) get_current_user_id() );
        $args = is_wp_error( $result ) ? array( 'triage_error' => 1 ) : array( 'triage_saved' => 1 );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ENQUIRIES ) ) );
        exit;
    }

    /**
     * Applies one review action in one transaction with its audit row. Returns true or WP_Error.
     */
    public static function apply( $enquiry_id, $op, $company_id, $actor ) {
        global $wpdb;
        $enquiry_id = TZFD_Request_Value::id( $enquiry_id );
        $company_id = TZFD_Request_Value::id( $company_id );
        if ( ! $enquiry_id || ! $company_id || ! in_array( $op, self::OPS, true ) ) {
            return new WP_Error( 'tzfd_bad_input', __( 'Choose a valid enquiry and action.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        self::ensure_table();
        $table = self::table();
        if ( ! TZFD_Transaction_Storage::require_innodb( array( $table, TZFD_Local_Audit::table_name() ) ) ) {
            return new WP_Error( 'tzfd_transaction_storage_required', __( 'Triage and audit tables must use InnoDB.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return new WP_Error( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND enquiry_id = %d FOR UPDATE', $table, $company_id, $enquiry_id ) );
        if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_not_found', __( 'Enquiry not found.', 'titan-zero-trades-front-desk' ), array( 'status' => 404 ) ); }

        $set = array();
        $summary = '';
        switch ( $op ) {
            case 'restore_spam':
                if ( ! in_array( (string) $row->spam_state, array( 'held', 'confirmed' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_state', __( 'This enquiry is not held for spam.', 'titan-zero-trades-front-desk' ), array( 'status' => 409 ) ); }
                $set = array( 'spam_state' => 'restored' );
                $summary = 'Spam hold restored by admin';
                break;
            case 'confirm_spam':
                if ( 'held' !== (string) $row->spam_state ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_state', __( 'Only a held enquiry can be confirmed as spam.', 'titan-zero-trades-front-desk' ), array( 'status' => 409 ) ); }
                $set = array( 'spam_state' => 'confirmed' );
                $summary = 'Confirmed as spam by admin. Not deleted';
                break;
            case 'override_urgent':
                $set = array( 'urgency_override' => 'urgent' );
                $summary = 'Urgency set to urgent by admin';
                break;
            case 'override_normal':
                $set = array( 'urgency_override' => 'normal' );
                $summary = 'Urgency set to not urgent by admin';
                break;
            case 'clear_override':
                $set = array( 'urgency_override' => '' );
                $summary = 'Urgency override cleared; rule result shown';
                break;
        }
        $set['state_by'] = $actor;
        $set['state_at'] = current_time( 'mysql' );
        try {
            $update = $wpdb->update( $table, $set, array( 'id' => (int) $row->id, 'company_id' => $company_id ) );
        } catch ( Throwable $e ) {
            $update = false;
        }
        if ( false === $update ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) ); }
        if ( ! self::audit( $company_id, $enquiry_id, $actor, $actor ? 'user' : 'system', 'enquiry.triage.' . $op, $summary ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_audit', __( 'The change could not be recorded, so it was not saved.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( false === $wpdb->query( 'COMMIT' ) ) {
            return new WP_Error( 'tzfd_commit_outcome_unknown', __( 'Enquiry update outcome is uncertain. Refresh the enquiry before retrying.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        return true;
    }
}
