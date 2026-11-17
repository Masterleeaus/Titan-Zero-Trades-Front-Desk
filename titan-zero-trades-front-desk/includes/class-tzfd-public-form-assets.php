<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Styling and required-field marker for the public Front Desk forms (enquiry and callback shortcodes).
 * The forms load the plugin's public stylesheet and a form stylesheet only when one of them is rendered,
 * and only once per page. Field names, IDs, nonces and validation are owned by the form classes, not here.
 */
final class TZFD_Public_Form_Assets {

    const STYLE_HANDLE = 'tzfd-public-forms';
    const BASE_HANDLE  = 'tzfdapp-publicstyle';

    /** Enqueue both stylesheets. Idempotent: WordPress's own queue check stops a second enqueue on the same page. */
    public static function enqueue() {
        if ( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) { return; }
        $main = dirname( __DIR__ ) . '/titan-zero-front-desk.php';
        $form_css = dirname( __DIR__ ) . '/css/tzfd-public-forms.css';
        wp_enqueue_style( self::BASE_HANDLE, plugins_url( 'css/stylepublic.css', $main ), array(), '1.0.0' );
        wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'css/tzfd-public-forms.css', $main ), array( self::BASE_HANDLE ), (string) @filemtime( $form_css ) );
    }

    /** Visible text placed after a required field's label text, so the requirement does not rely on colour or the browser alone. */
    public static function required_marker() {
        return '<span class="tzfd-required-marker">' . esc_html__( '(required)', 'titan-zero-trades-front-desk' ) . '</span>';
    }
}
