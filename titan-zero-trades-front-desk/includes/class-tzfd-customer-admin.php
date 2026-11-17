<?php
/** Native standalone customer administration; scoped to Front Desk's own local customer table. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Customer_Admin {
    public static function boot() { add_action( 'admin_post_tzfd_customer_save', array( __CLASS__, 'save' ) ); }
    public static function save() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_die( esc_html__( 'Invalid customer request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'Not permitted.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( 'tzfd_customer_save', 'tzfd_customer_nonce' );
        if ( ! is_array( $_POST ) || count( $_POST ) > 30 ) {
            wp_die( esc_html__( 'Invalid customer request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 30, 16384 );
        // Reject malformed/oversized values rather than silently coercing arrays or
        // truncating an invalid email into a seemingly valid customer record.
        $raw_id = sanitize_text_field( wp_unslash( $_POST['customer_id'] ?? '0' ) );
        if ( ! is_string( $raw_id ) && ! is_int( $raw_id ) ) {
            wp_die( esc_html__( 'Invalid customer identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $raw_id = (string) $raw_id;
        if ( ! ctype_digit( $raw_id ) || strlen( $raw_id ) > 20 ) {
            wp_die( esc_html__( 'Invalid customer identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        // absint() can saturate an oversized decimal string into a different ID.
        $valid_id = filter_var( $raw_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
        if ( false === $valid_id ) {
            wp_die( esc_html__( 'Invalid customer identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $id = (int) $valid_id;
        $fields = array();
        foreach ( array( 'name' => 190, 'email' => 190, 'phone' => 60 ) as $key => $max_length ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- type and length are checked on the raw field; it is unslashed here and sanitized on the following lines.
            $raw_field = $_POST[ $key ] ?? '';
            if ( ! is_string( $raw_field ) || strlen( $raw_field ) > 4 * $max_length ) {
                wp_die( esc_html__( 'Invalid customer field.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
            $raw = wp_unslash( $raw_field );
            $fields[ $key ] = 'email' === $key ? sanitize_email( $raw ) : sanitize_text_field( $raw );
            if ( strlen( $fields[ $key ] ) > $max_length ) {
                wp_die( esc_html__( 'Customer field is too long.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
            if ( 'email' === $key && '' !== trim( $raw ) && ( ! is_email( $raw ) || ! is_email( $fields[ $key ] ) ) ) {
                wp_die( esc_html__( 'Enter a valid email address.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
        }
        foreach ( array( 'address' => 2000, 'notes' => 5000 ) as $key => $max_length ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- type and length are checked on the raw field; it is unslashed here and sanitized on the following line.
            $raw_field = $_POST[ $key ] ?? '';
            if ( ! is_string( $raw_field ) || strlen( $raw_field ) > 4 * $max_length ) {
                wp_die( esc_html__( 'Invalid customer details.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
            $fields[ $key ] = sanitize_textarea_field( wp_unslash( $raw_field ) );
            if ( strlen( $fields[ $key ] ) > $max_length ) {
                wp_die( esc_html__( 'Customer details are too long.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
        }
        $result = $id ? TZFD_Local_Customers::update( $id, $fields ) : TZFD_Local_Customers::create( $fields );
        $status = $result ? 'saved' : 'error';
        wp_safe_redirect( add_query_arg( array( 'page' => 'tz-customers', 'tzfd_status' => $status ), admin_url( 'admin.php' ) ) );
        exit;
    }
    public static function page() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'Not permitted.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // This read-only selector must not coerce floats, signed numbers or arrays to another customer ID.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
        $customer_selector = isset( $_GET['customer'] ) && is_string( $_GET['customer'] ) ? sanitize_text_field( wp_unslash( $_GET['customer'] ) ) : '';
        $id = preg_match( '/^[1-9][0-9]{0,17}$/D', $customer_selector )
            ? filter_var( $customer_selector, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
        $id = false === $id ? 0 : (int) $id;
        $customer = $id ? TZFD_Local_Customers::find( $id ) : null;
        if ( $id && ! $customer ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Customer not found.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        echo '<div class="wrap"><h1>' . wp_kses( TZFD_UI_Graphics::brand_html( 'customers' ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Customers', 'titan-zero-trades-front-desk' ) . '</h1>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
        if ( isset( $_GET['tzfd_status'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
            $status = is_string( $_GET['tzfd_status'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_status'] ) ) : '';
            echo '<div class="notice ' . ( 'saved' === $status ? 'notice-success' : 'notice-error' ) . '"><p>' . ( 'saved' === $status ? esc_html__( 'Customer saved.', 'titan-zero-trades-front-desk' ) : esc_html__( 'Unable to save customer; check name and email.', 'titan-zero-trades-front-desk' ) ) . '</p></div>';
        }
        echo '<div class="tzfd-card"><h2>' . esc_html( $customer ? __( 'Edit customer', 'titan-zero-trades-front-desk' ) : __( 'Add customer', 'titan-zero-trades-front-desk' ) ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tzfd_customer_save">';
        if ( $customer ) { echo '<input type="hidden" name="customer_id" value="' . absint( $customer['id'] ) . '">'; }
        wp_nonce_field( 'tzfd_customer_save', 'tzfd_customer_nonce' );
        echo '<div class="tzfd-customer-fields tzfd-form-grid">';
        foreach ( array( 'name' => __( 'Name', 'titan-zero-trades-front-desk' ), 'email' => __( 'Email', 'titan-zero-trades-front-desk' ), 'phone' => __( 'Phone', 'titan-zero-trades-front-desk' ) ) as $key => $label ) {
            $type = 'email' === $key ? 'email' : ( 'phone' === $key ? 'tel' : 'text' );
            echo '<div><label for="tzfd-customer-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input class="regular-text" id="tzfd-customer-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $customer[ $key ] ?? '' ) . '" ' . ( 'name' === $key ? 'required' : '' ) . '></div>';
        }
        foreach ( array( 'address' => __( 'Address', 'titan-zero-trades-front-desk' ), 'notes' => __( 'Customer notes', 'titan-zero-trades-front-desk' ) ) as $key => $label ) {
            echo '<div><label for="tzfd-customer-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><textarea class="large-text" id="tzfd-customer-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="3" maxlength="' . ( 'address' === $key ? '2000' : '5000' ) . '">' . esc_textarea( $customer[ $key ] ?? '' ) . '</textarea></div>';
        }
        echo '</div><div class="tzfd-actions"><button class="button button-primary" type="submit">' . esc_html__( 'Save customer', 'titan-zero-trades-front-desk' ) . '</button></div></form></div>';
        echo '<h2>' . esc_html__( 'Existing customers', 'titan-zero-trades-front-desk' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Email', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Phone', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Action', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
        foreach ( TZFD_Local_Customers::search( '', array( 'limit' => 200 ) ) as $row ) {
            echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . wp_kses( TZFD_UI_Graphics::channel_html( 'email', 24 ), TZFD_UI_Graphics::allowed_html() ) . esc_html( $row['email'] ) . '</td><td>' . wp_kses( TZFD_UI_Graphics::channel_html( 'phone', 24 ), TZFD_UI_Graphics::allowed_html() ) . esc_html( $row['phone'] ) . '</td><td><a href="' . esc_url( add_query_arg( array( 'page' => 'tz-customers', 'customer' => (int) $row['id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Edit', 'titan-zero-trades-front-desk' ) . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
TZFD_Customer_Admin::boot();
