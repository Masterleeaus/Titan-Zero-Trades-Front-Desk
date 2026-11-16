<?php
/**
 * Lead urgency detection for Front Desk enquiries (issue #29).
 *
 * Deterministic and rule-only: no AI, no scoring, no network. Each enquiry is checked against admin-configured phrase
 * lists for four categories (urgent wording, complaints, callback requests, expiring booking dates). A phrase that is
 * negated ("not urgent", "no rush") does not count. The result is a level (urgent or normal) with human-readable
 * reasons. Nothing is declined, closed or ranked by it; the admin can override the level per enquiry, and every
 * override and rule change is written to the Front Desk audit journal.
 *
 * classify(), normalise(), alert_allowed() and override_event() are pure: they call no WordPress function when rules are
 * passed in, so they can be tested offline (tests/unit/lead-urgency-fixtures.php).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-request-value.php';
require_once __DIR__ . '/class-tzfd-local-audit.php';

final class TZFD_Lead_Urgency {

    const OPTION_RULES    = 'tzfd_lead_urgency_rules';
    const OPTION_ALERTS   = 'tzfd_lead_urgency_alerts';
    const OPTION_LAST     = 'tzfd_lead_urgency_last_alert_';
    const OVERRIDE_ACTION = 'tzfd_lead_urgency_override';
    const SETTINGS_ACTION = 'tzfd_lead_urgency_settings';
    const NONCE           = 'tzfd_lead_urgency';
    const NONCE_FIELD     = 'tzfd_lead_urgency_nonce';
    const MAX_PHRASES     = 50;
    const MAX_PHRASE_LEN  = 60;
    const DEFAULT_THROTTLE_MIN = 15;
    const SCHEMA_OPTION   = 'tzfd_lead_urgency_schema_v1';
    /** Columns added to tz_enquiries. Classification is stored at intake; the override is set by an admin. */
    const COLUMNS = array(
        'urgency_auto'     => "varchar(10) NOT NULL DEFAULT ''",
        'urgency_reason'   => "varchar(190) NOT NULL DEFAULT ''",
        'urgency_override' => "varchar(10) NOT NULL DEFAULT ''",
    );

    const CATEGORIES = array( 'urgent_term', 'complaint', 'callback', 'expiring_date' );
    const LEVELS     = array( 'urgent', 'normal' );
    const NEGATORS   = array( 'not', 'no', 'never', 'dont', "don't", 'isnt', "isn't", 'wasnt', "wasn't", 'without', 'nothing', 'cant', "can't", 'nope', 'none' );
    const MONTHS     = array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 );

    public static function register() {
        self::ensure_schema();
        add_action( 'admin_post_' . self::OVERRIDE_ACTION, array( __CLASS__, 'handle_override' ) );
        add_action( 'admin_post_' . self::SETTINGS_ACTION, array( __CLASS__, 'handle_settings' ) );
    }

    /**
     * Adds the urgency columns to tz_enquiries on sites that were activated before this release. Runs once (option-gated);
     * like TZFD_Business_Schema::ensure_company_column(), it only alters a column that is missing.
     */
    public static function ensure_schema() {
        global $wpdb;
        if ( get_option( self::SCHEMA_OPTION ) ) { return true; }
        $table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
        foreach ( self::COLUMNS as $column => $definition ) {
            $found = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
            if ( ! empty( $found ) ) { continue; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed plugin table and column names from constants.
            if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN ' . $column . ' ' . $definition, $table ) ) ) { return false; }
        }
        update_option( self::SCHEMA_OPTION, 1, false );
        return true;
    }

    /** String value of one POST field. Arrays and objects read as empty, so they cannot reach a sanitiser. */
    private static function post_text( $key, $default = '' ) {
        $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
        return is_scalar( $value ) ? (string) $value : '';
    }

    /** Built-in phrase lists. Admins edit these in the enquiry triage page; the defaults are used until they do. */
    public static function default_rules() {
        return array(
            'urgent_term'        => array( 'enabled' => true, 'phrases' => array( 'urgent', 'urgently', 'asap', 'as soon as possible', 'emergency', 'right now', 'immediately', 'burst pipe', 'flooding', 'no power', 'no hot water', 'gas leak' ) ),
            'complaint'          => array( 'enabled' => true, 'phrases' => array( 'complaint', 'complain', 'unacceptable', 'refund', 'chargeback', 'legal action', 'lawyer', 'ombudsman' ) ),
            'callback'           => array( 'enabled' => true, 'phrases' => array( 'call me back', 'call back', 'callback', 'ring me', 'phone me', 'give me a call', 'please call' ) ),
            'expiring_date'      => array( 'enabled' => true, 'phrases' => array( 'expires', 'expiring', 'deadline', 'offer ends', 'hold expires', 'last day' ) ),
            'expiry_window_days' => 2,
        );
    }

    /** Stored rules merged over the defaults and sanitised. A missing or broken option falls back to the defaults. */
    public static function rules() {
        $raw = function_exists( 'get_option' ) ? get_option( self::OPTION_RULES, array() ) : array();
        return self::normalise_rules( is_array( $raw ) ? $raw : array() );
    }

    /** Sanitises rule input (admin form or stored option). Unknown keys are dropped; phrases are cleaned and capped. */
    public static function normalise_rules( array $raw ) {
        $defaults = self::default_rules();
        $out = array();
        foreach ( self::CATEGORIES as $cat ) {
            $in = isset( $raw[ $cat ] ) && is_array( $raw[ $cat ] ) ? $raw[ $cat ] : array();
            $phrases = isset( $in['phrases'] ) && is_array( $in['phrases'] ) ? $in['phrases'] : $defaults[ $cat ]['phrases'];
            $clean = array();
            foreach ( $phrases as $p ) {
                if ( ! is_scalar( $p ) ) { continue; }
                $p = trim( str_replace( '|', ' ', self::normalise( (string) $p ) ) );
                if ( '' === $p || strlen( $p ) > self::MAX_PHRASE_LEN || in_array( $p, $clean, true ) ) { continue; }
                $clean[] = $p;
                if ( count( $clean ) >= self::MAX_PHRASES ) { break; }
            }
            $out[ $cat ] = array(
                'enabled' => array_key_exists( 'enabled', $in ) ? (bool) $in['enabled'] : true,
                'phrases' => $clean,
            );
        }
        $window = isset( $raw['expiry_window_days'] ) ? (int) $raw['expiry_window_days'] : (int) $defaults['expiry_window_days'];
        $out['expiry_window_days'] = max( 0, min( 14, $window ) );
        return $out;
    }

    /**
     * Classifies one enquiry. Returns array( 'automatic' => 'urgent'|'normal', 'reasons' => string[] ).
     * $received_utc is 'Y-m-d H:i:s' in UTC and anchors relative dates; null uses the current time.
     * $rules null reads the stored rules; pass an array to classify without WordPress.
     */
    public static function classify( $text, $received_utc = null, $rules = null ) {
        $rules = null === $rules ? self::rules() : self::normalise_rules( $rules );
        $norm = self::normalise( (string) $text );
        $reasons = array();

        $labels = array(
            'urgent_term' => 'Urgent wording',
            'complaint'   => 'Complaint',
            'callback'    => 'Callback request',
        );
        foreach ( $labels as $cat => $label ) {
            if ( empty( $rules[ $cat ]['enabled'] ) ) { continue; }
            foreach ( $rules[ $cat ]['phrases'] as $phrase ) {
                $hits = self::find_hits( $norm, $phrase );
                if ( $hits ) { $reasons[] = $label . ': "' . $phrase . '"'; break; }
            }
        }

        if ( ! empty( $rules['expiring_date']['enabled'] ) ) {
            $term = null;
            foreach ( $rules['expiring_date']['phrases'] as $phrase ) {
                if ( self::find_hits( $norm, $phrase ) ) { $term = $phrase; break; }
            }
            if ( null !== $term ) {
                $ref = self::reference_day( $received_utc );
                $window = (int) $rules['expiry_window_days'];
                foreach ( self::dates_in( $norm, $ref ) as $date ) {
                    $days = (int) $ref->diff( $date )->format( '%r%a' );
                    if ( $days >= 0 && $days <= $window ) {
                        $reasons[] = 'Expiring booking date: ' . $date->format( 'Y-m-d' ) . ' with "' . $term . '"';
                        break;
                    }
                }
            }
        }

        return array(
            'automatic' => $reasons ? 'urgent' : 'normal',
            'reasons'   => $reasons,
        );
    }

    /** The level an admin sees: a valid override wins, otherwise the automatic result ('' when not assessed). */
    public static function effective( $automatic, $override ) {
        if ( in_array( $override, self::LEVELS, true ) ) { return $override; }
        return in_array( $automatic, self::LEVELS, true ) ? $automatic : '';
    }

    /**
     * Gate for urgent alerts. Alerts need the admin's opt-in, an urgent automatic result, and no alert for this company
     * inside the throttle window. Enquiries that are not alerted stay visible in the triage list.
     */
    public static function alert_allowed( array $cfg, $automatic, $last_sent_ts, $now_ts ) {
        if ( empty( $cfg['enabled'] ) || 'urgent' !== $automatic ) { return false; }
        $minutes = isset( $cfg['throttle_minutes'] ) ? (int) $cfg['throttle_minutes'] : self::DEFAULT_THROTTLE_MIN;
        $window  = max( 1, min( 1440, $minutes ) ) * 60;
        return empty( $last_sent_ts ) || ( (int) $now_ts - (int) $last_sent_ts ) >= $window;
    }

    /** Audit event for an override change. Written in the same transaction as the override. */
    public static function override_event( $enquiry_id, $from, $to ) {
        return array(
            'plugin'    => 'titan-zero-front-desk',
            'action'    => 'enquiry.urgency_override',
            'entity'    => 'enquiry',
            'entity_id' => (int) $enquiry_id,
            'summary'   => 'Urgency override: ' . ( '' === $from ? 'automatic' : $from ) . ' to ' . ( '' === $to ? 'automatic' : $to ),
        );
    }

    /**
     * Sends one urgent alert for a company when the alert gate allows it. Called after a new enquiry is stored (not for
     * duplicates). Returns true when mail was accepted.
     */
    public static function maybe_alert( $company_id, $enquiry_id, $name, array $result ) {
        $cfg = get_option( self::OPTION_ALERTS, array() );
        $cfg = is_array( $cfg ) ? $cfg : array();
        $last_key = self::OPTION_LAST . (int) $company_id;
        $last = (int) get_option( $last_key, 0 );
        $now = time();
        if ( ! self::alert_allowed( $cfg, $result['automatic'] ?? '', $last, $now ) ) { return false; }

        $to = array();
        foreach ( preg_split( '/[,\s;]+/', (string) ( $cfg['recipients'] ?? '' ) ) as $addr ) {
            $addr = sanitize_email( $addr );
            if ( is_email( $addr ) ) { $to[] = $addr; }
        }
        if ( ! $to ) {
            $fallback = sanitize_email( (string) get_option( 'admin_email', '' ) );
            if ( is_email( $fallback ) ) { $to[] = $fallback; }
        }
        if ( ! $to ) { return false; }

        // Throttle is set before sending, so a burst cannot send twice. It is rolled back if mail is refused.
        update_option( $last_key, $now, false );
        $admin_url = admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ENQUIRIES );
        $body  = __( 'An enquiry was flagged as urgent by Front Desk.', 'titan-zero-trades-front-desk' ) . "\n\n";
        $body .= __( 'Name', 'titan-zero-trades-front-desk' ) . ': ' . sanitize_text_field( (string) $name ) . "\n";
        $body .= __( 'Reasons', 'titan-zero-trades-front-desk' ) . ': ' . implode( '; ', array_map( 'sanitize_text_field', $result['reasons'] ?? array() ) ) . "\n\n";
        $body .= __( 'Review it in Enquiries:', 'titan-zero-trades-front-desk' ) . ' ' . esc_url_raw( $admin_url ) . "\n";
        $sent = wp_mail( $to, __( 'Urgent enquiry received', 'titan-zero-trades-front-desk' ), $body );
        if ( ! $sent ) { update_option( $last_key, $last, false ); }
        return (bool) $sent;
    }

    /** Admin-post: sets or clears the urgency override on one enquiry. Capability, nonce, company and audit are enforced. */
    public static function handle_override() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::NONCE, self::NONCE_FIELD );
        $id       = TZFD_Request_Value::id( sanitize_text_field( self::post_text( 'enquiry' ) ) );
        $override = sanitize_key( self::post_text( 'override' ) );
        $company  = (int) TZFD_Scoped_Store::company_id();
        $ok = self::set_override( $id, $override, $company );
        $args = true === $ok ? array( 'enquiry_saved' => 1 ) : array( 'enquiry_error' => 1 );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ENQUIRIES ) ) );
        exit;
    }

    /** Writes the override and its audit record in one InnoDB transaction. Returns true or false. */
    public static function set_override( $enquiry_id, $override, $company_id ) {
        global $wpdb;
        $enquiry_id = TZFD_Request_Value::id( $enquiry_id );
        $company_id = TZFD_Request_Value::id( $company_id );
        if ( ! $enquiry_id || ! $company_id || ! in_array( $override, array_merge( array( '' ), self::LEVELS ), true ) ) { return false; }
        if ( ! class_exists( 'TZFD_Transaction_Storage' ) || ! class_exists( 'TZFD_Local_Audit' ) ) { return false; }
        $table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
        if ( ! TZFD_Transaction_Storage::require_innodb( array( $table, TZFD_Local_Audit::table_name() ) ) ) { return false; }
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, urgency_override FROM %i WHERE id = %d AND company_id = %d FOR UPDATE', $table, $enquiry_id, $company_id ) );
        if ( ! empty( $wpdb->last_error ) || ! $row ) { $wpdb->query( 'ROLLBACK' ); return false; }
        $from = (string) $row->urgency_override;
        if ( $from === $override ) { $wpdb->query( 'ROLLBACK' ); return true; }
        $update = $wpdb->update( $table, array( 'urgency_override' => $override ), array( 'id' => $enquiry_id, 'company_id' => $company_id ), array( '%s' ), array( '%d', '%d' ) );
        if ( false === $update ) { $wpdb->query( 'ROLLBACK' ); return false; }
        $actor = (int) get_current_user_id();
        $audit = TZFD_Local_Audit::insert( $company_id, $actor, $actor ? 'user' : 'system', self::override_event( $enquiry_id, $from, $override ) );
        if ( true !== $audit ) { $wpdb->query( 'ROLLBACK' ); return false; }
        return false !== $wpdb->query( 'COMMIT' );
    }

    /** Admin-post: saves the phrase lists, window and alert preferences. Changes are audited. */
    public static function handle_settings() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::NONCE, self::NONCE_FIELD );
        $company = (int) TZFD_Scoped_Store::company_id();
        if ( $company < 1 ) { wp_die( esc_html__( 'No company is selected.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }

        $enabled_in = isset( $_POST['urgency_enabled'] ) && is_array( $_POST['urgency_enabled'] ) ? $_POST['urgency_enabled'] : array();
        $phrases_in = isset( $_POST['urgency_phrases'] ) && is_array( $_POST['urgency_phrases'] ) ? $_POST['urgency_phrases'] : array();
        $raw_rules = array();
        foreach ( self::CATEGORIES as $cat ) {
            $text = isset( $phrases_in[ $cat ] ) && is_scalar( $phrases_in[ $cat ] ) ? sanitize_textarea_field( wp_unslash( (string) $phrases_in[ $cat ] ) ) : '';
            $raw_rules[ $cat ] = array(
                'enabled' => ! empty( $enabled_in[ $cat ] ),
                'phrases' => preg_split( '/[\r\n]+/', $text ),
            );
        }
        $raw_rules['expiry_window_days'] = absint( self::post_text( 'urgency_window_days', '2' ) );
        $rules = self::normalise_rules( $raw_rules );

        $alerts = array(
            'enabled'          => 'yes' === sanitize_key( self::post_text( 'urgency_alerts_enabled', 'no' ) ),
            'recipients'       => substr( sanitize_text_field( self::post_text( 'urgency_recipients' ) ), 0, 500 ),
            'throttle_minutes' => max( 1, min( 1440, absint( self::post_text( 'urgency_throttle_minutes', (string) self::DEFAULT_THROTTLE_MIN ) ) ) ),
        );

        update_option( self::OPTION_RULES, $rules, false );
        update_option( self::OPTION_ALERTS, $alerts, false );
        if ( class_exists( 'TZFD_Local_Audit' ) ) {
            $actor = (int) get_current_user_id();
            TZFD_Local_Audit::insert( $company, $actor, $actor ? 'user' : 'system', array(
                'plugin' => 'titan-zero-front-desk', 'action' => 'enquiry.urgency_rules', 'entity' => 'enquiry', 'entity_id' => 0,
                'summary' => 'Urgency rules and alert preferences updated',
            ) );
        }
        wp_safe_redirect( add_query_arg( array( 'enquiry_saved' => 1 ), admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ENQUIRIES ) ) );
        exit;
    }

    /** Display label for a level. An empty level means the enquiry was stored before this release and not assessed. */
    public static function label( $level ) {
        if ( 'urgent' === $level ) { return __( 'Urgent', 'titan-zero-trades-front-desk' ); }
        if ( 'normal' === $level ) { return __( 'Normal', 'titan-zero-trades-front-desk' ); }
        return __( 'Not assessed', 'titan-zero-trades-front-desk' );
    }

    /** Urgency cell for the triage table: the level, its reasons, and the admin override control. */
    public static function render_override( $row ) {
        $auto     = (string) ( $row->urgency_auto ?? '' );
        $override = (string) ( $row->urgency_override ?? '' );
        $level    = self::effective( $auto, $override );
        $text     = self::label( $level ) . ( '' !== $override ? ' (' . __( 'set by admin', 'titan-zero-trades-front-desk' ) . ')' : '' );
        $out  = '<strong>' . esc_html( $text ) . '</strong>';
        $reason = (string) ( $row->urgency_reason ?? '' );
        if ( '' !== $reason ) { $out .= '<br /><span class="description">' . esc_html( $reason ) . '</span>'; }
        $out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $out .= '<input type="hidden" name="action" value="' . esc_attr( self::OVERRIDE_ACTION ) . '" />';
        $out .= '<input type="hidden" name="enquiry" value="' . (int) ( $row->id ?? 0 ) . '" />';
        $out .= wp_nonce_field( self::NONCE, self::NONCE_FIELD, true, false );
        $out .= '<select name="override" aria-label="' . esc_attr__( 'Urgency override', 'titan-zero-trades-front-desk' ) . '">';
        $options = array( '' => __( 'Automatic', 'titan-zero-trades-front-desk' ), 'urgent' => __( 'Urgent', 'titan-zero-trades-front-desk' ), 'normal' => __( 'Normal', 'titan-zero-trades-front-desk' ) );
        foreach ( $options as $value => $name ) { $out .= '<option value="' . esc_attr( $value ) . '"' . selected( $override, $value, false ) . '>' . esc_html( $name ) . '</option>'; }
        $out .= '</select> <button class="button" type="submit">' . esc_html__( 'Save', 'titan-zero-trades-front-desk' ) . '</button></form>';
        return $out;
    }

    /** Admin form for the phrase lists, the expiry window and the urgent-alert preferences. */
    public static function render_settings() {
        $rules  = self::rules();
        $alerts = get_option( self::OPTION_ALERTS, array() );
        $alerts = is_array( $alerts ) ? $alerts : array();
        $labels = array(
            'urgent_term'   => __( 'Urgent wording', 'titan-zero-trades-front-desk' ),
            'complaint'     => __( 'Complaints', 'titan-zero-trades-front-desk' ),
            'callback'      => __( 'Callback requests', 'titan-zero-trades-front-desk' ),
            'expiring_date' => __( 'Expiring booking dates', 'titan-zero-trades-front-desk' ),
        );
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tzfd-urgency-settings">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::SETTINGS_ACTION ) . '" />';
        wp_nonce_field( self::NONCE, self::NONCE_FIELD );
        echo '<h2>' . esc_html__( 'Urgency rules', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Enquiries are flagged with these phrases only. One phrase per line. A phrase preceded by "not", "no" or "never" in the same clause does not count.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<table class="form-table">';
        foreach ( $labels as $cat => $label ) {
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
            echo '<label><input type="checkbox" name="urgency_enabled[' . esc_attr( $cat ) . ']" value="1"' . checked( ! empty( $rules[ $cat ]['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Enabled', 'titan-zero-trades-front-desk' ) . '</label><br />';
            echo '<textarea name="urgency_phrases[' . esc_attr( $cat ) . ']" rows="4" cols="60" aria-label="' . esc_attr( $label ) . '">' . esc_textarea( implode( "\n", $rules[ $cat ]['phrases'] ) ) . '</textarea>';
            if ( 'expiring_date' === $cat ) {
                echo '<p><label>' . esc_html__( 'Days ahead that count as expiring', 'titan-zero-trades-front-desk' ) . ' <input type="number" min="0" max="14" name="urgency_window_days" value="' . (int) $rules['expiry_window_days'] . '" /></label></p>';
            }
            echo '</td></tr>';
        }
        echo '<tr><th scope="row">' . esc_html__( 'Urgent alert email', 'titan-zero-trades-front-desk' ) . '</th><td>';
        echo '<select name="urgency_alerts_enabled"><option value="no"' . selected( empty( $alerts['enabled'] ), true, false ) . '>' . esc_html__( 'Off (default)', 'titan-zero-trades-front-desk' ) . '</option><option value="yes"' . selected( ! empty( $alerts['enabled'] ), true, false ) . '>' . esc_html__( 'On', 'titan-zero-trades-front-desk' ) . '</option></select>';
        echo ' <label>' . esc_html__( 'Recipients (comma separated; blank uses the site admin email)', 'titan-zero-trades-front-desk' ) . ' <input type="text" size="40" name="urgency_recipients" value="' . esc_attr( (string) ( $alerts['recipients'] ?? '' ) ) . '" /></label>';
        echo ' <label>' . esc_html__( 'At most one alert every (minutes)', 'titan-zero-trades-front-desk' ) . ' <input type="number" min="1" max="1440" name="urgency_throttle_minutes" value="' . (int) ( $alerts['throttle_minutes'] ?? self::DEFAULT_THROTTLE_MIN ) . '" /></label>';
        echo '</td></tr></table>';
        echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Save urgency settings', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
    }

    /** Lowercases, turns clause punctuation into '|' (negation does not cross it) and strips anything else. */
    public static function normalise( $text ) {
        $t = strtolower( (string) $text );
        $t = strtr( $t, array( "\xE2\x80\x99" => "'", "\xE2\x80\x98" => "'" ) );
        $t = preg_replace( '/[.!?;,:\r\n\t]+/', ' | ', $t );
        $t = preg_replace( "/[^a-z0-9'\\/\\- |]+/", ' ', $t );
        return trim( preg_replace( '/\s+/', ' ', $t ) );
    }

    /** Matches of a phrase that are not negated. A negator within three words before it, in the same clause, cancels it. */
    private static function find_hits( $norm, $phrase ) {
        $phrase = trim( (string) $phrase );
        if ( '' === $phrase ) { return array(); }
        $pattern = '/(?<![a-z0-9])' . preg_quote( $phrase, '/' ) . '(?![a-z0-9])/';
        if ( ! preg_match_all( $pattern, $norm, $m, PREG_OFFSET_CAPTURE ) ) { return array(); }
        $hits = array();
        foreach ( $m[0] as $hit ) {
            if ( ! self::negated( substr( $norm, 0, $hit[1] ) ) ) { $hits[] = $hit[0]; }
        }
        return $hits;
    }

    private static function negated( $before ) {
        $pos = strrpos( $before, '|' );
        $clause = false === $pos ? $before : substr( $before, $pos + 1 );
        $words = preg_split( '/\s+/', trim( $clause ), -1, PREG_SPLIT_NO_EMPTY );
        foreach ( array_slice( $words ?: array(), -3 ) as $word ) {
            if ( in_array( $word, self::NEGATORS, true ) ) { return true; }
        }
        return false;
    }

    /** Date that relative words and the expiry window are counted from (UTC). */
    private static function reference_day( $received_utc ) {
        $ref = null;
        if ( is_string( $received_utc ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $received_utc ) ) {
            $ref = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $received_utc, new DateTimeZone( 'UTC' ) ) ?: null;
        }
        if ( null === $ref ) { $ref = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ); }
        return $ref->setTime( 0, 0, 0 );
    }

    /** Explicit calendar dates in the text, on or after the reference day. Day-first order (Australian) for dd/mm/yyyy. */
    private static function dates_in( $norm, DateTimeImmutable $ref ) {
        $found = array();
        if ( preg_match_all( '/(?<![a-z0-9])(today|tomorrow)(?![a-z0-9])/', $norm, $m ) ) {
            foreach ( $m[1] as $word ) { $found[] = $ref->modify( 'tomorrow' === $word ? '+1 day' : '+0 days' ); }
        }
        if ( preg_match_all( '/(?<![0-9])(\d{4})-(\d{1,2})-(\d{1,2})(?![0-9])/', $norm, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) { $found[] = self::make_date( (int) $x[1], (int) $x[2], (int) $x[3] ); }
        }
        if ( preg_match_all( '/(?<![0-9])(\d{1,2})[\/-](\d{1,2})[\/-](\d{4}|\d{2})(?![0-9])/', $norm, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) {
                $year = 4 === strlen( $x[3] ) ? (int) $x[3] : 2000 + (int) $x[3];
                $found[] = self::make_date( $year, (int) $x[2], (int) $x[1] );
            }
        }
        $month_re = 'january|february|march|april|june|july|august|september|october|november|december|may|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec';
        if ( preg_match_all( '/(?<![a-z0-9])(\d{1,2})(?:st|nd|rd|th)? (' . $month_re . ')[a-z]*(?: (\d{4}))?(?![a-z0-9])/', $norm, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) { $found[] = self::named_month_date( (int) $x[1], $x[2], $x[3] ?? '', $ref ); }
        }
        if ( preg_match_all( '/(?<![a-z0-9])(' . $month_re . ')[a-z]* (\d{1,2})(?:st|nd|rd|th)?(?: (\d{4}))?(?![0-9])/', $norm, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $x ) { $found[] = self::named_month_date( (int) $x[2], $x[1], $x[3] ?? '', $ref ); }
        }
        $dates = array();
        foreach ( $found as $d ) {
            if ( $d instanceof DateTimeImmutable && $d >= $ref ) { $dates[] = $d; }
        }
        return $dates;
    }

    private static function named_month_date( $day, $month_word, $year, DateTimeImmutable $ref ) {
        $key = substr( $month_word, 0, 3 );
        if ( ! isset( self::MONTHS[ $key ] ) ) { return null; }
        $y = '' !== $year ? (int) $year : (int) $ref->format( 'Y' );
        $date = self::make_date( $y, self::MONTHS[ $key ], $day );
        // Without a year, a date that already passed this year is treated as next year.
        if ( null !== $date && '' === $year && $date < $ref ) { $date = self::make_date( $y + 1, self::MONTHS[ $key ], $day ); }
        return $date;
    }

    private static function make_date( $year, $month, $day ) {
        if ( ! checkdate( $month, $day, $year ) ) { return null; }
        return new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), new DateTimeZone( 'UTC' ) );
    }
}
