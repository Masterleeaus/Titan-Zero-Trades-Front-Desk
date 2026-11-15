<?php
/** Customer-scoped site discovery endpoint for Front Desk administrators. Standalone: it checks the request and refuses it. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-request-value.php';

if ( ! class_exists( 'TZFD_Workspace_Site_Ajax', false ) ) {
final class TZFD_Workspace_Site_Ajax {
    public static function register() {
        add_action( 'wp_ajax_tzfd_workspace_customer_sites', array( __CLASS__, 'handle' ) );
    }
    public static function handle() {
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- structure and size check only; the method is compared exactly, and no field value is used before the nonce check below.
        if ( ! TZFD_Admin_Write_Guard::valid( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ), $_POST, wp_unslash( $_SERVER['CONTENT_LENGTH'] ?? null ), 8, 4096 ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid or oversized request.', 'titan-zero-trades-front-desk' ) ), 400 );
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- method compared exactly to 'POST'; a sanitizer could strip %xx and change the result.
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) {
            wp_send_json_error( array( 'message' => __( 'POST request required.', 'titan-zero-trades-front-desk' ) ), 405 );
            return;
        }
        if ( ! is_user_logged_in() || ! current_user_can( 'tz_fd_access' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'titan-zero-trades-front-desk' ) ), 403 );
            return;
        }
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'tzfd_workspace_customer_sites' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request token.', 'titan-zero-trades-front-desk' ) ), 403 );
            return;
        }
        // Front Desk runs standalone and has no Workspace site source, so no sites can be listed.
        wp_send_json_error( array( 'message' => __( 'Customer or Workspace unavailable.', 'titan-zero-trades-front-desk' ) ), 400 );
    }
}
TZFD_Workspace_Site_Ajax::register();
}
