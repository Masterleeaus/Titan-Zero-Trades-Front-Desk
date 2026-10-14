<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Two reminders for administrators on Front Desk screens:
 *  - the AI key reminder, while no AI key is saved (the AI chat and AI quotes stay off);
 *  - the booking speed reminder, while the Cache Booking Availability add-on is off.
 * Each can be dismissed for 30 days, per user. Nothing is shown to other users.
 */
final class TZFD_Admin_Notices {
    const CACHE_ADDON_ID = 'tzfd-Cache';
    const META_PREFIX    = 'tzfd_notice_dismissed_';
    const SNOOZE_DAYS    = 30;

    public static function register() {
        add_action( 'admin_init', array( __CLASS__, 'handle_dismiss' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render' ) );
    }

    public static function is_front_desk_screen() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current screen.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return 0 === strpos( $page, 'tzfd' ) || 0 === strpos( $page, 'titan-zero-trades-front-desk' );
    }

    public static function is_snoozed( $key, $user_id ) {
        $when = (int) get_user_meta( $user_id, self::META_PREFIX . $key, true );
        return $when > 0 && ( time() - $when ) < self::SNOOZE_DAYS * DAY_IN_SECONDS;
    }

    public static function cache_addon_on() {
        $active = get_option( 'tzfd_addons_active_list', array() );
        return is_array( $active ) && in_array( self::CACHE_ADDON_ID, $active, true );
    }

    /** Keys of the reminders that should show now for this user. */
    public static function pending( $user_id ) {
        $out = array();
        if ( ! class_exists( 'TZFD_Addon_Support' ) ) { return $out; }
        if ( ! TZFD_Addon_Support::ai_configured() && ! self::is_snoozed( 'ai_key', $user_id ) ) {
            $out[] = 'ai_key';
        }
        if ( ! self::cache_addon_on() && ! self::is_snoozed( 'speed', $user_id ) ) {
            $out[] = 'speed';
        }
        return $out;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_front_desk_screen() ) { return; }
        $user_id = get_current_user_id();
        foreach ( self::pending( $user_id ) as $key ) {
            if ( 'ai_key' === $key ) {
                $title = __( 'The AI booking assistant is off', 'titan-zero-trades-front-desk' );
                $text  = __( 'The AI chat and AI quotes stay off until AI is set up. Under Settings, then AI, choose a provider and paste its key, or use a local model server such as Ollama, which needs no key.', 'titan-zero-trades-front-desk' );
                $link  = admin_url( 'admin.php?page=tzfd_settings#ai' );
                $label = __( 'Add an AI key', 'titan-zero-trades-front-desk' );
            } else {
                $title = __( 'Booking availability can be cached', 'titan-zero-trades-front-desk' );
                $text  = __( 'Turn on Cache Booking Availability under Addons. It keeps a copy of the availability display for busy sites, and it clears itself when bookings change, so customers still see current times.', 'titan-zero-trades-front-desk' );
                $link  = admin_url( 'admin.php?page=tzfd_addons' );
                $label = __( 'Open Addons', 'titan-zero-trades-front-desk' );
            }
            $dismiss = wp_nonce_url(
                add_query_arg( array( 'tzfd_notice_dismiss' => $key ) ),
                'tzfd_dismiss_' . $key
            );
            // Title, text and buttons are separate blocks, so the buttons sit on their own row.
            echo '<div class="notice notice-info tzfd-notice">';
            echo '<p class="tzfd-notice-title"><strong>' . esc_html( $title ) . '</strong></p>';
            echo '<p>' . esc_html( $text ) . '</p>';
            echo '<div class="tzfd-notice-actions">';
            echo '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
            echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Remind me in 30 days', 'titan-zero-trades-front-desk' ) . '</a>';
            echo '</div></div>';
        }
    }

    public static function handle_dismiss() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below with wp_verify_nonce before any change.
        if ( ! isset( $_GET['tzfd_notice_dismiss'] ) ) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
        $key = sanitize_key( wp_unslash( $_GET['tzfd_notice_dismiss'] ) );
        if ( ! in_array( $key, array( 'ai_key', 'speed' ), true ) || ! is_user_logged_in() ) { return; }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tzfd_dismiss_' . $key ) ) { return; }
        update_user_meta( get_current_user_id(), self::META_PREFIX . $key, time() );
        wp_safe_redirect( remove_query_arg( array( 'tzfd_notice_dismiss', '_wpnonce' ) ) );
        exit;
    }
}
