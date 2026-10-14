<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned tables. Values are bound with prepare() and %i/%d placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-stored-data.php';
require_once __DIR__ . '/class-tzfd-bookings.php';

/**
 * Every booking across every calendar, on the Calendar screen with Day, Week and Month views.
 *
 * The date and slot helpers are pure (no database, no WordPress calls) so they can be tested.
 * fetch() is the only database read. Cancelled and rejected bookings are shown too, marked by status.
 */
final class TZFD_Booking_View {
    const VIEWS    = array( 'day', 'week', 'month' );
    /** Most recent booking rows scanned for one page view. A truncated result is flagged on the page. */
    const MAX_ROWS = 20000;
    /** Chips shown in one Month cell before the "+ more" link. */
    const MONTH_CHIPS = 3;

    /* ---------------------------------------------------------------
     * Pure helpers
     * ------------------------------------------------------------- */

    /** Normalises a view name. Anything unknown becomes week. */
    public static function view( $name ) {
        return in_array( $name, self::VIEWS, true ) ? $name : 'week';
    }

    /**
     * Start and end of a stored slot ("HH:MM/HH:MM", or a lone "HH:MM").
     * Returns null when the text is not a time.
     *
     * @return array|null  keys: start, end (may be ''), minutes (from midnight, for sorting)
     */
    public static function parse_slot( $slot ) {
        $slot = trim( (string) $slot );
        if ( 1 !== preg_match( '/^(\d{1,2}):(\d{2})(?:\/(\d{1,2}):(\d{2}))?$/', $slot, $m ) ) { return null; }
        if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) { return null; }
        $end = '';
        if ( isset( $m[3] ) && '' !== $m[3] ) {
            if ( (int) $m[3] > 23 || (int) $m[4] > 59 ) { return null; }
            $end = sprintf( '%02d:%02d', (int) $m[3], (int) $m[4] );
        }
        return array(
            'start'   => sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] ),
            'end'     => $end,
            'minutes' => (int) $m[1] * 60 + (int) $m[2],
        );
    }

    /**
     * Label and CSS class for a stored status. An empty status means the booking is confirmed.
     *
     * @return array  keys: label, class
     */
    public static function status( $cancelled ) {
        $map = array(
            ''                      => array( __( 'Confirmed', 'titan-zero-trades-front-desk' ), 'confirmed' ),
            'Pending'               => array( __( 'Pending', 'titan-zero-trades-front-desk' ), 'pending' ),
            'Attended'              => array( __( 'Attended', 'titan-zero-trades-front-desk' ), 'attended' ),
            'Cancelled'             => array( __( 'Cancelled', 'titan-zero-trades-front-desk' ), 'cancelled' ),
            'Cancelled by customer' => array( __( 'Cancelled by customer', 'titan-zero-trades-front-desk' ), 'cancelled' ),
            'Rejected'              => array( __( 'Rejected', 'titan-zero-trades-front-desk' ), 'cancelled' ),
        );
        $key = (string) $cancelled;
        if ( isset( $map[ $key ] ) ) {
            return array( 'label' => $map[ $key ][0], 'class' => $map[ $key ][1] );
        }
        return array( 'label' => $key, 'class' => 'other' );
    }

    /** Date (Y-m-d) plus or minus a number of days. Date maths runs in UTC, so no time-zone shifts. */
    public static function add_days( $date, $days ) {
        return self::utc( $date )->modify( sprintf( '%+d days', (int) $days ) )->format( 'Y-m-d' );
    }

    /** First day of the week containing $date. $start_of_week uses WordPress's 0 (Sunday) to 6 (Saturday). */
    public static function week_start( $date, $start_of_week = 1 ) {
        $sow = max( 0, min( 6, (int) $start_of_week ) );
        $dow = (int) self::utc( $date )->format( 'w' );
        return self::add_days( $date, -( ( $dow - $sow + 7 ) % 7 ) );
    }

    /** Every month (Y-m) from one date to another, inclusive. */
    public static function months_between( $from, $to ) {
        $months = array();
        $cur    = substr( $from, 0, 7 );
        $end    = substr( $to, 0, 7 );
        for ( $guard = 0; $cur <= $end && $guard < 240; $guard++ ) {
            $months[] = $cur;
            $cur      = self::utc( $cur . '-01' )->modify( '+1 month' )->format( 'Y-m' );
        }
        return $months;
    }

    /**
     * What one view covers: the first and last date to load, every date to draw, and the previous and next dates.
     * Month views draw whole weeks, so the grid can start in the previous month and end in the next.
     *
     * @return array  keys: view, date, from, to, days, prev, next
     */
    public static function range( $view, $date, $start_of_week = 1 ) {
        $view = self::view( $view );
        if ( ! TZFD_Bookings::valid_date( $date ) ) { $date = gmdate( 'Y-m-d' ); }

        if ( 'day' === $view ) {
            return array(
                'view' => $view, 'date' => $date, 'from' => $date, 'to' => $date, 'days' => array( $date ),
                'prev' => self::add_days( $date, -1 ), 'next' => self::add_days( $date, 1 ),
            );
        }
        if ( 'week' === $view ) {
            $from = self::week_start( $date, $start_of_week );
            $days = array();
            for ( $i = 0; $i < 7; $i++ ) { $days[] = self::add_days( $from, $i ); }
            return array(
                'view' => $view, 'date' => $date, 'from' => $from, 'to' => $days[6], 'days' => $days,
                'prev' => self::add_days( $date, -7 ), 'next' => self::add_days( $date, 7 ),
            );
        }
        // Month
        $first = substr( $date, 0, 8 ) . '01';
        $last  = self::utc( $first )->format( 'Y-m-t' );
        $from  = self::week_start( $first, $start_of_week );
        $to    = self::add_days( self::week_start( $last, $start_of_week ), 6 );
        $days  = array();
        for ( $cur = $from; $cur <= $to; $cur = self::add_days( $cur, 1 ) ) { $days[] = $cur; }
        return array(
            'view' => $view, 'date' => $first, 'from' => $from, 'to' => $to, 'days' => $days,
            'prev' => self::utc( $first )->modify( '-1 month' )->format( 'Y-m-d' ),
            'next' => self::utc( $first )->modify( '+1 month' )->format( 'Y-m-d' ),
        );
    }

    /**
     * Customer name from a decoded booking. Uses a field called "name", otherwise the first field whose
     * name contains "name" (for example first_name). Company, service and calendar fields are skipped.
     * Returns '' when no name is found, so the caller can fall back to the email address.
     */
    public static function customer_name( array $data ) {
        $best = '';
        foreach ( $data as $key => $value ) {
            if ( ! is_string( $key ) || ! is_string( $value ) || '' === trim( $value ) ) { continue; }
            $k = strtolower( $key );
            if ( 'name' === $k ) { return self::clean( $value ); }
            if ( '' === $best && false !== strpos( $k, 'name' ) && ! preg_match( '/service|calendar|form|file|field|company|resource/', $k ) ) {
                $best = self::clean( $value );
            }
        }
        return $best;
    }

    /**
     * Flat list of one booking's appointments that fall inside a date range. Each item is one
     * appointment, ready for the views.
     */
    public static function items_from_booking( $booking_id, $form_id, $calendar_name, $email, array $data, $from, $to ) {
        $items    = array();
        $customer = self::customer_name( $data );
        if ( '' === $customer ) { $customer = (string) $email; }
        foreach ( (array) ( $data['apps'] ?? array() ) as $app ) {
            if ( ! is_array( $app ) ) { continue; }
            $date = (string) ( $app['date'] ?? '' );
            if ( ! TZFD_Bookings::valid_date( $date ) || $date < $from || $date > $to ) { continue; }
            $slot   = self::parse_slot( $app['slot'] ?? '' );
            $status = self::status( $app['cancelled'] ?? '' );
            $items[] = array(
                'booking_id' => (int) $booking_id,
                'form_id'    => (int) $form_id,
                'calendar'   => (string) $calendar_name,
                'date'       => $date,
                'start'      => $slot ? $slot['start'] : '',
                'end'        => $slot ? $slot['end'] : '',
                'minutes'    => $slot ? $slot['minutes'] : -1,
                'service'    => self::clean( (string) ( $app['service'] ?? '' ) ),
                'customer'   => self::clean( $customer ),
                'email'      => (string) $email,
                'status'     => $status['label'],
                'status_class' => $status['class'],
            );
        }
        return $items;
    }

    /** Items grouped by date (Y-m-d), each day sorted by start time. */
    public static function group_by_date( array $items ) {
        $grouped = array();
        foreach ( $items as $item ) { $grouped[ $item['date'] ][] = $item; }
        foreach ( $grouped as $date => $list ) {
            usort( $list, function ( $a, $b ) {
                return array( $a['minutes'], $a['booking_id'] ) <=> array( $b['minutes'], $b['booking_id'] );
            } );
            $grouped[ $date ] = $list;
        }
        ksort( $grouped );
        return $grouped;
    }

    /* ---------------------------------------------------------------
     * Database read
     * ------------------------------------------------------------- */

    /** Calendar names by ID. A calendar with no name is called "Calendar N". */
    public static function calendar_names() {
        global $wpdb;
        $names = array();
        $rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, form_name FROM %i ORDER BY form_name', $wpdb->prefix . 'tzfd_forms' ) );
        foreach ( (array) $rows as $row ) {
            $names[ (int) $row->id ] = '' !== (string) $row->form_name
                ? (string) $row->form_name
                /* translators: %d: calendar ID. */
                : sprintf( __( 'Calendar %d', 'titan-zero-trades-front-desk' ), (int) $row->id );
        }
        return $names;
    }

    /**
     * Appointments from every calendar (or one, when $calendar_id is set) between two dates.
     * Only bookings whose stored text mentions a month in the range are decoded, which keeps the scan small.
     *
     * @return array  keys: items (flat list), scanned (rows read), truncated (true when MAX_ROWS was reached)
     */
    public static function fetch( $from, $to, $calendar_id = 0 ) {
        global $wpdb;
        $result = array( 'items' => array(), 'scanned' => 0, 'truncated' => false );
        if ( ! TZFD_Bookings::valid_date( $from ) || ! TZFD_Bookings::valid_date( $to ) || $from > $to ) {
            return $result;
        }

        // One pattern matches any of the months: "YYYY-MM-" appearing in the stored data. The months are
        // generated digits and hyphens only (months_between), so the pattern needs no escaping.
        $months = array();
        foreach ( self::months_between( $from, $to ) as $ym ) {
            $months[] = $ym . '-';
        }
        $calendar = (int) $calendar_id > 0 ? (int) $calendar_id : 0;

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT m.id, m.formid, m.notifyto, m.posted_data FROM %i m WHERE m.posted_data REGEXP %s AND ( %d = 0 OR m.formid = %d ) ORDER BY m.id DESC LIMIT %d',
            $wpdb->prefix . TZFD_Bookings::TABLE,
            implode( '|', $months ),
            $calendar,
            $calendar,
            self::MAX_ROWS
        ) );
        $rows = (array) $rows;
        $result['scanned']   = count( $rows );
        $result['truncated'] = $result['scanned'] >= self::MAX_ROWS;

        $names = self::calendar_names();
        foreach ( $rows as $row ) {
            $data = TZFD_Stored_Data::decode_booking( (string) $row->posted_data );
            if ( null === $data ) { continue; }
            $form_id = (int) $row->formid;
            $found   = self::items_from_booking( (int) $row->id, $form_id, $names[ $form_id ] ?? '', (string) $row->notifyto, $data, $from, $to );
            foreach ( $found as $item ) { $result['items'][] = $item; }
        }
        return $result;
    }

    /* ---------------------------------------------------------------
     * Page
     * ------------------------------------------------------------- */

    /** Calendar screen: Day, Week or Month view of all bookings. Route: page=tzfd_calendar. */
    public static function render_page() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }

        $view  = self::view( self::query( 'view' ) );
        $date  = self::query( 'date' );
        if ( ! TZFD_Bookings::valid_date( $date ) ) { $date = self::today(); }
        $cal   = (int) self::query( 'fcal' );
        $sow   = (int) get_option( 'start_of_week', 1 );
        $range = self::range( $view, $date, $sow );
        $found = self::fetch( $range['from'], $range['to'], $cal );
        $by    = self::group_by_date( $found['items'] );
        $names = self::calendar_names();

        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-calendar">';
        TZFD_Admin_Layout::header( __( 'Calendar', 'titan-zero-trades-front-desk' ), __( 'See appointments by week or month, and open a booking for details.', 'titan-zero-trades-front-desk' ),
            '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( TZFD_Admin_Menu::add_booking_url() ) . '">+ ' . esc_html__( 'New booking', 'titan-zero-trades-front-desk' ) . '</a>' );

        self::render_toolbar( $range, $cal, $names );

        if ( empty( $names ) ) {
            TZFD_Brand::empty_state( 'calendar', __( 'No calendars yet. Create one, and its bookings will appear here.', 'titan-zero-trades-front-desk' ) );
        } elseif ( empty( $found['items'] ) ) {
            TZFD_Brand::empty_state( 'calendar', __( 'No bookings in this period.', 'titan-zero-trades-front-desk' ) );
        }
        if ( $found['truncated'] ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Only the most recent bookings are shown. Open a single calendar to see all of its bookings.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }

        if ( 'day' === $view ) {
            self::render_day( $range, $by );
        } elseif ( 'week' === $view ) {
            self::render_week( $range, $by );
        } else {
            self::render_month( $range, $by );
        }
        echo '</div>';
    }

    /** Previous, today and next links and the period title on the left; calendar filter, view switch and manage link on the right. */
    private static function render_toolbar( array $range, $cal, array $names ) {
        $base  = array( 'view' => $range['view'], 'fcal' => $cal ?: null );
        $title = self::title( $range );
        echo '<div class="tzfd-cal-toolbar">';
        echo '<div class="tzfd-cal-nav">';
        echo '<a class="tzfd-btn tzfd-btn-secondary tzfd-cal-step" href="' . esc_url( self::url( $base + array( 'date' => $range['prev'] ) ) ) . '" aria-label="' . esc_attr__( 'Previous', 'titan-zero-trades-front-desk' ) . '">&lsaquo;</a>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( self::url( $base + array( 'date' => self::today() ) ) ) . '">' . esc_html__( 'Today', 'titan-zero-trades-front-desk' ) . '</a>';
        echo '<a class="tzfd-btn tzfd-btn-secondary tzfd-cal-step" href="' . esc_url( self::url( $base + array( 'date' => $range['next'] ) ) ) . '" aria-label="' . esc_attr__( 'Next', 'titan-zero-trades-front-desk' ) . '">&rsaquo;</a>';
        echo '<strong class="tzfd-cal-title">' . esc_html( $title ) . '</strong>';
        echo '</div>';

        echo '<div class="tzfd-cal-right">';
        echo '<form method="get" class="tzfd-cal-filter" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr( TZFD_Admin_Menu::CALENDAR ) . '" />';
        echo '<input type="hidden" name="view" value="' . esc_attr( $range['view'] ) . '" />';
        echo '<input type="hidden" name="date" value="' . esc_attr( $range['date'] ) . '" />';
        echo '<label class="screen-reader-text" for="tzfd-cal-fcal">' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</label>';
        echo '<select id="tzfd-cal-fcal" name="fcal"><option value="0">' . esc_html__( 'All calendars', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $names as $id => $name ) {
            echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $cal, $id, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select> <button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Show', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '</form>';

        echo '<div class="tzfd-cal-views" role="group" aria-label="' . esc_attr__( 'View', 'titan-zero-trades-front-desk' ) . '">';
        $labels = array( 'day' => __( 'Day', 'titan-zero-trades-front-desk' ), 'week' => __( 'Week', 'titan-zero-trades-front-desk' ), 'month' => __( 'Month', 'titan-zero-trades-front-desk' ) );
        foreach ( $labels as $key => $label ) {
            $on = $key === $range['view'];
            echo '<a class="tzfd-btn ' . ( $on ? 'tzfd-btn-primary' : 'tzfd-btn-secondary' ) . '"' . ( $on ? ' aria-current="page"' : '' ) . ' href="' . esc_url( self::url( array( 'view' => $key, 'date' => $range['date'], 'fcal' => $cal ?: null ) ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';
        echo '<a class="tzfd-btn tzfd-btn-secondary tzfd-cal-more" href="' . esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::CALENDAR . '&manage=1' ) ) . '">' . esc_html__( 'Manage calendars', 'titan-zero-trades-front-desk' ) . '</a>';
        echo '</div>';
        echo '</div>';
    }

    /** Day view: one row per appointment, in time order. */
    private static function render_day( array $range, array $by ) {
        $list = $by[ $range['date'] ] ?? array();
        if ( empty( $list ) ) { return; }
        echo '<div class="tzfd-card tzfd-table-wrap"><table class="widefat striped tzfd-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Time', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $list as $item ) {
            echo '<tr>';
            echo '<td>' . esc_html( self::time_text( $item ) ) . '</td>';
            echo '<td><a href="' . esc_url( self::booking_list_url( $item ) ) . '">' . esc_html( $item['customer'] ) . '</a>';
            if ( '' !== $item['email'] && $item['email'] !== $item['customer'] ) { echo '<br><span class="tzfd-muted">' . esc_html( $item['email'] ) . '</span>'; }
            echo '</td>';
            echo '<td>' . esc_html( $item['service'] ) . '</td>';
            echo '<td>' . esc_html( $item['calendar'] ) . '</td>';
            echo '<td><span class="tzfd-badge tzfd-badge-' . esc_attr( $item['status_class'] ) . '">' . esc_html( $item['status'] ) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Week view: seven columns, one per day, with the day's appointments listed in each. */
    private static function render_week( array $range, array $by ) {
        echo '<div class="tzfd-card tzfd-cal-scroll"><table class="tzfd-cal-grid tzfd-cal-week"><thead><tr>';
        foreach ( $range['days'] as $day ) {
            echo '<th scope="col"' . ( self::is_today( $day ) ? ' class="tzfd-cal-today"' : '' ) . '><a href="' . esc_url( self::url( array( 'view' => 'day', 'date' => $day, 'fcal' => self::cal_arg() ) ) ) . '">' . esc_html( self::label( $day, 'D j M' ) ) . '</a></th>';
        }
        echo '</tr></thead><tbody><tr>';
        foreach ( $range['days'] as $day ) {
            echo '<td' . ( self::is_today( $day ) ? ' class="tzfd-cal-today"' : '' ) . '>';
            foreach ( $by[ $day ] ?? array() as $item ) { self::render_chip( $item ); }
            echo '</td>';
        }
        echo '</tr></tbody></table></div>';
    }

    /** Month view: a grid of whole weeks. Days outside the month are muted; busy days link to their Day view. */
    private static function render_month( array $range, array $by ) {
        $month = substr( $range['date'], 0, 7 );
        echo '<div class="tzfd-card tzfd-cal-scroll"><table class="tzfd-cal-grid tzfd-cal-month"><thead><tr>';
        for ( $i = 0; $i < 7; $i++ ) {
            echo '<th scope="col">' . esc_html( self::label( $range['days'][ $i ], 'D' ) ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( array_chunk( $range['days'], 7 ) as $week ) {
            echo '<tr>';
            foreach ( $week as $day ) {
                $classes = ( substr( $day, 0, 7 ) === $month ? '' : 'tzfd-cal-out' ) . ( self::is_today( $day ) ? ' tzfd-cal-today' : '' );
                $list    = $by[ $day ] ?? array();
                echo '<td class="' . esc_attr( trim( $classes ) ) . '"><a class="tzfd-cal-daynum" href="' . esc_url( self::url( array( 'view' => 'day', 'date' => $day, 'fcal' => self::cal_arg() ) ) ) . '">' . esc_html( (int) substr( $day, 8, 2 ) ) . '</a>';
                foreach ( array_slice( $list, 0, self::MONTH_CHIPS ) as $item ) { self::render_chip( $item ); }
                if ( count( $list ) > self::MONTH_CHIPS ) {
                    /* translators: %d: number of bookings not shown in the cell. */
                    echo '<a class="tzfd-cal-more" href="' . esc_url( self::url( array( 'view' => 'day', 'date' => $day, 'fcal' => self::cal_arg() ) ) ) . '">' . esc_html( sprintf( __( '+%d more', 'titan-zero-trades-front-desk' ), count( $list ) - self::MONTH_CHIPS ) ) . '</a>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /* ---------------------------------------------------------------
     * Small render helpers
     * ------------------------------------------------------------- */

    /** One appointment as a coloured chip linking to its calendar's Booking Orders. */
    private static function render_chip( array $item ) {
        $text = trim( ( '' !== $item['start'] ? $item['start'] . ' ' : '' ) . $item['customer'] );
        $title = trim( $item['customer'] . ' · ' . $item['service'] . ' · ' . $item['status'] . ' · ' . $item['calendar'], ' ·' );
        // Live bookings take a colour from their service so each service reads at a glance; cancelled ones keep the status style.
        $svc = 'cancelled' === $item['status_class'] ? '' : ' tzfd-svc-' . ( ( crc32( (string) $item['service'] ) & 3 ) + 1 );
        echo '<a class="tzfd-chip tzfd-chip-' . esc_attr( $item['status_class'] ) . esc_attr( $svc ) . '" href="' . esc_url( self::booking_list_url( $item ) ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $text ) . '</a>';
    }

    private static function time_text( array $item ) {
        if ( '' === $item['start'] ) { return '—'; }
        return '' !== $item['end'] ? $item['start'] . ' – ' . $item['end'] : $item['start'];
    }

    private static function booking_list_url( array $item ) {
        return admin_url( 'admin.php?page=' . TZFD_Admin_Menu::PARENT . '&cal=' . (int) $item['form_id'] . '&list=1' );
    }

    /** True when the date is today, in the site's time zone. */
    private static function is_today( $day ) {
        return $day === self::today();
    }

    private static function cal_arg() {
        $cal = (int) self::query( 'fcal' );
        return $cal > 0 ? $cal : null;
    }

    /** Title for the toolbar, for example "Monday, 12 October 2026" or "5 Oct – 11 Oct 2026". */
    public static function title( array $range ) {
        if ( 'day' === $range['view'] ) { return self::label( $range['date'], 'l, j F Y' ); }
        if ( 'week' === $range['view'] ) { return self::label( $range['from'], 'j M' ) . ' – ' . self::label( $range['to'], 'j M Y' ); }
        return self::label( substr( $range['date'], 0, 8 ) . '01', 'F Y' );
    }

    /** Formats a Y-m-d date in the site's language. Noon keeps the day the same in every time zone. */
    private static function label( $date, $format ) {
        $stamp = strtotime( $date . ' 12:00:00 UTC' );
        return function_exists( 'date_i18n' ) ? date_i18n( $format, $stamp ) : gmdate( $format, $stamp );
    }

    /** Today's date in the site's time zone. */
    public static function today() {
        return function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    }

    /** Admin URL for this screen with the given query values. Null values are left out. */
    public static function url( array $args ) {
        $query = array( 'page' => TZFD_Admin_Menu::CALENDAR );
        foreach ( $args as $key => $value ) {
            if ( null !== $value && '' !== $value && 0 !== $value ) { $query[ $key ] = $value; }
        }
        return admin_url( 'admin.php?' . http_build_query( $query ) );
    }

    /** Read-only query value, sanitised. */
    private static function query( $key ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter values; this screen changes no state.
        return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
    }

    private static function utc( $date ) {
        return new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );
    }

    private static function clean( $text ) {
        return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( mb_substr( (string) $text, 0, 200 ) ) : trim( (string) $text );
    }
}
