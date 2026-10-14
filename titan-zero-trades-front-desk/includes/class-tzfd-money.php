<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Currency and business details. Every money amount in Front Desk (dashboard totals, payments,
 * quotes) is shown with the format saved here. Settings tab: Business and money.
 */
final class TZFD_Money {
    const OPTION   = 'titan_zero_front_desk_money_v1';
    const BUSINESS = 'titan_zero_front_desk_business_v1';
    const NONCE    = 'tzfd_money_save';
    const PAGE     = 'tzfd-business';

    public static function register() {
        add_action( 'admin_post_tzfd_money_save', array( __CLASS__, 'handle_save' ) );
    }

    public static function defaults() {
        return array( 'code' => 'AUD', 'symbol' => '$', 'position' => 'before', 'decimals' => 2 );
    }

    /** Saved currency settings, with defaults for anything missing. */
    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() );
    }

    public static function currency_code() {
        return self::settings()['code'];
    }

    /** Amount with the saved symbol, position and decimals: "$1,234.50" or "1,234.50 kr". */
    public static function format( $amount, $settings = null ) {
        $s = is_array( $settings ) ? array_merge( self::defaults(), $settings ) : self::settings();
        $number = number_format( (float) $amount, (int) $s['decimals'], '.', ',' );
        return 'after' === $s['position'] ? $number . ' ' . $s['symbol'] : $s['symbol'] . $number;
    }

    /** Reads a typed amount: "30", "30.5", "$1,200.00". Returns null when it is not a number. */
    public static function parse_amount( $text ) {
        $text = trim( (string) $text );
        if ( '' === $text ) { return null; }
        $clean = preg_replace( '/[^0-9.\-]/', '', $text );
        if ( '' === $clean || ! is_numeric( $clean ) ) { return null; }
        return round( (float) $clean, 2 );
    }

    /** Clean currency settings from the form. */
    public static function sanitize_settings( array $in ) {
        $code     = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['code'] ?? '' ) ) );
        $symbol   = mb_substr( trim( sanitize_text_field( (string) ( $in['symbol'] ?? '' ) ) ), 0, 4 );
        $position = ( $in['position'] ?? '' ) === 'after' ? 'after' : 'before';
        $decimals = (int) ( $in['decimals'] ?? 2 );
        return array(
            'code'     => 3 === strlen( $code ) ? $code : 'AUD',
            'symbol'   => '' !== $symbol ? $symbol : '$',
            'position' => $position,
            'decimals' => max( 0, min( 2, $decimals ) ),
        );
    }

    /** Business details, all optional. */
    public static function business() {
        $saved = get_option( self::BUSINESS, array() );
        $saved = is_array( $saved ) ? $saved : array();
        $out   = array();
        foreach ( array( 'name', 'phone', 'email', 'address', 'tax_number', 'website' ) as $key ) {
            $out[ $key ] = isset( $saved[ $key ] ) ? (string) $saved[ $key ] : '';
        }
        return $out;
    }

    public static function sanitize_business( array $in ) {
        $out = array();
        $out['name']       = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 120 );
        $out['phone']      = mb_substr( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ), 0, 40 );
        $email             = sanitize_email( (string) ( $in['email'] ?? '' ) );
        $out['email']      = is_email( $email ) ? $email : '';
        $out['address']    = mb_substr( sanitize_textarea_field( (string) ( $in['address'] ?? '' ) ), 0, 300 );
        $out['tax_number'] = mb_substr( sanitize_text_field( (string) ( $in['tax_number'] ?? '' ) ), 0, 40 );
        $website           = esc_url_raw( (string) ( $in['website'] ?? '' ), array( 'http', 'https' ) );
        $out['website']    = mb_substr( $website, 0, 200 );
        return $out;
    }

    /** Admin-post handler for both forms on the Business and money page. */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access is required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::NONCE, 'tzfd_money_nonce' );
        // Each value is unslashed and cleaned here, then sanitize_settings() / sanitize_business() apply the rule for each field.
        // Money values are plain text. Business values use wp_strip_all_tags() so line breaks (address) and
        // percent sequences (website URL) survive for the field-specific rules.
        $money_in    = isset( $_POST['money'] ) && is_array( $_POST['money'] ) ? map_deep( wp_unslash( $_POST['money'] ), 'sanitize_text_field' ) : array();
        $business_in = isset( $_POST['business'] ) && is_array( $_POST['business'] ) ? map_deep( wp_unslash( $_POST['business'] ), 'wp_strip_all_tags' ) : array();
        $money       = self::sanitize_settings( $money_in );
        $business    = self::sanitize_business( $business_in );
        update_option( self::OPTION, $money, false );
        update_option( self::BUSINESS, $business, false );
        if ( TZFD_Admin_Menu::posted_from_settings() ) {
            wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS ) ) . '#general' );
            exit;
        }
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Business and money page, a tab of Settings. */
    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access is required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        TZFD_Admin_Menu::render_settings_tabs( 'business' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
        $saved = isset( $_GET['saved'] );
        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-business"><h1>' . esc_html__( 'Business and money', 'titan-zero-trades-front-desk' ) . '</h1>';
        echo '<p class="tzfd-lead">' . esc_html__( 'Your business details and the currency every amount is shown in. Changes apply straight away.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( $saved ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        self::render_form( false );
        echo '</div>';
    }

    /** The currency and business form. Settings embeds it with $in_settings true, so it returns to Settings after saving. */
    public static function render_form( $in_settings = false ) {
        $m = self::settings();
        $b = self::business();

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tzfd_money_save" />';
        if ( $in_settings ) { echo '<input type="hidden" name="return_to" value="settings" />'; }
        wp_nonce_field( self::NONCE, 'tzfd_money_nonce' );

        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Currency', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<div class="tzfd-form-grid">';
        self::field( 'money[code]', __( 'Currency code', 'titan-zero-trades-front-desk' ), $m['code'], 'text', __( 'Three letters, for example AUD, USD, GBP or NZD. Used on quotes and reports.', 'titan-zero-trades-front-desk' ), 3 );
        self::field( 'money[symbol]', __( 'Symbol', 'titan-zero-trades-front-desk' ), $m['symbol'], 'text', __( 'Shown next to amounts, for example $, £ or €.', 'titan-zero-trades-front-desk' ), 4 );
        echo '<div><label for="tzfd_money_position">' . esc_html__( 'Symbol position', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_money_position" name="money[position]">';
        echo '<option value="before"' . selected( $m['position'], 'before', false ) . '>' . esc_html__( 'Before the amount ($1,200)', 'titan-zero-trades-front-desk' ) . '</option>';
        echo '<option value="after"' . selected( $m['position'], 'after', false ) . '>' . esc_html__( 'After the amount (1,200 kr)', 'titan-zero-trades-front-desk' ) . '</option></select></div>';
        echo '<div><label for="tzfd_money_decimals">' . esc_html__( 'Decimal places', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_money_decimals" name="money[decimals]">';
        foreach ( array( 0 => __( 'None ($1,200)', 'titan-zero-trades-front-desk' ), 2 => __( 'Two ($1,200.00)', 'titan-zero-trades-front-desk' ) ) as $d => $label ) {
            echo '<option value="' . (int) $d . '"' . selected( (int) $m['decimals'], $d, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';
        echo '</div>';
        echo '<p class="tzfd-muted">' . esc_html__( 'Example:', 'titan-zero-trades-front-desk' ) . ' <strong>' . esc_html( self::format( 1234.5, $m ) ) . '</strong></p></div>';

        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Business details', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p class="tzfd-muted">' . esc_html__( 'Shown on your payment instructions and reports. All optional.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<div class="tzfd-form-grid">';
        self::field( 'business[name]', __( 'Business name', 'titan-zero-trades-front-desk' ), $b['name'] );
        self::field( 'business[phone]', __( 'Phone', 'titan-zero-trades-front-desk' ), $b['phone'], 'tel' );
        self::field( 'business[email]', __( 'Email', 'titan-zero-trades-front-desk' ), $b['email'], 'email' );
        self::field( 'business[tax_number]', __( 'Tax number (ABN, GST or VAT)', 'titan-zero-trades-front-desk' ), $b['tax_number'] );
        self::field( 'business[website]', __( 'Website', 'titan-zero-trades-front-desk' ), $b['website'], 'url' );
        echo '<div class="tzfd-full"><label for="tzfd_business_address">' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</label>';
        echo '<textarea id="tzfd_business_address" name="business[address]" rows="3" class="large-text">' . esc_textarea( $b['address'] ) . '</textarea></div>';
        echo '</div></div>';

        echo '<div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Save changes', 'titan-zero-trades-front-desk' ) . '</button></div>';
        echo '</form>';
    }

    private static function field( $name, $label, $value, $type = 'text', $help = '', $maxlength = 0 ) {
        $id = 'tzfd_' . preg_replace( '/[^a-z_]/', '_', $name );
        echo '<div><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text"' . ( $maxlength > 0 ? ' maxlength="' . esc_attr( (int) $maxlength ) . '"' : '' ) . ' />';
        if ( '' !== $help ) { echo '<p class="description">' . esc_html( $help ) . '</p>'; }
        echo '</div>';
    }
}
