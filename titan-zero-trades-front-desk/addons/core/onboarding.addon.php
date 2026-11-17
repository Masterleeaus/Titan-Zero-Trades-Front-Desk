<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/../../includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/../../includes/class-tzfd-admin-write-guard.php';
require_once __DIR__.'/base.addon.php';

if ( ! class_exists( 'TZFD_Onboarding' ) )
{
    /**
     * Setup wizard: vertical, services, details, and missed-call text-back in one guided flow.
     * Services can come from the vertical pack, this site's booking calendars, other booking
     * plugins' service tables, a CSV file, or a web page the administrator names.
     */
    class TZFD_Onboarding extends TZFD_BaseAddon
    {
        public function get_addon_settings()
        {
            return TZFD_Addon_Support::info_block( __( 'Start from an industry preset with the Preset Setup wizard.', 'titan-zero-trades-front-desk' ), array( __( 'Open the Preset Setup', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tzfd-setup' ) ) );
        }

        
        protected $addonID = "tzfd-Onboarding";
        protected $name = "Preset Setup";
        protected $description;
        public $category = ' Setup wizard (included in this plugin version)';
        public $help = '';

        const DRAFT    = 'tz_fd_setup_draft';
        const DONE     = 'tz_fd_setup_done';
        const MAX_CSV  = 262144;   // 256 KB
        const MAX_HTML = 1000000;  // 1 MB read from a web page
        const MAX_ITEMS = 60;      // list items taken from a web page
        const MAX_SERVICES = 500;

        /** Booking plugins whose service tables can be read. Column names are for the current releases. */
        const PLUGIN_TABLES = array(
            array( 'bookly_services',    'name', 'price',         'Bookly' ),
            array( 'amelia_services',    'name', 'price',         'Amelia' ),
            array( 'latepoint_services', 'name', 'charge_amount', 'LatePoint' ),
            array( 'bkntc_services',     'name', 'price',         'Booknetic' ),
        );

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
            $this->description = $this->tr_apply( "Guided setup: choose your vertical, pick services from your vertical pack, your calendars, other booking plugins, a CSV file or a web page, then enter your details.", 'titan-zero-trades-front-desk' );
            // The setup wizard is always available to administrators. Its menu page and form handler
            // must not depend on the Addons switch, or Start setup leads to a 'not allowed' page.
            add_action( 'admin_menu', array( &$this, 'add_menu' ), 117 );
            add_action( 'admin_init', array( &$this, 'handle_post' ) );
            add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_picker_assets' ) );
        }

        /** The vertical picker stylesheet is loaded only on the Preset Setup screen. */
        public function enqueue_picker_assets( $hook_suffix )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route check to select assets; no state change.
            $page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            if ( 'tzfd-setup' !== $page || ! current_user_can( 'tz_fd_access' ) ) { return; }
            $main = dirname( __DIR__, 2 ) . '/titan-zero-front-desk.php';
            wp_enqueue_style( 'tzfd-vertical-picker', plugin_dir_url( $main ) . 'css/tzfd-vertical-picker.css', array(), defined( 'TZFD_VERSION' ) ? TZFD_VERSION : null );
        }

        public function add_menu()
        {
            add_submenu_page(
                '',
                __( 'Preset Setup', 'titan-zero-trades-front-desk' ),
                __( 'Preset Setup', 'titan-zero-trades-front-desk' ),
                'tz_fd_access',
                'tzfd-setup',
                array( &$this, 'render' )
            );
        }

        /************************ PURE HELPERS (tested) *****************************/

        /** Services from CSV text: name, price (optional header row). */
        public static function candidates_from_csv( $csv )
        {
            $out   = array();
            $first = true;
            foreach ( preg_split( '/\r\n|\r|\n/', (string) $csv ) as $line ) {
                if ( count( $out ) >= self::MAX_SERVICES ) break;
                if ( '' === trim( $line ) ) continue;
                $row  = str_getcsv( $line );
                $name = trim( (string) ( $row[0] ?? '' ) );
                if ( $first ) {
                    $first = false;
                    if ( 'name' === strtolower( $name ) ) continue; // header row
                }
                if ( '' === $name ) continue;
                $out[] = array( 'name' => self::clean_name( $name ), 'price' => self::clean_price( $row[1] ?? '' ), 'source' => 'csv' );
            }
            return $out;
        }

        /** Services from list items on a web page. Prices are read when a currency sign is present. */
        public static function candidates_from_html( $html )
        {
            $out = array();
            if ( ! preg_match_all( '#<li\b[^>]*>(.*?)</li>#is', (string) $html, $m ) ) return $out;
            foreach ( $m[1] as $inner ) {
                if ( count( $out ) >= self::MAX_ITEMS ) break;
                $text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ) ) );
                if ( strlen( $text ) < 3 || strlen( $text ) > 120 ) continue;
                $price = '';
                if ( preg_match( '/([$£€]\s?\d[\d,]*(?:\.\d{1,2})?)/u', $text, $pm ) ) {
                    $price = $pm[1];
                    $text  = str_replace( $pm[1], '', $text );
                }
                $text = trim( preg_replace( '/^[\s\-:|·–—]+|[\s\-:|·–—]+$/u', '', $text ) );
                if ( strlen( $text ) < 3 ) continue;
                $out[] = array( 'name' => self::clean_name( $text ), 'price' => $price, 'source' => 'web' );
            }
            return $out;
        }

        /** Services from booking calendars. Each form is array( 'name' => ..., 'structure' => JSON ). */
        public static function candidates_from_forms( array $forms )
        {
            $out = array();
            foreach ( $forms as $f ) {
                $data = json_decode( (string) ( $f['structure'] ?? '' ), true );
                if ( ! is_array( $data ) ) continue;
                self::walk_services( $data, 'calendar: ' . (string) ( $f['name'] ?? '' ), $out );
            }
            return $out;
        }

        private static function walk_services( $node, $label, array &$out )
        {
            if ( ! is_array( $node ) ) return;
            foreach ( $node as $key => $value ) {
                if ( 'services' === $key && is_array( $value ) ) {
                    foreach ( $value as $svc ) {
                        if ( ! is_array( $svc ) || empty( $svc['name'] ) ) continue;
                        $name = self::clean_name( $svc['name'] );
                        if ( preg_match( '/^Service \d+$/', $name ) ) continue; // the plugin's placeholder service
                        $out[] = array( 'name' => $name, 'price' => isset( $svc['price'] ) && is_numeric( $svc['price'] ) ? (string) $svc['price'] : '', 'source' => $label );
                    }
                } else {
                    self::walk_services( $value, $label, $out );
                }
            }
        }

        /** Services from other booking plugins' tables. $db is a wpdb-compatible object. */
        public static function candidates_from_tables( $db, $company_id = 0 )
        {
            $out = array();
            $company_id = absint( $company_id );
            if ( $company_id < 1 ) { return $out; }
            foreach ( self::PLUGIN_TABLES as $spec ) {
                list( $suffix, $name_col, $price_col, $label ) = $spec;
                $table = $db->prefix . $suffix;
                if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) continue;
                // Only integrate third-party records with an explicit, verifiable
                // company_id column. Never import an unscoped provider catalogue.
                $owner_col = $db->get_var( $db->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'company_id' ) );
                if ( 'company_id' !== $owner_col ) { continue; }
                $rows = $db->get_results( $db->prepare(
                    'SELECT %i AS name, %i AS price FROM %i WHERE company_id = %d LIMIT 200',
                    $name_col, $price_col, $table, $company_id
                ), ARRAY_A );
                foreach ( (array) $rows as $r ) {
                    if ( empty( $r['name'] ) ) continue;
                    $out[] = array( 'name' => self::clean_name( $r['name'] ), 'price' => (string) ( $r['price'] ?? '' ), 'source' => 'plugin: ' . $label );
                }
            }
            return $out;
        }

        /** Removes repeats (ignoring case and extra spaces). The first occurrence wins, so source order matters. */
        public static function merge_candidates( array $items )
        {
            $seen = array(); $out = array();
            foreach ( $items as $it ) {
                $key = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $it['name'] ) ) );
                if ( '' === $key || isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $out[] = $it;
            }
            return $out;
        }

        /** Typed services, one per line. */
        public static function parse_services_text( $text )
        {
            $out = array();
            foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
                $name = self::clean_name( $line );
                if ( '' !== $name ) $out[] = array( 'name' => $name, 'price' => '', 'source' => 'typed' );
                if ( count( $out ) >= self::MAX_SERVICES ) break;
            }
            return $out;
        }

        private static function clean_name( $name )
        {
            return substr( trim( sanitize_text_field( (string) $name ) ), 0, 120 );
        }

        private static function clean_price( $price )
        {
            return substr( trim( sanitize_text_field( (string) $price ) ), 0, 20 );
        }

        /** Missed-call settings for the wizard, or an error message. */
        public static function missed_call_settings( array $d, $inbound_secret = '' )
        {
            $defaults = TZFD_MissedCall::defaults();
            $current  = TZFD_Addon_Support::global_settings( 'missed_call', $defaults );
            $auth_value = trim( (string) ( $d['sms_auth_value'] ?? '' ) );
            $auth_header = trim( (string) ( $d['sms_auth_header'] ?? '' ) );
            // Start from every key the validator reads (stored value or default), then overwrite
            // the fields the wizard asks for. Keys the wizard does not ask for must not be missing,
            // or settings_error() reads null and rejects every save.
            $new = array_merge( $defaults, $current );
            $new['inbound_secret']  = '' !== $inbound_secret ? $inbound_secret : (string) $current['inbound_secret'];
            $new['sms_url']         = trim( (string) ( $d['sms_url'] ?? '' ) );
            $new['sms_auth_header'] = '' !== $auth_header ? $auth_header : 'Authorization';
            $new['sms_auth_value']  = '' !== $auth_value ? $auth_value : (string) $current['sms_auth_value'];
            $new['booking_url']     = trim( (string) ( $d['booking_url'] ?? '' ) );
            $new['message']         = $defaults['message'];
            return array_intersect_key( $new, $defaults );
        }

        /**
         * Writes the business profile, the pack details, and (if chosen) the missed-call settings.
         * Returns '' on success or an error message; nothing is written when the settings are invalid.
         */
        public static function apply_details( array $d, array $services, $inbound_secret = '' )
        {
            $on = ! empty( $d['missed_call_on'] );
            if ( $on ) {
                $settings = self::missed_call_settings( $d, $inbound_secret );
                $err = TZFD_MissedCall::settings_error( $settings );
                if ( '' !== $err ) return $err;
            }

            $biz = TZFD_Scoped_Store::get( 'tz_fd_business_profile', array() );
            if ( ! is_array( $biz ) ) $biz = array();
            $biz['name']     = sanitize_text_field( (string) ( $d['business_name'] ?? ( $biz['name'] ?? '' ) ) );
            $biz['services'] = implode( ', ', array_map( function ( $s ) { return $s['name']; }, $services ) );
            $biz['area']     = sanitize_text_field( (string) ( $d['service_area'] ?? ( $biz['area'] ?? '' ) ) );
            TZFD_Scoped_Store::update( 'tz_fd_business_profile', $biz );
            TZFD_Scoped_Store::update( 'tz_fd_ai_services', $biz['services'] );
            TZFD_Scoped_Store::update( 'tz_fd_ai_service_area', $biz['area'] );

            TZFD_Scoped_Store::update( TZFD_Vertical_Packs::VARS_OPTION, self::pack_vars_from( $d ) );

            if ( $on ) {
                if ( ! class_exists( 'TZFD_Addon_Support' ) ) return 'Missed-call settings are not available in this build.';
                TZFD_Addon_Support::save_global_settings( 'missed_call', TZFD_MissedCall::defaults(), $settings );
            }
            return '';
        }

        /**
         * Pack variables from the details step. A blank submitted value keeps the stored value,
         * so an incomplete form never overwrites saved answers with empty strings.
         */
        private static function pack_vars_from( array $d )
        {
            $stored = TZFD_Scoped_Store::get( TZFD_Vertical_Packs::VARS_OPTION, array() );
            $stored = is_array( $stored ) ? $stored : array();
            $vars   = array();
            foreach ( array_keys( TZFD_Vertical_Packs::default_vars() ) as $key ) {
                $value        = sanitize_text_field( (string) ( $d[ $key ] ?? '' ) );
                $vars[ $key ] = '' !== $value ? $value : (string) ( $stored[ $key ] ?? '' );
            }
            return $vars;
        }

        /************************ DRAFT STATE *****************************/

        public static function draft()
        {
            $d = TZFD_Scoped_Store::get( self::DRAFT, array() );
            $draft = array_merge( array( 'vertical' => '', 'trades' => array(), 'candidates' => array(), 'services' => array(), 'details' => array() ), is_array( $d ) ? $d : array() );
            $draft['trades'] = array_values( array_filter( array_map( 'sanitize_key', (array) $draft['trades'] ), 'strlen' ) );
            // The company's saved selection (Workspace, or the local one when Workspace is absent) is the default pick.
            if ( '' === $draft['vertical'] && TZFD_Vertical_Registry::available() ) {
                $sel = TZFD_Vertical_Registry::selection();
                if ( 'ok' === $sel['status'] && $sel['primary'] ) {
                    $draft['vertical'] = $sel['primary'];
                    $draft['trades']   = array_slice( array_values( array_unique( array_merge( array( $sel['primary'] ), (array) $sel['trades'] ) ) ), 0, TZFD_Vertical_Registry::MAX_TRADES );
                }
            }
            return $draft;
        }

        /** Every vertical id the picker may accept: installed packs plus the built-in catalogue. */
        private static function allowed_vertical_ids( array $packs )
        {
            $ids = array_map( 'strval', array_keys( $packs ) );
            if ( class_exists( 'TZFD_Builtin_Verticals' ) ) {
                foreach ( (array) TZFD_Builtin_Verticals::ids_by_category() as $group ) {
                    $ids = array_merge( $ids, array_map( 'strval', (array) $group ) );
                }
            }
            return array_flip( array_values( array_unique( $ids ) ) );
        }

        /**
         * Picker groups: category id => array( 'label', 'items' => array( id, name, icon, tagline ) ).
         * Installed packs that the built-in catalogue does not list go in a final group, so none are hidden.
         */
        private static function picker_groups( array $packs )
        {
            $groups  = array();
            $listed  = array();
            if ( class_exists( 'TZFD_Builtin_Verticals' ) && class_exists( 'TZFD_Vertical_Registry' ) ) {
                $labels = (array) TZFD_Builtin_Verticals::categories();
                $lists  = (array) TZFD_Vertical_Registry::builtin_list_for_ui();
                foreach ( array_unique( array_merge( array_keys( $labels ), array_keys( $lists ) ) ) as $category ) {
                    $items = array();
                    foreach ( (array) ( $lists[ $category ] ?? array() ) as $v ) {
                        if ( empty( $v['id'] ) ) { continue; }
                        $items[]              = array(
                            'id'      => (string) $v['id'],
                            'name'    => (string) ( $v['name'] ?? $v['id'] ),
                            'icon'    => (string) ( $v['icon'] ?? '' ),
                            'tagline' => (string) ( $v['tagline'] ?? '' ),
                        );
                        $listed[ (string) $v['id'] ] = true;
                    }
                    if ( array() !== $items ) {
                        $groups[ (string) $category ] = array( 'label' => (string) ( $labels[ $category ] ?? $category ), 'items' => $items );
                    }
                }
            }
            $extra = array();
            foreach ( $packs as $id => $p ) {
                if ( isset( $listed[ (string) $id ] ) ) { continue; }
                $extra[] = array(
                    'id'      => (string) $id,
                    'name'    => (string) ( $p['name'] ?? $id ),
                    'icon'    => (string) ( $p['icon'] ?? '' ),
                    'tagline' => (string) ( $p['tagline'] ?? '' ),
                );
            }
            if ( array() !== $extra ) {
                $groups['installed-packs'] = array( 'label' => __( 'Other installed packs', 'titan-zero-trades-front-desk' ), 'items' => $extra );
            }
            return $groups;
        }

        private static function save_draft( array $d )
        {
            TZFD_Scoped_Store::update( self::DRAFT, $d );
        }

        /************************ POST HANDLING *****************************/

        /** Tags and attributes the wizard's form openers and hidden fields use. Everything else is stripped. */
        private static function form_allowed_html()
        {
            return array(
                'form'  => array( 'method' => true, 'action' => true, 'enctype' => true ),
                'input' => array( 'type' => true, 'id' => true, 'name' => true, 'value' => true ),
            );
        }

        public function handle_post()
        {
            if ( ! isset( $_POST['tz_fd_setup_action'] ) ) return;
            // Setup mutates company settings and must never be invoked with a mixed-method request.
            TZFD_Admin_Write_Guard::enforce( 400, 1048576 );
            if ( ! current_user_can( 'tz_fd_access' ) ) {
                wp_die( esc_html__( 'You are not allowed to change setup settings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
            }
            // Setup is tenant-owned: a WordPress capability alone never authorizes
            // changing another company's calendars or importing their services.
            if ( ! class_exists( 'TZFD_Scoped_Store', false ) || TZFD_Scoped_Store::company_id() < 1 ) {
                wp_die( esc_html__( 'Select a company before running setup.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
            }
            check_admin_referer( 'tz_fd_setup', 'tz_fd_setup_nonce' );

            $action = sanitize_key( tzfd_request_text( 'post', 'tz_fd_setup_action' ) );
            $draft  = self::draft();
            $packs  = TZFD_Vertical_Packs::available();

            if ( 'vertical' === $action ) {
                $picked = sanitize_key( tzfd_request_text( 'post', 'vertical' ) );
                if ( 'other' === $picked ) {
                    $draft['vertical'] = 'other';
                    $draft['trades']   = array();
                    self::save_draft( $draft );
                    return self::go( 2 );
                }
                // trades[] is an array of ids: each entry is unslashed and sanitised. Unknown ids are dropped below.
                $raw_trades = isset( $_POST['trades'] ) ? map_deep( wp_unslash( $_POST['trades'] ), 'sanitize_key' ) : array();
                $raw_trades = is_array( $raw_trades ) ? $raw_trades : array();
                $allowed    = self::allowed_vertical_ids( $packs );
                $primary    = isset( $allowed[ $picked ] ) ? $picked : '';
                $trades     = array();
                if ( '' !== $primary ) {
                    // The primary goes first, then the other trades in the order posted. Dedupe, then cap at MAX_TRADES.
                    $extra = array();
                    foreach ( $raw_trades as $t ) {
                        if ( is_string( $t ) && '' !== $t && isset( $allowed[ $t ] ) && $t !== $primary ) { $extra[] = $t; }
                    }
                    $trades = array_slice( array_values( array_unique( array_merge( array( $primary ), $extra ) ) ), 0, TZFD_Vertical_Registry::MAX_TRADES );
                    // The full list goes to the local selection. If it refuses, the user stays on this step.
                    $saved = TZFD_Vertical_Registry::save_local_selection( array( 'primary' => $primary, 'trades' => $trades ) );
                    if ( true !== $saved ) {
                        return self::go( 1, TZFD_Vertical_Registry::message( $saved ) );
                    }
                }
                $draft['vertical'] = $primary;
                $draft['trades']   = $trades;
                self::save_draft( $draft );
                return self::go( 2 );
            }

            if ( 'sources' === $action ) {
                $raw_sources = isset( $_POST['sources'] ) ? map_deep( wp_unslash( $_POST['sources'] ), 'sanitize_text_field' ) : array();
                if ( ! is_array( $raw_sources ) || count( $raw_sources ) > 5 ) {
                    wp_die( esc_html__( 'Invalid service sources.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                }
                $sources = array();
                foreach ( $raw_sources as $source ) {
                    if ( ! is_string( $source ) || ! in_array( $source, array( 'pack', 'site', 'plugins', 'csv', 'url' ), true ) ) {
                        wp_die( esc_html__( 'Invalid service source.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                    }
                    $sources[] = $source;
                }
                $sources = array_unique( $sources );
                $items = array();
                if ( in_array( 'pack', $sources, true ) && isset( $packs[ $draft['vertical'] ] ) ) {
                    $pack = $packs[ $draft['vertical'] ];
                    foreach ( (array) $pack['service_types'] as $s ) {
                        $items[] = array( 'name' => self::clean_name( $s ), 'price' => '', 'source' => 'pack: ' . $pack['name'] );
                    }
                }
                global $wpdb;
                if ( in_array( 'site', $sources, true ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time read of the site's form definitions while the wizard builds its candidate list; caching would only risk stale data.
                    $forms = $wpdb->get_results( $wpdb->prepare(
                        'SELECT form_name AS name, form_structure AS structure FROM %i WHERE company_id = %d LIMIT 500',
                        $wpdb->prefix . TZFD_Tables::FORMS,
                        TZFD_Scoped_Store::company_id()
                    ), ARRAY_A );
                    if ( ! is_array( $forms ) || ! empty( $wpdb->last_error ) ) {
                        return self::go( 2, __( 'Calendar services could not be loaded. Please retry.', 'titan-zero-trades-front-desk' ) );
                    }
                    $items = array_merge( $items, self::candidates_from_forms( $forms ) );
                }
                if ( in_array( 'plugins', $sources, true ) ) {
                    $items = array_merge( $items, self::candidates_from_tables( $wpdb, TZFD_Scoped_Store::company_id() ) );
                }
                // The upload is checked with is_uploaded_file(), its size and its extension, then parsed as data.
                if ( in_array( 'csv', $sources, true ) ) {
                    $upload = isset( $_FILES['services_csv'] ) && is_array( $_FILES['services_csv'] ) ? array(
                        'error'    => isset( $_FILES['services_csv']['error'] ) ? intval( $_FILES['services_csv']['error'] ) : null,
                        'tmp_name' => isset( $_FILES['services_csv']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['services_csv']['tmp_name'] ) ) : null,
                        'size'     => isset( $_FILES['services_csv']['size'] ) ? intval( $_FILES['services_csv']['size'] ) : null,
                        'name'     => isset( $_FILES['services_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['services_csv']['name'] ) ) : null,
                    ) : null;
                    if ( ! is_array( $upload ) || ! isset( $upload['error'], $upload['tmp_name'], $upload['size'], $upload['name'] ) ||
                         ! is_int( $upload['error'] ) || UPLOAD_ERR_OK !== $upload['error'] ||
                         ! is_int( $upload['size'] ) || $upload['size'] < 1 || $upload['size'] > self::MAX_CSV ||
                         ! is_string( $upload['tmp_name'] ) || ! is_string( $upload['name'] ) ||
                         'csv' !== strtolower( pathinfo( sanitize_file_name( $upload['name'] ), PATHINFO_EXTENSION ) ) ||
                         ! is_uploaded_file( $upload['tmp_name'] ) ) {
                        return self::go( 2, __( 'Invalid CSV upload. Select a CSV file up to 256 KB.', 'titan-zero-trades-front-desk' ) );
                    }
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    if ( ! WP_Filesystem() || ! isset( $GLOBALS['wp_filesystem'] ) ) {
                        return self::go( 2, __( 'File storage is unavailable.', 'titan-zero-trades-front-desk' ) );
                    }
                    $csv_text = $GLOBALS['wp_filesystem']->get_contents( $upload['tmp_name'] );
                    if ( ! is_string( $csv_text ) || strlen( $csv_text ) > self::MAX_CSV ) {
                        return self::go( 2, __( 'CSV file could not be read.', 'titan-zero-trades-front-desk' ) );
                    }
                    $items = array_merge( $items, self::candidates_from_csv( $csv_text ) );
                }
                $url = trim( esc_url_raw( tzfd_request_text( 'post', 'web_url' ) ) );
                if ( in_array( 'url', $sources, true ) && 0 === stripos( $url, 'https://' ) && TZFD_MissedCall::is_https( $url ) ) {
                    // wp_safe_remote_get() refuses private and loopback hosts. The page is read once and only parsed as text.
                    $resp = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3, 'limit_response_size' => self::MAX_HTML ) );
                    if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
                        return self::go( 2, __( 'The service web page could not be fetched safely.', 'titan-zero-trades-front-desk' ) );
                    }
                    $body = wp_remote_retrieve_body( $resp );
                    if ( ! is_string( $body ) || strlen( $body ) > self::MAX_HTML ) {
                        return self::go( 2, __( 'Invalid service web page response.', 'titan-zero-trades-front-desk' ) );
                    }
                    $items = array_merge( $items, self::candidates_from_html( $body ) );
                }
                $draft['candidates'] = self::merge_candidates( $items );
                self::save_draft( $draft );
                return self::go( 3 );
            }

            if ( 'services' === $action ) {
                $selected = isset( $_POST['services'] ) ? map_deep( wp_unslash( $_POST['services'] ), 'sanitize_text_field' ) : array();
                if ( ! is_array( $selected ) || count( $selected ) > self::MAX_SERVICES ) {
                    wp_die( esc_html__( 'Invalid service selection.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                }
                $chosen = array();
                foreach ( $selected as $value ) {
                    if ( ! is_string( $value ) && ! is_int( $value ) ) {
                        wp_die( esc_html__( 'Invalid service identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                    }
                    $index = (string) $value;
                    if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,5})$/D', $index ) || ! isset( $draft['candidates'][ (int) $index ] ) ) {
                        wp_die( esc_html__( 'Unknown service identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                    }
                    $chosen[ (int) $index ] = $draft['candidates'][ (int) $index ];
                }
                $typed = self::parse_services_text( sanitize_textarea_field( tzfd_request_text( 'post', 'extra_services' ) ) );
                $draft['services'] = self::merge_candidates( array_merge( $chosen, $typed ) );
                self::save_draft( $draft );
                return self::go( 4 );
            }

            if ( 'answers' === $action ) {
                $pack = $packs[ $draft['vertical'] ] ?? null;
                if ( $pack ) {
                    $pack_count = count( (array) $pack['kb'] );
                    $kq  = (array) map_deep( wp_unslash( $_POST['kb_q'] ?? array() ), 'sanitize_text_field' );
                    $ka  = (array) map_deep( wp_unslash( $_POST['kb_a'] ?? array() ), 'sanitize_textarea_field' );
                    $kd  = (array) map_deep( wp_unslash( $_POST['kb_del'] ?? array() ), 'sanitize_text_field' );
                    $nq  = (array) map_deep( wp_unslash( $_POST['kb_nq'] ?? array() ), 'sanitize_text_field' );
                    $na  = (array) map_deep( wp_unslash( $_POST['kb_na'] ?? array() ), 'sanitize_textarea_field' );
                    $raw = array( 'kb' => array(), 'catalog' => array(), 'kb_removed' => array(), 'kb_added' => array() );
                    foreach ( $kq as $i => $q ) {
                        $i       = (int) $i;
                        $deleted = array_key_exists( $i, $kd );
                        if ( $i < $pack_count ) {
                            if ( $deleted ) {
                                $raw['kb_removed'][] = $i;
                            } else {
                                $raw['kb'][ $i ] = array( 'q' => $q, 'a' => $ka[ $i ] ?? '' );
                            }
                        } elseif ( ! $deleted ) {
                            // Questions the site added earlier, shown again on this page.
                            $raw['kb_added'][] = array( 'q' => $q, 'a' => $ka[ $i ] ?? '' );
                        }
                    }
                    foreach ( array_keys( $nq ) as $k ) {
                        $raw['kb_added'][] = array( 'q' => $nq[ $k ] ?? '', 'a' => $na[ $k ] ?? '' );
                    }
                    $cl = (array) map_deep( wp_unslash( $_POST['cat_label'] ?? array() ), 'sanitize_text_field' );
                    $cp = (array) map_deep( wp_unslash( $_POST['cat_price'] ?? array() ), 'sanitize_text_field' );
                    foreach ( (array) $pack['catalog'] as $i => $item ) {
                        $raw['catalog'][ $i ] = array( 'label' => $cl[ $i ] ?? '', 'unit_price' => $cp[ $i ] ?? '' );
                    }
                    $draft['answers'] = array(
                        'vertical' => $draft['vertical'],
                        'edits'    => TZFD_Vertical_Packs::clean_edits( $raw, $pack ),
                    );
                    self::save_draft( $draft );
                }
                return self::go( 5 );
            }

            if ( 'business' === $action ) {
                $choice = sanitize_key( tzfd_request_text( 'post', 'booking_choice' ) );
                if ( ! in_array( $choice, array( 'new', 'page', 'link' ), true ) ) {
                    $choice = 'new';
                }
                $page_id = absint( tzfd_request_text( 'post', 'booking_page_id' ) );
                $link    = esc_url_raw( trim( (string) tzfd_request_text( 'post', 'booking_link' ) ) );
                // The calendar select: 0 is the default calendar. Any other ID must belong to this company.
                $calendar_id = absint( tzfd_request_text( 'post', 'booking_calendar_id' ) );
                if ( ! TZFD_Booking_Page::is_valid_calendar( $calendar_id ) ) {
                    wp_die( esc_html__( 'Unknown calendar.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                }
                $fields  = array(
                    'business_name'       => tzfd_request_text( 'post', 'business_name' ),
                    'service_area'        => tzfd_request_text( 'post', 'service_area' ),
                    'booking_choice'      => $choice,
                    'booking_page_id'     => 0,
                    'booking_url'         => '',
                    'booking_calendar_id' => 'new' === $choice ? $calendar_id : 0,
                );
                if ( 'page' === $choice ) {
                    if ( ! TZFD_Booking_Page::is_valid_choice( $page_id ) ) {
                        return self::go( 5, __( 'Choose a page for your booking form, or pick another option.', 'titan-zero-trades-front-desk' ) );
                    }
                    $fields['booking_page_id'] = $page_id;
                    $fields['booking_url']     = TZFD_Booking_Page::url_for( $page_id );
                } elseif ( 'link' === $choice ) {
                    if ( ! preg_match( '#^https://#i', $link ) ) {
                        return self::go( 5, __( 'Paste a booking address that starts with https://.', 'titan-zero-trades-front-desk' ) );
                    }
                    $fields['booking_url'] = $link;
                }
                self::merge_details( $draft, $fields );
                return self::go( 6 );
            }

            if ( 'pricing' === $action ) {
                $fields = array();
                foreach ( self::policy_fields( 'price' ) as $k => $spec ) {
                    $fields[ $k ] = tzfd_request_text( 'post', $k );
                }
                self::merge_details( $draft, $fields );
                return self::go( 7 );
            }

            if ( 'policies' === $action ) {
                $fields = array();
                foreach ( self::policy_fields( 'other' ) as $k => $spec ) {
                    $fields[ $k ] = tzfd_request_text( 'post', $k );
                }
                self::merge_details( $draft, $fields );
                return self::go( 8 );
            }

            if ( 'confirm' === $action ) {
                $fields = array(
                    'sms_url'         => tzfd_request_text( 'post', 'sms_url' ),
                    'sms_auth_header' => tzfd_request_text( 'post', 'sms_auth_header' ),
                    'sms_auth_value'  => tzfd_request_text( 'post', 'sms_auth_value' ),
                    'missed_call_on'  => ! empty( $_POST['missed_call_on'] ) ? '1' : '',
                    'intake_mode'     => TZFD_Intake_Mode::normalize( sanitize_key( tzfd_request_text( 'post', 'intake_mode' ) ) ),
                );
                self::merge_details( $draft, $fields );
                return self::go( 9 );
            }

            if ( 'finish' === $action ) {
                $d = $draft['details'];
                // Finish needs the details step: without it there is nothing valid to install or to write.
                if ( ! is_array( $d ) || ! array_key_exists( 'intake_mode', $d ) || ! array_key_exists( 'business_name', $d ) ) {
                    return self::go( 5, 'Complete the details step before finishing setup.' );
                }
                $secret = '';
                $current = TZFD_Addon_Support::global_settings( 'missed_call', TZFD_MissedCall::defaults() );
                if ( ! empty( $d['missed_call_on'] ) && '' === $current['inbound_secret'] ) {
                    require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-signed-request.php';
                    $secret = TZFD_Signed_Request::new_secret();
                }
                // Check the missed-call settings before anything is written.
                if ( ! empty( $d['missed_call_on'] ) ) {
                    $err = TZFD_MissedCall::settings_error( self::missed_call_settings( $d, $secret ) );
                    if ( '' !== $err ) return self::go( 8, $err );
                }
                // Answer and price edits from step 4 go in before the pack installs.
                $answers = $draft['answers'] ?? array();
                if ( isset( $packs[ $draft['vertical'] ] ) && ! empty( $answers ) && ( $answers['vertical'] ?? '' ) === $draft['vertical'] ) {
                    TZFD_Vertical_Packs::save_edits( $draft['vertical'], (array) ( $answers['edits'] ?? array() ) );
                }
                // Pack details first, so the pack installs with this site's answers.
                TZFD_Scoped_Store::update( TZFD_Vertical_Packs::VARS_OPTION, self::pack_vars_from( $d ) );
                if ( isset( $packs[ $draft['vertical'] ] ) && class_exists( 'TZFD_Vertical_Packs' ) ) {
                    if ( TZFD_Vertical_Packs::is_installed( $draft['vertical'] ) ) TZFD_Vertical_Packs::uninstall_pack( $draft['vertical'] );
                    TZFD_Vertical_Packs::install_pack( $draft['vertical'] );
                }
                // Resolve the booking page now, so a page is only created when setup finishes.
                if ( 'new' === ( $d['booking_choice'] ?? 'new' ) ) {
                    if ( empty( $d['booking_page_id'] ) ) {
                        $created = TZFD_Booking_Page::create( '', (int) ( $d['booking_calendar_id'] ?? 0 ) );
                        if ( is_wp_error( $created ) ) return self::go( 5, __( 'The booking page could not be created. Try again, or choose a page you already have.', 'titan-zero-trades-front-desk' ) );
                        $d['booking_page_id'] = (int) $created;
                        $draft['details']['booking_page_id'] = (int) $created;
                        self::save_draft( $draft );
                    }
                    $d['booking_url'] = TZFD_Booking_Page::url_for( (int) $d['booking_page_id'] );
                }
                $err = self::apply_details( $d, $draft['services'], $secret );
                if ( '' !== $err ) return self::go( 8, $err );

                // Booking intake: the chosen mode becomes the site default and applies to every calendar in the setup.
                $tz_intake = TZFD_Intake_Mode::normalize( $d['intake_mode'] ?? '' );
                TZFD_Intake_Mode::set_default( $tz_intake );
                global $wpdb;
                $tz_intake_ids = $wpdb->get_col( $wpdb->prepare(
                    'SELECT id FROM %i WHERE company_id = %d',
                    $wpdb->prefix . TZFD_Tables::FORMS,
                    TZFD_Scoped_Store::company_id()
                ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table name, bound with %i.
                foreach ( (array) $tz_intake_ids as $tz_intake_id ) {
                    TZFD_Intake_Mode::set_for_form( (int) $tz_intake_id, $tz_intake );
                }

                TZFD_Scoped_Store::update( self::DONE, gmdate( 'Y-m-d H:i' ) . ' UTC' );
                TZFD_Scoped_Store::delete( self::DRAFT );
                if ( '' !== $secret ) set_transient( 'tz_fd_setup_secret_' . TZFD_Scoped_Store::company_id() . '_' . get_current_user_id(), $secret, 600 );
                return self::go( 'done' );
            }
        }

        private static function go( $step, $error = '' )
        {
            $args = array( 'page' => 'tzfd-setup', 'step' => (string) $step );
            if ( '' !== $error ) $args['error'] = rawurlencode( $error );
            wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }

        /************************ SCREENS *****************************/

        /** Policy fields on the pricing page. Everything else from default_vars() goes to the optional page. */
        const PRICE_FIELDS = array( 'price_note', 'callout_note', 'deposit_note', 'payment_note', 'minimum_note', 'discount_note', 'cancellation_notice', 'warranty_note', 'guarantee_note' );

        /** @return array key => spec for the policy group: 'price' or 'other'. */
        private static function policy_fields( $group )
        {
            $all = TZFD_Vertical_Packs::default_vars();
            if ( 'price' === $group ) {
                return array_intersect_key( $all, array_flip( self::PRICE_FIELDS ) );
            }
            return array_diff_key( $all, array_flip( self::PRICE_FIELDS ) );
        }

        /** Shows each policy as a plain question, with its current wording and an example. */
        private static function render_policy_fields( array $fields, array $d )
        {
            $current = TZFD_Vertical_Packs::vars();
            foreach ( $fields as $key => $spec ) {
                $value = ( isset( $d[ $key ] ) && '' !== $d[ $key ] ) ? $d[ $key ] : ( $current[ $key ] ?? '' );
                echo '<p><label><strong>' . esc_html( $spec['label'] ) . '</strong><br>';
                echo '<textarea class="large-text" rows="2" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea></label>';
                if ( ! empty( $spec['help'] ) ) {
                    echo '<br><span class="description">' . esc_html( $spec['help'] ) . '</span>';
                }
                echo '</p>';
            }
        }

        /** The booking page choice: create one, use an existing page, or paste a link. */
        private static function render_booking_choice( array $d )
        {
            $choice = in_array( $d['booking_choice'] ?? '', array( 'new', 'page', 'link' ), true ) ? $d['booking_choice'] : 'new';
            $chosen_page     = absint( $d['booking_page_id'] ?? 0 );
            $chosen_calendar = absint( $d['booking_calendar_id'] ?? 0 );
            echo '<fieldset><legend><strong>' . esc_html__( 'Where do customers book?', 'titan-zero-trades-front-desk' ) . '</strong></legend>';
            echo '<p><label><input type="radio" name="booking_choice" value="new" ' . checked( $choice, 'new', false ) . ' /> ' . esc_html__( 'Create a booking page for me. It will be published as "Book an appointment". (Recommended)', 'titan-zero-trades-front-desk' ) . '</label></p>';
            echo '<p><label><input type="radio" name="booking_choice" value="page" ' . checked( $choice, 'page', false ) . ' /> ' . esc_html__( 'Use a page I already have on this website:', 'titan-zero-trades-front-desk' ) . '</label><br>';
            echo '<select name="booking_page_id"><option value="0">' . esc_html__( 'Choose a page', 'titan-zero-trades-front-desk' ) . '</option>';
            foreach ( TZFD_Booking_Page::choices() as $page ) {
                echo '<option value="' . (int) $page['id'] . '" ' . selected( $chosen_page, (int) $page['id'], false ) . '>' . esc_html( $page['title'] ) . '</option>';
            }
            echo '</select></p>';
            echo '<p><label><strong>' . esc_html__( 'Calendar for the booking form:', 'titan-zero-trades-front-desk' ) . '</strong><br>';
            echo '<select name="booking_calendar_id"><option value="0" ' . selected( $chosen_calendar, 0, false ) . '>' . esc_html__( 'Default calendar', 'titan-zero-trades-front-desk' ) . '</option>';
            foreach ( TZFD_Booking_Page::calendar_choices() as $calendar_id => $calendar_name ) {
                echo '<option value="' . (int) $calendar_id . '" ' . selected( $chosen_calendar, (int) $calendar_id, false ) . '>' . esc_html( $calendar_name ) . '</option>';
            }
            echo '</select></label><br><span class="description">' . esc_html__( 'Only used when the booking page is created. For a page you already have, set the calendar in that page.', 'titan-zero-trades-front-desk' ) . '</span></p>';
            echo '<p><label><input type="radio" name="booking_choice" value="link" ' . checked( $choice, 'link', false ) . ' /> ' . esc_html__( 'My booking page is somewhere else. Paste its web address:', 'titan-zero-trades-front-desk' ) . '</label><br>';
            echo '<input type="url" class="large-text" name="booking_link" placeholder="https://" value="' . esc_attr( $d['booking_url'] ?? '' ) . '" /></p>';
            echo '</fieldset>';
        }

        /**
         * Read-only AI setup check (issue #40). Lists what is missing before AI can be turned on, and the suggestions
         * that a later confirmation step could apply. Setup never applies them here, and booking never needs AI.
         */
        private static function render_ai_setup_review( array $draft )
        {
            if ( ! class_exists( 'TZFD_Ai_Setup_Check' ) ) return;
            $review = TZFD_Ai_Setup_Check::review( self::ai_setup_state( $draft ) );

            echo '<h3>' . esc_html__( 'AI setup check', 'titan-zero-trades-front-desk' ) . '</h3>';
            echo '<p class="description">' . esc_html__( 'AI stays off. Setup does not change anything listed below, and booking works without AI.', 'titan-zero-trades-front-desk' ) . '</p>';

            if ( empty( $review['missing'] ) ) {
                echo '<p>' . esc_html__( 'Nothing is missing for AI.', 'titan-zero-trades-front-desk' ) . '</p>';
            } else {
                echo '<p><strong>' . esc_html__( 'Missing configuration:', 'titan-zero-trades-front-desk' ) . '</strong></p><ul>';
                foreach ( $review['missing'] as $item ) {
                    $note = $item['required'] ? __( '(needed before AI can be turned on)', 'titan-zero-trades-front-desk' ) : __( '(optional)', 'titan-zero-trades-front-desk' );
                    echo '<li>' . esc_html( $item['label'] . ' ' . $note . ': ' . $item['hint'] ) . '</li>';
                }
                echo '</ul>';
            }

            if ( ! empty( $review['suggestions'] ) ) {
                echo '<p><strong>' . esc_html__( 'Suggestions for you to review:', 'titan-zero-trades-front-desk' ) . '</strong></p><ul>';
                foreach ( $review['suggestions'] as $suggestion ) {
                    echo '<li>' . esc_html( TZFD_Ai_Setup_Check::describe( $suggestion ) ) . '</li>';
                }
                echo '</ul>';
            }
        }

        /** Setup values for the AI check, read from the draft and the saved business profile. */
        private static function ai_setup_state( array $draft )
        {
            $profile = TZFD_Scoped_Store::get( 'tz_fd_business_profile', array() );
            if ( ! is_array( $profile ) ) $profile = array();
            $name = (string) ( $draft['details']['business_name'] ?? ( $profile['name'] ?? '' ) );
            $services = array();
            foreach ( (array) ( $draft['services'] ?? array() ) as $service ) {
                if ( is_array( $service ) && isset( $service['name'] ) ) $services[] = (string) $service['name'];
            }
            $greeting = TZFD_Scoped_Store::get( 'tz_fd_ai_greeting', '' );
            return array(
                'business_name'       => $name,
                'services'            => $services,
                'greeting'            => is_string( $greeting ) ? $greeting : '',
                'hours_configured'    => false, // This wizard does not collect opening hours, so hours stay a named gap.
                'faq_questions'       => array(), // Saved FAQ questions are not read here yet, so an existing FAQ could be offered again.
                'tags'                => array(),
                'connector_available' => function_exists( 'has_filter' ) && has_filter( TZFD_Ai_Free_Core::HOSTED_FILTER ),
            );
        }

        /** Short text for the review page. */
        private static function booking_summary( array $d )
        {
            $choice = $d['booking_choice'] ?? 'new';
            if ( 'page' === $choice && ! empty( $d['booking_page_id'] ) ) {
                return __( 'An existing page', 'titan-zero-trades-front-desk' );
            }
            if ( 'link' === $choice ) {
                return __( 'Your own link', 'titan-zero-trades-front-desk' );
            }
            return __( 'A new page will be created', 'titan-zero-trades-front-desk' );
        }

        /** Merges fields from one wizard page into the saved draft details. */
        private static function merge_details( array $draft, array $fields )
        {
            $draft['details'] = array_merge( is_array( $draft['details'] ?? null ) ? $draft['details'] : array(), $fields );
            self::save_draft( $draft );
        }

        public function render()
        {
            if ( ! current_user_can( 'tz_fd_access' ) ) return;
            TZFD_Admin_Menu::render_settings_tabs( 'presets' );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step selector for display; each step's save is nonce-protected.
            $step  = sanitize_key( sanitize_text_field( wp_unslash( $_GET['step'] ?? '1' ) ) );
            $draft = self::draft();
            $packs = TZFD_Vertical_Packs::available();

            echo '<div class="wrap"><h1>🧭 ' . esc_html__( 'Preset Setup', 'titan-zero-trades-front-desk' ) . '</h1>';
            // Setup-flow banner (Front Desk brand code).
            echo wp_kses( TZFD_Local_Brand::setup_flow( $step ), TZFD_Local_Brand::allowed_html() );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error text from a redirect; it is sanitized, escaped and changes no state.
            $setup_error = isset( $_GET['error'] ) ? tzfd_request_text( 'get', 'error' ) : '';
            if ( '' !== $setup_error ) {
                echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $setup_error ) ) . '</p></div>';
            }

            if ( 'done' === $step ) {
                $key = 'tz_fd_setup_secret_' . TZFD_Scoped_Store::company_id() . '_' . get_current_user_id();
                $secret = get_transient( $key );
                delete_transient( $key );
                echo '<h2>' . esc_html__( 'Setup complete', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p>' . esc_html__( 'Your phone system should post missed calls to:', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( rest_url( 'titan-zero-front-desk/v1/missed-call' ) ) . '</code></p>';
                if ( $secret ) {
                    echo '<p>' . esc_html__( 'Inbound secret (shown once; use it to sign each request, see Help):', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( $secret ) . '</code></p>';
                }
                echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=tzfd-setup&step=1' ) ) . '">' . esc_html__( 'Open the setup wizard', 'titan-zero-trades-front-desk' ) . '</a></p>';
                echo '</div>';
                return;
            }

            $form_open = '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php?page=tzfd-setup&step=' . $step ) ) . '">' . wp_nonce_field( 'tz_fd_setup', 'tz_fd_setup_nonce', true, false );

            if ( '1' === $step ) {
                echo '<h2>' . esc_html__( '1. Your trade', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p class="description tzfd-vp-intro">' . esc_html__( 'Your main trade sets the default services and answers for the steps that follow. You can also pick up to five trades in total; the main trade is always used first.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tz_fd_setup_action" value="vertical" />';

                $groups  = self::picker_groups( $packs );
                $current = (string) $draft['vertical'];
                $trades  = array_values( array_map( 'strval', (array) $draft['trades'] ) );
                $max     = TZFD_Vertical_Registry::MAX_TRADES;
                $count   = array();
                if ( '' !== $current && 'other' !== $current ) { $count[] = $current; }
                foreach ( $trades as $t ) { if ( '' !== $t && 'other' !== $t && ! in_array( $t, $count, true ) ) { $count[] = $t; } }
                /* translators: 1: number of verticals selected, 2: maximum verticals allowed. */
                echo '<p class="tzfd-vp-count" aria-live="polite"><strong>' . esc_html( sprintf( __( '%1$d of %2$d selected', 'titan-zero-trades-front-desk' ), min( count( $count ), $max ), $max ) ) . '</strong></p>';

                if ( array() === $groups ) {
                    echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No trades are available on this site yet. Choose Other vertical (no pack) and press Next to enter your services by hand.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                }

                if ( array() !== $groups ) {
                    echo '<fieldset class="tzfd-vp-picker"><legend>' . esc_html__( 'Main trade (radio) and other trades (tick boxes)', 'titan-zero-trades-front-desk' ) . '</legend>';
                    foreach ( $groups as $gid => $group ) {
                        $ids_in_group = wp_list_pluck( $group['items'], 'id' );
                        $open         = (bool) array_intersect( $ids_in_group, $count );
                        echo '<details class="tzfd-vp-group"' . ( $open ? ' open' : '' ) . '>';
                        echo '<summary>' . esc_html( $group['label'] ) . ' <span class="tzfd-vp-group-count">(' . count( $group['items'] ) . ')</span></summary>';
                        echo '<div class="tzfd-vp-list">';
                        foreach ( $group['items'] as $item ) {
                            $id      = $item['id'];
                            $is_main = ( $id === $current );
                            echo '<div class="tzfd-vp-row' . ( $is_main ? ' is-main' : '' ) . '">';
                            echo '<div class="tzfd-vp-info"><span class="tzfd-vp-icon" aria-hidden="true">' . esc_html( $item['icon'] ) . '</span><span class="tzfd-vp-text"><span class="tzfd-vp-name">' . esc_html( $item['name'] ) . '</span>';
                            if ( '' !== $item['tagline'] ) { echo '<span class="tzfd-vp-tagline">' . esc_html( $item['tagline'] ) . '</span>'; }
                            echo '</span></div>';
                            echo '<label class="tzfd-vp-main"><input type="radio" name="vertical" value="' . esc_attr( $id ) . '"' . checked( $current, $id, false ) . ' /> <span>' . esc_html__( 'Main', 'titan-zero-trades-front-desk' ) . '</span></label>';
                            // The main trade is always selected: its box is shown ticked and cannot be cleared.
                            echo '<label class="tzfd-vp-also"><input type="checkbox" name="trades[]" value="' . esc_attr( $id ) . '"' . ( $is_main ? ' checked disabled' : checked( in_array( $id, $trades, true ), true, false ) ) . ' /> <span>' . esc_html__( 'Also', 'titan-zero-trades-front-desk' ) . '</span></label>';
                            echo '</div>';
                        }
                        echo '</div></details>';
                    }
                    echo '</fieldset>';
                }

                echo '<fieldset class="tzfd-vp-picker tzfd-vp-picker-other"><legend>' . esc_html__( 'No trade in the list', 'titan-zero-trades-front-desk' ) . '</legend>';
                echo '<div class="tzfd-vp-row"><label class="tzfd-vp-main tzfd-vp-wide"><input type="radio" name="vertical" value="other"' . checked( $current, 'other', false ) . ' /> <span>' . esc_html__( 'Other vertical (no pack)', 'titan-zero-trades-front-desk' ) . '</span></label></div>';
                echo '</fieldset>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '2' === $step ) {
                echo '<h2>' . esc_html__( '2. Where should services come from?', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tz_fd_setup_action" value="sources" />';
                $opts = array(
                    'pack'    => __( 'Your vertical pack', 'titan-zero-trades-front-desk' ),
                    'site'    => __( 'This site\'s booking calendars', 'titan-zero-trades-front-desk' ),
                    'plugins' => __( 'Other booking plugins on this site (Bookly, Amelia, LatePoint, Booknetic)', 'titan-zero-trades-front-desk' ),
                    'csv'     => __( 'A CSV file (name, price)', 'titan-zero-trades-front-desk' ),
                    'url'     => __( 'A web page (list items are read)', 'titan-zero-trades-front-desk' ),
                );
                foreach ( $opts as $key => $label ) {
                    echo '<p><label><input type="checkbox" name="sources[]" value="' . esc_attr( $key ) . '" ' . checked( 'pack' === $key && isset( $packs[ $draft['vertical'] ] ), true, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
                }
                echo '<p><input type="file" name="services_csv" accept=".csv,text/csv" /></p>';
                echo '<p><input type="url" name="web_url" class="regular-text" placeholder="https://" /> <span class="description">' . esc_html__( 'Must start with https://. Read once, when you press Next. Nothing is sent.', 'titan-zero-trades-front-desk' ) . '</span></p>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Find services', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '3' === $step ) {
                echo '<h2>' . wp_kses( TZFD_UI_Graphics::brand_html( 'services', 32 ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( '3. Choose your services', 'titan-zero-trades-front-desk' ) . '</h2>';
                if ( empty( $draft['candidates'] ) ) {
                    echo '<p>' . esc_html__( 'No services were found. Go back and try another source, or add them below.', 'titan-zero-trades-front-desk' ) . '</p>';
                }
                echo wp_kses( $form_open . '<input type="hidden" name="tz_fd_setup_action" value="services" />', self::form_allowed_html() );
                foreach ( $draft['candidates'] as $i => $c ) {
                    echo '<p><label><input type="checkbox" name="services[]" value="' . esc_attr( $i ) . '" checked /> ' . wp_kses( TZFD_UI_Graphics::service_html( $c['name'] ), TZFD_UI_Graphics::allowed_html() ) . esc_html( $c['name'] ) . ( '' !== $c['price'] ? ' — ' . esc_html( $c['price'] ) : '' ) . ' <span class="description">(' . esc_html( $c['source'] ) . ')</span></label></p>';
                }
                echo '<p><label>' . esc_html__( 'Add services, one per line', 'titan-zero-trades-front-desk' ) . '<br><textarea name="extra_services" rows="4" class="large-text"></textarea></label></p>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '4' === $step ) {
                $answers = $draft['answers'] ?? array();
                echo '<h2>' . esc_html__( '4. Questions and prices', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p class="description">' . esc_html__( 'These questions and answers come from your trade pack. Change any answer, tick Remove to drop a question, or add your own questions at the bottom. Customers see them on your booking page.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo wp_kses( $form_open . '<input type="hidden" name="tz_fd_setup_action" value="answers" />', self::form_allowed_html() );
                $pack = $packs[ $draft['vertical'] ] ?? null;
                if ( ! $pack ) {
                    echo '<p>' . esc_html__( 'No trade pack was chosen, so there are no questions or prices to review. Press Next to continue.', 'titan-zero-trades-front-desk' ) . '</p>';
                } else {
                    $edits      = ( ( $answers['vertical'] ?? '' ) === $draft['vertical'] ) ? (array) ( $answers['edits'] ?? array() ) : TZFD_Vertical_Packs::edits( $draft['vertical'] );
                    $pack_count = count( (array) $pack['kb'] );
                    $removed    = array_map( 'intval', (array) ( $edits['kb_removed'] ?? array() ) );
                    // Removed questions stay visible (ticked) so the owner can restore them.
                    $shown_edits = $edits;
                    unset( $shown_edits['kb_removed'] );
                    $view = TZFD_Vertical_Packs::apply_edits( TZFD_Vertical_Packs::apply_vars( $pack ), $shown_edits );
                    echo '<h3>' . esc_html__( 'Questions customers ask', 'titan-zero-trades-front-desk' ) . '</h3>';
                    foreach ( (array) $view['kb'] as $i => $qa ) {
                        $i = (int) $i;
                        echo '<fieldset class="tzfd-qa-row"><p><label>' . esc_html__( 'Question', 'titan-zero-trades-front-desk' ) . '<br><input type="text" class="large-text" name="kb_q[' . (int) $i . ']" value="' . esc_attr( $qa['q'] ?? '' ) . '" /></label></p>';
                        echo '<p><label>' . esc_html__( 'Answer', 'titan-zero-trades-front-desk' ) . '<br><textarea class="large-text" rows="3" name="kb_a[' . (int) $i . ']">' . esc_textarea( $qa['a'] ?? '' ) . '</textarea></label></p>';
                        echo '<p><label><input type="checkbox" name="kb_del[' . (int) $i . ']" value="1" ' . checked( in_array( $i, $removed, true ), true, false ) . ' /> ' . esc_html__( 'Remove this question', 'titan-zero-trades-front-desk' ) . '</label></p></fieldset>';
                    }
                    echo '<h3>' . esc_html__( 'Add your own questions (optional)', 'titan-zero-trades-front-desk' ) . '</h3>';
                    echo '<p class="description">' . esc_html__( 'Write a question a customer often asks, and the answer you would give. Leave a row empty to skip it.', 'titan-zero-trades-front-desk' ) . '</p>';
                    for ( $k = 0; $k < 3; $k++ ) {
                        echo '<fieldset class="tzfd-qa-row"><p><label>' . esc_html__( 'New question', 'titan-zero-trades-front-desk' ) . '<br><input type="text" class="large-text" name="kb_nq[' . (int) $k . ']" value="" /></label></p>';
                        echo '<p><label>' . esc_html__( 'Your answer', 'titan-zero-trades-front-desk' ) . '<br><textarea class="large-text" rows="2" name="kb_na[' . (int) $k . ']"></textarea></label></p></fieldset>';
                    }
                    echo '<h3>' . esc_html__( 'Prices', 'titan-zero-trades-front-desk' ) . '</h3>';
                    echo '<p class="description">' . esc_html__( 'Example prices from the trade pack, per unit. Enter numbers only. You can set your own full price list later in Titan Zero Quotes.', 'titan-zero-trades-front-desk' ) . '</p>';
                    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Price per unit', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
                    foreach ( (array) $view['catalog'] as $i => $item ) {
                        echo '<tr><td><input type="text" class="regular-text" name="cat_label[' . (int) $i . ']" value="' . esc_attr( $item['label'] ?? '' ) . '" /></td>';
                        echo '<td><input type="number" min="0" step="0.01" name="cat_price[' . (int) $i . ']" value="' . esc_attr( number_format( (float) ( $item['unit_price'] ?? 0 ), 2, '.', '' ) ) . '" /> <span class="description">' . esc_html( $item['unit'] ?? '' ) . '</span></td></tr>';
                    }
                    echo '</tbody></table>';
                }
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '5' === $step ) {
                $d = $draft['details'];
                echo '<h2>' . esc_html__( '5. Your business', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p class="description">' . esc_html__( 'Your business name, the area you work in, and where customers book.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tz_fd_setup_action" value="business" />';
                echo '<p><label>' . esc_html__( 'What is your business called?', 'titan-zero-trades-front-desk' ) . '<br><input type="text" class="large-text" name="business_name" value="' . esc_attr( $d['business_name'] ?? '' ) . '" /></label></p>';
                echo '<p><label>' . esc_html__( 'Which suburbs or towns do you work in?', 'titan-zero-trades-front-desk' ) . '<br><input type="text" class="large-text" name="service_area" value="' . esc_attr( $d['service_area'] ?? '' ) . '" /></label></p>';
                self::render_booking_choice( $d );
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '6' === $step ) {
                $d = $draft['details'];
                echo '<h2>' . esc_html__( '6. Pricing and payment', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p class="description">' . esc_html__( 'Answer in your own words. Customers read these on your booking page. Leave a question as it is if the wording suits you.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tz_fd_setup_action" value="pricing" />';
                self::render_policy_fields( self::policy_fields( 'price' ), $d );
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '7' === $step ) {
                $d = $draft['details'];
                echo '<h2>' . esc_html__( '7. Other policies', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p class="description">' . esc_html__( 'Everything here is optional. Open a question to answer it, or leave it as it is.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tz_fd_setup_action" value="policies" />';
                echo '<details class="tzfd-policy-group"><summary>' . esc_html__( 'Other policies and questions', 'titan-zero-trades-front-desk' ) . '</summary>';
                self::render_policy_fields( self::policy_fields( 'other' ), $d );
                echo '</details>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '8' === $step ) {
                $d = $draft['details'];
                echo '<h2>' . esc_html__( '8. Booking confirmation and text-back', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tz_fd_setup_action" value="confirm" />';
                $tz_intake_now = isset( $d['intake_mode'] ) ? $d['intake_mode'] : TZFD_Intake_Mode::default_mode();
                echo '<p><label>' . esc_html__( 'When a customer books online, how should it be confirmed?', 'titan-zero-trades-front-desk' ) . '<br><select name="intake_mode">';
                echo '<option value="instant"' . selected( $tz_intake_now, TZFD_Intake_Mode::MODE_INSTANT, false ) . '>' . esc_html__( 'Straight away. The booking is confirmed at once.', 'titan-zero-trades-front-desk' ) . '</option>';
                echo '<option value="request"' . selected( $tz_intake_now, TZFD_Intake_Mode::MODE_REQUEST, false ) . '>' . esc_html__( 'After I approve it. The booking waits as Pending until you approve it.', 'titan-zero-trades-front-desk' ) . '</option>';
                echo '</select></label></p>';
                echo '<p class="description">' . esc_html__( 'Choose "After I approve it" if you check every job before you accept it. You can change this for each calendar later in Settings.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo '<h3>' . esc_html__( 'Text back missed calls (optional)', 'titan-zero-trades-front-desk' ) . '</h3>';
                echo '<p><label><input type="checkbox" name="missed_call_on" value="1" ' . checked( ! empty( $d['missed_call_on'] ), true, false ) . ' /> ' . esc_html__( 'When I miss a call, send the caller a text with a booking link', 'titan-zero-trades-front-desk' ) . '</label></p>';
                echo '<p class="description">' . esc_html__( 'This needs an SMS service that you already pay for. Leave it off if you do not have one.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo '<p><label>' . esc_html__( 'SMS service address (starts with https://)', 'titan-zero-trades-front-desk' ) . '<br><input type="url" class="large-text" name="sms_url" value="' . esc_attr( $d['sms_url'] ?? '' ) . '" /></label></p>';
                echo '<p><label>' . esc_html__( 'Name of the login header your SMS service asks for (leave blank for Authorization)', 'titan-zero-trades-front-desk' ) . '<br><input type="text" name="sms_auth_header" value="' . esc_attr( $d['sms_auth_header'] ?? '' ) . '" /></label></p>';
                echo '<p><label>' . esc_html__( 'Login key for your SMS service (leave blank to keep the current one)', 'titan-zero-trades-front-desk' ) . '<br><input type="password" class="large-text" name="sms_auth_value" value="" /></label></p>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '9' === $step ) {
                echo '<h2>' . esc_html__( '9. Review and finish', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p>' . esc_html__( 'Trade:', 'titan-zero-trades-front-desk' ) . ' <strong>' . esc_html( $draft['vertical'] ?: '—' ) . '</strong></p>';
                echo '<p>' . esc_html__( 'Services:', 'titan-zero-trades-front-desk' ) . ' <strong>' . count( $draft['services'] ) . '</strong></p>';
                $tz_review_mode = TZFD_Intake_Mode::normalize( $draft['details']['intake_mode'] ?? '' );
                echo '<p>' . esc_html__( 'Booking confirmation:', 'titan-zero-trades-front-desk' ) . ' <strong>' . ( TZFD_Intake_Mode::MODE_REQUEST === $tz_review_mode ? esc_html__( 'after you approve it', 'titan-zero-trades-front-desk' ) : esc_html__( 'straight away', 'titan-zero-trades-front-desk' ) ) . '</strong></p>';
                echo '<p>' . esc_html__( 'Booking page:', 'titan-zero-trades-front-desk' ) . ' <strong>' . esc_html( self::booking_summary( $draft['details'] ) ) . '</strong></p>';
                echo '<p>' . esc_html__( 'Text back missed calls:', 'titan-zero-trades-front-desk' ) . ' <strong>' . ( ! empty( $draft['details']['missed_call_on'] ) ? esc_html__( 'on', 'titan-zero-trades-front-desk' ) : esc_html__( 'off', 'titan-zero-trades-front-desk' ) ) . '</strong></p>';
                self::render_ai_setup_review( $draft );
                echo wp_kses( $form_open . '<input type="hidden" name="tz_fd_setup_action" value="finish" />', self::form_allowed_html() );
                echo '<p><button class="button button-primary">' . esc_html__( 'Finish setup', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } else {
                echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=tzfd-setup&step=1' ) ) . '">' . esc_html__( 'Open the setup wizard', 'titan-zero-trades-front-desk' ) . '</a></p>';
            }
            echo '</div>';
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $tz_onboarding_obj = new TZFD_Onboarding();

    // Add addon object to the objects list
    global $tz_addons_objs_list;
    $tz_addons_objs_list[ $tz_onboarding_obj->get_addon_id() ] = $tz_onboarding_obj;
}
