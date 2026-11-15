<?php
/** Browser export authorization. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Keep authorization at the export entry point as well as its dispatcher. */
final class TZFD_Admin_Export_Request {
    public static function enforce( $request ) {
        if ( ! is_admin() || ! current_user_can( 'tz_fd_access' ) ) {
            wp_die( esc_html__( 'You do not have permission to export bookings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- method compared exactly to 'GET'; a sanitizer could strip %xx and change the result.
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! is_string( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'Booking exports require a GET request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        // A GET export must not carry POST form data; avoid ambiguous request-source merges.
        if ( ! empty( $_POST ) ) {
            wp_die( esc_html__( 'Ambiguous export request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export; the anonce check below runs before any booking data is read.
        $request = $_GET; // Explicitly bind CSRF verification to the export link's HTTP method.
        if ( ! is_array( $request ) || count( $request ) > 24 ||
             ( isset( $_SERVER['CONTENT_LENGTH'] ) &&
               ( ! is_scalar( $_SERVER['CONTENT_LENGTH'] ) || (int) $_SERVER['CONTENT_LENGTH'] > 0 ) ) ) {
            wp_die( esc_html__( 'Invalid export request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        foreach ( $request as $key => $value ) {
            if ( ! is_string( $key ) || strlen( $key ) > 64 ||
                 ( ! is_string( $value ) && ! is_int( $value ) ) ||
                 strlen( (string) $value ) > 2048 ) {
                wp_die( esc_html__( 'Invalid export filter.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
        }
        $nonce = is_array( $request ) && isset( $request['anonce'] ) && is_string( $request['anonce'] )
            ? sanitize_text_field( wp_unslash( $request['anonce'] ) ) : '';
        if ( '' === $nonce || strlen( $nonce ) > 128 || ! wp_verify_nonce( $nonce, 'tz_fd_actions_booking' ) ) {
            wp_die( esc_html__( 'Your export link has expired. Reload the booking screen and try again.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
    }
}
