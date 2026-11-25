<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.
require_once dirname( __DIR__ ) . '/includes/class-tzfd-request-scalar.php';

class TZFD_BOOK_BaseClass {

    protected $item = 1;

    /** Restore every WordPress blog switch, including a failing site installation. */
    public function install( $networkwide ) {
        global $wpdb;
        if ( function_exists( 'is_multisite' ) && is_multisite() && $networkwide ) {
            $blogids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
            foreach ( $blogids as $blog_id ) {
                switch_to_blog( (int) $blog_id );
                try {
                    $this->_install();
                } finally {
                    restore_current_blog();
                }
            }
            return;
        }
        $this->_install();
    }

    /**
     * Generic reader for one request value: GET first, then POST. The value is sanitised before it is returned.
     *
     * This helper is a reader only. It verifies no nonce and checks no capability, and that is intentional: the
     * callers differ (routing, display, read-only lookups, and several different nonce actions), so a single check
     * here would be wrong for most of them. A caller that changes data, or returns private data, verifies its own
     * nonce and capability before it acts on any value read through here.
     *
     * Data-changing callers, each verified in its own code before any posted value is used:
     * - Booking submission in tzfd-main-class.inc.php (POST with the booking process field): anonce, tz_fd_actions_bookingform.
     *   It also reads the captcha answer field (hdcaptcha_<prefix>_post) and compares it. That field is not stored.
     * - _blockmultiple route, then block_multiple(): anonce, tz_fd_actions_admin, is_admin(); per calendar, tz_fd_access
     *   or the calendar's access list.
     * - _post_options route, then save_options(): anonce, tz_fd_actions_admin, edit_pages.
     * - tz_fd_do_action_loaded=wizard, then data_management_loaded(): anonce, tz_fd_actions_pwizard, tz_fd_access.
     *   tz_fd_hour_id is read in the same routine to choose the calendar; the publish wizard screen reads it for display.
     *
     * Read-only callers that return private data:
     * - _csv1 and _csv2 exports: anonce (GET or POST), tz_fd_actions_booking, check_current_user_access(). The export
     *   reads the search, dfrom and dto filters (export_csv()) and the cal value.
     *
     * Every other caller (page, cal, list, report, schedule, addbk, blocktimes, pwizard, captcha, id) only
     * routes or displays. The admin screens sit behind the edit_pages gate in route_front_desk() and the per-calendar
     * access checks in their own files.
     */
    public function get_param( $tzfd_key ) {
        if ( ! is_string( $tzfd_key ) || '' === $tzfd_key || strlen( $tzfd_key ) > 120 ) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads a request value for routing, display or a read-only lookup. Changes no data here; a data-changing caller verifies its own nonce first.
        if (isset($_GET[$tzfd_key]) && tzfd_request_text( 'get', $tzfd_key ) != '')
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Same reason as above; the value is sanitised before it is returned.
            return $this->sanitize(tzfd_request_text( 'get', $tzfd_key ));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reads a request value for routing, display or a read-only lookup. Changes no data here; a data-changing caller verifies its own nonce first.
        else if (isset($_POST[$tzfd_key]) && tzfd_request_text( 'post', $tzfd_key ) != '')
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Same reason as above; the value is sanitised before it is returned.
            return $this->sanitize(tzfd_request_text( 'post', $tzfd_key ));
        else
            return '';
    }

    public function is_administrator() {
        return current_user_can('tz_fd_access');
    }

    public function get_site_url( $admin = false ) {
        $blog = get_current_blog_id();
        if( $admin )
            $url = get_admin_url( $blog );
        else
            $url = get_home_url( $blog );

        // The WordPress-configured origin is trusted over request headers.
        return esc_url_raw( untrailingslashit( $url ) . '/' );
    }

    public function get_FULL_site_url( $admin = false ) {
        $blog = get_current_blog_id();
        if( $admin )
            $url = get_admin_url( $blog );
        else
            $url = get_home_url( $blog );

        // The configured URL is authoritative. Never reconstruct the origin
        // from HTTP_HOST, which is supplied by the incoming HTTP request.
        // Preserve the configured scheme, hostname, port and subdirectory.
        return esc_url_raw( untrailingslashit( $url ) . '/' );
    }

    public function cleanJSON ( $str ) {
        $str = str_replace('&qquot;','"',$str);
        $str = str_replace('	',' ',$str);
        $str = str_replace("\n",'\n',$str);
        $str = str_replace("\r",'',$str);
        return $str;
    }

    /** Preserve input keys and cap recursion; never grow sparse arrays while iterating. */
    public function sanitize ( $tzfd_local_v, $depth = 0 ) {
        if ( $depth >= 32 ) { return ''; }
        if ( is_array( $tzfd_local_v ) ) {
            $clean = array();
            foreach ( $tzfd_local_v as $tzfd_key => $tzfd_value ) {
                $clean[$tzfd_key] = $this->sanitize( $tzfd_value, $depth + 1 );
            }
            return $clean;
        }
        if ( ! is_scalar( $tzfd_local_v ) && null !== $tzfd_local_v ) { return ''; }
        $allowed_tags = wp_kses_allowed_html( 'post' );
        unset( $allowed_tags['script'], $allowed_tags['iframe'] );
        return $this->clean_sanitize( wp_kses( (string) $tzfd_local_v, $allowed_tags ) );
    }

    public function clean_sanitize ( $str, $depth = 0 ) {
        if ( $depth >= 32 ) { return ''; }
        if ( is_array( $str ) ) {
            $clean = array();
            foreach ( $str as $tzfd_key => $tzfd_value ) {
                $clean[$tzfd_key] = $this->clean_sanitize( $tzfd_value, $depth + 1 );
            }
            return $clean;
        }
        if ( ! is_scalar( $str ) && null !== $str ) { return ''; }
        return trim( wp_check_invalid_utf8( (string) $str ) );
    }


    public function sanitizeTableName ( $tzfd_local_v ) {
        $tzfd_local_v = $this->sanitize( $tzfd_local_v );
        $tzfd_local_v = str_replace( '"', '', $tzfd_local_v );
        $tzfd_local_v = str_replace( "'", "", $tzfd_local_v );
        $tzfd_local_v = str_replace( '`', '', $tzfd_local_v );
        $tzfd_local_v = str_replace( ' ', '', $tzfd_local_v );
		return $tzfd_local_v;
	}


    public function recursive_implode( $glue, array $array, $include_keys = false, $trim_all = true ) {
    	$glued_string = '';

    	// Recursively iterates array and adds key/value to glued string
    	array_walk_recursive($array, function($tzfd_value, $tzfd_key) use ($glue, $include_keys, &$glued_string)
    	{
    		$include_keys and $glued_string .= $tzfd_key.$glue;
    		$glued_string .= $tzfd_value.$glue;
    	});

    	// Removes last $glue from string
    	strlen($glue) > 0 and $glued_string = substr($glued_string, 0, -strlen($glue));

    	// Trim ALL whitespace
    	$trim_all and $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);

    	return (string) $glued_string;
    }


    /** Adds a missing TEXT column. Table and column names are bound with %i; only the text type is created. */
    public function add_field_verify ( $table, $field, $type = "text" ) {
        global $wpdb;
        $table_name = $this->sanitizeTableName($table);
        $results = $wpdb->get_results( $wpdb->prepare( "SHOW columns FROM %i where field=%s", $table_name, $field ) );
        if (!count($results))
        {
            $field_name = $this->sanitizeTableName($field);
            switch ( strtolower( (string) $this->sanitizeTableName($type) ) ) {
                case 'text':
                    $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD %i TEXT", $table_name, $field_name ) );
                    break;
                default:
                    _doing_it_wrong( __METHOD__, 'Only the text column type is supported.', '2.0.1' );
            }
        }
    }

    function verify_nonce ( $tzfd_nonce, $action ) {
        $tzfd_nonce = is_string( $tzfd_nonce ) ? sanitize_text_field( wp_unslash( $tzfd_nonce ) ) : '';
        if ( ! wp_verify_nonce( $tzfd_nonce, $action ) ) { $this->nonce_failed(); }
    }

    /** Stops the request with the standard message when a nonce check fails. */
    function nonce_failed() {
        echo esc_html__( 'Error: Action cannot be authenticated (nonce failed). Please contact our support service if this problem persists.', 'titan-zero-trades-front-desk' );
        exit;
    }



    public $option_buffered_item = false;
    public $option_buffered_id = -1;

    public function get_option ( $field, $default_value = '' ) {
        global $wpdb;
        if ($this->option_buffered_id == $this->item)
            $tzfd_value = (property_exists($this->option_buffered_item, $field) && isset($this->option_buffered_item->$field) ? $this->option_buffered_item->$field : $default_value);
        else
        {
           $tzfd_local_row = TZFD_Form_Access::row( $this->item );
           $tzfd_myrows = $tzfd_local_row ? array( $tzfd_local_row ) : array(); 
           if (count($tzfd_myrows))
           {
               $tzfd_value = isset($tzfd_myrows[0]->$field) ? $tzfd_myrows[0]->$field : $default_value;
               $this->option_buffered_item = $tzfd_myrows[0];
               $this->option_buffered_id  = $this->item;
           }
           else
               $tzfd_value =  $default_value;
        }
        if ( $tzfd_value == '' && is_object( $this->option_buffered_item ) && $this->option_buffered_item->form_structure == '' )
            $tzfd_value = $default_value;

        $tzfd_value = apply_filters( 'tzfd_get_option', $tzfd_value, $field, $this->item );
        $tzfd_value = apply_filters( 'tz_fd_get_option', $tzfd_value, $field, $this->item ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy alias: fires the pre-rename hook name for one release

        return $tzfd_value;
    }

    public function get_option_not_empty( $field, $default_value = '' )
    {
        $tzfd_value = $this->get_option( $field, $default_value );
        return ( $tzfd_value ? $tzfd_value : $default_value );
    }


} // end class

