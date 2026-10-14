<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/**
 * Vertical Packs — industry presets
 *
 * A pack is a JSON manifest that seeds the receptionist's knowledge base,
 * the quote catalog, service types, staff skills, urgency signals, and
 * extra AI instructions for a specific trade. Install a pack and the AI
 * immediately knows how to talk to a plumber's customer.
 *
 * WordPress.org compliant: packs are bundled JSON files. No remote
 * fetching, no executable code, no third-party downloads. Site owners
 * can register additional packs (local paths only) via a filter.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once dirname( __FILE__ ).'/base.addon.php';

if ( ! class_exists( 'TZFD_Vertical_Packs' ) ) {

    class TZFD_Vertical_Packs extends TZFD_BaseAddon
    {
        public function get_addon_settings()
        {
            return TZFD_Addon_Support::info_block( __( 'Install and manage industry presets on the Vertical Packs page.', 'titan-zero-trades-front-desk' ), array( __( 'Open Vertical Packs', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tzfd-packs' ) ) );
        }

        
        protected $addonID = "tzfd-VerticalPacks";
        protected $name = "Vertical Packs (industry presets)";
        protected $description;
        public $category = ' Vertical packs (included in this plugin version)';
        public $help = '';

        const STATE_OPTION = 'tzfd_installed_packs';

        function __construct() {
            $this->description = $this->tr_apply(
                "Industry presets for 65 trades and services. Install one to set your service types, reference answers and example prices. Edit every answer and price before you install it.",
                'titan-zero-trades-front-desk'
            );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'admin_menu', array( &$this, 'add_menu' ), 116 );
            add_action( 'admin_init', array( &$this, 'handle_actions' ) );
            add_action( 'admin_init', array( &$this, 'save_vars' ) );
            add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_packs_assets' ) );
        }

        /** Loads the confirm-before-remove script on the packs page. Replaces the inline onclick (R2). */
        public function enqueue_packs_assets( $hook_suffix ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check, no change is made.
            $tzfd_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            if ( 'tzfd-packs' !== $tzfd_page ) return;

            wp_enqueue_script(
                'tzfd-core-addons-packs',
                plugins_url( 'js/inline/core-addons-packs.js', dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk.php' ),
                array(),
                '2.0.1',
                true
            );
        }

        /* ============================================================
         *  Pack discovery
         * ============================================================ */

        /** @return array{id:string, ...}[] */
        public static function available() {
            $packs = array();

            // 1. Bundled packs in /packs/
            $dir = dirname( __DIR__ ) . '/packs';
            if ( is_dir( $dir ) ) {
                foreach ( glob( $dir . '/*.json' ) as $tzfd_file_path ) {
                    $tzfd_data = self::read_pack( $tzfd_file_path );
                    if ( $tzfd_data ) $packs[ $tzfd_data['id'] ] = $tzfd_data;
                }
            }

            // 2. Filter-registered packs (paths to JSON files)
            $extra = apply_filters( 'tzfd_vertical_packs_register', array() );
            foreach ( (array) $extra as $tzfd_entry ) {
                if ( is_string( $tzfd_entry ) && file_exists( $tzfd_entry ) ) {
                    $tzfd_data = self::read_pack( $tzfd_entry );
                    if ( $tzfd_data ) $packs[ $tzfd_data['id'] ] = $tzfd_data;
                } elseif ( is_array( $tzfd_entry ) && isset( $tzfd_entry['id'] ) ) {
                    $packs[ $tzfd_entry['id'] ] = $tzfd_entry;
                }
            }

            // 3. User-uploaded packs (stored in options for admin convenience)
            $uploaded = get_option( 'tzfd_user_packs', array() );
            foreach ( (array) $uploaded as $tzfd_data ) {
                if ( isset( $tzfd_data['id'] ) ) $packs[ $tzfd_data['id'] ] = $tzfd_data;
            }

            return $packs;
        }

        private static function read_pack( $tzfd_file_path ) {
            // The file is only read and parsed as JSON data. Nothing in it is executed.
            if ( ! is_readable( $tzfd_file_path ) ) return null;
            $raw = file_get_contents( $tzfd_file_path );
            if ( ! $raw ) return null;
            $tzfd_data = json_decode( $raw, true );
            if ( ! is_array($tzfd_data) || empty($tzfd_data['id']) ) return null;
            // Sanitize every field we will use
            $tzfd_data['id']   = sanitize_key( $tzfd_data['id'] );
            $tzfd_data['name'] = sanitize_text_field( $tzfd_data['name'] ?? $tzfd_data['id'] );
            $tzfd_data['icon'] = sanitize_text_field( $tzfd_data['icon'] ?? '📦' );
            $tzfd_data['service_types'] = (array) map_deep( $tzfd_data['service_types'] ?? array(), 'sanitize_text_field' );
            $tzfd_data['staff_skills']  = (array) map_deep( $tzfd_data['staff_skills'] ?? array(), 'sanitize_text_field' );
            return $tzfd_data;
        }

        public static function installed() {
            $installed = get_option( self::STATE_OPTION, array() );
            return is_array( $installed ) ? $installed : array();
        }

        public static function is_installed( $id ) {
            return array_key_exists( $id, self::installed() );
        }

        /* ============================================================
         *  Site details used in pack answers
         * ============================================================ */

        const VARS_OPTION = 'tzfd_pack_vars';

        /** Placeholders used in pack answers. The site enters its own values on this page. */
        public static function default_vars() {
            return array(
                'price_note'          => array( 'label' => 'Pricing sentence', 'default' => 'We confirm the exact price with you before any work starts.' ),
                'cancellation_notice' => array( 'label' => 'Cancellation notice period', 'default' => "24 hours' notice" ),
                'callout_note'        => array( 'label' => 'Callout fee policy', 'default' => 'There is a callout fee that covers travel and the first part of the diagnosis. It is credited against the repair if you go ahead.' ),
                'deposit_note'        => array( 'label' => 'Deposit policy', 'default' => 'First-time bookings may require a deposit. Ask us when you book.' ),
                'guarantee_note'      => array( 'label' => 'Guarantee policy', 'default' => 'Tell us what is not right and we will return to fix it.' ),
                'warranty_note'       => array( 'label' => 'Workmanship warranty', 'default' => 'All our work carries a workmanship warranty. Ask us for the details when you book.' ),
                'emergency_note'      => array( 'label' => 'Emergency service', 'default' => 'We take emergency calls outside business hours.' ),
                'discount_note'       => array( 'label' => 'Discounts and packages', 'default' => 'Ask us about discounts and package pricing when you book.' ),
                'consult_note'        => array( 'label' => 'First consultation', 'default' => 'Ask us about a first consultation when you book.' ),
                'trial_note'          => array( 'label' => 'Trial sessions', 'default' => 'Ask us about trial sessions when you book.' ),
                'delivery_time'       => array( 'label' => 'Standard delivery time', 'default' => 'confirmed with you when you book' ),
                'raw_note'            => array( 'label' => 'Raw files', 'default' => 'Raw files are not included as standard. Ask us about raw delivery when you book.' ),
                'minimum_note'        => array( 'label' => 'Minimum project fee', 'default' => 'Ask us about our minimum project fee when you enquire.' ),
                'payment_note'        => array( 'label' => 'Payment terms', 'default' => 'Ask us about payment terms and deposits when you book.' ),
                'afterhours_note'     => array( 'label' => 'After-hours availability', 'default' => 'Ask us about after-hours availability when you get in touch.' ),
                'appraisal_note'      => array( 'label' => 'Appraisal and listing', 'default' => 'Ask us about appraisal and listing options when you call.' ),
                'management_note'     => array( 'label' => 'Property management', 'default' => 'Ask us about property management options when you call.' ),
                'rate_review_note'    => array( 'label' => 'Rate review', 'default' => 'Ask us about a rate review when you get in touch.' ),
                'audit_note' => array( 'label' => 'Audit or review', 'default' => 'Ask us about audit and review options when you enquire.' ),
                'contract_note' => array( 'label' => 'Contract terms', 'default' => 'Ask us about contract terms and notice periods when you enquire.' ),
                'partner_note' => array( 'label' => 'Partner programs', 'default' => 'Ask us about partner programs when you enquire.' ),
                'certification_note' => array( 'label' => 'Certification', 'default' => 'Ask us about our qualifications and certifications when you enquire.' ),
                'discovery_note' => array( 'label' => 'Discovery session', 'default' => 'Ask us about a discovery session when you enquire.' ),
                'waitlist_note' => array( 'label' => 'Waitlist', 'default' => 'Ask us about waitlist options when you enquire.' ),
                'bulk_bill_note' => array( 'label' => 'Billing and rebates', 'default' => 'Ask us about billing and rebate options when you book.' ),
                'health_plan_note' => array( 'label' => 'Health plans', 'default' => 'Ask us about health plan options when you book.' ),
                'touchup_window' => array( 'label' => 'Touch-up window', 'default' => 'Ask us about the touch-up window when you book.' ),
            );
        }

        /** The values in effect for this site: the saved value, or the default when blank. */
        public static function vars() {
            $saved = get_option( self::VARS_OPTION, array() );
            $saved = is_array( $saved ) ? $saved : array();
            $out = array();
            foreach ( self::default_vars() as $key => $spec ) {
                $val = isset( $saved[ $key ] ) ? trim( (string) $saved[ $key ] ) : '';
                $out[ $key ] = ( '' !== $val ) ? $val : $spec['default'];
            }
            return $out;
        }

        /** Replaces {placeholders} in a pack's text. Unknown placeholders are left as they are. */
        public static function fill( $text, array $vars )
        {
            return preg_replace_callback( '/\{([a-z_]+)\}/', function ( $m ) use ( $vars ) {
                return isset( $vars[ $m[1] ] ) ? $vars[ $m[1] ] : $m[0];
            }, (string) $text );
        }

        /** The pack as it will be installed, with the site's details filled in. */
        public static function apply_vars( array $pack )
        {
            $vars = self::vars();
            if ( ! empty( $pack['extra_prompt'] ) ) $pack['extra_prompt'] = self::fill( $pack['extra_prompt'], $vars );
            foreach ( (array) ( $pack['kb'] ?? array() ) as $i => $qa ) {
                $pack['kb'][ $i ]['q'] = self::fill( $qa['q'] ?? '', $vars );
                $pack['kb'][ $i ]['a'] = self::fill( $qa['a'] ?? '', $vars );
            }
            return $pack;
        }

        /* ---------- Site edits to a pack's answers and prices ---------- */

        const EDITS_OPTION = 'tzfd_pack_edits';

        /** Saved edits for one pack, or for all packs when no id is given. */
        public static function edits( $id = null ) {
            $all = get_option( self::EDITS_OPTION, array() );
            $all = is_array( $all ) ? $all : array();
            if ( null === $id ) return $all;
            return ( isset( $all[ $id ] ) && is_array( $all[ $id ] ) ) ? $all[ $id ] : array();
        }

        /** Stores one pack's edits, or removes them when none are left. */
        public static function save_edits( $id, array $edits ) {
            $all = self::edits();
            if ( empty( $edits['kb'] ) && empty( $edits['catalog'] ) ) {
                unset( $all[ $id ] );
            } else {
                $all[ $id ] = $edits;
            }
            update_option( self::EDITS_OPTION, $all, false );
        }

        /**
         * Keeps only what the site changed. $raw has the form values keyed by the pack's own
         * positions: kb[i] = array(q, a), catalog[i] = array(label, unit_price).
         * Text that still matches the filled-in default is not stored, so placeholders such as
         * {price_note} keep following the site details. Prices must be numbers of zero or more.
         */
        public static function clean_edits( array $raw, array $pack ) {
            $out    = array( 'kb' => array(), 'catalog' => array() );
            $filled = self::apply_vars( $pack );

            foreach ( (array) ( $pack['kb'] ?? array() ) as $i => $qa ) {
                $row = $raw['kb'][ $i ] ?? null;
                if ( ! is_array( $row ) ) continue;
                $change = array();
                $q = trim( sanitize_text_field( (string) ( $row['q'] ?? '' ) ) );
                $a = trim( sanitize_textarea_field( (string) ( $row['a'] ?? '' ) ) ); // Answers are plain text from a textarea.
                if ( '' !== $q && $q !== ( $filled['kb'][ $i ]['q'] ?? '' ) ) $change['q'] = $q;
                if ( '' !== $a && $a !== ( $filled['kb'][ $i ]['a'] ?? '' ) ) $change['a'] = $a;
                if ( $change ) $out['kb'][ $i ] = $change;
            }

            foreach ( (array) ( $pack['catalog'] ?? array() ) as $i => $item ) {
                $sku = (string) ( $item['sku'] ?? '' );
                $row = $raw['catalog'][ $i ] ?? null;
                if ( '' === $sku || ! is_array( $row ) ) continue;
                $change = array();
                $label = trim( sanitize_text_field( (string) ( $row['label'] ?? '' ) ) );
                if ( '' !== $label && $label !== (string) ( $item['label'] ?? '' ) ) $change['label'] = $label;
                $price = str_replace( array( '$', ',', ' ' ), '', (string) ( $row['unit_price'] ?? '' ) );
                if ( '' !== $price && is_numeric( $price ) && (float) $price >= 0 && (float) $price !== (float) ( $item['unit_price'] ?? 0 ) ) {
                    $change['unit_price'] = round( (float) $price, 2 );
                }
                if ( $change ) $out['catalog'][ $sku ] = $change;
            }
            return $out;
        }

        /** The pack with the site's answer and price edits applied. */
        public static function apply_edits( array $pack, array $edits ) {
            foreach ( (array) ( $edits['kb'] ?? array() ) as $i => $change ) {
                if ( ! isset( $pack['kb'][ $i ] ) ) continue;
                foreach ( array( 'q', 'a' ) as $f ) {
                    if ( isset( $change[ $f ] ) ) $pack['kb'][ $i ][ $f ] = $change[ $f ];
                }
            }
            foreach ( (array) ( $pack['catalog'] ?? array() ) as $i => $item ) {
                $sku = (string) ( $item['sku'] ?? '' );
                if ( '' === $sku || empty( $edits['catalog'][ $sku ] ) ) continue;
                foreach ( array( 'label', 'unit_price' ) as $f ) {
                    if ( isset( $edits['catalog'][ $sku ][ $f ] ) ) $pack['catalog'][ $i ][ $f ] = $edits['catalog'][ $sku ][ $f ];
                }
            }
            return $pack;
        }

        /** Saves the site details from the Vertical Packs page. */
        public function save_vars()
        {
            if ( ! isset( $_POST['tzfd_pack_vars_save'] ) || ! current_user_can( 'manage_options' ) ) return;
            $nonce = isset( $_POST['tzfd_pack_vars_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tzfd_pack_vars_nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'tzfd_pack_vars' ) ) return;

            $in    = isset( $_POST['pack_vars'] ) && is_array( $_POST['pack_vars'] ) ? map_deep( wp_unslash( $_POST['pack_vars'] ), 'sanitize_text_field' ) : array();
            $clean = array();
            foreach ( self::default_vars() as $key => $spec ) {
                $clean[ $key ] = isset( $in[ $key ] ) ? sanitize_text_field( (string) $in[ $key ] ) : '';
            }
            update_option( self::VARS_OPTION, $clean, false );
        }

        /* ============================================================
         *  Install / uninstall
         * ============================================================ */

        public static function install_pack( $id ) {
            $packs = self::available();
            if ( ! isset( $packs[$id] ) ) return new WP_Error( 'not_found', 'Pack not found' );
            $pack = self::apply_edits( self::apply_vars( $packs[$id] ), self::edits( $id ) );

            global $wpdb;
            $rec = self::current_receptionist();

            // 1. Service types + business profile
            $biz = get_option('tzfd_business_profile', array());
            if ( ! empty($pack['service_types']) ) {
                $existing = ! empty($biz['services']) ? array_map('trim', explode(',', $biz['services'] )) : array();
                $merged   = array_values( array_unique( array_merge( $existing, $pack['service_types'] ) ) );
                $biz['services'] = implode(', ', $merged);
            }
            update_option( 'tzfd_business_profile', $biz );
            update_option( 'tzfd_ai_services', $biz['services'] ?? '' );

            // 2. Extra prompt (appended to receptionist / quote system prompts)
            if ( ! empty($pack['extra_prompt']) ) {
                $cur = (string) get_option('tzfd_ai_system_prompt','');
                if ( strpos( $cur, '<!-- pack:'.$id.' -->' ) === false ) {
                    $cur = trim( $cur . "\n\n<!-- pack:{$id} -->\n" . $pack['extra_prompt'] );
                    update_option( 'tzfd_ai_system_prompt', $cur );
                }
            }

            // 3. Knowledge base entries
            $kb_created = 0;
            if ( $rec && ! empty($pack['kb']) ) {
                foreach ( $pack['kb'] as $qa ) {
                    $exists = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM %i
                         WHERE receptionist_id=%d AND question=%s",
                        $wpdb->prefix.TZFD_Receptionist_Schema::T_KB, intval($rec->id), $qa['q'] ?? '' ) );
                    if ( $exists ) continue;
                    $wpdb->insert( $wpdb->prefix.TZFD_Receptionist_Schema::T_KB, array(
                        'receptionist_id' => intval($rec->id),
                        'question'        => sanitize_text_field( $qa['q'] ?? '' ),
                        'answer'          => wp_kses_post( $qa['a'] ?? '' ),
                        'keywords'        => sanitize_text_field( $qa['keywords'] ?? '' ),
                        'priority'        => intval( $qa['priority'] ?? 10 ),
                        'active'          => 1,
                    ) );
                    $kb_created++;
                }
            }

            // 3b. Without the Receptionist add-on, the pack's reference answers are kept in their own option for the AI assistant.
            if ( ! $rec && ! empty($pack['kb']) ) {
                $store = get_option( 'tzfd_pack_kb', array() );
                if ( ! is_array($store) ) $store = array();
                $store[$id] = array();
                foreach ( $pack['kb'] as $qa ) {
                    $store[$id][] = array(
                        'q'        => sanitize_text_field( $qa['q'] ?? '' ),
                        'a'        => wp_kses_post( $qa['a'] ?? '' ),
                        'keywords' => sanitize_text_field( $qa['keywords'] ?? '' ),
                    );
                }
                update_option( 'tzfd_pack_kb', $store );
            }

            // 4. Catalog items
            $cat_created = 0;
            // The catalogue belongs to the Quotes add-on. Without it, the catalogue step is skipped.
            if ( ! empty($pack['catalog']) && class_exists( 'TZFD_Quotes_Engine' ) ) {
                // The catalogue table is normally created when the Quotes add-on loads. Make sure it exists before inserting.
                TZFD_Quotes_Engine::maybe_install();
                foreach ( $pack['catalog'] as $tzfd_item ) {
                    $sku = sanitize_text_field( $tzfd_item['sku'] ?? '' );
                    if ( ! $sku ) continue;
                    $exists = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM %i WHERE sku=%s",
                        $wpdb->prefix.TZFD_Quotes_Engine::T_CATALOG, $sku ) );
                    if ( $exists ) continue;
                    $tzfd_inserted = $wpdb->insert( $wpdb->prefix.TZFD_Quotes_Engine::T_CATALOG, array(
                        'sku'          => $sku,
                        'label'        => sanitize_text_field( $tzfd_item['label'] ?? '' ),
                        'description'  => wp_kses_post( $tzfd_item['description'] ?? '' ),
                        'kind'         => in_array( $tzfd_item['kind'] ?? 'labor', array('labor','material','fee','travel'), true ) ? $tzfd_item['kind'] : 'labor',
                        'unit'         => sanitize_text_field( $tzfd_item['unit'] ?? 'each' ),
                        'unit_price'   => floatval( $tzfd_item['unit_price'] ?? 0 ),
                        'taxable'      => ! empty( $tzfd_item['taxable'] ) ? 1 : 0,
                        'keywords'     => sanitize_text_field( $tzfd_item['keywords'] ?? '' ),
                        'service_type' => sanitize_text_field( $tzfd_item['service_type'] ?? ( $pack['service_types'][0] ?? '' ) ),
                        'active'       => 1,
                    ) );
                    if ( false !== $tzfd_inserted ) {
                        $cat_created++;
                    }
                }
            }

            // 5. Staff skills (append to existing staff as new "skill" tags)
            if ( $rec && ! empty($pack['staff_skills']) ) {
                $staff = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, skills FROM %i
                     WHERE receptionist_id=%d", $wpdb->prefix.TZFD_Receptionist_Schema::T_STAFF, intval($rec->id) ) );
                foreach ( $staff as $s ) {
                    $existing = array_filter( array_map('trim', explode(',', (string)$s->skills) ) );
                    $merged   = array_values( array_unique( array_merge( $existing, $pack['staff_skills'] ) ) );
                    $wpdb->update( $wpdb->prefix.TZFD_Receptionist_Schema::T_STAFF,
                        array( 'skills' => implode(',', $merged) ),
                        array( 'id' => $s->id ) );
                }
            }

            // 6. Store which pack is installed
            $installed = self::installed();
            $installed[ $id ] = array(
                'id'         => $id,
                'name'       => $pack['name'],
                'installed'  => current_time('mysql'),
                'kb'         => $kb_created,
                'catalog'    => $cat_created,
            );
            update_option( self::STATE_OPTION, $installed );

            do_action( 'tzfd_vertical_pack_installed', $id, $pack );

            return array(
                'ok'      => true,
                'kb'      => $kb_created,
                'catalog' => $cat_created,
                'pack'    => $pack,
            );
        }

        public static function uninstall_pack( $id ) {
            global $wpdb;
            $installed = self::installed();
            if ( ! isset( $installed[$id] ) ) return;

            $packs = self::available();
            $pack  = isset( $packs[$id] ) ? self::apply_edits( $packs[$id], self::edits( $id ) ) : null;

            if ( $pack ) {
                // Remove KB entries added by this pack (by exact question match)
                $rec = self::current_receptionist();
                if ( $rec && ! empty($pack['kb']) ) {
                    foreach ( $pack['kb'] as $qa ) {
                        $wpdb->delete( $wpdb->prefix.TZFD_Receptionist_Schema::T_KB,
                            array( 'receptionist_id'=>$rec->id, 'question'=>sanitize_text_field($qa['q'] ?? '') ),
                            array( '%d', '%s' ) );
                    }
                }
                // Remove catalog items by SKU
                if ( ! empty($pack['catalog']) && class_exists( 'TZFD_Quotes_Engine' ) ) {
                    foreach ( $pack['catalog'] as $tzfd_item ) {
                        if ( ! empty($tzfd_item['sku']) ) {
                            $wpdb->delete( $wpdb->prefix.TZFD_Quotes_Engine::T_CATALOG,
                                array( 'sku'=>sanitize_text_field($tzfd_item['sku']) ), array('%s') );
                        }
                    }
                }
                // Remove the pack-specific prompt block
                $cur = (string) get_option('tzfd_ai_system_prompt','');
                $cur = preg_replace( '/\n*<!-- pack:'.preg_quote($id,'/').' -->\n.*?(?=<!-- pack:|$)/s', '', $cur );
                update_option( 'tzfd_ai_system_prompt', $cur );
            }

            $kb_store = get_option( 'tzfd_pack_kb', array() );
            if ( is_array($kb_store) && isset($kb_store[$id]) ) { unset( $kb_store[$id] ); update_option( 'tzfd_pack_kb', $kb_store ); }

            unset( $installed[$id] );
            update_option( self::STATE_OPTION, $installed );

            do_action( 'tzfd_vertical_pack_uninstalled', $id, $pack );
        }

        /**
         * Reference answers for the AI assistant: the installed packs' Q&A that best match the customer's message.
         * Used when the Receptionist add-on is not active; with it, the answers live in the Receptionist's table.
         */
        public static function knowledge_for_prompt( $text, $max = 6, $max_chars = 1800 ) {
            $active = get_option( 'tzfd_addons_active_list', array() );
            if ( ! is_array($active) || ! in_array( 'tzfd-VerticalPacks', $active, true ) ) return '';
            $store = get_option( 'tzfd_pack_kb', array() );
            if ( ! is_array($store) || empty($store) ) return '';

            $lower = ' ' . strtolower( (string) $text ) . ' ';
            $words = array_unique( array_filter( preg_split( '/[^a-z0-9]+/', $lower ), function ( $w ) { return strlen($w) > 2; } ) );
            if ( empty($words) ) return '';

            $scored = array();
            foreach ( $store as $entries ) {
                foreach ( (array) $entries as $qa ) {
                    $score = 0;
                    foreach ( array_filter( array_map( 'trim', explode( ',', strtolower( (string) ($qa['keywords'] ?? '') ) ) ) ) as $kw ) {
                        if ( strpos( $lower, ' ' . $kw ) !== false ) $score += 2;
                    }
                    foreach ( array_filter( preg_split( '/[^a-z0-9]+/', strtolower( (string) ($qa['q'] ?? '') ) ), function ( $w ) { return strlen($w) > 3; } ) as $w ) {
                        if ( in_array( $w, $words, true ) ) $score += 1;
                    }
                    if ( $score > 0 ) $scored[] = array( $score, $qa );
                }
            }
            usort( $scored, function ( $a, $b ) { return $b[0] <=> $a[0]; } );

            $out = ''; $n = 0;
            foreach ( $scored as $tzfd_item ) {
                $block = 'Q: ' . $tzfd_item[1]['q'] . "\nA: " . trim( wp_strip_all_tags( (string) ($tzfd_item[1]['a'] ?? '') ) ) . "\n";
                if ( $n >= $max || strlen( $out . $block ) > $max_chars ) break;
                $out .= $block; $n++;
            }
            return trim($out);
        }

        /* ============================================================
         *  Integration with receptionist + quotes
         * ============================================================ */

        /** Merge all installed packs' urgency signals into one combined map. */
        public static function merged_urgency_signals() {
            $packs = self::available();
            $signals = array( 'emergency'=>array(), 'urgent'=>array(), 'routine'=>array() );
            foreach ( self::installed() as $id => $meta ) {
                if ( empty($packs[$id]['urgency_signals']) ) continue;
                foreach ( $signals as $level => $list ) {
                    if ( ! empty($packs[$id]['urgency_signals'][$level]) ) {
                        $signals[$level] = array_values( array_unique( array_merge(
                            $signals[$level],
                            (array)$packs[$id]['urgency_signals'][$level]
                        ) ) );
                    }
                }
            }
            return $signals;
        }

        private static function current_receptionist() {
            global $wpdb;
            // The receptionist tables belong to the AI Receptionist add-on. Without it, the FAQ and staff steps are skipped.
            if ( ! class_exists( 'TZFD_Receptionist_Schema' ) ) return null;
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM %i ORDER BY id ASC LIMIT 1", $wpdb->prefix.TZFD_Receptionist_Schema::T_RECEPTIONISTS ) );
        }

        /* ============================================================
         *  Admin UI
         * ============================================================ */

        public function add_menu() {
            add_submenu_page(
                '',
                __( 'Vertical Packs', 'titan-zero-trades-front-desk' ),
                __( 'Vertical Packs', 'titan-zero-trades-front-desk' ),
                'manage_options',
                'tzfd-packs',
                array( &$this, 'render' )
            );
        }

        public function handle_actions() {
            if ( ! current_user_can('manage_options') ) return;

            if ( isset($_GET['pack_install']) && wp_verify_nonce(sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'pack_install_'.sanitize_text_field( wp_unslash( $_GET['pack_install'] ?? '' ) )) ) {
                $res = self::install_pack( sanitize_key(wp_unslash( $_GET['pack_install'] ?? '' )) );
                if ( is_wp_error( $res ) ) {
                    wp_safe_redirect( add_query_arg('pack_error', rawurlencode($res->get_error_message()), admin_url('admin.php?page=tzfd-packs') ) );
                } else {
                    wp_safe_redirect( add_query_arg( array(
                        'pack_installed' => rawurlencode($res['pack']['name']),
                        'kb'             => intval( $res['kb'] ),
                        'catalog'        => intval( $res['catalog'] ),
                    ), admin_url('admin.php?page=tzfd-packs') ) );
                }
                exit;
            }
            if ( isset($_GET['pack_uninstall']) && wp_verify_nonce(sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'pack_uninstall_'.sanitize_text_field( wp_unslash( $_GET['pack_uninstall'] ?? '' ) )) ) {
                self::uninstall_pack( sanitize_key(wp_unslash( $_GET['pack_uninstall'] ?? '' )) );
                wp_safe_redirect( admin_url('admin.php?page=tzfd-packs') );
                exit;
            }
            if ( isset($_POST['tzfd_pack_upload_nonce']) && wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['tzfd_pack_upload_nonce'] ?? '' ) ), 'tzfd_pack_upload') ) {
                $this->handle_upload();
            }
        }

        public function render() {
            if ( ! current_user_can('manage_options') ) return;
            TZFD_Admin_Menu::render_settings_tabs( 'packs' );
            $packs     = self::available();
            $installed = self::installed();

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if ( isset($_GET['pack_installed']) ):
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(
                        /* translators: 1: industry pack name, 2: number of knowledge base entries added, 3: number of catalogue items added. */
                        esc_html__('Installed "%1$s". Added %2$d knowledge base entries and %3$d catalog items.', 'titan-zero-trades-front-desk'),
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
                        esc_html( sanitize_text_field( wp_unslash( $_GET['pack_installed'] ?? '' ) ) ),
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
                        intval( sanitize_text_field( wp_unslash( $_GET['kb'] ?? 0 ) ) ),
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
                        intval( sanitize_text_field( wp_unslash( $_GET['catalog'] ?? 0 ) ) )
                    ); ?></p>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display value.
                    $tzfd_installed_pack = sanitize_text_field( wp_unslash( $_GET['pack_installed'] ?? '' ) );
                    echo wp_kses( TZFD_Brand::slim_trade_banner( $packs, $tzfd_installed_pack ), TZFD_Brand::allowed_html() );
                    ?>
                </div>
            <?php endif; ?>
            <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state. */ ?>
            <?php if ( isset($_GET['pack_error']) ): ?>
                <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state. */ ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['pack_error'] ?? '' ) ) ); ?></p></div>
            <?php endif; ?>

            <div class="wrap">
                <h1>📦 <?php esc_html_e('Vertical Packs','titan-zero-trades-front-desk'); ?></h1>
                <p style="max-width:780px;font-size:14px;line-height:1.6;">
                    <?php esc_html_e('Install a pack and your AI receptionist instantly knows the trade: common FAQs, price catalog, service types, urgency language, and how to talk to that trade\'s customer. Install as many as you want — the AI handles multi-trade businesses.', 'titan-zero-trades-front-desk'); ?>
                </p>

                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:16px;margin-top:20px;">
                    <?php foreach ( $packs as $id => $pack ):
                        $is_in = isset( $installed[$id] );
                        $install_url = wp_nonce_url( add_query_arg('pack_install', $id), 'pack_install_'.$id );
                        $uninstall_url = wp_nonce_url( add_query_arg('pack_uninstall', $id), 'pack_uninstall_'.$id );
                        ?>
                        <div style="border:1px solid #333a48;border-radius:12px;background:#232834;overflow:hidden;display:flex;flex-direction:column;">
                            <?php echo wp_kses( TZFD_Brand::trade_banner( $id ), TZFD_Brand::allowed_html() ); ?>
                        <div style="background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:20px;">
                                <div style="font-size:36px;line-height:1;"><?php echo esc_html( $pack['icon'] ?? '📦' ); ?></div>
                                <h3 style="margin:10px 0 4px;color:#fff;"><?php echo esc_html( $pack['name'] ); ?></h3>
                                <p style="margin:0;font-size:12px;color:#a4acba;"><?php echo esc_html( $pack['tagline'] ?? $pack['description'] ?? '' ); ?></p>
                            </div>
                            <div style="padding:16px;flex:1;display:flex;flex-direction:column;">
                                <ul style="list-style:none;padding:0;margin:0 0 12px;font-size:13px;color:#a4acba;line-height:1.7;">
                                    <li>📚 <?php echo count( $pack['kb'] ?? array() ); ?> <?php esc_html_e('FAQ answers','titan-zero-trades-front-desk'); ?></li>
                                    <li>💰 <?php echo count( $pack['catalog'] ?? array() ); ?> <?php esc_html_e('catalog items','titan-zero-trades-front-desk'); ?></li>
                                    <li>🔧 <?php echo count( $pack['service_types'] ?? array() ); ?> <?php esc_html_e('service types','titan-zero-trades-front-desk'); ?></li>
                                    <?php if ( ! empty($pack['urgency_signals']['emergency']) ): ?>
                                        <li>🚨 <?php esc_html_e('Emergency language detection','titan-zero-trades-front-desk'); ?></li>
                                    <?php endif; ?>
                                </ul>
                                <?php if ( ! empty($pack['service_types']) ): ?>
                                    <div style="font-size:11px;color:#a4acba;margin-bottom:12px;">
                                        <?php echo esc_html( implode(' · ', array_slice( $pack['service_types'], 0, 4 ) ) ); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top:auto;">
                                    <?php if ( $is_in ): ?>
                                        <a class="button" href="<?php echo esc_url( $uninstall_url ); ?>" data-tzfd-confirm="<?php echo esc_attr__('Remove this pack? FAQ and catalog items it added will be deleted.','titan-zero-trades-front-desk'); ?>">
                                            ✓ <?php esc_html_e('Installed — Remove','titan-zero-trades-front-desk'); ?>
                                        </a>
                                    <?php else: ?>
                                        <a class="button button-primary" href="<?php echo esc_url( $install_url ); ?>">
                                            <?php esc_html_e('Install pack →','titan-zero-trades-front-desk'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr style="margin:40px 0 20px;" />

                <h2><?php esc_html_e('Your details for pack answers','titan-zero-trades-front-desk'); ?></h2>
                <p style="max-width:780px;">
                    <?php esc_html_e('Pack answers use these details. Enter your own prices, notice periods, and policies. Packs installed before you save keep the details they were installed with, so reinstall a pack to apply new details. Check every answer before your customers see it.', 'titan-zero-trades-front-desk'); ?>
                </p>
                <form method="post">
                    <?php wp_nonce_field('tzfd_pack_vars','tzfd_pack_vars_nonce'); ?>
                    <table class="form-table">
                        <?php $current_vars = self::vars(); foreach ( self::default_vars() as $key => $spec ): ?>
                            <tr>
                                <th scope="row"><label for="pv_<?php echo esc_attr($key); ?>"><?php echo esc_html($spec['label']); ?></label></th>
                                <td><input type="text" class="large-text" id="pv_<?php echo esc_attr($key); ?>" name="pack_vars[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr( $current_vars[ $key ] ); ?>" /></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <p><button class="button button-primary" name="tzfd_pack_vars_save" value="1"><?php esc_html_e('Save details','titan-zero-trades-front-desk'); ?></button></p>
                </form>

                <h2><?php esc_html_e('Upload a custom pack','titan-zero-trades-front-desk'); ?></h2>
                <p><?php esc_html_e('Have a pack from a third party? Upload the JSON file here. It is stored in your WordPress options table — no files are written to disk.', 'titan-zero-trades-front-desk'); ?></p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('tzfd_pack_upload','tzfd_pack_upload_nonce'); ?>
                    <p>
                        <input type="file" name="pack_file" accept="application/json,.json" required />
                        <button class="button"><?php esc_html_e('Upload pack','titan-zero-trades-front-desk'); ?></button>
                    </p>
                    <p style="color:#92400e;font-size:12px;">
                        <?php esc_html_e('Only upload packs from sources you trust. A pack can seed FAQ answers, catalog items, and AI instructions that will run on your site.', 'titan-zero-trades-front-desk'); ?>
                    </p>
                </form>
            </div>
            <?php
        }

        const MAX_UPLOAD_BYTES = 524288;

        private function handle_upload() {
            if ( ! current_user_can( 'manage_options' ) ) { return; }
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tzfd_pack_upload_nonce'] ?? '' ) ), 'tzfd_pack_upload' ) ) { return; }
            // The temporary path is a server path: it is sanitised here, checked with is_uploaded_file(), and never output.
            $tmp_path = isset( $_FILES['pack_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['pack_file']['tmp_name'] ) ) : '';
            $file_size = isset( $_FILES['pack_file']['size'] ) ? absint( $_FILES['pack_file']['size'] ) : 0;
            if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) return;
            // Only a .json file is accepted. The file is parsed as data and never stored or served.
            $tmp_name = isset( $_FILES['pack_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['pack_file']['name'] ) ) : '';
            if ( 'json' !== strtolower( pathinfo( $tmp_name, PATHINFO_EXTENSION ) ) ) {
                wp_safe_redirect( add_query_arg('pack_error', rawurlencode(__('Invalid pack file.','titan-zero-trades-front-desk')), admin_url('admin.php?page=tzfd-packs') ) );
                exit;
            }
            if ( $file_size > self::MAX_UPLOAD_BYTES ) {
                wp_safe_redirect( add_query_arg('pack_error', rawurlencode(__('The pack file is too large (512 KB at most).','titan-zero-trades-front-desk')), admin_url('admin.php?page=tzfd-packs') ) );
                exit;
            }
            $raw      = is_readable( $tmp_path ) ? file_get_contents( $tmp_path ) : false;
            if ( ! $raw || strlen( $raw ) > self::MAX_UPLOAD_BYTES ) return;
            $tzfd_data = json_decode( $raw, true );
            if ( ! is_array($tzfd_data) || empty($tzfd_data['id']) || empty($tzfd_data['name']) || ( isset($tzfd_data['kb']) && ! is_array($tzfd_data['kb']) ) || ( isset($tzfd_data['catalog']) && ! is_array($tzfd_data['catalog']) ) ) {
                wp_safe_redirect( add_query_arg('pack_error', rawurlencode(__('Invalid pack file.','titan-zero-trades-front-desk')), admin_url('admin.php?page=tzfd-packs') ) );
                exit;
            }
            $tzfd_data = self::sanitize_pack_data( $tzfd_data );
            $tzfd_data['id'] = sanitize_key( $tzfd_data['id'] );
            $user_packs = get_option( 'tzfd_user_packs', array() );
            $user_packs[ $tzfd_data['id'] ] = $tzfd_data;
            update_option( 'tzfd_user_packs', $user_packs );

            wp_safe_redirect( add_query_arg('pack_uploaded', '1', admin_url('admin.php?page=tzfd-packs') ) );
            exit;
        }

        /** Cleans every text value of an uploaded pack. Keys and structure are kept. Other scalar types pass through. */
        private static function sanitize_pack_data( $node ) {
            if ( is_array( $node ) ) {
                $clean = array();
                foreach ( $node as $key => $value ) {
                    $clean[ $key ] = self::sanitize_pack_data( $value );
                }
                return $clean;
            }
            if ( is_string( $node ) ) {
                return sanitize_textarea_field( $node );
            }
            return ( is_int( $node ) || is_float( $node ) || is_bool( $node ) || null === $node ) ? $node : null;
        }
    }

    $TZFD_Vertical_Packs_obj = new TZFD_Vertical_Packs();
    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $TZFD_Vertical_Packs_obj->get_addon_id() ] = $TZFD_Vertical_Packs_obj;
}
