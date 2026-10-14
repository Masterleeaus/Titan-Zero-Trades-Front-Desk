<?php
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET values choose which customer or view to display; they change no state. Every write is nonce-checked in its admin-post handler. 
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned customer table. Direct queries are intentional: a cache would serve stale customer details. Values are bound with prepare() or %i placeholders.
/**
 * Mini CRM: customers, the "New" menu in the admin bar, and the Customers menu page.
 * Customers are stored in one table. Quotes are matched by email; bookings are matched
 * by any stored value equal to the customer's email or phone. GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class TZFD_CRM {
    const TABLE          = 'tzfd_customers';
    const SCHEMA_OPTION  = 'titan_zero_front_desk_crm_schema';
    const SCHEMA_VERSION = '2';
    const CAP            = 'edit_pages';
    const PAGE           = 'tzfd-customers';

    private static $registered = false;

    public static function register() {
        if ( self::$registered ) {
            return;
        }
        self::$registered = true;
        add_action( 'admin_init', array( __CLASS__, 'ensure_schema' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
        add_action( 'admin_post_tzfd_crm_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_tzfd_crm_delete', array( __CLASS__, 'handle_delete' ) );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function ensure_schema() {
        if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL DEFAULT '',
            company varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            address text,
            city varchar(120) NOT NULL DEFAULT '',
            region varchar(120) NOT NULL DEFAULT '',
            postcode varchar(20) NOT NULL DEFAULT '',
            country varchar(80) NOT NULL DEFAULT '',
            preferred_contact varchar(10) NOT NULL DEFAULT '',
            source varchar(120) NOT NULL DEFAULT '',
            notes text,
            created datetime DEFAULT CURRENT_TIMESTAMP,
            updated datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY email (email)
        ) $charset;" );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
    }

    /* ------------------------------------------------------------
     *  Helpers
     * ------------------------------------------------------------ */

    private static function first_calendar() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM %i', $wpdb->prefix . 'tzfd_forms' ) );
    }

    private static function quotes_on() {
        return in_array( 'tzfd-Quotes', (array) get_option( 'tzfd_addons_active_list', array() ), true );
    }

    private static function page_url( $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
    }

    private static function deny() {
        wp_die( esc_html__( 'You cannot access this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
    }

    /* ------------------------------------------------------------
     *  "New" menu in the admin bar
     * ------------------------------------------------------------ */

    public static function admin_bar( $bar ) {
        if ( ! is_object( $bar ) || ! current_user_can( self::CAP ) ) {
            return;
        }
        $calendar = self::first_calendar();
        $bar->add_node( array(
            'id'     => 'tzfd-new-booking',
            'parent' => 'new-content',
            'title'  => __( 'New booking', 'titan-zero-trades-front-desk' ),
            'href'   => $calendar
                ? admin_url( 'admin.php?page=tzfd&cal=' . $calendar . '&addbk=1' )
                : admin_url( 'admin.php?page=tzfd_calendar' ),
        ) );
        if ( self::quotes_on() ) {
            $bar->add_node( array(
                'id'     => 'tzfd-new-quote',
                'parent' => 'new-content',
                'title'  => __( 'New quote', 'titan-zero-trades-front-desk' ),
                'href'   => admin_url( 'admin.php?page=tzfd-quotes&qtab=new' ),
            ) );
        }
        $bar->add_node( array(
            'id'     => 'tzfd-new-service',
            'parent' => 'new-content',
            'title'  => __( 'New service', 'titan-zero-trades-front-desk' ),
            'href'   => $calendar
                ? admin_url( 'admin.php?page=tzfd&cal=' . $calendar . '&edit=1' )
                : admin_url( 'admin.php?page=tzfd_calendar' ),
        ) );
        $bar->add_node( array(
            'id'     => 'tzfd-new-customer',
            'parent' => 'new-content',
            'title'  => __( 'New customer', 'titan-zero-trades-front-desk' ),
            'href'   => self::page_url( array( 'view' => 'edit' ) ),
        ) );
    }

    /* ------------------------------------------------------------
     *  Menu page
     * ------------------------------------------------------------ */

    public static function menu() {
        add_submenu_page(
            'tzfd',
            __( 'Customers', 'titan-zero-trades-front-desk' ),
            __( 'Customers', 'titan-zero-trades-front-desk' ),
            self::CAP,
            self::PAGE,
            array( __CLASS__, 'page' )
        );
    }

    /** Loads the confirm-prompt script on the Customers screen only. */
    public static function enqueue_scripts() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of which screen is open.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::PAGE !== $page ) {
            return;
        }
        wp_enqueue_script( 'tzfd-pipe-pay-confirm', TZFD_Brand::url( 'js/inline/pipe-pay-confirm.js' ), array(), '2.0.1', true );
    }

    public static function page() {
        if ( ! current_user_can( self::CAP ) ) {
            self::deny();
        }
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
        $id   = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

        // The list draws its own page header with the Add customer button; other views show the plain title.
        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-customers">';
        if ( '' !== $view || $id > 0 ) {
            echo '<h1>' . esc_html__( 'Customers', 'titan-zero-trades-front-desk' ) . '</h1>';
        }
        self::notices();

        if ( 'edit' === $view ) {
            self::render_form( $id );
        } elseif ( $id > 0 ) {
            self::render_detail( $id );
        } else {
            self::render_list();
        }
        echo '</div>';
    }

    private static function notices() {
        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Customer saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( isset( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Customer deleted. Their bookings and quotes are kept.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Enter a name or an email address.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
    }

    /** Field labels and the preferred-contact choices, in display order. */
    private static function preferred_choices() {
        return array(
            ''      => __( 'No preference', 'titan-zero-trades-front-desk' ),
            'email' => __( 'Email', 'titan-zero-trades-front-desk' ),
            'phone' => __( 'Phone', 'titan-zero-trades-front-desk' ),
            'text'  => __( 'Text message', 'titan-zero-trades-front-desk' ),
        );
    }

    private static function render_list() {
        global $wpdb;

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';

        $today = TZFD_Booking_View::today();
        $month = substr( $today, 0, 7 );

        $total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, name, company, email, phone, city, created FROM %i WHERE name LIKE %s OR company LIKE %s OR email LIKE %s OR phone LIKE %s OR city LIKE %s ORDER BY name LIMIT 300',
                self::table(), $like, $like, $like, $like, $like
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, company, email, phone, city, created FROM %i ORDER BY name LIMIT 300', self::table() ) );
        }

        // Activity from bookings (a year either side) and quotes waiting for a reply, matched by email.
        $found     = TZFD_Booking_View::fetch( TZFD_Booking_View::add_days( $today, -365 ), TZFD_Booking_View::add_days( $today, 365 ) );
        $by_email  = array();
        $upcoming30 = 0;
        $month_bookers = array();
        $until30 = TZFD_Booking_View::add_days( $today, 29 );
        foreach ( $found['items'] as $item ) {
            if ( 'cancelled' === $item['status_class'] ) { continue; }
            $email = strtolower( trim( (string) $item['email'] ) );
            if ( '' !== $email ) { $by_email[ $email ][] = $item['date']; }
            if ( $item['date'] >= $today && $item['date'] <= $until30 ) { $upcoming30++; }
            if ( '' !== $email && 0 === strpos( $item['date'], $month ) ) { $month_bookers[ $email ] = true; }
        }
        $waiting = array();
        if ( class_exists( 'TZFD_Quotes_Engine' ) && TZFD_Dashboard_Figures::quote_counts()['available'] ) {
            $quote_table = $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES;
            $emails = (array) $wpdb->get_col( $wpdb->prepare( "SELECT LOWER(customer_email) FROM %i WHERE status = 'sent' AND customer_email <> ''", $quote_table ) );
            foreach ( $emails as $e ) { $waiting[ strtolower( (string) $e ) ] = true; }
        }

        $list = array();
        $active_month = 0; $follow_n = 0;
        foreach ( (array) $rows as $row ) {
            $email = strtolower( trim( (string) $row->email ) );
            $dates = isset( $by_email[ $email ] ) ? $by_email[ $email ] : array();
            $past  = array_filter( $dates, function ( $d ) use ( $today ) { return $d < $today; } );
            $next  = array_filter( $dates, function ( $d ) use ( $today ) { return $d >= $today; } );
            $last  = $past ? max( $past ) : '';
            $soon  = $next ? min( $next ) : '';
            $follow = '' !== $email && isset( $waiting[ $email ] );
            if ( $follow ) { $status = 'follow'; $follow_n++; }
            elseif ( '' !== $last || '' !== $soon ) { $status = 'active'; }
            else { $status = 'new'; }
            if ( '' !== $email && isset( $month_bookers[ $email ] ) ) { $active_month++; }
            if ( 'follow' === $filter && 'follow' !== $status ) { continue; }
            if ( 'active' === $filter && 'active' !== $status ) { continue; }
            $list[] = array( 'row' => $row, 'last' => $last, 'next' => $soon, 'status' => $status );
        }

        echo '<div class="tzfd-page-head"><div><h1>' . esc_html__( 'Customers', 'titan-zero-trades-front-desk' ) . '</h1>';
        echo '<p class="tzfd-lead">' . esc_html__( 'Keep customer contact details, history and next steps together.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<div class="tzfd-page-actions"><a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( self::page_url( array( 'view' => 'edit' ) ) ) . '">+ ' . esc_html__( 'Add customer', 'titan-zero-trades-front-desk' ) . '</a></div></div>';

        echo '<div class="tzfd-stats" role="list">';
        TZFD_Admin_Layout::stat( (string) $total, __( 'All customers', 'titan-zero-trades-front-desk' ), __( 'records', 'titan-zero-trades-front-desk' ), 'red' );
        TZFD_Admin_Layout::stat( (string) $active_month, __( 'Active this month', 'titan-zero-trades-front-desk' ), __( 'had a booking', 'titan-zero-trades-front-desk' ), 'green' );
        TZFD_Admin_Layout::stat( (string) $upcoming30, __( 'Upcoming bookings', 'titan-zero-trades-front-desk' ), __( 'next 30 days', 'titan-zero-trades-front-desk' ), 'blue' );
        TZFD_Admin_Layout::stat( (string) $follow_n, __( 'Need follow-up', 'titan-zero-trades-front-desk' ), __( 'quote waiting for a reply', 'titan-zero-trades-front-desk' ), 'amber' );
        echo '</div>';

        echo '<div class="tzfd-card">';
        echo '<form method="get" class="tzfd-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name, email or phone', 'titan-zero-trades-front-desk' ) . '" aria-label="' . esc_attr__( 'Search customers', 'titan-zero-trades-front-desk' ) . '" />';
        echo '<label class="screen-reader-text" for="tzfd_cust_filter">' . esc_html__( 'Show', 'titan-zero-trades-front-desk' ) . '</label><select id="tzfd_cust_filter" name="filter">';
        foreach ( array( 'all' => __( 'All customers', 'titan-zero-trades-front-desk' ), 'active' => __( 'Active', 'titan-zero-trades-front-desk' ), 'follow' => __( 'Follow up', 'titan-zero-trades-front-desk' ) ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $filter, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Filter', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '<span class="tzfd-filter-spacer"></span><a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::SETTINGS . '#csv' ) ) . '">' . esc_html__( 'Export', 'titan-zero-trades-front-desk' ) . '</a>';
        echo '</form>';

        if ( empty( $rows ) ) {
            TZFD_Brand::empty_state( 'customers', '' !== $search ? __( 'No customers match that search.', 'titan-zero-trades-front-desk' ) : __( 'No customers yet. Add one, or create a quote or booking for a customer.', 'titan-zero-trades-front-desk' ) );
            echo '</div>';
            return;
        }

        echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table"><thead><tr>'
            . '<th>' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</th>'
            . '<th>' . esc_html__( 'Contact', 'titan-zero-trades-front-desk' ) . '</th>'
            . '<th>' . esc_html__( 'Last activity', 'titan-zero-trades-front-desk' ) . '</th>'
            . '<th>' . esc_html__( 'Next booking', 'titan-zero-trades-front-desk' ) . '</th>'
            . '<th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th>'
            . '</tr></thead><tbody>';
        if ( empty( $list ) ) {
            echo '<tr><td colspan="5" class="tzfd-muted">' . esc_html__( 'No customers match this filter.', 'titan-zero-trades-front-desk' ) . '</td></tr>';
        }
        $labels = array( 'active' => array( __( 'Active', 'titan-zero-trades-front-desk' ), 'active' ), 'follow' => array( __( 'Follow up', 'titan-zero-trades-front-desk' ), 'followup' ), 'new' => array( __( 'New', 'titan-zero-trades-front-desk' ), 'other' ) );
        foreach ( array_slice( $list, 0, 200 ) as $entry ) {
            $row = $entry['row'];
            $contact = array_filter( array( (string) $row->email, (string) $row->phone, (string) ( $row->city ?? '' ) ), 'strlen' );
            echo '<tr><td><a class="tzfd-open" href="' . esc_url( self::page_url( array( 'id' => (int) $row->id ) ) ) . '">'
                . esc_html( '' !== $row->name ? $row->name : __( '(no name)', 'titan-zero-trades-front-desk' ) ) . '</a>'
                . ( '' !== (string) $row->company ? '<br /><span class="tzfd-muted">' . esc_html( $row->company ) . '</span>' : '' ) . '</td>';
            echo '<td>' . esc_html( implode( ' · ', $contact ) ) . '</td>';
            echo '<td>' . ( '' !== $entry['last'] ? esc_html( mysql2date( 'j M', $entry['last'] ) . ' · ' . __( 'Booking', 'titan-zero-trades-front-desk' ) ) : '<span class="tzfd-muted">—</span>' ) . '</td>';
            echo '<td>' . ( '' !== $entry['next'] ? esc_html( mysql2date( 'D j M', $entry['next'] ) ) : '<span class="tzfd-muted">—</span>' ) . '</td>';
            $label = $labels[ $entry['status'] ];
            echo '<td><span class="tzfd-badge tzfd-badge-' . esc_attr( $label[1] ) . '">' . esc_html( $label[0] ) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="tzfd-pager">' . esc_html( sprintf( /* translators: 1: rows shown, 2: total customers. */ __( 'Showing %1$d of %2$d customers', 'titan-zero-trades-front-desk' ), min( 200, count( $list ) ), $total ) ) . '</p>';
        echo '</div>';
    }

    /** One labelled field inside the form grid. */
    private static function field( $name, $label, $value, $type = 'text', $full = false ) {
        $id = 'tzfd_crm_' . $name;
        echo '<div' . ( $full ? ' class="tzfd-full"' : '' ) . '>';
        echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        if ( 'textarea' === $type ) {
            echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3">' . esc_textarea( $value ) . '</textarea>';
        } else {
            echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
        }
        echo '</div>';
    }

    private static function render_form( $id ) {
        global $wpdb;
        $c = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) ) : null;
        if ( $id && ! $c ) {
            echo '<p>' . esc_html__( 'That customer was not found.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        $v = function ( $field ) use ( $c ) {
            return $c ? (string) $c->$field : '';
        };
        echo '<div class="tzfd-card">';
        echo '<h2 class="tzfd-section-title">' . esc_html( $id ? __( 'Edit customer', 'titan-zero-trades-front-desk' ) : __( 'New customer', 'titan-zero-trades-front-desk' ) ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tzfd_crm_save" />';
        echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '" />';
        wp_nonce_field( 'tzfd_crm_save', 'tzfd_crm_nonce' );

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Contact', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<div class="tzfd-form-grid">';
        self::field( 'name', __( 'Name', 'titan-zero-trades-front-desk' ), $v( 'name' ) );
        self::field( 'company', __( 'Company', 'titan-zero-trades-front-desk' ), $v( 'company' ) );
        self::field( 'email', __( 'Email', 'titan-zero-trades-front-desk' ), $v( 'email' ), 'email' );
        self::field( 'phone', __( 'Phone', 'titan-zero-trades-front-desk' ), $v( 'phone' ), 'tel' );
        echo '<div><label for="tzfd_crm_preferred_contact">' . esc_html__( 'Preferred contact', 'titan-zero-trades-front-desk' ) . '</label>';
        echo '<select id="tzfd_crm_preferred_contact" name="preferred_contact">';
        foreach ( self::preferred_choices() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $v( 'preferred_contact' ), $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';
        self::field( 'source', __( 'How they found you', 'titan-zero-trades-front-desk' ), $v( 'source' ) );
        echo '</div>';

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<div class="tzfd-form-grid">';
        self::field( 'address', __( 'Street address', 'titan-zero-trades-front-desk' ), $v( 'address' ), 'textarea', true );
        self::field( 'city', __( 'City or suburb', 'titan-zero-trades-front-desk' ), $v( 'city' ) );
        self::field( 'region', __( 'State or region', 'titan-zero-trades-front-desk' ), $v( 'region' ) );
        self::field( 'postcode', __( 'Postcode', 'titan-zero-trades-front-desk' ), $v( 'postcode' ) );
        self::field( 'country', __( 'Country', 'titan-zero-trades-front-desk' ), $v( 'country' ) );
        echo '</div>';

        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Notes', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<div class="tzfd-form-grid">';
        self::field( 'notes', __( 'Notes', 'titan-zero-trades-front-desk' ), $v( 'notes' ), 'textarea', true );
        echo '</div>';

        echo '<div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Save customer', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $id ? self::page_url( array( 'id' => $id ) ) : self::page_url() ) . '">' . esc_html__( 'Cancel', 'titan-zero-trades-front-desk' ) . '</a></div>';
        echo '</form></div>';
    }

    private static function render_detail( $id ) {
        global $wpdb;
        $c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) );
        if ( ! $c ) {
            echo '<p>' . esc_html__( 'That customer was not found.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        $choices = self::preferred_choices();
        $place   = implode( ', ', array_filter( array( (string) $c->city, (string) $c->region, (string) $c->postcode, (string) $c->country ), 'strlen' ) );

        echo '<div class="tzfd-card">';
        echo '<h2 class="tzfd-section-title">' . esc_html( '' !== $c->name ? $c->name : __( '(no name)', 'titan-zero-trades-front-desk' ) ) . '</h2>';
        if ( '' !== (string) $c->company ) { echo '<p class="tzfd-muted">' . esc_html( $c->company ) . '</p>'; }
        echo '<div class="tzfd-actions">';
        echo '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( self::page_url( array( 'view' => 'edit', 'id' => $id ) ) ) . '">' . esc_html__( 'Edit', 'titan-zero-trades-front-desk' ) . '</a>';
        if ( self::quotes_on() ) {
            echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=tzfd-quotes&qtab=new' ) ) . '">' . esc_html__( 'New quote', 'titan-zero-trades-front-desk' ) . '</a>';
        }
        $calendar = self::first_calendar();
        if ( $calendar ) {
            echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=tzfd&cal=' . $calendar . '&addbk=1' ) ) . '">' . esc_html__( 'New booking', 'titan-zero-trades-front-desk' ) . '</a>';
        }
        echo '</div>';

        echo '<div class="tzfd-dl">';
        if ( '' !== (string) $c->email ) { echo '<span>' . esc_html__( 'Email', 'titan-zero-trades-front-desk' ) . '</span><span><a href="mailto:' . esc_attr( $c->email ) . '">' . esc_html( $c->email ) . '</a></span>'; }
        if ( '' !== (string) $c->phone ) { echo '<span>' . esc_html__( 'Phone', 'titan-zero-trades-front-desk' ) . '</span><span>' . esc_html( $c->phone ) . '</span>'; }
        echo '<span>' . esc_html__( 'Preferred contact', 'titan-zero-trades-front-desk' ) . '</span><span>' . esc_html( $choices[ (string) $c->preferred_contact ] ?? $choices[''] ) . '</span>';
        if ( '' !== (string) $c->source ) { echo '<span>' . esc_html__( 'How they found you', 'titan-zero-trades-front-desk' ) . '</span><span>' . esc_html( $c->source ) . '</span>'; }
        echo '</div></div>';

        echo '<div class="tzfd-card"><h3 class="tzfd-section-title">' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</h3>';
        if ( '' === trim( (string) $c->address . $place ) ) {
            echo '<p class="tzfd-muted">' . esc_html__( 'No address saved.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            echo '<div class="tzfd-dl">';
            if ( '' !== trim( (string) $c->address ) ) { echo '<span>' . esc_html__( 'Street', 'titan-zero-trades-front-desk' ) . '</span><span>' . nl2br( esc_html( $c->address ) ) . '</span>'; }
            if ( '' !== $place ) { echo '<span>' . esc_html__( 'City, region, postcode', 'titan-zero-trades-front-desk' ) . '</span><span>' . esc_html( $place ) . '</span>'; }
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="tzfd-card"><h3 class="tzfd-section-title">' . esc_html__( 'Notes', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<p>' . ( '' !== trim( (string) $c->notes ) ? nl2br( esc_html( $c->notes ) ) : '<span class="tzfd-muted">' . esc_html__( 'No notes.', 'titan-zero-trades-front-desk' ) . '</span>' ) . '</p></div>';

        echo '<div class="tzfd-card">';
        self::render_quotes( $c );
        self::render_bookings( $c );
        echo '</div>';

        echo '<div class="tzfd-card"><h3 class="tzfd-section-title">' . esc_html__( 'Delete customer', 'titan-zero-trades-front-desk' ) . '</h3>';
        echo '<p class="tzfd-muted">' . esc_html__( 'Deleting removes this customer record only. Bookings and quotes stay in place.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr__( 'Delete this customer record?', 'titan-zero-trades-front-desk' ) . '">';
        echo '<input type="hidden" name="action" value="tzfd_crm_delete" />';
        echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '" />';
        wp_nonce_field( 'tzfd_crm_delete_' . $id, 'tzfd_crm_nonce' );
        echo '<button type="submit" class="tzfd-btn tzfd-btn-danger">' . esc_html__( 'Delete customer', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '</form></div>';
    }

    private static function render_quotes( $c ) {
        global $wpdb;
        if ( '' === $c->email || ! class_exists( 'TZFD_Quotes_Engine' ) ) {
            return;
        }
        $table = $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        $quotes = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, reference, status, total, created FROM %i WHERE customer_email = %s ORDER BY id DESC LIMIT 50',
            $table, $c->email
        ) );
        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Quotes', 'titan-zero-trades-front-desk' ) . '</h3>';
        if ( empty( $quotes ) ) {
            echo '<p>' . esc_html__( 'No quotes for this email address.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Reference', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Total', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Created', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
        foreach ( $quotes as $q ) {
            echo '<tr><td>' . esc_html( $q->reference ) . '</td><td>' . esc_html( $q->status ) . '</td><td>' . esc_html( $q->total ) . '</td><td>' . esc_html( $q->created ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_bookings( $c ) {
        global $wpdb;
        $needles = array_filter( array( strtolower( trim( (string) $c->email ) ), preg_replace( '/\D+/', '', (string) $c->phone ) ) );
        echo '<h3 class="tzfd-section-title">' . esc_html__( 'Bookings', 'titan-zero-trades-front-desk' ) . '</h3>';
        if ( empty( $needles ) ) {
            echo '<p>' . esc_html__( 'Add an email address or phone number to find this customer\'s bookings.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        $messages = $wpdb->get_results( $wpdb->prepare(
            'SELECT m.posted_data FROM %i m INNER JOIN %i f ON m.formid = f.id ORDER BY m.id DESC LIMIT 500',
            $wpdb->prefix . 'tzfd_messages', $wpdb->prefix . 'tzfd_forms'
        ) );
        $found = array();
        foreach ( (array) $messages as $m ) {
            $data = TZFD_Stored_Data::decode_booking( $m->posted_data );
            if ( ! is_array( $data ) || empty( $data['apps'] ) || ! is_array( $data['apps'] ) ) {
                continue;
            }
            if ( ! self::contains_any( $data, $needles ) ) {
                continue;
            }
            foreach ( $data['apps'] as $app ) {
                $found[] = array(
                    isset( $app['date'] ) ? (string) $app['date'] : '',
                    isset( $app['slot'] ) ? (string) $app['slot'] : '',
                    isset( $app['service'] ) ? (string) $app['service'] : '',
                    isset( $app['cancelled'] ) ? (string) $app['cancelled'] : '',
                );
            }
        }
        if ( empty( $found ) ) {
            echo '<p>' . esc_html__( 'No bookings found for this customer.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        usort( $found, function ( $a, $b ) { return strcmp( $b[0], $a[0] ); } );
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Time', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
        foreach ( array_slice( $found, 0, 100 ) as $row ) {
            $row_status = TZFD_Booking_View::status( $row[3] );
            echo '<tr><td>' . esc_html( $row[0] ) . '</td><td>' . esc_html( $row[1] ) . '</td><td>' . esc_html( $row[2] ) . '</td><td>' . wp_kses( TZFD_Brand::booking_tag( $row_status['class'], $row_status['label'] ), TZFD_Brand::allowed_html() ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** True when any string value in the decoded booking equals one of the needles (case-insensitive, phone digits only). */
    private static function contains_any( $value, $needles ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( self::contains_any( $item, $needles ) ) {
                    return true;
                }
            }
            return false;
        }
        if ( ! is_scalar( $value ) ) {
            return false;
        }
        $text = strtolower( trim( (string) $value ) );
        $digits = preg_replace( '/\D+/', '', $text );
        foreach ( $needles as $needle ) {
            if ( $text === $needle || ( strlen( $needle ) >= 6 && $digits === $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------
     *  Writes (admin-post, nonce and capability checked)
     * ------------------------------------------------------------ */

    public static function handle_save() {
        if ( ! current_user_can( self::CAP ) ) {
            self::deny();
        }
        check_admin_referer( 'tzfd_crm_save', 'tzfd_crm_nonce' );
        global $wpdb;

        $id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
        $name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $address = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
        $notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
        $company = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
        $city    = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $region  = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';
        $postcode = isset( $_POST['postcode'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['postcode'] ) ), 0, 20 ) : '';
        $country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
        $source  = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        $prefer  = isset( $_POST['preferred_contact'] ) ? sanitize_key( wp_unslash( $_POST['preferred_contact'] ) ) : '';
        if ( ! array_key_exists( $prefer, self::preferred_choices() ) ) { $prefer = ''; }

        if ( '' === $name && '' === $email ) {
            wp_safe_redirect( self::page_url( array( 'view' => 'edit', 'id' => $id, 'error' => 1 ) ) );
            exit;
        }

        $data = array(
            'name'              => $name,
            'company'           => $company,
            'email'             => $email,
            'phone'             => $phone,
            'address'           => $address,
            'city'              => $city,
            'region'            => $region,
            'postcode'          => $postcode,
            'country'           => $country,
            'preferred_contact' => $prefer,
            'source'            => $source,
            'notes'             => $notes,
            'updated'           => current_time( 'mysql' ),
        );
        if ( $id ) {
            $wpdb->update( self::table(), $data, array( 'id' => $id ) );
        } else {
            $data['created'] = current_time( 'mysql' );
            $wpdb->insert( self::table(), $data );
            $id = (int) $wpdb->insert_id;
        }
        wp_safe_redirect( self::page_url( array( 'id' => $id, 'saved' => 1 ) ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! current_user_can( self::CAP ) ) {
            self::deny();
        }
        $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
        check_admin_referer( 'tzfd_crm_delete_' . $id, 'tzfd_crm_nonce' );
        global $wpdb;
        if ( $id ) {
            $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
        }
        wp_safe_redirect( self::page_url( array( 'deleted' => 1 ) ) );
        exit;
    }
}
