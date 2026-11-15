<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned tables. Values are bound with prepare() and %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-booking-view.php';
require_once __DIR__ . '/class-tzfd-company-read-context.php';

/**
 * The headline figures across the top of the Dashboard: money booked today, bookings today, and
 * enquiries and bookings needing attention. Payments and quotes now belong to Titan Zero Invoicing and
 * Titan Zero Quotes (19 Oct split), so Front Desk shows no figures for them. The dashboard add-on skips its own
 * count cells when these are shown, so each figure appears once.
 */
final class TZFD_Dashboard_Figures {
    private static $shown = false;
    private static $service_prices_available = true;

    /** True once the top figures have been printed on this page. */
    public static function shown() {
        return self::$shown;
    }

    /** Price of each service, keyed by calendar ID and service name. */
    public static function service_prices() {
        global $wpdb;
        $map  = array();
        $company_id = TZFD_Company_Read_Context::id();
        if ( $company_id < 1 ) { self::$service_prices_available = false; return $map; }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, form_structure FROM %i WHERE company_id = %d', $wpdb->prefix . TZFD_Tables::FORMS, $company_id ) );
        self::$service_prices_available = is_array( $rows ) && empty( $wpdb->last_error );
        if ( ! self::$service_prices_available ) { return $map; }
        foreach ( (array) $rows as $row ) {
            foreach ( TZFD_Admin_Menu::services_in( (string) $row->form_structure ) as $service ) {
                $price = TZFD_Local_Money::parse_amount( $service['price'] );
                $map[ (int) $row->id ][ $service['name'] ] = null === $price ? 0.0 : (float) $price;
            }
        }
        return $map;
    }

    public static function service_prices_available() {
        return self::$service_prices_available;
    }

    /**
     * Today's figures. Booked is the value of today's appointments that are not cancelled,
     * using each service's price.
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
        return array(
            'today'        => $today,
            'bookings_available' => ! empty( $found['available'] ) && ! empty( $found['calendar_names_available'] ) && self::service_prices_available(),
            'booked'       => round( $booked, 2 ),
            'bookings'     => $count,
            'pending'      => $wait,
            'truncated'    => ! empty( $found['truncated'] ),
        );
    }

    /** Visible note when the booking scan reached its row cap, so the totals are not read as complete. Escaped HTML, or '' when complete. */
    public static function truncation_note( $truncated ) {
        if ( empty( $truncated ) ) { return ''; }
        return '<div class="notice notice-warning inline"><p>' . esc_html( sprintf(
            /* translators: %s: maximum number of booking records the figures cover. */
            __( 'These figures cover only the most recent %s booking records, so some bookings may not be counted.', 'titan-zero-trades-front-desk' ),
            number_format_i18n( TZFD_Booking_View::MAX_ROWS )
        ) ) . '</p></div>';
    }

    /**
     * The headline tiles on the Dashboard: enquiries received today, bookings in the next seven days, and
     * bookings awaiting approval in that week.
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
        $company_id = TZFD_Company_Read_Context::id();
        $enquiries_available = false;
        $enquiries = 0;
        if ( $company_id > 0 ) {
            $enquiries = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE company_id = %d AND `time` >= %s',
                $wpdb->prefix . TZFD_Bookings::TABLE,
                $company_id,
                $today . ' 00:00:00'
            ) );
            $enquiries_available = empty( $wpdb->last_error );
        }
        return array(
            'enquiries' => $enquiries,
            'enquiries_available' => $enquiries_available,
            'upcoming'  => $upcoming,
            'upcoming_available' => ! empty( $found['available'] ) && ! empty( $found['calendar_names_available'] ),
            'attention' => $attention,
            'attention_available' => ! empty( $found['available'] ) && ! empty( $found['calendar_names_available'] ),
            'truncated' => ! empty( $found['truncated'] ),
        );
    }

    /**
     * Set-up steps for the Dashboard welcome card, each with whether it is done. Read from the settings
     * the steps write, so the card follows the real state of the site.
     */
    public static function setup_steps() {
        global $wpdb;
        $company_id = TZFD_Company_Read_Context::id();
        $calendars = $company_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d', $wpdb->prefix . TZFD_Tables::FORMS, $company_id ) ) : 0;
        $services  = 0;
        foreach ( self::service_prices() as $calendar_services ) { $services += count( $calendar_services ); }
        return array(
            array( 'label' => __( 'Create your first calendar', 'titan-zero-trades-front-desk' ), 'done' => $calendars > 0 ),
            array( 'label' => __( 'Add services and prices', 'titan-zero-trades-front-desk' ), 'done' => $services > 0 ),
            array( 'label' => __( 'Choose how bookings are confirmed', 'titan-zero-trades-front-desk' ), 'done' => null !== TZFD_Scoped_Store::get( 'titan_zero_front_desk_intake_default', null, $company_id ) ),
            array( 'label' => __( 'Finish the setup wizard', 'titan-zero-trades-front-desk' ), 'done' => '' !== (string) TZFD_Scoped_Store::get( 'tz_fd_setup_done', '', $company_id ) ),
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
                'value' => ! empty( $f['bookings_available'] ) ? TZFD_Local_Money::format( $f['booked'] ) : '—',
                'label' => __( 'Booked today', 'titan-zero-trades-front-desk' ),
                'sub'   => ! empty( $f['bookings_available'] ) ? sprintf( /* translators: %d: number of appointments today. */ _n( '%d appointment', '%d appointments', $f['bookings'], 'titan-zero-trades-front-desk' ), $f['bookings'] ) : '—',
            ),
            array(
                'href'  => $day_url,
                'value' => ! empty( $f['bookings_available'] ) ? (string) $f['bookings'] : '—',
                'label' => __( 'Bookings today', 'titan-zero-trades-front-desk' ),
                'sub'   => ! empty( $f['bookings_available'] ) ? sprintf( /* translators: %d: bookings waiting for approval. */ __( '%d awaiting approval', 'titan-zero-trades-front-desk' ), $f['pending'] ) : '—',
            ),
        );
        echo '<div class="tzfd-stats tzfd-stats-top" role="list">';
        foreach ( $cards as $card ) {
            echo '<a class="tzfd-stat" role="listitem" href="' . esc_url( $card['href'] ) . '">';
            echo '<span class="tzfd-stat-n">' . esc_html( $card['value'] ) . '</span>';
            echo '<span class="tzfd-stat-l">' . esc_html( $card['label'] ) . '</span>';
            echo '<span class="tzfd-stat-s">' . esc_html( $card['sub'] ) . '</span></a>';
        }
        echo '</div>';
        echo self::truncation_note( $f['truncated'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_html() in truncation_note().
    }
}
