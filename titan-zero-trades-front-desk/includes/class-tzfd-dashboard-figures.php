<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned tables. Values are bound with prepare() and %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-money.php';
require_once __DIR__ . '/class-tzfd-booking-view.php';
require_once __DIR__ . '/class-tzfd-payments.php';

/**
 * The headline figures across the top of the Dashboard: money booked today, bookings today,
 * payments received, and quotes waiting. The dashboard add-on skips its own count cells when
 * these are shown, so each figure appears once.
 */
final class TZFD_Dashboard_Figures {
    private static $shown = false;

    /** True once the top figures have been printed on this page. */
    public static function shown() {
        return self::$shown;
    }

    /** Price of each service, keyed by calendar ID and service name. */
    public static function service_prices() {
        global $wpdb;
        $map  = array();
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, form_structure FROM %i', $wpdb->prefix . 'tzfd_forms' ) );
        foreach ( (array) $rows as $row ) {
            foreach ( TZFD_Admin_Menu::services_in( (string) $row->form_structure ) as $service ) {
                $price = TZFD_Money::parse_amount( $service['price'] );
                $map[ (int) $row->id ][ $service['name'] ] = null === $price ? 0.0 : (float) $price;
            }
        }
        return $map;
    }

    /**
     * Today's figures. Booked is the value of today's appointments that are not cancelled,
     * using each service's price. Quote counts are zero when the quotes engine is not loaded.
     */
    public static function figures( $today = null ) {
        $today  = $today ?: TZFD_Booking_View::today();
        $found  = TZFD_Booking_View::fetch( $today, $today );
        $prices = self::service_prices();
        $booked = 0.0;
        $count  = 0;
        $wait   = 0;
        foreach ( $found['items'] as $item ) {
            if ( 'cancelled' === $item['status_class'] ) { continue; }
            $count++;
            if ( 'pending' === $item['status_class'] ) { $wait++; }
            $booked += $prices[ $item['form_id'] ][ $item['service'] ] ?? 0.0;
        }
        $payments = TZFD_Payments::totals( $today );
        return array(
            'today'        => $today,
            'booked'       => round( $booked, 2 ),
            'bookings'     => $count,
            'pending'      => $wait,
            'payments'     => $payments,
            'quotes'       => self::quote_counts(),
        );
    }

    /** Quotes still in draft and quotes sent and waiting for the customer. */
    public static function quote_counts() {
        $out = array( 'available' => false, 'draft' => 0, 'sent' => 0 );
        if ( ! class_exists( 'TZFD_Quotes_Engine' ) ) { return $out; }
        global $wpdb;
        $table = $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { return $out; }
        $out['available'] = true;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE status IN ('draft','sent') GROUP BY status", $table ) );
        foreach ( (array) $rows as $row ) {
            $out[ $row->status ] = (int) $row->n;
        }
        return $out;
    }

    /**
     * The four headline tiles on the Dashboard: enquiries received today, bookings in the next seven days,
     * quotes sent and waiting for the customer, and bookings awaiting approval in that week.
     */
    public static function headline( $today = null ) {
        global $wpdb;
        $today = $today ?: TZFD_Booking_View::today();
        $found = TZFD_Booking_View::fetch( $today, TZFD_Booking_View::add_days( $today, 6 ) );
        $upcoming  = 0;
        $attention = 0;
        foreach ( $found['items'] as $item ) {
            if ( 'cancelled' === $item['status_class'] ) { continue; }
            $upcoming++;
            if ( 'pending' === $item['status_class'] ) { $attention++; }
        }
        $enquiries = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE `time` >= %s',
            $wpdb->prefix . TZFD_Bookings::TABLE,
            $today . ' 00:00:00'
        ) );
        $quotes = self::quote_counts();
        return array(
            'enquiries' => $enquiries,
            'upcoming'  => $upcoming,
            'follow'    => (int) $quotes['sent'],
            'attention' => $attention,
        );
    }

    /**
     * Set-up steps for the Dashboard welcome card, each with whether it is done. Read from the settings
     * the steps write, so the card follows the real state of the site.
     */
    public static function setup_steps() {
        global $wpdb;
        $calendars = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'tzfd_forms' ) );
        $services  = 0;
        foreach ( self::service_prices() as $calendar_services ) { $services += count( $calendar_services ); }
        $business  = get_option( TZFD_Money::BUSINESS, array() );
        $methods   = get_option( TZFD_Payments::METHODS_OPTION, array() );
        return array(
            array( 'label' => __( 'Create your first calendar', 'titan-zero-trades-front-desk' ), 'done' => $calendars > 0 ),
            array( 'label' => __( 'Add services and prices', 'titan-zero-trades-front-desk' ), 'done' => $services > 0 ),
            array( 'label' => __( 'Set your business details', 'titan-zero-trades-front-desk' ), 'done' => ! empty( $business ) ),
            array( 'label' => __( 'Choose how bookings are confirmed', 'titan-zero-trades-front-desk' ), 'done' => null !== get_option( 'titan_zero_front_desk_intake_default', null ) ),
            array( 'label' => __( 'Add a payment method', 'titan-zero-trades-front-desk' ), 'done' => ! empty( $methods ) ),
            array( 'label' => __( 'Finish the setup wizard', 'titan-zero-trades-front-desk' ), 'done' => '' !== (string) get_option( 'tzfd_setup_done', '' ) ),
        );
    }

    /** Prints the top cards of the Dashboard. */
    public static function render() {
        self::$shown = true;
        $f     = self::figures();
        $day_url = TZFD_Booking_View::url( array( 'view' => 'day', 'date' => $f['today'] ) );
        $cards = array(
            array(
                'href'  => $day_url,
                'value' => TZFD_Money::format( $f['booked'] ),
                'label' => __( 'Booked today', 'titan-zero-trades-front-desk' ),
                'sub'   => sprintf( /* translators: %d: number of appointments today. */ _n( '%d appointment', '%d appointments', $f['bookings'], 'titan-zero-trades-front-desk' ), $f['bookings'] ),
            ),
            array(
                'href'  => $day_url,
                'value' => (string) $f['bookings'],
                'label' => __( 'Bookings today', 'titan-zero-trades-front-desk' ),
                'sub'   => sprintf( /* translators: %d: bookings waiting for approval. */ __( '%d awaiting approval', 'titan-zero-trades-front-desk' ), $f['pending'] ),
            ),
            array(
                'href'  => admin_url( 'admin.php?page=' . TZFD_Payments::PAGE ),
                'value' => TZFD_Money::format( $f['payments']['total'] ),
                'label' => __( 'Total payments', 'titan-zero-trades-front-desk' ),
                'sub'   => sprintf( /* translators: %s: amount received today. */ __( '%s received today', 'titan-zero-trades-front-desk' ), TZFD_Money::format( $f['payments']['today'] ) ),
            ),
        );
        if ( $f['quotes']['available'] ) {
            $cards[] = array(
                'href'  => admin_url( 'admin.php?page=tzfd-quotes' ),
                'value' => (string) $f['quotes']['draft'],
                'label' => __( 'Quotes to send', 'titan-zero-trades-front-desk' ),
                'sub'   => sprintf( /* translators: %d: quotes sent and waiting for the customer. */ __( '%d waiting for the customer', 'titan-zero-trades-front-desk' ), $f['quotes']['sent'] ),
            );
        }

        echo '<div class="tzfd-stats tzfd-stats-top" role="list">';
        foreach ( $cards as $card ) {
            echo '<a class="tzfd-stat" role="listitem" href="' . esc_url( $card['href'] ) . '">';
            echo '<span class="tzfd-stat-n">' . esc_html( $card['value'] ) . '</span>';
            echo '<span class="tzfd-stat-l">' . esc_html( $card['label'] ) . '</span>';
            echo '<span class="tzfd-stat-s">' . esc_html( $card['sub'] ) . '</span></a>';
        }
        echo '</div>';
    }
}
