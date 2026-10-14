<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables. Values are bound with prepare() and %i/%d/%s placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
 * the same signed cancel and reschedule links used in booking emails, and their quotes.
 *
 * The portal never reveals whether an email address has records: the reply is the same either way.
 */
final class TZFD_Client_Portal {
    const OPT_BOOKING_PAGE = 'tzfd_booking_page_id';
    const OPT_PORTAL_PAGE  = 'tzfd_client_portal_page_id';
    const OPT_BOOKING_FORM = 'tzfd_booking_page_form_id';

    const SHORT_BOOKING = 'tzfd_booking_page';
    const SHORT_PORTAL  = 'tzfd_client_portal';

    const PARAM_LOGIN  = 'tzfd_portal_login';
    const PARAM_LOGOUT = 'tzfd_portal_logout';
    const PARAM_NOTICE = 'tzfd_portal_notice';

    const COOKIE         = 'tzfd_portal_session';
    const LINK_PREFIX    = 'tzfd_portal_link_';
    const SESSION_PREFIX = 'tzfd_portal_session_';
    const THROTTLE_PREFIX = 'tzfd_portal_throttle_';
    const LINK_TTL       = 1200;   // 20 minutes
    const SESSION_TTL    = 7200;   // 2 hours
    const THROTTLE_TTL   = 60;     // one sign-in email per address per minute

    const ACTION_REQUEST = 'tzfd_portal_request';
    const ACTION_CREATE  = 'tzfd_portal_create_pages';
    const NONCE_REQUEST  = 'tzfd_portal_request';
    const NONCE_CREATE   = 'tzfd_portal_create';
    const NONCE_LOGOUT   = 'tzfd_portal_logout';

    const MAX_ROWS = 200;

    public function register() {
        add_shortcode( self::SHORT_BOOKING, array( $this, 'booking_shortcode' ) );
        add_shortcode( self::SHORT_PORTAL, array( $this, 'portal_shortcode' ) );
        add_action( 'template_redirect', array( $this, 'handle_login_link' ) );
        add_action( 'admin_post_' . self::ACTION_REQUEST, array( $this, 'handle_request' ) );
        add_action( 'admin_post_nopriv_' . self::ACTION_REQUEST, array( $this, 'handle_request' ) );
        add_action( 'admin_post_' . self::ACTION_CREATE, array( $this, 'handle_create_pages' ) );
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

    /** Booking links shown in the portal: cancel and reschedule, the same signed links the emails carry. */
    public static function booking_links( $row, $email ) {
        $secret = wp_salt( 'auth' );
        $links  = array();
        $id     = (int) $row->id;

        $cancel = TZFD_Customer_Cancellation::create_token( $id, $email, $secret );
        if ( '' !== $cancel ) {
            $links['cancel'] = add_query_arg(
                array( 'action' => TZFD_Customer_Cancellation::ACTION, 'booking_id' => $id, 'token' => $cancel ),
                admin_url( 'admin-post.php' )
            );
        }

        $version = TZFD_Customer_Rescheduling::version( $row );
        $move    = TZFD_Customer_Rescheduling::token( $id, (int) $row->formid, $email, $version, $secret );
        if ( '' !== $move ) {
            $links['reschedule'] = add_query_arg(
                array(
                    'action'     => TZFD_Customer_Rescheduling::ACTION,
                    'booking_id' => $id,
                    'form_id'    => (int) $row->formid,
                    'version'    => $version,
                    'token'      => $move,
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
            $wpdb->prefix . 'tzfd_forms',
            (int) $form_id
        ) );
        return is_object( $row ) && 'true' === (string) $row->cu_enable_copy_to_user;
    }

    /** Bookings (appointments) and quotes that belong to one email address. */
    public static function records_for( $email ) {
        global $wpdb;
        $out   = array( 'bookings' => array(), 'quotes' => array() );
        $email = TZFD_Customer_Cancellation::normalize_email( $email );
        if ( '' === $email ) { return $out; }

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT m.id, m.formid, m.notifyto, m.data, m.posted_data FROM %i m WHERE LOWER(m.notifyto) = %s ORDER BY m.id DESC LIMIT %d',
            $wpdb->prefix . TZFD_Bookings::TABLE,
            $email,
            self::MAX_ROWS
        ) );
        $names = TZFD_Booking_View::calendar_names();
        $today = TZFD_Booking_View::today();

        foreach ( (array) $rows as $row ) {
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

        if ( class_exists( 'TZFD_Quote' ) ) {
            $quotes = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, token, reference, status, total, currency FROM %i
                 WHERE LOWER(customer_email) = %s AND status IN ('sent','approved','declined','expired')
                 ORDER BY id DESC LIMIT %d",
                $wpdb->prefix . 'tzfd_quotes',
                $email,
                self::MAX_ROWS
            ) );
            foreach ( (array) $quotes as $quote ) {
                $out['quotes'][] = array(
                    'reference' => (string) $quote->reference,
                    'status'    => self::quote_status_label( (string) $quote->status ),
                    'total'     => class_exists( 'TZFD_Money' ) ? TZFD_Money::format( (float) $quote->total ) : number_format( (float) $quote->total, 2 ) . ' ' . (string) $quote->currency,
                    'url'       => TZFD_Quote::public_url( (string) $quote->token ),
                );
            }
        }
        return $out;
    }

    public static function quote_status_label( $status ) {
        $map = array(
            'sent'     => __( 'Waiting for your reply', 'titan-zero-trades-front-desk' ),
            'approved' => __( 'Accepted', 'titan-zero-trades-front-desk' ),
            'declined' => __( 'Declined', 'titan-zero-trades-front-desk' ),
            'expired'  => __( 'Expired', 'titan-zero-trades-front-desk' ),
        );
        return $map[ $status ] ?? $status;
    }

    /** Calendar used by the booking page: the saved choice, else the first calendar. */
    public static function booking_form_id() {
        global $wpdb;
        $saved = (int) get_option( self::OPT_BOOKING_FORM, 0 );
        if ( $saved > 0 ) { return $saved; }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id ASC LIMIT 1', $wpdb->prefix . 'tzfd_forms' ) );
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
        $session = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
        if ( ! self::is_token( $session ) ) { return ''; }
        $email = get_transient( self::token_key( self::SESSION_PREFIX, $session ) );
        return is_string( $email ) ? $email : '';
    }

    /** Opens the portal from a one-time sign-in link, then sends the client to the clean portal address. */
    public function handle_login_link() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the presence of the flag is read here; the nonce below is what authorises the sign-out.
        if ( isset( $_GET[ self::PARAM_LOGOUT ] ) ) {
            // Signing out changes state, so the link must carry a nonce. Without one, nothing changes.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value is checked, not stored.
            $logout_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( wp_verify_nonce( $logout_nonce, self::NONCE_LOGOUT ) ) {
                $session = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
                if ( self::is_token( $session ) ) { delete_transient( self::token_key( self::SESSION_PREFIX, $session ) ); }
                self::set_session_cookie( '', time() - HOUR_IN_SECONDS );
            }
            wp_safe_redirect( self::portal_url() );
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The sign-in link token is the credential, so no nonce applies. Read-only.
        if ( ! isset( $_GET[ self::PARAM_LOGIN ] ) ) { return; }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The sign-in link token is the credential, so no nonce applies. Read-only.
        $token = sanitize_text_field( wp_unslash( $_GET[ self::PARAM_LOGIN ] ) );
        $email = self::is_token( $token ) ? get_transient( self::token_key( self::LINK_PREFIX, $token ) ) : false;
        if ( ! is_string( $email ) || '' === $email ) {
            wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'expired', self::portal_url() ) );
            exit;
        }

        delete_transient( self::token_key( self::LINK_PREFIX, $token ) );
        $session = wp_generate_password( 40, false, false );
        set_transient( self::token_key( self::SESSION_PREFIX, $session ), $email, self::SESSION_TTL );
        self::set_session_cookie( $session, time() + self::SESSION_TTL );
        wp_safe_redirect( self::portal_url() );
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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value is checked, not stored.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_REQUEST ) ) {
            wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'invalid', self::portal_url() ) );
            exit;
        }

        $raw_email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $email     = TZFD_Customer_Cancellation::normalize_email( $raw_email );
        if ( '' === $email ) {
            wp_safe_redirect( add_query_arg( self::PARAM_NOTICE, 'invalid', self::portal_url() ) );
            exit;
        }

        $throttle = self::token_key( self::THROTTLE_PREFIX, md5( $email ) );
        if ( false === get_transient( $throttle ) ) {
            set_transient( $throttle, 1, self::THROTTLE_TTL );
            $records = self::records_for( $email );
            if ( ! empty( $records['bookings'] ) || ! empty( $records['quotes'] ) ) {
                $token = wp_generate_password( 40, false, false );
                set_transient( self::token_key( self::LINK_PREFIX, $token ), $email, self::LINK_TTL );
                $link  = add_query_arg( self::PARAM_LOGIN, $token, self::portal_url() );
                $site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
                $body  = sprintf(
                    /* translators: 1: site name, 2: sign-in link, 3: minutes the link lasts. */
                    __( "Open your bookings and quotes at %1\$s:\n\n%2\$s\n\nThis link works once and expires in %3\$d minutes. If you did not ask for it, you can ignore this email.", 'titan-zero-trades-front-desk' ),
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
        if ( $form <= 0 ) {
            return '<p>' . esc_html__( 'Online booking is not set up yet.', 'titan-zero-trades-front-desk' ) . '</p>';
        }
        return do_shortcode( '[TZFD_FRONT_DESK id="' . $form . '"]' );
    }

    public function portal_shortcode( $atts ) {
        $email = self::session_email();
        ob_start();
        echo '<div class="tzfd-portal">';
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
        $key = isset( $_GET[ self::PARAM_NOTICE ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM_NOTICE ] ) ) : '';
        $map = array(
            'sent'    => __( 'If this email address has bookings or quotes, a sign-in link is on its way. Check your inbox.', 'titan-zero-trades-front-desk' ),
            'invalid' => __( 'Please enter a valid email address.', 'titan-zero-trades-front-desk' ),
            'expired' => __( 'That sign-in link has expired or was already used. Please request a new one.', 'titan-zero-trades-front-desk' ),
        );
        return $map[ $key ] ?? '';
    }

    private static function render_login() {
        $notice = self::notice_text();
        echo '<h2>' . esc_html__( 'Client portal', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p>' . esc_html__( 'See your bookings and quotes. Enter the email address you booked with and we will send you a sign-in link.', 'titan-zero-trades-front-desk' ) . '</p>';
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
        $logout   = wp_nonce_url( add_query_arg( self::PARAM_LOGOUT, '1', self::portal_url() ), self::NONCE_LOGOUT );

        echo '<div class="tzfd-portal-head"><p>' . sprintf(
            /* translators: %s: email address. */
            esc_html__( 'Signed in as %s', 'titan-zero-trades-front-desk' ),
            '<strong>' . esc_html( $email ) . '</strong>'
        ) . ' &middot; <a href="' . esc_url( $logout ) . '">' . esc_html__( 'Sign out', 'titan-zero-trades-front-desk' ) . '</a></p></div>';

        echo '<h2>' . esc_html__( 'Upcoming bookings', 'titan-zero-trades-front-desk' ) . '</h2>';
        self::render_bookings( array_values( array_filter( $records['bookings'], function ( $b ) { return $b['upcoming']; } ) ), true );

        $past = array_values( array_filter( $records['bookings'], function ( $b ) { return ! $b['upcoming']; } ) );
        if ( ! empty( $past ) ) {
            echo '<h2>' . esc_html__( 'Past bookings', 'titan-zero-trades-front-desk' ) . '</h2>';
            self::render_bookings( array_slice( array_reverse( $past ), 0, 20 ), false );
        }

        echo '<h2>' . esc_html__( 'Quotes', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( empty( $records['quotes'] ) ) {
            echo '<p>' . esc_html__( 'No quotes yet.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            echo '<ul class="tzfd-portal-list">';
            foreach ( $records['quotes'] as $quote ) {
                echo '<li><strong>' . esc_html( $quote['reference'] ) . '</strong> &middot; ' . esc_html( $quote['total'] ) . ' &middot; ' . esc_html( $quote['status'] );
                echo ' &middot; <a href="' . esc_url( $quote['url'] ) . '">' . esc_html__( 'View quote', 'titan-zero-trades-front-desk' ) . '</a></li>';
            }
            echo '</ul>';
        }
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
        if ( ! current_user_can( 'edit_pages' ) ) { return; }
        $names   = TZFD_Booking_View::calendar_names();
        $booking = (int) get_option( self::OPT_BOOKING_PAGE, 0 );
        $portal  = (int) get_option( self::OPT_PORTAL_PAGE, 0 );
        $current = self::booking_form_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a save, no change is made.
        $created = isset( $_GET['tzfd_portal_pages'] ) && 'created' === sanitize_key( wp_unslash( $_GET['tzfd_portal_pages'] ) );

        echo '<div class="tzfd-set-card">';
        echo '<h2>' . esc_html__( 'Booking page and client portal', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p>' . esc_html__( 'Creates a booking page and a client portal as normal pages, so they use your theme\'s header, footer and fonts. Clients sign in with their email address to see and change their bookings and quotes.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( $created ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Pages are ready.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( $booking > 0 && get_permalink( $booking ) ) {
            echo '<p>' . esc_html__( 'Booking page:', 'titan-zero-trades-front-desk' ) . ' <a href="' . esc_url( get_permalink( $booking ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $booking ) ) . '</a></p>';
        }
        if ( $portal > 0 && get_permalink( $portal ) ) {
            echo '<p>' . esc_html__( 'Client portal:', 'titan-zero-trades-front-desk' ) . ' <a href="' . esc_url( get_permalink( $portal ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $portal ) ) . '</a></p>';
        }

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
        echo '</div>';
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
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value is checked, not stored.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_CREATE ) ) {
            wp_die( esc_html__( 'The link has expired. Go back and try again.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }

        $form = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
        if ( $form > 0 ) { update_option( self::OPT_BOOKING_FORM, $form, false ); }

        self::ensure_page( self::OPT_BOOKING_PAGE, __( 'Book an appointment', 'titan-zero-trades-front-desk' ), '[' . self::SHORT_BOOKING . ']' );
        self::ensure_page( self::OPT_PORTAL_PAGE, __( 'Client portal', 'titan-zero-trades-front-desk' ), '[' . self::SHORT_PORTAL . ']' );

        wp_safe_redirect( add_query_arg( 'tzfd_portal_pages', 'created', admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS ) ) );
        exit;
    }
}
