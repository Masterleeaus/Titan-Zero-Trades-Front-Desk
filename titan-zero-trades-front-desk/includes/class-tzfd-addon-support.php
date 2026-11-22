<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/../includes/class-tzfd-request-scalar.php';

/**
 * Shared helpers for the Titan Zero Trades: Front Desk add-on implementations.
 *
 * Most methods are pure (no database or WordPress state) so they can be
 * exercised directly. Storage and rendering helpers are thin wrappers over
 * the WordPress options and form APIs. GPL-2.0-only.
 */
final class TZFD_Addon_Support {
    const OPTION_PREFIX = 'titan_zero_front_desk_addon_';
    const NONCE_ACTION  = 'tz_fd_actions_admin';

    /**
     * Fields stored as one-way hashes, keyed by add-on. A hashed field cannot be
     * read back, so an administrator enters a new value to change it.
     */
    const HASHED_FIELDS = array(
        'password' => array( 'password' ),
    );

    /**
     * Credential fields in site-wide settings. Stored encrypted with secret_encrypt(), decrypted on read.
     * Legacy plain values are encrypted on first read.
     */
    const SECRET_FIELD_NAMES = array( 'api_key', 'webhook_secret', 'secret', 'inbound_secret', 'sms_auth_value' );

    /* ---------------------------------------------------------------
     * Settings storage (wp_options, autoload off)
     * ------------------------------------------------------------- */

    /**
     * A calendar belongs to exactly one company. Never use a form option unless
     * the current Workspace company owns that calendar. Older options are
     * copied for the verified owner only, preserving saved configuration.
     */
    public static function form_option_name( $tzfd_key, $formid ) {
        $formid = absint( $formid );
        $legacy = self::OPTION_PREFIX . sanitize_key( $tzfd_key ) . '_form_' . $formid;
        if ( ! class_exists( 'TZFD_Scoped_Store' ) || $formid < 1 ) { return ''; }
        $company = TZFD_Scoped_Store::company_id();
        if ( $company < 1 || $company !== TZFD_Scoped_Store::company_for_form( $formid ) ) { return ''; }
        $scoped = TZFD_Scoped_Store::option_name( $legacy, $company );
        if ( null === get_option( $scoped, null ) ) {
            $old = get_option( $legacy, null );
            if ( null !== $old ) {
                add_option( $scoped, $old, '', false );
            }
        }
        return $scoped;
    }

    public static function global_option_name( $tzfd_key ) {
        $legacy = self::OPTION_PREFIX . sanitize_key( $tzfd_key ) . '_global';
        if ( ! class_exists( 'TZFD_Scoped_Store' ) ) { return ''; }
        $company = class_exists( 'TZFD_Scoped_Store' ) ? TZFD_Scoped_Store::company_id() : 0;
        if ( $company > 0 ) {
            $scoped = TZFD_Scoped_Store::option_name( $legacy, $company );
            if ( $company === TZFD_Scoped_Store::legacy_owner() && null === get_option( $scoped, null ) ) {
                $old = get_option( $legacy, null );
                if ( null !== $old ) { add_option( $scoped, $old, '', false ); }
            }
            return $scoped;
        }
        // No resolved company: never expose an earlier site's global defaults.
        return '';
    }

    /** Per-form settings merged over defaults. Unknown stored keys are ignored. */
    public static function form_settings( $tzfd_key, $formid, array $defaults ) {
        // Site-wide defaults apply to calendars that have no value of their own yet.
        $form_option = self::form_option_name( $tzfd_key, $formid );
        if ( '' === $form_option ) { return $defaults; }
        $global_option = self::global_option_name( $tzfd_key );
        $site = self::merge_stored( '' === $global_option ? array() : get_option( $global_option, array() ), $defaults );
        return self::merge_stored( get_option( $form_option, array() ), $site );
    }

    /**
     * Resolve settings for a scheduled task without an interactive Workspace selection.
     * Only background callers (no logged-in user) can use this method. Ownership is
     * independently checked against the authoritative form row. Normal UI paths
     * continue using form_settings() and their current-company checks.
     */
    public static function form_settings_for_job( $tzfd_key, $formid, $company_id, array $defaults ) {
        $formid = absint( $formid );
        $company_id = absint( $company_id );
        if ( $formid < 1 || $company_id < 1 || is_user_logged_in() ||
             ! class_exists( 'TZFD_Scoped_Store' ) ||
             TZFD_Scoped_Store::company_for_form( $formid ) !== $company_id ) { return $defaults; }
        $legacy = self::OPTION_PREFIX . sanitize_key( $tzfd_key );
        $form_name = $legacy . '_form_' . $formid;
        $global_name = $legacy . '_global';
        $form_option = TZFD_Scoped_Store::option_name( $form_name, $company_id );
        $global_option = TZFD_Scoped_Store::option_name( $global_name, $company_id );
        $form_stored = get_option( $form_option, null );
        $global_stored = get_option( $global_option, null );
        // Older unscoped form values belong to the verified owning company.
        if ( null === $form_stored ) {
            $form_stored = get_option( $form_name, array() );
            if ( is_array( $form_stored ) && $form_stored ) { add_option( $form_option, $form_stored, '', false ); }
        }
        if ( null === $global_stored && $company_id === TZFD_Scoped_Store::legacy_owner() ) {
            $global_stored = get_option( $global_name, array() );
            if ( is_array( $global_stored ) && $global_stored ) { add_option( $global_option, $global_stored, '', false ); }
        }
        $global = self::merge_stored( $global_stored, $defaults );
        return self::merge_stored( $form_stored, $global );
    }

    /**
     * Cron-only, explicitly company-scoped integration settings. Never resolve
     * credentials through the currently selected Workspace company in a worker.
     * The queue booking owner is checked against the booking table first.
     * No settings are returned when the tenant/booking cannot be verified.
     * Does not migrate unscoped legacy credentials across tenants.
     *
     * @return array|null Null means access denied or no settings saved.
     */
    public static function global_settings_for_job( $tzfd_key, $company, $booking_id, array $defaults ) {
        if ( ! is_string( $tzfd_key ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $tzfd_key )
            || ! is_int( $company ) || $company < 1 || ! is_int( $booking_id ) || $booking_id < 1
            || is_user_logged_in() || ! class_exists( 'TZFD_Scoped_Store' )
            || ! class_exists( 'TZFD_Addon_Records' )
            || ! TZFD_Addon_Records::booking_belongs_to_company( $company, $booking_id ) ) { return null; }
        $legacy = self::OPTION_PREFIX . $tzfd_key . '_global';
        $scoped = TZFD_Scoped_Store::option_name( $legacy, $company );
        $stored = get_option( $scoped, null );
        return is_array( $stored ) ? self::reveal_secrets( self::merge_stored( $stored, $defaults ) ) : null;
    }

    /** Global (site-wide) settings merged over defaults. Credential fields are returned decrypted. */
    public static function global_settings( $tzfd_key, array $defaults ) {
        $option = self::global_option_name( $tzfd_key );
        $raw    = '' === $option ? array() : get_option( $option, array() );
        $stored = self::merge_stored( $raw, $defaults );
        $legacy = false;
        foreach ( self::SECRET_FIELD_NAMES as $field ) {
            if ( isset( $stored[ $field ] ) && is_string( $stored[ $field ] ) && '' !== $stored[ $field ] && 0 !== strpos( $stored[ $field ], self::SECRET_PREFIX ) ) {
                $legacy = true;
            }
        }
        if ( $legacy && '' !== $option && is_array( $raw ) ) {
            // First read of a credential stored in an older format: store it in the current format.
            // A failed conversion keeps the old value.
            foreach ( self::SECRET_FIELD_NAMES as $field ) {
                if ( ! isset( $raw[ $field ] ) || ! is_string( $raw[ $field ] ) || '' === $raw[ $field ] ) { continue; }
                $raw[ $field ] = self::secret_upgrade( $raw[ $field ] );
            }
            update_option( $option, $raw, false );
        }
        return self::reveal_secrets( $stored );
    }

    /** Decrypts credential fields for use. Values without a known encrypted prefix are returned as they are. */
    public static function reveal_secrets( array $settings ) {
        foreach ( self::SECRET_FIELD_NAMES as $field ) {
            if ( isset( $settings[ $field ] ) && is_string( $settings[ $field ] ) && self::is_encrypted( $settings[ $field ] ) ) {
                $settings[ $field ] = self::secret_decrypt( $settings[ $field ] );
            }
        }
        return $settings;
    }

    /** Encrypts non-empty credential fields. If encryption is unavailable, the previously stored value is kept. */
    private static function protect_secrets( array $clean, array $existing ) {
        foreach ( self::SECRET_FIELD_NAMES as $field ) {
            if ( ! isset( $clean[ $field ] ) || ! is_string( $clean[ $field ] ) || '' === $clean[ $field ] ) { continue; }
            $enc = self::secret_encrypt( $clean[ $field ] );
            $clean[ $field ] = '' !== $enc ? $enc : ( isset( $existing[ $field ] ) && is_string( $existing[ $field ] ) ? $existing[ $field ] : '' );
        }
        return $clean;
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
            // Never turn an array/object submitted for a scalar setting into true or 1.
            if ( null !== $raw && ! is_string( $raw ) && ! is_int( $raw ) && ! is_bool( $raw ) ) {
                $clean[ $name ] = $default;
                continue;
            }
            if ( is_bool( $default ) ) {
                $clean[ $name ] = ( null !== $raw && '' !== $raw && '0' !== $raw && false !== $raw );
            } elseif ( is_int( $default ) ) {
                $clean[ $name ] = ( null !== $raw && preg_match( '/^\d{1,6}$/D', (string) $raw ) ) ? max( 0, min( 100000, (int) $raw ) ) : $default;
            } else {
                $clean[ $name ] = is_string( $raw ) ? trim( sanitize_textarea_field( $raw ) ) : '';
            }
        }
        return $clean;
    }

    public static function save_form_settings( $tzfd_key, $formid, array $defaults, array $input ) {
        $clean = self::store_hashed_fields( $tzfd_key, self::sanitize_settings( $defaults, $input ) );
        $option = self::form_option_name( $tzfd_key, $formid );
        if ( '' === $option ) { return false; }
        update_option( $option, $clean, false );
        return $clean;
    }

    public static function save_global_settings( $tzfd_key, array $defaults, array $input ) {
        $clean = self::store_hashed_fields( $tzfd_key, self::sanitize_settings( $defaults, $input ) );
        $option = self::global_option_name( $tzfd_key );
        if ( '' === $option ) { return false; }
        $existing = get_option( $option, array() );
        $clean = self::protect_secrets( $clean, is_array( $existing ) ? $existing : array() );
        update_option( $option, $clean, false );
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
            if ( '' === $name ) { continue; }
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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- method compared exactly to 'POST'; a sanitizer could strip %xx and change the result.
        if ( 'POST' !== wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ $marker ] ) || ! isset( $_POST['anonce'] ) ) { return null; }
        if ( ! current_user_can( 'tz_fd_access' ) || '' === self::form_option_name( $tzfd_key, $formid ) ) { return null; }
        if ( ! is_string( $_POST['anonce'] ) || ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), self::NONCE_ACTION ) ) { return null; }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ctype_digit() and the int cast accept digits only; a sanitizer could change which values match.
        if ( ! is_scalar( $_POST[ $marker ] ) || ! ctype_digit( (string) wp_unslash( $_POST[ $marker ] ) ) || (int) wp_unslash( $_POST[ $marker ] ) !== (int) $formid ) { return null; }
        return self::posted_with_prefix( 'tz_fd_' . $tzfd_key . '_', $_POST );
    }

    /** Values posted from an add-on's site-wide settings block, or null. */
    public static function global_request( $tzfd_key ) {
        $action = 'tz_fd_global_' . $tzfd_key;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- method compared exactly to 'POST'; a sanitizer could strip %xx and change the result.
        if ( 'POST' !== wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ 'tz_fd_save_' . $tzfd_key ] ) || ! isset( $_POST['_tz_fd_nonce'] ) ) { return null; }
        if ( ! current_user_can( 'tz_fd_access' ) || '' === self::global_option_name( $tzfd_key ) ) { return null; }
        if ( ! is_string( $_POST['_tz_fd_nonce'] ) || ! wp_verify_nonce( tzfd_request_text( 'post', '_tz_fd_nonce' ), $action ) ) { return null; }
        return self::posted_with_prefix( 'tz_fd_global_' . $tzfd_key . '_', $_POST );
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
            // Invalid nested values invalidate the entire settings submission; silently
            // converting them to blank could erase API credentials or enable a flag.
            if ( ! is_string( $tzfd_value ) ) { return null; }
            $values[ substr( $field, strlen( $prefix ) ) ] = wp_unslash( $tzfd_value );
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
        if ( ! is_string( $tzfd_date ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $tzfd_date, $date_parts ) ||
             ! checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] ) ) { return false; }
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
        $out = is_string( $template ) ? $template : '';
        foreach ( $values as $name => $tzfd_value ) {
            if ( ! is_string( $name ) || ! is_scalar( $tzfd_value ) ) { continue; }
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
        foreach ( $entries as $tzfd_local_entry ) {
            $tzfd_local_entry = ltrim( strtolower( trim( (string) $tzfd_local_entry ) ), '@' );
            if ( '' === $tzfd_local_entry ) { continue; }
            if ( false !== strpos( $tzfd_local_entry, '@' ) ) {
                if ( $address === $tzfd_local_entry ) { return true; }
                continue;
            }
            if ( $domain === $tzfd_local_entry || substr( $domain, -strlen( '.' . $tzfd_local_entry ) ) === '.' . $tzfd_local_entry ) { return true; }
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
    public static function exceeds_limit( $tzfd_local_count, $limit ) {
        $limit = (int) $limit;
        return $limit > 0 && (int) $tzfd_local_count > $limit;
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
        if ( ! is_string( $url ) || ! is_string( $secret ) ) { return null; }
        $url = trim( $url );
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
    public static function append_log( array $log, array $tzfd_local_entry, $max = 500 ) {
        $log[] = $tzfd_local_entry;
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
            $saved = false !== self::save_global_settings( $key, $defaults, $new );
        }
        $stored = self::global_settings( $key, $defaults );
        ob_start();
        echo '<div class="tzfd-addon-settings">';
        if ( $saved ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        if ( '' !== $intro ) { echo '<p>' . esc_html( $intro ) . '</p>'; }
        echo '<form method="post">';
        wp_nonce_field( 'tz_fd_global_' . $key, '_tz_fd_nonce' );
        echo '<input type="hidden" name="tz_fd_save_' . esc_attr( $key ) . '" value="1" />';
        self::render_table( 'tz_fd_global_' . $key . '_', $specs, $stored );
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

    /*
     * Stored credentials
     *
     * Current format: "gcm1:" + base64( 12-byte IV | 16-byte tag | ciphertext ), AES-256-GCM. GCM checks the data,
     * so an altered value is rejected rather than decrypted to garbage. The key is derived from the site's auth salt
     * with a purpose label, so it is not the same key material used for other signatures on the site.
     * Values in the earlier "enc:" format are not read. This version has no existing installs, so they read as empty
     * and the credential is entered again.
     */
    const SECRET_PREFIX = 'gcm1:';

    private static function secret_key() {
        return hash_hmac( 'sha256', 'titan-zero-trades-front-desk/credential-encryption/v2', wp_salt( 'auth' ), true );
    }

    public static function is_encrypted( $stored ) {
        if ( ! is_string( $stored ) ) { return false; }
        return 0 === strpos( $stored, self::SECRET_PREFIX );
    }

    /** Encrypts a credential for storage. Returns '' if encryption is unavailable or fails, so callers keep the old value. */
    public static function secret_encrypt( $plain ) {
        if ( ! function_exists( 'openssl_encrypt' ) || ! is_string( $plain ) || '' === $plain ) { return ''; }
        if ( ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) { return ''; }
        $iv     = random_bytes( 12 );
        $tag    = '';
        $cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        if ( false === $cipher || 16 !== strlen( $tag ) ) { return ''; }
        return self::SECRET_PREFIX . base64_encode( $iv . $tag . $cipher );
    }

    /** Returns the plain credential, or '' if the stored value is missing, damaged or was altered. */
    public static function secret_decrypt( $stored ) {
        $stored = (string) $stored;
        if ( '' === $stored ) { return ''; }
        if ( 0 === strpos( $stored, self::SECRET_PREFIX ) ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) { return ''; }
            $raw = base64_decode( substr( $stored, strlen( self::SECRET_PREFIX ) ), true );
            if ( false === $raw || strlen( $raw ) <= 28 ) { return ''; }
            $plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
            return false === $plain ? '' : $plain;
        }
        if ( 0 === strpos( $stored, 'enc:' ) ) {
            // Earlier format, no longer read. The credential must be entered again.
            return '';
        }
        // No prefix: stored before any encryption. Returned as it was saved, as before.
        return $stored;
    }

    /** Puts a stored credential into the current format. A value already current, or one that cannot be converted, is returned unchanged. */
    public static function secret_upgrade( $stored ) {
        $stored = (string) $stored;
        if ( '' === $stored || 0 === strpos( $stored, self::SECRET_PREFIX ) || 0 === strpos( $stored, 'enc:' ) ) { return $stored; }
        $plain = $stored;
        if ( '' === $plain ) { return $stored; }
        $enc = self::secret_encrypt( $plain );
        return '' !== $enc ? $enc : $stored;
    }
}
