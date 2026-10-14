<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned payments table. Values are bound with prepare() and %i/%d/%s placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-money.php';
require_once __DIR__ . '/class-tzfd-booking-view.php';

/**
 * Payments received against bookings: PayPal, bank transfer and cash. Each payment is one row.
 * Payment methods (the account details and instructions shown to customers) are kept in one option.
 * Money amounts are stored as plain decimals and shown with the Business and money format.
 */
final class TZFD_Payments {
    const TABLE         = 'tzfd_payments';
    const SCHEMA_OPTION = 'titan_zero_front_desk_payments_schema';
    const SCHEMA_VERSION = '1';
    const METHODS_OPTION = 'titan_zero_front_desk_payment_methods_v1';
    const PAGE          = 'tzfd-payments';
    const SAVE_NONCE    = 'tzfd_payment_save';
    const DELETE_NONCE  = 'tzfd_payment_delete_';
    const METHODS_NONCE = 'tzfd_payment_methods_save';
    const ROW_LIMIT     = 200;

    public static function register() {
        add_action( 'admin_init', array( __CLASS__, 'ensure_schema' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
        add_action( 'admin_post_tzfd_payment_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_tzfd_payment_delete', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_tzfd_payment_methods_save', array( __CLASS__, 'handle_methods_save' ) );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function ensure_schema() {
        if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
            form_id bigint(20) unsigned NOT NULL DEFAULT 0,
            customer varchar(190) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            method varchar(20) NOT NULL DEFAULT 'cash',
            reference varchar(190) NOT NULL DEFAULT '',
            note text,
            paid_on date DEFAULT NULL,
            created datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY paid_on (paid_on),
            KEY booking_id (booking_id)
        ) $charset;" );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
    }

    /* ---------------------------------------------------------------
     * Methods
     * ------------------------------------------------------------- */

    /** The three methods, in order, with their labels. */
    public static function method_labels() {
        return array(
            'paypal' => __( 'PayPal', 'titan-zero-trades-front-desk' ),
            'bank'   => __( 'Bank transfer', 'titan-zero-trades-front-desk' ),
            'cash'   => __( 'Cash', 'titan-zero-trades-front-desk' ),
        );
    }

    /** Saved method settings, with defaults. */
    public static function methods() {
        $saved = get_option( self::METHODS_OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        $out   = self::default_methods();
        foreach ( array( 'paypal', 'bank', 'cash' ) as $key ) {
            if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) {
                $out[ $key ] = array_merge( $out[ $key ], array_intersect_key( $saved[ $key ], $out[ $key ] ) );
            }
        }
        $out['default'] = isset( $saved['default'] ) && array_key_exists( $saved['default'], self::method_labels() ) ? $saved['default'] : 'cash';
        return $out;
    }

    public static function default_methods() {
        return array(
            'paypal'  => array( 'enabled' => false, 'account' => '', 'instructions' => '' ),
            'bank'    => array( 'enabled' => false, 'account_name' => '', 'bank' => '', 'bsb' => '', 'account_number' => '', 'instructions' => '' ),
            'cash'    => array( 'enabled' => true, 'instructions' => '' ),
            'default' => 'cash',
        );
    }

    /** Clean method settings from the form. */
    public static function sanitize_methods( array $in ) {
        // Each method is a section of the form. A section that is not an array is treated as empty.
        $pp   = isset( $in['paypal'] ) && is_array( $in['paypal'] ) ? $in['paypal'] : array();
        $bank = isset( $in['bank'] ) && is_array( $in['bank'] ) ? $in['bank'] : array();
        $cash = isset( $in['cash'] ) && is_array( $in['cash'] ) ? $in['cash'] : array();

        $out = self::default_methods();
        $out['paypal'] = array(
            'enabled'      => ! empty( $pp['enabled'] ),
            'account'      => mb_substr( trim( sanitize_text_field( (string) ( $pp['account'] ?? '' ) ) ), 0, 200 ),
            'instructions' => mb_substr( sanitize_textarea_field( (string) ( $pp['instructions'] ?? '' ) ), 0, 500 ),
        );
        $out['bank'] = array(
            'enabled'        => ! empty( $bank['enabled'] ),
            'account_name'   => mb_substr( sanitize_text_field( (string) ( $bank['account_name'] ?? '' ) ), 0, 120 ),
            'bank'           => mb_substr( sanitize_text_field( (string) ( $bank['bank'] ?? '' ) ), 0, 120 ),
            'bsb'            => mb_substr( preg_replace( '/[^0-9\-]/', '', (string) ( $bank['bsb'] ?? '' ) ), 0, 10 ),
            'account_number' => mb_substr( preg_replace( '/[^0-9\-]/', '', (string) ( $bank['account_number'] ?? '' ) ), 0, 20 ),
            'instructions'   => mb_substr( sanitize_textarea_field( (string) ( $bank['instructions'] ?? '' ) ), 0, 500 ),
        );
        $out['cash'] = array(
            'enabled'      => ! empty( $cash['enabled'] ),
            'instructions' => mb_substr( sanitize_textarea_field( (string) ( $cash['instructions'] ?? '' ) ), 0, 500 ),
        );
        $default = (string) ( $in['default'] ?? 'cash' );
        $out['default'] = array_key_exists( $default, self::method_labels() ) ? $default : 'cash';
        return $out;
    }

    /**
     * The default must be a method that is switched on. When the chosen one is off, the first method
     * that is on is used instead, or cash when none is.
     */
    public static function resolve_default( array $methods ) {
        if ( empty( $methods[ $methods['default'] ]['enabled'] ) ) {
            $methods['default'] = 'cash';
            foreach ( array( 'paypal', 'bank', 'cash' ) as $key ) {
                if ( ! empty( $methods[ $key ]['enabled'] ) ) { $methods['default'] = $key; break; }
            }
        }
        return $methods;
    }

    /** Customer-facing payment instructions for every enabled method, as plain text. */
    public static function instructions_text() {
        $m     = self::methods();
        $money = TZFD_Money::class;
        $lines = array();
        if ( $m['paypal']['enabled'] ) {
            $lines[] = __( 'PayPal', 'titan-zero-trades-front-desk' ) . ': ' . $m['paypal']['account'] . ( '' !== $m['paypal']['instructions'] ? ' — ' . $m['paypal']['instructions'] : '' );
        }
        if ( $m['bank']['enabled'] ) {
            $bank = array_filter( array(
                __( 'Account name', 'titan-zero-trades-front-desk' ) . ': ' . $m['bank']['account_name'],
                __( 'Bank', 'titan-zero-trades-front-desk' ) . ': ' . $m['bank']['bank'],
                __( 'BSB', 'titan-zero-trades-front-desk' ) . ': ' . $m['bank']['bsb'],
                __( 'Account number', 'titan-zero-trades-front-desk' ) . ': ' . $m['bank']['account_number'],
            ), function ( $line ) { return ! preg_match( '/: $/', $line ); } );
            $lines[] = __( 'Bank transfer', 'titan-zero-trades-front-desk' ) . ': ' . implode( ', ', $bank ) . ( '' !== $m['bank']['instructions'] ? '. ' . $m['bank']['instructions'] : '' );
        }
        if ( $m['cash']['enabled'] ) {
            $lines[] = __( 'Cash', 'titan-zero-trades-front-desk' ) . ': ' . ( '' !== $m['cash']['instructions'] ? $m['cash']['instructions'] : __( 'pay in person.', 'titan-zero-trades-front-desk' ) );
        }
        return implode( "\n", $lines );
    }

    /* ---------------------------------------------------------------
     * Payments
     * ------------------------------------------------------------- */

    /** Payments received today, this month and in total. */
    public static function totals( $today = null ) {
        global $wpdb;
        $today = $today ?: TZFD_Booking_View::today();
        $month = substr( $today, 0, 8 ) . '01';
        $row   = $wpdb->get_row( $wpdb->prepare(
            'SELECT COALESCE(SUM(amount),0) AS total,
                    COALESCE(SUM(CASE WHEN paid_on = %s THEN amount ELSE 0 END),0) AS today,
                    COALESCE(SUM(CASE WHEN paid_on >= %s THEN amount ELSE 0 END),0) AS month
             FROM %i',
            $today, $month, self::table()
        ) );
        return array(
            'total' => (float) ( $row->total ?? 0 ),
            'today' => (float) ( $row->today ?? 0 ),
            'month' => (float) ( $row->month ?? 0 ),
        );
    }

    /** Recent payments, newest first. */
    public static function recent( $limit = self::ROW_LIMIT ) {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT id, booking_id, form_id, customer, amount, method, reference, note, paid_on FROM %i ORDER BY paid_on DESC, id DESC LIMIT %d',
            self::table(), max( 1, min( 500, (int) $limit ) )
        ) );
    }

    /** Checks and cleans a payment from the form. Returns the row to store, or a WP_Error-style message string. */
    public static function clean_payment( array $in ) {
        $amount = TZFD_Money::parse_amount( $in['amount'] ?? '' );
        if ( null === $amount || $amount <= 0 ) { return __( 'Enter the amount paid, more than zero.', 'titan-zero-trades-front-desk' ); }
        $method = (string) ( $in['method'] ?? '' );
        if ( ! array_key_exists( $method, self::method_labels() ) ) { return __( 'Choose PayPal, bank transfer or cash.', 'titan-zero-trades-front-desk' ); }
        $paid_on = (string) ( $in['paid_on'] ?? '' );
        if ( ! TZFD_Bookings::valid_date( $paid_on ) ) { $paid_on = TZFD_Booking_View::today(); }
        $booking = 0;
        $form    = 0;
        if ( preg_match( '/^(\d+)-(\d+)$/', (string) ( $in['booking'] ?? '' ), $m ) ) {
            $form    = (int) $m[1];
            $booking = (int) $m[2];
        }
        return array(
            'booking_id' => $booking,
            'form_id'    => $form,
            'customer'   => mb_substr( sanitize_text_field( (string) ( $in['customer'] ?? '' ) ), 0, 190 ),
            'amount'     => number_format( $amount, 2, '.', '' ),
            'method'     => $method,
            'reference'  => mb_substr( sanitize_text_field( (string) ( $in['reference'] ?? '' ) ), 0, 190 ),
            'note'       => mb_substr( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ), 0, 500 ),
            'paid_on'    => $paid_on,
        );
    }

    /** True when the booking exists in the bookings table. */
    public static function booking_exists( $form_id, $booking_id ) {
        global $wpdb;
        if ( $booking_id < 1 ) { return true; }
        $found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND formid = %d', $wpdb->prefix . TZFD_Bookings::TABLE, $booking_id, $form_id ) );
        return null !== $found;
    }

    public static function handle_save() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::SAVE_NONCE, 'tzfd_payment_nonce' );
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- clean_payment() sanitises every field.
        $row = self::clean_payment( wp_unslash( $_POST ) );
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput
        $args = array( 'page' => self::PAGE );
        if ( is_string( $row ) ) {
            $args['error'] = $row;
        } elseif ( ! self::booking_exists( $row['form_id'], $row['booking_id'] ) ) {
            $args['error'] = __( 'That booking was not found. Choose it again, or leave the booking blank.', 'titan-zero-trades-front-desk' );
        } else {
            global $wpdb;
            $wpdb->insert( self::table(), $row );
            $args['saved'] = 1;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
        check_admin_referer( self::DELETE_NONCE . $id, 'tzfd_payment_nonce' );
        global $wpdb;
        if ( $id > 0 ) { $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); }
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_methods_save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access is required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::METHODS_NONCE, 'tzfd_payment_methods_nonce' );
        // Each value is unslashed and stripped of tags here. sanitize_methods() then applies the rule for each field
        // (text, multi-line instructions, digits only, boolean, fixed list). wp_strip_all_tags() keeps line breaks.
        $posted = isset( $_POST['methods'] ) && is_array( $_POST['methods'] ) ? map_deep( wp_unslash( $_POST['methods'] ), 'wp_strip_all_tags' ) : array();
        $clean  = self::sanitize_methods( $posted );
        $clean = self::resolve_default( $clean );
        update_option( self::METHODS_OPTION, $clean, false );
        if ( TZFD_Admin_Menu::posted_from_settings() ) {
            wp_safe_redirect( add_query_arg( 'methods_saved', '1', admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS ) ) . '#payments' );
            exit;
        }
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'methods_saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ---------------------------------------------------------------
     * Page
     * ------------------------------------------------------------- */

    /** Booking choices for the record form: "formid-bookingid" => label. Recent and upcoming bookings only. */
    public static function booking_choices() {
        $today = TZFD_Booking_View::today();
        $found = TZFD_Booking_View::fetch( TZFD_Booking_View::add_days( $today, -30 ), TZFD_Booking_View::add_days( $today, 30 ) );
        $out   = array();
        foreach ( array_slice( $found['items'], 0, 300 ) as $item ) {
            if ( 'cancelled' === $item['status_class'] ) { continue; }
            $label = $item['date'] . ( '' !== $item['start'] ? ' ' . $item['start'] : '' ) . ' · ' . $item['customer'] . ( '' !== $item['service'] ? ' · ' . $item['service'] : '' );
            $out[ $item['form_id'] . '-' . $item['booking_id'] ] = $label;
        }
        return $out;
    }

    public static function page() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $can_manage = current_user_can( 'manage_options' );
        $today      = TZFD_Booking_View::today();
        $totals     = self::totals( $today );
        $rows       = self::recent();
        $methods    = self::methods();
        $labels     = self::method_labels();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice flags shown after a redirect.
        $error      = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        $saved      = isset( $_GET['saved'] );
        $deleted    = isset( $_GET['deleted'] );
        $msaved     = isset( $_GET['methods_saved'] );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-payments"><h1>' . esc_html__( 'Payments', 'titan-zero-trades-front-desk' ) . '</h1>';
        echo '<p class="tzfd-lead">' . esc_html__( 'Record money received by PayPal, bank transfer or cash, and see what has come in.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( $saved ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Payment recorded.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( $deleted ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Payment removed.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( $msaved ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Payment methods saved.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( '' !== $error ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>'; }

        echo '<div class="tzfd-stats">';
        self::stat( __( 'Received today', 'titan-zero-trades-front-desk' ), TZFD_Money::format( $totals['today'] ) );
        self::stat( __( 'Received this month', 'titan-zero-trades-front-desk' ), TZFD_Money::format( $totals['month'] ) );
        self::stat( __( 'Total received', 'titan-zero-trades-front-desk' ), TZFD_Money::format( $totals['total'] ) );
        echo '</div>';

        // Record a payment
        $choices = self::booking_choices();
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Record a payment', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tzfd_payment_save" />';
        wp_nonce_field( self::SAVE_NONCE, 'tzfd_payment_nonce' );
        echo '<div class="tzfd-form-grid">';
        echo '<div><label for="tzfd_pay_booking">' . esc_html__( 'Booking (optional)', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_pay_booking" name="booking"><option value="">' . esc_html__( 'Not linked to a booking', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $choices as $value => $label ) { echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $label ) . '</option>'; }
        echo '</select><p class="description">' . esc_html__( 'Bookings from the last 30 days and the next 30 days.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<div><label for="tzfd_pay_customer">' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_pay_customer" type="text" name="customer" class="regular-text" /></div>';
        echo '<div><label for="tzfd_pay_amount">' . esc_html__( 'Amount', 'titan-zero-trades-front-desk' ) . ' (' . esc_html( TZFD_Money::currency_code() ) . ')</label><input id="tzfd_pay_amount" type="text" inputmode="decimal" name="amount" class="regular-text" placeholder="0.00" required /></div>';
        echo '<div><label for="tzfd_pay_method">' . esc_html__( 'Paid by', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_pay_method" name="method">';
        foreach ( $labels as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $methods['default'], $key, false ) . '>' . esc_html( $label ) . ( $methods[ $key ]['enabled'] ? '' : ' ' . esc_html__( '(not offered to customers)', 'titan-zero-trades-front-desk' ) ) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label for="tzfd_pay_date">' . esc_html__( 'Date received', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_pay_date" type="date" name="paid_on" value="' . esc_attr( $today ) . '" /></div>';
        echo '<div><label for="tzfd_pay_reference">' . esc_html__( 'Reference (transaction ID, bank reference)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_pay_reference" type="text" name="reference" class="regular-text" /></div>';
        echo '<div class="tzfd-full"><label for="tzfd_pay_note">' . esc_html__( 'Note (optional)', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_pay_note" name="note" rows="2" class="large-text"></textarea></div>';
        echo '</div><div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Record payment', 'titan-zero-trades-front-desk' ) . '</button></div></form></div>';

        // Payments list
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Payments', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( empty( $rows ) ) {
            echo '<p class="tzfd-muted">' . esc_html__( 'No payments recorded yet.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            echo '<div class="tzfd-table-wrap"><table class="widefat striped tzfd-table"><thead><tr>';
            echo '<th>' . esc_html__( 'Date', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Method', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Reference', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Amount', 'titan-zero-trades-front-desk' ) . '</th><th></th>';
            echo '</tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $method = $labels[ $row->method ] ?? $row->method;
                echo '<tr><td>' . esc_html( $row->paid_on ) . '</td><td>' . esc_html( $row->customer ) . '</td><td>' . esc_html( $method ) . '</td><td>' . esc_html( $row->reference ) . '</td>';
                echo '<td><strong>' . esc_html( TZFD_Money::format( $row->amount ) ) . '</strong></td><td class="tzfd-row-actions">';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tzfd-inline-form" data-confirm="' . esc_attr__( 'Remove this payment?', 'titan-zero-trades-front-desk' ) . '">';
                echo '<input type="hidden" name="action" value="tzfd_payment_delete" /><input type="hidden" name="id" value="' . (int) $row->id . '" />';
                wp_nonce_field( self::DELETE_NONCE . (int) $row->id, 'tzfd_payment_nonce' );
                echo '<button type="submit" class="tzfd-btn tzfd-btn-danger">' . esc_html__( 'Remove', 'titan-zero-trades-front-desk' ) . '</button></form></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Payment methods
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Payment methods', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( ! $can_manage ) {
            echo '<p class="tzfd-muted">' . esc_html__( 'Ask an administrator to change payment methods.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            self::methods_form( $methods );
        }
        echo '</div>';

        // Customer instructions
        $text = self::instructions_text();
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Payment instructions for customers', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( '' === $text ) {
            echo '<p class="tzfd-muted">' . esc_html__( 'Turn on at least one payment method above to write instructions for customers.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            echo '<p class="tzfd-muted">' . esc_html__( 'Copy this into your booking confirmation or invoice.', 'titan-zero-trades-front-desk' ) . '</p>';
            echo '<textarea class="large-text code tzfd-select-on-click" rows="5" readonly>' . esc_textarea( $text ) . '</textarea>';
        }
        echo '</div></div>';
    }

    /** Loads the confirm-prompt and select-on-click script on the Payments screen only. */
    public static function enqueue_scripts() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only check of which screen is open.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( self::PAGE !== $page ) {
            return;
        }
        wp_enqueue_script( 'tzfd-pipe-pay-confirm', TZFD_Brand::url( 'js/inline/pipe-pay-confirm.js' ), array(), '2.0.1', true );
    }

    private static function stat( $label, $value ) {
        echo '<div class="tzfd-stat"><div class="tzfd-stat-n">' . esc_html( $value ) . '</div><div class="tzfd-stat-l">' . esc_html( $label ) . '</div></div>';
    }

    /** The payment methods form. Settings embeds it with $in_settings true, so it returns to Settings after saving. */
    public static function methods_form( array $m, $in_settings = false ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tzfd_payment_methods_save" />';
        if ( $in_settings ) { echo '<input type="hidden" name="return_to" value="settings" />'; }
        wp_nonce_field( self::METHODS_NONCE, 'tzfd_payment_methods_nonce' );
        echo '<h3 class="tzfd-section-title">' . esc_html__( 'PayPal', 'titan-zero-trades-front-desk' ) . '</h3><div class="tzfd-form-grid">';
        echo '<div class="tzfd-full"><label class="tzfd-check"><input type="checkbox" name="methods[paypal][enabled]" value="1"' . checked( $m['paypal']['enabled'], true, false ) . ' /> ' . esc_html__( 'Offer PayPal to customers', 'titan-zero-trades-front-desk' ) . '</label></div>';
        echo '<div><label for="tzfd_pp_account">' . esc_html__( 'PayPal email or PayPal.me link', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_pp_account" type="text" class="regular-text" name="methods[paypal][account]" value="' . esc_attr( $m['paypal']['account'] ) . '" /></div>';
        echo '<div><label for="tzfd_pp_instr">' . esc_html__( 'Note for customers (optional)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_pp_instr" type="text" class="regular-text" name="methods[paypal][instructions]" value="' . esc_attr( $m['paypal']['instructions'] ) . '" /></div>';
        echo '</div>';

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Bank transfer', 'titan-zero-trades-front-desk' ) . '</h3><div class="tzfd-form-grid">';
        echo '<div class="tzfd-full"><label class="tzfd-check"><input type="checkbox" name="methods[bank][enabled]" value="1"' . checked( $m['bank']['enabled'], true, false ) . ' /> ' . esc_html__( 'Offer bank transfer to customers', 'titan-zero-trades-front-desk' ) . '</label></div>';
        $bank_fields = array( 'account_name' => __( 'Account name', 'titan-zero-trades-front-desk' ), 'bank' => __( 'Bank', 'titan-zero-trades-front-desk' ), 'bsb' => __( 'BSB', 'titan-zero-trades-front-desk' ), 'account_number' => __( 'Account number', 'titan-zero-trades-front-desk' ) );
        foreach ( $bank_fields as $key => $label ) {
            echo '<div><label for="tzfd_bank_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="tzfd_bank_' . esc_attr( $key ) . '" type="text" class="regular-text" name="methods[bank][' . esc_attr( $key ) . ']" value="' . esc_attr( $m['bank'][ $key ] ) . '" /></div>';
        }
        echo '<div class="tzfd-full"><label for="tzfd_bank_instr">' . esc_html__( 'Note for customers (optional), for example the reference to use', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_bank_instr" type="text" class="regular-text" name="methods[bank][instructions]" value="' . esc_attr( $m['bank']['instructions'] ) . '" /></div>';
        echo '</div>';

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Cash', 'titan-zero-trades-front-desk' ) . '</h3><div class="tzfd-form-grid">';
        echo '<div class="tzfd-full"><label class="tzfd-check"><input type="checkbox" name="methods[cash][enabled]" value="1"' . checked( $m['cash']['enabled'], true, false ) . ' /> ' . esc_html__( 'Offer cash to customers', 'titan-zero-trades-front-desk' ) . '</label></div>';
        echo '<div class="tzfd-full"><label for="tzfd_cash_instr">' . esc_html__( 'Note for customers (optional), for example "pay on the day"', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_cash_instr" type="text" class="regular-text" name="methods[cash][instructions]" value="' . esc_attr( $m['cash']['instructions'] ) . '" /></div>';
        echo '</div>';

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Default method', 'titan-zero-trades-front-desk' ) . '</h3><div class="tzfd-form-grid"><div>';
        echo '<label for="tzfd_pay_default">' . esc_html__( 'Selected first when you record a payment', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_pay_default" name="methods[default]">';
        foreach ( self::method_labels() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $m['default'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div></div>';
        echo '<div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Save payment methods', 'titan-zero-trades-front-desk' ) . '</button></div></form>';
    }
}
