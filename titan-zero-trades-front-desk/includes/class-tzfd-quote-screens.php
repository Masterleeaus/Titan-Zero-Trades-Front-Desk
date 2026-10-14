<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned quote tables. Values are bound with prepare() and %i/%d/%s placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-money.php';
require_once __DIR__ . '/class-tzfd-booking-view.php';
require_once __DIR__ . '/class-tzfd-service-details.php';
require_once __DIR__ . '/class-tzfd-dashboard-figures.php';

/**
 * Quotes screens: the list, the quote editor, New quote, Duplicate, Delete, Send, the price catalog
 * and quote settings. Every change is a nonce-checked admin-post request with a capability check,
 * and every result comes back as a notice on the page it was made from.
 *
 * Editors (edit_pages) can work on quotes. The price catalog and quote settings need manage_options.
 */
final class TZFD_Quote_Screens {
    const PAGE          = 'tzfd-quotes';
    const CAP_EDIT      = 'edit_pages';
    const CAP_ADMIN     = 'manage_options';
    const NONCE_FIELD   = 'tzfd_quote_nonce';
    const STATUSES      = array( 'draft', 'sent', 'approved', 'declined', 'expired' );
    const SET_STATUSES  = array( 'draft', 'sent', 'declined', 'expired' );
    const URGENCY       = array( 'routine', 'urgent', 'emergency' );
    const KINDS         = array( 'labor', 'material', 'fee', 'travel', 'other' );
    const ROW_LIMIT     = 200;

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 110 );
        add_action( 'admin_post_tzfd_quote_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_tzfd_quote_create', array( __CLASS__, 'handle_create' ) );
        add_action( 'admin_post_tzfd_quote_duplicate', array( __CLASS__, 'handle_duplicate' ) );
        add_action( 'admin_post_tzfd_quote_delete', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_tzfd_quote_send', array( __CLASS__, 'handle_send' ) );
        add_action( 'admin_post_tzfd_quote_catalog_save', array( __CLASS__, 'handle_catalog_save' ) );
        add_action( 'admin_post_tzfd_quote_catalog_delete', array( __CLASS__, 'handle_catalog_delete' ) );
        add_action( 'admin_post_tzfd_quote_catalog_seed', array( __CLASS__, 'handle_catalog_seed' ) );
        add_action( 'admin_post_tzfd_quote_settings_save', array( __CLASS__, 'handle_settings_save' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'tzfd',
            __( 'Quotes', 'titan-zero-trades-front-desk' ),
            __( 'Quotes', 'titan-zero-trades-front-desk' ),
            self::CAP_EDIT,
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    /** Formats an amount with the saved currency: "$1,234.50". */
    public static function money( $amount ) {
        return TZFD_Money::format( (float) $amount );
    }

    /* ---------------------------------------------------------------
     * Helpers: capability, nonce, redirects, notices
     * ------------------------------------------------------------- */

    /** Stops a request from a user who may not make it. Each handler checks its own nonce and capability first. */
    private static function deny() {
        wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
    }

    /** Sends the user back to the Quotes screen with a notice or error code. */
    private static function back( array $args = array() ) {
        wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE, 'qtab' => 'quotes' ), $args ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function messages() {
        return array(
            'notice' => array(
                'saved'          => array( 'success', __( 'Quote saved. Totals were recalculated.', 'titan-zero-trades-front-desk' ) ),
                'sent'           => array( 'success', __( 'Quote saved and emailed to the customer.', 'titan-zero-trades-front-desk' ) ),
                'marked_sent'    => array( 'success', __( 'Quote marked as sent. There is no customer email on file, so nothing was emailed.', 'titan-zero-trades-front-desk' ) ),
                'resent'         => array( 'success', __( 'Quote emailed to the customer again.', 'titan-zero-trades-front-desk' ) ),
                'created'        => array( 'success', __( 'Draft quote created. Add or check the line items, then save.', 'titan-zero-trades-front-desk' ) ),
                'created_ai'     => array( 'success', __( 'Draft quote written from the job description. Check every line before you send it.', 'titan-zero-trades-front-desk' ) ),
                'duplicated'     => array( 'success', __( 'Copy made as a new draft.', 'titan-zero-trades-front-desk' ) ),
                'deleted'        => array( 'success', __( 'Quote deleted.', 'titan-zero-trades-front-desk' ) ),
                'catalog_saved'  => array( 'success', __( 'Item added to the price catalog.', 'titan-zero-trades-front-desk' ) ),
                'catalog_deleted'=> array( 'success', __( 'Item removed from the price catalog. Quotes that already use it keep their own copy.', 'titan-zero-trades-front-desk' ) ),
                'catalog_seeded' => array( 'success', __( 'Starter catalog added.', 'titan-zero-trades-front-desk' ) ),
                'settings_saved' => array( 'success', __( 'Quote settings saved.', 'titan-zero-trades-front-desk' ) ),
            ),
            'error' => array(
                'not_found'      => __( 'That quote was not found. It may have been deleted.', 'titan-zero-trades-front-desk' ),
                'approved'       => __( 'The customer has approved this quote, so it cannot be changed. Duplicate it to make a new draft.', 'titan-zero-trades-front-desk' ),
                'no_items'       => __( 'Add at least one line item before you email the quote.', 'titan-zero-trades-front-desk' ),
                'cannot_send'    => __( 'This quote cannot be sent in its current state.', 'titan-zero-trades-front-desk' ),
                'need_name'      => __( 'Enter the customer name.', 'titan-zero-trades-front-desk' ),
                'could_not_save' => __( 'The quote could not be saved. Try again.', 'titan-zero-trades-front-desk' ),
                'delete_approved'=> __( 'Approved quotes are kept as a record and cannot be deleted. Decline or expire them instead.', 'titan-zero-trades-front-desk' ),
                'catalog_label'  => __( 'Enter a label for the catalog item.', 'titan-zero-trades-front-desk' ),
                'catalog_sku'    => __( 'That SKU is already in the catalog. Use another, or leave SKU blank to make one.', 'titan-zero-trades-front-desk' ),
                'catalog_not_empty' => __( 'The catalog already has items, so the starter list was not added.', 'titan-zero-trades-front-desk' ),
            ),
        );
    }

    private static function notice_html() {
        $all = self::messages();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice codes shown after a redirect.
        $ok  = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
        $err = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( '' !== $ok && isset( $all['notice'][ $ok ] ) ) {
            echo '<div class="notice notice-' . esc_attr( $all['notice'][ $ok ][0] ) . ' inline"><p>' . esc_html( $all['notice'][ $ok ][1] ) . '</p></div>';
        }
        if ( '' !== $err && isset( $all['error'][ $err ] ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $all['error'][ $err ] ) . '</p></div>';
        }
    }

    /* ---------------------------------------------------------------
     * Cleaning input
     * ------------------------------------------------------------- */

    /** Quote-level fields from the editor or New quote form. Only known keys are returned. */
    public static function clean_quote_fields( array $in ) {
        $urgency = sanitize_key( (string) ( $in['urgency'] ?? 'routine' ) );
        $email   = sanitize_email( (string) ( $in['customer_email'] ?? '' ) );
        $valid   = (string) ( $in['valid_until'] ?? '' );
        $status  = (string) ( $in['status'] ?? 'draft' );
        return array(
            'customer_name'    => mb_substr( sanitize_text_field( (string) ( $in['customer_name'] ?? '' ) ), 0, 190 ),
            'customer_email'   => is_email( $email ) ? $email : '',
            'customer_phone'   => mb_substr( sanitize_text_field( (string) ( $in['customer_phone'] ?? '' ) ), 0, 40 ),
            'service_address'  => mb_substr( sanitize_textarea_field( (string) ( $in['service_address'] ?? '' ) ), 0, 500 ),
            'service_type'     => mb_substr( sanitize_text_field( (string) ( $in['service_type'] ?? '' ) ), 0, 120 ),
            'urgency'          => in_array( $urgency, self::URGENCY, true ) ? $urgency : 'routine',
            'description'      => mb_substr( sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ), 0, 2000 ),
            'customer_message' => wp_kses_post( (string) ( $in['customer_message'] ?? '' ) ),
            'notes'            => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 2000 ),
            'tax_rate'         => round( max( 0, min( 100, (float) ( $in['tax_rate'] ?? 0 ) ) ), 3 ),
            'discount'         => round( max( 0, (float) ( $in['discount'] ?? 0 ) ), 2 ),
            'deposit'          => round( max( 0, (float) ( $in['deposit'] ?? 0 ) ), 2 ),
            'valid_until'      => TZFD_Bookings::valid_date( $valid ) ? $valid : null,
            'status'           => in_array( $status, self::SET_STATUSES, true ) ? $status : 'draft',
        );
    }

    /**
     * Line items from the editor rows. Rows marked for removal, and rows with no label, are dropped.
     * Returns a list in the order shown.
     */
    public static function clean_items( $rows ) {
        $out = array();
        if ( ! is_array( $rows ) ) { return $out; }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) { continue; }
            $label = mb_substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, 190 );
            if ( '' === trim( $label ) ) { continue; }
            $kind = sanitize_key( (string) ( $row['kind'] ?? 'labor' ) );
            $unit = mb_substr( sanitize_text_field( (string) ( $row['unit'] ?? '' ) ), 0, 20 );
            $out[] = array(
                'kind'       => in_array( $kind, self::KINDS, true ) ? $kind : 'labor',
                'sku'        => mb_substr( sanitize_text_field( (string) ( $row['sku'] ?? '' ) ), 0, 60 ),
                'label'      => $label,
                'qty'        => round( max( 0, (float) ( $row['qty'] ?? 1 ) ), 2 ),
                'unit'       => '' !== $unit ? $unit : 'each',
                'unit_price' => round( max( 0, (float) ( $row['unit_price'] ?? 0 ) ), 2 ),
                'taxable'    => ! empty( $row['taxable'] ) ? 1 : 0,
            );
            if ( count( $out ) >= self::ROW_LIMIT ) { break; }
        }
        return $out;
    }

    /* ---------------------------------------------------------------
     * Booking prefill for New quote
     * ------------------------------------------------------------- */

    /** Bookings from 60 days back to 60 days ahead, keyed "formid-bookingid-date-start". */
    public static function booking_choices() {
        $today = TZFD_Booking_View::today();
        $found = TZFD_Booking_View::fetch( TZFD_Booking_View::add_days( $today, -60 ), TZFD_Booking_View::add_days( $today, 60 ) );
        $out   = array();
        foreach ( array_slice( $found['items'], 0, 300 ) as $item ) {
            if ( 'cancelled' === $item['status_class'] ) { continue; }
            $key = $item['form_id'] . '-' . $item['booking_id'] . '-' . $item['date'] . '-' . $item['start'];
            $out[ $key ] = $item['date'] . ( '' !== $item['start'] ? ' ' . $item['start'] : '' ) . ' · ' . $item['customer'] . ( '' !== $item['service'] ? ' · ' . $item['service'] : '' );
        }
        return $out;
    }

    /**
     * Fields and one service line for the chosen booking, or null when the choice is not a current booking.
     * The line uses the service's price from its calendar.
     */
    public static function prefill_from_booking( $value ) {
        if ( ! preg_match( '/^(\d+)-(\d+)-(\d{4}-\d{2}-\d{2})-(\d{2}:\d{2}|)$/', (string) $value, $m ) ) { return null; }
        $form  = (int) $m[1];
        $booking = (int) $m[2];
        $today = TZFD_Booking_View::today();
        $found = TZFD_Booking_View::fetch( TZFD_Booking_View::add_days( $today, -60 ), TZFD_Booking_View::add_days( $today, 60 ) );
        foreach ( $found['items'] as $item ) {
            if ( $item['form_id'] !== $form || $item['booking_id'] !== $booking || $item['date'] !== $m[3] || $item['start'] !== $m[4] ) { continue; }
            $prices = TZFD_Dashboard_Figures::service_prices();
            $price  = $prices[ $form ][ $item['service'] ] ?? 0.0;
            $details = TZFD_Service_Details::get( $form, $item['service'] );
            return array(
                'customer_name'   => (string) $item['customer'],
                'customer_email'  => (string) $item['email'],
                'service_type'    => (string) $item['service'],
                'service_address' => (string) $details['address'],
                'description'     => sprintf( /* translators: 1: date, 2: start time. */ __( 'Booked for %1$s at %2$s.', 'titan-zero-trades-front-desk' ), $item['date'], $item['start'] ),
                'items'           => array( array(
                    'kind' => 'labor', 'sku' => '', 'label' => (string) $item['service'], 'qty' => 1,
                    'unit' => 'each', 'unit_price' => round( (float) $price, 2 ), 'taxable' => 1,
                ) ),
            );
        }
        return null;
    }

    /* ---------------------------------------------------------------
     * Create, save, duplicate, delete, send
     * ------------------------------------------------------------- */

    /** New quote from the form: a draft, from the AI when it is set up, or from a booking. */
    public static function handle_create() {
        if ( ! current_user_can( self::CAP_EDIT ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_create', self::NONCE_FIELD );
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- clean_quote_fields() sanitises every field.
        $in = wp_unslash( $_POST );
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput
        $fields = self::clean_quote_fields( $in );
        $fields['status'] = 'draft';
        $prefill = self::prefill_from_booking( (string) ( $in['booking'] ?? '' ) );
        if ( $prefill ) {
            foreach ( array( 'customer_name', 'customer_email', 'service_type', 'service_address', 'description' ) as $key ) {
                if ( '' === $fields[ $key ] && '' !== (string) ( $prefill[ $key ] ?? '' ) ) { $fields[ $key ] = $prefill[ $key ]; }
            }
        }
        if ( '' === $fields['customer_name'] ) { self::back( array( 'qtab' => 'new', 'error' => 'need_name' ) ); }

        $items  = $prefill ? $prefill['items'] : array();
        $ai     = TZFD_Addon_Support::ai_configured();
        $result = null;
        if ( $ai && '' !== $fields['description'] && class_exists( 'TZFD_Quote_AI' ) ) {
            $context = array(
                'tax_rate' => floatval( get_option( 'tzfd_quotes_tax_rate', 0 ) ),
                'markup'   => floatval( get_option( 'tzfd_quotes_default_markup', 20 ) ),
            );
            $result = TZFD_Quote_AI::suggest( array(
                'customer_name' => $fields['customer_name'], 'customer_email' => $fields['customer_email'],
                'customer_phone' => $fields['customer_phone'], 'address' => $fields['service_address'],
                'service_type' => $fields['service_type'], 'urgency' => $fields['urgency'], 'description' => $fields['description'],
            ), $context );
            $items = (array) ( $result['items'] ?? array() );
        }

        $validity = (int) get_option( 'tzfd_quotes_validity_days', 14 );
        $quote_id = TZFD_Quote::create( array_merge( $fields, array(
            'formid'           => 0,
            'currency'         => TZFD_Money::currency_code(),
            'tax_rate'         => round( floatval( get_option( 'tzfd_quotes_tax_rate', 0 ) ), 3 ),
            'valid_until'      => $fields['valid_until'] ?: wp_date( 'Y-m-d', time() + ( max( 1, $validity ) * DAY_IN_SECONDS ) ),
            'notes'            => $result ? (string) ( $result['notes'] ?? '' ) : $fields['notes'],
            'customer_message' => $result ? (string) ( $result['customer_message'] ?? '' ) : $fields['customer_message'],
            'confidence'       => $result ? (float) ( $result['confidence'] ?? 0 ) : 0,
            'ai_model'         => $result ? (string) ( $result['provider'] ?? '' ) : '',
            'ai_source'        => $result ? (string) ( $result['source'] ?? '' ) : 'manual',
        ) ) );
        if ( ! $quote_id ) { self::back( array( 'qtab' => 'new', 'error' => 'could_not_save' ) ); }

        TZFD_Quote::replace_items( $quote_id, $items );
        self::apply_default_deposit( $quote_id );
        TZFD_Quote::snapshot( $quote_id, $result ? 'ai generated' : 'created' );
        self::back( array( 'id' => $quote_id, 'notice' => $result ? 'created_ai' : 'created' ) );
    }

    /** Deposit from the default percentage, once the total is known. Only when the quote has no deposit set. */
    private static function apply_default_deposit( $quote_id ) {
        global $wpdb;
        $pct = floatval( get_option( 'tzfd_quotes_deposit_percent', 0 ) );
        $q   = TZFD_Quote::get( $quote_id );
        if ( $pct <= 0 || ! $q || floatval( $q->deposit ) > 0 ) { return; }
        $wpdb->update( $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES,
            array( 'deposit' => round( floatval( $q->total ) * ( $pct / 100 ), 2 ) ), array( 'id' => $quote_id ) );
    }

    /** Saves the editor: quote fields, then every line item, then the totals. Optionally emails the quote. */
    public static function handle_save() {
        $id = absint( wp_unslash( $_POST['quote_id'] ?? 0 ) ); // The nonce action includes the quote id, so it is read first.
        if ( ! current_user_can( self::CAP_EDIT ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_save_' . $id, self::NONCE_FIELD );
        $q = TZFD_Quote::get( $id );
        if ( ! $q ) { self::back( array( 'error' => 'not_found' ) ); }
        if ( 'approved' === $q->status ) { self::back( array( 'id' => $id, 'error' => 'approved' ) ); }

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- clean_quote_fields() and clean_items() sanitise every field.
        $in = wp_unslash( $_POST );
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput
        $fields = self::clean_quote_fields( $in );
        if ( ! isset( $in['status'] ) ) { unset( $fields['status'] ); } // Keep the current status when the form does not send one.
        if ( '' === $fields['customer_name'] ) { self::back( array( 'id' => $id, 'error' => 'need_name' ) ); }
        $items  = self::clean_items( $in['items'] ?? array() );
        $intent = sanitize_key( (string) ( $in['intent'] ?? 'save' ) );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES, $fields, array( 'id' => $id ) );
        TZFD_Quote::replace_items( $id, $items );
        TZFD_Quote::snapshot( $id, 'admin save' );

        if ( 'send' === $intent ) {
            if ( empty( $items ) ) { self::back( array( 'id' => $id, 'error' => 'no_items' ) ); }
            $has_email = '' !== (string) TZFD_Quote::get( $id )->customer_email;
            if ( ! TZFD_Quote::send( $id ) ) { self::back( array( 'id' => $id, 'error' => 'cannot_send' ) ); }
            self::back( array( 'id' => $id, 'notice' => $has_email ? 'sent' : 'marked_sent' ) );
        }
        self::back( array( 'id' => $id, 'notice' => 'saved' ) );
    }

    public static function handle_duplicate() {
        $id = absint( wp_unslash( $_POST['quote_id'] ?? 0 ) ); // The nonce action includes the quote id, so it is read first.
        if ( ! current_user_can( self::CAP_EDIT ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_duplicate_' . $id, self::NONCE_FIELD );
        $q = TZFD_Quote::get( $id );
        if ( ! $q ) { self::back( array( 'error' => 'not_found' ) ); }
        $new = TZFD_Quote::create( array(
            'formid'           => (int) $q->formid,
            'customer_name'    => $q->customer_name,
            'customer_email'   => $q->customer_email,
            'customer_phone'   => $q->customer_phone,
            'service_address'  => $q->service_address,
            'service_type'     => $q->service_type,
            'description'      => $q->description,
            'urgency'          => $q->urgency,
            'status'           => 'draft',
            'currency'         => $q->currency ?: TZFD_Money::currency_code(),
            'tax_rate'         => $q->tax_rate,
            'discount'         => $q->discount,
            'deposit'          => $q->deposit,
            'valid_until'      => wp_date( 'Y-m-d', time() + ( max( 1, (int) get_option( 'tzfd_quotes_validity_days', 14 ) ) * DAY_IN_SECONDS ) ),
            'notes'            => $q->notes,
            'customer_message' => $q->customer_message,
            'ai_source'        => 'copy',
        ) );
        if ( ! $new ) { self::back( array( 'id' => $id, 'error' => 'could_not_save' ) ); }
        $items = array();
        foreach ( TZFD_Quote::items( $id ) as $it ) {
            $items[] = array(
                'kind' => $it->kind, 'sku' => $it->sku, 'label' => $it->label, 'qty' => $it->qty, 'unit' => $it->unit,
                'unit_price' => $it->unit_price, 'taxable' => $it->taxable, 'notes' => $it->notes,
            );
        }
        TZFD_Quote::replace_items( $new, $items );
        TZFD_Quote::snapshot( $new, 'copied from ' . $q->reference );
        self::back( array( 'id' => $new, 'notice' => 'duplicated' ) );
    }

    public static function handle_delete() {
        $id = absint( wp_unslash( $_POST['quote_id'] ?? 0 ) ); // The nonce action includes the quote id, so it is read first.
        if ( ! current_user_can( self::CAP_EDIT ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_delete_' . $id, self::NONCE_FIELD );
        $q = TZFD_Quote::get( $id );
        if ( ! $q ) { self::back( array( 'error' => 'not_found' ) ); }
        if ( 'approved' === $q->status ) { self::back( array( 'id' => $id, 'error' => 'delete_approved' ) ); }
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( $p . TZFD_Quotes_Engine::T_ITEMS, array( 'quote_id' => $id ), array( '%d' ) );
        $wpdb->delete( $p . TZFD_Quotes_Engine::T_REVISIONS, array( 'quote_id' => $id ), array( '%d' ) );
        $wpdb->delete( $p . TZFD_Quotes_Engine::T_QUOTES, array( 'id' => $id ), array( '%d' ) );
        self::back( array( 'notice' => 'deleted' ) );
    }

    public static function handle_send() {
        $id = absint( wp_unslash( $_POST['quote_id'] ?? 0 ) ); // The nonce action includes the quote id, so it is read first.
        if ( ! current_user_can( self::CAP_EDIT ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_send_' . $id, self::NONCE_FIELD );
        if ( ! TZFD_Quote::get( $id ) ) { self::back( array( 'error' => 'not_found' ) ); }
        if ( empty( TZFD_Quote::items( $id ) ) ) { self::back( array( 'id' => $id, 'error' => 'no_items' ) ); }
        $has_email = '' !== (string) TZFD_Quote::get( $id )->customer_email;
        if ( ! TZFD_Quote::send( $id ) ) { self::back( array( 'id' => $id, 'error' => 'cannot_send' ) ); }
        self::back( array( 'id' => $id, 'notice' => $has_email ? 'resent' : 'marked_sent' ) );
    }

    /* ---------------------------------------------------------------
     * Price catalog and settings (administrators)
     * ------------------------------------------------------------- */

    public static function handle_catalog_save() {
        if ( ! current_user_can( self::CAP_ADMIN ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_catalog_save', self::NONCE_FIELD );
        $in    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Each field is sanitised below.
        $label = mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 190 );
        if ( '' === trim( $label ) ) { self::back_to_catalog( 'catalog_label' ); }
        $sku = mb_substr( sanitize_text_field( (string) ( $in['sku'] ?? '' ) ), 0, 60 );
        if ( '' === $sku ) { $sku = mb_substr( strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '-', $label ) ), 0, 60 ); }
        global $wpdb;
        $table = $wpdb->prefix . TZFD_Quotes_Engine::T_CATALOG;
        if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE sku = %s', $table, $sku ) ) ) { self::back_to_catalog( 'catalog_sku' ); }
        $kind = sanitize_key( (string) ( $in['kind'] ?? 'labor' ) );
        $wpdb->insert( $table, array(
            'sku'          => $sku,
            'label'        => $label,
            'description'  => wp_kses_post( (string) ( $in['description'] ?? '' ) ),
            'kind'         => in_array( $kind, self::KINDS, true ) ? $kind : 'labor',
            'unit'         => mb_substr( sanitize_text_field( (string) ( $in['unit'] ?? 'each' ) ), 0, 20 ) ?: 'each',
            'unit_price'   => round( max( 0, (float) ( $in['unit_price'] ?? 0 ) ), 2 ),
            'taxable'      => ! empty( $in['taxable'] ) ? 1 : 0,
            'keywords'     => mb_substr( sanitize_text_field( (string) ( $in['keywords'] ?? '' ) ), 0, 255 ),
            'service_type' => mb_substr( sanitize_text_field( (string) ( $in['service_type'] ?? '' ) ), 0, 120 ),
            'active'       => 1,
        ) );
        self::back_to_catalog( 'catalog_saved' );
    }

    public static function handle_catalog_delete() {
        $id = absint( wp_unslash( $_POST['item_id'] ?? 0 ) ); // The nonce action includes the item id, so it is read first.
        if ( ! current_user_can( self::CAP_ADMIN ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_catalog_delete_' . $id, self::NONCE_FIELD );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . TZFD_Quotes_Engine::T_CATALOG, array( 'id' => $id ), array( '%d' ) );
        self::back_to_catalog( 'catalog_deleted' );
    }

    public static function handle_catalog_seed() {
        if ( ! current_user_can( self::CAP_ADMIN ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_catalog_seed', self::NONCE_FIELD );
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . TZFD_Quotes_Engine::T_CATALOG ) );
        if ( $count > 0 ) { self::back_to_catalog( 'catalog_not_empty' ); }
        TZFD_Quote_Catalog::seed();
        self::back_to_catalog( 'catalog_seeded' );
    }

    private static function back_to_catalog( $code ) {
        $key = isset( self::messages()['error'][ $code ] ) ? 'error' : 'notice';
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'catalog', $key => $code ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_settings_save() {
        if ( ! current_user_can( self::CAP_ADMIN ) ) { self::deny(); }
        check_admin_referer( 'tzfd_quote_settings_save', self::NONCE_FIELD );
        $in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Each value is cast or sanitised below.
        update_option( 'tzfd_quotes_tax_rate', round( max( 0, min( 100, (float) ( $in['tax_rate'] ?? 0 ) ) ), 3 ), false );
        update_option( 'tzfd_quotes_default_markup', round( max( 0, (float) ( $in['markup'] ?? 0 ) ), 1 ), false );
        update_option( 'tzfd_quotes_validity_days', max( 1, min( 365, (int) ( $in['validity'] ?? 14 ) ) ), false );
        update_option( 'tzfd_quotes_deposit_percent', round( max( 0, min( 100, (float) ( $in['deposit_pct'] ?? 0 ) ) ), 1 ), false );
        update_option( 'tzfd_quotes_auto_send', ! empty( $in['auto_send'] ) ? '1' : '0', false );
        update_option( 'tzfd_quotes_extra_prompt', sanitize_textarea_field( (string) ( $in['extra_prompt'] ?? '' ) ), false );
        update_option( 'tzfd_quotes_terms', wp_kses_post( (string) ( $in['terms'] ?? '' ) ), false );
        if ( TZFD_Admin_Menu::posted_from_settings() ) {
            wp_safe_redirect( add_query_arg( 'quote_saved', '1', admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS ) ) . '#payments' );
            exit;
        }
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'settings', 'notice' => 'settings_saved' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ---------------------------------------------------------------
     * Screens
     * ------------------------------------------------------------- */

    public static function render_page() {
        if ( ! current_user_can( self::CAP_EDIT ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        wp_enqueue_script( 'tzfd-quotes-admin', TZFD_Brand::url( 'js/inline/quotes-admin.js' ), array(), '2.0.1', true );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
        $tab = isset( $_GET['qtab'] ) ? sanitize_key( wp_unslash( $_GET['qtab'] ) ) : 'quotes';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( in_array( $tab, array( 'catalog', 'settings' ), true ) && ! current_user_can( self::CAP_ADMIN ) ) { $tab = 'quotes'; }
        $tabs = array(
            'quotes'   => __( 'Quotes', 'titan-zero-trades-front-desk' ),
            'new'      => __( 'New quote', 'titan-zero-trades-front-desk' ),
            'catalog'  => __( 'Price catalog', 'titan-zero-trades-front-desk' ),
            'settings' => __( 'Settings', 'titan-zero-trades-front-desk' ),
        );
        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-quotes">';
        // The Quotes list draws its own header, notices and tabs (mock-up layout). Other views keep the title and tab bar.
        if ( 'quotes' !== $tab ) {
            echo '<h1>' . esc_html__( 'Quotes', 'titan-zero-trades-front-desk' ) . '</h1>';
            echo '<p class="tzfd-lead">' . esc_html__( 'Prepare quotes for customers, send them by email, and track whether they are approved.', 'titan-zero-trades-front-desk' ) . '</p>';
            self::notice_html();
            echo '<nav class="nav-tab-wrapper tzfd-tab-wrapper" aria-label="' . esc_attr__( 'Quote tabs', 'titan-zero-trades-front-desk' ) . '">';
            foreach ( $tabs as $key => $label ) {
                if ( in_array( $key, array( 'catalog', 'settings' ), true ) && ! current_user_can( self::CAP_ADMIN ) ) { continue; }
                $url = add_query_arg( array( 'page' => self::PAGE, 'qtab' => $key ), admin_url( 'admin.php' ) );
                echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav>';
        }
        switch ( $tab ) {
            case 'new':      self::render_new(); break;
            case 'catalog':  self::render_catalog(); break;
            case 'settings': self::render_settings(); break;
            default:         self::render_list();
        }
        echo '</div>';
    }

    /**
     * The Quotes list, as drawn in the mock-up: header with New quote, four tiles, status tabs, a filter bar
     * with search and date range, the quote table, and a footer. The editor opens below the header when an id is given.
     */
    private static function render_list() {
        global $wpdb;
        $table    = $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES;
        $is_admin = current_user_can( self::CAP_ADMIN );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters for the list.
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $range  = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'all';
        $id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        TZFD_Admin_Layout::header( __( 'Quotes', 'titan-zero-trades-front-desk' ), __( 'Prepare quotes for customers, send them by email, and track whether they are approved.', 'titan-zero-trades-front-desk' ),
            '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'new' ), admin_url( 'admin.php' ) ) ) . '">+ ' . esc_html__( 'New quote', 'titan-zero-trades-front-desk' ) . '</a>' );
        self::notice_html();

        if ( $id > 0 ) {
            self::render_editor( $id );
            return;
        }

        $counts = array( 'draft' => array( 0, 0.0 ), 'sent' => array( 0, 0.0 ), 'approved' => array( 0, 0.0 ), 'declined' => array( 0, 0.0 ), 'expired' => array( 0, 0.0 ) );
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, COALESCE(SUM(total),0) AS value FROM %i GROUP BY status', $table ) ) as $row ) {
            if ( isset( $counts[ $row->status ] ) ) { $counts[ $row->status ] = array( (int) $row->n, (float) $row->value ); }
        }
        $all_n      = array_sum( array_column( $counts, 0 ) );
        $month      = substr( (string) current_time( 'Y-m-d' ), 0, 7 ) . '-01';
        $accepted_m = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'approved' AND updated >= %s", $table, $month . ' 00:00:00' ) );
        $quoted_v   = $counts['draft'][1] + $counts['sent'][1] + $counts['approved'][1];

        echo '<div class="tzfd-stats" role="list">';
        TZFD_Admin_Layout::stat( (string) $counts['draft'][0], __( 'Draft', 'titan-zero-trades-front-desk' ), __( 'not sent', 'titan-zero-trades-front-desk' ), 'ink', add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'quotes', 'status' => 'draft' ), admin_url( 'admin.php' ) ) );
        TZFD_Admin_Layout::stat( (string) $counts['sent'][0], __( 'Awaiting reply', 'titan-zero-trades-front-desk' ), __( 'sent to customers', 'titan-zero-trades-front-desk' ), 'blue', add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'quotes', 'status' => 'sent' ), admin_url( 'admin.php' ) ) );
        TZFD_Admin_Layout::stat( (string) $accepted_m, __( 'Accepted', 'titan-zero-trades-front-desk' ), __( 'this month', 'titan-zero-trades-front-desk' ), 'green', add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'quotes', 'status' => 'approved' ), admin_url( 'admin.php' ) ) );
        TZFD_Admin_Layout::stat( self::money( $quoted_v ), __( 'Quoted value', 'titan-zero-trades-front-desk' ), __( 'open and accepted', 'titan-zero-trades-front-desk' ), 'red' );
        echo '</div>';

        $tabs = array(
            'all'      => array( __( 'All quotes', 'titan-zero-trades-front-desk' ), $all_n ),
            'draft'    => array( __( 'Draft', 'titan-zero-trades-front-desk' ), $counts['draft'][0] ),
            'sent'     => array( __( 'Sent', 'titan-zero-trades-front-desk' ), $counts['sent'][0] ),
            'approved' => array( __( 'Accepted', 'titan-zero-trades-front-desk' ), $counts['approved'][0] ),
            'declined' => array( __( 'Declined', 'titan-zero-trades-front-desk' ), $counts['declined'][0] ),
        );
        if ( ! isset( $tabs[ $status ] ) ) { $status = 'all'; }

        echo '<div class="tzfd-card">';
        echo '<nav class="tzfd-tabs" aria-label="' . esc_attr__( 'Quote status', 'titan-zero-trades-front-desk' ) . '">';
        foreach ( $tabs as $key => $tab ) {
            $url = add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'quotes', 'status' => $key, 's' => $search, 'range' => $range ), admin_url( 'admin.php' ) );
            echo '<a href="' . esc_url( $url ) . '"' . ( $key === $status ? ' class="is-active" aria-current="page"' : '' ) . '>' . esc_html( $tab[0] ) . ' <span class="tzfd-muted">' . (int) $tab[1] . '</span></a>';
        }
        echo '</nav>';

        echo '<form method="get" class="tzfd-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="qtab" value="quotes" /><input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search customer or quote', 'titan-zero-trades-front-desk' ) . '" aria-label="' . esc_attr__( 'Search customer or quote', 'titan-zero-trades-front-desk' ) . '" />';
        echo '<label class="screen-reader-text" for="tzfd_q_range">' . esc_html__( 'Date range', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_q_range" name="range">';
        foreach ( array( 'all' => __( 'All dates', 'titan-zero-trades-front-desk' ), 'month' => __( 'This month', 'titan-zero-trades-front-desk' ), '30' => __( 'Last 30 days', 'titan-zero-trades-front-desk' ) ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $range, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><button class="tzfd-btn tzfd-btn-secondary" type="submit">' . esc_html__( 'Filter', 'titan-zero-trades-front-desk' ) . '</button>';
        if ( $is_admin ) {
            echo '<span class="tzfd-filter-spacer"></span><a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'catalog' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Price catalog', 'titan-zero-trades-front-desk' ) . '</a>';
        }
        echo '</form>';

        $rows = self::list_rows( $status, $search );
        if ( 'all' !== $range ) {
            $from = 'month' === $range ? $month : gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - 30 * DAY_IN_SECONDS );
            $rows = array_values( array_filter( $rows, function ( $q ) use ( $from ) { return substr( (string) $q->updated, 0, 10 ) >= $from; } ) );
        }
        if ( empty( $rows ) ) {
            echo '<div class="tzfd-empty-list">';
            TZFD_Brand::empty_state( 'quotes', '' === $search && 'all' === $status && 'all' === $range ? __( 'No quotes yet. Start one from New quote, from a booking.', 'titan-zero-trades-front-desk' ) : __( 'No quotes match these filters.', 'titan-zero-trades-front-desk' ) );
            echo '</div></div>';
        } else {
            echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table"><thead><tr>';
            foreach ( array( __( 'Quote', 'titan-zero-trades-front-desk' ), __( 'Customer', 'titan-zero-trades-front-desk' ), __( 'Service', 'titan-zero-trades-front-desk' ), __( 'Total', 'titan-zero-trades-front-desk' ), __( 'Updated', 'titan-zero-trades-front-desk' ), __( 'Status', 'titan-zero-trades-front-desk' ), '' ) as $h ) {
                echo '<th>' . esc_html( $h ) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ( $rows as $q ) {
                $open = admin_url( 'admin.php?page=' . self::PAGE . '&qtab=quotes&id=' . (int) $q->id );
                echo '<tr><td><strong>' . esc_html( $q->reference ) . '</strong></td>';
                echo '<td>' . esc_html( $q->customer_name ?: $q->customer_email ) . '</td>';
                echo '<td>' . esc_html( $q->service_type ) . '</td>';
                echo '<td><strong>' . esc_html( self::money( $q->total ) ) . '</strong></td>';
                echo '<td>' . esc_html( mysql2date( 'j M', (string) $q->updated ) ) . '</td>';
                echo '<td>' . wp_kses( self::status_badge( $q->status ), TZFD_Brand::allowed_html() ) . '</td>';
                echo '<td class="tzfd-row-actions"><a class="tzfd-open" href="' . esc_url( $open ) . '">' . esc_html__( 'Open', 'titan-zero-trades-front-desk' ) . ' &rarr;</a> &middot; ';
                echo '<a class="tzfd-open" href="' . esc_url( TZFD_Quote::public_url( $q->token ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Customer view', 'titan-zero-trades-front-desk' ) . '</a></td></tr>';
            }
            echo '</tbody></table></div>';
            echo '<p class="tzfd-pager">' . esc_html( sprintf( /* translators: 1: quotes shown, 2: quotes in total. */ __( 'Showing %1$d of %2$d quotes', 'titan-zero-trades-front-desk' ), count( $rows ), $all_n ) ) . '</p>';
            echo '</div>';
        }

        echo '<div class="tzfd-card tzfd-foot-card"><div><h3>' . esc_html__( 'Build quotes', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<p>' . esc_html__( 'Use your price catalog, add clear line items and send a review link to the customer.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        if ( $is_admin ) {
            echo '<div class="tzfd-page-actions"><a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'catalog' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open price catalog', 'titan-zero-trades-front-desk' ) . '</a>';
            echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'settings' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Settings', 'titan-zero-trades-front-desk' ) . '</a></div>';
        }
        echo '</div>';
    }

    private static function list_rows( $status, $search ) {
        global $wpdb;
        // One fixed query. The status filter is 'any' (no filter), 'open' (draft or sent) or 'one' (the given status).
        // The search filter is off when the search term is empty. Every value is bound with prepare().
        $mode = 'open' === $status ? 'open' : ( in_array( $status, self::STATUSES, true ) ? 'one' : 'any' );
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE ( %s = 'any' OR ( %s = 'open' AND status IN ('draft','sent') ) OR ( %s = 'one' AND status = %s ) )
             AND ( %s = '' OR reference LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR service_type LIKE %s )
             ORDER BY updated DESC, id DESC LIMIT %d",
            $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES,
            $mode, $mode, $mode, $status,
            $search, $like, $like, $like, $like,
            self::ROW_LIMIT
        ) );
    }

    private static function status_badge( $status ) {
        $labels = array( 'approved' => __( 'Accepted', 'titan-zero-trades-front-desk' ), 'sent' => __( 'Sent', 'titan-zero-trades-front-desk' ), 'draft' => __( 'Draft', 'titan-zero-trades-front-desk' ), 'declined' => __( 'Declined', 'titan-zero-trades-front-desk' ), 'expired' => __( 'Expired', 'titan-zero-trades-front-desk' ) );
        $label  = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
        $tags   = array( 'draft' => 'draft', 'sent' => 'quote-sent', 'approved' => 'confirmed', 'declined' => 'cancelled', 'expired' => 'cancelled' );
        $tag    = isset( $tags[ $status ] ) ? $tags[ $status ] : 'draft';
        return '<span class="tzfd-badge tzfd-badge-' . esc_attr( in_array( $status, array_keys( $labels ), true ) ? $status : 'draft' ) . '">' . TZFD_Brand::status_tag( $tag, $label ) . '</span>';
    }

    /** Editor for one quote. Approved quotes are shown read-only. */
    private static function render_editor( $id ) {
        $q = TZFD_Quote::get( $id );
        if ( ! $q ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( self::messages()['error']['not_found'] ) . '</p></div>';
            return;
        }
        $items    = TZFD_Quote::items( $id );
        $approved = 'approved' === $q->status;
        $back     = add_query_arg( array( 'page' => self::PAGE, 'qtab' => 'quotes' ), admin_url( 'admin.php' ) );

        echo '<div class="tzfd-card" id="tzfd-quote-editor">';
        echo '<div class="tzfd-list-head"><div><h2 class="tzfd-section-title">' . esc_html( $q->reference ) . ' · ' . esc_html( $q->customer_name ?: $q->customer_email ) . '</h2>';
        echo '<p class="tzfd-muted">' . wp_kses( self::status_badge( $q->status ), TZFD_Brand::allowed_html() ) . ' ' . esc_html__( 'Last saved', 'titan-zero-trades-front-desk' ) . ' ' . esc_html( substr( (string) $q->updated, 0, 16 ) ) . '</p></div>';
        echo '<div><a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All quotes', 'titan-zero-trades-front-desk' ) . '</a></div></div>';

        if ( $approved ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The customer approved this quote. It is kept as it was agreed. Duplicate it to make changes.', 'titan-zero-trades-front-desk' ) . '</p></div>';
            self::render_readonly( $q, $items );
        } else {
            self::render_form( $q, $items );
        }

        echo '<div class="tzfd-actions">';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( TZFD_Quote::public_url( $q->token ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview customer view', 'titan-zero-trades-front-desk' ) . '</a>';
        self::small_post_button( 'tzfd_quote_duplicate', $id, __( 'Duplicate', 'titan-zero-trades-front-desk' ), 'tzfd-btn-secondary', '' );
        if ( 'sent' === $q->status ) {
            self::small_post_button( 'tzfd_quote_send', $id, __( 'Send again', 'titan-zero-trades-front-desk' ), 'tzfd-btn-secondary', __( 'Email the quote to the customer again?', 'titan-zero-trades-front-desk' ) );
        }
        if ( ! $approved ) {
            self::small_post_button( 'tzfd_quote_delete', $id, __( 'Delete', 'titan-zero-trades-front-desk' ), 'tzfd-btn-danger', __( 'Delete this quote? This cannot be undone.', 'titan-zero-trades-front-desk' ) );
        }
        echo '</div></div>';
    }

    /** Small form that posts one action for this quote (Duplicate, Send again, Delete). */
    private static function small_post_button( $action, $id, $label, $class, $confirm ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tzfd-inline-form"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="quote_id" value="' . (int) $id . '" />';
        wp_nonce_field( $action . '_' . (int) $id, self::NONCE_FIELD );
        echo '<button type="submit" class="tzfd-btn ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
    }

    private static function render_readonly( $q, $items ) {
        echo '<table class="widefat striped tzfd-table"><thead><tr><th>' . esc_html__( 'Item', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Qty', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Unit price', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Line total', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
        foreach ( $items as $it ) {
            echo '<tr><td>' . esc_html( $it->label ) . '</td><td>' . esc_html( rtrim( rtrim( number_format( (float) $it->qty, 2 ), '0' ), '.' ) ) . '</td>';
            echo '<td>' . esc_html( self::money( $it->unit_price ) ) . '</td><td>' . esc_html( self::money( (float) $it->qty * (float) $it->unit_price ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="tzfd-section-title">' . esc_html__( 'Total', 'titan-zero-trades-front-desk' ) . ': <strong>' . esc_html( self::money( $q->total ) ) . '</strong></p>';
    }

    /** The editable quote form: customer, job, line items, adjustments and notes. */
    private static function render_form( $q, $items ) {
        $sym_sample = TZFD_Money::format( 1234.5 );
        $decimals   = (int) TZFD_Money::settings()['decimals'];
        $tpl        = str_replace( array( '1,234.50', '1,235' ), '%s', $sym_sample );
        $next       = 0;
        foreach ( $items as $i => $it ) { $next = max( $next, $i + 1 ); }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="tzfd-quote-form">';
        echo '<input type="hidden" name="action" value="tzfd_quote_save" /><input type="hidden" name="quote_id" value="' . (int) $q->id . '" />';
        wp_nonce_field( 'tzfd_quote_save_' . (int) $q->id, self::NONCE_FIELD );

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</h3><div class="tzfd-form-grid">';
        self::field( 'customer_name', __( 'Name', 'titan-zero-trades-front-desk' ), $q->customer_name, 'text', true );
        self::field( 'customer_email', __( 'Email', 'titan-zero-trades-front-desk' ), $q->customer_email, 'email' );
        self::field( 'customer_phone', __( 'Phone', 'titan-zero-trades-front-desk' ), $q->customer_phone, 'tel' );
        echo '<div class="tzfd-full"><label for="tzfd_q_address">' . esc_html__( 'Where the job is', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_q_address" name="service_address" rows="2" class="large-text">' . esc_textarea( $q->service_address ) . '</textarea></div>';
        echo '</div>';

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Job', 'titan-zero-trades-front-desk' ) . '</h3><div class="tzfd-form-grid">';
        self::field( 'service_type', __( 'Service', 'titan-zero-trades-front-desk' ), $q->service_type, 'text' );
        echo '<div><label for="tzfd_q_urgency">' . esc_html__( 'Urgency', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_q_urgency" name="urgency">';
        foreach ( self::URGENCY as $u ) { echo '<option value="' . esc_attr( $u ) . '"' . selected( $q->urgency, $u, false ) . '>' . esc_html( ucfirst( $u ) ) . '</option>'; }
        echo '</select></div>';
        echo '<div><label for="tzfd_q_valid">' . esc_html__( 'Valid until', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_q_valid" type="date" name="valid_until" value="' . esc_attr( (string) $q->valid_until ) . '" /></div>';
        echo '<div><label for="tzfd_q_status">' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_q_status" name="status">';
        foreach ( self::SET_STATUSES as $s ) { echo '<option value="' . esc_attr( $s ) . '"' . selected( $q->status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>'; }
        echo '</select><p class="description">' . esc_html__( 'Customers approve a quote themselves on its page. Set Approved only through that.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<div class="tzfd-full"><label for="tzfd_q_desc">' . esc_html__( 'Job description', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_q_desc" name="description" rows="2" class="large-text">' . esc_textarea( $q->description ) . '</textarea></div>';
        echo '</div>';

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Line items', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table tzfd-quote-items"><thead><tr>';
        foreach ( array( __( 'Type', 'titan-zero-trades-front-desk' ), __( 'SKU', 'titan-zero-trades-front-desk' ), __( 'Description', 'titan-zero-trades-front-desk' ), __( 'Qty', 'titan-zero-trades-front-desk' ), __( 'Unit', 'titan-zero-trades-front-desk' ), __( 'Unit price', 'titan-zero-trades-front-desk' ), __( 'Taxable', 'titan-zero-trades-front-desk' ), __( 'Line total', 'titan-zero-trades-front-desk' ), '' ) as $h ) {
            echo '<th>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody id="tzfd-quote-rows">';
        foreach ( $items as $i => $it ) {
            self::item_row( $i, array(
                'kind' => $it->kind, 'sku' => $it->sku, 'label' => $it->label, 'qty' => $it->qty,
                'unit' => $it->unit, 'unit_price' => $it->unit_price, 'taxable' => $it->taxable,
            ), $decimals );
        }
        echo '</tbody></table></div>';
        echo '<p><button type="button" class="tzfd-btn tzfd-btn-secondary" id="tzfd-quote-add-row">+ ' . esc_html__( 'Add line item', 'titan-zero-trades-front-desk' ) . '</button> ';
        echo '<span class="tzfd-muted">' . esc_html__( 'Leave the description blank and the line is dropped when you save. Pick items from the Price catalog tab by SKU.', 'titan-zero-trades-front-desk' ) . '</span></p>';

        echo '<template id="tzfd-quote-row-template">';
        self::item_row( '__I__', array( 'kind' => 'labor', 'sku' => '', 'label' => '', 'qty' => 1, 'unit' => 'each', 'unit_price' => 0, 'taxable' => 1 ), $decimals );
        echo '</template>';

        echo '<div class="tzfd-form-grid tzfd-quote-bottom"><div>';
        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Adjustments', 'titan-zero-trades-front-desk' ) . '</h3><div class="tzfd-form-grid">';
        echo '<div><label for="tzfd_q_tax">' . esc_html__( 'Tax rate (%)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_q_tax" type="number" step="0.001" min="0" max="100" class="tzfd-calc" name="tax_rate" value="' . esc_attr( rtrim( rtrim( number_format( (float) $q->tax_rate, 3, '.', '' ), '0' ), '.' ) ) . '" /></div>';
        echo '<div><label for="tzfd_q_disc">' . esc_html__( 'Discount (amount)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_q_disc" type="number" step="0.01" min="0" class="tzfd-calc" name="discount" value="' . esc_attr( number_format( (float) $q->discount, 2, '.', '' ) ) . '" /></div>';
        echo '<div><label for="tzfd_q_dep">' . esc_html__( 'Deposit due on approval', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_q_dep" type="number" step="0.01" min="0" name="deposit" value="' . esc_attr( number_format( (float) $q->deposit, 2, '.', '' ) ) . '" /></div>';
        echo '</div></div>';
        echo '<div><h3 class="tzfd-section-title">' . esc_html__( 'Totals', 'titan-zero-trades-front-desk' ) . '</h3><table class="widefat tzfd-dl-table"><tbody>';
        echo '<tr><td>' . esc_html__( 'Subtotal', 'titan-zero-trades-front-desk' ) . '</td><td id="tzfd-t-sub">' . esc_html( self::money( $q->subtotal ) ) . '</td></tr>';
        echo '<tr><td>' . esc_html__( 'Discount', 'titan-zero-trades-front-desk' ) . '</td><td id="tzfd-t-disc">' . esc_html( '-' . self::money( $q->discount ) ) . '</td></tr>';
        echo '<tr><td>' . esc_html__( 'Tax', 'titan-zero-trades-front-desk' ) . '</td><td id="tzfd-t-tax">' . esc_html( self::money( $q->tax_amount ) ) . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__( 'Total', 'titan-zero-trades-front-desk' ) . '</strong></td><td id="tzfd-t-total"><strong>' . esc_html( self::money( $q->total ) ) . '</strong></td></tr>';
        echo '<tr><td>' . esc_html__( 'Deposit', 'titan-zero-trades-front-desk' ) . '</td><td id="tzfd-t-dep">' . esc_html( self::money( $q->deposit ) ) . '</td></tr>';
        echo '</tbody></table><p class="description">' . esc_html__( 'Totals shown here update as you type. The saved totals are recalculated when you save.', 'titan-zero-trades-front-desk' ) . '</p></div></div>';

        echo '<div class="tzfd-form-grid">';
        echo '<div class="tzfd-full"><label for="tzfd_q_msg">' . esc_html__( 'Message to the customer (shown on the quote)', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_q_msg" name="customer_message" rows="3" class="large-text">' . esc_textarea( $q->customer_message ) . '</textarea></div>';
        echo '<div class="tzfd-full"><label for="tzfd_q_notes">' . esc_html__( 'Internal notes (not shown to the customer)', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_q_notes" name="notes" rows="3" class="large-text">' . esc_textarea( $q->notes ) . '</textarea></div>';
        echo '</div>';

        echo '<div class="tzfd-actions">';
        echo '<button type="submit" name="intent" value="save" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Save quote', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '<button type="submit" name="intent" value="send" class="tzfd-btn tzfd-btn-secondary" data-confirm="' . esc_attr( __( 'Save and email this quote to the customer now?', 'titan-zero-trades-front-desk' ) ) . '">' . esc_html__( 'Save and email to customer', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '</div></form>';

        self::editor_script( $tpl, $decimals, $next );
    }

    private static function field( $name, $label, $value, $type = 'text', $required = false ) {
        $id = 'tzfd_q_' . $name;
        echo '<div><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text"' . ( $required ? ' required' : '' ) . ' /></div>';
    }

    /** One line item row. $i is a number, or '__I__' for the template the script copies. */
    private static function item_row( $i, array $it, $decimals ) {
        $kinds = array( 'labor' => __( 'Labour', 'titan-zero-trades-front-desk' ), 'material' => __( 'Materials', 'titan-zero-trades-front-desk' ), 'fee' => __( 'Fee', 'titan-zero-trades-front-desk' ), 'travel' => __( 'Travel', 'titan-zero-trades-front-desk' ), 'other' => __( 'Other', 'titan-zero-trades-front-desk' ) );
        $p     = 'items[' . $i . ']';
        $qty   = number_format( (float) $it['qty'], 2, '.', '' );
        $price = number_format( (float) $it['unit_price'], 2, '.', '' );
        $line  = (float) $it['qty'] * (float) $it['unit_price'];
        echo '<tr class="tzfd-quote-item">';
        echo '<td><select name="' . esc_attr( $p ) . '[kind]">';
        foreach ( $kinds as $k => $label ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $it['kind'], $k, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></td>';
        echo '<td><input type="text" name="' . esc_attr( $p ) . '[sku]" value="' . esc_attr( $it['sku'] ) . '" size="8" /></td>';
        echo '<td><input type="text" name="' . esc_attr( $p ) . '[label]" value="' . esc_attr( $it['label'] ) . '" class="regular-text" /></td>';
        echo '<td><input type="number" step="0.01" min="0" name="' . esc_attr( $p ) . '[qty]" value="' . esc_attr( $qty ) . '" class="small-text tzfd-calc tzfd-qty" /></td>';
        echo '<td><input type="text" name="' . esc_attr( $p ) . '[unit]" value="' . esc_attr( $it['unit'] ) . '" size="6" /></td>';
        echo '<td><input type="number" step="0.01" min="0" name="' . esc_attr( $p ) . '[unit_price]" value="' . esc_attr( $price ) . '" class="small-text tzfd-calc tzfd-price" /></td>';
        echo '<td><input type="checkbox" name="' . esc_attr( $p ) . '[taxable]" value="1"' . checked( (int) $it['taxable'], 1, false ) . ' class="tzfd-calc tzfd-taxable" /></td>';
        echo '<td class="tzfd-line-total">' . esc_html( self::money( $line ) ) . '</td>';
        echo '<td><button type="button" class="tzfd-btn tzfd-btn-secondary tzfd-row-remove" aria-label="' . esc_attr__( 'Remove this line', 'titan-zero-trades-front-desk' ) . '">' . esc_html__( 'Remove', 'titan-zero-trades-front-desk' ) . '</button></td>';
        echo '</tr>';
    }

    /** Keeps the totals box and line totals in step while typing. The server recalculates on save. */
    private static function editor_script( $tpl, $decimals, $next ) {
        wp_enqueue_script( 'tzfd-quotes-editor', TZFD_Brand::url( 'js/inline/quotes-editor.js' ), array(), '2.0.1', true );
        wp_localize_script( 'tzfd-quotes-editor', 'tzfdQuoteEditor', array(
            'tpl'  => (string) $tpl,
            'dec'  => (int) $decimals,
            'next' => (int) $next,
        ) );
    }

    /** New quote form: customer and job, optionally from a booking. */
    private static function render_new() {
        $ai       = TZFD_Addon_Support::ai_configured();
        $choices  = self::booking_choices();
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'New quote', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p class="tzfd-muted">' . ( $ai
            ? esc_html__( 'Describe the job. The AI writes a line-item estimate from your price catalog and past accepted quotes. Check every line before you send it.', 'titan-zero-trades-front-desk' )
            : esc_html__( 'Start a draft for a customer. Add the line items in the editor, from the price catalog or by hand. Nothing is estimated automatically.', 'titan-zero-trades-front-desk' ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tzfd_quote_create" />';
        wp_nonce_field( 'tzfd_quote_create', self::NONCE_FIELD );
        echo '<div class="tzfd-form-grid">';
        echo '<div class="tzfd-full"><label for="tzfd_n_booking">' . esc_html__( 'From a booking (optional)', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_n_booking" name="booking"><option value="">' . esc_html__( 'Not from a booking', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $choices as $value => $label ) { echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $label ) . '</option>'; }
        echo '</select><p class="description">' . esc_html__( 'Fills in the customer, service, address and the service price. Bookings from the last 60 days and the next 60 days.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        self::field( 'customer_name', __( 'Customer name', 'titan-zero-trades-front-desk' ), '', 'text', true );
        self::field( 'customer_email', __( 'Email', 'titan-zero-trades-front-desk' ), '', 'email' );
        self::field( 'customer_phone', __( 'Phone', 'titan-zero-trades-front-desk' ), '', 'tel' );
        self::field( 'service_type', __( 'Service', 'titan-zero-trades-front-desk' ), '', 'text' );
        echo '<div class="tzfd-full"><label for="tzfd_n_address">' . esc_html__( 'Where the job is', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_n_address" name="service_address" rows="2" class="large-text"></textarea></div>';
        echo '<div><label for="tzfd_n_urgency">' . esc_html__( 'Urgency', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_n_urgency" name="urgency">';
        foreach ( self::URGENCY as $u ) { echo '<option value="' . esc_attr( $u ) . '">' . esc_html( ucfirst( $u ) ) . '</option>'; }
        echo '</select></div>';
        echo '<div class="tzfd-full"><label for="tzfd_n_desc">' . esc_html( $ai ? __( 'Describe the job', 'titan-zero-trades-front-desk' ) : __( 'Job notes', 'titan-zero-trades-front-desk' ) ) . '</label><textarea id="tzfd_n_desc" name="description" rows="4" class="large-text"></textarea></div>';
        echo '</div><div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html( $ai ? __( 'Write the quote', 'titan-zero-trades-front-desk' ) : __( 'Create draft quote', 'titan-zero-trades-front-desk' ) ) . '</button></div></form></div>';
    }

    /** Price catalog: add items, remove items, and import the starter list. */
    private static function render_catalog() {
        global $wpdb;
        $items = TZFD_Quote_Catalog::all();
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Add an item', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tzfd_quote_catalog_save" />';
        wp_nonce_field( 'tzfd_quote_catalog_save', self::NONCE_FIELD );
        echo '<div class="tzfd-form-grid">';
        self::field( 'label', __( 'Description', 'titan-zero-trades-front-desk' ), '', 'text', true );
        self::field( 'sku', __( 'SKU (optional)', 'titan-zero-trades-front-desk' ), '', 'text' );
        echo '<div><label for="tzfd_c_kind">' . esc_html__( 'Type', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_c_kind" name="kind">';
        foreach ( self::KINDS as $k ) { echo '<option value="' . esc_attr( $k ) . '">' . esc_html( ucfirst( $k ) ) . '</option>'; }
        echo '</select></div>';
        self::field( 'unit', __( 'Unit', 'titan-zero-trades-front-desk' ), 'each', 'text' );
        self::field( 'unit_price', __( 'Unit price', 'titan-zero-trades-front-desk' ), '', 'number' );
        self::field( 'service_type', __( 'Service', 'titan-zero-trades-front-desk' ), '', 'text' );
        self::field( 'keywords', __( 'Keywords for the AI (comma separated)', 'titan-zero-trades-front-desk' ), '', 'text' );
        echo '<div><label class="tzfd-check"><input type="checkbox" name="taxable" value="1" checked /> ' . esc_html__( 'Taxable', 'titan-zero-trades-front-desk' ) . '</label></div>';
        echo '</div><div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Add to catalog', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '</div></form></div>';

        echo '<div class="tzfd-card"><div class="tzfd-list-head"><h2 class="tzfd-section-title">' . esc_html__( 'Catalog', 'titan-zero-trades-front-desk' ) . ' (' . count( $items ) . ')</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tzfd_quote_catalog_seed" />';
        wp_nonce_field( 'tzfd_quote_catalog_seed', self::NONCE_FIELD );
        echo '<button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Add starter catalog', 'titan-zero-trades-front-desk' ) . '</button></form></div>';
        if ( empty( $items ) ) {
            echo '<p class="tzfd-muted">' . esc_html__( 'No items yet. Add your own above, or add the starter catalog for common trades.', 'titan-zero-trades-front-desk' ) . '</p></div>';
            return;
        }
        echo '<div class="tzfd-table-wrap"><table class="widefat striped tzfd-table"><thead><tr>';
        foreach ( array( 'SKU', __( 'Description', 'titan-zero-trades-front-desk' ), __( 'Type', 'titan-zero-trades-front-desk' ), __( 'Unit', 'titan-zero-trades-front-desk' ), __( 'Price', 'titan-zero-trades-front-desk' ), __( 'Service', 'titan-zero-trades-front-desk' ), __( 'Taxable', 'titan-zero-trades-front-desk' ), '' ) as $h ) {
            echo '<th>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $items as $i ) {
            echo '<tr><td><code>' . esc_html( $i->sku ) . '</code></td><td>' . esc_html( $i->label ) . '</td><td>' . esc_html( $i->kind ) . '</td><td>' . esc_html( $i->unit ) . '</td>';
            echo '<td>' . esc_html( self::money( $i->unit_price ) ) . '</td><td>' . esc_html( $i->service_type ) . '</td><td>' . ( $i->taxable ? '✓' : '' ) . '</td>';
            echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tzfd-inline-form" data-confirm="' . esc_attr( __( 'Remove this item from the catalog?', 'titan-zero-trades-front-desk' ) ) . '">';
            echo '<input type="hidden" name="action" value="tzfd_quote_catalog_delete" /><input type="hidden" name="item_id" value="' . (int) $i->id . '" />';
            wp_nonce_field( 'tzfd_quote_catalog_delete_' . (int) $i->id, self::NONCE_FIELD );
            echo '<button type="submit" class="tzfd-btn tzfd-btn-danger">' . esc_html__( 'Remove', 'titan-zero-trades-front-desk' ) . '</button></form></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** Quote settings: tax, markup, validity, deposit, AI prompt and terms. Currency is under Business and money. */
    private static function render_settings() {
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Quote settings', 'titan-zero-trades-front-desk' ) . '</h2>';
        self::render_settings_form( false );
        echo '</div>';
    }

    /** Quote money defaults. Settings embeds it with $in_settings true, so it returns to Settings after saving. */
    public static function render_settings_form( $in_settings = false ) {
        echo '<p class="tzfd-muted">' . esc_html__( 'Currency and business details are under Settings, General.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tzfd_quote_settings_save" />';
        if ( $in_settings ) { echo '<input type="hidden" name="return_to" value="settings" />'; }
        wp_nonce_field( 'tzfd_quote_settings_save', self::NONCE_FIELD );
        echo '<div class="tzfd-form-grid">';
        echo '<div><label for="tzfd_s_tax">' . esc_html__( 'Default tax rate (%)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_s_tax" type="number" step="0.001" min="0" max="100" name="tax_rate" value="' . esc_attr( get_option( 'tzfd_quotes_tax_rate', 0 ) ) . '" /></div>';
        echo '<div><label for="tzfd_s_markup">' . esc_html__( 'Default markup on materials (%)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_s_markup" type="number" step="0.1" min="0" name="markup" value="' . esc_attr( get_option( 'tzfd_quotes_default_markup', 20 ) ) . '" /></div>';
        echo '<div><label for="tzfd_s_validity">' . esc_html__( 'Quotes valid for (days)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_s_validity" type="number" min="1" max="365" name="validity" value="' . esc_attr( (int) get_option( 'tzfd_quotes_validity_days', 14 ) ) . '" /></div>';
        echo '<div><label for="tzfd_s_deposit">' . esc_html__( 'Default deposit (%)', 'titan-zero-trades-front-desk' ) . '</label><input id="tzfd_s_deposit" type="number" step="0.1" min="0" max="100" name="deposit_pct" value="' . esc_attr( get_option( 'tzfd_quotes_deposit_percent', 0 ) ) . '" /></div>';
        echo '<div class="tzfd-full"><label class="tzfd-check"><input type="checkbox" name="auto_send" value="1"' . checked( get_option( 'tzfd_quotes_auto_send', '0' ), '1', false ) . ' /> ' . esc_html__( 'Email the customer as soon as the AI writes a quote (skip review).', 'titan-zero-trades-front-desk' ) . '</label></div>';
        if ( TZFD_Addon_Support::ai_configured() ) {
            echo '<div class="tzfd-full"><label for="tzfd_s_prompt">' . esc_html__( 'Additional AI instructions', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_s_prompt" name="extra_prompt" rows="3" class="large-text">' . esc_textarea( get_option( 'tzfd_quotes_extra_prompt', '' ) ) . '</textarea><p class="description">' . esc_html__( 'Anything special: minimum job size, pricing strategy, upsell rules.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        echo '<div class="tzfd-full"><label for="tzfd_s_terms">' . esc_html__( 'Terms and conditions (shown on every quote)', 'titan-zero-trades-front-desk' ) . '</label><textarea id="tzfd_s_terms" name="terms" rows="4" class="large-text">' . esc_textarea( get_option( 'tzfd_quotes_terms', 'Estimate is valid for 14 days. Final pricing may adjust after on-site inspection.' ) ) . '</textarea></div>';
        echo '</div><div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Save settings', 'titan-zero-trades-front-desk' ) . '</button></div></form>';
    }
}
