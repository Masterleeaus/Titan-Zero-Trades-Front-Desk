<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared helpers for the Titan Zero Trades: Front Desk add-on implementations.
 *
 * Most methods are pure (no database or WordPress state) so they can be
 * exercised directly. Storage and rendering helpers are thin wrappers over
 * the WordPress options and form APIs. GPL-2.0-only.
 */
final class TZFD_Addon_Support {
    const OPTION_PREFIX = 'titan_zero_front_desk_addon_';
    const NONCE_ACTION  = 'tzfd_actions_admin';

    /**
     * Fields stored as one-way hashes, keyed by add-on. A hashed field cannot be
     * read back, so an administrator enters a new value to change it.
     */
    const HASHED_FIELDS = array(
        'password' => array( 'password' ),
    );

    /* ---------------------------------------------------------------
     * Settings storage (wp_options, autoload off)
     * ------------------------------------------------------------- */

    public static function form_option_name( $tzfd_key, $formid ) {
        return self::OPTION_PREFIX . sanitize_key( $tzfd_key ) . '_form_' . (int) $formid;
    }

    public static function global_option_name( $tzfd_key ) {
        return self::OPTION_PREFIX . sanitize_key( $tzfd_key ) . '_global';
    }

    /** Per-form settings merged over defaults. Unknown stored keys are ignored. */
    public static function form_settings( $tzfd_key, $formid, array $defaults ) {
        // Site-wide defaults apply to calendars that have no value of their own yet.
        $site = self::merge_stored( get_option( self::global_option_name( $tzfd_key ), array() ), $defaults );
        return self::merge_stored( get_option( self::form_option_name( $tzfd_key, $formid ), array() ), $site );
    }

    /** Global (site-wide) settings merged over defaults. */
    public static function global_settings( $tzfd_key, array $defaults ) {
        return self::merge_stored( get_option( self::global_option_name( $tzfd_key ), array() ), $defaults );
    }

    public static function merge_stored( $stored, array $defaults ) {
        if ( ! is_array( $stored ) ) { $stored = array(); }
        return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
    }

    /**
     * Sanitize submitted values against the defaults' types and persist them.
     * Types come from the default: bool (checkbox), int, or string.
     * A key absent from $input is treated as unchecked / zero / empty, which is
     * correct because every field is posted with its form.
     */
    public static function sanitize_settings( array $defaults, array $input ) {
        $clean = array();
        foreach ( $defaults as $name => $default ) {
            $raw = array_key_exists( $name, $input ) ? $input[ $name ] : null;
            if ( is_bool( $default ) ) {
                $clean[ $name ] = ( null !== $raw && '' !== $raw && '0' !== $raw );
            } elseif ( is_int( $default ) ) {
                $clean[ $name ] = max( 0, min( 100000, (int) $raw ) );
            } else {
                $clean[ $name ] = is_string( $raw ) ? trim( sanitize_textarea_field( $raw ) ) : '';
            }
        }
        return $clean;
    }

    public static function save_form_settings( $tzfd_key, $formid, array $defaults, array $input ) {
        $clean = self::store_hashed_fields( $tzfd_key, self::sanitize_settings( $defaults, $input ) );
        update_option( self::form_option_name( $tzfd_key, $formid ), $clean, false );
        return $clean;
    }

    public static function save_global_settings( $tzfd_key, array $defaults, array $input ) {
        $clean = self::store_hashed_fields( $tzfd_key, self::sanitize_settings( $defaults, $input ) );
        update_option( self::global_option_name( $tzfd_key ), $clean, false );
        return $clean;
    }

    /** True when the value is a password_hash() result rather than plain text. */
    public static function is_password_hash( $value ) {
        if ( ! is_string( $value ) || '' === $value ) { return false; }
        $info = password_get_info( $value );
        return ! empty( $info['algo'] );
    }

    /**
     * Hash the configured fields of one add-on before they are stored. Empty
     * values stay empty (no password) and values that are already hashes are
     * kept as they are, so re-saving an unchanged form never double-hashes.
     */
    public static function store_hashed_fields( $tzfd_key, array $clean ) {
        if ( empty( self::HASHED_FIELDS[ $tzfd_key ] ) ) { return $clean; }
        foreach ( self::HASHED_FIELDS[ $tzfd_key ] as $field ) {
            if ( ! isset( $clean[ $field ] ) || ! is_string( $clean[ $field ] ) || '' === $clean[ $field ] ) { continue; }
            if ( self::is_password_hash( $clean[ $field ] ) ) { continue; }
            $clean[ $field ] = password_hash( $clean[ $field ], PASSWORD_DEFAULT );
        }
        return $clean;
    }

    /**
     * Upgrade a legacy plain-text value to a hash after it has been used
     * successfully. Only the stored option that holds this exact plain value
     * is rewritten; the site-wide option is checked as well.
     */
    public static function upgrade_plain_password( $tzfd_key, $formid, $plain ) {
        if ( ! is_string( $plain ) || '' === $plain || self::is_password_hash( $plain ) ) { return; }
        $options = array( self::form_option_name( $tzfd_key, $formid ), self::global_option_name( $tzfd_key ) );
        foreach ( $options as $name ) {
            $stored = get_option( $name, null );
            if ( ! is_array( $stored ) || empty( self::HASHED_FIELDS[ $tzfd_key ] ) ) { continue; }
            foreach ( self::HASHED_FIELDS[ $tzfd_key ] as $field ) {
                if ( isset( $stored[ $field ] ) && is_string( $stored[ $field ] ) && hash_equals( $stored[ $field ], $plain ) ) {
                    $stored[ $field ] = password_hash( $plain, PASSWORD_DEFAULT );
                    update_option( $name, $stored, false );
                    return;
                }
            }
        }
    }

    /* ---------------------------------------------------------------
     * Form-editor and global-settings UI plumbing
     * ------------------------------------------------------------- */

    /**
     * Values posted from the per-form editor for one add-on, or null when this
     * request is not a valid save of that add-on's block.
     */
    public static function form_request( $tzfd_key, $formid ) {
        $marker = 'TZFD_' . $tzfd_key . '_id';
        if ( ! isset( $_POST[ $marker ] ) || ! isset( $_POST['anonce'] ) ) { return null; }
        if ( ! current_user_can( 'edit_pages' ) ) { return null; }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['anonce'] ?? '' ) ), self::NONCE_ACTION ) ) { return null; }
        if ( (int) sanitize_text_field( wp_unslash( $_POST[ $marker ] ?? '' ) ) !== (int) $formid ) { return null; }
        return self::posted_with_prefix( 'tzfd_' . $tzfd_key . '_', $_POST );
    }

    /** Values posted from an add-on's site-wide settings block, or null. */
    public static function global_request( $tzfd_key ) {
        $action = 'tzfd_global_' . $tzfd_key;
        if ( ! isset( $_POST[ 'tzfd_save_' . $tzfd_key ] ) || ! isset( $_POST['_tzfd_nonce'] ) ) { return null; }
        if ( ! current_user_can( 'manage_options' ) ) { return null; }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tzfd_nonce'] ?? '' ) ), $action ) ) { return null; }
        return self::posted_with_prefix( 'tzfd_global_' . $tzfd_key . '_', $_POST );
    }

    /**
     * Collect the posted fields that start with $prefix. $posted is the request
     * body; the callers pass it only after they have verified the nonce.
     */
    private static function posted_with_prefix( $prefix, array $posted ) {
        $values = array();
        foreach ( $posted as $field => $tzfd_value ) {
            $field = (string) $field;
            if ( 0 !== strpos( $field, $prefix ) ) { continue; }
            $values[ substr( $field, strlen( $prefix ) ) ] = is_string( $tzfd_value ) ? wp_unslash( $tzfd_value ) : '';
        }
        return $values;
    }

    /**
     * Render a settings table. $specs maps field name => array(label, help, type)
     * where type is one of: text, int, bool, textarea, password. Passwords are
     * never echoed back; the field is left empty and "keep current" is implied.
     */
    public static function render_table( $prefix, array $specs, array $values ) {
        echo '<table class="form-table" role="presentation" style="width:100%;">';
        foreach ( $specs as $name => $spec ) {
            $id    = $prefix . $name;
            $type  = isset( $spec['type'] ) ? $spec['type'] : 'text';
            $tzfd_value = isset( $values[ $name ] ) ? $values[ $name ] : '';
            echo '<tr><th scope="row" style="width:220px;text-align:left;vertical-align:top;padding:8px 10px 8px 0;"><label for="' . esc_attr( $id ) . '">' . esc_html( $spec['label'] ) . '</label></th><td style="padding:8px 0;">';
            switch ( $type ) {
                case 'bool':
                    echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="1" ' . checked( (bool) $tzfd_value, true, false ) . ' />';
                    break;
                case 'int':
                    echo '<input type="number" min="0" step="1" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) (int) $tzfd_value ) . '" class="small-text" />';
                    break;
                case 'textarea':
                    echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" rows="4" class="large-text">' . esc_textarea( (string) $tzfd_value ) . '</textarea>';
                    break;
                case 'password':
                    echo '<input type="password" autocomplete="new-password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="" class="regular-text" placeholder="' . esc_attr__( 'Leave blank to keep the current value', 'titan-zero-trades-front-desk' ) . '" />';
                    break;
                default:
                    echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $tzfd_value ) . '" class="large-text" />';
            }
            if ( ! empty( $spec['help'] ) ) {
                echo '<p class="description">' . esc_html( $spec['help'] ) . '</p>';
            }
            echo '</td></tr>';
        }
        echo '</table>';
    }

    /* ---------------------------------------------------------------
     * Pure helpers
     * ------------------------------------------------------------- */

    /**
     * Start (or end) of a booked appointment as a timestamp-bearing object.
     * $tzfd_date is Y-m-d; $slot is "HH:MM/HH:MM" (24-hour), as stored in bookings.
     *
     * @return DateTimeImmutable|false
     */
    public static function slot_boundary( $tzfd_date, $slot, $which, $tz ) {
        if ( ! is_string( $tzfd_date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tzfd_date ) ) { return false; }
        if ( ! is_string( $slot ) || 1 !== preg_match( '/^(\d{2}:\d{2})\/(\d{2}:\d{2})$/', trim( $slot ), $m ) ) { return false; }
        $time = ( 'end' === $which ) ? $m[2] : $m[1];
        $tzfd_hour = (int) substr( $time, 0, 2 );
        $min  = (int) substr( $time, 3, 2 );
        if ( $min > 59 || $tzfd_hour > 24 || ( 24 === $tzfd_hour && $min > 0 ) ) { return false; }
        if ( ! ( $tz instanceof DateTimeZone ) ) { $tz = new DateTimeZone( 'UTC' ); }
        try {
            if ( 24 === $tzfd_hour ) {
                $day = new DateTimeImmutable( $tzfd_date . ' 00:00:00', $tz );
                return $day->modify( '+1 day' );
            }
            return new DateTimeImmutable( sprintf( '%s %02d:%02d:00', $tzfd_date, $tzfd_hour, $min ), $tz );
        } catch ( Exception $e ) {
            return false;
        }
    }

    /** Replace %name% tokens with values. Unknown tokens are left untouched. */
    public static function fill_template( $template, array $values ) {
        $out = (string) $template;
        foreach ( $values as $name => $tzfd_value ) {
            $out = str_replace( '%' . $name . '%', (string) $tzfd_value, $out );
        }
        return $out;
    }

    /** Email subjects must not carry line breaks (header injection). */
    public static function single_line( $text ) {
        return trim( preg_replace( '/[\r\n]+/', ' ', (string) $text ) );
    }

    /** Split a free-text list on newlines, commas or semicolons. */
    public static function parse_entries( $text ) {
        $out = array();
        foreach ( preg_split( '/[\r\n,;]+/', (string) $text ) as $tzfd_item ) {
            $tzfd_item = strtolower( trim( $tzfd_item ) );
            if ( '' !== $tzfd_item ) { $out[] = $tzfd_item; }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * True when an address matches a list entry. Entries may be a full address
     * ("x@example.com"), a domain ("example.com") or "@example.com". A domain
     * entry also matches its subdomains.
     */
    public static function address_listed( $address, array $entries ) {
        $address = strtolower( trim( (string) $address ) );
        if ( '' === $address || false === strpos( $address, '@' ) ) { return false; }
        $domain = substr( $address, strrpos( $address, '@' ) + 1 );
        foreach ( $entries as $tzfd_entry ) {
            $tzfd_entry = ltrim( strtolower( trim( (string) $tzfd_entry ) ), '@' );
            if ( '' === $tzfd_entry ) { continue; }
            if ( false !== strpos( $tzfd_entry, '@' ) ) {
                if ( $address === $tzfd_entry ) { return true; }
                continue;
            }
            if ( $domain === $tzfd_entry || substr( $domain, -strlen( '.' . $tzfd_entry ) ) === '.' . $tzfd_entry ) { return true; }
        }
        return false;
    }

    /**
     * Empty expected password means no password is required. A stored hash is
     * checked with password_verify; a legacy plain value with hash_equals.
     */
    public static function password_matches( $given, $expected ) {
        $expected = (string) $expected;
        if ( '' === $expected ) { return true; }
        if ( ! is_string( $given ) ) { return false; }
        if ( self::is_password_hash( $expected ) ) { return password_verify( $given, $expected ); }
        return hash_equals( $expected, $given );
    }

    /** A limit of 0 means unlimited. */
    public static function exceeds_limit( $tzfd_count, $limit ) {
        $limit = (int) $limit;
        return $limit > 0 && (int) $tzfd_count > $limit;
    }

    /** Status stored on one appointment. An empty string means Approved. */
    public static function appointment_status( $tzfd_app ) {
        return ( is_array( $tzfd_app ) && isset( $tzfd_app['cancelled'] ) && is_string( $tzfd_app['cancelled'] ) ) ? $tzfd_app['cancelled'] : '';
    }

    /** True when every appointment in the booking has exactly this status. */
    public static function all_have_status( array $apps, $status ) {
        if ( empty( $apps ) ) { return false; }
        foreach ( $apps as $tzfd_app ) {
            if ( self::appointment_status( $tzfd_app ) !== $status ) { return false; }
        }
        return true;
    }

    /** True when a booking created at $created_local (site-local MySQL time) is at least $hours old. */
    public static function is_older_than( $created_local, $hours, $tz, $now_ts ) {
        $hours = (int) $hours;
        if ( $hours <= 0 || ! is_string( $created_local ) || '' === $created_local ) { return false; }
        try {
            $created = new DateTimeImmutable( $created_local, $tz instanceof DateTimeZone ? $tz : new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return false;
        }
        return $created->getTimestamp() <= ( (int) $now_ts - $hours * 3600 );
    }

    /**
     * Webhook destination settings, or null when invalid. Requires an https
     * URL without credentials or fragment, and a secret of at least 32 bytes.
     */
    public static function webhook_settings( $url, $secret ) {
        $url    = trim( (string) $url );
        $secret = (string) $secret;
        if ( '' === $url || strlen( $secret ) < 32 ) { return null; }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) { return null; }
        if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return null; }
        return array( 'url' => $url, 'secret' => $secret );
    }

    /** Digits only, for wa.me links. */
    public static function whatsapp_url( $number, $tzfd_message ) {
        $digits = preg_replace( '/\D+/', '', (string) $number );
        if ( strlen( $digits ) < 6 || strlen( $digits ) > 15 ) { return ''; }
        return 'https://wa.me/' . $digits . '?text=' . rawurlencode( (string) $tzfd_message );
    }

    /** sms: link with a leading + kept when present. */
    public static function sms_url( $number, $tzfd_message ) {
        $clean = preg_replace( '/[^\d+]/', '', (string) $number );
        if ( 1 !== preg_match( '/^\+?\d{6,15}$/', $clean ) ) { return ''; }
        return 'sms:' . $clean . '?body=' . rawurlencode( (string) $tzfd_message );
    }

    /** Keep the newest $max entries of a log. */
    public static function append_log( array $log, array $tzfd_entry, $max = 500 ) {
        $log[] = $tzfd_entry;
        if ( count( $log ) > $max ) { $log = array_slice( $log, -$max ); }
        return $log;
    }

    /* ---------------------------------------------------------------
     * Add-on settings blocks (shown on the Add-ons page) and API key storage
     * ------------------------------------------------------------- */

    /** Save and render a site-wide settings form for one add-on. */
    public static function global_settings_block( $key, array $defaults, array $specs, $intro = '' ) {
        $saved   = false;
        $request = self::global_request( $key );
        if ( null !== $request ) {
            $current = self::global_settings( $key, $defaults );
            $new     = array();
            foreach ( array_keys( $defaults ) as $k ) {
                $new[ $k ] = isset( $request[ $k ] ) ? $request[ $k ] : '';
            }
            foreach ( $specs as $name => $spec ) {
                if ( isset( $spec['type'] ) && 'password' === $spec['type'] && '' === trim( (string) $new[ $name ] ) ) {
                    $new[ $name ] = $current[ $name ]; // blank keeps the stored value
                }
            }
            self::save_global_settings( $key, $defaults, $new );
            $saved = true;
        }
        $stored = self::global_settings( $key, $defaults );
        ob_start();
        echo '<div class="tzfd-addon-settings">';
        if ( $saved ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( '' !== $intro ) { echo '<p>' . esc_html( $intro ) . '</p>'; }
        echo '<form method="post">';
        wp_nonce_field( 'tzfd_global_' . $key, '_tzfd_nonce' );
        echo '<input type="hidden" name="tzfd_save_' . esc_attr( $key ) . '" value="1" />';
        self::render_table( 'tzfd_global_' . $key . '_', $specs, $stored );
        submit_button( __( 'Save settings', 'titan-zero-trades-front-desk' ), 'secondary', 'submit', false );
        echo '</form></div>';
        return ob_get_clean();
    }

    /** Short status block for an add-on with no site-wide fields. */
    public static function info_block( $text, array $links = array() ) {
        $html = '<div class="tzfd-addon-settings"><p>' . esc_html( $text ) . '</p>';
        foreach ( $links as $label => $url ) {
            $html .= '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
        }
        return $html . '</div>';
    }

    /**
     * True once an AI key or a local AI server has been saved (Settings > AI, or the AI Setup
     * wizard), or once a site supplies a provider through the tzfd_ai_provider filter.
     * Every AI feature checks this first. Without it, the rest of the plugin works as normal.
     */
    public static function ai_configured() {
        if ( '' !== (string) get_option( 'tzfd_ai_api_key', '' ) ) { return true; }
        return apply_filters( 'tzfd_ai_provider', null ) instanceof TZFD_AI_Provider;
    }

    /** Encrypts an API key before storage. Same format as the AI add-on, so either can read it. */
    public static function secret_encrypt( $plain ) {
        // Never store an AI key unencrypted. Without OpenSSL nothing is saved; the AI page then shows it is not set up.
        if ( ! function_exists( 'openssl_encrypt' ) ) { return ''; }
        $k  = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv = openssl_random_pseudo_bytes( 16 );
        return 'enc:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', $k, OPENSSL_RAW_DATA, $iv ) );
    }

    public static function secret_decrypt( $stored ) {
        $stored = (string) $stored;
        if ( '' === $stored ) { return ''; }
        if ( strpos( $stored, 'enc:' ) !== 0 ) {
            // Legacy plain storage is base64 only. A value that is not valid base64 gives no secret.
            $plain = base64_decode( $stored, true );
            return false === $plain ? '' : $plain;
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) { return ''; }
        $raw = base64_decode( substr( $stored, 4 ), true );
        // A 16-byte IV followed by at least one cipher block. Anything shorter is not a stored secret.
        if ( false === $raw || strlen( $raw ) <= 16 ) { return ''; }
        $iv  = substr( $raw, 0, 16 );
        $ct  = substr( $raw, 16 );
        $k   = hash( 'sha256', wp_salt( 'auth' ), true );
        $plain = openssl_decrypt( $ct, 'aes-256-cbc', $k, OPENSSL_RAW_DATA, $iv );
        return false === $plain ? '' : $plain;
    }
}
