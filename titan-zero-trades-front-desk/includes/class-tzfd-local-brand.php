<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Minimal safe Front Desk brand rendering when no shared brand library exists. */
final class TZFD_Local_Brand {
    public static function allowed_html() { return array( 'span' => array( 'class' => true, 'aria-label' => true ), 'strong' => array(), 'em' => array(), 'div' => array( 'class' => true ), 'p' => array(), 'img' => array( 'src' => true, 'alt' => true, 'loading' => true ) ); }
    public static function status_tag( $icon, $label = '' ) { return '<span class="tzfd-status">' . esc_html( (string) $label ) . '</span>'; }
    public static function empty_state( $type, $message = '' ) { $map = array( 'bookings' => 'calendar-empty.png', 'calendar' => 'calendar-empty.png', 'services' => 'briefcase-empty.png', 'customers' => 'users-empty.png', 'workers' => 'workers-empty.png', 'messages' => 'chat-empty.png', 'search' => 'search-empty.png', 'equipment' => 'equipment-empty.png', 'reception' => 'chat-empty.png' );
        $asset = isset( $map[ $type ] ) ? $map[ $type ] : 'search-empty.png';
        $url = plugin_dir_url( dirname( __FILE__ ) ) . 'images/ui-graphics/05-Empty-States/' . $asset;
        echo '<div class="tzfd-empty-state tzfd-empty-state--illustrated"><img loading="lazy" width="160" height="120" alt="" src="' . esc_url( $url ) . '"><p>' . esc_html( (string) $message ) . '</p></div>'; }
    public static function logo_img() { echo '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'; }
    /** Figure for a full-width help visual. Fixed relative paths only; the caller passes a constant. */
    private static function visual_html( $relative ) {
        $url = plugin_dir_url( dirname( __FILE__ ) ) . $relative;
        return '<div class="tzfd-help-visual"><img loading="lazy" alt="" src="' . esc_url( $url ) . '"></div>';
    }
    /** Wizard step artwork: step 1 shows the setup-flow overview. Other steps keep the banner. The four-stage progress art (step-01 to step-04) is shown on the help page, because its labels do not match the wizard steps. */
    public static function setup_flow( $step = 1 ) {
        $step = (int) $step;
        if ( 1 === $step ) { return self::visual_html( 'images/brand/onboarding/front-desk-setup-flow.svg' ); }
        $url = plugin_dir_url( dirname( __FILE__ ) ) . 'images/ui-graphics/03-Onboarding/step-01.webp';
        return '<div class="tzfd-visual-banner"><img loading="lazy" alt="" src="' . esc_url( $url ) . '"><div><strong>' . esc_html__( 'Set up your Front Desk', 'titan-zero-trades-front-desk' ) . '</strong><p>' . esc_html__( 'Configure services, availability and booking preferences.', 'titan-zero-trades-front-desk' ) . '</p></div></div>';
    }
    /** Help walkthrough artwork for one topic. Unknown topics fall back to the calendar walkthrough. */
    public static function workflow_img( $topic = 'calendar' ) {
        $topics = array( 'calendar', 'chat', 'settings', 'users' );
        $topic  = in_array( $topic, $topics, true ) ? $topic : 'calendar';
        echo self::visual_html( 'images/ui-graphics/07-Help/Walkthrough-Illustrations/' . $topic . '-walkthrough.webp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by visual_html() with esc_url().
    }
    /** One onboarding step image (1 to 4), for the help page. */
    public static function step_img( $step ) {
        $step = (int) $step;
        if ( $step < 1 || $step > 4 ) { return; }
        echo self::visual_html( 'images/ui-graphics/03-Onboarding/step-0' . $step . '.webp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by visual_html() with esc_url().
    }
    /** Help page gallery: every walkthrough and onboarding step image, in one place. */
    public static function help_gallery() {
        echo '<div class="tzfd-help-gallery"><p><strong>' . esc_html__( 'Visual guides', 'titan-zero-trades-front-desk' ) . '</strong></p>';
        foreach ( array( 'calendar', 'chat', 'settings', 'users' ) as $topic ) { self::workflow_img( $topic ); }
        for ( $n = 1; $n <= 4; $n++ ) { self::step_img( $n ); }
        echo self::visual_html( 'images/brand/onboarding/front-desk-setup-flow.svg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by visual_html() with esc_url().
        echo '</div>';
    }
}
