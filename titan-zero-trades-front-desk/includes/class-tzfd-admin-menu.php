<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Front Desk admin navigation.
 *
 * The main menu is: Dashboard, Calendar, Customers, Bookings, Quotes, Payments, Services,
 * Workers, Locations, AI, Addons, Settings. Every other admin tool is a tab on the
 * Settings page. Tab pages that open on their own URL show the same tab bar.
 * Calendar screens keep their existing routes under the front desk slug.
 */
final class TZFD_Admin_Menu {
    const PARENT   = 'tzfd';
    const CALENDAR = 'tzfd_calendar';
    const BOOKINGS = 'tzfd_bookings';
    const SERVICES = 'tzfd_services';
    const ADDONS   = 'tzfd_addons';
    const SETTINGS = 'tzfd_settings';
    const HELP     = 'tzfd_support';
    const EMAILS   = 'tzfd-status-update-emails';
    const PREVIEW  = 'tzfd-scheduling-preview';
    const SETUP    = 'tzfd-setup';
    const PACKS    = 'tzfd-packs';
    const QUOTES   = 'tzfd-quotes';
    const CUSTOMERS = 'tzfd-customers';
    const WORKERS  = 'tzfd-resources';
    const AI       = 'tzfd-ai-setup';
    const PAYMENTS = 'tzfd-payments';
    const LOCATIONS = 'tzfd-locations';
    const BUSINESS = 'tzfd-business';

    /** Main menu, in order. Any other submenu under Front Desk is removed. */
    public static function main_menu_order() {
        return array( self::PARENT, self::CALENDAR, self::CUSTOMERS, self::BOOKINGS, self::QUOTES, self::PAYMENTS,
                      self::SERVICES, self::WORKERS, self::LOCATIONS, self::AI, self::ADDONS, self::SETTINGS );
    }

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_pages' ), 11 );
        add_action( 'admin_menu', array( __CLASS__, 'reorder_menu' ), 9999 );
    }

    /** Pages that are reached from the Settings tab bar or from other pages, not from the main menu. */
    public static function add_pages() {
        add_submenu_page( self::PARENT, __( 'Dashboard', 'titan-zero-trades-front-desk' ), __( 'Dashboard', 'titan-zero-trades-front-desk' ), 'read', self::PARENT, array( 'TZFD_Admin_Menu', 'route_front_desk' ) );
        add_submenu_page( self::PARENT, __( 'Calendar', 'titan-zero-trades-front-desk' ), __( 'Calendar', 'titan-zero-trades-front-desk' ), 'edit_pages', self::CALENDAR, array( 'TZFD_Admin_Menu', 'route_front_desk' ) );
        add_submenu_page( self::PARENT, __( 'Bookings', 'titan-zero-trades-front-desk' ), __( 'Bookings', 'titan-zero-trades-front-desk' ), 'edit_pages', self::BOOKINGS, array( __CLASS__, 'page_bookings' ) );
        add_submenu_page( self::PARENT, __( 'Payments', 'titan-zero-trades-front-desk' ), __( 'Payments', 'titan-zero-trades-front-desk' ), 'edit_pages', self::PAYMENTS, array( 'TZFD_Payments', 'page' ) );
        add_submenu_page( self::PARENT, __( 'Services', 'titan-zero-trades-front-desk' ), __( 'Services', 'titan-zero-trades-front-desk' ), 'edit_pages', self::SERVICES, array( __CLASS__, 'page_services' ) );
        add_submenu_page( self::PARENT, __( 'Addons', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ), 'edit_pages', self::ADDONS, array( 'TZFD_Admin_Menu', 'route_front_desk' ) );
        add_submenu_page( self::PARENT, __( 'Settings', 'titan-zero-trades-front-desk' ), __( 'Settings', 'titan-zero-trades-front-desk' ), 'edit_pages', self::SETTINGS, array( 'TZFD_Admin_Menu', 'route_front_desk' ) );

        // Hidden pages (parent ''): reached from the Settings tab bar.
        add_submenu_page( '', __( 'Help', 'titan-zero-trades-front-desk' ), __( 'Help', 'titan-zero-trades-front-desk' ), 'edit_pages', self::HELP, array( 'TZFD_Admin_Menu', 'route_front_desk' ) );
        add_submenu_page( '', __( 'Business and money', 'titan-zero-trades-front-desk' ), __( 'Business and money', 'titan-zero-trades-front-desk' ), 'manage_options', self::BUSINESS, array( 'TZFD_Money', 'page' ) );
    }

    /** Keeps the main menu in the order above and drops anything else added under Front Desk. */
    public static function reorder_menu() {
        global $submenu;
        if ( empty( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) { return; }
        $position = array_flip( self::main_menu_order() );
        $items    = array();
        foreach ( $submenu[ self::PARENT ] as $item ) {
            if ( isset( $item[2], $position[ $item[2] ] ) ) {
                $items[ $position[ $item[2] ] ] = $item;
            }
        }
        ksort( $items );
        $submenu[ self::PARENT ] = array_values( $items );
    }

    /** Front Desk page router. The bare front desk slug shows the Dashboard; calendar screens keep their routes. */
    public static function route_front_desk() {
        // The top-level menu is registered with 'read' so the Dashboard can be listed. Every route under this
        // slug (calendar, bookings, settings, addons, help) needs edit_pages, the same as the submenu screens.
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $plugin = self::plugin();
        if ( ! $plugin ) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $tab_pages = array( self::HELP => 'help' );
        // The add request from the list screen (page=PARENT&a=1) is handled by the settings page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value, no change is made.
        if ( self::PARENT === $page && isset( $_GET['a'] ) ) { $plugin->settings_page(); return; }
        if ( isset( $tab_pages[ $page ] ) ) {
            self::render_settings_tabs( $tab_pages[ $page ] );
        }
        if ( self::PARENT === $page && ! self::has_calendar_route() ) {
            self::page_dashboard();
            return;
        }
        // Calendar: every booking with Day, Week and Month views. The calendar list stays under manage=1.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
        $manage = isset( $_GET['manage'] ) ? sanitize_key( wp_unslash( $_GET['manage'] ) ) : '';
        if ( self::CALENDAR === $page && ! self::has_calendar_route() && '1' !== $manage ) {
            TZFD_Booking_View::render_page();
            return;
        }
        $plugin->settings_page();
    }

    private static function has_calendar_route() {
        foreach ( array( 'cal', 'pwizard', 'confirm' ) as $key ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
            if ( isset( $_GET[ $key ] ) ) { return true; }
        }
        return false;
    }

    private static function plugin() {
        global $tzfd_plugin;
        return ( is_object( $tzfd_plugin ) && method_exists( $tzfd_plugin, 'settings_page' ) ) ? $tzfd_plugin : null;
    }


    /* ---------------------------------------------------------------
     * Settings tab bar
     * ------------------------------------------------------------- */

    /**
     * Tabs in order. kind "tab" is a section of the Settings page (data-tab number and hash);
     * kind "page" opens its own admin page. The admin flag hides a tab from other users.
     */
    public static function settings_tabs() {
        return array(
            array( 'key' => 'reports',        'label' => __( 'Email reports', 'titan-zero-trades-front-desk' ),       'kind' => 'tab',  'tab' => '1', 'hash' => 'reports',         'admin' => false ),
            array( 'key' => 'troubleshooting', 'label' => __( 'Troubleshooting', 'titan-zero-trades-front-desk' ),    'kind' => 'tab',  'tab' => '2', 'hash' => 'troubleshooting', 'admin' => false ),
            array( 'key' => 'csv',            'label' => __( 'CSV export', 'titan-zero-trades-front-desk' ),         'kind' => 'tab',  'tab' => '5', 'hash' => 'csv',             'admin' => false ),
            array( 'key' => 'calendar',       'label' => __( 'Calendar display', 'titan-zero-trades-front-desk' ),   'kind' => 'tab',  'tab' => '6', 'hash' => 'calendar',        'admin' => true ),
            array( 'key' => 'other',          'label' => __( 'Other settings', 'titan-zero-trades-front-desk' ),     'kind' => 'tab',  'tab' => '7', 'hash' => 'other',           'admin' => false ),
            array( 'key' => 'ai',             'label' => __( 'AI', 'titan-zero-trades-front-desk' ),                 'kind' => 'tab',  'tab' => '8', 'hash' => 'ai',              'admin' => true ),
            array( 'key' => 'business',       'label' => __( 'Business and money', 'titan-zero-trades-front-desk' ), 'kind' => 'page', 'slug' => self::BUSINESS,                      'admin' => true ),
            array( 'key' => 'help',           'label' => __( 'Help', 'titan-zero-trades-front-desk' ),               'kind' => 'page', 'slug' => self::HELP,                         'admin' => false ),
            array( 'key' => 'emails',         'label' => __( 'Status update emails', 'titan-zero-trades-front-desk' ), 'kind' => 'page', 'slug' => self::EMAILS,                     'admin' => true ),
            array( 'key' => 'preview',        'label' => __( 'Scheduling Preview', 'titan-zero-trades-front-desk' ), 'kind' => 'page', 'slug' => self::PREVIEW,                      'admin' => true ),
            array( 'key' => 'presets',        'label' => __( 'Preset Setup', 'titan-zero-trades-front-desk' ),       'kind' => 'page', 'slug' => self::SETUP,                        'admin' => true ),
            array( 'key' => 'packs',          'label' => __( 'Vertical Packs', 'titan-zero-trades-front-desk' ),     'kind' => 'page', 'slug' => self::PACKS,                        'admin' => true ),
        );
    }

    /** URL of the Settings page, optionally at one section (for example settings#payments). */
    /**
     * True when a form posted from the Settings page should return there. Only the Settings-hosted forms
     * (Money, Payment methods, Quote settings) send return_to. The posting form's own nonce must verify,
     * so a forged return_to changes nothing. The calling save handler has already checked its capability.
     */
    public static function posted_from_settings() {
        $forms = array(
            array( 'field' => 'tzfd_money_nonce',           'action' => TZFD_Money::NONCE ),
            array( 'field' => 'tzfd_payment_methods_nonce', 'action' => TZFD_Payments::METHODS_NONCE ),
            array( 'field' => TZFD_Quote_Screens::NONCE_FIELD, 'action' => 'tzfd_quote_settings_save' ),
        );
        $verified = false;
        foreach ( $forms as $form ) {
            if ( isset( $_POST[ $form['field'] ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $form['field'] ] ) ), $form['action'] ) ) {
                $verified = true;
                break;
            }
        }
        return $verified && isset( $_POST['return_to'] ) && 'settings' === sanitize_key( wp_unslash( $_POST['return_to'] ) );
    }

    /**
     * Prints the Settings tab bar. $current is the active tab key. Every tab is a link to the
     * Settings page at its hash, or to its own admin page.
     */
    public static function render_settings_tabs( $current ) {
        $is_admin = current_user_can( 'manage_options' );
        echo '<nav class="nav-tab-wrapper tzfd-tab-wrapper tzfd-settings-tabs" aria-label="' . esc_attr__( 'Settings tabs', 'titan-zero-trades-front-desk' ) . '">';
        foreach ( self::settings_tabs() as $tab ) {
            if ( $tab['admin'] && ! $is_admin ) { continue; }
            $class = 'nav-tab' . ( $tab['key'] === $current ? ' nav-tab-active' : '' );
            if ( 'tab' === $tab['kind'] ) {
                $url = admin_url( 'admin.php?page=' . self::SETTINGS . '#' . $tab['hash'] );
                echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $tab['label'] ) . '</a>';
            } else {
                $url = admin_url( 'admin.php?page=' . $tab['slug'] );
                echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $tab['label'] ) . '</a>';
            }
        }
        echo '</nav>';
    }

    /* ---------------------------------------------------------------
     * Dashboard
     * ------------------------------------------------------------- */

    public static function setup_url() {
        return admin_url( 'admin.php?page=' . self::SETUP . '&step=1' );
    }

    public static function page_dashboard() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;
        $today   = TZFD_Booking_View::today();
        $head    = TZFD_Dashboard_Figures::headline( $today );
        $steps   = TZFD_Dashboard_Figures::setup_steps();
        $figures = TZFD_Dashboard_Figures::figures( $today );
        $user    = wp_get_current_user();
        $first_name = $user->ID ? (string) get_user_meta( $user->ID, 'first_name', true ) : '';
        $first   = '' !== $first_name ? $first_name : (string) $user->display_name;
        $done_n  = 0;
        foreach ( $steps as $step ) { if ( $step['done'] ) { $done_n++; } }
        $total   = count( $steps );
        $percent = $total > 0 ? (int) round( 100 * $done_n / $total ) : 0;
        $can_set = current_user_can( 'manage_options' );
        $ready   = $done_n >= $total;

        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-dashboard">';
        TZFD_Admin_Layout::header( __( 'Dashboard', 'titan-zero-trades-front-desk' ), __( 'At a glance across enquiries, bookings and quotes.', 'titan-zero-trades-front-desk' ) );

        // Welcome and set-up progress.
        echo '<div class="tzfd-card tzfd-welcome"><div class="tzfd-welcome-main">';
        /* translators: %s: first name of the signed-in user. */
        echo '<h2>' . esc_html( sprintf( __( 'Welcome back, %s', 'titan-zero-trades-front-desk' ), $first ) ) . '</h2>';
        echo '<p class="tzfd-muted">' . esc_html( $ready ? __( 'Your Front Desk is set up. The figures below show what needs you today.', 'titan-zero-trades-front-desk' ) : __( 'Your Front Desk is ready. Finish the set-up steps to open bookings.', 'titan-zero-trades-front-desk' ) ) . '</p>';
        echo '<div class="tzfd-bar"><span style="width:' . (int) $percent . '%"></span></div>';
        /* translators: 1: steps done, 2: total steps. */
        echo '<p class="tzfd-muted tzfd-progress-text">' . esc_html( sprintf( __( '%1$d of %2$d steps complete', 'titan-zero-trades-front-desk' ), $done_n, $total ) ) . '</p>';
        echo '<ul class="tzfd-steps">';
        foreach ( $steps as $step ) {
            echo '<li class="' . ( $step['done'] ? 'is-done' : '' ) . '">' . esc_html( $step['label'] ) . '</li>';
        }
        echo '</ul></div>';
        if ( $can_set && ! $ready ) {
            echo '<div class="tzfd-welcome-action"><a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( self::setup_url() ) . '">' . esc_html__( 'Continue setup', 'titan-zero-trades-front-desk' ) . '</a></div>';
        } elseif ( ! $ready ) {
            echo '<p class="tzfd-muted">' . esc_html__( 'Ask an administrator to run setup.', 'titan-zero-trades-front-desk' ) . '</p>';
        }
        echo '</div>';

        // Four headline tiles.
        $week_url = TZFD_Booking_View::url( array( 'view' => 'week', 'date' => $today ) );
        echo '<div class="tzfd-stats" role="list">';
        TZFD_Admin_Layout::stat( (string) $head['enquiries'], __( 'New enquiries', 'titan-zero-trades-front-desk' ), __( 'today', 'titan-zero-trades-front-desk' ), 'red' );
        TZFD_Admin_Layout::stat( (string) $head['upcoming'], __( 'Upcoming bookings', 'titan-zero-trades-front-desk' ), __( 'next 7 days', 'titan-zero-trades-front-desk' ), 'blue', $week_url );
        TZFD_Admin_Layout::stat( (string) $head['follow'], __( 'Quotes to follow up', 'titan-zero-trades-front-desk' ), __( 'sent, awaiting reply', 'titan-zero-trades-front-desk' ), 'amber', admin_url( 'admin.php?page=' . self::QUOTES ) );
        TZFD_Admin_Layout::stat( (string) $head['attention'], __( 'Needs attention', 'titan-zero-trades-front-desk' ), __( 'awaiting approval', 'titan-zero-trades-front-desk' ), 'green', admin_url( 'admin.php?page=' . self::BOOKINGS ) );
        echo '</div>';

        echo '<div class="tzfd-dash-grid">';

        // Upcoming bookings.
        $upcoming = TZFD_Booking_View::fetch( $today, TZFD_Booking_View::add_days( $today, 6 ) );
        $rows     = array();
        foreach ( $upcoming['items'] as $item ) {
            if ( 'cancelled' === $item['status_class'] ) { continue; }
            $rows[] = $item;
        }
        usort( $rows, function ( $a, $b ) { return array( $a['date'], $a['start'] ) <=> array( $b['date'], $b['start'] ); } );
        echo '<div class="tzfd-card"><div class="tzfd-card-head"><div><h2>' . esc_html__( 'Upcoming bookings', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p class="tzfd-muted">' . esc_html__( 'Next 7 days, across every calendar', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $week_url ) . '">' . esc_html__( 'View calendar', 'titan-zero-trades-front-desk' ) . '</a></div>';
        echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Date and time', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="4" class="tzfd-muted">' . esc_html__( 'No bookings in the next 7 days.', 'titan-zero-trades-front-desk' ) . '</td></tr>';
        }
        foreach ( array_slice( $rows, 0, 6 ) as $item ) {
            $when = mysql2date( 'D j M', $item['date'] ) . ( '' !== $item['start'] ? ', ' . $item['start'] : '' );
            echo '<tr><td><strong>' . esc_html( $item['customer'] ) . '</strong></td><td>' . esc_html( $item['service'] ) . '</td><td>' . esc_html( $when ) . '</td>';
            echo '<td><span class="tzfd-badge tzfd-badge-' . esc_attr( $item['status_class'] ) . '">' . esc_html( $item['status'] ) . '</span></td></tr>';
        }
        echo '</tbody></table></div></div>';

        // Right column: activity, then quick actions.
        echo '<div class="tzfd-side-col">';
        echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2>' . esc_html__( 'Front desk activity', 'titan-zero-trades-front-desk' ) . '</h2><span class="tzfd-live">' . esc_html__( 'LIVE', 'titan-zero-trades-front-desk' ) . '</span></div>';
        echo '<ul class="tzfd-activity">';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables; values are bound with prepare().
        $recent = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, time, posted_data FROM %i ORDER BY id DESC LIMIT 4', $wpdb->prefix . TZFD_Bookings::TABLE ) );
        foreach ( $recent as $row ) {
            $data = TZFD_Stored_Data::decode_booking( (string) $row->posted_data );
            $name = is_array( $data ) ? TZFD_Booking_View::customer_name( $data ) : '';
            $name = '' !== $name ? $name : __( 'a customer', 'titan-zero-trades-front-desk' );
            /* translators: %s: customer name. */
            echo '<li><strong>' . esc_html__( 'New enquiry received', 'titan-zero-trades-front-desk' ) . '</strong><span>' . esc_html( sprintf( __( 'Booking from %s', 'titan-zero-trades-front-desk' ), $name ) ) . '</span><small>' . esc_html( mysql2date( 'j M, H:i', (string) $row->time ) ) . '</small></li>';
        }
        if ( TZFD_Dashboard_Figures::quote_counts()['available'] ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables; values are bound with prepare().
            $quote_rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT customer_name, status, updated FROM %i WHERE status IN ('sent','approved','declined') ORDER BY updated DESC LIMIT 3",
                $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES
            ) );
            foreach ( $quote_rows as $row ) {
                /* translators: %s: customer name. */
                $verb = 'sent' === $row->status ? __( 'Quote sent to %s', 'titan-zero-trades-front-desk' ) : ( 'approved' === $row->status ? __( 'Quote approved by %s', 'titan-zero-trades-front-desk' ) : __( 'Quote declined by %s', 'titan-zero-trades-front-desk' ) );
                echo '<li><strong>' . esc_html( sprintf( $verb, $row->customer_name ) ) . '</strong><small>' . esc_html( mysql2date( 'j M, H:i', (string) $row->updated ) ) . '</small></li>';
            }
        }
        if ( empty( $recent ) ) {
            echo '<li class="tzfd-muted">' . esc_html__( 'Nothing yet. New bookings and quotes appear here.', 'titan-zero-trades-front-desk' ) . '</li>';
        }
        echo '</ul></div>';

        $add_url = self::add_booking_url();
        echo '<div class="tzfd-card"><h2>' . esc_html__( 'Quick actions', 'titan-zero-trades-front-desk' ) . '</h2>';
        /* translators: 1: amount booked today, 2: payments received today. */
        echo '<p class="tzfd-muted">' . esc_html( sprintf( __( 'Today: %1$s booked, %2$s received', 'titan-zero-trades-front-desk' ), TZFD_Money::format( $figures['booked'] ), TZFD_Money::format( $figures['payments']['today'] ) ) ) . '</p>';
        echo '<div class="tzfd-quick"><a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( $add_url ) . '">+ ' . esc_html__( 'Add booking', 'titan-zero-trades-front-desk' ) . '</a>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::QUOTES . '&qtab=new' ) ) . '">+ ' . esc_html__( 'Create quote', 'titan-zero-trades-front-desk' ) . '</a></div>';
        echo '</div>';
        echo '</div>';

        echo '</div></div>';
    }

    /* ---------------------------------------------------------------
     * Bookings: upcoming bookings across calendars, with a link to each calendar's Booking Orders
     * ------------------------------------------------------------- */

    /** Link that opens the add-booking form on the first calendar, or the calendar when there is none. */
    public static function add_booking_url() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the name is bound with prepare().
        $calendar_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id LIMIT 1', $wpdb->prefix . 'tzfd_forms' ) );
        return $calendar_id > 0 ? admin_url( 'admin.php?page=' . self::PARENT . '&cal=' . $calendar_id . '&addbk=1' ) : admin_url( 'admin.php?page=' . self::CALENDAR );
    }

    /**
     * Bookings across every calendar: tabs (all, upcoming, needs approval, completed), a filter bar,
     * the stat tiles from the mock-up, and the booking table. Each row opens its calendar's booking list.
     */
    public static function page_bookings() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;
        $today = TZFD_Booking_View::today();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $range   = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'next30';
        $service = isset( $_GET['service'] ) ? sanitize_text_field( wp_unslash( $_GET['service'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $from = TZFD_Booking_View::add_days( $today, -60 );
        $to   = TZFD_Booking_View::add_days( $today, 60 );
        $found = TZFD_Booking_View::fetch( $from, $to );
        $items = $found['items'];

        // Stat tiles use the whole window; the table uses the tab and filters.
        $upcoming_week = 0; $needs = 0; $completed_month = 0;
        $month_start = substr( $today, 0, 8 ) . '01';
        foreach ( $items as $item ) {
            $live = 'cancelled' !== $item['status_class'];
            if ( $live && $item['date'] >= $today && $item['date'] <= TZFD_Booking_View::add_days( $today, 6 ) ) { $upcoming_week++; }
            if ( 'pending' === $item['status_class'] ) { $needs++; }
            if ( $live && $item['date'] < $today && $item['date'] >= $month_start ) { $completed_month++; }
        }

        $list = array();
        foreach ( $items as $item ) {
            $live = 'cancelled' !== $item['status_class'];
            if ( 'upcoming' === $tab && ! ( $live && $item['date'] >= $today ) ) { continue; }
            if ( 'approval' === $tab && 'pending' !== $item['status_class'] ) { continue; }
            if ( 'completed' === $tab && ! ( $live && $item['date'] < $today ) ) { continue; }
            if ( 'next7' === $range && ! ( $item['date'] >= $today && $item['date'] <= TZFD_Booking_View::add_days( $today, 6 ) ) ) { continue; }
            if ( 'next30' === $range && ! ( $item['date'] >= $today && $item['date'] <= TZFD_Booking_View::add_days( $today, 29 ) ) ) { continue; }
            if ( 'past30' === $range && ! ( $item['date'] < $today && $item['date'] >= TZFD_Booking_View::add_days( $today, -30 ) ) ) { continue; }
            if ( '' !== $service && $item['service'] !== $service ) { continue; }
            if ( '' !== $search ) {
                $hay = strtolower( $item['customer'] . ' ' . $item['email'] . ' ' . $item['booking_id'] );
                if ( false === strpos( $hay, strtolower( $search ) ) ) { continue; }
            }
            $list[] = $item;
        }
        usort( $list, function ( $a, $b ) { return array( $b['date'], $b['start'], $b['booking_id'] ) <=> array( $a['date'], $a['start'], $a['booking_id'] ); } );
        if ( 'upcoming' === $tab ) { usort( $list, function ( $a, $b ) { return array( $a['date'], $a['start'] ) <=> array( $b['date'], $b['start'] ); } ); }

        // Services for the filter.
        $service_names = array();
        foreach ( TZFD_Dashboard_Figures::service_prices() as $calendar_services ) { foreach ( array_keys( $calendar_services ) as $name ) { $service_names[ $name ] = $name; } }
        ksort( $service_names );

        $tabs = array(
            'all'       => __( 'All bookings', 'titan-zero-trades-front-desk' ),
            'upcoming'  => __( 'Upcoming', 'titan-zero-trades-front-desk' ),
            'approval'  => __( 'Needs approval', 'titan-zero-trades-front-desk' ),
            'completed' => __( 'Completed', 'titan-zero-trades-front-desk' ),
        );
        $base = admin_url( 'admin.php?page=' . self::BOOKINGS );

        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-bookings">';
        TZFD_Admin_Layout::header( __( 'Bookings', 'titan-zero-trades-front-desk' ), __( 'Review requests, confirm appointments and keep each booking moving.', 'titan-zero-trades-front-desk' ),
            '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( self::add_booking_url() ) . '">+ ' . esc_html__( 'New booking', 'titan-zero-trades-front-desk' ) . '</a>' );

        if ( empty( $service_names ) && empty( $items ) ) {
            echo '<div class="tzfd-card">';
            TZFD_Brand::empty_state( 'bookings', __( 'No bookings yet. Create a calendar, and its bookings will appear here.', 'titan-zero-trades-front-desk' ) );
            echo '</div></div>';
            return;
        }

        echo '<div class="tzfd-stats" role="list">';
        TZFD_Admin_Layout::stat( (string) $upcoming_week, __( 'Upcoming', 'titan-zero-trades-front-desk' ), __( 'next 7 days', 'titan-zero-trades-front-desk' ), 'blue', $base . '&tab=upcoming' );
        TZFD_Admin_Layout::stat( (string) $needs, __( 'Needs approval', 'titan-zero-trades-front-desk' ), __( 'requests', 'titan-zero-trades-front-desk' ), 'amber', $base . '&tab=approval' );
        TZFD_Admin_Layout::stat( (string) $completed_month, __( 'Completed', 'titan-zero-trades-front-desk' ), __( 'this month', 'titan-zero-trades-front-desk' ), 'green', $base . '&tab=completed' );
        echo '</div>';

        echo '<div class="tzfd-card">';
        echo '<nav class="tzfd-tabs" aria-label="' . esc_attr__( 'Booking lists', 'titan-zero-trades-front-desk' ) . '">';
        foreach ( $tabs as $key => $label ) {
            echo '<a href="' . esc_url( $base . ( 'all' === $key ? '' : '&tab=' . $key ) ) . '"' . ( $key === $tab ? ' class="is-active" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';

        echo '<form method="get" class="tzfd-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::BOOKINGS ) . '" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search customer or booking ID', 'titan-zero-trades-front-desk' ) . '" aria-label="' . esc_attr__( 'Search customer or booking ID', 'titan-zero-trades-front-desk' ) . '" />';
        echo '<label class="screen-reader-text" for="tzfd_range">' . esc_html__( 'Date range', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_range" name="range">';
        $ranges = array( 'next7' => __( 'Next 7 days', 'titan-zero-trades-front-desk' ), 'next30' => __( 'Next 30 days', 'titan-zero-trades-front-desk' ), 'past30' => __( 'Past 30 days', 'titan-zero-trades-front-desk' ), 'all' => __( 'All dates', 'titan-zero-trades-front-desk' ) );
        foreach ( $ranges as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $range, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select>';
        echo '<label class="screen-reader-text" for="tzfd_service">' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_service" name="service"><option value="">' . esc_html__( 'All services', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $service_names as $name ) { echo '<option value="' . esc_attr( $name ) . '"' . selected( $service, $name, false ) . '>' . esc_html( $name ) . '</option>'; }
        echo '</select>';
        echo '<button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Filter', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '</form>';

        echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Booking', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Date and time', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</th><th></th>';
        echo '</tr></thead><tbody>';
        if ( empty( $list ) ) {
            echo '<tr><td colspan="9" class="tzfd-muted">' . esc_html__( 'No bookings match these filters.', 'titan-zero-trades-front-desk' ) . '</td></tr>';
        }
        foreach ( array_slice( $list, 0, 100 ) as $item ) {
            $when = mysql2date( 'D j M Y', $item['date'] ) . ( '' !== $item['start'] ? ', ' . $item['start'] : '' );
            $open = admin_url( 'admin.php?page=' . self::PARENT . '&cal=' . (int) $item['form_id'] . '&list=1' );
            echo '<tr><td>#' . (int) $item['booking_id'] . '</td><td><strong>' . esc_html( $item['customer'] ) . '</strong></td><td>' . esc_html( $item['service'] ) . '</td>';
            echo '<td>' . esc_html( $when ) . '</td>';
            echo '<td><span class="tzfd-badge tzfd-badge-' . esc_attr( $item['status_class'] ) . '">' . esc_html( $item['status'] ) . '</span></td>';
            echo '<td>' . esc_html( $item['calendar'] ) . '</td>';
            echo '<td><a class="tzfd-open" href="' . esc_url( $open ) . '">' . esc_html__( 'Open', 'titan-zero-trades-front-desk' ) . ' &rarr;</a></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';

        echo '<div class="tzfd-card tzfd-foot-card"><div><h3>' . esc_html__( 'Booking workflow', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<p>' . esc_html__( 'Request, review details, confirm the time, then send confirmation.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::ADDONS ) ) . '">' . esc_html__( 'Automations', 'titan-zero-trades-front-desk' ) . '</a></div>';
        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Services: the services of every calendar, with a link to edit each calendar
     * ------------------------------------------------------------- */

    public static function page_services() {
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only values: they choose the notice, the filters and the service shown.
        $saved     = isset( $_GET['saved'] );
        $failed    = isset( $_GET['error'] );
        $edit_cal  = isset( $_GET['cal'] ) ? absint( wp_unslash( $_GET['cal'] ) ) : 0;
        $edit_name = isset( $_GET['svc'] ) ? sanitize_text_field( wp_unslash( $_GET['svc'] ) ) : '';
        $search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $filter_cal = isset( $_GET['filter_cal'] ) ? absint( wp_unslash( $_GET['filter_cal'] ) ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-services">';
        TZFD_Admin_Layout::header( __( 'Services', 'titan-zero-trades-front-desk' ), __( 'Set up the services customers can request and your team can deliver.', 'titan-zero-trades-front-desk' ),
            '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT . '&manage=1' ) ) . '">+ ' . esc_html__( 'Add service', 'titan-zero-trades-front-desk' ) . '</a>' );
        if ( $saved ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Service address saved.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( $failed ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'That service was not found. Reload the page and try again.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the name is bound with prepare().
        $calendars = $wpdb->get_results( $wpdb->prepare( 'SELECT id, form_name, form_structure FROM %i ORDER BY form_name', $wpdb->prefix . 'tzfd_forms' ) );
        $rows = array();
        $calendar_options = array();
        foreach ( (array) $calendars as $calendar ) {
            $label = '' !== (string) $calendar->form_name ? $calendar->form_name : sprintf( /* translators: %d: calendar ID. */ __( 'Calendar %d', 'titan-zero-trades-front-desk' ), (int) $calendar->id );
            $calendar_options[ (int) $calendar->id ] = $label;
            foreach ( self::services_in( (string) $calendar->form_structure ) as $service ) {
                $details = TZFD_Service_Details::get( (int) $calendar->id, $service['name'] );
                $rows[] = array_merge( $service, array( 'calendar' => (int) $calendar->id, 'calendar_label' => $label, 'address' => $details['address'], 'location_id' => $details['location_id'] ) );
            }
        }
        $locations = TZFD_Service_Details::location_options();

        if ( $edit_cal > 0 && '' !== $edit_name ) {
            foreach ( $rows as $row ) {
                if ( $row['calendar'] === $edit_cal && $row['name'] === $edit_name ) { self::service_form( $row, $locations ); break; }
            }
        }

        // Catalog card: how many services are live, and where to manage calendars.
        echo '<div class="tzfd-card tzfd-foot-card"><div><h2 class="tzfd-card-title">' . esc_html__( 'Your service catalog', 'titan-zero-trades-front-desk' ) . ' <span class="tzfd-badge tzfd-badge-active">' . esc_html( sprintf( /* translators: %d: number of active services. */ __( '%d ACTIVE', 'titan-zero-trades-front-desk' ), count( $rows ) ) ) . '</span></h2>';
        echo '<p>' . esc_html__( 'Services are linked to calendars, durations, prices and addresses.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT . '&manage=1' ) ) . '">' . esc_html__( 'Manage calendars', 'titan-zero-trades-front-desk' ) . '</a></div>';

        // Services table with the filters from the mock-up.
        $shown = array();
        foreach ( $rows as $row ) {
            if ( $filter_cal > 0 && $row['calendar'] !== $filter_cal ) { continue; }
            if ( '' !== $search && false === stripos( $row['name'] . ' ' . $row['calendar_label'], $search ) ) { continue; }
            $shown[] = $row;
        }
        usort( $shown, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );

        echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2>' . esc_html__( 'Services', 'titan-zero-trades-front-desk' ) . '</h2></div>';
        echo '<form method="get" class="tzfd-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::SERVICES ) . '" />';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search services', 'titan-zero-trades-front-desk' ) . '" aria-label="' . esc_attr__( 'Search services', 'titan-zero-trades-front-desk' ) . '" />';
        echo '<label class="screen-reader-text" for="tzfd_filter_cal">' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_filter_cal" name="filter_cal"><option value="0">' . esc_html__( 'All calendars', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $calendar_options as $id => $label ) { echo '<option value="' . (int) $id . '"' . selected( $filter_cal, $id, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select><button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Filter', 'titan-zero-trades-front-desk' ) . '</button></form>';

        echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Location', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Duration', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Price', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Capacity', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Visibility', 'titan-zero-trades-front-desk' ) . '</th><th></th>';
        echo '</tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="9">';
            TZFD_Brand::empty_state( 'services', __( 'No services yet. Add them in a calendar, or use Preset Setup.', 'titan-zero-trades-front-desk' ) );
            echo '</td></tr>';
        } elseif ( empty( $shown ) ) {
            echo '<tr><td colspan="9" class="tzfd-muted">' . esc_html__( 'No services match these filters.', 'titan-zero-trades-front-desk' ) . '</td></tr>';
        }
        foreach ( $shown as $row ) {
            $address_url = admin_url( 'admin.php?page=' . self::SERVICES . '&cal=' . (int) $row['calendar'] . '&svc=' . rawurlencode( $row['name'] ) );
            $duration    = is_numeric( $row['duration'] ) ? sprintf( /* translators: %d: minutes. */ __( '%d min', 'titan-zero-trades-front-desk' ), (int) $row['duration'] ) : (string) $row['duration'];
            $price       = is_numeric( $row['price'] ) ? TZFD_Money::format( (float) $row['price'] ) : (string) $row['price'];
            $where = isset( $locations[ $row['location_id'] ] ) ? $locations[ $row['location_id'] ] : '';
            echo '<tr><td><strong>' . esc_html( $row['name'] ) . '</strong></td><td>' . esc_html( $row['calendar_label'] ) . '</td>';
            echo '<td>' . ( '' !== (string) $row['address'] ? '<span class="tzfd-cell-clip" title="' . esc_attr( $row['address'] ) . '">' . esc_html( $row['address'] ) . '</span>' : '<span class="tzfd-muted">' . esc_html__( 'No address yet', 'titan-zero-trades-front-desk' ) . '</span>' ) . '</td>';
            echo '<td>' . ( '' !== $where ? esc_html( $where ) : '<span class="tzfd-muted">—</span>' ) . '</td>';
            echo '<td>' . ( '' !== $duration ? esc_html( $duration ) : '<span class="tzfd-muted">—</span>' ) . '</td>';
            echo '<td>' . ( '' !== $price ? esc_html( $price ) : '<span class="tzfd-muted">—</span>' ) . '</td>';
            echo '<td>' . ( '' !== (string) $row['capacity'] ? esc_html( $row['capacity'] ) : '<span class="tzfd-muted">—</span>' ) . '</td>';
            echo '<td><span class="tzfd-badge tzfd-badge-published">' . esc_html__( 'Published', 'titan-zero-trades-front-desk' ) . '</span></td>';
            echo '<td class="tzfd-row-actions"><a class="tzfd-open" href="' . esc_url( $address_url ) . '">' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</a> &middot; ';
            echo '<a class="tzfd-open" href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT . '&cal=' . (int) $row['calendar'] . '&edit=1' ) ) . '">' . esc_html__( 'Edit calendar', 'titan-zero-trades-front-desk' ) . '</a></td></tr>';
        }
        echo '</tbody></table></div></div>';

        echo '<div class="tzfd-card tzfd-foot-card"><div><p class="tzfd-tip"><strong>' . esc_html__( 'Tip:', 'titan-zero-trades-front-desk' ) . '</strong> ' . esc_html__( 'Add an address and location to each service, so customers and workers see where it is given.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<div class="tzfd-page-actions"><a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT . '&manage=1' ) ) . '">+ ' . esc_html__( 'Add a service', 'titan-zero-trades-front-desk' ) . '</a>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::QUOTES . '&qtab=catalog' ) ) . '">' . esc_html__( 'Edit price catalog', 'titan-zero-trades-front-desk' ) . '</a></div></div>';
        echo '</div>';
    }

    /** Address and location editor for one service, shown above the Services table. */
    private static function service_form( $row, $locations ) {
        $details = TZFD_Service_Details::get( $row['calendar'], $row['name'] );
        /* translators: %s: service name. */
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html( sprintf( __( 'Address for %s', 'titan-zero-trades-front-desk' ), $row['name'] ) ) . '</h2>';
        echo '<p class="tzfd-muted">' . esc_html( $row['calendar_label'] ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tzfd_service_save" />';
        echo '<input type="hidden" name="calendar" value="' . (int) $row['calendar'] . '" />';
        echo '<input type="hidden" name="service" value="' . esc_attr( $row['name'] ) . '" />';
        wp_nonce_field( TZFD_Service_Details::NONCE, 'tzfd_service_nonce' );
        echo '<div class="tzfd-form-grid"><div class="tzfd-full"><label for="tzfd_service_address">' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</label>';
        echo '<textarea id="tzfd_service_address" name="address" rows="3" class="large-text">' . esc_textarea( $details['address'] ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Where this service is given, such as a clinic room or the customer\'s home.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<div><label for="tzfd_service_location">' . esc_html__( 'Location', 'titan-zero-trades-front-desk' ) . '</label>';
        echo '<select id="tzfd_service_location" name="location_id"><option value="">' . esc_html__( 'No saved location', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $locations as $id => $name ) {
            echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $details['location_id'], (string) $id, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select>';
        if ( empty( $locations ) ) { echo '<p class="description">' . esc_html__( 'Add a location under Locations to pick one here.', 'titan-zero-trades-front-desk' ) . '</p>'; }
        echo '</div></div>';
        echo '<div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Save address', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::SERVICES ) ) . '">' . esc_html__( 'Cancel', 'titan-zero-trades-front-desk' ) . '</a></div>';
        echo '</form></div>';
    }

    /** Services stored in one calendar's form structure (JSON), as plain rows. */
    public static function services_in( $structure ) {
        $data = json_decode( (string) $structure, true );
        if ( ! is_array( $data ) ) { $data = json_decode( stripslashes( (string) $structure ), true ); }
        $out = array();
        self::collect_services( is_array( $data ) ? $data : array(), $out );
        return $out;
    }

    private static function collect_services( $node, array &$out ) {
        if ( ! is_array( $node ) ) { return; }
        foreach ( $node as $key => $value ) {
            if ( 'services' === $key && is_array( $value ) ) {
                foreach ( $value as $item ) {
                    if ( is_array( $item ) && ! empty( $item['name'] ) ) {
                        $out[] = array(
                            'name'     => (string) $item['name'],
                            'price'    => isset( $item['price'] ) ? (string) $item['price'] : '',
                            'duration' => isset( $item['duration'] ) ? (string) $item['duration'] : '',
                            'capacity' => isset( $item['capacity'] ) ? (string) $item['capacity'] : '',
                        );
                    }
                }
            } elseif ( is_array( $value ) ) {
                self::collect_services( $value, $out );
            }
        }
    }
}
