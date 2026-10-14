<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One layout for every Front Desk screen, matching the Front Desk mock-ups: a dark sidebar with the
 * menu, a top bar with breadcrumb, search, help, notifications and the user, then a page header with
 * its main action, cards, stat tiles, tabs, filter bars, tables and status badges. Brand red (#E31B23)
 * is the accent. Styles and the sidebar apply only on Front Desk screens (body class tzfd-screen), so
 * the rest of the WordPress admin is unchanged. The WordPress admin menu is hidden on those screens;
 * the sidebar links to WordPress from its footer.
 */
final class TZFD_Admin_Layout {
    const RED       = '#E31B23';
    const RED_DARK  = '#B81219';
    const INK       = '#f2f4f8';
    const MUTED     = '#a4acba';
    const BORDER    = '#333a48';
    const SURFACE   = '#232834';
    const SURFACE_2 = '#1f232d';
    const PAGE_BG   = '#1b1e26';
    const SIDEBAR   = '#1b1e26';
    const SIDE_W    = '232px';

    public static function register() {
        add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'in_admin_header', array( __CLASS__, 'render_chrome' ) );
    }

    /** True on any Front Desk admin screen (the menu slug starts with tzfd, or is the Front Desk slug). */
    public static function is_front_desk_screen() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return '' !== $page && ( 0 === strpos( $page, 'tzfd' ) || 0 === strpos( $page, 'titan-zero-trades-front-desk' ) );
    }

    public static function body_class( $classes ) {
        return self::is_front_desk_screen() ? $classes . ' tzfd-screen' : $classes;
    }

    public static function enqueue() {
        if ( ! self::is_front_desk_screen() ) { return; }
        wp_add_inline_style( 'wp-admin', self::css() . "\n" . self::screen_css() );
    }

    /* ---------------------------------------------------------------
     * Sidebar and top bar
     * ------------------------------------------------------------- */

    /** Menu items in the order of the mock-ups: key, label, page slug, icon file in images/brand/icons. */
    public static function nav_items() {
        $menu = 'TZFD_Admin_Menu';
        return array(
            'dashboard' => array( __( 'Dashboard', 'titan-zero-trades-front-desk' ), $menu::PARENT, 'dashboard' ),
            'calendar'  => array( __( 'Calendar', 'titan-zero-trades-front-desk' ), $menu::CALENDAR, 'calendar' ),
            'customers' => array( __( 'Customers', 'titan-zero-trades-front-desk' ), $menu::CUSTOMERS, 'customers' ),
            'bookings'  => array( __( 'Bookings', 'titan-zero-trades-front-desk' ), $menu::BOOKINGS, 'bookings' ),
            'quotes'    => array( __( 'Quotes', 'titan-zero-trades-front-desk' ), $menu::QUOTES, 'quotes' ),
            'services'  => array( __( 'Services', 'titan-zero-trades-front-desk' ), $menu::SERVICES, 'services' ),
            'workers'   => array( __( 'Workers', 'titan-zero-trades-front-desk' ), $menu::WORKERS, 'workers' ),
            'ai'        => array( __( 'AI', 'titan-zero-trades-front-desk' ), $menu::AI, 'ai-receptionist' ),
            'addons'    => array( __( 'Add-ons', 'titan-zero-trades-front-desk' ), $menu::ADDONS, 'addons' ),
            'settings'  => array( __( 'Settings', 'titan-zero-trades-front-desk' ), $menu::SETTINGS, 'settings' ),
        );
    }

    /** The sidebar item for the current screen. Calendar routes count as Calendar; Locations as Workers; other tools as Settings. */
    public static function active_key() {
        $menu = 'TZFD_Admin_Menu';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $menu::PARENT === $page ) {
            foreach ( array( 'cal', 'pwizard', 'confirm' ) as $key ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
                if ( isset( $_GET[ $key ] ) ) { return 'calendar'; }
            }
            return 'dashboard';
        }
        $map = array(
            $menu::CALENDAR   => 'calendar',
            $menu::CUSTOMERS  => 'customers',
            $menu::BOOKINGS   => 'bookings',
            $menu::QUOTES     => 'quotes',
            $menu::SERVICES   => 'services',
            $menu::WORKERS    => 'workers',
            $menu::LOCATIONS  => 'workers',
            $menu::AI         => 'ai',
            $menu::ADDONS     => 'addons',
            $menu::SETTINGS   => 'settings',
        );
        return isset( $map[ $page ] ) ? $map[ $page ] : 'settings';
    }

    /** Number of optional add-ons that are not switched on yet, shown beside Add-ons. */
    public static function addons_available() {
        global $tzfd_addons_objs_list;
        $count = 0;
        foreach ( (array) $tzfd_addons_objs_list as $addon ) {
            if ( is_object( $addon ) && method_exists( $addon, 'addon_is_active' ) && ! $addon->addon_is_active() ) { $count++; }
        }
        return $count;
    }

    /** Prints an icon as a CSS mask so it takes the colour of its text. */
    public static function print_icon( $file, $class = 'tzfd-ic' ) {
        $url = TZFD_Brand::url( 'images/brand/icons/' . $file . '.svg' );
        echo '<span class="' . esc_attr( $class ) . '" aria-hidden="true" style="--tzfd-ic:url(\'' . esc_url( $url ) . '\')"></span>';
    }

    /** Sidebar and top bar. Printed on Front Desk screens only (in_admin_header). */
    public static function render_chrome() {
        if ( ! self::is_front_desk_screen() ) { return; }
        $menu  = 'TZFD_Admin_Menu';
        $key   = self::active_key();
        $items = self::nav_items();
        $user  = wp_get_current_user();
        $name  = (string) $user->display_name;
        $words = preg_split( '/\s+/', trim( $name ) );
        $init  = '';
        foreach ( array_slice( (array) $words, 0, 2 ) as $word ) { $init .= mb_strtoupper( mb_substr( $word, 0, 1 ) ); }
        $role  = current_user_can( 'manage_options' ) ? __( 'Administrator', 'titan-zero-trades-front-desk' ) : __( 'Editor', 'titan-zero-trades-front-desk' );
        $site  = get_bloginfo( 'name' );
        $title = isset( $items[ $key ] ) ? $items[ $key ][0] : __( 'Settings', 'titan-zero-trades-front-desk' );
        $avail = self::addons_available();

        echo '<aside id="tzfd-sidebar" class="tzfd-sidebar" aria-label="' . esc_attr__( 'Front Desk menu', 'titan-zero-trades-front-desk' ) . '">';
        echo '<a class="tzfd-brand" href="' . esc_url( admin_url( 'admin.php?page=' . $menu::PARENT ) ) . '"><span class="tzfd-mark">T</span><span class="tzfd-wordmark"><strong>TITAN ZERO</strong><small>FRONT DESK</small></span></a>';
        echo '<div class="tzfd-workspace"><span>' . esc_html( '' !== $site ? $site : __( 'My site', 'titan-zero-trades-front-desk' ) ) . '</span></div>';
        echo '<p class="tzfd-nav-label">' . esc_html__( 'Workspace', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<nav aria-label="' . esc_attr__( 'Front Desk', 'titan-zero-trades-front-desk' ) . '"><ul class="tzfd-nav">';
        foreach ( $items as $item_key => $item ) {
            $current = $item_key === $key;
            echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . $item[1] ) ) . '"' . ( $current ? ' class="is-active" aria-current="page"' : '' ) . '>';
            self::print_icon( $item[2] );
            echo '<span class="tzfd-nav-text">' . esc_html( $item[0] ) . '</span>';
            if ( 'addons' === $item_key && $avail > 0 ) { echo '<span class="tzfd-count">' . (int) $avail . '</span>'; }
            echo '</a></li>';
        }
        echo '</ul></nav>';
        // The WordPress menu is hidden on these screens, so its links are listed under the Front Desk links.
        self::print_wp_menu();
        echo '<div class="tzfd-help-block"><a class="tzfd-help-link" href="' . esc_url( admin_url( 'admin.php?page=' . $menu::HELP ) ) . '">';
        self::print_icon( 'support' );
        echo '<span>' . esc_html__( 'Help & documentation', 'titan-zero-trades-front-desk' ) . '</span></a>';
        echo '<small>' . esc_html__( 'Support', 'titan-zero-trades-front-desk' ) . ' · ' . esc_html( sprintf( /* translators: %s: plugin version number. */ __( 'Version %s', 'titan-zero-trades-front-desk' ), self::version() ) ) . '</small></div>';
        echo '<div class="tzfd-user"><span class="tzfd-avatar">' . esc_html( $init ) . '</span><span><strong>' . esc_html( $name ) . '</strong><small>' . esc_html( $role ) . '</small></span></div>';
        echo '</aside>';

        // Top bar: breadcrumb, search, help, notifications and the user.
        echo '<header class="tzfd-topbar">';
        echo '<p class="tzfd-crumb">' . esc_html__( 'Front Desk', 'titan-zero-trades-front-desk' ) . ' <span aria-hidden="true">·</span> <strong>' . esc_html( $title ) . '</strong></p>';
        echo '<form class="tzfd-top-search" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" role="search">';
        echo '<input type="hidden" name="page" value="' . esc_attr( $menu::CUSTOMERS ) . '" />';
        self::print_icon( 'search', 'tzfd-ic tzfd-ic-search' );
        echo '<input type="search" name="s" placeholder="' . esc_attr__( 'Search customers, bookings...', 'titan-zero-trades-front-desk' ) . '" aria-label="' . esc_attr__( 'Search customers', 'titan-zero-trades-front-desk' ) . '" /></form>';
        echo '<div class="tzfd-top-icons">';
        echo '<a class="tzfd-round" href="' . esc_url( admin_url( 'admin.php?page=' . $menu::HELP ) ) . '" aria-label="' . esc_attr__( 'Help', 'titan-zero-trades-front-desk' ) . '">?</a>';
        echo '<a class="tzfd-round tzfd-round-alert" href="' . esc_url( admin_url( 'admin.php?page=' . $menu::PARENT ) ) . '" aria-label="' . esc_attr__( 'Notifications', 'titan-zero-trades-front-desk' ) . '">';
        self::print_icon( 'notifications' );
        echo '</a>';
        echo '<span class="tzfd-top-user">' . esc_html( $init ) . '</span>';
        echo '</div></header>';
    }

    /** Prints the WordPress admin menu as sidebar links, so no WordPress link is lost while the WordPress menu is hidden. */
    public static function print_wp_menu() {
        global $menu;
        echo '<details class="tzfd-wp-menu" open><summary>' . esc_html__( 'WordPress menu', 'titan-zero-trades-front-desk' ) . '</summary><ul class="tzfd-nav tzfd-nav-wp">';
        foreach ( (array) $menu as $entry ) {
            $slug = isset( $entry[2] ) ? (string) $entry[2] : '';
            $cap  = isset( $entry[1] ) ? (string) $entry[1] : '';
            if ( '' === $slug || 0 === strpos( $slug, 'separator' ) || 'tzfd' === $slug ) { continue; }
            if ( '' !== $cap && ! current_user_can( $cap ) ) { continue; }
            $label = trim( wp_strip_all_tags( preg_replace( '#<span[^>]*>.*?</span>#s', '', (string) ( $entry[0] ?? '' ) ) ) );
            if ( '' === $label ) { continue; }
            if ( filter_var( $slug, FILTER_VALIDATE_URL ) ) {
                $href = $slug;
            } else {
                $href = admin_url( false !== strpos( $slug, '.php' ) ? $slug : 'admin.php?page=' . $slug );
            }
            echo '<li><a href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul></details>';
    }

    private static function version() {
        $data = get_file_data( dirname( __FILE__, 2 ) . '/titan-zero-trades-front-desk.php', array( 'Version' => 'Version' ) );
        return ! empty( $data['Version'] ) ? (string) $data['Version'] : '1.0.0';
    }

    /**
     * Page header used by Front Desk screens: title, a one-line lead, and the page's main action(s).
     * $actions is trusted HTML built with esc_* functions by the caller.
     */
    public static function header( $title, $lead = '', $actions = '' ) {
        echo '<div class="tzfd-page-head"><div><h1>' . esc_html( $title ) . '</h1>';
        if ( '' !== $lead ) { echo '<p class="tzfd-lead">' . esc_html( $lead ) . '</p>'; }
        echo '</div>';
        if ( '' !== $actions ) { echo '<div class="tzfd-page-actions">' . wp_kses( $actions, self::allowed_actions() ) . '</div>'; }
        echo '</div>';
    }

    /** Tags allowed in the page-action HTML passed to header(): buttons and links only. */
    public static function allowed_actions() {
        $common = array( 'class' => true, 'id' => true, 'title' => true, 'role' => true, 'aria-label' => true, 'aria-hidden' => true, 'aria-current' => true );
        return array(
            'a'      => $common + array( 'href' => true, 'target' => true, 'rel' => true ),
            'button' => $common + array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true ),
            'span'   => $common,
        );
    }

    /**
     * Stat tile for a page. $accent is red, blue, amber, green or ink. $href may be '' for a plain tile.
     */
    public static function stat( $value, $label, $sub = '', $accent = 'red', $href = '' ) {
        if ( '' !== $href ) {
            echo '<a class="tzfd-stat tzfd-stat--' . esc_attr( $accent ) . '" href="' . esc_url( $href ) . '">';
        } else {
            echo '<div class="tzfd-stat tzfd-stat--' . esc_attr( $accent ) . '">';
        }
        echo '<span class="tzfd-stat-l">' . esc_html( $label ) . '</span>';
        echo '<span class="tzfd-stat-n">' . esc_html( $value ) . '</span>';
        if ( '' !== $sub ) { echo '<span class="tzfd-stat-s">' . esc_html( $sub ) . '</span>'; }
        if ( '' !== $href ) {
            echo '</a>';
        } else {
            echo '</div>';
        }
    }

    /* ---------------------------------------------------------------
     * Shared stylesheet
     * ------------------------------------------------------------- */

    /** Shared stylesheet for Front Desk screens. Colours come from the constants above. */
    public static function css() {
        $red   = self::RED;
        $dark  = self::RED_DARK;
        $ink   = self::INK;
        $mut   = self::MUTED;
        $line  = self::BORDER;
        $bg    = self::PAGE_BG;
        $side  = self::SIDEBAR;
        $surf  = self::SURFACE;
        $surf2 = self::SURFACE_2;
        $sw    = self::SIDE_W;
        return strtr( '/* Hide the WordPress menu on Front Desk screens: the Front Desk sidebar replaces it */
.tzfd-screen #adminmenumain, .tzfd-screen #adminmenuback, .tzfd-screen #adminmenuwrap, .tzfd-screen #collapse-menu { display:none !important; }
.tzfd-screen #wpcontent, .tzfd-screen #wpfooter { margin-left:@sw@ !important; background:@bg@; }
.tzfd-screen #wpbody-content { padding-bottom:40px; }
.tzfd-screen { background:@bg@; }
#toplevel_page_tzfd.wp-has-current-submenu > a, #toplevel_page_tzfd.current > a, #toplevel_page_tzfd:hover > a { background:@red@ !important; color:#fff !important; }

/* Sidebar */
.tzfd-sidebar { position:fixed; top:32px; left:0; bottom:0; width:@sw@; box-sizing:border-box; background:@side@; color:#c9ccd4; overflow-y:auto; z-index:100; padding:18px 14px 14px; display:flex; flex-direction:column; gap:4px; font-size:13px; }
.tzfd-sidebar > * { flex-shrink:0; }
.tzfd-sidebar a { text-decoration:none; color:inherit; }
.tzfd-brand { display:flex; align-items:center; gap:10px; padding:4px 6px 14px; }
.tzfd-mark { width:34px; height:34px; border-radius:8px; background:@red@; color:#fff; font-weight:800; font-size:18px; display:flex; align-items:center; justify-content:center; flex:0 0 auto; }
.tzfd-wordmark { display:flex; flex-direction:column; line-height:1.1; color:#fff; }
.tzfd-wordmark strong { font-size:13px; letter-spacing:.04em; }
.tzfd-wordmark small { font-size:10px; letter-spacing:.14em; color:@mut@; }
.tzfd-workspace { background:#262a34; border-radius:8px; padding:9px 12px; color:#fff; font-weight:600; margin-bottom:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tzfd-nav-label { font-size:10px; letter-spacing:.14em; text-transform:uppercase; color:@mut@; margin:0 6px 6px; }
.tzfd-nav { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:2px; }
.tzfd-nav a { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:7px; color:#c9ccd4; }
.tzfd-nav a:hover { background:#252934; color:#fff; }
.tzfd-nav a.is-active { background:rgba(227,27,35,.2); color:#fff; font-weight:600; }
.tzfd-nav a.is-active .tzfd-ic { background:@red@; }
.tzfd-nav-text { flex:1; }
.tzfd-count { background:#2b2f3a; color:#fff; font-size:11px; border-radius:999px; padding:0 7px; line-height:18px; }
.tzfd-ic { display:inline-block; width:18px; height:18px; flex:0 0 18px; background:currentColor; -webkit-mask:var(--tzfd-ic) center / contain no-repeat; mask:var(--tzfd-ic) center / contain no-repeat; }
.tzfd-help-block { margin-top:auto; border-top:1px solid #2b2f3a; padding:14px 6px 10px; display:flex; flex-direction:column; gap:4px; }
.tzfd-help-link { display:flex; align-items:center; gap:10px; color:#fff; }
.tzfd-help-block small { color:@mut@; font-size:11px; padding-left:28px; }
.tzfd-user { display:flex; align-items:center; gap:10px; padding:12px 6px; }
.tzfd-user strong { display:block; color:#fff; font-size:13px; }
.tzfd-user small { color:@mut@; font-size:11px; }
.tzfd-avatar { width:32px; height:32px; border-radius:50%; background:#2b2f3a; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; flex:0 0 auto; }
.tzfd-back-wp { color:@mut@ !important; font-size:12px; padding:0 6px 4px; }
.tzfd-back-wp:hover { color:#fff !important; }
.tzfd-wp-menu { border-top:1px solid #2b2f3a; padding-top:10px; margin-top:6px; }
.tzfd-wp-menu summary { font-size:10px; letter-spacing:.14em; text-transform:uppercase; color:@mut@; margin:0 6px 6px; cursor:pointer; list-style:none; }
.tzfd-wp-menu summary::-webkit-details-marker { display:none; }
.tzfd-nav-wp a { padding:6px 10px; font-size:12px; }

/* Top bar */
.tzfd-topbar { display:flex; align-items:center; gap:14px; background:@bg@; border-bottom:1px solid @line@; padding:12px 24px; margin:0 0 4px; }
.tzfd-crumb { margin:0; color:@mut@; font-size:13px; white-space:nowrap; }
.tzfd-crumb strong { color:@ink@; }
.tzfd-top-search { flex:1; max-width:420px; margin:0 0 0 auto; position:relative; }
.tzfd-top-search input[type="search"] { width:100%; padding:7px 12px 7px 34px; border:1px solid @line@; border-radius:8px; background:@surf2@; font-size:13px; box-sizing:border-box; }
.tzfd-top-search .tzfd-ic-search { position:absolute; left:10px; top:9px; color:@mut@; width:16px; height:16px; flex-basis:16px; }
.tzfd-top-icons { display:flex; align-items:center; gap:10px; }
.tzfd-round { width:32px; height:32px; border-radius:50%; border:1px solid @line@; display:inline-flex; align-items:center; justify-content:center; color:@ink@; text-decoration:none; font-weight:600; background:@surf@; }
.tzfd-round .tzfd-ic { width:16px; height:16px; flex-basis:16px; }
.tzfd-round-alert { color:@red@; border-color:#f3c3c5; }
.tzfd-top-user { width:32px; height:32px; border-radius:50%; background:#3a4150; color:#fff; font-weight:700; font-size:12px; display:flex; align-items:center; justify-content:center; }
@media screen and (max-width:782px) {
  .tzfd-screen #wpcontent, .tzfd-screen #wpfooter { margin-left:0 !important; }
  .tzfd-sidebar { position:static; width:auto; padding:12px; }
  .tzfd-nav { flex-direction:row; overflow-x:auto; }
  .tzfd-nav-label, .tzfd-workspace, .tzfd-help-block, .tzfd-user, .tzfd-back-wp { display:none; }
  .tzfd-nav a { white-space:nowrap; }
  .tzfd-topbar { flex-wrap:wrap; padding:10px 14px; }
  .tzfd-crumb { width:100%; }
  .tzfd-top-search { max-width:none; order:3; width:100%; }
}

/* Page header */
.tzfd-screen .tzfd-page { max-width:1180px; }
.tzfd-screen .tzfd-page > h1, .tzfd-screen .tzfd-page-head h1 { font-size:26px; font-weight:700; color:@ink@; margin:0; padding:0; line-height:1.2; }
.tzfd-screen .tzfd-page-head { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px 16px; margin:14px 0 18px; }
.tzfd-screen .tzfd-page-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.tzfd-screen .tzfd-lead { color:@mut@; font-size:14px; margin:4px 0 0; }

/* Cards and sections */
.tzfd-screen .tzfd-card { background:@surf@; border:1px solid @line@; border-radius:10px; padding:18px 20px; margin:0 0 16px; box-shadow:0 1px 2px rgba(16,24,40,.04); }
.tzfd-screen .tzfd-card h2, .tzfd-screen .tzfd-card h3 { margin-top:0; }
.tzfd-screen .tzfd-card-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px 16px; margin:0 0 12px; }
.tzfd-screen .tzfd-card-head h2, .tzfd-screen .tzfd-card-head h3 { margin:0; font-size:16px; font-weight:600; color:@ink@; }
.tzfd-screen .tzfd-section-title { font-size:16px; font-weight:600; color:@ink@; margin:20px 0 8px; }
.tzfd-screen .tzfd-muted { color:@mut@; }
.tzfd-screen .tzfd-foot-card { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px 16px; }
.tzfd-screen .tzfd-foot-card p { margin:0; color:@mut@; }

/* Buttons: primary is brand red, secondary is white with a light border */
.tzfd-screen .tzfd-btn { display:inline-flex; align-items:center; gap:6px; min-height:34px; line-height:32px; padding:0 14px; border-radius:8px; border:1px solid @line@; background:@surf@; color:@ink@; text-decoration:none; font-size:13px; font-weight:500; cursor:pointer; vertical-align:middle; box-sizing:border-box; }
.tzfd-screen .tzfd-btn:hover, .tzfd-screen .tzfd-btn:focus { border-color:@red@; color:@red@; }
.tzfd-screen .tzfd-btn-primary, .tzfd-screen .button-primary, .tzfd-screen input[type="submit"].button-primary { background:@red@ !important; border-color:@red@ !important; color:#fff !important; box-shadow:none !important; text-shadow:none; }
.tzfd-screen .tzfd-btn-primary:hover, .tzfd-screen .tzfd-btn-primary:focus, .tzfd-screen .button-primary:hover, .tzfd-screen .button-primary:focus { background:@dark@ !important; border-color:@dark@ !important; color:#fff !important; }
.tzfd-screen .tzfd-btn-secondary { background:@surf@; }
.tzfd-screen .tzfd-btn-danger { color:@red@; border-color:@red@; background:@surf@; }
.tzfd-screen .tzfd-btn-danger:hover { background:@red@; color:#fff; }
.tzfd-screen .tzfd-btn[aria-current="page"] { background:@red@; border-color:@red@; color:#fff; }
.tzfd-screen .tzfd-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0; }

/* Forms: two columns on wide screens, one column on phones */
.tzfd-screen .tzfd-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px 18px; }
.tzfd-screen .tzfd-form-grid .tzfd-full { grid-column:1 / -1; }
.tzfd-screen .tzfd-form-grid label { display:block; font-weight:600; margin:0 0 4px; color:@ink@; }
.tzfd-screen .tzfd-form-grid input[type="text"], .tzfd-screen .tzfd-form-grid input[type="email"], .tzfd-screen .tzfd-form-grid input[type="tel"],
.tzfd-screen .tzfd-form-grid input[type="number"], .tzfd-screen .tzfd-form-grid select, .tzfd-screen .tzfd-form-grid textarea { width:100%; max-width:none; }
.tzfd-screen .tzfd-form-grid .description { color:@mut@; margin:4px 0 0; }
@media screen and (max-width:782px) { .tzfd-screen .tzfd-form-grid { grid-template-columns:1fr; } }

/* Filter bar: search, selects and buttons on one row */
.tzfd-screen .tzfd-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 12px; }
.tzfd-screen .tzfd-filters input[type="search"], .tzfd-screen .tzfd-filters select { border:1px solid @line@; border-radius:8px; min-height:34px; background:@surf@; font-size:13px; box-sizing:border-box; }
.tzfd-screen .tzfd-filters input[type="search"] { min-width:min(280px,100%); }
.tzfd-screen .tzfd-filters .tzfd-filter-spacer { flex:1; }

/* Tabs: underlined, active tab in brand red */
.tzfd-screen .nav-tab-wrapper, .tzfd-screen .tzfd-tabs { border-bottom:1px solid @line@; margin:0 0 16px; padding:0; display:flex; flex-wrap:wrap; gap:4px 22px; }
.tzfd-screen .nav-tab-wrapper .nav-tab, .tzfd-screen .tzfd-tabs a { color:@mut@; background:transparent; border:0; border-bottom:2px solid transparent; margin:0 0 -1px; padding:8px 2px 10px; font-size:14px; text-decoration:none; }
.tzfd-screen .nav-tab-wrapper .nav-tab:hover, .tzfd-screen .tzfd-tabs a:hover { color:@red@; }
.tzfd-screen .nav-tab-wrapper .nav-tab-active, .tzfd-screen .nav-tab-wrapper .nav-tab-active:hover, .tzfd-screen .tzfd-tabs a.is-active { color:@red@; border-bottom-color:@red@; font-weight:600; background:transparent; }

/* Tables: scroll sideways on small screens rather than squashing */
.tzfd-screen .tzfd-table-wrap { overflow-x:auto; padding:0; }
.tzfd-screen .tzfd-table { border:0; box-shadow:none; width:100%; margin:0; }
.tzfd-screen .tzfd-table th, .tzfd-screen .tzfd-table td { text-align:left; }
.tzfd-screen .tzfd-cell-clip { display:inline-block; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle; }
.tzfd-screen .tzfd-table thead th { font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:@mut@; font-weight:600; background:@surf@; border-bottom:1px solid @line@; padding:10px 12px; }
.tzfd-screen .tzfd-table td { padding:12px; vertical-align:middle; }
.tzfd-screen .tzfd-table tbody tr:nth-child(odd) td { background:@surf@; }
.tzfd-screen .tzfd-table tbody tr:nth-child(even) td { background:@surf2@; }
.tzfd-screen .tzfd-table a.tzfd-open { color:@red@; font-weight:500; text-decoration:none; }
.tzfd-screen .tzfd-pager { display:flex; justify-content:flex-end; gap:6px; align-items:center; margin:12px 0 0; color:@mut@; font-size:13px; }

/* Status badges and booking chips */
.tzfd-screen .tzfd-badge, .tzfd-screen .tzfd-chip { display:inline-block; border-radius:999px; padding:1px 9px; font-size:12px; line-height:19px; font-weight:500; white-space:nowrap; text-decoration:none; background:@surf2@; color:@mut@; }
.tzfd-screen .tzfd-badge-confirmed, .tzfd-screen .tzfd-chip-confirmed, .tzfd-screen .tzfd-badge-accepted, .tzfd-screen .tzfd-badge-active, .tzfd-screen .tzfd-badge-published, .tzfd-screen .tzfd-badge-available { background:#e6f6ec; color:#17693a; }
.tzfd-screen .tzfd-badge-pending, .tzfd-screen .tzfd-chip-pending, .tzfd-screen .tzfd-badge-awaiting, .tzfd-screen .tzfd-badge-followup, .tzfd-screen .tzfd-badge-warn { background:#fff4dc; color:#8a5300; }
.tzfd-screen .tzfd-badge-sent, .tzfd-screen .tzfd-badge-attended, .tzfd-screen .tzfd-chip-attended, .tzfd-screen .tzfd-badge-admin { background:#e8f0fb; color:#1f4f8f; }
.tzfd-screen .tzfd-badge-declined, .tzfd-screen .tzfd-badge-cancelled-red, .tzfd-screen .tzfd-badge-live { background:#fde8e9; color:#b3202a; }
.tzfd-screen .tzfd-badge-draft, .tzfd-screen .tzfd-badge-cancelled, .tzfd-screen .tzfd-chip-cancelled, .tzfd-screen .tzfd-badge-expired, .tzfd-screen .tzfd-badge-other, .tzfd-screen .tzfd-chip-other { background:@surf2@; color:@mut@; }
.tzfd-screen .tzfd-badge-cancelled, .tzfd-screen .tzfd-chip-cancelled { text-decoration:line-through; }
.tzfd-screen .tzfd-badge-live { font-weight:700; letter-spacing:.04em; }

/* Calendar */
.tzfd-screen .tzfd-cal-toolbar { display:flex; flex-wrap:wrap; gap:10px 16px; align-items:center; margin:0 0 14px; }
.tzfd-screen .tzfd-cal-nav { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.tzfd-screen .tzfd-cal-title { margin-left:8px; font-size:16px; font-weight:600; color:@ink@; }
.tzfd-screen .tzfd-cal-views { display:flex; gap:0; }
.tzfd-screen .tzfd-cal-views .tzfd-btn { border-radius:0; margin-left:-1px; }
.tzfd-screen .tzfd-cal-views .tzfd-btn:first-child { border-radius:8px 0 0 8px; margin-left:0; }
.tzfd-screen .tzfd-cal-views .tzfd-btn:last-child { border-radius:0 8px 8px 0; }
.tzfd-screen .tzfd-cal-filter { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:0; }
.tzfd-screen .tzfd-cal-scroll { overflow-x:auto; padding:0; background:@surf@; border:1px solid @line@; border-radius:10px; }
.tzfd-screen .tzfd-cal-grid { border-collapse:collapse; width:100%; min-width:640px; table-layout:fixed; }
.tzfd-screen .tzfd-cal-grid th, .tzfd-screen .tzfd-cal-grid td { border:1px solid #eef0f3; vertical-align:top; padding:6px; text-align:left; }
.tzfd-screen .tzfd-cal-grid th { background:@surf2@; font-size:12px; color:@mut@; font-weight:600; }
.tzfd-screen .tzfd-cal-grid th a { color:inherit; text-decoration:none; }
.tzfd-screen .tzfd-cal-week td { height:180px; }
.tzfd-screen .tzfd-cal-month td { height:104px; }
.tzfd-screen .tzfd-cal-out { background:@surf2@; }
.tzfd-screen .tzfd-cal-out .tzfd-cal-daynum { color:@mut@; }
.tzfd-screen .tzfd-cal-today { background:#2b1d21; }
.tzfd-screen .tzfd-cal-today .tzfd-cal-daynum, .tzfd-screen th.tzfd-cal-today a { color:@red@; font-weight:700; }
.tzfd-screen .tzfd-cal-daynum { display:inline-block; font-weight:600; color:@ink@; text-decoration:none; margin-bottom:4px; }
.tzfd-screen .tzfd-cal-grid .tzfd-chip { display:block; max-width:100%; overflow:hidden; text-overflow:ellipsis; margin:0 0 4px; border-radius:6px; padding:3px 8px; }
.tzfd-screen .tzfd-cal-more { display:block; font-size:12px; color:@red@; }
.tzfd-screen .tzfd-svc-1 { background:#e8f0fb !important; color:#1f4f8f !important; }
.tzfd-screen .tzfd-svc-2 { background:#e6f6ec !important; color:#17693a !important; }
.tzfd-screen .tzfd-svc-3 { background:#fff4dc !important; color:#8a5300 !important; }
.tzfd-screen .tzfd-svc-4 { background:#f1e9fb !important; color:#5b2d9b !important; }
@media screen and (max-width:782px) { .tzfd-screen .tzfd-cal-title { margin-left:0; width:100%; } }

/* Customer details and search */
.tzfd-screen .tzfd-dl { display:grid; grid-template-columns:minmax(120px,max-content) 1fr; gap:8px 18px; margin:0; }
.tzfd-screen .tzfd-dl > span:nth-child(odd) { color:@mut@; font-size:13px; padding-top:2px; }
.tzfd-screen .tzfd-dl > span:nth-child(even) { color:@ink@; word-break:break-word; }
.tzfd-screen .tzfd-search { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:0; }
.tzfd-screen .tzfd-search input[type="search"] { min-width:min(320px,100%); }
.tzfd-screen .tzfd-actions form { margin:0; }
@media screen and (max-width:782px) { .tzfd-screen .tzfd-dl { grid-template-columns:1fr; gap:2px 0; } .tzfd-screen .tzfd-dl > span:nth-child(odd) { padding-top:8px; } }

/* Stat tiles: label, number, then a short line. Accent colour on the left edge. */
.tzfd-screen .tzfd-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin:0 0 16px; }
.tzfd-screen .tzfd-stat { display:flex; flex-direction:column; background:@surf@; border:1px solid @line@; border-left:4px solid @red@; border-radius:10px; padding:14px 16px; color:inherit; text-decoration:none; transition:border-color .15s, box-shadow .15s; }
.tzfd-screen .tzfd-stat--blue { border-left-color:#2563eb; }
.tzfd-screen .tzfd-stat--amber { border-left-color:#d97706; }
.tzfd-screen .tzfd-stat--green { border-left-color:#16a34a; }
.tzfd-screen .tzfd-stat--ink { border-left-color:@ink@; }
.tzfd-screen .tzfd-stat-l { order:0; font-size:12px; color:@mut@; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
.tzfd-screen .tzfd-stat-n { order:1; font-size:28px; font-weight:700; line-height:1.2; color:@ink@; margin:6px 0 2px; }
.tzfd-screen .tzfd-stat-s { order:2; font-size:12px; color:@mut@; }
.tzfd-screen a.tzfd-stat:hover, .tzfd-screen a.tzfd-stat:focus { border-color:@red@; box-shadow:0 2px 8px rgba(227,27,35,.12); color:inherit; }
.tzfd-screen a.tzfd-stat:hover .tzfd-stat-n { color:@red@; }

/* Dashboard notices: one button per line on phones and in a row on wider screens */
.tzfd-screen .tzfd-notice-actions { display:flex; flex-wrap:wrap; gap:8px; margin:8px 0 4px; }
.tzfd-screen .tzfd-notice-actions .button { margin:0; }
.tzfd-screen .tzfd-notice p { margin:6px 0; }
.tzfd-screen .tzfd-live { display:inline-block; background:#fde8e9; color:#b3202a; font-size:11px; font-weight:700; letter-spacing:.06em; border-radius:999px; padding:1px 8px; }
.tzfd-screen .tzfd-bar { height:6px; background:@surf2@; border-radius:999px; overflow:hidden; }
.tzfd-screen .tzfd-bar > span { display:block; height:100%; background:@red@; border-radius:999px; }

/* Dashboard: welcome card, two columns, activity and quick actions */
.tzfd-screen .tzfd-welcome { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px 20px; }
.tzfd-screen .tzfd-welcome-main { flex:1 1 360px; min-width:0; }
.tzfd-screen .tzfd-welcome h2 { font-size:20px; font-weight:700; color:@ink@; margin:0 0 4px; }
.tzfd-screen .tzfd-welcome .tzfd-bar { max-width:520px; margin:12px 0 4px; }
.tzfd-screen .tzfd-progress-text { margin:0 0 10px; font-size:13px; }
.tzfd-screen .tzfd-steps { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:6px 16px; font-size:13px; color:@mut@; }
.tzfd-screen .tzfd-steps li { padding-left:22px; position:relative; }
.tzfd-screen .tzfd-steps li::before { content:""; position:absolute; left:0; top:5px; width:12px; height:12px; border-radius:50%; border:1.5px solid #c3c6cc; box-sizing:border-box; }
.tzfd-screen .tzfd-steps li.is-done { color:@ink@; }
.tzfd-screen .tzfd-steps li.is-done::before { background:#16a34a; border-color:#16a34a; }
.tzfd-screen .tzfd-welcome-action { flex:0 0 auto; }
.tzfd-screen .tzfd-dash-grid { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:16px; align-items:start; }
.tzfd-screen .tzfd-dash-grid > .tzfd-card { margin:0; }
.tzfd-screen .tzfd-side-col { display:flex; flex-direction:column; gap:16px; }
.tzfd-screen .tzfd-side-col > .tzfd-card { margin:0; }
@media screen and (max-width:960px) { .tzfd-screen .tzfd-dash-grid { grid-template-columns:minmax(0,1fr); } .tzfd-screen .tzfd-dash-grid > * { min-width:0; } }
.tzfd-screen .tzfd-activity { list-style:none; margin:0 0 6px; padding:0; }
.tzfd-screen .tzfd-activity li { padding:10px 0; border-bottom:1px solid #eef0f3; display:flex; flex-direction:column; gap:1px; font-size:13px; }
.tzfd-screen .tzfd-activity li:last-child { border-bottom:0; }
.tzfd-screen .tzfd-activity span, .tzfd-screen .tzfd-activity small { color:@mut@; }
.tzfd-screen .tzfd-activity small { font-size:12px; }
.tzfd-screen .tzfd-quick { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }

/* Shared card pieces */
.tzfd-screen .tzfd-card-title { font-size:16px; font-weight:600; color:@ink@; margin:0 0 4px; }
.tzfd-screen .tzfd-card-title .tzfd-badge { margin-left:6px; vertical-align:middle; }
.tzfd-screen .tzfd-tip { margin:0; color:@mut@; font-size:13px; }
.tzfd-screen .tzfd-tip strong { color:@ink@; }
.tzfd-screen .tzfd-list-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px 16px; }
.tzfd-screen .tzfd-banner { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px 16px; margin-bottom:16px; }
.tzfd-screen .tzfd-banner p { margin:4px 0 0; }
.tzfd-screen .tzfd-banner-warn { border-left:4px solid #d97706; background:#fffaf0; }

/* Calendar toolbar: navigation on the left, filter, views and manage on the right */
.tzfd-screen .tzfd-cal-toolbar { justify-content:space-between; }
.tzfd-screen .tzfd-cal-right { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.tzfd-screen .tzfd-cal-step { min-width:34px; text-align:center; font-size:16px; line-height:30px; padding:0; }
.tzfd-screen .tzfd-cal-filter select { min-height:34px; border:1px solid @line@; border-radius:8px; background:@surf@; }
.tzfd-screen .tzfd-cal-more { border-style:dashed; }
/* Dark content area: the page, top bar and banner use the sidebar colour; cards and fields sit one step lighter */
.tzfd-screen .tzfd-card, .tzfd-screen .tzfd-stat, .tzfd-screen .tzfd-cal-scroll { background:@surf@; }
.tzfd-screen input[type="text"], .tzfd-screen input[type="search"], .tzfd-screen input[type="email"], .tzfd-screen input[type="tel"],
.tzfd-screen input[type="number"], .tzfd-screen input[type="date"], .tzfd-screen input[type="time"], .tzfd-screen input[type="password"],
.tzfd-screen select, .tzfd-screen textarea { background:@surf2@; color:@ink@; border-color:@line@; }
.tzfd-screen input::placeholder, .tzfd-screen textarea::placeholder { color:@mut@; opacity:1; }
.tzfd-screen .description, .tzfd-screen .tzfd-muted, .tzfd-screen .tzfd-lead { color:@mut@; }
.tzfd-screen .tzfd-btn-secondary { background:@surf@; color:@ink@; border-color:@line@; }
.tzfd-screen .tzfd-round { background:@surf@; color:@ink@; border-color:@line@; }
.tzfd-screen .tzfd-table thead th { background:@surf2@; color:@mut@; border-bottom-color:@line@; }
.tzfd-screen .tzfd-table td, .tzfd-screen .tzfd-table th { border-color:@line@; }
/* Add-ons, Help and the publish wizard: the same dark surfaces as the rest of Front Desk */
.tzfd-screen .tzfdad-ad-page { color:@ink@; }
.tzfd-screen .tzfdad-ad-sub, .tzfd-screen .tzfdad-ad-sechead p, .tzfd-screen .tzfdad-ad-tile p, .tzfd-screen .tzfdad-ad-row p, .tzfd-screen .tzfdad-ad-foot, .tzfd-screen .tzfdad-ad-subnote { color:@mut@; }
.tzfd-screen .tzfdad-ad-head h1, .tzfd-screen .tzfdad-ad-sechead h2, .tzfd-screen .tzfdad-ad-tile h3, .tzfd-screen .tzfdad-ad-row h3 { color:@ink@; }
.tzfd-screen .tzfdad-ad-card, .tzfd-screen .tzfdad-ad-tile, .tzfd-screen .tzfdad-addon-details { background:@surf@; border-color:@line@; color:@ink@; }
.tzfd-screen .tzfdad-ad-tile.tzfdad-is-off { background:@surf2@; }
.tzfd-screen .tzfdad-ad-tile.tzfdad-is-pro { background:@surf2@; }
.tzfd-screen .tzfdad-ad-tile.tzfdad-is-pro *, .tzfd-screen .tzfdad-ad-tile.tzfdad-is-pro p { color:@mut@; }
.tzfd-screen .tzfdad-ad-row { border-top-color:@line@; }
.tzfd-screen .tzfdad-pill { background:rgba(59,130,246,.18); color:#93c5fd; }
.tzfd-screen .tzfdad-pill-on { background:rgba(34,197,94,.16); color:#86efac; }
.tzfd-screen .tzfdad-pill-off, .tzfd-screen .tzfdad-pill-muted, .tzfd-screen .tzfdad-ad-badge { background:@surf2@; color:@mut@; }
.tzfd-screen .tzfdad-slider { background:#4a5366; }
.tzfd-screen .tzfdad-ad-back { color:@mut@; }
.tzfd-screen .tzfdad-addon-details summary { color:@ink@; }
.tzfd-screen .tzfd-help-wrap h2 { color:@ink@; border-bottom-color:@line@; }
.tzfd-screen .tzfd-help-search, .tzfd-screen .tzfd-help-section { background:@surf@; border-color:@line@; color:@ink@; }
.tzfd-screen .tzfd-help-search label, .tzfd-screen .tzfd-help-item summary { color:@ink@; }
.tzfd-screen .tzfd-help-empty { color:#fca5a5; }
.tzfd-screen .tzfd-help-item { border-bottom-color:@line@; }
.tzfd-screen .tzfd-help-item code { background:@surf2@; color:@ink@; }
.tzfd-screen .tzfd-help-footer { border-top-color:@line@; }
.tzfd-screen .tzfd-pwizard .tzfd-section-container { background:@surf@; border-color:@line@; }
.tzfd-screen .tzfd-wp-pagination { color:@mut@; }
.tzfd-screen .tzfd-wp-pagination .pagination-links .button { background:@surf@; border-color:@line@; color:@ink@; }
.tzfd-screen .tzfd-wp-pagination .pagination-links .button.disabled { background:@surf2@; color:@mut@; }
.tzfd-screen .tzfd-wp-pagination .pagination-links .button:hover:not(.disabled) { background:@surf2@; border-color:@red@; color:@red@; }
.tzfd-screen .tzfd-wp-pagination .paging-input input { background:@surf2@; color:@ink@; border-color:@line@; }
.tzfd-screen .tzfd-banner-warn { background:rgba(217,119,6,.12); color:@ink@; }
.tzfd-screen .tzfd-activity li { border-bottom-color:@line@; }
.tzfd-screen .tzfd-steps li::before { border-color:#4a5366; }
.tzfd-screen .tzfd-statssection-container, .tzfd-screen .tzfd-orderssection-container, .tzfd-screen .tzfd-graphs { background:@surf@; border-color:@line@; color:@ink@; }
.tzfd-screen .tzfd-statssection-header, .tzfd-screen .tzfd_field_header, .tzfd-screen .tzfd-breadcrumb { background:@surf2@; color:@ink@; border-color:@line@; }
.tzfd-screen .tzfd-orders-list thead { background:@surf2@; }
.tzfd-screen #dex_table_form tr, .tzfd-screen #dex_table_form td.tzfdmessage { background:@surf@; color:@ink@; }
.tzfd-screen .widefat, .tzfd-screen .wp-list-table { background:@surf@; color:@ink@; border-color:@line@; }
.tzfd-screen .widefat th, .tzfd-screen .widefat td { border-color:@line@; color:@ink@; }
.tzfd-screen .widefat thead th, .tzfd-screen .widefat tfoot th { background:@surf2@; color:@mut@; }
.tzfd-screen .widefat tr.alternate, .tzfd-screen .widefat tr.alternate td, .tzfd-screen .widefat tr:hover td { background:@surf2@; }
.tzfd-screen .notice:not(.notice-error):not(.notice-success), .tzfd-screen .notice-info { background:@surf@; color:@ink@; }
.tzfd-screen .notice-info p { color:@ink@; }
.tzfd-screen .tzfd-wizard-body { color:@ink@; }
.tzfd-screen .tzfd-wizard-body h2, .tzfd-screen .tzfd-wizard-body h3, .tzfd-screen .tzfd-wizard-body label { color:@ink@; }
.tzfd-screen .tzfd-wizard-body p, .tzfd-screen .tzfd-wizard-body .description { color:@mut@; }
.tzfd-screen .tzfd-setup-flow { background:transparent; }
.tzfd-screen h1, .tzfd-screen h2, .tzfd-screen h3, .tzfd-screen h4, .tzfd-screen .wrap h2, .tzfd-screen .form-table th, .tzfd-screen label { color:@ink@; }
.tzfd-screen p { color:@mut@; }
.tzfd-screen .wp-admin table.wp-list-table, .tzfd-screen table.wp-list-table.widefat, .tzfd-screen table.widefat.tzfd-orders-list { background:@surf@; color:@ink@; }
.tzfd-screen table.widefat.striped > tbody > tr:nth-child(odd), .tzfd-screen table.widefat.striped > tbody > tr:nth-child(odd) > th { background:@surf2@; }
.tzfd-screen table.widefat.striped > tbody > tr:nth-child(even) > td, .tzfd-screen table.widefat.striped > tbody > tr:nth-child(even) > th { background:@surf@; }
.tzfd-screen table.widefat tbody tr.tzfd_cancelled td { color:@mut@; }
.tzfd-screen #wpbody-content table.widefat, .tzfd-screen #wpbody-content table.wp-list-table { background-color:@surf@ !important; color:@ink@; }
.tzfd-screen #wpbody-content table.widefat thead th, .tzfd-screen #wpbody-content table.widefat tfoot th { background-color:@surf2@ !important; color:@mut@; }
.tzfd-screen .tablenav, .tzfd-screen .tablenav .tablenav-pages { background:transparent; color:@mut@; }
.tzfd-screen .tzfd-adintsection-container, .tzfd-screen .tzfdnoprint.tablenav { background:@surf@ !important; color:@ink@; border-color:@line@; }
.tzfd-screen #wpbody-content table.widefat tbody + tfoot td, .tzfd-screen #wpbody-content .tzfd-orders-list td[colspan] { background-color:@surf@ !important; }
.tzfd-screen #wpbody-content table.widefat tr.tzfd-orders-empty td, .tzfd-screen #wpbody-content .tzfd-orders-list tr.no-items td { background-color:@surf@ !important; }
.tzfd-screen .tzfd-setup-flow img, .tzfd-screen .tzfd-workflow img { border:1px solid @line@; }
', array( '@surf@' => $surf, '@surf2@' => $surf2, '@bg@' => $bg, '@dark@' => $dark, '@ink@' => $ink, '@line@' => $line, '@mut@' => $mut, '@red@' => $red, '@side@' => $side, '@sw@' => $sw ) );
    }

    /**
     * Styles for individual Front Desk screens (publish wizard, bookings list, add-ons, help). They are added
     * to the admin stylesheet with the shared styles, so no style element is printed in a page body.
     */
    public static function screen_css() {
        return <<<'CSS'
/* Publish a booking form (scoped to the wrapper in tzfd-publish-wizzard.inc.php) */
.tzfd-pwizard .tzfd-buttons-container{margin:1em 1em 1em 0;}
.tzfd-pwizard .tzfd-return-link{float:right;}
.tzfd-pwizard .tzfd-mssg{margin-left:0 !important; }
.tzfd-pwizard .tzfd-section-container {
	border: 1px solid #e6e6e6;
	padding:0px;
	border-radius: 3px;
	-webkit-box-flex: 1;
	flex: 1;
	margin: 1em 1em 1em 0;
	min-width: 200px;
	background: #ffffff;
	position:relative;
}
.tzfd-pwizard .tzfd-section{padding:20px;display:none;}
.tzfd-pwizard .tzfd-section label{font-weight:600;}
.tzfd-pwizard .tzfd-section-active{display:block;}

.tzfd-pwizard .tzfd-row{display:none;}
.tzfd-pwizard .tzfd-section table td,
.tzfd-pwizard .tzfd-section table th{padding-left:0;padding-right:0;}
.tzfd-pwizard .tzfd-section select,
.tzfd-pwizard .tzfd-section input[type="text"]{width:100%;}

.tzfd-pwizard .tzfdmvcontainer { font-size:16px !important; }
/* Bookings list pagination */
            /* Force exact standard WordPress List Table pagination styling to override plugin conflicts */
            .tzfd-wp-pagination { float: right; display: flex; align-items: center; margin-top: 3px; color: #3c434a; font-size: 13px; }
            .tzfd-wp-pagination .displaying-num { margin-right: 7px; }
            .tzfd-wp-pagination .pagination-links { display: flex; align-items: center; }
            .tzfd-wp-pagination .pagination-links .button { 
                margin: 0 2px; 
                padding: 0 4px; 
                min-width: 30px; 
                min-height: 30px; 
                line-height: 28px; 
                text-align: center; 
                display: inline-block;
                box-sizing: border-box;
                border: 1px solid #8c8f94;
                border-radius: 3px;
                background: #f6f7f7;
                color: #2271b1;
                text-decoration: none;
                font-weight: normal;
            }
            .tzfd-wp-pagination .pagination-links .button.disabled { border-color: #dcdcde; color: #a7aaad; background: #f6f7f7; cursor: default; }
            .tzfd-wp-pagination .pagination-links .button:hover:not(.disabled) { background: #f0f0f1; border-color: #0a4b78; color: #0a4b78; }
            .tzfd-wp-pagination .paging-input { margin: 0 4px; display: inline-flex; align-items: center; }
            .tzfd-wp-pagination .paging-input input { width: 40px !important; margin: 0 4px; text-align: center; border: 1px solid #8c8f94; border-radius: 3px; line-height: 2; min-height: 30px; padding: 0 4px; color: #2c3338; }
        
/* Add-ons */
	.tzfdad-ad-page { max-width: 1100px; margin: 12px 20px 40px 0; color: #1d2327; }
	.tzfdad-ad-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
	.tzfdad-ad-head h1 { margin: 0 0 4px; font-size: 26px; }
	.tzfdad-ad-sub { margin: 0; color: #50575e; }
	.tzfdad-ad-card { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 20px; margin: 0 0 18px; }
	.tzfdad-ad-sechead { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
	.tzfdad-ad-sechead h2 { margin: 0 0 4px; font-size: 18px; }
	.tzfdad-ad-sechead p { margin: 0; color: #50575e; }
	.tzfdad-pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; background: #e8f0fe; color: #1a4fa0; white-space: nowrap; }
	.tzfdad-pill-on { background: #e7f6ec; color: #17653a; }
	.tzfdad-pill-off { background: #f0f0f1; color: #50575e; }
	.tzfdad-pill-muted { background: #f0f0f1; color: #646970; }
	.tzfdad-ad-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
	.tzfdad-ad-tile { border: 1px solid #dcdcde; border-radius: 10px; padding: 16px; display: flex; flex-direction: column; gap: 10px; background: #fff; }
	.tzfdad-ad-tile.tzfdad-is-off { background: #fafafa; }
	.tzfdad-ad-tile.tzfdad-is-pro { background: #f6f7f7; color: #8c8f94; }
	.tzfdad-ad-tile.tzfdad-is-pro * { color: #8c8f94; }
	.tzfdad-ad-tile-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
	.tzfdad-ad-tile h3 { margin: 0; font-size: 15px; line-height: 1.3; }
	.tzfdad-ad-tile p { margin: 0; color: #50575e; font-size: 13px; line-height: 1.5; }
	.tzfdad-ad-badges { display: flex; flex-wrap: wrap; gap: 6px; }
	.tzfdad-ad-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; background: #f0f0f1; color: #3c434a; }
	.tzfdad-ad-tile-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: auto; }
	.tzfdad-ad-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 0; border-top: 1px solid #f0f0f1; }
	.tzfdad-ad-row:first-child { border-top: 0; }
	.tzfdad-ad-row h3 { margin: 0 0 4px; font-size: 14px; }
	.tzfdad-ad-row p { margin: 0; color: #50575e; font-size: 13px; }
	.tzfdad-ad-row-side { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
	.tzfdad-switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; }
	.tzfdad-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
	.tzfdad-slider { position: absolute; inset: 0; background: #c3c4c7; border-radius: 24px; cursor: pointer; transition: background .15s; }
	.tzfdad-slider:before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
	.tzfdad-switch input:checked + .tzfdad-slider { background: #2271b1; }
	.tzfdad-switch input:checked + .tzfdad-slider:before { transform: translateX(18px); }
	.tzfdad-switch input:focus-visible + .tzfdad-slider { outline: 2px solid #2271b1; outline-offset: 2px; }
	.tzfdad-switch input:disabled + .tzfdad-slider { opacity: .45; cursor: not-allowed; }
	.tzfdad-btn-primary { background: #d63638 !important; border-color: #b32d2e !important; color: #fff !important; box-shadow: none !important; text-shadow: none !important; }
	.tzfdad-btn-primary:hover { background: #b32d2e !important; }
	.tzfdad-ad-foot { color: #646970; font-size: 12px; margin: 6px 0 14px; }
	.tzfdad-ad-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
	.tzfdad-ad-back { text-decoration: none; }
	.tzfdad-addon-details { margin: 8px 0; border: 1px solid #e2e2e2; padding: 8px 12px; background: #fff; border-radius: 6px; max-width: 900px; }
	.tzfdad-addon-details summary { cursor: pointer; font-weight: 600; }
	.tzfdad-ad-subnote { color: #646970; font-size: 12px; margin: 0 0 8px; }
	@media (max-width: 782px) { .tzfdad-ad-page { margin-right: 16px; } .tzfdad-ad-row { flex-direction: column; align-items: flex-start; } }
/* Help and FAQ */
    .tzfd-help-wrap { max-width: 900px; }
    .tzfd-help-wrap h2 { margin-top: 1.6em; padding-bottom: 8px; border-bottom: 1px solid #ccd0d4; }
    .tzfd-help-search { padding: 16px 20px; margin-top: 20px; max-width: 900px; }
    .tzfd-help-search label { display: block; font-weight: 600; margin-bottom: 8px; }
    .tzfd-help-search input[type=search] { width: 100%; max-width: 420px; }
    .tzfd-help-empty { font-weight: 600; color: #8a2424; }
    .tzfd-help-toc ul { display: flex; flex-wrap: wrap; gap: 8px 16px; margin: 16px 0; }
    .tzfd-help-toc li { margin: 0; }
    .tzfd-help-section { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 8px 20px; margin-bottom: 16px; }
    .tzfd-help-item { border-bottom: 1px solid #f0f0f1; padding: 10px 0; }
    .tzfd-help-item:last-child { border-bottom: 0; }
    .tzfd-help-item summary { cursor: pointer; font-weight: 600; line-height: 1.5; font-size: 15px; }
    .tzfd-help-answer, .tzfd-help-item ol { margin: 8px 0 4px 18px; line-height: 1.6; }
    .tzfd-help-item code { background: #f6f7f7; padding: 1px 4px; border-radius: 3px; word-break: break-word; }
    .tzfd-help-table td, .tzfd-help-table th { vertical-align: top; }
    .tzfd-help-table tr.tzfd-help-item { border-bottom: 0; padding: 0; }
    .tzfd-help-hidden { display: none !important; }
    .tzfd-help-footer { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid #ccd0d4; }
    @media (max-width: 782px) { .tzfd-help-wrap { padding-right: 8px; } .tzfd-help-search input[type=search] { max-width: 100%; } }
CSS;
    }
}
