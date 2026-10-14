<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__.'/base.addon.php';

if ( ! class_exists( 'TZFD_Onboarding' ) )
{
    /**
     * Setup wizard: trade, services, details, and missed-call text-back in one guided flow.
     * Services can come from the trade pack, this site's booking calendars, other booking
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

        const DRAFT    = 'tzfd_setup_draft';
        const DONE     = 'tzfd_setup_done';
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
            $this->description = $this->tr_apply( "Guided setup: choose your trade, pick services from your trade pack, your calendars, other booking plugins, a CSV file or a web page, then enter your details.", 'titan-zero-trades-front-desk' );
            // The setup wizard is always available to administrators. Its menu page and form handler
            // must not depend on the Addons switch, or Start setup leads to a 'not allowed' page.
            add_action( 'admin_menu', array( &$this, 'add_menu' ), 117 );
            add_action( 'admin_init', array( &$this, 'handle_post' ) );
        }

        public function add_menu()
        {
            add_submenu_page(
                '',
                __( 'Preset Setup', 'titan-zero-trades-front-desk' ),
                __( 'Preset Setup', 'titan-zero-trades-front-desk' ),
                'manage_options',
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
        public static function candidates_from_tables( $db )
        {
            $out = array();
            foreach ( self::PLUGIN_TABLES as $spec ) {
                list( $suffix, $name_col, $price_col, $label ) = $spec;
                $table = $db->prefix . $suffix;
                if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) continue;
                $rows = $db->get_results( $db->prepare( 'SELECT %i AS name, %i AS price FROM %i LIMIT 200', $name_col, $price_col, $table ), ARRAY_A );
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
            $new = array(
                'inbound_secret'  => '' !== $inbound_secret ? $inbound_secret : $current['inbound_secret'],
                'sms_url'         => trim( (string) ( $d['sms_url'] ?? '' ) ),
                'sms_auth_header' => '' !== $auth_header ? $auth_header : 'Authorization',
                'sms_auth_value'  => '' !== $auth_value ? $auth_value : $current['sms_auth_value'],
                'booking_url'     => trim( (string) ( $d['booking_url'] ?? '' ) ),
                'message'         => $defaults['message'],
            );
            return $new;
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

            $biz = get_option( 'tzfd_business_profile', array() );
            if ( ! is_array( $biz ) ) $biz = array();
            $biz['name']     = sanitize_text_field( (string) ( $d['business_name'] ?? ( $biz['name'] ?? '' ) ) );
            $biz['services'] = implode( ', ', array_map( function ( $s ) { return $s['name']; }, $services ) );
            $biz['area']     = sanitize_text_field( (string) ( $d['service_area'] ?? ( $biz['area'] ?? '' ) ) );
            update_option( 'tzfd_business_profile', $biz );
            update_option( 'tzfd_ai_services', $biz['services'] );
            update_option( 'tzfd_ai_service_area', $biz['area'] );

            $vars = array();
            foreach ( TZFD_Vertical_Packs::default_vars() as $key => $spec ) {
                $vars[ $key ] = sanitize_text_field( (string) ( $d[ $key ] ?? '' ) );
            }
            update_option( TZFD_Vertical_Packs::VARS_OPTION, $vars, false );

            if ( $on ) {
                if ( ! class_exists( 'TZFD_Addon_Support' ) ) return 'Missed-call settings are not available in this build.';
                TZFD_Addon_Support::save_global_settings( 'missed_call', TZFD_MissedCall::defaults(), $settings );
            }
            return '';
        }

        /************************ DRAFT STATE *****************************/

        public static function draft()
        {
            $d = get_option( self::DRAFT, array() );
            return array_merge( array( 'vertical' => '', 'candidates' => array(), 'services' => array(), 'details' => array() ), is_array( $d ) ? $d : array() );
        }

        private static function save_draft( array $d )
        {
            update_option( self::DRAFT, $d, false );
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
            if ( empty( $_POST['tzfd_setup_action'] ) || ! current_user_can( 'manage_options' ) ) return;
            check_admin_referer( 'tzfd_setup', 'tzfd_setup_nonce' );

            $action = sanitize_key( wp_unslash( $_POST['tzfd_setup_action'] ) );
            $draft  = self::draft();
            $packs  = TZFD_Vertical_Packs::available();

            if ( 'vertical' === $action ) {
                $v = sanitize_key( wp_unslash( $_POST['vertical'] ?? '' ) );
                $draft['vertical'] = ( 'other' === $v || isset( $packs[ $v ] ) ) ? $v : '';
                self::save_draft( $draft );
                return self::go( 2 );
            }

            if ( 'sources' === $action ) {
                $sources = (array) map_deep( wp_unslash( $_POST['sources'] ?? array() ), 'sanitize_key' );
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
                    $forms = $wpdb->get_results( $wpdb->prepare( 'SELECT form_name AS name, form_structure AS structure FROM %i', $wpdb->prefix . 'tzfd_forms' ), ARRAY_A );
                    $items = array_merge( $items, self::candidates_from_forms( (array) $forms ) );
                }
                if ( in_array( 'plugins', $sources, true ) ) {
                    $items = array_merge( $items, self::candidates_from_tables( $wpdb ) );
                }
                // The upload is checked with is_uploaded_file(), its size and its extension, then parsed as data.
                $csv_tmp  = isset( $_FILES['services_csv']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['services_csv']['tmp_name'] ) ) : '';
                $csv_name = isset( $_FILES['services_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['services_csv']['name'] ) ) : '';
                $csv_size = isset( $_FILES['services_csv']['size'] ) ? absint( $_FILES['services_csv']['size'] ) : 0;
                if ( in_array( 'csv', $sources, true ) && '' !== $csv_tmp && is_uploaded_file( $csv_tmp ) ) {
                    if ( $csv_size <= self::MAX_CSV && 'csv' === strtolower( pathinfo( $csv_name, PATHINFO_EXTENSION ) ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        $csv_text = ( WP_Filesystem() && isset( $GLOBALS['wp_filesystem'] ) ) ? (string) $GLOBALS['wp_filesystem']->get_contents( $csv_tmp ) : '';
                        $items = array_merge( $items, self::candidates_from_csv( $csv_text ) );
                    }
                }
                $url = trim( esc_url_raw( wp_unslash( $_POST['web_url'] ?? '' ) ) );
                if ( in_array( 'url', $sources, true ) && 0 === stripos( $url, 'https://' ) && TZFD_MissedCall::is_https( $url ) ) {
                    // wp_safe_remote_get() refuses private and loopback hosts. The page is read once and only parsed as text.
                    $resp = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3 ) );
                    if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
                        $body  = substr( (string) wp_remote_retrieve_body( $resp ), 0, self::MAX_HTML );
                        $items = array_merge( $items, self::candidates_from_html( $body ) );
                    }
                }
                $draft['candidates'] = self::merge_candidates( $items );
                self::save_draft( $draft );
                return self::go( 3 );
            }

            if ( 'services' === $action ) {
                $chosen = array();
                foreach ( (array) map_deep( wp_unslash( $_POST['services'] ?? array() ), 'absint' ) as $i ) {
                    if ( isset( $draft['candidates'][ $i ] ) ) $chosen[] = $draft['candidates'][ $i ];
                }
                $typed = self::parse_services_text( sanitize_textarea_field( wp_unslash( $_POST['extra_services'] ?? '' ) ) );
                $draft['services'] = self::merge_candidates( array_merge( $chosen, $typed ) );
                self::save_draft( $draft );
                return self::go( 4 );
            }

            if ( 'answers' === $action ) {
                $pack = $packs[ $draft['vertical'] ] ?? null;
                if ( $pack ) {
                    $raw = array( 'kb' => array(), 'catalog' => array() );
                    $kq  = (array) map_deep( wp_unslash( $_POST['kb_q'] ?? array() ), 'sanitize_text_field' );
                    $ka  = (array) map_deep( wp_unslash( $_POST['kb_a'] ?? array() ), 'sanitize_textarea_field' );
                    foreach ( $kq as $i => $q ) {
                        $raw['kb'][ (int) $i ] = array( 'q' => $q, 'a' => $ka[ $i ] ?? '' );
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

            if ( 'details' === $action ) {
                $d = array();
                foreach ( array( 'business_name', 'service_area', 'sms_url', 'sms_auth_header', 'sms_auth_value', 'booking_url' ) as $k ) {
                    $d[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );
                }
                foreach ( array_keys( TZFD_Vertical_Packs::default_vars() ) as $k ) {
                    $d[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );
                }
                $d['missed_call_on'] = ! empty( $_POST['missed_call_on'] ) ? '1' : '';
                $d['intake_mode'] = TZFD_Intake_Mode::normalize( sanitize_key( wp_unslash( $_POST['intake_mode'] ?? '' ) ) );
                $draft['details'] = $d;
                self::save_draft( $draft );
                return self::go( 6 );
            }

            if ( 'finish' === $action ) {
                $d = $draft['details'];
                $secret = '';
                $current = TZFD_Addon_Support::global_settings( 'missed_call', TZFD_MissedCall::defaults() );
                if ( ! empty( $d['missed_call_on'] ) && '' === $current['inbound_secret'] ) {
                    $secret = wp_generate_password( 48, false );
                }
                // Check the missed-call settings before anything is written.
                if ( ! empty( $d['missed_call_on'] ) ) {
                    $err = TZFD_MissedCall::settings_error( self::missed_call_settings( $d, $secret ) );
                    if ( '' !== $err ) return self::go( 5, $err );
                }
                // Answer and price edits from step 4 go in before the pack installs.
                $answers = $draft['answers'] ?? array();
                if ( isset( $packs[ $draft['vertical'] ] ) && ! empty( $answers ) && ( $answers['vertical'] ?? '' ) === $draft['vertical'] ) {
                    TZFD_Vertical_Packs::save_edits( $draft['vertical'], (array) ( $answers['edits'] ?? array() ) );
                }
                // Pack details first, so the pack installs with this site's answers.
                update_option( TZFD_Vertical_Packs::VARS_OPTION, array_intersect_key( $d, TZFD_Vertical_Packs::default_vars() ), false );
                if ( isset( $packs[ $draft['vertical'] ] ) && class_exists( 'TZFD_Vertical_Packs' ) ) {
                    if ( TZFD_Vertical_Packs::is_installed( $draft['vertical'] ) ) TZFD_Vertical_Packs::uninstall_pack( $draft['vertical'] );
                    TZFD_Vertical_Packs::install_pack( $draft['vertical'] );
                }
                $err = self::apply_details( $d, $draft['services'], $secret );
                if ( '' !== $err ) return self::go( 5, $err );

                // Booking intake: the chosen mode becomes the site default and applies to every calendar in the setup.
                $tzfd_intake = TZFD_Intake_Mode::normalize( $d['intake_mode'] ?? '' );
                TZFD_Intake_Mode::set_default( $tzfd_intake );
                global $wpdb;
                $tzfd_intake_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', $wpdb->prefix . 'tzfd_forms' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table name, bound with %i.
                foreach ( (array) $tzfd_intake_ids as $tzfd_intake_id ) {
                    TZFD_Intake_Mode::set_for_form( (int) $tzfd_intake_id, $tzfd_intake );
                }

                update_option( self::DONE, gmdate( 'Y-m-d H:i' ) . ' UTC', false );
                delete_option( self::DRAFT );
                if ( '' !== $secret ) set_transient( 'tzfd_setup_secret_' . get_current_user_id(), $secret, 600 );
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

        public function render()
        {
            if ( ! current_user_can( 'manage_options' ) ) return;
            TZFD_Admin_Menu::render_settings_tabs( 'presets' );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step selector for display; each step's save is nonce-protected.
            $step  = sanitize_key( wp_unslash( $_GET['step'] ?? '1' ) );
            $draft = self::draft();
            $packs = TZFD_Vertical_Packs::available();

            echo '<div class="wrap"><h1>🧭 ' . esc_html__( 'Preset Setup', 'titan-zero-trades-front-desk' ) . '</h1>';
            echo wp_kses( TZFD_Brand::setup_flow(), TZFD_Brand::allowed_html() );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error text from a redirect; it is sanitized, escaped and changes no state.
            $setup_error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
            if ( '' !== $setup_error ) {
                echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $setup_error ) ) . '</p></div>';
            }

            if ( 'done' === $step ) {
                $key = 'tzfd_setup_secret_' . get_current_user_id();
                $secret = get_transient( $key );
                delete_transient( $key );
                echo '<h2>' . esc_html__( 'Setup complete', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p>' . esc_html__( 'Your phone system should post missed calls to:', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( rest_url( 'tzfd/v1/missed-call' ) ) . '</code></p>';
                if ( $secret ) {
                    echo '<p>' . esc_html__( 'Inbound secret (shown once; send it in the X-TZFD-Secret header):', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( $secret ) . '</code></p>';
                }
                echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=tzfd-setup&step=1' ) ) . '">' . esc_html__( 'Open the setup wizard', 'titan-zero-trades-front-desk' ) . '</a></p>';
                echo '</div>';
                return;
            }

            $form_open = '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php?page=tzfd-setup&step=' . $step ) ) . '">' . wp_nonce_field( 'tzfd_setup', 'tzfd_setup_nonce', true, false );

            if ( '1' === $step ) {
                echo '<h2>' . esc_html__( '1. Your trade', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tzfd_setup_action" value="vertical" />';
                foreach ( $packs as $id => $p ) {
                    echo '<p><label><input type="radio" name="vertical" value="' . esc_attr( $id ) . '" ' . checked( $draft['vertical'], $id, false ) . ' /> ' . esc_html( ( $p['icon'] ?? '' ) . ' ' . $p['name'] ) . ' <span style="color:#64748b">' . esc_html( $p['tagline'] ?? '' ) . '</span></label></p>';
                }
                echo '<p><label><input type="radio" name="vertical" value="other" ' . checked( $draft['vertical'], 'other', false ) . ' /> ' . esc_html__( 'Other trade (no pack)', 'titan-zero-trades-front-desk' ) . '</label></p>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '2' === $step ) {
                echo '<h2>' . esc_html__( '2. Where should services come from?', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tzfd_setup_action" value="sources" />';
                $opts = array(
                    'pack'    => __( 'Your trade pack', 'titan-zero-trades-front-desk' ),
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
                echo '<h2>' . esc_html__( '3. Choose your services', 'titan-zero-trades-front-desk' ) . '</h2>';
                if ( empty( $draft['candidates'] ) ) {
                    echo '<p>' . esc_html__( 'No services were found. Go back and try another source, or add them below.', 'titan-zero-trades-front-desk' ) . '</p>';
                }
                echo wp_kses( $form_open . '<input type="hidden" name="tzfd_setup_action" value="services" />', self::form_allowed_html() );
                foreach ( $draft['candidates'] as $i => $c ) {
                    echo '<p><label><input type="checkbox" name="services[]" value="' . esc_attr( $i ) . '" checked /> ' . esc_html( $c['name'] ) . ( '' !== $c['price'] ? ' — ' . esc_html( $c['price'] ) : '' ) . ' <span class="description">(' . esc_html( $c['source'] ) . ')</span></label></p>';
                }
                echo '<p><label>' . esc_html__( 'Add services, one per line', 'titan-zero-trades-front-desk' ) . '<br><textarea name="extra_services" rows="4" class="large-text"></textarea></label></p>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '4' === $step ) {
                echo '<h2>' . esc_html__( '4. Review answers and prices', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p class="description">' . esc_html__( 'These come from your trade pack. Change any of them. Only the text you change is saved. Standard wording keeps following your business details entered on the next page.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo wp_kses( $form_open . '<input type="hidden" name="tzfd_setup_action" value="answers" />', self::form_allowed_html() );
                $pack = $packs[ $draft['vertical'] ] ?? null;
                if ( ! $pack ) {
                    echo '<p>' . esc_html__( 'No trade pack was chosen, so there are no answers or prices to review. Press Next to continue.', 'titan-zero-trades-front-desk' ) . '</p>';
                } else {
                    $answers = $draft['answers'] ?? array();
                    $edits   = ( ( $answers['vertical'] ?? '' ) === $draft['vertical'] ) ? (array) ( $answers['edits'] ?? array() ) : TZFD_Vertical_Packs::edits( $draft['vertical'] );
                    $view    = TZFD_Vertical_Packs::apply_edits( TZFD_Vertical_Packs::apply_vars( $pack ), $edits );
                    echo '<h3>' . esc_html__( 'Questions customers ask', 'titan-zero-trades-front-desk' ) . '</h3>';
                    foreach ( (array) $view['kb'] as $i => $qa ) {
                        echo '<p><label>' . esc_html__( 'Question', 'titan-zero-trades-front-desk' ) . '<br><input type="text" class="large-text" name="kb_q[' . (int) $i . ']" value="' . esc_attr( $qa['q'] ?? '' ) . '" /></label></p>';
                        echo '<p><label>' . esc_html__( 'Answer', 'titan-zero-trades-front-desk' ) . '<br><textarea class="large-text" rows="3" name="kb_a[' . (int) $i . ']">' . esc_textarea( $qa['a'] ?? '' ) . '</textarea></label></p><hr />';
                    }
                    echo '<h3>' . esc_html__( 'Prices', 'titan-zero-trades-front-desk' ) . '</h3>';
                    echo '<p class="description">' . esc_html__( 'Example prices from the pack. Set your own before you use Quotes. Enter numbers only.', 'titan-zero-trades-front-desk' ) . '</p>';
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
                echo '<h2>' . esc_html__( '5. Your details', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo wp_kses( $form_open, self::form_allowed_html() );
                echo '<input type="hidden" name="tzfd_setup_action" value="details" />';
                $fields = array_merge(
                    array( 'business_name' => array( 'label' => 'Business name', 'default' => '' ), 'service_area' => array( 'label' => 'Service area', 'default' => '' ), 'booking_url' => array( 'label' => 'Booking page link', 'default' => '' ) ),
                    TZFD_Vertical_Packs::default_vars()
                );
                foreach ( $fields as $key => $spec ) {
                    echo '<p><label>' . esc_html( $spec['label'] ) . '<br><input type="text" class="large-text" name="' . esc_attr( $key ) . '" value="' . esc_attr( $d[ $key ] ?? '' ) . '" /></label></p>';
                }
                echo '<h3>' . esc_html__( 'Booking intake', 'titan-zero-trades-front-desk' ) . '</h3>';
                $tzfd_intake_now = isset( $d['intake_mode'] ) ? $d['intake_mode'] : TZFD_Intake_Mode::default_mode();
                echo '<p><label>' . esc_html__( 'How new bookings are confirmed', 'titan-zero-trades-front-desk' ) . '<br><select name="intake_mode">';
                echo '<option value="instant"' . selected( $tzfd_intake_now, TZFD_Intake_Mode::MODE_INSTANT, false ) . '>' . esc_html__( 'Instant confirmation (the booking is confirmed straight away)', 'titan-zero-trades-front-desk' ) . '</option>';
                echo '<option value="request"' . selected( $tzfd_intake_now, TZFD_Intake_Mode::MODE_REQUEST, false ) . '>' . esc_html__( 'Request (saved as Pending until you approve it)', 'titan-zero-trades-front-desk' ) . '</option>';
                echo '</select></label></p>';
                echo '<p class="description">' . esc_html__( 'Request mode suits businesses that approve each booking first. You can change this per calendar later under Settings.', 'titan-zero-trades-front-desk' ) . '</p>';
                echo '<h3>' . esc_html__( 'Missed-call text-back (optional)', 'titan-zero-trades-front-desk' ) . '</h3>';
                echo '<p><label><input type="checkbox" name="missed_call_on" value="1" ' . checked( ! empty( $d['missed_call_on'] ), true, false ) . ' /> ' . esc_html__( 'Text callers a booking link when a call is missed', 'titan-zero-trades-front-desk' ) . '</label></p>';
                echo '<p><label>' . esc_html__( 'SMS endpoint (https://)', 'titan-zero-trades-front-desk' ) . '<br><input type="url" class="large-text" name="sms_url" value="' . esc_attr( $d['sms_url'] ?? '' ) . '" /></label></p>';
                echo '<p><label>' . esc_html__( 'Auth header name (blank = Authorization)', 'titan-zero-trades-front-desk' ) . '<br><input type="text" name="sms_auth_header" value="' . esc_attr( $d['sms_auth_header'] ?? '' ) . '" /></label></p>';
                echo '<p><label>' . esc_html__( 'Auth header value (blank keeps the current one)', 'titan-zero-trades-front-desk' ) . '<br><input type="password" class="large-text" name="sms_auth_value" value="" /></label></p>';
                echo '<p><button class="button button-primary">' . esc_html__( 'Next', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } elseif ( '6' === $step ) {
                echo '<h2>' . esc_html__( '6. Review and finish', 'titan-zero-trades-front-desk' ) . '</h2>';
                echo '<p>' . esc_html__( 'Trade:', 'titan-zero-trades-front-desk' ) . ' <strong>' . esc_html( $draft['vertical'] ?: '—' ) . '</strong></p>';
                echo '<p>' . esc_html__( 'Services:', 'titan-zero-trades-front-desk' ) . ' <strong>' . count( $draft['services'] ) . '</strong></p>';
                $tzfd_review_mode = TZFD_Intake_Mode::normalize( $draft['details']['intake_mode'] ?? '' );
                echo '<p>' . esc_html__( 'Booking intake:', 'titan-zero-trades-front-desk' ) . ' <strong>' . ( TZFD_Intake_Mode::MODE_REQUEST === $tzfd_review_mode ? esc_html__( 'request (approve first)', 'titan-zero-trades-front-desk' ) : esc_html__( 'instant confirmation', 'titan-zero-trades-front-desk' ) ) . '</strong></p>';
                echo '<p>' . esc_html__( 'Missed-call text-back:', 'titan-zero-trades-front-desk' ) . ' <strong>' . ( ! empty( $draft['details']['missed_call_on'] ) ? esc_html__( 'on', 'titan-zero-trades-front-desk' ) : esc_html__( 'off', 'titan-zero-trades-front-desk' ) ) . '</strong></p>';
                echo wp_kses( $form_open . '<input type="hidden" name="tzfd_setup_action" value="finish" />', self::form_allowed_html() );
                echo '<p><button class="button button-primary">' . esc_html__( 'Finish setup', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
            } else {
                echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=tzfd-setup&step=1' ) ) . '">' . esc_html__( 'Open the setup wizard', 'titan-zero-trades-front-desk' ) . '</a></p>';
            }
            echo '</div>';
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $tzfd_onboarding_obj = new TZFD_Onboarding();

    // Add addon object to the objects list
    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $tzfd_onboarding_obj->get_addon_id() ] = $tzfd_onboarding_obj;
}
