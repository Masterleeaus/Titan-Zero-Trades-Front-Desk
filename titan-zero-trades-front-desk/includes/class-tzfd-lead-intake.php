<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/**
 * Lead intake from Titan Zero Business Builder.
 *
 * Builder fires do_action( 'tz_builder_lead_captured', $payload ). This class listens and stores
 * the enquiry in Front Desk's own tz_enquiries table, stamped with the company from the payload.
 * It writes nothing to any other plugin's table. Builder refuses lead intake when nothing listens
 * on this hook, so registering here is what lets Builder's leads reach Front Desk.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Lead_Intake {

    const HOOK = 'tz_builder_lead_captured';

    /** Called once from tzfd_boot(), after Workspace is confirmed. */
    public static function register() {
        add_action( self::HOOK, array( __CLASS__, 'receive' ), 10, 1 );
        add_shortcode( 'tzfd_enquiry_form', array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_post_nopriv_tzfd_public_enquiry', array( __CLASS__, 'submit_public' ) );
        add_action( 'admin_post_tzfd_public_enquiry', array( __CLASS__, 'submit_public' ) );
    }

    /** Public, nonce-protected enquiry form. No dependency on other plugins. */
    public static function shortcode() {
        if ( ! class_exists( 'TZFD_Scoped_Store' ) || (int) TZFD_Scoped_Store::company_id() < 1 ) { return ''; }
        require_once __DIR__ . '/class-tzfd-ui-graphics.php';
        require_once __DIR__ . '/class-tzfd-public-form-assets.php';
        TZFD_Public_Form_Assets::enqueue();
        $req = TZFD_Public_Form_Assets::required_marker();
        $out = '<form class="tzfd-public-enquiry" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $out .= '<input type="hidden" name="action" value="tzfd_public_enquiry">';
        $out .= wp_nonce_field( 'tzfd_public_enquiry', '_tzfd_enquiry_nonce', true, false );
        $out .= '<label>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . ' ' . $req . ' <input required aria-required="true" maxlength="190" name="name" autocomplete="name"></label>';
        $out .= '<label>' . wp_kses( TZFD_UI_Graphics::channel_html( 'email', 24 ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Email', 'titan-zero-trades-front-desk' ) . ' ' . $req . ' <input required aria-required="true" type="email" maxlength="190" name="email" autocomplete="email"></label>';
        $out .= '<label>' . wp_kses( TZFD_UI_Graphics::channel_html( 'phone', 24 ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Phone (optional)', 'titan-zero-trades-front-desk' ) . ' <input maxlength="60" name="phone" autocomplete="tel"></label>';
        $out .= '<label>' . esc_html__( 'How can we help?', 'titan-zero-trades-front-desk' ) . ' ' . $req . ' <textarea required aria-required="true" maxlength="5000" name="enquiry"></textarea></label>';
        // Privacy consent is required before submit. The wording is PLACEHOLDER text pending owner review (filter: tzfd_enquiry_consent_text).
        $out .= '<label class="tzfd-consent"><input required type="checkbox" name="privacy_consent" value="1"> ' . esc_html( self::consent_text() ) . '</label>';
        $out .= '<div class="tzfd-hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>';
        $out .= '<button type="submit">' . esc_html__( 'Send enquiry', 'titan-zero-trades-front-desk' ) . '</button></form>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
        if ( isset( $_GET['tzfd_enquiry_sent'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['tzfd_enquiry_sent'] ) ) ) { $out = '<p role="status">' . esc_html__( 'Thank you. Your enquiry has been received.', 'titan-zero-trades-front-desk' ) . '</p>' . $out; }
        return $out;
    }

    public static function submit_public() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { wp_die( esc_html__( 'Method not allowed', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) ); }
        if ( ! isset( $_POST['_tzfd_enquiry_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tzfd_enquiry_nonce'] ) ), 'tzfd_public_enquiry' ) ) { wp_die( esc_html__( 'Invalid form submission', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- honeypot presence check; raw value is never stored or output, and sanitizing would trim whitespace-only bot input into a pass.
        if ( ! empty( wp_unslash( $_POST['website'] ?? '' ) ) ) { wp_die( esc_html__( 'Invalid submission', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        $company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
        if ( $company < 1 ) { wp_die( esc_html__( 'Enquiry form unavailable', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) ); }
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST['enquiry'] ?? '' ) );
        if ( ! is_email( $email ) || '' === trim( $name ) || '' === trim( $message ) || strlen( $message ) > 5000 ) { wp_die( esc_html__( 'Please complete all required fields', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        // Consent is recorded only when the box was ticked on this submission. Refusal stores nothing.
        if ( ! isset( $_POST['privacy_consent'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['privacy_consent'] ) ) ) { wp_die( esc_html__( 'Please tick the privacy consent box to send your enquiry.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        // One enquiry per address per company per minute. TZFD_Rate_Limit claims the slot in one atomic statement,
        // so parallel posts from one address cannot all pass a check that ran before any of them wrote.
        require_once __DIR__ . '/class-tzfd-rate-limit.php';
        $key = 'lead-intake|' . $company . '|' . (string) sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        if ( ! TZFD_Rate_Limit::allow( $key, 1, MINUTE_IN_SECONDS ) ) { wp_die( esc_html__( 'Please wait before submitting another enquiry', 'titan-zero-trades-front-desk' ), '', array( 'response' => 429 ) ); }
        $ip_hash = hash_hmac( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), wp_salt( 'auth' ) );
        $saved = self::receive( array( 'company_id' => $company, 'name' => $name, 'email' => $email, 'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), 'enquiry' => $message, 'source' => 'website-form', 'triage_ip_hash' => $ip_hash, 'privacy_consent_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
        if ( ! $saved ) { wp_die( esc_html__( 'Your enquiry could not be saved. Please contact us directly.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) ); }
        // First customer contact: write the greeting if none is saved yet (owner decision on #400, 2026-11-23).
        // A greeting the administrator already has is never replaced, and nothing is written without a business name.
        require_once __DIR__ . '/class-tzfd-ai-setup-check.php';
        $profile  = TZFD_Scoped_Store::get( 'tz_fd_business_profile', array(), $company );
        $greeting = TZFD_Ai_Setup_Check::first_contact_greeting( TZFD_Scoped_Store::get( 'tz_fd_ai_greeting', '', $company ), is_array( $profile ) ? (string) ( $profile['name'] ?? '' ) : '' );
        if ( null !== $greeting ) { TZFD_Scoped_Store::update( 'tz_fd_ai_greeting', $greeting, $company ); }
        $referer = wp_get_referer();
        wp_safe_redirect( add_query_arg( 'tzfd_enquiry_sent', '1', $referer ? $referer : home_url( '/' ) ) );
        exit;
    }

    /** Placeholder consent wording. Owner review pending; override with the tzfd_enquiry_consent_text filter. */
    public static function consent_text() {
        $default = '[PLACEHOLDER - pending owner review] I agree that my details may be used to reply to this enquiry.';
        $text    = apply_filters( 'tzfd_enquiry_consent_text', $default );
        return is_string( $text ) && '' !== trim( $text ) ? $text : $default;
    }

    /** Adds the consent column to an existing tz_enquiries table once (activation only creates it on fresh installs). */
    private static function ensure_consent_column( $table ) {
        global $wpdb;
        if ( get_option( 'tzfd_enq_consent_col_v1' ) ) { return; }
        $has = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'privacy_consent_utc'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted plugin table name.
        if ( empty( $has ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN privacy_consent_utc datetime NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted plugin table name, no user input.
        }
        update_option( 'tzfd_enq_consent_col_v1', 1, false );
    }

    /**
     * Stores one enquiry. Returns the new row id, the existing row id for a duplicate, or 0 when the payload is refused.
     *
     * @param mixed $payload Array from Builder (see class header).
     * @return int
     */
    public static function receive( $payload ) {
        global $wpdb;
        if ( ! is_array( $payload ) ) { return 0; }

        $company_id = isset( $payload['company_id'] ) && ( is_int( $payload['company_id'] ) || is_string( $payload['company_id'] ) ) && preg_match( '/^[1-9][0-9]*$/D', (string) $payload['company_id'] ) ? absint( $payload['company_id'] ) : 0;
        if ( 0 === $company_id ) { return 0; } // Fail closed: an enquiry without a company is never stored.

        $name    = isset( $payload['name'] ) && is_scalar( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : '';
        $email   = isset( $payload['email'] ) && is_scalar( $payload['email'] ) ? sanitize_email( (string) $payload['email'] ) : '';
        $phone   = isset( $payload['phone'] ) && is_scalar( $payload['phone'] ) ? sanitize_text_field( (string) $payload['phone'] ) : '';
        $enquiry = isset( $payload['enquiry'] ) && is_scalar( $payload['enquiry'] ) ? sanitize_textarea_field( (string) $payload['enquiry'] ) : '';
        if ( '' === $name && '' === $email && '' === $phone && '' === $enquiry ) { return 0; }

        $received = isset( $payload['received'] ) && is_scalar( $payload['received'] ) ? sanitize_text_field( (string) $payload['received'] ) : '';
        // Builder data is external to Front Desk; reject impossible calendar dates, not just invalid formats.
        $received_date = false;
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $received ) ) {
            $received_date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $received, new DateTimeZone( 'UTC' ) );
        }
        $received = $received_date instanceof DateTimeImmutable && $received_date->format( 'Y-m-d H:i:s' ) === $received
            ? $received
            : gmdate( 'Y-m-d H:i:s' );

        $row = array(
            'company_id'        => $company_id,
            'name'              => substr( $name, 0, 190 ),
            'email'             => substr( $email, 0, 190 ),
            'phone'             => substr( $phone, 0, 60 ),
            'preferred_contact' => isset( $payload['preferred_contact'] ) && is_scalar( $payload['preferred_contact'] ) ? substr( sanitize_key( (string) $payload['preferred_contact'] ), 0, 20 ) : '',
            'enquiry'           => $enquiry,
            'campaign_id'       => isset( $payload['campaign_id'] ) && is_scalar( $payload['campaign_id'] ) ? absint( $payload['campaign_id'] ) : 0,
            'campaign_title'    => isset( $payload['campaign_title'] ) && is_scalar( $payload['campaign_title'] ) ? substr( sanitize_text_field( (string) $payload['campaign_title'] ), 0, 190 ) : '',
            'source'            => isset( $payload['source'] ) && is_scalar( $payload['source'] ) ? substr( sanitize_key( (string) $payload['source'] ), 0, 60 ) : '',
            'received_utc'      => $received,
            // Null means no consent was recorded (Builder payloads and rows stored before this column existed).
            'privacy_consent_utc' => isset( $payload['privacy_consent_utc'] ) && is_string( $payload['privacy_consent_utc'] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $payload['privacy_consent_utc'] ) ? $payload['privacy_consent_utc'] : null,
            'status'            => 'new',
            'created'           => current_time( 'mysql' ),
        );

        $table = TZFD_Tables::name( TZFD_Tables::ENQUIRIES );
        // Duplicate guard: a reloaded or re-posted form sends the same enquiry again. The same company, email and text
        // inside 10 minutes is one enquiry. Without an email, name and enquiry text decide.
        $dupe = $wpdb->get_var( $wpdb->prepare("SELECT id FROM %i WHERE company_id = %d AND email = %s AND name = %s AND enquiry = %s AND created >= %s LIMIT 1", $table, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TZFD_Tables.
            $company_id, $email, $name, $enquiry, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 600 )) );
        // A duplicate is a success, not a failure: return the stored row so the caller shows the same confirmation. Nothing is stored twice.
        if ( $dupe ) { return (int) $dupe; }
        self::ensure_consent_column( $table );
        $ok    = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Front Desk's own table; company_id set above.
        if ( ! $ok ) { return 0; }
        $new_id = (int) $wpdb->insert_id;
        // Rules triage after the enquiry is saved. A triage failure never loses or changes the enquiry.
        if ( class_exists( 'TZFD_Enquiry_Triage' ) ) {
            $ip_hash = isset( $payload['triage_ip_hash'] ) && is_string( $payload['triage_ip_hash'] ) ? $payload['triage_ip_hash'] : '';
            TZFD_Enquiry_Triage::record_safely( $company_id, $new_id, $enquiry, $email, $ip_hash );
        }
        return $new_id;
    }
}
