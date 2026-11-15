<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( defined( 'ABSPATH' ) ) { require_once __DIR__ . '/class-tzfd-request-scalar.php'; }
require_once __DIR__ . '/class-tzfd-request-value.php';

/**
 * Standalone Front Desk administration routes.
 *
 * All primary pages live under Front Desk in the native WordPress sidebar.
 */
final class TZFD_Admin_Menu {
    const PARENT   = 'tzfd';
    const CALENDAR = 'tz_fd_calendar';
    // Standalone native customer administration; other CRM integrations are optional.
    const CUSTOMERS = 'tz-customers';
    const BOOKINGS = 'tz_fd_bookings';
    const SERVICES = 'tz_fd_services';
    const ENQUIRIES = 'tz_fd_enquiries';
    const ADDONS   = 'tz_fd_addons';
    const SETTINGS = 'tz_fd_settings';
    const HELP     = 'tz_fd_support';
    const EMAILS   = 'tzfd-status-update-emails';
    const PREVIEW  = 'tzfd-scheduling-preview';
    const SETUP    = 'tzfd-setup';
    const PACKS    = 'tzfd-packs';
    const WORKERS  = 'tzfd-resources';
    const EQUIPMENT = 'tzfd-equipment';
    const LOCATIONS = 'tzfd-locations';
    const PIPELINE = TZFD_Tables::PIPELINE;
    /** Standalone Front Desk navigation order. */
    public static function main_menu_order() {
        return apply_filters( 'tz_fd_admin_menu_order', array(
            self::PARENT, self::BOOKINGS, self::CALENDAR, self::ENQUIRIES, self::CUSTOMERS, self::SERVICES, self::PIPELINE, self::WORKERS, self::EQUIPMENT,
            self::LOCATIONS, self::ADDONS, self::SETTINGS,
            self::EMAILS, self::PREVIEW, self::SETUP, self::PACKS,
        ) );
    }

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_pages' ), 11 );
        add_action( 'admin_menu', array( __CLASS__, 'reorder_menu' ), 9999 );
        // Vertical Packs route (see load_packs_page). Runs after the add-on's own menu hook (116) and only adds the route if that did not.
        add_action( 'admin_menu', array( __CLASS__, 'add_packs_route' ), 120 );
        add_action( 'admin_init', array( __CLASS__, 'load_packs_page' ) );
        add_filter( 'parent_file', array( __CLASS__, 'highlight_parent' ) );
        add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_routes' ) );
        add_action( 'admin_init', array( __CLASS__, 'hidden_setup_title' ) );
        add_action( 'admin_init', array( __CLASS__, 'hidden_page_titles' ) );
        add_action( 'admin_post_tz_fd_service_create', array( __CLASS__, 'create_service' ) );
        add_action( 'admin_post_tz_fd_service_update', array( __CLASS__, 'update_service' ) );
        add_action( 'admin_post_tz_fd_service_archive', array( __CLASS__, 'archive_service' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_navigation_script' ) );
    }

    /**
     * Vertical Packs is an add-on file. The boot loader reads that file only on a few pages, and the add-on
     * registers its menu route only when it is switched on. Without a route WordPress answers 403. These helpers
     * reuse the add-on's own object, handlers and render method; nothing is copied.
     */
    private static function packs_addon() {
        global $tz_addons_objs_list;
        if ( ! class_exists( 'TZFD_Vertical_Packs' ) ) {
            $file = dirname( __DIR__ ) . '/addons/core/vertical-packs.addon.php';
            if ( ! is_file( $file ) ) { return null; }
            require_once $file;
        }
        $key = 'tzfd-VerticalPacks';
        $obj = ( isset( $tz_addons_objs_list[ $key ] ) && is_object( $tz_addons_objs_list[ $key ] ) ) ? $tz_addons_objs_list[ $key ] : null;
        // The add-on switch decides. The object is kept even when the add-on is off, so its handlers must not be reachable then.
        if ( null === $obj || ! method_exists( $obj, 'addon_is_active' ) || ! $obj->addon_is_active() ) { return null; }
        return $obj;
    }

    /** On the packs page, runs the add-on's POST and GET handlers when the add-on did not hook them itself. */
    public static function load_packs_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value; the add-on's handlers check their own nonces.
        $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
        if ( self::PACKS !== $page || ! current_user_can( 'tz_fd_access' ) ) { return; }
        $obj = self::packs_addon();
        if ( ! $obj ) { return; }
        if ( ! has_action( 'admin_init', array( $obj, 'handle_actions' ) ) ) { $obj->handle_actions(); }
        if ( ! has_action( 'admin_init', array( $obj, 'save_vars' ) ) ) { $obj->save_vars(); }
        if ( ! has_action( 'admin_enqueue_scripts', array( $obj, 'enqueue_packs_assets' ) ) ) { $obj->enqueue_packs_assets( '' ); }
    }

    /** Hidden route for the packs page, added only when the add-on has not added one. */
    public static function add_packs_route() {
        if ( has_action( 'admin_page_' . self::PACKS ) ) { return; }
        add_submenu_page( '', __( 'Industry packs', 'titan-zero-trades-front-desk' ), __( 'Industry packs', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::PACKS, array( __CLASS__, 'page_packs' ) );
    }

    /** Renders the Vertical Packs page through the add-on's own render(). */
    public static function page_packs() {
        $obj = self::packs_addon();
        if ( ! $obj || ! method_exists( $obj, 'render' ) ) {
            wp_die( esc_html__( 'Vertical Packs is switched off. Switch it on under Add-ons to use it.', 'titan-zero-trades-front-desk' ) );
        }
        $obj->render();
    }

    /** Keeps the selected Settings subtab highlighted when navigation uses a URL fragment. */
    public static function enqueue_navigation_script() {
        if ( ! class_exists( 'TZFD_Admin_Layout' ) || ! TZFD_Admin_Layout::is_front_desk_screen() ) { return; }
        wp_enqueue_script( 'wp-util' );
        wp_add_inline_script( 'wp-util', "(function(){function sync(){var hash=window.location.hash.slice(1);if(!hash){return;}var links=document.querySelectorAll('[data-tzfd-hash]');links.forEach(function(link){var active=link.getAttribute('data-tzfd-hash')===hash;link.classList.toggle('is-active',active);if(active){link.setAttribute('aria-current','page');}else{link.removeAttribute('aria-current');}});}document.addEventListener('DOMContentLoaded',sync);window.addEventListener('hashchange',sync);}());" );
        // Service archive confirmation is attached through WordPress's script API, not an inline HTML handler.
        wp_add_inline_script( 'wp-util', "document.addEventListener('submit',function(event){var form=event.target;if(!form||!form.matches||!form.matches('form[data-tzfd-confirm-archive]')){return;}var message=form.getAttribute('data-tzfd-confirm-archive');if(message&&!window.confirm(message)){event.preventDefault();}},true);" );
    }

    /** Both portal links deliberately open the shared booking/customer portal page builder. */
    public static function customer_portal_builder() {
        self::portal_builder();
    }

    public static function portal_builder() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'Access denied.', 'titan-zero-trades-front-desk' ) ); }
        if ( class_exists( 'TZFD_Client_Portal' ) ) {
            ( new TZFD_Client_Portal() )->render_builder_page();
            return;
        }
        wp_die( esc_html__( 'The portal builder is unavailable.', 'titan-zero-trades-front-desk' ) );
    }

    /** Wraps an existing screen callback with the Front Desk UI pack when it is present. Returns $callback otherwise. */
    public static function ui_wrap( $callback, $page ) {
        return class_exists( 'TZ_UI_Front_Desk', false ) ? TZ_UI_Front_Desk::wrap( $callback, $page ) : $callback;
    }

    /** Pages that are reached from the Settings tab bar or from other pages, not from the main menu. */
    public static function add_pages() {
        // Own the Front Desk menu; retain all existing page slugs and callbacks.
        add_menu_page(
            __( 'Titan Front Desk', 'titan-zero-trades-front-desk' ),
            __( 'Front Desk', 'titan-zero-trades-front-desk' ),
            'tz_fd_access', self::PARENT,
            array( __CLASS__, 'route_front_desk' ),
            class_exists( 'TZFD_UI_Graphics' ) && '' !== TZFD_UI_Graphics::brand_icon( 'dashboard' ) ? TZFD_UI_Graphics::brand_icon( 'dashboard' ) : 'dashicons-calendar-alt', 59
        );
        add_submenu_page( self::PARENT, __( 'Dashboard', 'titan-zero-trades-front-desk' ), __( 'Dashboard', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::PARENT, array( __CLASS__, 'route_front_desk' ) );
        add_submenu_page( self::PARENT, __( 'Calendar', 'titan-zero-trades-front-desk' ), __( 'Calendar', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::CALENDAR, self::ui_wrap( array( 'TZFD_Admin_Menu', 'route_front_desk' ), self::CALENDAR ) );
        add_submenu_page( self::PARENT, __( 'Bookings', 'titan-zero-trades-front-desk' ), __( 'Bookings', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::BOOKINGS, self::ui_wrap( array( __CLASS__, 'page_bookings' ), self::BOOKINGS ) );
        add_submenu_page( self::PARENT, __( 'Customers', 'titan-zero-trades-front-desk' ), __( 'Customers', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::CUSTOMERS, self::ui_wrap( array( 'TZFD_Customer_Admin', 'page' ), self::CUSTOMERS ) );
        add_submenu_page( self::PARENT, __( 'Services', 'titan-zero-trades-front-desk' ), __( 'Services', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::SERVICES, self::ui_wrap( array( __CLASS__, 'page_services' ), self::SERVICES ) );
        // Enquiries belongs to the independent Front Desk menu.
        add_submenu_page( self::PARENT, __( 'Enquiries', 'titan-zero-trades-front-desk' ), __( 'Enquiries', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::ENQUIRIES, self::ui_wrap( array( 'TZFD_Enquiry_Review', 'page' ), self::ENQUIRIES ) );
        add_submenu_page( self::PARENT, __( 'Add-ons', 'titan-zero-trades-front-desk' ), __( 'Add-ons', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::ADDONS, self::ui_wrap( array( 'TZFD_Admin_Menu', 'route_front_desk' ), self::ADDONS ) );
        add_submenu_page( self::PARENT, __( 'Settings', 'titan-zero-trades-front-desk' ), __( 'Settings', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::SETTINGS, self::ui_wrap( array( 'TZFD_Admin_Menu', 'route_front_desk' ), self::SETTINGS ) );

        add_submenu_page( '', __( 'Help', 'titan-zero-trades-front-desk' ), __( 'Help', 'titan-zero-trades-front-desk' ), 'tz_fd_access', self::HELP, array( 'TZFD_Admin_Menu', 'route_front_desk' ) );
        add_submenu_page( '', __( 'Customer Portal', 'titan-zero-trades-front-desk' ), __( 'Customer Portal', 'titan-zero-trades-front-desk' ), 'tz_fd_access', 'tzfd-customer-portal-manager', array( __CLASS__, 'customer_portal_builder' ) );
    }
    /** Old admin.php?page= slugs (from plugin links and older bookmarks) mapped to the current slug. */
    public static function legacy_routes() {
        return array(
            'tzfd_settings' => self::SETTINGS,
            'tzfd_support'  => self::HELP,
            'tzfd_addons'   => self::ADDONS,
        );
    }

    /** Highlight the independent Front Desk menu on its own pages. */
    /**
     * The preset setup page is a hidden page under no parent. WordPress only finds titles for top-level or child pages,
     * so the title is set here; otherwise admin-header.php passes null to strip_tags() on every visit.
     */
    public static function hidden_setup_title() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu state.
        $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
        if ( self::SETUP !== $page ) { return; }
        global $title;
        $title = __( 'Preset Setup', 'titan-zero-trades-front-desk' );
    }

    /**
     * Hidden pages registered under parent '' cannot be titled by WordPress: get_admin_page_title() never
     * searches the submenu for an empty parent, so admin-header.php passes null to strip_tags(). The title is
     * supplied through the global $title, which get_admin_page_title() returns first. Slugs, capabilities
     * and visible titles are unchanged.
     */
    public static function hidden_page_titles() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu state.
        $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
        $titles = array(
            self::HELP                     => __( 'Help', 'titan-zero-trades-front-desk' ),
            self::PACKS                    => __( 'Industry packs', 'titan-zero-trades-front-desk' ),
            self::EMAILS                   => __( 'Titan Zero Front Desk Status Emails', 'titan-zero-trades-front-desk' ),
            'tzfd-customer-portal-manager' => __( 'Customer Portal', 'titan-zero-trades-front-desk' ),
        );
        if ( '' === $page || ! isset( $titles[ $page ] ) ) { return; }
        global $title;
        $title = $titles[ $page ];
    }

    public static function highlight_parent( $parent_file ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu state.
        $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
        if ( self::is_front_desk_slug( $page ) || 'tzfd-customer-portal-manager' === $page ) {
            return self::PARENT;
        }
        return $parent_file;
    }

    /** True for a Front Desk admin slug ('tzfd', 'tzfd-*', 'tz_fd_*'). Workspace and CRM slugs are not Front Desk's. */
    public static function is_front_desk_slug( $slug ) {
        $slug = (string) $slug;
        return self::CUSTOMERS === $slug || 0 === strpos( $slug, 'tzfd' ) || 0 === strpos( $slug, 'tz_fd_' );
    }

    /**
     * Sends old Front Desk routes to their current slug, keeping the other query arguments:
     * admin.php?page=tzfd_settings and similar, and options-general.php?page=tzfd (the removed Settings entry).
     */
    public static function redirect_legacy_routes() {
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value; the redirect changes no data.
        $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
        if ( '' === $page ) { return; }
        $map    = self::legacy_routes();
        $target = '';
        if ( 'options-general.php' === $pagenow && self::PARENT === $page ) {
            $target = self::PARENT;
        } elseif ( 'admin.php' === $pagenow && isset( $map[ $page ] ) ) {
            $target = $map[ $page ];
        }
        if ( '' === $target ) { return; }
        // Do not forward arbitrary request fields, nonces, tokens or untrusted URLs across admin redirects.
        // Only the read-only settings subtab is intentionally preserved for old bookmarks.
        $args = array( 'page' => $target );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only settings subtab on a redirect; no state change.
        if ( self::SETTINGS === $target && isset( $_GET['gotab'] ) && is_string( $_GET['gotab'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only settings subtab on a redirect; no state change.
            $tab = sanitize_key( wp_unslash( $_GET['gotab'] ) );
            if ( preg_match( '/^[a-z0-9_-]{1,40}$/D', $tab ) ) { $args['gotab'] = $tab; }
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Orders Front Desk’s native WordPress submenu. */
    public static function reorder_menu() {
        global $submenu;
        if ( empty( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) { return; }
        $position = array_flip( self::main_menu_order() );
        $items = array();
        $extension_items = array();
        foreach ( $submenu[ self::PARENT ] as $item ) {
            if ( isset( $item[2], $position[ $item[2] ] ) ) { $items[ $position[ $item[2] ] ] = $item; }
            else { $extension_items[] = $item; }
        }
        ksort( $items );
        $submenu[ self::PARENT ] = array_merge( array_values( $items ), $extension_items );
    }

    /** Native Front Desk page router; the primary slug opens Dashboard. */
    public static function route_front_desk() {
        // The top-level menu is registered with the Front Desk capability, so only its roles see it. Every route under this
        // slug (calendar, bookings, settings, addons, help) needs edit_pages, the same as the submenu screens.
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $plugin = self::plugin();
        if ( ! $plugin ) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
        $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
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
        $manage = isset( $_GET['manage'] ) ? sanitize_key( tzfd_request_text( 'get', 'manage' ) ) : '';
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
        global $tz_plugin;
        return ( is_object( $tz_plugin ) && method_exists( $tz_plugin, 'settings_page' ) ) ? $tz_plugin : null;
    }

    /** Apply the calendar's own user allow-list to service tools as well as its editor. */
    public static function can_manage_calendar( $calendar_id ) {
        $calendar_id = absint( $calendar_id );
        $plugin = self::plugin();
        return $calendar_id > 0 && $plugin && method_exists( $plugin, 'check_current_user_access' ) && $plugin->check_current_user_access( $calendar_id );
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
            array( 'key' => 'help',           'label' => __( 'Help', 'titan-zero-trades-front-desk' ),               'kind' => 'page', 'slug' => self::HELP,                         'admin' => false ),
            array( 'key' => 'emails',         'label' => __( 'Status update emails', 'titan-zero-trades-front-desk' ), 'kind' => 'page', 'slug' => self::EMAILS,                     'admin' => true ),
            array( 'key' => 'preview',        'label' => __( 'Scheduling Preview', 'titan-zero-trades-front-desk' ), 'kind' => 'page', 'slug' => self::PREVIEW,                      'admin' => true ),
            array( 'key' => 'presets',        'label' => __( 'Preset Setup', 'titan-zero-trades-front-desk' ),       'kind' => 'page', 'slug' => self::SETUP,                        'admin' => true ),
            array( 'key' => 'packs',          'label' => __( 'Industry packs', 'titan-zero-trades-front-desk' ),     'kind' => 'page', 'slug' => self::PACKS,                        'admin' => true ),
        );
    }

    /** URL of the Settings page, optionally at one section (for example settings#csv). */
    /**
     * Prints the Settings tab bar. $current is the active tab key. Every tab is a link to the
     * Settings page at its hash, or to its own admin page.
     */
    public static function render_settings_tabs( $current ) {
        $is_admin = current_user_can( 'tz_fd_access' );
        echo '<nav class="nav-tab-wrapper tzfd-tab-wrapper tzfd-settings-tabs" aria-label="' . esc_attr__( 'Settings tabs', 'titan-zero-trades-front-desk' ) . '">';
        foreach ( self::settings_tabs() as $tab ) {
            if ( $tab['admin'] && ! $is_admin ) { continue; }
            $class = 'nav-tab' . ( $tab['key'] === $current ? ' nav-tab-active' : '' );
            if ( 'tab' === $tab['kind'] ) {
                $url = admin_url( 'admin.php?page=' . self::SETTINGS . '#' . $tab['hash'] );
                $channel = class_exists( 'TZFD_UI_Graphics' ) ? ( array( 'ai' => 'ai', 'reports' => 'email' )[ $tab['key'] ] ?? '' ) : '';
                echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . ( '' !== $channel ? wp_kses( TZFD_UI_Graphics::icon_html( TZFD_UI_Graphics::channel_icon( $channel ), 24 ), TZFD_UI_Graphics::allowed_html() ) : '' ) . esc_html( $tab['label'] ) . '</a>';
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
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
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
        $can_set = current_user_can( 'tz_fd_access' );
        $ready   = $done_n >= $total;

        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-dashboard">';
        TZFD_Admin_Layout::header( __( 'Dashboard', 'titan-zero-trades-front-desk' ), __( 'At a glance across enquiries and bookings.', 'titan-zero-trades-front-desk' ), '', 'dashboard' );
        if ( empty( $head['enquiries_available'] ) || empty( $head['upcoming_available'] ) || empty( $figures['bookings_available'] ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some dashboard figures could not be loaded. A dash or unavailable message means the database read failed; check the related screen before relying on a zero.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }

        echo TZFD_Dashboard_Figures::truncation_note( ! empty( $figures['truncated'] ) || ! empty( $head['truncated'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_html() in truncation_note().

        // Welcome and set-up progress.
        echo '<div class="tzfd-card tzfd-welcome"><div class="tzfd-welcome-main">';
        /* translators: %s: first name of the signed-in user. */
        echo '<h2>' . esc_html( sprintf( __( 'Welcome back, %s', 'titan-zero-trades-front-desk' ), $first ) ) . '</h2>';
        echo '<p class="tzfd-muted">' . esc_html( $ready ? __( 'Front Desk is set up. The figures below show what needs you today.', 'titan-zero-trades-front-desk' ) : __( 'Front Desk is ready. Finish setup to open bookings.', 'titan-zero-trades-front-desk' ) ) . '</p>';
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

        // Headline tiles.
        $week_url = TZFD_Booking_View::url( array( 'view' => 'week', 'date' => $today ) );
        echo '<div class="tzfd-stats" role="list">';
        TZFD_Admin_Layout::stat( ! empty( $head['enquiries_available'] ) ? (string) $head['enquiries'] : '—', __( 'New enquiries', 'titan-zero-trades-front-desk' ), __( 'today', 'titan-zero-trades-front-desk' ), 'red' );
        TZFD_Admin_Layout::stat( ! empty( $head['upcoming_available'] ) ? (string) $head['upcoming'] : '—', __( 'Upcoming bookings', 'titan-zero-trades-front-desk' ), __( 'next 7 days', 'titan-zero-trades-front-desk' ), 'blue', $week_url );
        TZFD_Admin_Layout::stat( ! empty( $head['attention_available'] ) ? (string) $head['attention'] : '—', __( 'Needs attention', 'titan-zero-trades-front-desk' ), __( 'awaiting approval', 'titan-zero-trades-front-desk' ), 'green', admin_url( 'admin.php?page=' . self::BOOKINGS ) );
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
            echo '<tr><td colspan="4" class="tzfd-muted">' . esc_html( empty( $upcoming['available'] ) || empty( $upcoming['calendar_names_available'] ) ? __( 'Bookings could not be loaded. Check the database and reload.', 'titan-zero-trades-front-desk' ) : __( 'No bookings in the next 7 days.', 'titan-zero-trades-front-desk' ) ) . '</td></tr>';
        }
        foreach ( array_slice( $rows, 0, 6 ) as $item ) {
            $when = mysql2date( 'D j M', $item['date'] ) . ( '' !== $item['start'] ? ', ' . $item['start'] : '' );
            echo '<tr><td><strong>' . esc_html( $item['customer'] ) . '</strong></td><td>' . ( class_exists( 'TZFD_UI_Graphics' ) ? wp_kses( TZFD_UI_Graphics::icon_html( TZFD_UI_Graphics::service_icon( $item['service'] ), 24 ), TZFD_UI_Graphics::allowed_html() ) : '' ) . esc_html( $item['service'] ) . '</td><td>' . esc_html( $when ) . '</td>';
            echo '<td><span class="tzfd-badge tzfd-badge-' . esc_attr( $item['status_class'] ) . '">' . esc_html( $item['status'] ) . '</span></td></tr>';
        }
        echo '</tbody></table></div></div>';

        // Right column: activity, then quick actions.
        echo '<div class="tzfd-side-col">';
        echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2>' . esc_html__( 'Front desk activity', 'titan-zero-trades-front-desk' ) . '</h2><span class="tzfd-live">' . esc_html__( 'LIVE', 'titan-zero-trades-front-desk' ) . '</span></div>';
        echo '<ul class="tzfd-activity">';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables; values are bound with prepare().
        $recent_company = TZFD_Company_Read_Context::id();
        $recent = $recent_company > 0 ? $wpdb->get_results( $wpdb->prepare(
            'SELECT id, time, posted_data FROM %i WHERE company_id = %d ORDER BY id DESC LIMIT 4',
            $wpdb->prefix . TZFD_Bookings::TABLE, $recent_company
        ) ) : array();
        $recent_available = $recent_company > 0 && is_array( $recent ) && empty( $wpdb->last_error );
        if ( ! $recent_available ) { $recent = array(); }
        foreach ( $recent as $row ) {
            $data = TZFD_Stored_Data::decode_booking( (string) $row->posted_data );
            $name = is_array( $data ) ? TZFD_Booking_View::customer_name( $data ) : '';
            $name = '' !== $name ? $name : __( 'a customer', 'titan-zero-trades-front-desk' );
            /* translators: %s: customer name. */
            echo '<li><strong>' . esc_html__( 'New enquiry received', 'titan-zero-trades-front-desk' ) . '</strong><span>' . esc_html( sprintf( __( 'Booking from %s', 'titan-zero-trades-front-desk' ), $name ) ) . '</span><small>' . esc_html( mysql2date( 'j M, H:i', (string) $row->time ) ) . '</small></li>';
        }
        if ( empty( $recent ) && ! $recent_available ) {
            echo '<li class="tzfd-muted">' . esc_html__( 'Booking activity is unavailable. Check the database and reload this page.', 'titan-zero-trades-front-desk' ) . '</li>';
        } elseif ( empty( $recent ) ) {
            echo '<li class="tzfd-muted">' . esc_html__( 'Nothing yet. New bookings appear here.', 'titan-zero-trades-front-desk' ) . '</li>';
        }
        echo '</ul></div>';

        $add_url = self::add_booking_url();
        echo '<div class="tzfd-card"><h2>' . esc_html__( 'Quick actions', 'titan-zero-trades-front-desk' ) . '</h2>';
        $booked_today = ! empty( $figures['bookings_available'] ) ? TZFD_Local_Money::format( $figures['booked'] ) : '—';
        echo '<p class="tzfd-muted">' . esc_html( sprintf( /* translators: %s: amount booked today. */ __( 'Today: %s booked', 'titan-zero-trades-front-desk' ), $booked_today ) ) . '</p>';
        echo '<div class="tzfd-quick"><a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( $add_url ) . '">+ ' . esc_html__( 'Add booking', 'titan-zero-trades-front-desk' ) . '</a></div>';
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
        // Never link to another company's calendar or guess a default when no company is selected.
        $company_id = TZFD_Scoped_Store::company_id();
        $calendar_id = $company_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE company_id = %d ORDER BY id LIMIT 1', $wpdb->prefix . TZFD_Tables::FORMS, $company_id ) ) : 0;
        return $calendar_id > 0 ? admin_url( 'admin.php?page=' . self::PARENT . '&cal=' . $calendar_id . '&addbk=1' ) : admin_url( 'admin.php?page=' . self::CALENDAR );
    }

    /**
     * Bookings across every calendar: tabs (all, upcoming, needs approval, completed), a filter bar,
     * the stat tiles from the mock-up, and the booking table. Each row opens its calendar's booking list.
     */
    public static function page_bookings() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;
        $today = TZFD_Booking_View::today();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $tab     = is_string( $_GET['tab'] ?? null ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $range   = is_string( $_GET['range'] ?? null ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'next30';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $service = sanitize_text_field( wp_unslash( $_GET['service'] ?? '' ) );

        $from = TZFD_Booking_View::add_days( $today, -60 );
        $to   = TZFD_Booking_View::add_days( $today, 60 );
        $found = TZFD_Booking_View::fetch( $from, $to );
        $items = $found['items'];
        $booking_data_error = empty( $found['available'] ) || empty( $found['calendar_names_available'] );

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
            '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=tzfd-booking-portal-builder' ) ) . '">' . esc_html__( 'Create & manage booking portal', 'titan-zero-trades-front-desk' ) . '</a> <a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( self::add_booking_url() ) . '">+ ' . esc_html__( 'New booking', 'titan-zero-trades-front-desk' ) . '</a>', 'bookings' );

        if ( $booking_data_error ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Booking data could not be loaded. Check the database before relying on these lists or totals.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }

        if ( ! $booking_data_error && empty( $service_names ) && empty( $items ) ) {
            echo '<div class="tzfd-card">';
            if ( class_exists( 'TZFD_Local_Brand' ) && method_exists( 'TZFD_Local_Brand', 'empty_state' ) ) { TZFD_Local_Brand::empty_state( 'bookings', __( 'No bookings yet. Create a calendar, and its bookings will appear here.', 'titan-zero-trades-front-desk' ) ); }
            else { echo '<p class="tzfd-muted">' . esc_html__( 'No bookings yet. Create a calendar, and its bookings will appear here.', 'titan-zero-trades-front-desk' ) . '</p>'; }
            echo '</div></div>';
            return;
        }

        echo '<div class="tzfd-stats" role="list">';
        TZFD_Admin_Layout::stat( ! $booking_data_error ? (string) $upcoming_week : '—', __( 'Upcoming', 'titan-zero-trades-front-desk' ), __( 'next 7 days', 'titan-zero-trades-front-desk' ), 'blue', $base . '&tab=upcoming' );
        TZFD_Admin_Layout::stat( ! $booking_data_error ? (string) $needs : '—', __( 'Needs approval', 'titan-zero-trades-front-desk' ), __( 'requests', 'titan-zero-trades-front-desk' ), 'amber', $base . '&tab=approval' );
        TZFD_Admin_Layout::stat( ! $booking_data_error ? (string) $completed_month : '—', __( 'Completed', 'titan-zero-trades-front-desk' ), __( 'this month', 'titan-zero-trades-front-desk' ), 'green', $base . '&tab=completed' );
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
        echo wp_kses( TZFD_UI_Graphics::brand_html( 'search', 24 ), TZFD_UI_Graphics::allowed_html() ) . '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search customer or booking ID', 'titan-zero-trades-front-desk' ) . '" aria-label="' . esc_attr__( 'Search customer or booking ID', 'titan-zero-trades-front-desk' ) . '" />';
        echo '<label class="screen-reader-text" for="tz_fd_range">' . esc_html__( 'Date range', 'titan-zero-trades-front-desk' ) . '</label><select id="tz_fd_range" name="range">';
        $ranges = array( 'next7' => __( 'Next 7 days', 'titan-zero-trades-front-desk' ), 'next30' => __( 'Next 30 days', 'titan-zero-trades-front-desk' ), 'past30' => __( 'Past 30 days', 'titan-zero-trades-front-desk' ), 'all' => __( 'All dates', 'titan-zero-trades-front-desk' ) );
        foreach ( $ranges as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $range, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select>';
        echo '<label class="screen-reader-text" for="tz_fd_service">' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</label><select id="tz_fd_service" name="service"><option value="">' . esc_html__( 'All services', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $service_names as $name ) { echo '<option value="' . esc_attr( $name ) . '"' . selected( $service, $name, false ) . '>' . esc_html( $name ) . '</option>'; }
        echo '</select>';
        echo '<button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Filter', 'titan-zero-trades-front-desk' ) . '</button>';
        echo '</form>';

        echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Booking', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Date and time', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</th><th></th>';
        echo '</tr></thead><tbody>';
        if ( empty( $list ) ) {
            echo '<tr><td colspan="9" class="tzfd-muted">' . esc_html( $booking_data_error ? __( 'Booking records are unavailable. Check the database and reload this page.', 'titan-zero-trades-front-desk' ) : __( 'No bookings match these filters.', 'titan-zero-trades-front-desk' ) ) . '</td></tr>';
        }
        foreach ( array_slice( $list, 0, 100 ) as $item ) {
            $when = mysql2date( 'D j M Y', $item['date'] ) . ( '' !== $item['start'] ? ', ' . $item['start'] : '' );
            $open = admin_url( 'admin.php?page=' . self::PARENT . '&cal=' . (int) $item['form_id'] . '&list=1' );
            echo '<tr><td>#' . (int) $item['booking_id'] . '</td><td><strong>' . esc_html( $item['customer'] ) . '</strong></td><td>' . ( class_exists( 'TZFD_UI_Graphics' ) ? wp_kses( TZFD_UI_Graphics::icon_html( TZFD_UI_Graphics::service_icon( $item['service'] ), 24 ), TZFD_UI_Graphics::allowed_html() ) : '' ) . esc_html( $item['service'] ) . '</td>';
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

    /** Appointment fields available as direct targets for the New Service form. */
    public static function service_fields_in( $structure ) {
        $decoded = json_decode( (string) $structure );
        if ( ! is_array( $decoded ) ) { $decoded = json_decode( stripslashes( (string) $structure ) ); }
        $out = array();
        if ( is_array( $decoded ) && isset( $decoded[0] ) ) { self::collect_service_fields( $decoded[0], $out ); }
        return $out;
    }

    private static function collect_service_fields( $node, array &$out ) {
        if ( is_object( $node ) ) {
            if ( isset( $node->ftype, $node->name ) && 'fapp' === $node->ftype && is_string( $node->name ) && '' !== $node->name ) {
                $out[] = array( 'name' => $node->name, 'title' => isset( $node->title ) && is_string( $node->title ) ? $node->title : '' );
                return;
            }
            foreach ( $node as $child ) { if ( is_array( $child ) || is_object( $child ) ) { self::collect_service_fields( $child, $out ); } }
        } elseif ( is_array( $node ) ) {
            foreach ( $node as $child ) { if ( is_array( $child ) || is_object( $child ) ) { self::collect_service_fields( $child, $out ); } }
        }
    }

    /** Append one service to the selected appointment field without replacing sibling form data. */
    private static function append_service_to_field( &$node, $field_name, array $service ) {
        if ( is_object( $node ) ) {
            if ( isset( $node->ftype, $node->name ) && 'fapp' === $node->ftype && (string) $node->name === $field_name ) {
                if ( ! isset( $node->services ) || ! is_array( $node->services ) ) { return 'target'; }
                if ( count( $node->services ) >= 200 ) { return 'limit'; }
                $used_ids = array();
                $max_id = 0;
                foreach ( $node->services as $existing ) {
                    if ( ! is_object( $existing ) ) { continue; }
                    if ( isset( $existing->name ) && is_string( $existing->name ) && 0 === strcasecmp( trim( $existing->name ), $service['name'] ) ) { return 'duplicate'; }
                    if ( isset( $existing->idx ) && is_scalar( $existing->idx ) ) {
                        $existing_id = (string) $existing->idx;
                        $used_ids[ $existing_id ] = true;
                        if ( ctype_digit( $existing_id ) ) { $max_id = max( $max_id, (int) $existing_id ); }
                    }
                }
                if ( $max_id >= PHP_INT_MAX - 1 ) { return 'limit'; }
                $new_id = $max_id + 1;
                while ( isset( $used_ids[ (string) $new_id ] ) && $new_id < PHP_INT_MAX ) { $new_id++; }
                if ( $new_id < 1 || $new_id >= PHP_INT_MAX ) { return 'limit'; }
                $service['idx'] = $new_id;
                $node->services[] = (object) $service;
                return 'created';
            }
            foreach ( $node as $key => $child ) {
                if ( is_array( $child ) || is_object( $child ) ) {
                    $result = self::append_service_to_field( $node->$key, $field_name, $service );
                    if ( 'target' !== $result ) { return $result; }
                }
            }
        } elseif ( is_array( $node ) ) {
            foreach ( $node as $key => $child ) {
                if ( is_array( $child ) || is_object( $child ) ) {
                    $result = self::append_service_to_field( $node[ $key ], $field_name, $service );
                    if ( 'target' !== $result ) { return $result; }
                }
            }
        }
        return 'target';
    }

    private static function service_integer( $value, $minimum, $maximum ) {
        if ( is_int( $value ) ) { $number = $value; }
        elseif ( is_string( $value ) && preg_match( '/\A[0-9]{1,10}\z/D', $value ) ) { $number = (int) $value; }
        else { return null; }
        return $number >= $minimum && $number <= $maximum ? $number : null;
    }

    /** Admin-post handler: creates a calendar service under the shared calendar lock. */
    public static function create_service() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 40, 16384 );
        check_admin_referer( 'tz_fd_service_create', 'tz_fd_service_create_nonce' );
        $target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
        $name = TZFD_Legacy_Compat::substr( trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ), 0, 120 );
        $price_raw = isset( $_POST['price'] ) && is_scalar( $_POST['price'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['price'] ) ) : '';
        if ( ! preg_match( '/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', $price_raw ) ) { self::service_redirect( array( 'error' => 'fields' ) ); }
        $price = TZFD_Local_Money::parse_amount( $price_raw );
        $duration_input = isset( $_POST['duration'] ) && is_scalar( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['duration'] ) ) : null;
        $capacity_input = isset( $_POST['capacity'] ) && is_scalar( $_POST['capacity'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['capacity'] ) ) : null;
        $duration = self::service_integer( $duration_input, 1, 1440 );
        $capacity = self::service_integer( $capacity_input, 1, 1000000 );
        if ( '' === $name || null === $price || ! is_finite( (float) $price ) || $price < 0 || $price > 9999999999.99 || null === $duration || null === $capacity ) {
            self::service_redirect( array( 'error' => 'fields' ) );
        }
        if ( ! preg_match( '/\A([1-9][0-9]{0,9}):([0-9a-f]+)\z/D', $target, $matches ) || 0 !== strlen( $matches[2] ) % 2 ) {
            self::service_redirect( array( 'error' => 'target' ) );
        }
        $calendar_id = (int) $matches[1];
        $field_name = hex2bin( $matches[2] );
        if ( ! is_string( $field_name ) || '' === $field_name || ! preg_match( '//u', $field_name ) ) { self::service_redirect( array( 'error' => 'target' ) ); }
        if ( ! self::can_manage_calendar( $calendar_id ) ) { wp_die( esc_html__( 'You do not have permission to manage services in this calendar.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // The service is written to the local catalogue first. The calendar copy is the booking
        // form's configuration and is written second. If the calendar write fails, the catalogue row is archived.
        $catalog_id = TZFD_Shared_Store::create_service( array(
            'name'             => $name,
            'description'      => '',
            'duration_minutes' => $duration,
            'price'            => number_format( $price, 2, '.', '' ),
            'gst_applies'      => 0,
        ) );
        if ( false === $catalog_id ) { self::service_redirect( array( 'error' => 'database' ) ); }
        global $wpdb;
        $table = $wpdb->prefix . TZFD_Tables::FORMS;
        $lock_name = 'tz_fd_svc_' . md5( $table . ':' . $calendar_id );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct $wpdb access to plugin-owned state is required; object caching would serve stale records or bypass lock semantics.
        $locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
        if ( ! $locked ) {
            // Workspace catalogue row was created before the calendar lock: compensate on failure.
            TZFD_Shared_Store::archive_service( $catalog_id );
            self::service_redirect( array( 'error' => 'busy' ) );
        }
        $result = 'target';
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct $wpdb access to plugin-owned state is required; object caching would serve stale records or bypass lock semantics.
            // Company isolation: a calendar is read only inside the request's company. No company means no row.
            $company  = TZFD_Scoped_Store::company_id();
            $calendar = $company > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT form_structure FROM %i WHERE id = %d AND company_id = %d', $table, $calendar_id, $company ) ) : null;
            if ( ! $calendar && ! empty( $wpdb->last_error ) ) { $result = 'database'; }
            if ( $calendar ) {
                $structure = json_decode( (string) $calendar->form_structure );
                if ( ! is_array( $structure ) ) { $structure = json_decode( stripslashes( (string) $calendar->form_structure ) ); }
                $matching_targets = array_filter( self::service_fields_in( (string) $calendar->form_structure ), static function ( $field ) use ( $field_name ) { return $field_name === $field['name']; } );
                if ( 1 === count( $matching_targets ) && is_array( $structure ) && isset( $structure[0] ) ) {
                    $service = array(
                        'name' => $name,
                        'price' => number_format( $price, 2, '.', '' ),
                        'duration' => $duration,
                        'capacity' => $capacity,
                        'pb' => 0,
                        'pa' => 0,
                        'ohindex' => 0,
                    );
                    $result = self::append_service_to_field( $structure[0], $field_name, $service );
                    if ( 'created' === $result ) {
                        $encoded = wp_json_encode( $structure );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct $wpdb access to plugin-owned state is required; object caching would serve stale records or bypass lock semantics.
                        $updated = is_string( $encoded ) ? $wpdb->query( $wpdb->prepare(
                            'UPDATE %i SET form_structure = %s WHERE id = %d AND company_id = %d AND BINARY form_structure = BINARY %s',
                            $table, $encoded, $calendar_id, $company, (string) $calendar->form_structure
                        ) ) : false;
                        if ( false === $updated ) { $result = 'database'; }
                        elseif ( 0 === $updated ) { $result = 'changed'; }
                    }
                }
            }
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct $wpdb access to plugin-owned state is required; object caching would serve stale records or bypass lock semantics.
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
        if ( 'created' === $result ) {
            TZFD_Service_Details::link_catalog( $calendar_id, $name, $catalog_id );
        } else {
            TZFD_Shared_Store::archive_service( $catalog_id );
        }
        self::service_redirect( 'created' === $result ? array( 'created' => 1 ) : array( 'error' => $result ) );
    }

    private static function service_redirect( $args ) {
        wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SERVICES ), $args ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function page_services() {
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $saved     = isset( $_GET['saved'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $failed    = isset( $_GET['error'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only id; the strict numeric TZFD_Request_Value::id() validator is the check.
        $edit_cal  = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_GET['cal'] ?? '' ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $edit_name = sanitize_text_field( wp_unslash( $_GET['svc'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter; no state change.
        $search    = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only id; the strict numeric TZFD_Request_Value::id() validator is the check.
        $filter_cal = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_GET['filter_cal'] ?? '' ) ) );

        echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-services">';
        TZFD_Admin_Layout::header( __( 'Services', 'titan-zero-trades-front-desk' ), __( 'Set up the services customers can request and your team can deliver.', 'titan-zero-trades-front-desk' ),
            '<a class="tzfd-btn tzfd-btn-primary" href="#tzfd-service-create">+ ' . esc_html__( 'New service', 'titan-zero-trades-front-desk' ) . '</a> <a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::CALENDAR . '&manage=1' ) ) . '">' . esc_html__( 'Manage calendars', 'titan-zero-trades-front-desk' ) . '</a>', 'services' );
        TZFD_Shared_Store::render_panel();
        if ( $saved ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Service address saved.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notices after checked admin-post actions.
        if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Service updated in the catalogue and the calendar.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice.
        if ( isset( $_GET['archived'] ) ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Service archived. It can no longer be booked.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notices after a checked admin-post action.
        $service_error = isset( $_GET['error'] ) ? sanitize_key( tzfd_request_text( 'get', 'error' ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice; the service creation handler checks its POST nonce and capability.
        if ( isset( $_GET['created'] ) ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Service created in the selected calendar. You can edit its hours and other calendar settings there.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( $failed ) {
            $messages = array(
                'fields' => __( 'Enter a service name, valid price, duration and capacity.', 'titan-zero-trades-front-desk' ),
                'busy' => __( 'This calendar is being updated. Wait a moment and retry.', 'titan-zero-trades-front-desk' ),
                'duplicate' => __( 'That appointment field already has a service with this name.', 'titan-zero-trades-front-desk' ),
                'rename' => __( 'Another service in this calendar already has a saved address under that name. Choose a different name.', 'titan-zero-trades-front-desk' ),
                'limit' => __( 'The selected appointment field has reached its service limit.', 'titan-zero-trades-front-desk' ),
                'database' => __( 'The service could not be saved. Reload the page and check the calendar.', 'titan-zero-trades-front-desk' ),
                'changed' => __( 'The calendar changed while this service was being created. Reload the page and try again.', 'titan-zero-trades-front-desk' ),
                'target' => __( 'Choose a current calendar appointment field and try again.', 'titan-zero-trades-front-desk' ),
                'stale' => __( 'The calendar changed while this service was being saved. Reload the page and try again.', 'titan-zero-trades-front-desk' ),
                'catalog' => __( 'The calendar was updated, but the service catalogue could not be archived. Contact an administrator to reconcile the records.', 'titan-zero-trades-front-desk' ),
            );
            echo '<div class="notice notice-error inline"><p>' . esc_html( $messages[ $service_error ] ?? __( 'That service or calendar was not found. Reload the page and try again.', 'titan-zero-trades-front-desk' ) ) . '</p></div>';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the name is bound with prepare().
        $company_id = TZFD_Scoped_Store::company_id();
        $calendars = $company_id > 0 ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, form_name, form_structure FROM %i WHERE company_id = %d ORDER BY form_name', $wpdb->prefix . TZFD_Tables::FORMS, $company_id ) ) : array();
        $service_data_available = is_array( $calendars ) && empty( $wpdb->last_error );
        if ( ! $service_data_available ) { $calendars = array(); }
        else { $calendars = array_values( array_filter( $calendars, static function ( $calendar ) { return self::can_manage_calendar( (int) $calendar->id ); } ) ); }
        if ( ! $service_data_available ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Calendar and service records could not be loaded. Check the database and reload this page before relying on an empty catalog.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
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

        $service_targets = array();
        foreach ( (array) $calendars as $calendar ) {
            $calendar_id = (int) $calendar->id;
            $calendar_label = '' !== (string) $calendar->form_name ? (string) $calendar->form_name : sprintf( /* translators: %d: calendar ID. */ __( 'Calendar %d', 'titan-zero-trades-front-desk' ), $calendar_id );
            foreach ( self::service_fields_in( (string) $calendar->form_structure ) as $field ) {
                $target = $calendar_id . ':' . bin2hex( $field['name'] );
                $service_targets[ $target ] = $calendar_label . ' — ' . ( '' !== $field['title'] ? $field['title'] : $field['name'] );
            }
        }

        echo '<div id="tzfd-service-create" class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html__( 'Add a service', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p class="tzfd-muted">' . esc_html__( 'Choose an appointment field. The new service is added to that calendar with its price, duration and capacity.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( $service_data_available && $service_targets ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tz_fd_service_create" />';
            wp_nonce_field( 'tz_fd_service_create', 'tz_fd_service_create_nonce' );
            echo '<div class="tzfd-form-grid"><div class="tzfd-full"><label for="tz_fd_service_target">' . esc_html__( 'Calendar and appointment field', 'titan-zero-trades-front-desk' ) . '</label><select id="tz_fd_service_target" name="target" required><option value="">' . esc_html__( 'Choose a calendar', 'titan-zero-trades-front-desk' ) . '</option>';
            foreach ( $service_targets as $target => $label ) { echo '<option value="' . esc_attr( $target ) . '">' . esc_html( $label ) . '</option>'; }
            echo '</select></div>';
            echo '<div><label for="tz_fd_service_new_name">' . esc_html__( 'Service name', 'titan-zero-trades-front-desk' ) . '</label><input id="tz_fd_service_new_name" name="name" type="text" maxlength="120" required /></div>';
            echo '<div><label for="tz_fd_service_new_price">' . esc_html__( 'Price', 'titan-zero-trades-front-desk' ) . ' (' . esc_html( TZFD_Local_Money::currency_code() ) . ')</label><input id="tz_fd_service_new_price" name="price" type="number" min="0" max="9999999999.99" step="0.01" value="0.00" required /></div>';
            echo '<div><label for="tz_fd_service_new_duration">' . esc_html__( 'Duration (minutes)', 'titan-zero-trades-front-desk' ) . '</label><input id="tz_fd_service_new_duration" name="duration" type="number" min="1" max="1440" value="60" required /></div>';
            echo '<div><label for="tz_fd_service_new_capacity">' . esc_html__( 'Capacity', 'titan-zero-trades-front-desk' ) . '</label><input id="tz_fd_service_new_capacity" name="capacity" type="number" min="1" max="1000000" value="1" required /></div></div>';
            echo '<div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Create service', 'titan-zero-trades-front-desk' ) . '</button></div></form>';
        } elseif ( ! $service_data_available ) {
            echo '<p class="tzfd-muted">' . esc_html__( 'Service creation is unavailable until calendar records can be loaded.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            echo '<p class="tzfd-muted">' . esc_html__( 'Create a calendar with an appointment field first, then add services here.', 'titan-zero-trades-front-desk' ) . '</p>';
        }
        echo '</div>';

        if ( $edit_cal > 0 && '' !== $edit_name ) {
            foreach ( $rows as $row ) {
                if ( $row['calendar'] === $edit_cal && $row['name'] === $edit_name ) { self::service_form( $row, $locations ); break; }
            }
        }

        // Catalog card: how many services are live, and where to manage calendars.
        echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Use Add a service to create a service in an appointment field. Manage calendars for opening hours and other calendar rules. This screen also edits addresses and locations for existing services.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<div class="tzfd-card tzfd-foot-card"><div><h2 class="tzfd-card-title">' . esc_html__( 'Your service catalog', 'titan-zero-trades-front-desk' ) . ' <span class="tzfd-badge tzfd-badge-active">' . esc_html( $service_data_available ? sprintf( /* translators: %d: number of active services. */ __( '%d ACTIVE', 'titan-zero-trades-front-desk' ), count( $rows ) ) : __( 'UNAVAILABLE', 'titan-zero-trades-front-desk' ) ) . '</span></h2>';
        echo '<p>' . esc_html__( 'Services are linked to calendars, durations, prices and addresses.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::CALENDAR . '&manage=1' ) ) . '">' . esc_html__( 'Manage calendars', 'titan-zero-trades-front-desk' ) . '</a></div>';

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
        echo wp_kses( TZFD_UI_Graphics::brand_html( 'search', 24 ), TZFD_UI_Graphics::allowed_html() ) . '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search services', 'titan-zero-trades-front-desk' ) . '" aria-label="' . esc_attr__( 'Search services', 'titan-zero-trades-front-desk' ) . '" />';
        echo '<label class="screen-reader-text" for="tz_fd_filter_cal">' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</label><select id="tz_fd_filter_cal" name="filter_cal"><option value="0">' . esc_html__( 'All calendars', 'titan-zero-trades-front-desk' ) . '</option>';
        foreach ( $calendar_options as $id => $label ) { echo '<option value="' . (int) $id . '"' . selected( $filter_cal, $id, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select><button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Filter', 'titan-zero-trades-front-desk' ) . '</button></form>';

        echo '<div class="tzfd-table-wrap"><table class="widefat tzfd-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Location', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Duration', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Price', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Capacity', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Visibility', 'titan-zero-trades-front-desk' ) . '</th><th></th>';
        echo '</tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="9">';
            if ( ! $service_data_available ) {
                echo '<p class="tzfd-muted">' . esc_html__( 'Service records are unavailable. Check the database and reload this page.', 'titan-zero-trades-front-desk' ) . '</p>';
            } else {
                if ( class_exists( 'TZFD_Local_Brand' ) && method_exists( 'TZFD_Local_Brand', 'empty_state' ) ) { TZFD_Local_Brand::empty_state( 'services', __( 'No services yet. Add them in a calendar, or use Preset Setup.', 'titan-zero-trades-front-desk' ) ); }
                else { echo '<p class="tzfd-muted">' . esc_html__( 'No services yet. Add them in a calendar, or use Preset Setup.', 'titan-zero-trades-front-desk' ) . '</p>'; }
            }
            echo '</td></tr>';
        } elseif ( empty( $shown ) ) {
            echo '<tr><td colspan="7" class="tzfd-muted">' . esc_html__( 'No services match these filters.', 'titan-zero-trades-front-desk' ) . '</td></tr>';
        }
        foreach ( $shown as $row ) {
            $address_url = admin_url( 'admin.php?page=' . self::SERVICES . '&cal=' . (int) $row['calendar'] . '&svc=' . rawurlencode( $row['name'] ) );
            $duration    = is_numeric( $row['duration'] ) ? sprintf( /* translators: %d: minutes. */ __( '%d min', 'titan-zero-trades-front-desk' ), (int) $row['duration'] ) : (string) $row['duration'];
            $price       = is_numeric( $row['price'] ) ? TZFD_Local_Money::format( (float) $row['price'] ) : (string) $row['price'];
            $where = isset( $locations[ $row['location_id'] ] ) ? $locations[ $row['location_id'] ] : '';
            $icon = class_exists( 'TZFD_UI_Graphics' ) ? TZFD_UI_Graphics::service_icon( $row['name'] ) : '';
            echo '<tr><td><div class="tzfd-service-identity">';
            if ( '' !== $icon ) { echo wp_kses( TZFD_UI_Graphics::icon_html( $icon, 40 ), TZFD_UI_Graphics::allowed_html() ); }
            echo '<strong>' . esc_html( $row['name'] ) . '</strong></div></td><td>' . esc_html( $row['calendar_label'] ) . '</td>';
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
        echo '<div class="tzfd-page-actions"><a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::CALENDAR . '&manage=1' ) ) . '">+ ' . esc_html__( 'Manage calendars and services', 'titan-zero-trades-front-desk' ) . '</a></div></div>';
        echo '</div>';
    }

    /** Address and location editor for one service, shown above the Services table. */
    private static function service_form( $row, $locations ) {
        $details = TZFD_Service_Details::get( $row['calendar'], $row['name'] );
        // Edit: name, price, duration and capacity. The Workspace catalogue is updated first, then the calendar copy.
        echo '<div class="tzfd-card">';
        if ( class_exists( 'TZFD_UI_Graphics' ) ) { echo wp_kses( TZFD_UI_Graphics::picture_html( TZFD_UI_Graphics::service_picture( $row['name'] ), 160 ), TZFD_UI_Graphics::allowed_html() ); }
        echo '<h2 class="tzfd-section-title">' . esc_html__( 'Edit service', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tz_fd_service_update" />';
        echo '<input type="hidden" name="calendar" value="' . (int) $row['calendar'] . '" /><input type="hidden" name="service" value="' . esc_attr( $row['name'] ) . '" />';
        wp_nonce_field( 'tz_fd_service_update', 'tz_fd_service_update_nonce' );
        echo '<div class="tzfd-form-grid"><div><label for="tz_fd_edit_name">' . esc_html__( 'Service name', 'titan-zero-trades-front-desk' ) . '</label><input id="tz_fd_edit_name" name="name" type="text" maxlength="120" required value="' . esc_attr( $row['name'] ) . '" /></div>';
        echo '<div><label for="tz_fd_edit_price">' . esc_html__( 'Price', 'titan-zero-trades-front-desk' ) . '</label><input id="tz_fd_edit_price" name="price" type="text" inputmode="decimal" required value="' . esc_attr( (string) $row['price'] ) . '" /></div>';
        echo '<div><label for="tz_fd_edit_duration">' . esc_html__( 'Duration (minutes)', 'titan-zero-trades-front-desk' ) . '</label><input id="tz_fd_edit_duration" name="duration" type="number" min="1" max="1440" required value="' . esc_attr( (string) $row['duration'] ) . '" /></div>';
        echo '<div><label for="tz_fd_edit_capacity">' . esc_html__( 'Capacity', 'titan-zero-trades-front-desk' ) . '</label><input id="tz_fd_edit_capacity" name="capacity" type="number" min="1" required value="' . esc_attr( (string) $row['capacity'] ) . '" /></div></div>';
        echo '<div class="tzfd-actions"><button type="submit" class="tzfd-btn tzfd-btn-primary">' . esc_html__( 'Save service', 'titan-zero-trades-front-desk' ) . '</button></div></form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-tzfd-confirm-archive="' . esc_attr__( 'Archive this service? Customers will no longer be able to book it. Past bookings keep their details.', 'titan-zero-trades-front-desk' ) . '">';
        echo '<input type="hidden" name="action" value="tz_fd_service_archive" /><input type="hidden" name="calendar" value="' . (int) $row['calendar'] . '" /><input type="hidden" name="service" value="' . esc_attr( $row['name'] ) . '" />';
        wp_nonce_field( 'tz_fd_service_archive', 'tz_fd_service_archive_nonce' );
        echo '<p><button type="submit" class="tzfd-btn tzfd-btn-secondary">' . esc_html__( 'Archive service', 'titan-zero-trades-front-desk' ) . '</button></p></form></div>';
        /* translators: %s: service name. */
        echo '<div class="tzfd-card"><h2 class="tzfd-section-title">' . esc_html( sprintf( __( 'Address for %s', 'titan-zero-trades-front-desk' ), $row['name'] ) ) . '</h2>';
        echo '<p class="tzfd-muted">' . esc_html( $row['calendar_label'] ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tz_fd_service_save" />';
        echo '<input type="hidden" name="calendar" value="' . (int) $row['calendar'] . '" />';
        echo '<input type="hidden" name="service" value="' . esc_attr( $row['name'] ) . '" />';
        wp_nonce_field( TZFD_Service_Details::NONCE, 'tz_fd_service_nonce' );
        echo '<div class="tzfd-form-grid"><div class="tzfd-full"><label for="tz_fd_service_address">' . esc_html__( 'Address', 'titan-zero-trades-front-desk' ) . '</label>';
        echo '<textarea id="tz_fd_service_address" name="address" rows="3" class="large-text">' . esc_textarea( $details['address'] ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Where this service is given, such as a clinic room or the customer\'s home.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        echo '<div><label for="tz_fd_service_location">' . esc_html__( 'Location', 'titan-zero-trades-front-desk' ) . '</label>';
        echo '<select id="tz_fd_service_location" name="location_id"><option value="">' . esc_html__( 'No saved location', 'titan-zero-trades-front-desk' ) . '</option>';
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

    /**
     * Edits a calendar service: Workspace catalogue first, then the calendar's copy under the calendar lock.
     * If the calendar write fails, the catalogue row is put back to its earlier values.
     */
    public static function update_service() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 40, 16384 );
        check_admin_referer( 'tz_fd_service_update', 'tz_fd_service_update_nonce' );
        $calendar_id = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_POST['calendar'] ?? '' ) ) );
        $current     = sanitize_text_field( wp_unslash( $_POST['service'] ?? '' ) );
        $name       = TZFD_Legacy_Compat::substr( trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ), 0, 120 );
        $price_raw   = isset( $_POST['price'] ) && is_scalar( $_POST['price'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['price'] ) ) : '';
        $duration    = self::service_integer( isset( $_POST['duration'] ) && is_scalar( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['duration'] ) ) : null, 1, 1440 );
        $capacity    = self::service_integer( isset( $_POST['capacity'] ) && is_scalar( $_POST['capacity'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['capacity'] ) ) : null, 1, 1000000 );
        $price       = preg_match( '/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', $price_raw ) ? TZFD_Local_Money::parse_amount( $price_raw ) : null;
        if ( '' === $name || null === $price || ! is_finite( (float) $price ) || $price < 0 || $price > 9999999999.99 || null === $duration || null === $capacity ) { self::service_redirect( array( 'error' => 'fields' ) ); }
        if ( $calendar_id < 1 || ! self::can_manage_calendar( $calendar_id ) || ! TZFD_Service_Details::service_exists( $calendar_id, $current ) ) {
            self::service_redirect( array( 'error' => 'target' ) );
        }
        // Refuse a rename onto a saved address entry before any write, so no partial change is left behind.
        if ( $name !== $current && TZFD_Service_Details::has_entry( $calendar_id, $name ) ) {
            self::service_redirect( array( 'error' => 'rename' ) );
        }
        $price_text = number_format( $price, 2, '.', '' );
        $catalog_id = TZFD_Service_Details::catalog_id( $calendar_id, $current );
        $snapshot   = $catalog_id ? TZFD_Shared_Store::find_service( $catalog_id ) : null;
        if ( $catalog_id ) {
            if ( ! TZFD_Shared_Store::update_service( $catalog_id, array( 'name' => $name, 'price' => $price_text, 'duration_minutes' => $duration ) ) ) {
                self::service_redirect( array( 'error' => 'database' ) );
            }
        }
        $result = self::write_calendar_service( $calendar_id, 'update', $current, array(
            'name' => $name, 'price' => $price_text, 'duration' => $duration, 'capacity' => $capacity,
        ) );
        if ( 'changed' !== $result ) {
            if ( $catalog_id && is_array( $snapshot ) ) {
                TZFD_Shared_Store::update_service( $catalog_id, array( 'name' => (string) $snapshot['name'], 'price' => (string) $snapshot['price'], 'duration_minutes' => (int) $snapshot['duration_minutes'] ) );
            }
            self::service_redirect( array( 'error' => $result ) );
        }
        if ( ! $catalog_id ) {
            $new_catalog = TZFD_Shared_Store::create_service( array( 'name' => $name, 'description' => '', 'duration_minutes' => $duration, 'price' => $price_text, 'gst_applies' => 0 ) );
            if ( false !== $new_catalog ) { $catalog_id = (int) $new_catalog; }
        }
        if ( ! TZFD_Service_Details::rename( $calendar_id, $current, $name, $catalog_id ) ) {
            self::service_redirect( array( 'error' => 'database' ) );
        }
        self::service_redirect( array( 'updated' => 1 ) );
    }

    /** Archives a calendar service: removed from the calendar so it cannot be booked, and archived in the catalogue. */
    public static function archive_service() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 40, 16384 );
        check_admin_referer( 'tz_fd_service_archive', 'tz_fd_service_archive_nonce' );
        $calendar_id = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_POST['calendar'] ?? '' ) ) );
        $current     = sanitize_text_field( wp_unslash( $_POST['service'] ?? '' ) );
        if ( $calendar_id < 1|| ! self::can_manage_calendar( $calendar_id ) || ! TZFD_Service_Details::service_exists( $calendar_id, $current ) ) {
            self::service_redirect( array( 'error' => 'target' ) );
        }
        $catalog_id = TZFD_Service_Details::catalog_id( $calendar_id, $current );
        $result     = self::write_calendar_service( $calendar_id, 'archive', $current, array() );
        if ( 'changed' !== $result ) { self::service_redirect( array( 'error' => $result ) ); }
        if ( $catalog_id && ! TZFD_Shared_Store::archive_service( $catalog_id ) ) {
            self::service_redirect( array( 'error' => 'catalog' ) );
        }
        self::service_redirect( array( 'archived' => 1 ) );
    }

    /**
     * Updates or removes one service in a calendar's form structure under the same lock and compare-and-swap
     * as create_service(). Returns 'changed', 'busy', 'target', 'duplicate', 'changed' (stale copy) or 'database'.
     */
    private static function write_calendar_service( $calendar_id, $mode, $current, array $fields ) {
        global $wpdb;
        $table     = $wpdb->prefix . TZFD_Tables::FORMS;
        $lock_name = 'tz_fd_svc_' . md5( $table . ':' . $calendar_id );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock name bound with prepare().
        $locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
        if ( ! $locked ) { return 'busy'; }
        $result = 'target';
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table, bound with prepare().
            // Company isolation: a calendar is read only inside the request's company. No company means no row.
            $company  = TZFD_Scoped_Store::company_id();
            $calendar = $company > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT form_structure FROM %i WHERE id = %d AND company_id = %d', $table, $calendar_id, $company ) ) : null;
            if ( ! $calendar ) { $result = 'database'; }
            if ( $calendar ) {
                $structure = json_decode( (string) $calendar->form_structure );
                if ( ! is_array( $structure ) ) { $structure = json_decode( stripslashes( (string) $calendar->form_structure ) ); }
                if ( is_array( $structure ) && isset( $structure[0] ) ) {
                    $result = self::change_service_in_field( $structure[0], $current, $mode, $fields );
                    if ( 'changed' === $result ) {
                        $encoded = wp_json_encode( $structure );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table, bound with prepare(); compare-and-swap on the old value.
                        $updated = is_string( $encoded ) ? $wpdb->query( $wpdb->prepare(
                            'UPDATE %i SET form_structure = %s WHERE id = %d AND company_id = %d AND BINARY form_structure = BINARY %s',
                            $table, $encoded, $calendar_id, $company, (string) $calendar->form_structure
                        ) ) : false;
                        if ( false === $updated ) { $result = 'database'; }
                        elseif ( 0 === $updated ) { $result = 'stale'; }
                    }
                }
            }
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock name bound with prepare().
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
        return $result;
    }

    /** Finds a service by name in any appointment field and updates or removes it. Returns the same codes as write_calendar_service(). */
    private static function change_service_in_field( &$node, $current, $mode, array $fields ) {
        if ( is_object( $node ) ) {
            if ( isset( $node->ftype, $node->name ) && 'fapp' === $node->ftype && is_string( $node->name ) ) {
                if ( ! isset( $node->services ) || ! is_array( $node->services ) ) { return 'target'; }
                foreach ( $node->services as $i => $existing ) {
                    if ( ! is_object( $existing ) || ! isset( $existing->name ) || (string) $existing->name !== $current ) { continue; }
                    if ( 'archive' === $mode ) {
                        array_splice( $node->services, $i, 1 );
                        return 'changed';
                    }
                    if ( isset( $fields['name'] ) && $fields['name'] !== $current ) {
                        foreach ( $node->services as $other ) {
                            if ( is_object( $other ) && isset( $other->name ) && 0 === strcasecmp( trim( (string) $other->name ), $fields['name'] ) ) { return 'duplicate'; }
                        }
                    }
                    foreach ( $fields as $key => $value ) { $existing->$key = $value; }
                    return 'changed';
                }
            }
            foreach ( $node as $key => $child ) {
                if ( is_array( $child ) || is_object( $child ) ) {
                    $result = self::change_service_in_field( $node->$key, $current, $mode, $fields );
                    if ( 'target' !== $result ) { return $result; }
                }
            }
        } elseif ( is_array( $node ) ) {
            foreach ( $node as $key => $child ) {
                if ( is_array( $child ) || is_object( $child ) ) {
                    $result = self::change_service_in_field( $node[ $key ], $current, $mode, $fields );
                    if ( 'target' !== $result ) { return $result; }
                }
            }
        }
        return 'target';
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
