<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Brand pack: the Front Desk menu icon, the menu's submenu icons, the page banner on each
 * Front Desk screen, empty-state illustrations and the wordmark. Files live in images/brand/ (mirroring the brand pack folders).
 */
class TZFD_Brand {

    /** Front Desk submenu slug => icon file in images/brand/icons/ (without .svg). */
    const MENU_ICONS = array(
        'tzfd'              => 'dashboard',
        'tzfd_calendar'     => 'calendar',
        'tzfd-customers'                   => 'customers',
        'tzfd_bookings'     => 'bookings',
        'tzfd-quotes'                      => 'quotes',
        'tzfd_services'     => 'services',
        'tzfd-payments'                    => 'payments',
        'tzfd-resources' => 'workers',
        'tzfd-locations'                   => 'locations',
        'tzfd-ai-setup'                      => 'ai-receptionist',
        'tzfd_addons'       => 'addons',
        'tzfd_settings'     => 'settings',
    );

    /** Front Desk page slug => banner key in images/brand/banners/ (without -1440x220.png). */
    const BANNERS = array(
        'tzfd'              => 'dashboard',
        'tzfd_calendar'     => 'calendar',
        'tzfd-customers'                   => 'customers',
        'tzfd_bookings'     => 'bookings',
        'tzfd-quotes'                      => 'quotes',
        'tzfd_services'     => 'services',
        'tzfd-payments'                    => 'payments',
        'tzfd-resources' => 'workers',
        'tzfd-locations'                   => 'locations',
        'tzfd-ai-setup'                      => 'ai',
        'tzfd_addons'       => 'addons',
        'tzfd_settings'     => 'settings',
        'tzfd-business'                    => 'settings',
    );

    /** Front Desk page slug => heading icon in images/brand/icons/ (shown before the page title). */
    const HEADING_ICONS = array(
        'tzfd'                      => 'analytics',
        'tzfd_support'              => 'support',
        'tzfd-setup'                => 'automation',
        'tzfd-packs'                => 'forms',
        'tzfd-scheduling-preview'   => 'availability',
        'tzfd_pipeline'             => 'inbox',
        'tzfd-status-update-emails' => 'notifications',
        'tzfd_addons'               => 'integrations',
    );

    /** Trade pack id => trade banner in images/brand/marketing/trade-banners/ (without -1544x500.png). */
    const TRADE_BANNERS = array(
        'cleaning'     => 'cleaning',
        'plumbing'     => 'plumbing',
        'electrical'   => 'electrical',
        'hvac'         => 'hvac',
        'landscaping'  => 'landscaping',
        'handyman'     => 'handyman',
    );

    /** Empty-state kind => illustration path inside images/brand/. */
    const EMPTY_STATES = array(
        'reception' => 'illustrations/reception-empty.svg',
        'bookings'  => 'illustrations/bookings-empty.svg',
        'quotes'    => 'illustrations/quotes-empty.svg',
        'customers' => 'ui-kit/empty-states/customers-empty.svg',
        'services'  => 'ui-kit/empty-states/services-empty.svg',
        'workers'   => 'ui-kit/empty-states/workers-empty.svg',
        'calendar'  => 'ui-kit/empty-states/calendar-empty.svg',
    );

    public static function register() {
        add_action( 'in_admin_header', array( __CLASS__, 'render_banner' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_css' ) );
        add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
    }

    /** Absolute path to a file in the plugin folder. */
    public static function path( $relative ) {
        return plugin_dir_path( dirname( __FILE__, 2 ) . '/titan-zero-trades-front-desk.php' ) . $relative;
    }

    /** URL of a file in the plugin folder. */
    public static function url( $relative ) {
        return plugin_dir_url( dirname( __FILE__, 2 ) . '/titan-zero-trades-front-desk.php' ) . $relative;
    }

    /** Menu icon for the top-level Front Desk item: the receptionist icon in WordPress's grey menu colour. */
    public static function menu_icon() {
        $svg = file_get_contents( self::path( 'images/brand/icons/ai-receptionist.svg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
        if ( false === $svg || '' === $svg ) {
            return 'dashicons-phone';
        }
        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( str_replace( 'currentColor', '#a7aaad', $svg ) );
    }

    /** Banner key for the current Front Desk screen, or '' when the screen has no banner. */
    public static function screen_key() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( '' === $page || ! isset( self::BANNERS[ $page ] ) ) {
            return '';
        }
        if ( TZFD_Admin_Menu::PARENT === $page ) {
            foreach ( array( 'cal', 'pwizard', 'confirm' ) as $key ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
                if ( isset( $_GET[ $key ] ) ) {
                    return 'calendar';
                }
            }
        }
        return self::BANNERS[ $page ];
    }

    /** Slim banner above the title on each Front Desk screen. On for every Front Desk screen; turn off with the titan_zero_front_desk_show_page_banners filter (return false). */
    public static function render_banner() {
        $key = self::screen_key();
        if ( '' === $key || ! apply_filters( 'titan_zero_front_desk_show_page_banners', true ) ) {
            return;
        }
        $labels = array(
            'dashboard' => __( 'Dashboard', 'titan-zero-trades-front-desk' ),
            'calendar' => __( 'Bookings', 'titan-zero-trades-front-desk' ),
        );
        $label = $labels[ $key ] ?? ucwords( str_replace( '-', ' ', $key ) );
        printf(
            '<div class="tzfd-page-banner tzfd-brand-strip"><span class="tzfd-brand-strip-icon" aria-hidden="true"><img src="%s" alt="" width="48" height="48" /></span><span class="tzfd-brand-strip-copy"><span class="tzfd-brand-strip-kicker">%s</span><strong>%s</strong><span class="tzfd-brand-strip-description">%s</span></span><a class="tzfd-brand-strip-action" href="%s">%s &rarr;</a></div>',
            esc_url( self::url( 'images/brand/branding/front-desk-icon.svg' ) ),
            esc_html__( 'TITAN ZERO · FRONT DESK', 'titan-zero-trades-front-desk' ),
            esc_html( $label ),
            esc_html__( 'Bookings, quotes and customers in one place', 'titan-zero-trades-front-desk' ),
            esc_url( admin_url( 'admin.php?page=tzfd' ) ),
            esc_html__( 'Dashboard', 'titan-zero-trades-front-desk' )
        );
    }

    /** Wordmark for the top of the Help page. */
    public static function logo_img() {
        $name = __( 'Titan Zero Trades: Front Desk', 'titan-zero-trades-front-desk' );
        printf(
            '<p class="tzfd-brand-logo"><img class="tzfd-mark" src="%s" alt="" width="40" height="40" /><img class="tzfd-logo-light" src="%s" alt="%s" height="40" /><img class="tzfd-logo-dark" src="%s" alt="%s" height="40" /></p>',
            esc_url( self::url( 'images/brand/branding/front-desk-icon.svg' ) ),
            esc_url( self::url( 'images/brand/branding/logo-light.svg' ) ),
            esc_attr( $name ),
            esc_url( self::url( 'images/brand/branding/logo-dark.svg' ) ),
            esc_attr( $name )
        );
    }

    /** Empty-list message with its illustration. $text is translated by the caller. */
    public static function empty_state( $kind, $text ) {
        if ( ! isset( self::EMPTY_STATES[ $kind ] ) ) {
            echo '<p><em>' . esc_html( $text ) . '</em></p>';
            return;
        }
        printf(
            '<div class="tzfd-empty-state"><img src="%s" alt="" width="240" height="160" /><p>%s</p></div>',
            esc_url( self::url( 'images/brand/' . self::EMPTY_STATES[ $kind ] ) ),
            esc_html( $text )
        );
    }

    /** Adds the screen's key to the admin body class, so its heading icon can be styled. */
    public static function body_class( $classes ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( '' !== $page && isset( self::HEADING_ICONS[ $page ] ) ) {
            $classes .= ' tzfd-screen-' . $page;
        }
        return $classes;
    }

    /**
     * Tags allowed in the HTML this class returns. Call sites print helper output with
     * wp_kses( TZFD_Brand::helper(), TZFD_Brand::allowed_html() ).
     */
    public static function allowed_html() {
        return array(
            'img'  => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'class' => true, 'loading' => true ),
            'span' => array( 'class' => true ),
            'div'  => array( 'class' => true ),
        );
    }

    /** A status icon with its label. Used for pipeline states, quote statuses and booking statuses. */
    public static function status_tag( $key, $label ) {
        return '<span class="tzfd-status-tag"><img src="' . esc_url( self::url( 'images/brand/ui-kit/status-badges/' . $key . '.svg' ) ) . '" alt="" width="16" height="16" />' . esc_html( $label ) . '</span>';
    }

    /** Status tag for a booking, from the class TZFD_Booking_View::status() returns. */
    public static function booking_tag( $class, $label ) {
        $icons = array( 'confirmed' => 'confirmed', 'pending' => 'waiting', 'attended' => 'completed', 'cancelled' => 'cancelled' );
        return isset( $icons[ $class ] ) ? self::status_tag( $icons[ $class ], $label ) : esc_html( $label );
    }

    /** Trade banner for a trade pack card, or '' for packs without one. */
    public static function trade_banner( $pack_id ) {
        if ( ! isset( self::TRADE_BANNERS[ $pack_id ] ) ) { return ''; }
        return '<img class="tzfd-trade-banner" src="' . esc_url( self::url( 'images/brand/marketing/trade-banners/' . self::TRADE_BANNERS[ $pack_id ] . '-1544x500.png' ) ) . '" alt="" width="1544" height="500" loading="lazy" />';
    }

    /** Slim trade banner for the pack named in the install notice, or '' when that pack is not a trade pack. */
    public static function slim_trade_banner( $packs, $name ) {
        foreach ( self::TRADE_BANNERS as $id => $file ) {
            if ( isset( $packs[ $id ]['name'] ) && $packs[ $id ]['name'] === $name ) {
                return '<img class="tzfd-trade-banner tzfd-trade-slim" src="' . esc_url( self::url( 'images/brand/marketing/trade-banners/slim/' . $file . '-1440x220.png' ) ) . '" alt="" width="1440" height="220" loading="lazy" />';
            }
        }
        return '';
    }

    /** The four feature cards on the Help page. */
    public static function feature_cards() {
        $cards = array(
            'ai-receptionist' => __( 'AI receptionist', 'titan-zero-trades-front-desk' ),
            'bookings'        => __( 'Bookings', 'titan-zero-trades-front-desk' ),
            'front-desk-team' => __( 'Front desk team', 'titan-zero-trades-front-desk' ),
            'quotes'          => __( 'Quotes', 'titan-zero-trades-front-desk' ),
        );
        echo '<div class="tzfd-feature-cards">';
        foreach ( $cards as $file => $alt ) {
            printf(
                '<img src="%s" alt="%s" width="760" height="460" loading="lazy" />',
                esc_url( self::url( 'images/brand/ui-kit/feature-cards/' . $file . '.svg' ) ),
                esc_attr( $alt )
            );
        }
        echo '</div>';
    }

    /** The workflow overview on the Help page. */
    public static function workflow_img() {
        printf(
            '<div class="tzfd-workflow"><img src="%s" alt="%s" width="1600" height="1000" loading="lazy" /></div>',
            esc_url( self::url( 'images/brand/marketing/workflow-overview-dark-1600x1000.webp' ) ),
            esc_attr__( 'How bookings move from request to approval', 'titan-zero-trades-front-desk' )
        );
    }

    /** The suggested setup sequence on the Preset Setup page. */
    public static function setup_flow() {
        return '<div class="tzfd-setup-flow"><img src="' . esc_url( self::url( 'images/brand/onboarding/front-desk-setup-flow.svg' ) ) . '" alt="' . esc_attr__( 'Setup steps: choose a trade, add services, set your calendar', 'titan-zero-trades-front-desk' ) . '" width="1440" height="460" loading="lazy" /></div>';
    }

    /** Submenu icons, banner and empty-state styles, added to the admin stylesheet. */
    public static function enqueue_css() {
        $css = '';
        foreach ( self::MENU_ICONS as $slug => $icon ) {
            $url  = esc_url( self::url( 'images/brand/icons/' . $icon . '.svg' ) );
            $css .= sprintf(
                '#adminmenu .wp-submenu a[href$="page=%1$s"]::before{content:"";display:inline-block;width:16px;height:16px;margin:0 6px 0 0;vertical-align:-3px;background-color:currentColor;-webkit-mask:url("%2$s") center/contain no-repeat;mask:url("%2$s") center/contain no-repeat;}',
                $slug,
                $url
            );
        }
        foreach ( self::HEADING_ICONS as $slug => $icon ) {
            $css .= sprintf(
                'body.tzfd-screen-%1$s .wrap > h1:first-of-type::before{content:"";display:inline-block;width:28px;height:28px;margin:0 10px 0 0;vertical-align:-6px;background:url(%2$s) center/contain no-repeat;}',
                $slug,
                esc_url( self::url( 'images/brand/icons/' . $icon . '.svg' ) )
            );
        }
        $css .= '.tzfd-brand-logo{display:flex;align-items:center;gap:10px;margin:8px 0 4px}'
              . '.tzfd-brand-logo .tzfd-logo-dark{display:none}'
              . 'body.is-dark-theme .tzfd-brand-logo .tzfd-logo-light{display:none}'
              . 'body.is-dark-theme .tzfd-brand-logo .tzfd-logo-dark{display:inline-block}'
              . '.tzfd-status-tag{display:inline-flex;align-items:center;gap:6px}'
              . '.tzfd-status-tag img{width:16px;height:16px}'
              . '.tzfd-feature-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin:16px 0}'
              . '.tzfd-feature-cards img,.tzfd-workflow img,.tzfd-setup-flow img,.tzfd-trade-banner{display:block;max-width:100%;height:auto;border-radius:8px}'
              . '.tzfd-workflow{margin:16px 0}'
              . '.tzfd-setup-flow{margin:12px 0 18px}'
              . '.tzfd-page-banner{margin:10px 0 14px}'
              . '.tzfd-page-banner img{display:block;width:100%;max-width:1440px;height:auto;max-height:120px;object-fit:cover;border-radius:8px}'
              . '.tzfd-empty-state{text-align:center;padding:24px 16px;color:#50575e}'
              . '.tzfd-empty-state img{display:block;margin:0 auto 8px;max-width:240px;height:auto}'
              . '.tzfd-brand-strip{display:flex!important;align-items:center;gap:16px;min-height:84px;padding:14px 22px;background:#1b1e26;border:1px solid #333a48;border-radius:14px;color:#f4f8ff;box-sizing:border-box}'
              . '.tzfd-brand-strip-icon{width:58px;height:58px;display:grid;place-items:center;flex:none;background:#232834;border-radius:14px}'
              . '.tzfd-brand-strip .tzfd-brand-strip-icon img{width:48px;height:48px;object-fit:contain}'
              . '.tzfd-brand-strip-copy{display:flex;flex:1;min-width:0;flex-direction:column;gap:3px}'
              . '.tzfd-brand-strip-kicker{font-size:10px;letter-spacing:.16em;font-weight:800;color:#91cffa}'
              . '.tzfd-brand-strip-copy strong{font-size:24px;line-height:1.2;color:#fff}'
              . '.tzfd-brand-strip-description{font-size:12px;color:#c5d5e8}'
              . '.tzfd-brand-strip-action{padding:10px 14px;border:1px solid #4a5366;border-radius:9px;color:#c1e8ff;text-decoration:none;font-weight:700;white-space:nowrap}'
              . '@media(max-width:600px){.tzfd-brand-strip{padding:12px;gap:10px}.tzfd-brand-strip-copy strong{font-size:19px}.tzfd-brand-strip-action{font-size:11px;padding:8px}}'
              . '.tzfd-brand-logo{margin:8px 0 4px}';
        wp_add_inline_style( 'wp-admin', $css );
    }
}
