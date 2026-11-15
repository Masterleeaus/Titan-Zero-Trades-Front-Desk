<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/../includes/class-tzfd-request-scalar.php';

require_once dirname( __DIR__ ) . '/addons/core/base.addon.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';

/**
 * Shared flow for hosted captchas (Cloudflare Turnstile, Google reCAPTCHA).
 *
 * The widget is added to each form before the submit button. On submit, the token the widget posted is
 * checked by the adapter tz_fd_{SLUG}_verify. The check fails closed: while no verify adapter is connected,
 * booking submissions are refused. Administrators submitting from the back end are not checked.
 */
abstract class TZFD_Captcha_Gate_Addon extends TZFD_BaseAddon
{
    public $category = 'Integration with third party services';
    const SLUG         = '';
    const FIELD        = '';
    const SCRIPT_URL   = '';
    const WIDGET_CLASS = '';
    const MAX_TOKEN    = 2048;
    const NONCE_ACTION = 'tz_fd_captcha_gate';
    const NONCE_FIELD  = 'tz_fd_captcha_nonce';

    public static function defaults()
    {
        return array( 'mode' => 'test', 'site_key' => '', 'api_key' => '' );
    }

    protected function captcha_boot()
    {
        if ( ! $this->addon_is_active() ) { return; }
        add_filter( 'tz_fd_the_form', array( $this, 'add_widget' ), 96, 2 );
        add_filter( 'tz_fd_valid_submission', array( $this, 'verify_submission' ), 10, 1 );
    }

    public function settings()
    {
        return TZFD_Extension_Core::global_settings( static::SLUG, static::defaults(), array( 'api_key' ) );
    }

    /** Adds the widget and its nonce to a form. Nothing is added until a site key is saved. */
    public function add_widget( $html, $formid )
    {
        $settings = $this->settings();
        $site_key = isset( $settings['site_key'] ) && is_string( $settings['site_key'] ) ? $settings['site_key'] : '';
        if ( '' === $site_key || ! $this->addon_is_active() ) { return $html; }
        // Enqueue the provider script rather than printing it inside the form markup.
        wp_enqueue_script('tzfd-captcha-' . sanitize_key( static::WIDGET_CLASS ), static::SCRIPT_URL, array(), '1.0.0', true);
        $widget = '<div class="' . esc_attr( static::WIDGET_CLASS ) . '" data-sitekey="' . esc_attr( $site_key ) . '"></div>'
                . wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false );
        return TZFD_Extension_Core::insert_before_submit( $html, $widget );
    }

    /** Returns the verdict for the tz_fd_valid_submission filter. */
    public function verify_submission( $valid )
    {
        if ( ! $valid ) { return false; }
        if ( ! $this->addon_is_active() ) { return true; }
        if ( current_user_can( 'tz_fd_access' ) ) { return true; }
        // The nonce is checked before the captcha answer is read. A stale or missing nonce refuses the booking.
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! is_string( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( tzfd_request_text( 'post', self::NONCE_FIELD ), self::NONCE_ACTION ) ) {
            $this->refuse_stale_form();
            return false;
        }
        $token = isset( $_POST[ static::FIELD ] ) && is_string( $_POST[ static::FIELD ] )
            ? sanitize_text_field( wp_unslash( $_POST[ static::FIELD ] ) ) : '';
        if ( '' === $token || strlen( $token ) > self::MAX_TOKEN ) { return false; }
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? tzfd_request_text( 'server', 'REMOTE_ADDR' ) : '';
        if ( '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) { $ip = ''; }
        return true === TZFD_Extension_Core::adapter( static::SLUG, 'verify', array(
            'token' => $token, 'ip' => $ip, 'settings' => $this->settings(),
        ) );
    }

    /**
     * Shows the translated nonce error and ends the request. Only the real POST gets the message. The captcha
     * pre-check is a GET, so for it the function returns and the caller's false keeps the core's existing refusal.
     */
    private function refuse_stale_form()
    {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( tzfd_request_text( 'server', 'REQUEST_METHOD' ) ) : '';
        if ( 'POST' !== $method ) { return; }
        echo esc_html__( 'Your booking form has expired or is out of date. Please reload the page and try again.', 'titan-zero-trades-front-desk' );
        exit;
    }

    public function has_adapter()
    {
        return false !== has_filter( 'tz_fd_' . static::SLUG . '_verify' );
    }

    public function get_addon_settings()
    {
        $stored = $this->settings();
        $status = $this->has_adapter()
            ? __( 'Status: verify adapter connected.', 'titan-zero-trades-front-desk' )
            : __( 'Status: no verify adapter connected. Booking submissions are refused until one is connected.', 'titan-zero-trades-front-desk' );
        $values = $stored;
        $values['api_key'] = '';
        $specs = array(
            'mode'     => array( 'label' => __( 'Mode', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => 'test or live. Passed to the adapter.' ),
            'site_key' => array( 'label' => __( 'Site key', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => 'Public key shown in the widget.' ),
            'api_key'  => array( 'label' => __( 'Secret key', 'titan-zero-trades-front-desk' ), 'type' => 'password', 'help' => 'Read by your verify adapter only.' ),
        );
        ob_start();
        TZFD_Extension_Core::render_global_form( $this->name, static::SLUG, $specs, $values, $status );
        return ob_get_clean();
    }
}
