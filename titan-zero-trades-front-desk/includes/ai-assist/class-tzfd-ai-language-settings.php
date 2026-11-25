<?php
/**
 * Administrator screen for the configured reply languages (TZFD_AI_Multilingual::OPTION).
 *
 * - Only administrators (manage_options) can open the screen or save; the save is refused again inside
 *   TZFD_AI_Multilingual::save_configured_locales().
 * - The form posts to admin-post.php with a nonce; the nonce and capability are checked before anything is written.
 * - The stored list is site-wide (one option, not per company). Nothing on this screen calls a hosted service.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-multilingual.php';

final class TZFD_AI_Language_Settings {
    const PAGE   = 'tzfd-ai-languages';
    const ACTION = 'tzfd_ai_save_languages';
    const NONCE  = 'tzfd_ai_save_languages';
    const FIELD  = 'tzfd_ai_languages';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 14 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
    }

    public static function add_page() {
        add_submenu_page(
            'tzfd',
            __( 'Reply languages', 'titan-zero-trades-front-desk' ),
            __( 'Reply languages', 'titan-zero-trades-front-desk' ),
            TZFD_AI_Multilingual::SAVE_CAPABILITY,
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    /** Splits the textarea into candidate tags. Invalid tags are dropped by clean_locales(). */
    public static function parse_input( $raw ) {
        $raw = is_string( $raw ) ? $raw : '';
        return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ), 'strlen' ) );
    }

    public static function handle_save() {
        if ( ! current_user_can( TZFD_AI_Multilingual::SAVE_CAPABILITY ) ) {
            wp_die( esc_html__( 'You are not allowed to change reply languages.', 'titan-zero-trades-front-desk' ), 403 );
        }
        check_admin_referer( self::NONCE );
        $raw    = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
        $tags   = self::parse_input( $raw );
        if ( count( $tags ) > 0 && count( TZFD_AI_Multilingual::clean_locales( $tags ) ) === 0 ) {
            $status = 'invalid'; // Every tag was rejected: keep the stored list unchanged.
        } else {
            $saved  = TZFD_AI_Multilingual::save_configured_locales( $tags );
            $status = ( false === $saved ) ? 'forbidden' : 'saved';
        }
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tzfd_langs' => $status ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( TZFD_AI_Multilingual::SAVE_CAPABILITY ) ) {
            wp_die( esc_html__( 'You are not allowed to view this page.', 'titan-zero-trades-front-desk' ), 403 );
        }
        $status = isset( $_GET['tzfd_langs'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_langs'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of a status flag only.
        $locales = TZFD_AI_Multilingual::configured_locales();

        echo '<div class="wrap tzfd-ai-languages">';
        echo '<h1>' . esc_html__( 'Reply languages', 'titan-zero-trades-front-desk' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'List the languages customers may reply in, using tags such as en-AU, es or fr. The first tag is the default. Up to 5 tags. Translations are used only where they have been reviewed.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( 'saved' === $status ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reply languages saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        } elseif ( 'invalid' === $status ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'None of the tags were valid. The list was not changed.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        } elseif ( 'forbidden' === $status ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'You are not allowed to change reply languages.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
        wp_nonce_field( self::NONCE );
        echo '<p><label for="' . esc_attr( self::FIELD ) . '">' . esc_html__( 'Language tags', 'titan-zero-trades-front-desk' ) . '</label></p>';
        echo '<textarea id="' . esc_attr( self::FIELD ) . '" name="' . esc_attr( self::FIELD ) . '" rows="3" cols="40" class="large-text code">' . esc_textarea( implode( ', ', $locales ) ) . '</textarea>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save reply languages', 'titan-zero-trades-front-desk' ) . '</button></p>';
        echo '</form></div>';
    }
}
