<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-request-value.php';


/**
 * Standalone Front Desk administration presentation.
 * Native WordPress menus and page rendering need no other plugin.
 */
final class TZFD_Admin_Layout {
    /** Versioned contract for optional Workspace companion plugins. */
    const EXTENSION_API_VERSION = 1;

    public static function register() {
        add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'all_admin_notices', array( __CLASS__, 'official_brand_header' ), 0 );
        // Independent Front Desk navigation is registered by TZFD_Admin_Menu.
        // Do not duplicate its links in Workspace's module menu.
    }

    /** One official brand banner for all Front Desk administration routes. */
    public static function official_brand_header() {
        static $rendered = false;
        if ( $rendered || ! self::is_front_desk_screen() || ! current_user_can( 'tz_fd_access' ) ) { return; }
        $rendered = true;
        $url = plugin_dir_url( dirname( __FILE__ ) ) . 'images/brand/branding/titan-zero-official-transparent.png';
        echo '<div class="tzfd-global-brand" role="banner"><img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Titan Zero — Business Operating System', 'titan-zero-trades-front-desk' ) . '" width="290" height="97" decoding="async"></div>';
    }

    /** True on any Front Desk admin screen (the menu slug starts with tzfd, or is the Front Desk slug). */
    public static function is_front_desk_screen() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( '' === $page ) { return false; }
        // The native Customers route is intentionally not prefixed tzfd.
        // Keep this explicit: do not apply Front Desk branding to other plugins.
        if ( 'tz-customers' === $page ) { return true; }
        if ( 'tz_pipeline' === $page ) { return true; }
        return 0 === strpos( $page, 'tzfd' ) || 0 === strpos( $page, 'tz_fd_' ) || 0 === strpos( $page, 'titan-zero-trades-front-desk' );
    }

    public static function body_class( $classes ) {
        return self::is_front_desk_screen() ? $classes . ' tzfd-screen' : $classes;
    }

    public static function enqueue() {
        if ( ! self::is_front_desk_screen() ) { return; }
        wp_register_style( 'tzfd-standalone-admin', false, array(), defined( 'TZFD_VERSION' ) ? TZFD_VERSION : null );
        wp_enqueue_style( 'tzfd-standalone-admin' );
        wp_add_inline_style( 'tzfd-standalone-admin', self::css() . "\n" . self::screen_css() );
        wp_enqueue_style( 'tzfd-admin-consolidated', plugin_dir_url( dirname( __FILE__ ) ) . 'css/tzfd-admin-consolidated.css', array( 'tzfd-standalone-admin' ), defined( 'TZFD_VERSION' ) ? TZFD_VERSION : null );
    }

    /** The sidebar section for the current screen. */
    /**
     * Page header for Front Desk screens. Drawn by the shell: title, a one-line lead and the page actions.
     * $actions is trusted HTML built with esc_* functions by the caller, and is filtered here.
     */
    public static function header( $title, $lead = '', $actions = '', $brand = '' ) {
        echo '<div class="tzfd-page-head"><div><h1>';
        if ( class_exists( 'TZFD_UI_Graphics' ) && '' !== TZFD_UI_Graphics::brand_icon( $brand ) ) {
            echo wp_kses( TZFD_UI_Graphics::icon_html( TZFD_UI_Graphics::brand_icon( $brand ), 32 ), TZFD_UI_Graphics::allowed_html() );
        }
        echo esc_html( $title ) . '</h1>';
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
     * Stat tile for a page, drawn by the shell. $accent is red, blue, amber, green or ink.
     * $sub is folded into the label, because the shell tile has one caption. $href may be '' for a plain tile.
     */
    public static function stat( $value, $label, $sub = '', $accent = 'red', $href = '' ) {
        $caption = trim( $label . ( '' !== $sub ? ' · ' . $sub : '' ) );
        $tile = '<span class="tzfd-stat-l">' . esc_html( $caption ) . '</span> <span class="tzfd-stat-n">' . esc_html( (string) $value ) . '</span>';
        if ( '' !== $href ) { echo '<a class="tzfd-stat-link" href="' . esc_url( $href ) . '">' . wp_kses_post( $tile ) . '</a>'; }
        else { echo '<div class="tzfd-stat-tile">' . wp_kses_post( $tile ) . '</div>'; }
    }

    /** Notice by key. Does nothing: notices stay WordPress-owned. */
    public static function notice( $key ) {
        // Notices remain WordPress-owned; do not depend on the suite shell.
    }

    /* ---------------------------------------------------------------
     * Shared stylesheet
     * ------------------------------------------------------------- */

    /** Front Desk screen styles. Colours are the shared tokens from the shell (--tz-*); fallbacks are the dark palette. */
    public static function css() {
        return '/* Page header */
.tzfd-screen .tzfd-page { max-width:1180px; }
.tzfd-screen .tzfd-page > h1, .tzfd-screen .tzfd-page-head h1 { font-size:26px; font-weight:700; color:var(--tz-text,#f2f4f8); margin:0; padding:0; line-height:1.2; }
.tzfd-screen .tzfd-page-head { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px 16px; margin:14px 0 18px; }
.tzfd-screen .tzfd-page-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.tzfd-screen .tzfd-lead { color:var(--tz-muted,#e0e6ef); font-size:14px; margin:4px 0 0; }

/* Cards and sections */
.tzfd-screen .tzfd-card { background:var(--tz-panel,#232834); border:1px solid var(--tz-border,#333a48); border-radius:10px; padding:18px 20px; margin:0 0 16px; box-shadow:0 1px 2px rgba(16,24,40,.04); }
.tzfd-screen .tzfd-card h2, .tzfd-screen .tzfd-card h3 { margin-top:0; }
.tzfd-screen .tzfd-card-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px 16px; margin:0 0 12px; }
.tzfd-screen .tzfd-card-head h2, .tzfd-screen .tzfd-card-head h3 { margin:0; font-size:16px; font-weight:600; color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-section-title { font-size:16px; font-weight:600; color:var(--tz-text,#f2f4f8); margin:20px 0 8px; }
.tzfd-screen .tzfd-muted { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-foot-card { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px 16px; }
.tzfd-screen .tzfd-foot-card p { margin:0; color:var(--tz-muted,#e0e6ef); }

/* Buttons: primary is brand red, secondary is white with a light border */
.tzfd-screen .tzfd-btn { display:inline-flex; align-items:center; gap:6px; min-height:34px; line-height:32px; padding:0 14px; border-radius:8px; border:1px solid var(--tz-border,#333a48); background:var(--tz-panel,#232834); color:var(--tz-text,#f2f4f8); text-decoration:none; font-size:13px; font-weight:500; cursor:pointer; vertical-align:middle; box-sizing:border-box; }
.tzfd-screen .tzfd-btn:hover, .tzfd-screen .tzfd-btn:focus { border-color:var(--tz-accent,#E31B23); color:var(--tz-accent,#E31B23); }
.tzfd-screen .tzfd-btn-primary, .tzfd-screen .button-primary, .tzfd-screen input[type="submit"].button-primary { background:var(--tz-accent,#E31B23) !important; border-color:var(--tz-accent,#E31B23) !important; color:var(--tz-on-accent,#fff) !important; box-shadow:none !important; text-shadow:none; }
.tzfd-screen .tzfd-btn-primary:hover, .tzfd-screen .tzfd-btn-primary:focus, .tzfd-screen .button-primary:hover, .tzfd-screen .button-primary:focus { background:var(--tz-accent-hover,#B81219) !important; border-color:var(--tz-accent-hover,#B81219) !important; color:var(--tz-on-accent,#fff) !important; }
.tzfd-screen .tzfd-btn-secondary { background:var(--tz-panel,#232834); }
.tzfd-screen .tzfd-btn-danger { color:var(--tz-accent,#E31B23); border-color:var(--tz-accent,#E31B23); background:var(--tz-panel,#232834); }
.tzfd-screen .tzfd-btn-danger:hover { background:var(--tz-accent,#E31B23); color:var(--tz-on-accent,#fff); }
.tzfd-screen .tzfd-btn[aria-current="page"] { background:var(--tz-accent,#E31B23); border-color:var(--tz-accent,#E31B23); color:var(--tz-on-accent,#fff); }
.tzfd-screen .tzfd-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0; }

/* Forms: two columns on wide screens, one column on phones */
.tzfd-screen .tzfd-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px 18px; }
.tzfd-screen .tzfd-form-grid .tzfd-full { grid-column:1 / -1; }
.tzfd-screen .tzfd-form-grid label { display:block; font-weight:600; margin:0 0 4px; color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-form-grid input[type="text"], .tzfd-screen .tzfd-form-grid input[type="email"], .tzfd-screen .tzfd-form-grid input[type="tel"],
.tzfd-screen .tzfd-form-grid input[type="number"], .tzfd-screen .tzfd-form-grid select, .tzfd-screen .tzfd-form-grid textarea { width:100%; max-width:none; }
.tzfd-screen .tzfd-form-grid .description { color:var(--tz-muted,#e0e6ef); margin:4px 0 0; }
@media screen and (max-width:782px) { .tzfd-screen .tzfd-form-grid { grid-template-columns:1fr; } }

/* Legacy and native controls need explicit high-contrast colors on the dark Front Desk canvas. */
.tzfd-screen #wpbody-content .wrap { color:var(--tz-text,#f2f4f8); }
.tzfd-screen #wpbody-content .wrap label, .tzfd-screen #wpbody-content .wrap legend { color:var(--tz-text,#f2f4f8) !important; opacity:1 !important; }
.tzfd-screen #wpbody-content .wrap p, .tzfd-screen #wpbody-content .wrap .description { color:#d4d9e2 !important; opacity:1 !important; }
.tzfd-screen #wpbody-content .wrap small, .tzfd-screen #wpbody-content .wrap .tzfdappsoft, .tzfd-screen #wpbody-content .wrap .tzfd-step *:not(i) { color:var(--tz-muted,#e0e6ef) !important; opacity:1 !important; }
.tzfd-screen #wpbody-content .wrap .notice-success p, .tzfd-screen #wpbody-content .wrap .notice-error p, .tzfd-screen #wpbody-content .wrap .notice-warning p { color:var(--tz-text,#edf7ff) !important; }
.tzfd-screen #wpbody-content .wrap .notice-info p { color:var(--tz-text,#f2f4f8) !important; }
.tzfd-screen #wpbody-content .wrap fieldset { border-color:#737e92 !important; }
.tzfd-screen #wpbody-content .wrap input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]),
.tzfd-screen #wpbody-content .wrap select, .tzfd-screen #wpbody-content .wrap textarea {
    background:var(--tz-field,#10294b) !important; color:var(--tz-text,#edf7ff) !important; border-color:var(--tz-border,#284867) !important; opacity:1 !important; color-scheme:dark;
}
.tzfd-screen #wpbody-content .wrap select option { background:var(--tz-field,#10294b) !important; color:var(--tz-text,#edf7ff) !important; }
.tzfd-screen #wpbody-content .wrap input::placeholder, .tzfd-screen #wpbody-content .wrap textarea::placeholder { color:var(--tz-muted,#b5c8dc) !important; opacity:1 !important; }
.tzfd-screen #wpbody-content .wrap input:disabled, .tzfd-screen #wpbody-content .wrap select:disabled, .tzfd-screen #wpbody-content .wrap textarea:disabled {
    background:#f0f0f1 !important; color:#50575e !important; border-color:#8c8f94 !important; opacity:1 !important;
}

/* Filter bar: search, selects and buttons on one row */
.tzfd-screen .tzfd-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 12px; }
.tzfd-screen .tzfd-filters input[type="search"], .tzfd-screen .tzfd-filters select { border:1px solid var(--tz-border,#333a48); border-radius:8px; min-height:34px; background:var(--tz-panel,#232834); font-size:13px; box-sizing:border-box; }
.tzfd-screen .tzfd-filters input[type="search"] { min-width:min(280px,100%); }
.tzfd-screen .tzfd-filters .tzfd-filter-spacer { flex:1; }

/* Tabs: underlined, active tab in brand red */
.tzfd-screen .nav-tab-wrapper, .tzfd-screen .tzfd-tabs { border-bottom:1px solid var(--tz-border,#333a48); margin:0 0 16px; padding:0; display:flex; flex-wrap:wrap; gap:4px 22px; }
.tzfd-screen .nav-tab-wrapper .nav-tab, .tzfd-screen .tzfd-tabs a { color:var(--tz-muted,#e0e6ef); background:transparent; border:0; border-bottom:2px solid transparent; margin:0 0 -1px; padding:8px 2px 10px; font-size:14px; text-decoration:none; }
.tzfd-screen .nav-tab-wrapper .nav-tab:hover, .tzfd-screen .tzfd-tabs a:hover { color:var(--tz-accent,#E31B23); }
.tzfd-screen .nav-tab-wrapper .nav-tab-active, .tzfd-screen .nav-tab-wrapper .nav-tab-active:hover, .tzfd-screen .tzfd-tabs a.is-active { color:var(--tz-accent,#E31B23); border-bottom-color:var(--tz-accent,#E31B23); font-weight:600; background:transparent; }

/* Tables: scroll sideways on small screens rather than squashing */
.tzfd-screen .tzfd-table-wrap { overflow-x:auto; padding:0; }
.tzfd-screen .tzfd-table { border:0; box-shadow:none; width:100%; margin:0; }
.tzfd-screen .tzfd-table th, .tzfd-screen .tzfd-table td { text-align:left; }
.tzfd-screen .tzfd-cell-clip { display:inline-block; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle; }
.tzfd-screen .tzfd-table thead th { font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--tz-muted,#e0e6ef); font-weight:600; background:var(--tz-panel,#232834); border-bottom:1px solid var(--tz-border,#333a48); padding:10px 12px; }
.tzfd-screen .tzfd-table td { padding:12px; vertical-align:middle; }
.tzfd-screen .tzfd-table tbody tr:nth-child(odd) td { background:var(--tz-panel,#232834); }
.tzfd-screen .tzfd-table tbody tr:nth-child(even) td { background:var(--tz-field,#1f232d); }
.tzfd-screen .tzfd-table a.tzfd-open { color:var(--tz-accent,#E31B23); font-weight:500; text-decoration:none; }
.tzfd-screen .tzfd-pager { display:flex; justify-content:flex-end; gap:6px; align-items:center; margin:12px 0 0; color:var(--tz-muted,#e0e6ef); font-size:13px; }

/* Status badges and booking chips */
.tzfd-screen .tzfd-badge, .tzfd-screen .tzfd-chip { display:inline-block; border-radius:999px; padding:1px 9px; font-size:12px; line-height:19px; font-weight:500; white-space:nowrap; text-decoration:none; background:var(--tz-field,#1f232d); color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-badge-confirmed, .tzfd-screen .tzfd-chip-confirmed, .tzfd-screen .tzfd-badge-accepted, .tzfd-screen .tzfd-badge-active, .tzfd-screen .tzfd-badge-published, .tzfd-screen .tzfd-badge-available { background:#e6f6ec; color:#17693a; }
.tzfd-screen .tzfd-badge-pending, .tzfd-screen .tzfd-chip-pending, .tzfd-screen .tzfd-badge-awaiting, .tzfd-screen .tzfd-badge-followup, .tzfd-screen .tzfd-badge-warn { background:#fff4dc; color:#8a5300; }
.tzfd-screen .tzfd-badge-sent, .tzfd-screen .tzfd-badge-attended, .tzfd-screen .tzfd-chip-attended, .tzfd-screen .tzfd-badge-admin { background:#e8f0fb; color:#1f4f8f; }
.tzfd-screen .tzfd-badge-declined, .tzfd-screen .tzfd-badge-cancelled-red, .tzfd-screen .tzfd-badge-live { background:#fde8e9; color:#b3202a; }
.tzfd-screen .tzfd-badge-draft, .tzfd-screen .tzfd-badge-cancelled, .tzfd-screen .tzfd-chip-cancelled, .tzfd-screen .tzfd-badge-expired, .tzfd-screen .tzfd-badge-other, .tzfd-screen .tzfd-chip-other { background:var(--tz-field,#1f232d); color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-badge-cancelled, .tzfd-screen .tzfd-chip-cancelled { text-decoration:line-through; }
.tzfd-screen .tzfd-badge-live { font-weight:700; letter-spacing:.04em; }

/* Calendar */
.tzfd-screen .tzfd-cal-toolbar { display:flex; flex-wrap:wrap; gap:10px 16px; align-items:center; margin:0 0 14px; }
.tzfd-screen .tzfd-cal-nav { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.tzfd-screen .tzfd-cal-title { margin-left:8px; font-size:16px; font-weight:600; color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-cal-views { display:flex; gap:0; }
.tzfd-screen .tzfd-cal-views .tzfd-btn { border-radius:0; margin-left:-1px; }
.tzfd-screen .tzfd-cal-views .tzfd-btn:first-child { border-radius:8px 0 0 8px; margin-left:0; }
.tzfd-screen .tzfd-cal-views .tzfd-btn:last-child { border-radius:0 8px 8px 0; }
.tzfd-screen .tzfd-cal-filter { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:0; }
.tzfd-screen .tzfd-cal-scroll { overflow-x:auto; padding:0; background:var(--tz-panel,#232834); border:1px solid var(--tz-border,#333a48); border-radius:10px; }
.tzfd-screen .tzfd-cal-grid { border-collapse:collapse; width:100%; min-width:640px; table-layout:fixed; }
.tzfd-screen .tzfd-cal-grid th, .tzfd-screen .tzfd-cal-grid td { border:1px solid #eef0f3; vertical-align:top; padding:6px; text-align:left; }
.tzfd-screen .tzfd-cal-grid th { background:var(--tz-field,#1f232d); font-size:12px; color:var(--tz-muted,#e0e6ef); font-weight:600; }
.tzfd-screen .tzfd-cal-grid th a { color:inherit; text-decoration:none; }
.tzfd-screen .tzfd-cal-week td { height:180px; }
.tzfd-screen .tzfd-cal-month td { height:104px; }
.tzfd-screen .tzfd-cal-out { background:var(--tz-field,#1f232d); }
.tzfd-screen .tzfd-cal-out .tzfd-cal-daynum { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-cal-today { background:#2b1d21; }
.tzfd-screen .tzfd-cal-today .tzfd-cal-daynum, .tzfd-screen th.tzfd-cal-today a { color:var(--tz-accent,#E31B23); font-weight:700; }
.tzfd-screen .tzfd-cal-daynum { display:inline-block; font-weight:600; color:var(--tz-text,#f2f4f8); text-decoration:none; margin-bottom:4px; }
.tzfd-screen .tzfd-cal-grid .tzfd-chip { display:block; max-width:100%; overflow:hidden; text-overflow:ellipsis; margin:0 0 4px; border-radius:6px; padding:3px 8px; }
.tzfd-screen .tzfd-cal-more { display:block; font-size:12px; color:var(--tz-accent,#E31B23); }
.tzfd-screen .tzfd-svc-1 { background:#e8f0fb !important; color:#1f4f8f !important; }
.tzfd-screen .tzfd-svc-2 { background:#e6f6ec !important; color:#17693a !important; }
.tzfd-screen .tzfd-svc-3 { background:#fff4dc !important; color:#8a5300 !important; }
.tzfd-screen .tzfd-svc-4 { background:#f1e9fb !important; color:#5b2d9b !important; }
@media screen and (max-width:782px) { .tzfd-screen .tzfd-cal-title { margin-left:0; width:100%; } }

/* Customer details and search */
.tzfd-screen .tzfd-dl { display:grid; grid-template-columns:minmax(120px,max-content) 1fr; gap:8px 18px; margin:0; }
.tzfd-screen .tzfd-dl > span:nth-child(odd) { color:var(--tz-muted,#e0e6ef); font-size:13px; padding-top:2px; }
.tzfd-screen .tzfd-dl > span:nth-child(even) { color:var(--tz-text,#f2f4f8); word-break:break-word; }
.tzfd-screen .tzfd-search { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:0; }
.tzfd-screen .tzfd-search input[type="search"] { min-width:min(320px,100%); }
.tzfd-screen .tzfd-actions form { margin:0; }
@media screen and (max-width:782px) { .tzfd-screen .tzfd-dl { grid-template-columns:1fr; gap:2px 0; } .tzfd-screen .tzfd-dl > span:nth-child(odd) { padding-top:8px; } }

/* Stat tiles: label, number, then a short line. Accent colour on the left edge. */
.tzfd-screen .tzfd-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin:0 0 16px; }
.tzfd-screen .tzfd-stat { display:flex; flex-direction:column; background:var(--tz-panel,#232834); border:1px solid var(--tz-border,#333a48); border-left:4px solid var(--tz-accent,#E31B23); border-radius:10px; padding:14px 16px; color:inherit; text-decoration:none; transition:border-color .15s, box-shadow .15s; }
.tzfd-screen .tzfd-stat--blue { border-left-color:#2563eb; }
.tzfd-screen .tzfd-stat--amber { border-left-color:#d97706; }
.tzfd-screen .tzfd-stat--green { border-left-color:#16a34a; }
.tzfd-screen .tzfd-stat--ink { border-left-color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-stat-l { order:0; font-size:12px; color:var(--tz-muted,#e0e6ef); text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
.tzfd-screen .tzfd-stat-n { order:1; font-size:28px; font-weight:700; line-height:1.2; color:var(--tz-text,#f2f4f8); margin:6px 0 2px; }
.tzfd-screen .tzfd-stat-s { order:2; font-size:12px; color:var(--tz-muted,#e0e6ef); }
.tzfd-screen a.tzfd-stat:hover, .tzfd-screen a.tzfd-stat:focus { border-color:var(--tz-accent,#E31B23); box-shadow:0 2px 8px rgba(227,27,35,.12); color:inherit; }
.tzfd-screen a.tzfd-stat:hover .tzfd-stat-n { color:var(--tz-accent,#E31B23); }

/* Dashboard notices: one button per line on phones and in a row on wider screens */
.tzfd-screen .tzfd-notice-actions { display:flex; flex-wrap:wrap; gap:8px; margin:8px 0 4px; }
.tzfd-screen .tzfd-notice-actions .button { margin:0; }
.tzfd-screen .tzfd-notice p { margin:6px 0; }
.tzfd-screen .tzfd-live { display:inline-block; background:#fde8e9; color:#b3202a; font-size:11px; font-weight:700; letter-spacing:.06em; border-radius:999px; padding:1px 8px; }
.tzfd-screen .tzfd-bar { height:6px; background:var(--tz-field,#1f232d); border-radius:999px; overflow:hidden; }
.tzfd-screen .tzfd-bar > span { display:block; height:100%; background:var(--tz-accent,#E31B23); border-radius:999px; }

/* Dashboard: welcome card, two columns, activity and quick actions */
.tzfd-screen .tzfd-welcome { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px 20px; }
.tzfd-screen .tzfd-welcome-main { flex:1 1 360px; min-width:0; }
.tzfd-screen .tzfd-welcome h2 { font-size:20px; font-weight:700; color:var(--tz-text,#f2f4f8); margin:0 0 4px; }
.tzfd-screen .tzfd-welcome .tzfd-bar { max-width:520px; margin:12px 0 4px; }
.tzfd-screen .tzfd-progress-text { margin:0 0 10px; font-size:13px; }
.tzfd-screen .tzfd-steps { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:6px 16px; font-size:13px; color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-steps li { padding-left:22px; position:relative; }
.tzfd-screen .tzfd-steps li::before { content:""; position:absolute; left:0; top:5px; width:12px; height:12px; border-radius:50%; border:1.5px solid #c3c6cc; box-sizing:border-box; }
.tzfd-screen .tzfd-steps li.is-done { color:var(--tz-text,#f2f4f8); }
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
.tzfd-screen .tzfd-activity span, .tzfd-screen .tzfd-activity small { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-activity small { font-size:12px; }
.tzfd-screen .tzfd-quick { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }

/* Shared card pieces */
.tzfd-screen .tzfd-card-title { font-size:16px; font-weight:600; color:var(--tz-text,#f2f4f8); margin:0 0 4px; }
.tzfd-screen .tzfd-card-title .tzfd-badge { margin-left:6px; vertical-align:middle; }
.tzfd-screen .tzfd-tip { margin:0; color:var(--tz-muted,#e0e6ef); font-size:13px; }
.tzfd-screen .tzfd-tip strong { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-list-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px 16px; }
.tzfd-screen .tzfd-banner { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px 16px; margin-bottom:16px; }
.tzfd-screen .tzfd-banner p { margin:4px 0 0; }
.tzfd-screen .tzfd-banner-warn { border-left:4px solid #d97706; background:#fffaf0; }

/* Calendar toolbar: navigation on the left, filter, views and manage on the right */
.tzfd-screen .tzfd-cal-toolbar { justify-content:space-between; }
.tzfd-screen .tzfd-cal-right { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.tzfd-screen .tzfd-cal-step { min-width:34px; text-align:center; font-size:16px; line-height:30px; padding:0; }
.tzfd-screen .tzfd-cal-filter select { min-height:34px; border:1px solid var(--tz-border,#333a48); border-radius:8px; background:var(--tz-panel,#232834); }
.tzfd-screen .tzfd-cal-more { border-style:dashed; }
/* Dark content area: the page, top bar and banner use the sidebar colour; cards and fields sit one step lighter */
.tzfd-screen .tzfd-card, .tzfd-screen .tzfd-stat, .tzfd-screen .tzfd-cal-scroll { background:var(--tz-panel,#232834); }
.tzfd-screen input[type="text"], .tzfd-screen input[type="search"], .tzfd-screen input[type="email"], .tzfd-screen input[type="tel"],
.tzfd-screen input[type="number"], .tzfd-screen input[type="date"], .tzfd-screen input[type="time"], .tzfd-screen input[type="password"],
.tzfd-screen select, .tzfd-screen textarea { background:var(--tz-field,#1f232d); color:var(--tz-text,#f2f4f8); border-color:var(--tz-border,#333a48); }
.tzfd-screen input::placeholder, .tzfd-screen textarea::placeholder { color:var(--tz-muted,#e0e6ef); opacity:1; }
.tzfd-screen .description, .tzfd-screen .tzfd-muted, .tzfd-screen .tzfd-lead { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-btn-secondary { background:var(--tz-panel,#232834); color:var(--tz-text,#f2f4f8); border-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-round { background:var(--tz-panel,#232834); color:var(--tz-text,#f2f4f8); border-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-table thead th { background:var(--tz-field,#1f232d); color:var(--tz-muted,#e0e6ef); border-bottom-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-table td, .tzfd-screen .tzfd-table th { border-color:var(--tz-border,#333a48); }
/* Add-ons, Help and the publish wizard: the same dark surfaces as the rest of Front Desk */
.tzfd-screen .tzfdad-ad-page { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfdad-ad-sub, .tzfd-screen .tzfdad-ad-sechead p, .tzfd-screen .tzfdad-ad-tile p, .tzfd-screen .tzfdad-ad-row p, .tzfd-screen .tzfdad-ad-foot, .tzfd-screen .tzfdad-ad-subnote { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfdad-ad-head h1, .tzfd-screen .tzfdad-ad-sechead h2, .tzfd-screen .tzfdad-ad-tile h3, .tzfd-screen .tzfdad-ad-row h3 { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfdad-ad-card, .tzfd-screen .tzfdad-ad-tile, .tzfd-screen .tzfdad-addon-details { background:var(--tz-panel,#232834); border-color:var(--tz-border,#333a48); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfdad-ad-tile.tzfdad-is-off { background:var(--tz-field,#1f232d); }
.tzfd-screen .tzfdad-ad-tile.tzfdad-is-pro { background:var(--tz-field,#1f232d); }
.tzfd-screen .tzfdad-ad-tile.tzfdad-is-pro *, .tzfd-screen .tzfdad-ad-tile.tzfdad-is-pro p { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfdad-ad-row { border-top-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfdad-pill { background:rgba(59,130,246,.18); color:#93c5fd; }
.tzfd-screen .tzfdad-pill-on { background:rgba(34,197,94,.16); color:#86efac; }
.tzfd-screen .tzfdad-pill-off, .tzfd-screen .tzfdad-pill-muted, .tzfd-screen .tzfdad-ad-badge { background:var(--tz-field,#1f232d); color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfdad-slider { background:#4a5366; }
.tzfd-screen .tzfdad-ad-back { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfdad-addon-details summary { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-help-wrap h2 { color:var(--tz-text,#f2f4f8); border-bottom-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-help-search, .tzfd-screen .tzfd-help-section { background:var(--tz-panel,#232834); border-color:var(--tz-border,#333a48); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-help-search label, .tzfd-screen .tzfd-help-item summary { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-help-empty { color:#fca5a5; }
.tzfd-screen .tzfd-help-item { border-bottom-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-help-item code { background:var(--tz-field,#1f232d); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-help-footer { border-top-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-pwizard .tzfd-section-container { background:var(--tz-panel,#232834); border-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-wp-pagination { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-wp-pagination .pagination-links .button { background:var(--tz-panel,#232834); border-color:var(--tz-border,#333a48); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-wp-pagination .pagination-links .button.disabled { background:var(--tz-field,#1f232d); color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-wp-pagination .pagination-links .button:hover:not(.disabled) { background:var(--tz-field,#1f232d); border-color:var(--tz-accent,#E31B23); color:var(--tz-accent,#E31B23); }
.tzfd-screen .tzfd-wp-pagination .paging-input input { background:var(--tz-field,#1f232d); color:var(--tz-text,#f2f4f8); border-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-banner-warn { background:rgba(217,119,6,.12); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-activity li { border-bottom-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-steps li::before { border-color:#4a5366; }
.tzfd-screen .tzfd-statssection-container, .tzfd-screen .tzfd-orderssection-container, .tzfd-screen .tzfd-graphs { background:var(--tz-panel,#232834); border-color:var(--tz-border,#333a48); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-statssection-header, .tzfd-screen .tz_fd_field_header, .tzfd-screen .tzfd-breadcrumb { background:var(--tz-field,#1f232d); color:var(--tz-text,#f2f4f8); border-color:var(--tz-border,#333a48); }
.tzfd-screen .tzfd-orders-list thead { background:var(--tz-field,#1f232d); }
.tzfd-screen #dex_table_form tr, .tzfd-screen #dex_table_form td.tzfdmessage { background:var(--tz-panel,#232834); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .widefat, .tzfd-screen .wp-list-table { background:var(--tz-panel,#232834); color:var(--tz-text,#f2f4f8); border-color:var(--tz-border,#333a48); }
.tzfd-screen .widefat th, .tzfd-screen .widefat td { border-color:var(--tz-border,#333a48); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .widefat thead th, .tzfd-screen .widefat tfoot th { background:var(--tz-field,#1f232d); color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .widefat tr.alternate, .tzfd-screen .widefat tr.alternate td, .tzfd-screen .widefat tr:hover td { background:var(--tz-field,#1f232d); }
.tzfd-screen .notice:not(.notice-error):not(.notice-success), .tzfd-screen .notice-info { background:var(--tz-panel,#232834); color:var(--tz-text,#f2f4f8); }
.tzfd-screen .notice-info p { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-wizard-body { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-wizard-body h2, .tzfd-screen .tzfd-wizard-body h3, .tzfd-screen .tzfd-wizard-body label { color:var(--tz-text,#f2f4f8); }
.tzfd-screen .tzfd-wizard-body p, .tzfd-screen .tzfd-wizard-body .description { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-setup-flow { background:transparent; }
.tzfd-screen h1, .tzfd-screen h2, .tzfd-screen h3, .tzfd-screen h4, .tzfd-screen .wrap h2, .tzfd-screen .form-table th, .tzfd-screen label { color:var(--tz-text,#f2f4f8); }
.tzfd-screen p { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .wp-admin table.wp-list-table, .tzfd-screen table.wp-list-table.widefat, .tzfd-screen table.widefat.tzfd-orders-list { background:var(--tz-panel,#232834); color:var(--tz-text,#f2f4f8); }
.tzfd-screen table.widefat.striped > tbody > tr:nth-child(odd), .tzfd-screen table.widefat.striped > tbody > tr:nth-child(odd) > th { background:var(--tz-field,#1f232d); }
.tzfd-screen table.widefat.striped > tbody > tr:nth-child(even) > td, .tzfd-screen table.widefat.striped > tbody > tr:nth-child(even) > th { background:var(--tz-panel,#232834); }
.tzfd-screen table.widefat tbody tr.tz_fd_cancelled td { color:var(--tz-muted,#e0e6ef); }
.tzfd-screen #wpbody-content table.widefat, .tzfd-screen #wpbody-content table.wp-list-table { background-color:var(--tz-panel,#232834) !important; color:var(--tz-text,#f2f4f8); }
.tzfd-screen #wpbody-content table.widefat thead th, .tzfd-screen #wpbody-content table.widefat tfoot th { background-color:var(--tz-field,#1f232d) !important; color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tablenav, .tzfd-screen .tablenav .tablenav-pages { background:transparent; color:var(--tz-muted,#e0e6ef); }
.tzfd-screen .tzfd-adintsection-container, .tzfd-screen .tzfdnoprint.tablenav { background:var(--tz-panel,#232834) !important; color:var(--tz-text,#f2f4f8); border-color:var(--tz-border,#333a48); }
.tzfd-screen #wpbody-content table.widefat tbody + tfoot td, .tzfd-screen #wpbody-content .tzfd-orders-list td[colspan] { background-color:var(--tz-panel,#232834) !important; }
.tzfd-screen #wpbody-content table.widefat tr.tzfd-orders-empty td, .tzfd-screen #wpbody-content .tzfd-orders-list tr.no-items td { background-color:var(--tz-panel,#232834) !important; }
.tzfd-screen .tzfd-setup-flow img, .tzfd-screen .tzfd-workflow img { border:1px solid var(--tz-border,#333a48); }';
    }


    /**
     * Styles for individual Workspace screens (publish wizard, bookings list, add-ons, help). They are added
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
