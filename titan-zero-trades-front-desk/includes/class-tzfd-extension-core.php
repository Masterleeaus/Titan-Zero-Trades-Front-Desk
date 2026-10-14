<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Helpers shared by the integration add-ons.
 *
 * An "adapter" is the point where a site connects a vendor. Each add-on asks
 * for an adapter through a filter named tzfd_{addon}_{operation}. When no
 * adapter answers, the add-on does its local part only and reports that the
 * vendor step is not connected. Nothing in this file calls a vendor.
 */
final class TZFD_Extension_Core {

    /** Asks the connected adapter for a result. Returns null when none is connected. */
    public static function adapter( $addon, $operation, array $args = array() ) {
        $hook = 'tzfd_' . sanitize_key( $addon ) . '_' . sanitize_key( $operation );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Name is always built from the literal 'tzfd_' prefix plus sanitised add-on and operation slugs.
        return apply_filters( $hook, null, $args );
    }

    /** Constant-time comparison of a computed signature with the received one. */
    public static function signature_matches( $expected, $given ) {
        if ( ! is_string( $expected ) || ! is_string( $given ) || '' === $expected || '' === $given ) {
            return false;
        }
        return hash_equals( $expected, $given );
    }

    /** True when $timestamp is within $tolerance seconds of now (replay protection). */
    public static function within_tolerance( $timestamp, $tolerance, $now = null ) {
        $timestamp = (int) $timestamp;
        $now       = null === $now ? time() : (int) $now;
        return $timestamp > 0 && abs( $now - $timestamp ) <= (int) $tolerance;
    }

    /** Minor-unit exponent for a currency: 0 for zero-decimal, 3 for three-decimal, otherwise 2. */
    public static function currency_exponent( $code ) {
        $code = strtoupper( (string) $code );
        if ( in_array( $code, array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ), true ) ) { return 0; }
        if ( in_array( $code, array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' ), true ) ) { return 3; }
        return 2;
    }

    /** Converts an amount in minor units (cents, paise, pence) to a decimal amount. */
    public static function from_minor_units( $minor, $exponent = 2 ) {
        return round( ( (int) $minor ) / pow( 10, (int) $exponent ), (int) $exponent );
    }

    /** Uppercase three-letter currency code, or '' when the value is not one. */
    public static function currency_code( $tzfd_value ) {
        $tzfd_value = strtoupper( trim( (string) $tzfd_value ) );
        return 1 === preg_match( '/^[A-Z]{3}$/', $tzfd_value ) ? $tzfd_value : '';
    }

    /** Positive booking id, or 0. */
    public static function booking_id( $tzfd_value ) {
        $id = filter_var( $tzfd_value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        return false === $id ? 0 : (int) $id;
    }

    /**
     * Registers a public POST endpoint at tzfd/v1/webhook/{slug}. Anonymous senders must reach it, so the
     * permission check is webhook_permission(); the callback then runs the add-on's own verification.
     */
    public static function register_webhook( $slug, $callback ) {
        add_action( 'rest_api_init', function() use ( $slug, $callback ) {
            register_rest_route( 'tzfd/v1', '/webhook/' . sanitize_key( $slug ), array(
                'methods'             => 'POST',
                'permission_callback' => array( 'TZFD_Extension_Core', 'webhook_permission' ),
                'callback'            => $callback,
            ) );
        } );
    }

    /**
     * Permission check for tzfd/v1/webhook/{slug}. A named method, never __return_true.
     * - A request with an X-TZFD-Signature header must carry sha256= plus HMAC-SHA256 of the raw body,
     *   keyed with the webhook secret stored for this slug. The comparison uses hash_equals().
     * - A request without that header must pass the slug's verify adapter (vendor signature scheme).
     * A missing secret, or no adapter, rejects the request.
     */
    public static function webhook_permission( WP_REST_Request $request ) {
        $slug = sanitize_key( basename( (string) $request->get_route() ) );
        if ( '' === $slug ) { return false; }

        $stored = get_option( TZFD_Addon_Support::global_option_name( $slug ), array() );
        $stored = is_array( $stored ) ? $stored : array();
        $raw    = (string) $request->get_body();
        $given  = (string) $request->get_header( 'x-tzfd-signature' );

        if ( '' !== $given ) {
            $secret = isset( $stored['webhook_secret'] ) ? (string) $stored['webhook_secret'] : '';
            if ( '' === $secret ) { return false; }
            $expected = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );
            return self::signature_matches( $expected, $given );
        }

        return true === self::adapter( $slug, 'verify', array( 'raw' => $raw, 'request' => $request, 'settings' => $stored ) );
    }

    /** JSON response with a status code. */
    public static function respond( array $tzfd_data, $status = 200 ) {
        return new WP_REST_Response( $tzfd_data, $status );
    }

    /**
     * Site-wide settings form for an integration add-on. Saves a valid posted
     * form and returns the stored values. Blank secret fields keep their stored value.
     */
    public static function global_settings( $tzfd_key, array $defaults, array $secret_keys = array() ) {
        $request = TZFD_Addon_Support::global_request( $tzfd_key );
        if ( null !== $request ) {
            $current = TZFD_Addon_Support::global_settings( $tzfd_key, $defaults );
            foreach ( $secret_keys as $secret ) {
                if ( ! isset( $request[ $secret ] ) || '' === trim( (string) $request[ $secret ] ) ) {
                    $request[ $secret ] = $current[ $secret ];
                }
            }
            TZFD_Addon_Support::save_global_settings( $tzfd_key, $defaults, $request );
        }
        return TZFD_Addon_Support::global_settings( $tzfd_key, $defaults );
    }

    /** Renders the settings form. $specs and $values follow TZFD_Addon_Support::render_table(). */
    public static function render_global_form( $name, $tzfd_key, array $specs, array $values, $status ) {
        echo '<div class="tzfd-addons-container"><h2>' . esc_html( $name ) . '</h2>';
        echo '<p>' . esc_html( $status ) . '</p>';
        echo '<form method="post">';
        wp_nonce_field( 'tzfd_global_' . $tzfd_key, '_tzfd_nonce' );
        echo '<input type="hidden" name="tzfd_save_' . esc_attr( $tzfd_key ) . '" value="1" />';
        TZFD_Addon_Support::render_table( 'tzfd_global_' . $tzfd_key . '_', $specs, $values );
        submit_button( __( 'Save settings', 'titan-zero-trades-front-desk' ) );
        echo '</form></div>';
    }

    /** Per-calendar settings block, shown in the form editor. Saves a valid posted block first. */
    public static function form_settings_block( $name, $tzfd_key, $form_id, array $defaults, array $specs, array $secret_keys = array() ) {
        $request = TZFD_Addon_Support::form_request( $tzfd_key, $form_id );
        if ( null !== $request ) {
            $current = TZFD_Addon_Support::form_settings( $tzfd_key, $form_id, $defaults );
            foreach ( $secret_keys as $secret ) {
                if ( ! isset( $request[ $secret ] ) || '' === trim( (string) $request[ $secret ] ) ) {
                    $request[ $secret ] = $current[ $secret ];
                }
            }
            TZFD_Addon_Support::save_form_settings( $tzfd_key, $form_id, $defaults, $request );
        }
        $values = TZFD_Addon_Support::form_settings( $tzfd_key, $form_id, $defaults );
        foreach ( $secret_keys as $secret ) { $values[ $secret ] = ''; }
        echo '<h3>' . esc_html( $name ) . '</h3>';
        echo '<input type="hidden" name="TZFD_' . esc_attr( $tzfd_key ) . '_id" value="' . esc_attr( (string) (int) $form_id ) . '" />';
        TZFD_Addon_Support::render_table( 'tzfd_' . $tzfd_key . '_', $specs, $values );
        return $values;
    }

    /** Inserts HTML before the submit button of a form (or before </form>). Returns the form HTML unchanged if neither is found. */
    public static function insert_before_submit( $html, $field_html ) {
        if ( ! is_string( $html ) ) { return ''; }
        if ( '' === $field_html ) { return $html; }
        if ( 1 === preg_match( '/<div id="tzfd_subbtn/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
            $pos = $m[0][1];
            return substr( $html, 0, $pos ) . $field_html . substr( $html, $pos );
        }
        $pos = strripos( $html, '</form>' );
        if ( false === $pos ) { return $html; }
        return substr( $html, 0, $pos ) . $field_html . substr( $html, $pos );
    }

    /** Parses "key,value" lines (one per line). Blank lines and lines without a comma are skipped. */
    public static function parse_pairs( $text ) {
        $pairs = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
            $parts = array_map( 'trim', explode( ',', $line, 2 ) );
            if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) { $pairs[ $parts[0] ] = $parts[1]; }
        }
        return $pairs;
    }
}
