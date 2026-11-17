<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables. Values are bound with prepare() and %i/%d/%s placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( defined( 'ABSPATH' ) ) { require_once __DIR__ . '/class-tzfd-request-scalar.php'; }
require_once __DIR__ . '/class-tzfd-request-value.php';


require_once __DIR__ . '/class-tzfd-stored-data.php';
require_once __DIR__ . '/class-tzfd-bookings.php';
require_once __DIR__ . '/class-tzfd-booking-view.php';
require_once __DIR__ . '/class-tzfd-customer-cancellation.php';
require_once __DIR__ . '/class-tzfd-customer-rescheduling.php';

/**
 * Booking page and client portal.
 *
 * Two pages are created from Settings: a booking page (the booking form inside the active theme's
 * page layout) and a client portal. Clients sign in with their email address: they get a one-time
 * link that lasts 20 minutes and opens a session for 2 hours. The portal lists their bookings, with
 * the same signed cancel and reschedule links used in booking emails. Quotes are listed by Titan Zero Quotes
 * through the tz_fd_client_portal_sections action (19 Oct split); Front Desk never reads quote tables.
 *
 * The portal never reveals whether an email address has records: the reply is the same either way.
 */
final class TZFD_Client_Portal {
    const OPT_BOOKING_PAGE = 'tz_fd_booking_page_id';
    const OPT_PORTAL_PAGE  = 'tz_fd_client_portal_page_id';
    const OPT_BOOKING_FORM = 'tz_fd_booking_page_form_id';
    const OPT_BOOKING_STYLE = 'tz_fd_booking_portal_booking_style';
    const OPT_PORTAL_STYLE = 'tz_fd_booking_portal_client_style';
    const OPT_BOOKING_OPTIONS = 'tz_fd_booking_portal_booking_options';
    const OPT_PORTAL_OPTIONS = 'tz_fd_booking_portal_client_options';
    const OPT_LINKS_PAGE = 'tz_fd_booking_portal_links_page_id';
    const OPT_COLOUR_TEMPLATE = 'tz_fd_booking_portal_colour_template';
    const COLOUR_TEMPLATES = array( 'light', 'navy', 'slate' );
    const ACTION_SAVE_BUILDER = 'tz_fd_booking_portal_save_builder';
    const NONCE_BUILDER = 'tz_fd_booking_portal_builder';

    const SHORT_BOOKING = 'tz_fd_booking_page';
    const SHORT_PORTAL  = 'tz_fd_client_portal';

    const PARAM_LOGIN  = 'tz_fd_portal_login';
    const PARAM_LOGOUT = 'tz_fd_portal_logout';
    const PARAM_NOTICE = 'tz_fd_portal_notice';

    const COOKIE         = 'tz_fd_portal_session';
    const LINK_PREFIX    = 'tz_fd_portal_link_';
    const SESSION_PREFIX = 'tz_fd_portal_session_';
    const THROTTLE_PREFIX = 'tz_fd_portal_throttle_';
    const LINK_ISSUE_PREFIX = 'tz_fd_portal_link_issued_';
    const LINK_TTL       = 1200;   // 20 minutes
    const SESSION_TTL    = 7200;   // 2 hours
    const THROTTLE_TTL   = 60;     // one sign-in email per address per minute

    const ACTION_REQUEST = 'tz_fd_portal_request';
    const ACTION_CREATE  = 'tz_fd_portal_create_pages';
    const NONCE_REQUEST  = 'tz_fd_portal_request';
    const NONCE_CREATE   = 'tz_fd_portal_create';
    const NONCE_LOGOUT   = 'tz_fd_portal_logout';

    const MAX_ROWS = 200;

    public function register() {
        add_shortcode( self::SHORT_BOOKING, array( $this, 'booking_shortcode' ) );
        add_shortcode( self::SHORT_PORTAL, array( $this, 'portal_shortcode' ) );
        add_action( 'template_redirect', array( $this, 'handle_login_link' ) );
        add_action( 'admin_post_' . self::PARAM_LOGOUT, array( $this, 'handle_logout' ) );
        add_action( 'admin_post_nopriv_' . self::PARAM_LOGOUT, array( $this, 'handle_logout' ) );
        add_action( 'admin_post_' . self::ACTION_REQUEST, array( $this, 'handle_request' ) );
        add_action( 'admin_post_nopriv_' . self::ACTION_REQUEST, array( $this, 'handle_request' ) );
        add_action( 'admin_post_' . self::ACTION_CREATE, array( $this, 'handle_create_pages' ) );
        add_action( 'admin_post_' . self::ACTION_SAVE_BUILDER, array( $this, 'handle_save_builder' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_builder_styles' ) );
        add_action( 'admin_menu', array( $this, 'register_builder_page' ), 12 );
        add_action( 'admin_init', array( $this, 'hidden_builder_title' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_builder_admin_styles' ) );
    }

    /* ---------------------------------------------------------------
     * Pure helpers
     * ------------------------------------------------------------- */

    /** A token made by wp_generate_password( 40, false, false ): letters and digits only. */
    public static function is_token( $token ) {
        return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9]{20,64}$/D', $token );
    }

    /** Stored key for a token. The token itself is never kept, only its hash. */
    public static function token_key( $prefix, $token ) {
        return $prefix . hash( 'sha256', (string) $token );
    }

    /**
     * The issue time of one portal link, recorded the first time the portal shows it and reused on every later view.
     * Expiry is fixed from issue, so viewing never moves it. Kept in an option (not a transient) so cache eviction
     * cannot restart the clock. Each booking version gets its own record, so a changed booking starts a new one.
     */
    private static function link_issued_at( $kind, $booking_id, $form_id, $email, $version, $now ) {
        $name   = self::LINK_ISSUE_PREFIX . md5( $kind . '|' . (int) $booking_id . '|' . (int) $form_id . '|' . strtolower( (string) $email ) . '|' . (string) $version );
        $issued = (int) get_option( $name, 0 );
        if ( $issued <= 0 ) {
            add_option( $name, (int) $now, '', 'no' );
            $issued = (int) get_option( $name, 0 );
        }
        return $issued;
    }

    /** Booking links shown in the portal: cancel and reschedule, the same signed links the emails carry. */
    public static function booking_links( $row, $email ) {
        $secret = wp_salt( 'auth' );
        $links  = array();
        $id     = (int) $row->id;
        $form   = (int) $row->formid;
        $now    = time();

        // Both link types expire a fixed 14 days after their recorded issue time. An expired link is left out, never renewed.
        $cancel_version = TZFD_Customer_Cancellation::row_version( $row );
        $cancel_issued  = self::link_issued_at( 'cancel', $id, $form, $email, $cancel_version, $now );
        if ( TZFD_Customer_Cancellation::is_live( $cancel_issued, $now ) ) {
            $cancel_expires = $cancel_issued + TZFD_Customer_Cancellation::TOKEN_TTL;
            $cancel         = TZFD_Customer_Cancellation::create_token( $id, $email, $secret, $cancel_version, $cancel_expires );
            if ( '' !== $cancel ) {
                $links['cancel'] = add_query_arg(
                    array( 'action' => TZFD_Customer_Cancellation::ACTION, 'booking_id' => $id, 'expires' => $cancel_expires, 'token' => $cancel ),
                    admin_url( 'admin-post.php' )
                );
            }
        }

        // Reschedule links rebuild from their recorded issue time, so the token and expiry stay those of the first view.
        $version     = TZFD_Customer_Rescheduling::version( $row );
        $move_issued = self::link_issued_at( 'reschedule', $id, $form, $email, $version, $now );
        $move        = TZFD_Customer_Rescheduling::is_live( $move_issued, $now )
            ? TZFD_Customer_Rescheduling::issue( $id, $form, $email, $version, $secret, $move_issued )
            : null;
        if ( null !== $move ) {
            $links['reschedule'] = add_query_arg(
                array(
                    'action'     => TZFD_Customer_Rescheduling::ACTION,
                    'booking_id' => $id,
                    'form_id'    => (int) $row->formid,
                    'version'    => $version,
                    'issued'     => $move['issued'],
                    'expires'    => $move['expires'],
                    'token'      => $move['token'],
                ),
                admin_url( 'admin-post.php' )
            );
        }
        return $links;
    }

    /** Whether a calendar sends customer copies, which is the switch for showing change links. */
    public static function links_enabled( $form_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT id, cu_enable_copy_to_user FROM %i WHERE id = %d',
            $wpdb->prefix . TZFD_Tables::FORMS,
            (int) $form_id
        ) );
        return is_object( $row ) && 'true' === (string) $row->cu_enable_copy_to_user;
    }

    /** Bookings (appointments) that belong to one email address. */
    public static function records_for( $email ) {
        global $wpdb;
        $out   = array( 'bookings' => array(), 'booking_available' => true );
        $email = TZFD_Customer_Cancellation::normalize_email( $email );
        if ( '' === $email ) { return $out; }

        // Pass 95: an email address is not a company boundary. Require the
        // authoritative request tenant before querying customer bookings.
        $company_id = class_exists( 'TZFD_Scoped_Store' ) ? TZFD_Scoped_Store::company_id() : 0;
        if ( $company_id < 1 ) {
            $out['booking_available'] = false;
            return $out;
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT m.id, m.formid, m.notifyto, m.data, m.posted_data FROM %i m INNER JOIN %i f ON f.id = m.formid WHERE LOWER(m.notifyto) = %s AND f.company_id = %d ORDER BY m.id DESC LIMIT %d',
            $wpdb->prefix . TZFD_Bookings::TABLE,
            $wpdb->prefix . TZFD_Tables::FORMS,
            $email,
            $company_id,
            self::MAX_ROWS
        ) );
        if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
            $out['booking_available'] = false;
            $rows = array();
        }
        $names = TZFD_Booking_View::calendar_names();
        if ( ! TZFD_Booking_View::calendar_names_available() ) { $out['booking_available'] = false; }
        $today = TZFD_Booking_View::today();

        foreach ( (array) $rows as $row ) {
            // Defence in depth against stale or incorrectly joined records.
            if ( TZFD_Scoped_Store::company_for_form( (int) $row->formid ) !== $company_id ) { continue; }
            $data = TZFD_Stored_Data::decode_booking( (string) $row->posted_data );
            if ( null === $data ) { continue; }
            $form_id = (int) $row->formid;
            $links   = self::links_enabled( $form_id ) ? self::booking_links( $row, $email ) : array();
            $items   = TZFD_Booking_View::items_from_booking(
                (int) $row->id,
                $form_id,
                (string) ( $names[ $form_id ] ?? '' ),
                $email,
                $data,
                '0001-01-01',
                '9999-12-31'
            );
            foreach ( $items as $item ) {
                $item['upcoming'] = $item['date'] >= $today;
                $item['can_change'] = $item['upcoming'] && 'cancelled' !== $item['status_class'];
                $item['links']    = $item['can_change'] ? $links : array();
                $out['bookings'][] = $item;
            }
        }

        usort( $out['bookings'], function ( $a, $b ) {
            return strcmp( $a['date'] . $a['start'], $b['date'] . $b['start'] );
        } );

        return $out;
    }

    /** Calendar used by the booking page: the saved choice, else the first calendar. */
    public static function booking_form_id( $names = null ) {
        $saved = (int) get_option( self::OPT_BOOKING_FORM, 0 );
        if ( null === $names ) { $names = TZFD_Booking_View::calendar_names(); }
        // A failed query and a genuinely empty calendar list are different
        // states. Callers can inspect calendar_names_available() before
        // presenting the latter as a setup problem.
        if ( $saved > 0 && isset( $names[ $saved ] ) ) { return $saved; }
        if ( $names ) { return (int) key( $names ); }
        return 0;
    }

    public static function portal_url() {
        $page = (int) get_option( self::OPT_PORTAL_PAGE, 0 );
        return $page > 0 ? get_permalink( $page ) : home_url( '/' );
    }

    /* ---------------------------------------------------------------
     * Sessions and sign-in links
     * ------------------------------------------------------------- */

    /** The email address of the signed-in client, or '' when nobody is signed in. */
    public static function session_email() {
        $session = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ?? '' ) );
        if ( ! self::is_token( $session ) ) { return ''; }
        $identity = get_transient( self::token_key( self::SESSION_PREFIX, $session ) );
        $company = class_exists( 'TZFD_Scoped_Store' ) ? TZFD_Scoped_Store::company_id() : 0;
        // Legacy email-only sessions are intentionally invalidated on upgrade.
        return $company > 0 && is_array( $identity ) &&
            isset( $identity['company_id'], $identity['email'] ) &&
            (int) $identity['company_id'] === $company && is_string( $identity['email'] )
                ? $identity['email'] : '';
    }

    /** A sign-out changes session state, so GET and prefetched URLs are never allowed. */
    public function handle_logout() {
        // The nonce is verified before the POST shape is inspected, so no other $_POST field is read ahead of it.
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ||
            ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_LOGOUT ) ||
            ! is_array( $_POST ) || count( $_POST ) > 6 ) {
            wp_die( esc_html__( 'Invalid sign-out request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
        $session = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ?? '' ) );
        $proof = sanitize_text_field( wp_unslash( $_POST['tzfd_logout_proof'] ?? '' ) );
        // Guests can share a WordPress nonce. Require session-bound proof too.
        $expected = self::is_token( $session ) ? hash_hmac( 'sha256', 'logout|' . $session, wp_salt( 'auth' ) ) : '';
        if ( '' === $expected || ! hash_equals( $expected, $proof ) ) {
            wp_die( esc_html__( 'Invalid portal session.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        if ( self::is_token( $session ) ) {
            delete_transient( self::token_key( self::SESSION_PREFIX, $session ) );
        }
        self::set_session_cookie( '', time() - HOUR_IN_SECONDS );
        wp_safe_redirect( self::portal_url() );
        exit;
    }

    /**
     * One-time sign-in link handler. A GET with a valid token only shows a confirmation form and never
     * consumes the token, so link previewers and prefetchers cannot sign anyone in. The form POSTs the
     * token back with a nonce tied to it; only that POST consumes the token and opens the session.
     */
    public function handle_login_link() {
        $is_post = 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only. The GET token is validated below, and this GET path never changes state.
        $is_link = ! $is_post && isset( $_GET[ self::PARAM_LOGIN ] );
        $is_confirm = $is_post && isset( $_POST[ self::PARAM_LOGIN ] );
        if ( ! $is_link && ! $is_confirm ) { return; }

        // One-time sign-in URLs contain credentials; forbid intermediary caching
        // and prevent their query tokens from leaking in a referrer.
        if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
        if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }

        if ( $is_link ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The emailed token is the credential. This GET only reads it to show the confirmation form.
            $token = sanitize_text_field( wp_unslash( $_GET[ self::PARAM_LOGIN ] ?? '' ) );
            if ( null === self::pending_login( $token ) ) { self::redirect_expired_link(); }
            self::render_login_confirmation( $token );
        }

        // The token is read before the nonce check only because the nonce action is derived from it.
        // wp_verify_nonce() below gates every change that follows.
        $token = sanitize_text_field( wp_unslash( $_POST[ self::PARAM_LOGIN ] ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value is checked, not stored.
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
        $identity = self::is_token( $token ) && wp_verify_nonce( $nonce, self::login_confirm_action( $token ) )
            ? self::pending_login( $token )
            : null;
        if ( null === $identity ) { self::redirect_expired_link(); }

        // Claim the link before opening a session. delete_transient() returns true only for the one request
        // that removed it, so two clicks on the same link at the same moment cannot both sign in.
        if ( ! delete_transient( self::token_key( self::LINK_PREFIX, $token ) ) ) { self::redirect_expired_link(); }
        $session = wp_generate_password( 40, false, false );
        set_transient( self::token_key( self::SESSION_PREFIX, $session ), $identity, self::SESSION_TTL );
        self::set_session_cookie( $session, time() + self::SESSION_TTL );
        wp_safe_redirect( self::portal_url() );
        exit;
    }

    /** The stored identity for a sign-in token, or null. Read-only: the token is not consumed. */
    private static function pending_login( $token ) {
        $identity = self::is_token( $token ) ? get_transient( self::token_key( self::LINK_PREFIX, $token ) ) : false;
        $company = class_exists( 'TZFD_Scoped_Store' ) ? TZFD_Scoped_Store::company_id() : 0;
        if ( $company < 1 || ! is_array( $identity ) ||
            ! isset( $identity['email'], $identity['company_id'] ) ||
            ! is_string( $identity['email'] ) || '' === $identity['email'] ||
            (int) $identity['company_id'] !== $company ) {
            return null;
        }
        return $identity;
    }

    /** Nonce action for one token, so a nonce issued for one link cannot confirm another. The raw token is not used. */
    private static function login_confirm_action( $token ) {
        return 'tzfd_login_confirm_' . hash( 'sha256', (string) $token );
    }

    /** Confirmation page for a valid link. It has one button; the sign-in happens only on that POST. */
    private static function render_login_confirmation( $token ) {
        $html  = '<p>' . esc_html__( 'Confirm to open your bookings. This link works once.', 'titan-zero-trades-front-desk' ) . '</p>';
        $html .= '<form method="post" action="' . esc_url( self::portal_url() ) . '" class="tzfd-portal-confirm">';
        $html .= '<input type="hidden" name="' . esc_attr( self::PARAM_LOGIN ) . '" value="' . esc_attr( $token ) . '" />';
        $html .= wp_nonce_field( self::login_confirm_action( $token ), '_wpnonce', false, false );
        $html .= '<p><button type="submit">' . esc_html__( 'Sign in', 'titan-zero-trades-front-desk' ) . '</button></p>';
        $html .= '</form>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every fragment of $html is escaped where it is added (esc_html__, esc_attr, esc_url, wp_nonce_field).
        wp_die( $html, esc_html__( 'Sign in to your bookings', 'titan-zero-trades-front-desk' ), array( 'response' => 200 ) );
    }

    /** Invalid, expired, already-used or unverified link: no session and no token change. */
    private static function redirect_expired_link() {
        wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'expired', self::portal_url() ) );
        exit;
    }

    private static function set_session_cookie( $value, $expires ) {
        if ( headers_sent() ) { return; }
        setcookie( self::COOKIE, $value, array(
            'expires'  => $expires,
            'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );
    }

    /** Sign-in form submission. Always answers the same way, so it cannot show who has records. */
    public function handle_request() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value is checked, not stored.
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_REQUEST ) ) {
            wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'invalid', self::portal_url() ) );
            exit;
        }

        $raw_email = isset( $_POST['email'] ) ? sanitize_email( tzfd_request_text( 'post', 'email' ) ) : '';
        $email     = TZFD_Customer_Cancellation::normalize_email( $raw_email );
        if ( '' === $email ) {
            wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'invalid', self::portal_url() ) );
            exit;
        }

        $throttle = self::token_key( self::THROTTLE_PREFIX, md5( $email ) );
        if ( false === get_transient( $throttle ) ) {
            set_transient( $throttle, 1, self::THROTTLE_TTL );
            $records = self::records_for( $email );
            if ( empty( $records['booking_available'] ) ) {
                delete_transient( $throttle );
                wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'unavailable', self::portal_url() ) );
                exit;
            }
            if ( ! empty( $records['bookings'] ) ) {
                $token = wp_generate_password( 40, false, false );
                $company = class_exists( 'TZFD_Scoped_Store' ) ? TZFD_Scoped_Store::company_id() : 0;
                if ( $company < 1 ) {
                    wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'unavailable', self::portal_url() ) );
                    exit;
                }
                set_transient( self::token_key( self::LINK_PREFIX, $token ),
                    array( 'email' => $email, 'company_id' => $company ), self::LINK_TTL );
                $link  = add_query_arg( self::PARAM_LOGIN, $token, self::portal_url() );
                $site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
                $body  = sprintf(
                    /* translators: 1: site name, 2: sign-in link, 3: minutes the link lasts. */
                    __( "Open your bookings at %1\$s:\n\n%2\$s\n\nThis link works once and expires in %3\$d minutes. If you did not ask for it, you can ignore this email.", 'titan-zero-trades-front-desk' ),
                    $site,
                    $link,
                    (int) ( self::LINK_TTL / MINUTE_IN_SECONDS )
                );
                $subject = sprintf(
                    /* translators: %s: site name. */
                    __( 'Your bookings at %s', 'titan-zero-trades-front-desk' ),
                    $site
                );
                wp_mail( $email, $subject, $body );
            }
        }

        wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'sent', self::portal_url() ) );
        exit;
    }

    /* ---------------------------------------------------------------
     * Shortcodes
     * ------------------------------------------------------------- */

    /** The booking form, placed in the page the theme draws, so the theme's header, footer and fonts apply. */
    public function booking_shortcode( $atts ) {
        $form = self::booking_form_id();
        if ( ! TZFD_Booking_View::calendar_names_available() ) {
            return '<p>' . esc_html__( 'Online booking is temporarily unavailable. Please try again shortly.', 'titan-zero-trades-front-desk' ) . '</p>';
        }
        if ( $form <= 0 ) {
            return '<p>' . esc_html__( 'Online booking is not set up yet.', 'titan-zero-trades-front-desk' ) . '</p>';
        }
        $style = self::style_key( get_option( self::OPT_BOOKING_STYLE, 'modern' ), array( 'classic', 'modern', 'compact' ), 'modern' );
        $options = self::builder_options( get_option( self::OPT_BOOKING_OPTIONS, array() ) );
        $classes = 'tzfd-booking-builder tzfd-booking-style-' . $style . ' ' . self::colour_class() . ( ! empty( $options['show_card'] ) ? ' tzfd-builder-card' : '' ) . ( ! empty( $options['compact_spacing'] ) ? ' tzfd-builder-compact' : '' ) . ( empty( $options['show_heading'] ) ? ' tzfd-builder-no-heading' : '' );
        $heading = ! empty( $options['show_heading'] ) ? '<h2 class="tzfd-builder-heading">' . esc_html__( 'Book an appointment', 'titan-zero-trades-front-desk' ) . '</h2>' : '';
        return '<div class="' . esc_attr( $classes ) . '">' . $heading . do_shortcode( '[TZFD_FRONT_DESK id="' . $form . '"]' ) . '</div>';
    }

    public function portal_shortcode( $atts ) {
        $email = self::session_email();
        ob_start();
        $style = self::style_key( get_option( self::OPT_PORTAL_STYLE, 'modern' ), array( 'classic', 'modern', 'minimal' ), 'modern' );
        $options = self::builder_options( get_option( self::OPT_PORTAL_OPTIONS, array() ) );
        $classes = 'tzfd-portal tzfd-portal-style-' . $style . ' ' . self::colour_class() . ( ! empty( $options['show_card'] ) ? ' tzfd-builder-card' : '' ) . ( ! empty( $options['compact_spacing'] ) ? ' tzfd-builder-compact' : '' ) . ( empty( $options['show_heading'] ) ? ' tzfd-builder-no-heading' : '' );
        echo '<div class="' . esc_attr( $classes ) . '">';
        if ( '' === $email ) {
            self::render_login();
        } else {
            self::render_dashboard( $email );
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function notice_text() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only message keyword.
        $key = isset( $_GET[ self::PARAM_NOTICE ] ) ? sanitize_key( tzfd_request_text( 'get', self::PARAM_NOTICE ) ) : '';
        $map = array(
            'sent'    => __( 'If this email address has bookings, a sign-in link is on its way. Check your inbox.', 'titan-zero-trades-front-desk' ),
            'invalid' => __( 'Please enter a valid email address.', 'titan-zero-trades-front-desk' ),
            'expired' => __( 'That sign-in link has expired or was already used. Please request a new one.', 'titan-zero-trades-front-desk' ),
            'unavailable' => __( 'We could not check the portal just now. Please try again shortly.', 'titan-zero-trades-front-desk' ),
        );
        return $map[ $key ] ?? '';
    }

    private static function render_login() {
        $notice = self::notice_text();
        echo '<h2>' . esc_html__( 'Client portal', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p>' . esc_html__( 'See your bookings. Enter the email address you booked with and we will send you a sign-in link.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( '' !== $notice ) {
            echo '<p class="tzfd-portal-notice" role="status">' . esc_html( $notice ) . '</p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tzfd-portal-login">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REQUEST ) . '" />';
        wp_nonce_field( self::NONCE_REQUEST );
        echo '<label for="tzfd-portal-email">' . esc_html__( 'Email address', 'titan-zero-trades-front-desk' ) . '</label> ';
        echo '<input type="email" id="tzfd-portal-email" name="email" required autocomplete="email" /> ';
        echo '<button type="submit">' . esc_html__( 'Send sign-in link', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '</form>';
    }

    private static function render_dashboard( $email ) {
        $records  = self::records_for( $email );
        $notice   = self::notice_text();
        $logout   = admin_url( 'admin-post.php' );

        echo '<div class="tzfd-portal-head"><p>' . sprintf(
            /* translators: %s: email address. */
            esc_html__( 'Signed in as %s', 'titan-zero-trades-front-desk' ),
            '<strong>' . esc_html( $email ) . '</strong>'
        ) . ' &middot; <form method="post" action="' . esc_url( $logout ) . '" style="display:inline">'
        . '<input type="hidden" name="action" value="' . esc_attr( self::PARAM_LOGOUT ) . '">'
        . '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::NONCE_LOGOUT ) ) . '">'
        . '<input type="hidden" name="tzfd_logout_proof" value="' . esc_attr( hash_hmac( 'sha256', 'logout|' . sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ?? '' ) ), wp_salt( 'auth' ) ) ) . '">'
        . '<button type="submit" class="tzfd-portal-logout">' . esc_html__( 'Sign out', 'titan-zero-trades-front-desk' ) . '</button></form></p></div>';

        echo '<h2>' . esc_html__( 'Upcoming bookings', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( empty( $records['booking_available'] ) ) {
            echo '<p class="tzfd-portal-notice" role="status">' . esc_html__( 'Your bookings could not be loaded. Please try again shortly.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            self::render_bookings( array_values( array_filter( $records['bookings'], function ( $b ) { return $b['upcoming']; } ) ), true );
        }

        $past = array_values( array_filter( $records['bookings'], function ( $b ) { return ! $b['upcoming']; } ) );
        if ( ! empty( $records['booking_available'] ) && ! empty( $past ) ) {
            echo '<h2>' . esc_html__( 'Past bookings', 'titan-zero-trades-front-desk' ) . '</h2>';
            self::render_bookings( array_slice( array_reverse( $past ), 0, 20 ), false );
        }

        // Other Titan Zero plugins add their own portal sections here (Quotes lists the client's quotes).
        do_action( 'tz_fd_client_portal_sections', $records );
    }

    private static function render_bookings( array $items, $allow_change ) {
        if ( empty( $items ) ) {
            echo '<p>' . esc_html__( 'Nothing to show here yet.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        echo '<ul class="tzfd-portal-list">';
        foreach ( $items as $item ) {
            $when = $item['date'] . ( '' !== $item['start'] ? ' ' . $item['start'] . ( '' !== $item['end'] ? '–' . $item['end'] : '' ) : '' );
            echo '<li><strong>' . esc_html( $when ) . '</strong>';
            if ( '' !== $item['service'] ) { echo ' &middot; ' . esc_html( $item['service'] ); }
            echo ' &middot; ' . esc_html( $item['status'] );
            if ( $allow_change && ! empty( $item['links'] ) ) {
                foreach ( array( 'reschedule' => __( 'Change time', 'titan-zero-trades-front-desk' ), 'cancel' => __( 'Cancel', 'titan-zero-trades-front-desk' ) ) as $key => $label ) {
                    if ( ! empty( $item['links'][ $key ] ) ) {
                        echo ' &middot; <a href="' . esc_url( $item['links'][ $key ] ) . '">' . esc_html( $label ) . '</a>';
                    }
                }
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /* ---------------------------------------------------------------
     * Settings: create the pages
     * ------------------------------------------------------------- */

    /** Settings card: choose the calendar for the booking page and create or refresh the two pages. */
    public static function render_settings_card() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { return; }
        $names   = TZFD_Booking_View::calendar_names();
        $booking = (int) get_option( self::OPT_BOOKING_PAGE, 0 );
        $portal  = (int) get_option( self::OPT_PORTAL_PAGE, 0 );
        $current = self::booking_form_id( $names );
        $calendar_query_error = ! TZFD_Booking_View::calendar_names_available();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a save, no change is made.
        $created = isset( $_GET['tz_fd_portal_pages'] ) && 'created' === sanitize_key( tzfd_request_text( 'get', 'tz_fd_portal_pages' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error after a checked admin-post action.
        $invalid_form = isset( $_GET['tz_fd_portal_pages'] ) && 'invalid_form' === sanitize_key( tzfd_request_text( 'get', 'tz_fd_portal_pages' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error after a checked admin-post action.
        $database_error = isset( $_GET['tz_fd_portal_pages'] ) && 'database' === sanitize_key( tzfd_request_text( 'get', 'tz_fd_portal_pages' ) );

        echo '<div class="tzfd-set-card">';
        echo '<h2>' . esc_html__( 'Booking page and client portal', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p>' . esc_html__( 'Creates a booking page and a client portal as normal pages, so they use your theme\'s header, footer and fonts. Clients sign in with their email address to see and change their bookings.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( $created ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Pages are ready.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( $invalid_form ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Choose a calendar that still exists before creating the booking pages.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( $calendar_query_error || $database_error ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Calendars could not be loaded. The database query failed; reload this page after the database is available.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( $booking > 0 && get_permalink( $booking ) ) {
            echo '<p>' . esc_html__( 'Booking page:', 'titan-zero-trades-front-desk' ) . ' <a href="' . esc_url( get_permalink( $booking ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $booking ) ) . '</a></p>';
        }
        if ( $portal > 0 && get_permalink( $portal ) ) {
            echo '<p>' . esc_html__( 'Client portal:', 'titan-zero-trades-front-desk' ) . ' <a href="' . esc_url( get_permalink( $portal ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $portal ) ) . '</a></p>';
        }

        if ( ! $calendar_query_error ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREATE ) . '" />';
        wp_nonce_field( self::NONCE_CREATE );
        if ( count( $names ) > 1 ) {
            echo '<p><label for="tzfd-booking-calendar">' . esc_html__( 'Calendar for the booking page', 'titan-zero-trades-front-desk' ) . '</label><br />';
            echo '<select id="tzfd-booking-calendar" name="form_id">';
            foreach ( $names as $form_id => $name ) {
                echo '<option value="' . esc_attr( (string) $form_id ) . '"' . selected( (int) $current, (int) $form_id, false ) . '>' . esc_html( $name ) . '</option>';
            }
            echo '</select></p>';
        }
        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create or update pages', 'titan-zero-trades-front-desk' ) . '</button></p>';
        echo '</form>';
        }
        echo '</div>';
    }

    /** Normalise style keys and the independent display toggles. */
    private static function style_key( $value, array $allowed, $fallback ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : $fallback;
    }

    /** The saved colour template, validated against the allow-list. Invalid or missing values give light. */
    private static function colour_template() {
        return self::style_key( get_option( self::OPT_COLOUR_TEMPLATE, 'light' ), self::COLOUR_TEMPLATES, 'light' );
    }

    /** Theme class added to both public wrappers, for example tzfd-portal-theme-navy. */
    private static function colour_class() {
        return 'tzfd-portal-theme-' . self::colour_template();
    }

    private static function builder_options( $value ) {
        $value = is_array( $value ) ? $value : array();
        return array(
            'show_heading'   => isset( $value['show_heading'] ) && is_scalar( $value['show_heading'] ) && '1' === (string) $value['show_heading'],
            'show_card'      => isset( $value['show_card'] ) && is_scalar( $value['show_card'] ) && '1' === (string) $value['show_card'],
            'compact_spacing'=> isset( $value['compact_spacing'] ) && is_scalar( $value['compact_spacing'] ) && '1' === (string) $value['compact_spacing'],
        );
    }

    public function enqueue_builder_styles() {
        wp_enqueue_style( 'tzfd-booking-portal-builder', plugins_url( '../css/tzfd-booking-portal-builder.css', __FILE__ ), array(), '2.0.44' );
    }

    public function enqueue_builder_admin_styles( $hook_suffix ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen routing used only to enqueue a stylesheet.
        if ( isset( $_GET['page'] ) && in_array( sanitize_key( tzfd_request_text( 'get', 'page' ) ), array( 'tzfd-booking-portal-builder', 'tzfd-customer-portal-manager' ), true ) ) {
            wp_enqueue_style( 'tzfd-booking-portal-builder-admin', plugins_url( '../css/tzfd-booking-portal-builder.css', __FILE__ ), array(), '2.0.44' );
        }
    }

    public function register_builder_page() {
        add_submenu_page( '', __( 'Create & manage booking portal', 'titan-zero-trades-front-desk' ), __( 'Create & manage booking portal', 'titan-zero-trades-front-desk' ), 'tz_fd_access', 'tzfd-booking-portal-builder', array( $this, 'render_builder_page' ) );
    }

    /** The builder is registered under parent '', so WordPress cannot find its title; supply it through the global $title. */
    public function hidden_builder_title() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing value.
        if ( ! isset( $_GET['page'] ) || 'tzfd-booking-portal-builder' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { return; }
        global $title;
        $title = __( 'Create & manage booking portal', 'titan-zero-trades-front-desk' );
    }

    /** The dedicated builder opened by the Bookings page action. */
    public function render_builder_page() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $names = TZFD_Booking_View::calendar_names();
        $current = self::booking_form_id( $names );
        $calendar_query_error = ! TZFD_Booking_View::calendar_names_available();
        $booking_style = self::style_key( get_option( self::OPT_BOOKING_STYLE, 'modern' ), array( 'classic', 'modern', 'compact' ), 'modern' );
        $portal_style = self::style_key( get_option( self::OPT_PORTAL_STYLE, 'modern' ), array( 'classic', 'modern', 'minimal' ), 'modern' );
        $colour_template = self::colour_template();
        $booking_options =self::builder_options( get_option( self::OPT_BOOKING_OPTIONS, array( 'show_heading' => 1, 'show_card' => 1 ) ) );
        $portal_options = self::builder_options( get_option( self::OPT_PORTAL_OPTIONS, array( 'show_heading' => 1, 'show_card' => 1 ) ) );
        $booking_page = (int) get_option( self::OPT_BOOKING_PAGE, 0 );
        $portal_page = (int) get_option( self::OPT_PORTAL_PAGE, 0 );
        $links_page = (int) get_option( self::OPT_LINKS_PAGE, 0 );
        echo '<div class="wrap tzfd-portal-builder-admin"><h1>' . wp_kses( TZFD_UI_Graphics::brand_html( 'forms' ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Create & manage booking portal', 'titan-zero-trades-front-desk' ) . '</h1>';
        echo '<p>' . esc_html__( 'Choose a look for each customer-facing page, use the switches to tune the layout, then create or update the WordPress pages. A final links page lets you open both pages to check them.', 'titan-zero-trades-front-desk' ) . '</p>';
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These GET values only select display notices; save_builder verifies its POST nonce and capability.
        if ( isset( $_GET['tz_fd_builder_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['tz_fd_builder_saved'] ) ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Styles saved and pages created or updated.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( isset( $_GET['tz_fd_builder_saved'] ) && '0' === sanitize_text_field( wp_unslash( $_GET['tz_fd_builder_saved'] ) ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not create one or more portal pages. Check WordPress page permissions and try again.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( isset( $_GET['tz_fd_builder_saved'] ) && 'invalid_form' === sanitize_text_field( wp_unslash( $_GET['tz_fd_builder_saved'] ) ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose a calendar that still exists, then save the portal settings again.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( $calendar_query_error || ( isset( $_GET['tz_fd_builder_saved'] ) && 'database' === TZFD_Request_Value::key( is_string( $_GET['tz_fd_builder_saved'] ) ? sanitize_key( wp_unslash( $_GET['tz_fd_builder_saved'] ) ) : null ) ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Calendars could not be loaded. The database query failed; reload this page after the database is available.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        echo '<img class="tzfd-builder-hero" src="' . esc_url( plugins_url( '../images/ui-graphics/04-Booking-Portal/booking-hero.svg', __FILE__ ) ) . '" alt="" width="1600" height="900" loading="lazy" decoding="async" />';
        if ( ! $calendar_query_error ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_BUILDER ) . '" />';
        wp_nonce_field( self::NONCE_BUILDER );
        self::render_colour_choice( $colour_template );
        echo '<h2>' . esc_html__( 'Booking form', 'titan-zero-trades-front-desk' ) . '</h2><div class="tzfd-builder-choice-grid">';
        self::render_style_choice( 'booking_style', $booking_style, array( 'modern' => __( 'Modern cards', 'titan-zero-trades-front-desk' ), 'classic' => __( 'Classic', 'titan-zero-trades-front-desk' ), 'compact' => __( 'Compact', 'titan-zero-trades-front-desk' ) ) );
        echo '</div><div class="tzfd-builder-toggles">';
        self::render_toggle( 'booking_options[show_heading]', __( 'Show booking heading', 'titan-zero-trades-front-desk' ), $booking_options['show_heading'] );
        self::render_toggle( 'booking_options[show_card]', __( 'Place form in a card', 'titan-zero-trades-front-desk' ), $booking_options['show_card'] );
        self::render_toggle( 'booking_options[compact_spacing]', __( 'Use tighter spacing', 'titan-zero-trades-front-desk' ), $booking_options['compact_spacing'] );
        echo '</div>';
        echo '<h2>' . esc_html__( 'Customer portal', 'titan-zero-trades-front-desk' ) . '</h2><div class="tzfd-builder-choice-grid">';
        self::render_style_choice( 'portal_style', $portal_style, array( 'modern' => __( 'Modern cards', 'titan-zero-trades-front-desk' ), 'classic' => __( 'Classic', 'titan-zero-trades-front-desk' ), 'minimal' => __( 'Minimal', 'titan-zero-trades-front-desk' ) ) );
        echo '</div><div class="tzfd-builder-toggles">';
        self::render_toggle( 'portal_options[show_heading]', __( 'Show portal heading', 'titan-zero-trades-front-desk' ), $portal_options['show_heading'] );
        self::render_toggle( 'portal_options[show_card]', __( 'Place portal in a card', 'titan-zero-trades-front-desk' ), $portal_options['show_card'] );
        self::render_toggle( 'portal_options[compact_spacing]', __( 'Use tighter spacing', 'titan-zero-trades-front-desk' ), $portal_options['compact_spacing'] );
        echo '</div>';
        if ( count( $names ) > 1 ) {
            echo '<p><label for="tzfd-builder-calendar"><strong>' . esc_html__( 'Calendar for booking form', 'titan-zero-trades-front-desk' ) . '</strong></label><br /><select id="tzfd-builder-calendar" name="form_id">';
            foreach ( $names as $form_id => $name ) { echo '<option value="' . esc_attr( (string) $form_id ) . '"' . selected( (int) $current, (int) $form_id, false ) . '>' . esc_html( $name ) . '</option>'; }
            echo '</select></p>';
        }
        echo '<p><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save styles and create pages', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
        }
        echo '<hr /><h2>' . esc_html__( 'Check your pages', 'titan-zero-trades-front-desk' ) . '</h2><p>' . esc_html__( 'After creating the pages, open them here to verify how they look on your site.', 'titan-zero-trades-front-desk' ) . '</p><ul class="tzfd-builder-links">';
        foreach ( array(
            array( $booking_page, __( 'Booking form page', 'titan-zero-trades-front-desk' ) ),
            array( $portal_page, __( 'Customer portal page', 'titan-zero-trades-front-desk' ) ),
            array( $links_page, __( 'Page containing both links', 'titan-zero-trades-front-desk' ) ),
        ) as $page_link ) {
            list( $id, $label ) = $page_link;
            if ( $id > 0 && get_permalink( $id ) ) { echo '<li><strong>' . esc_html( $label ) . ':</strong> <a href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $id ) ) . '</a></li>'; }
        }
        echo '</ul></div>';
    }

    private static function render_style_choice( $name, $selected_value, array $choices ) {
        foreach ( $choices as $key => $label ) {
            echo '<label class="tzfd-builder-style-option"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $key ) . '"' . checked( $selected_value, $key, false ) . ' /><span class="tzfd-builder-swatch tzfd-swatch-' . esc_attr( $key ) . '" aria-hidden="true"></span><strong>' . esc_html( $label ) . '</strong></label>';
        }
    }

    /** Colour template radios, each with its desktop and mobile preview beside the label. */
    private static function render_colour_choice( $selected_value ) {
        $choices = array(
            'light' => __( 'Light', 'titan-zero-trades-front-desk' ),
            'navy'  => __( 'Navy', 'titan-zero-trades-front-desk' ),
            'slate' => __( 'Slate', 'titan-zero-trades-front-desk' ),
        );
        echo '<h2>' . esc_html__( 'Colour template', 'titan-zero-trades-front-desk' ) . '</h2><div class="tzfd-builder-choice-grid tzfd-builder-colour-grid">';
        foreach ( $choices as $key => $label ) {
            $base = plugins_url( '../images/ui-graphics/04-Booking-Portal/Template-Previews/' . $key, __FILE__ );
            $desktop_alt = sprintf(
                /* translators: %s: colour template name, such as Light, Navy or Slate. */
                __( '%s template preview, desktop', 'titan-zero-trades-front-desk' ),
                $label
            );
            $mobile_alt = sprintf(
                /* translators: %s: colour template name, such as Light, Navy or Slate. */
                __( '%s template preview, mobile', 'titan-zero-trades-front-desk' ),
                $label
            );
            echo '<label class="tzfd-builder-style-option tzfd-builder-colour-option"><input type="radio" name="colour_template" value="' . esc_attr( $key ) . '"' . checked( $selected_value, $key, false ) . ' /><strong>' . esc_html( $label ) . '</strong>';
            echo '<span class="tzfd-builder-colour-previews">';
            echo '<img class="tzfd-builder-colour-desktop" src="' . esc_url( $base . '-desktop.webp' ) . '" alt="' . esc_attr( $desktop_alt ) . '" width="1280" height="720" loading="lazy" decoding="async" />';
            echo '<img class="tzfd-builder-colour-mobile" src="' . esc_url( $base . '-mobile.webp' ) . '" alt="' . esc_attr( $mobile_alt ) . '" width="480" height="840" loading="lazy" decoding="async" />';
            echo '</span></label>';
        }
        echo '</div>';
    }

    private static function render_toggle( $name, $label, $checked_value ) {
        echo '<label class="tzfd-builder-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked_value, true, false ) . ' /><span class="tzfd-builder-toggle-track" aria-hidden="true"></span><span>' . esc_html( $label ) . '</span></label>';
    }

    public function handle_save_builder() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_BUILDER ) ) { wp_die( esc_html__( 'The link has expired. Go back and try again.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $names = TZFD_Booking_View::calendar_names();
        if ( ! TZFD_Booking_View::calendar_names_available() ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'tzfd-booking-portal-builder', 'tz_fd_builder_saved' => 'database' ), admin_url( 'admin.php' ) ) );
            exit;
        }
        $form = 0;
        if ( 1 === count( $names ) ) {
            $form = (int) key( $names );
        } elseif ( count( $names ) > 1 ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only passed to TZFD_Request_Value::id(), which accepts digits only; sanitize_text_field() would change inputs such as "5%20" into a valid id.
            $form = TZFD_Request_Value::id( wp_unslash( $_POST['form_id'] ?? '' ) );
            if ( ! isset( $names[ $form ] ) ) {
                wp_safe_redirect( add_query_arg( array( 'page' => 'tzfd-booking-portal-builder', 'tz_fd_builder_saved' => 'invalid_form' ), admin_url( 'admin.php' ) ) );
                exit;
            }
        }
        $booking_style = TZFD_Request_Value::key( is_string( $_POST['booking_style'] ?? null ) ? sanitize_key( wp_unslash( $_POST['booking_style'] ) ) : null, 'modern' );
        $portal_style = TZFD_Request_Value::key( is_string( $_POST['portal_style'] ?? null ) ? sanitize_key( wp_unslash( $_POST['portal_style'] ) ) : null, 'modern' );
        update_option( self::OPT_BOOKING_STYLE, self::style_key( $booking_style, array( 'classic', 'modern', 'compact' ), 'modern' ), false );
        update_option( self::OPT_PORTAL_STYLE, self::style_key( $portal_style, array( 'classic', 'modern', 'minimal' ), 'modern' ), false );
        $colour_template = TZFD_Request_Value::key( is_string( $_POST['colour_template'] ?? null ) ? sanitize_key( wp_unslash( $_POST['colour_template'] ) ) : null, 'light' );
        update_option( self::OPT_COLOUR_TEMPLATE, self::style_key( $colour_template, self::COLOUR_TEMPLATES, 'light' ), false );
        foreach ( array( 'booking_options' => self::OPT_BOOKING_OPTIONS, 'portal_options' => self::OPT_PORTAL_OPTIONS ) as $input => $option ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map_deep sanitizes every nested value; builder_options reads only three allow-listed flags.
            $raw = isset( $_POST[ $input ] ) && is_array( $_POST[ $input ] ) ? map_deep( wp_unslash( $_POST[ $input ] ), 'sanitize_text_field' ) : array();
            update_option( $option, self::builder_options( $raw ), false );
        }
        if ( $form > 0 ) { update_option( self::OPT_BOOKING_FORM, $form, false ); }
        else { delete_option( self::OPT_BOOKING_FORM ); }
        $booking_id = self::ensure_page( self::OPT_BOOKING_PAGE, __( 'Book an appointment', 'titan-zero-trades-front-desk' ), '[' . self::SHORT_BOOKING . ']' );
        $portal_id = self::ensure_page( self::OPT_PORTAL_PAGE, __( 'Customer portal', 'titan-zero-trades-front-desk' ), '[' . self::SHORT_PORTAL . ']' );
        if ( $booking_id && $portal_id ) {
            $links_content = '<div class="tzfd-booking-portal-links"><h2>' . esc_html__( 'Check your booking pages', 'titan-zero-trades-front-desk' ) . '</h2><p>' . esc_html__( 'Use these links to check the customer-facing booking form and portal.', 'titan-zero-trades-front-desk' ) . '</p><ul><li><a href="' . esc_url( get_permalink( $booking_id ) ) . '">' . esc_html__( 'Open booking form', 'titan-zero-trades-front-desk' ) . '</a></li><li><a href="' . esc_url( get_permalink( $portal_id ) ) . '">' . esc_html__( 'Open customer portal', 'titan-zero-trades-front-desk' ) . '</a></li></ul></div>';
            $links_id = self::ensure_page( self::OPT_LINKS_PAGE, __( 'Booking Portal Links', 'titan-zero-trades-front-desk' ), $links_content );
            if ( $links_id ) { wp_update_post( array( 'ID' => $links_id, 'post_content' => $links_content ) ); }
        }
        $status = ( $booking_id && $portal_id ) ? '1' : '0';
        wp_safe_redirect( add_query_arg( array( 'page' => 'tzfd-booking-portal-builder', 'tz_fd_builder_saved' => $status ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Creates each page once. Later saves keep the same page, so links stay valid. */
    private static function ensure_page( $option, $title, $content ) {
        $id = (int) get_option( $option, 0 );
        if ( $id > 0 ) {
            $post = get_post( $id );
            if ( $post && 'trash' !== $post->post_status ) { return $id; }
        }
        $new = wp_insert_post( array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
        if ( is_wp_error( $new ) || ! $new ) { return 0; }
        update_option( $option, (int) $new, false );
        return (int) $new;
    }

    public function handle_create_pages() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value is checked, not stored.
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_CREATE ) ) {
            wp_die( esc_html__( 'The link has expired. Go back and try again.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }

        $names = TZFD_Booking_View::calendar_names();
        if ( ! TZFD_Booking_View::calendar_names_available() ) {
            wp_safe_redirect( add_query_arg( 'tz_fd_portal_pages', 'database', admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS ) ) );
            exit;
        }
        if ( 1 === count( $names ) ) {
            update_option( self::OPT_BOOKING_FORM, (int) key( $names ), false );
        } elseif ( count( $names ) > 1 ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only passed to TZFD_Request_Value::id(), which accepts digits only; sanitize_text_field() would change inputs such as "5%20" into a valid id.
            $form = TZFD_Request_Value::id( wp_unslash( $_POST['form_id'] ?? '' ) );
            if ( ! isset( $names[ $form ] ) ) {
                wp_safe_redirect( add_query_arg( 'tz_fd_portal_pages', 'invalid_form', admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS ) ) );
                exit;
            }
            update_option( self::OPT_BOOKING_FORM, $form, false );
        } else {
            delete_option( self::OPT_BOOKING_FORM );
        }

        self::ensure_page( self::OPT_BOOKING_PAGE, __( 'Book an appointment', 'titan-zero-trades-front-desk' ), '[' . self::SHORT_BOOKING . ']' );
        self::ensure_page( self::OPT_PORTAL_PAGE, __( 'Client portal', 'titan-zero-trades-front-desk' ), '[' . self::SHORT_PORTAL . ']' );

        wp_safe_redirect( add_query_arg( 'tz_fd_portal_pages', 'created', admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS ) ) );
        exit;
    }
}
