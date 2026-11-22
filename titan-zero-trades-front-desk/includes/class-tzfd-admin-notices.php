<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/../includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/class-tzfd-request-value.php';


/**
 * One reminder for administrators on Front Desk screens:
 *  - the booking speed reminder, while the Cache Booking Availability add-on is off.
 * The free build has no AI provider connection, so no AI key reminder exists.
 * Each can be dismissed for 30 days, per user. Nothing is shown to other users.
 */
final class TZFD_Admin_Notices {
    const CACHE_ADDON_ID = 'tzfd-Cache';
    const META_PREFIX    = 'tz_fd_notice_dismissed_';
    const SNOOZE_DAYS    = 30;

    public static function register() {
        add_action( 'admin_post_tzfd_dismiss_notice', array( __CLASS__, 'handle_dismiss' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render' ) );
    }

    public static function is_front_desk_screen() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current screen.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // The Get started screen shows no admin notice (issue #366).
        if ( class_exists( 'TZFD_Setup_Page' ) && TZFD_Setup_Page::is_current_screen() ) { return false; }
        return 0 === strpos( $page, 'tzfd' ) || 0 === strpos( $page, 'titan-zero-trades-front-desk' );
    }

    public static function is_snoozed( $key, $user_id ) {
        $when = (int) get_user_meta( $user_id, self::META_PREFIX . $key, true );
        return $when > 0 && ( time() - $when ) < self::SNOOZE_DAYS * DAY_IN_SECONDS;
    }

    public static function cache_addon_on() {
        $active = TZFD_Scoped_Store::get_for_boot( 'tz_fd_addons_active_list', array() );
        return is_array( $active ) && in_array( self::CACHE_ADDON_ID, $active, true );
    }

    /** Keys of the reminders that should show now for this user. */
    public static function pending( $user_id ) {
        $out = array();
        if ( ! class_exists( 'TZFD_Addon_Support' ) ) { return $out; }
        if ( ! self::cache_addon_on() && ! self::is_snoozed( 'speed', $user_id ) ) {
            $out[] = 'speed';
        }
        return $out;
    }

    public static function render() {
        if ( ! current_user_can( 'tz_fd_access' ) || ! self::is_front_desk_screen() ) { return; }
        $user_id = get_current_user_id();
        foreach ( self::pending( $user_id ) as $key ) {
            $title = __( 'Booking availability can be cached', 'titan-zero-trades-front-desk' );
            $text  = __( 'Turn on Cache Booking Availability under Addons. It keeps a copy of the availability display for busy sites, and it clears itself when bookings change, so customers still see current times.', 'titan-zero-trades-front-desk' );
            $link  = admin_url( 'admin.php?page=tz_fd_addons' );
            $label = __( 'Open Addons', 'titan-zero-trades-front-desk' );
            // Build a fresh URL rather than propagating arbitrary GET parameters
            // (which can contain booking identifiers or third-party tokens).
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
            $screen = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            if ( 0 !== strpos( $screen, 'tzfd' ) && 0 !== strpos( $screen, 'titan-zero-trades-front-desk' ) ) {
                $screen = 'tzfd';
            }
            // Mutations must not be triggered by GET links or browser prefetch.
            $dismiss = admin_url( 'admin-post.php' );
            // Title, text and buttons are separate blocks, so the buttons sit on their own row.
            echo '<div class="notice notice-info tzfd-notice">';
            echo '<p class="tzfd-notice-title"><strong>' . esc_html( $title ) . '</strong></p>';
            echo '<p>' . esc_html( $text ) . '</p>';
            echo '<div class="tzfd-notice-actions">';
            echo '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
            echo '<form method="post" action="' . esc_url( $dismiss ) . '" style="display:inline">';
            echo '<input type="hidden" name="action" value="tzfd_dismiss_notice">';
            echo '<input type="hidden" name="tz_fd_notice_dismiss" value="' . esc_attr( $key ) . '">';
            echo '<input type="hidden" name="page" value="' . esc_attr( $screen ) . '">';
            wp_nonce_field( 'tz_fd_dismiss_' . $key, '_wpnonce' );
            echo '<button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Remind me in 30 days', 'titan-zero-trades-front-desk' ) . '</button></form>';
            echo '</div></div>';
        }
    }

    public static function handle_dismiss() {
        // Method, login and capability are checked before any notice field is read.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- method compared exactly to 'POST'; a sanitizer could strip %xx and change the result.
        if ( 'POST' !== wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
            ! is_user_logged_in() || ! current_user_can( 'tz_fd_access' ) ) {
            wp_die( esc_html__( 'Invalid notice request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        // The nonce action embeds the notice key, so verify the nonce against each known key before reading any notice field.
        $known    = array( 'speed' );
        $verified = false;
        foreach ( $known as $candidate ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) ), 'tz_fd_dismiss_' . $candidate ) ) {
                $verified = true;
                break;
            }
        }
        if ( ! $verified ) {
            // No known key matches this nonce, so this fails with the standard expired-link response.
            check_admin_referer( 'tz_fd_dismiss_' . $known[0], '_wpnonce' );
        }
        if ( ! is_array( $_POST ) || count( $_POST ) > 8 ||
            ! isset( $_POST['tz_fd_notice_dismiss'] ) || ! is_string( $_POST['tz_fd_notice_dismiss'] ) ) {
            wp_die( esc_html__( 'Invalid notice request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        $key = sanitize_key( wp_unslash( $_POST['tz_fd_notice_dismiss'] ) );
        if ( ! in_array( $key, $known, true ) ) {
            wp_die( esc_html__( 'Unknown notice.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        check_admin_referer( 'tz_fd_dismiss_' . $key, '_wpnonce' );
        update_user_meta( get_current_user_id(), self::META_PREFIX . $key, time() );
        $screen = isset( $_POST['page'] ) && is_string( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : '';
        if ( 0 !== strpos( $screen, 'tzfd' ) && 0 !== strpos( $screen, 'titan-zero-trades-front-desk' ) ) {
            $screen = 'tzfd';
        }
        wp_safe_redirect( add_query_arg( 'page', $screen, admin_url( 'admin.php' ) ) );
        exit;
    }
}
