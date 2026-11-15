<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Static, bundled artwork catalogue. No user-provided paths are accepted. */
final class TZFD_UI_Graphics {
    /** Brand icons (images/brand/icons) that may be drawn on Front Desk screens. */
    const BRAND_ICONS = array(
        'addons', 'ai-receptionist', 'analytics', 'automation', 'availability', 'bookings', 'calendar', 'customers',
        'dashboard', 'forms', 'inbox', 'integrations', 'locations', 'notifications', 'payments', 'search',
        'services', 'settings', 'support', 'workers',
    );

    /** Service category vocabulary shared by the icon and thumbnail lookups. */
    const SERVICE_PATTERNS = array(
        'cleaning' => '/\b(clean|cleaning|housekeep|maid|janitor|sanitis|sanitiz)\w*\b/i',
        'plumbing' => '/\b(plumb|pipe|drain|tap|faucet)\w*\b/i',
        'electrical' => '/\b(electric|wiring|lighting)\w*\b/i',
        'landscaping' => '/\b(landscap|garden|lawn|mow)\w*\b/i',
        'painting' => '/\b(paint|decorat)\w*\b/i',
        'repairs' => '/\b(repair|maintenan|handyman|fix)\w*\b/i',
        'consulting' => '/\b(consult|advis|audit)\w*\b/i',
        'appointments' => '/\b(appoint|meeting|session|visit)\w*\b/i',
    );

    public static function url( $path ) {
        return plugin_dir_url( dirname( __FILE__ ) ) . 'images/ui-graphics/' . ltrim( $path, '/' );
    }

    /** Contextual asset resolver; paths are fixed to bundled files. */
    public static function asset( $family, $name ) {
        $assets = array(
            'channel' => array( 'phone' => '02-Communications/Channel-Icons/phone.png', 'email' => '02-Communications/Channel-Icons/email.png', 'sms' => '02-Communications/Channel-Icons/sms.png', 'whatsapp' => '02-Communications/Channel-Icons/whatsapp.png', 'ai' => '02-Communications/Channel-Icons/ai.png' ),
            'service' => array( 'cleaning' => '01-Services/Icons/cleaning.png', 'plumbing' => '01-Services/Icons/plumbing.png', 'electrical' => '01-Services/Icons/electrical.png', 'landscaping' => '01-Services/Icons/landscaping.png', 'painting' => '01-Services/Icons/painting.png', 'repairs' => '01-Services/Icons/repairs.png', 'consulting' => '01-Services/Icons/consulting.png', 'appointments' => '01-Services/Icons/appointments.png' ),
            'connection' => array( 'connected' => '06-Integrations/Connection-States/connected.png', 'disconnected' => '06-Integrations/Connection-States/disconnected.png', 'connecting' => '06-Integrations/Connection-States/connecting.png', 'attention' => '06-Integrations/Connection-States/attention.png' ),
            'empty' => array( 'calendar' => '05-Empty-States/calendar-empty.png', 'users' => '05-Empty-States/users-empty.png', 'services' => '05-Empty-States/briefcase-empty.png', 'messages' => '05-Empty-States/chat-empty.png', 'search' => '05-Empty-States/search-empty.png' ),
        );
        return isset( $assets[ $family ][ $name ] ) ? self::url( $assets[ $family ][ $name ] ) : '';
    }

    /** Category key for a service name, or '' when no category matches. */
    private static function service_category( $service_name ) {
        $name = strtolower( (string) $service_name );
        foreach ( self::SERVICE_PATTERNS as $category => $pattern ) {
            if ( preg_match( $pattern, $name ) ) { return $category; }
        }
        return '';
    }

    /** Resolve a supplied service image using a bounded category vocabulary. */
    public static function service_thumbnail( $service_name ) {
        $category = self::service_category( $service_name );
        return '' === $category ? '' : self::url( '01-Services/Thumbnails/' . $category . '-thumbnail.webp' );
    }

    /** Service icon: the category icon when the name matches, otherwise the generic appointments icon. */
    public static function service_icon( $service_name ) {
        $category = self::service_category( $service_name );
        return self::asset( 'service', '' !== $category ? $category : 'appointments' );
    }

    /** Larger service picture: the category thumbnail when it matches, otherwise the appointments thumbnail. */
    public static function service_picture( $service_name ) {
        $thumbnail = self::service_thumbnail( $service_name );
        return '' !== $thumbnail ? $thumbnail : self::url( '01-Services/Thumbnails/appointments-thumbnail.webp' );
    }

    /** Channel icon (ai, email, phone, sms, whatsapp), or '' for an unknown channel. */
    public static function channel_icon( $channel ) {
        return self::asset( 'channel', (string) $channel );
    }

    /** Brand icon URL (SVG) for one of the allow-listed names, or '' otherwise. */
    public static function brand_icon( $name ) {
        if ( ! in_array( (string) $name, self::BRAND_ICONS, true ) ) { return ''; }
        return plugin_dir_url( dirname( __FILE__ ) ) . 'images/brand/icons/' . $name . '.svg';
    }

    /** Tags and attributes allowed when an icon or picture is echoed through wp_kses(). */
    public static function allowed_html() {
        return array(
            'img' => array(
                'class' => true, 'src' => true, 'alt' => true, 'width' => true, 'height' => true,
                'loading' => true, 'decoding' => true, 'style' => true,
            ),
        );
    }

    /**
     * Escaped <img> for an icon. $size is clamped to 24 to 48 px; the image keeps its aspect ratio.
     * Pass an empty $alt for decorative icons that sit beside a text label.
     */
    public static function icon_html( $url, $size = 24, $alt = '' ) {
        if ( '' === (string) $url ) { return ''; }
        $size = max( 24, min( 48, (int) $size ) );
        return '<img class="tzfd-ui-icon" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" decoding="async" style="max-width:100%;height:auto;width:' . (int) $size . 'px;vertical-align:middle;margin-right:6px;flex:0 0 auto" />';
    }

    /** Ready-to-echo brand icon markup, or '' when the name is not allow-listed. */
    public static function brand_html( $name, $size = 32 ) {
        return wp_kses( self::icon_html( self::brand_icon( $name ), $size ), self::allowed_html() );
    }

    /** Ready-to-echo channel icon markup (ai, email, phone, sms, whatsapp), or ''. */
    public static function channel_html( $channel, $size = 24 ) {
        return wp_kses( self::icon_html( self::channel_icon( $channel ), $size ), self::allowed_html() );
    }

    /** Ready-to-echo service icon markup for a service name. */
    public static function service_html( $service_name, $size = 24 ) {
        return wp_kses( self::icon_html( self::service_icon( $service_name ), $size ), self::allowed_html() );
    }

    /** Escaped <img> for a service picture. Scales down to the column or card it sits in. */
    public static function picture_html( $url, $max_width = 160, $alt = '' ) {
        if ( '' === (string) $url ) { return ''; }
        $max_width = max( 24, min( 320, (int) $max_width ) );
        return '<img class="tzfd-ui-picture" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async" style="max-width:' . (int) $max_width . 'px;width:100%;height:auto;display:block;border-radius:8px" />';
    }
}
