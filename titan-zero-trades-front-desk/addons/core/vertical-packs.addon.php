<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/../../includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/../../includes/class-tzfd-pack-import-guard.php';
require_once __DIR__ . '/../../includes/class-tzfd-admin-write-guard.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/**
 * Vertical Packs — industry presets
 *
 * Workspace owns the canonical vertical pack JSON. Front Desk uses that shared
 * manifest to seed the receptionist's knowledge base,
 * the price catalogue (handed to Titan Zero Quotes through a hook), service types, staff skills, urgency signals, and
 * extra AI instructions for a specific vertical. Install a pack and the AI
 * immediately knows how to talk to a plumber's customer.
 *
 * The 65 built-in packs are read from data/verticals/ through TZFD_Vertical_Registry, which uses Workspace's
 * copy instead when Workspace is present. Site owners
 * can register or upload custom packs; they cannot replace a canonical
 * Workspace pack with the same id. No remote fetching or executable pack code.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once dirname( __FILE__ ).'/base.addon.php';

if ( ! class_exists( 'TZFD_Vertical_Packs' ) ) {

    class TZFD_Vertical_Packs extends TZFD_BaseAddon
    {
        public function get_addon_settings()
        {
            return TZFD_Addon_Support::info_block( __( 'Choose and install the industry packs for your business on the Industry packs page.', 'titan-zero-trades-front-desk' ), array( __( 'Open Industry packs', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tzfd-packs' ) ) );
        }

        
        protected $addonID = "tzfd-VerticalPacks";
        protected $name = "Vertical Packs (industry presets)";
        protected $description;
        public $category = ' Vertical packs (included in this plugin version)';
        public $help = '';

        const STATE_OPTION = 'tz_fd_installed_packs';

        function __construct() {
            $this->description = $this->tr_apply(
                "Front Desk presets for 65 verticals and services. Install one to set your service types and reference answers. Review every answer and price before installing it.",
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
            $tz_page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
            if ( 'tzfd-packs' !== $tz_page ) return;

            wp_enqueue_script(
                'tzfd-core-addons-packs',
                plugins_url( 'js/inline/core-addons-packs.js', dirname( __DIR__, 2 ) . '/titan-zero-front-desk.php' ),
                array(),
                '2.0.1',
                true
            );
        }

        /* ============================================================
         *  Pack discovery
         * ============================================================ */

        /** @return array{id:string, ...}[] */
        /** Pack IDs that have a bundled trade banner (images/brand/marketing/trade-banners). */
        const TRADE_BANNER_IDS = array( 'cleaning', 'electrical', 'handyman', 'hvac', 'landscaping', 'plumbing' );

        /**
         * URL of a pack's full-width trade banner, or an empty string when the pack has none.
         *
         * @param string $pack_id Pack ID.
         * @return string
         */
        public static function trade_banner_url( $pack_id ) {
            if ( ! in_array( (string) $pack_id, self::TRADE_BANNER_IDS, true ) ) {
                return '';
            }
            return plugins_url(
                'images/brand/marketing/trade-banners/' . $pack_id . '-1544x500.png',
                dirname( __DIR__, 2 ) . '/titan-zero-front-desk.php'
            );
        }

        /** URL of a pack's slim (1440 by 220) trade banner, or an empty string when the pack has none. */
        public static function slim_banner_url( $pack_id ) {
            if ( ! in_array( (string) $pack_id, self::TRADE_BANNER_IDS, true ) ) {
                return '';
            }
            return plugins_url(
                'images/brand/marketing/trade-banners/slim/' . $pack_id . '-1440x220.png',
                dirname( __DIR__, 2 ) . '/titan-zero-front-desk.php'
            );
        }

        /** Slim banner URL for the pack with this display name, or an empty string. */
        public static function slim_banner_url_for_name( $name, array $packs ) {
            foreach ( $packs as $pack_id => $pack ) {
                if ( isset( $pack['name'] ) && (string) $pack['name'] === (string) $name ) {
                    return self::slim_banner_url( $pack_id );
                }
            }
            return '';
        }

        public static function available() {
            $packs = array();

            // 1. Optional site or extension packs. These add custom IDs; Workspace wins on canonical ID collisions.
            $extra = apply_filters( 'tz_fd_vertical_packs_register', array() );
            foreach ( (array) $extra as $tz_entry ) {
                if ( is_string( $tz_entry ) && file_exists( $tz_entry ) ) {
                    $tz_data = self::read_pack( $tz_entry );
                    if ( $tz_data ) $packs[ $tz_data['id'] ] = $tz_data;
                } elseif ( is_array( $tz_entry ) && isset( $tz_entry['id'] ) ) {
                    $packs[ $tz_entry['id'] ] = $tz_entry;
                }
            }

            // 2. User-uploaded packs (stored in company-scoped options for admin convenience).
            $uploaded = TZFD_Scoped_Store::get( 'tz_fd_user_packs', array() );
            foreach ( (array) $uploaded as $tz_data ) {
                if ( TZFD_Pack_Import_Guard::validate( $tz_data ) ) $packs[ $tz_data['id'] ] = $tz_data;
            }

            // 3. The built-in vertical list and packs: Workspace's when Workspace is present, otherwise the packs bundled
            // in data/verticals/. Either way they win duplicate IDs.
            foreach ( TZFD_Vertical_Registry::ids() as $tz_registry_id ) {
                $tz_registry_pack = TZFD_Vertical_Registry::pack( $tz_registry_id );
                if ( $tz_registry_pack ) $packs[ $tz_registry_pack['id'] ] = $tz_registry_pack;
            }

            return $packs;
        }

        private static function read_pack( $tz_file_path ) {
            // The file is only read and parsed as JSON data. Nothing in it is executed.
            if ( ! is_string( $tz_file_path ) || ! is_readable( $tz_file_path ) ||
                 ! is_file( $tz_file_path ) || filesize( $tz_file_path ) > TZFD_Pack_Import_Guard::MAX_BYTES ) return null;
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if ( ! WP_Filesystem() || ! isset( $GLOBALS['wp_filesystem'] ) ) return null;
            $raw = $GLOBALS['wp_filesystem']->get_contents( $tz_file_path );
            if ( ! is_string( $raw ) || strlen( $raw ) > TZFD_Pack_Import_Guard::MAX_BYTES ) return null;
            $tz_data = json_decode( $raw, true, 32 );
            if ( ! TZFD_Pack_Import_Guard::validate( $tz_data ) ) return null;
            // Sanitize every field we will use
            $tz_data['id']   = sanitize_key( $tz_data['id'] );
            $tz_data['name'] = sanitize_text_field( $tz_data['name'] ?? $tz_data['id'] );
            $tz_data['icon'] = sanitize_text_field( $tz_data['icon'] ?? '📦' );
            $tz_data['service_types'] = (array) map_deep( $tz_data['service_types'] ?? array(), 'sanitize_text_field' );
            $tz_data['staff_skills']  = (array) map_deep( $tz_data['staff_skills'] ?? array(), 'sanitize_text_field' );
            return $tz_data;
        }

        public static function installed() {
            $installed = TZFD_Scoped_Store::get( self::STATE_OPTION, array() );
            return is_array( $installed ) ? $installed : array();
        }

        public static function is_installed( $id ) {
            return array_key_exists( $id, self::installed() );
        }

        /* ============================================================
         *  Site details used in pack answers
         * ============================================================ */

        const VARS_OPTION = 'tz_fd_pack_vars';

        /** Placeholders used in pack answers. The site enters its own values on this page. */
        public static function default_vars() {
            return array(
                'price_note' => array( 'label' => __( 'How do you explain your prices to customers?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the exact price with you before any work starts.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write one or two sentences about how you quote. Example: We confirm the exact price with you before any work starts.', 'titan-zero-trades-front-desk' ) ),
                'cancellation_notice' => array( 'label' => __( 'How much notice must customers give to cancel?', 'titan-zero-trades-front-desk' ), 'default' => __( "Customers need to give us [number] hours' notice to cancel a booking.", 'titan-zero-trades-front-desk' ), 'help' => __( "Write the notice time in hours. Example: [number] hours' notice before the booked time.", 'titan-zero-trades-front-desk' ) ),
                'callout_note' => array( 'label' => __( 'Do you charge a callout or travel fee?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm any callout or travel fee with you when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then the fee and what it covers. Example: Yes, the callout fee is [amount] and covers [what it covers].', 'titan-zero-trades-front-desk' ) ),
                'deposit_note' => array( 'label' => __( 'Do you take a deposit when someone books?', 'titan-zero-trades-front-desk' ), 'default' => __( 'First-time bookings may require a deposit, and we tell you the amount when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what the deposit covers. Example: Yes, a deposit of [amount] is due when you book.', 'titan-zero-trades-front-desk' ) ),
                'guarantee_note' => array( 'label' => __( 'What happens if a customer is not happy with the work?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We explain how we handle any problem with the work when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write how you handle complaints about the work. Example: Tell us what is not right, and we will [what you will do].', 'titan-zero-trades-front-desk' ) ),
                'warranty_note' => array( 'label' => __( 'Do you give a warranty on your work?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm our warranty terms with you before you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then how long the warranty lasts. Example: Yes, our work is covered for [number] months.', 'titan-zero-trades-front-desk' ) ),
                'emergency_note' => array( 'label' => __( 'Do you take emergency or after-hours calls?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm whether we take emergency calls outside business hours when you get in touch.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then when emergency calls are taken. Example: Yes, emergency calls are taken [days and hours].', 'titan-zero-trades-front-desk' ) ),
                'discount_note' => array( 'label' => __( 'Do you offer discounts or packages?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We may offer discounts and package pricing, and we explain any options when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what discounts or packages you offer. Example: Yes, we offer [discount or package] for [who it is for].', 'titan-zero-trades-front-desk' ) ),
                'consult_note' => array( 'label' => __( 'Do you offer a first consultation?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We may offer a first consultation, and we explain what it includes when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what a first consultation includes. Example: Yes, a first consultation lasts [length] and includes [what it includes].', 'titan-zero-trades-front-desk' ) ),
                'trial_note' => array( 'label' => __( 'Do you offer trial sessions?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We may offer trial sessions, and we explain the options when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what a trial session includes. Example: Yes, a trial session lasts [length] and includes [what it includes].', 'titan-zero-trades-front-desk' ) ),
                'delivery_time' => array( 'label' => __( 'How long does a job usually take to finish?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the usual completion time with you when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write the usual time a job takes. Example: Most jobs take [number] working days.', 'titan-zero-trades-front-desk' ) ),
                'raw_note' => array( 'label' => __( 'Do customers get raw or original files?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm whether raw or original files are included when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then how raw files are delivered. Example: Yes, raw files are delivered [how].', 'titan-zero-trades-front-desk' ) ),
                'minimum_note' => array( 'label' => __( 'Is there a minimum job size or fee?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm any minimum project fee with you when you enquire.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then the minimum fee. Example: Yes, the minimum fee is [amount].', 'titan-zero-trades-front-desk' ) ),
                'payment_note' => array( 'label' => __( 'When and how do customers pay?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm our payment terms and any deposit with you when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write when payment is due and how customers can pay. Example: Payment is due [when], by [payment methods].', 'titan-zero-trades-front-desk' ) ),
                'afterhours_note' => array( 'label' => __( 'When are you available outside normal hours?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm our after-hours availability when you get in touch.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write the days and hours you are available outside normal hours. Example: We are available [days and hours].', 'titan-zero-trades-front-desk' ) ),
                'appraisal_note' => array( 'label' => __( 'Do you offer appraisals and listings?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the appraisal and listing options available when you call.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what an appraisal includes. Example: Yes, an appraisal includes [what it includes].', 'titan-zero-trades-front-desk' ) ),
                'management_note' => array( 'label' => __( 'Do you offer property management?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the property management options available when you call.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then the management services you offer. Example: Yes, we offer [services].', 'titan-zero-trades-front-desk' ) ),
                'rate_review_note' => array( 'label' => __( 'Do you offer rate reviews?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the rate review process with you when you get in touch.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then how often rates are reviewed. Example: Yes, rates are reviewed [how often].', 'titan-zero-trades-front-desk' ) ),
                'audit_note' => array( 'label' => __( 'Do you offer audits or reviews?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the audit and review options with you when you enquire.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what an audit covers. Example: Yes, an audit covers [what it covers].', 'titan-zero-trades-front-desk' ) ),
                'contract_note' => array( 'label' => __( 'What are your contract terms and notice periods?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm our contract terms and notice periods with you when you enquire.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write the contract length and the notice needed to end it. Example: Contracts run for [period], with [notice] to end.', 'titan-zero-trades-front-desk' ) ),
                'partner_note' => array( 'label' => __( 'Do you offer partner programs?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the partner programs we offer when you enquire.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what the program offers. Example: Yes, our partner program offers [what it offers].', 'titan-zero-trades-front-desk' ) ),
                'certification_note' => array( 'label' => __( 'What qualifications and certifications do you hold?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We share our qualifications and certifications when you enquire.', 'titan-zero-trades-front-desk' ), 'help' => __( 'List your qualifications and licences. Example: We hold [qualifications or licences].', 'titan-zero-trades-front-desk' ) ),
                'discovery_note' => array( 'label' => __( 'Do you offer a discovery session?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm whether a discovery session is available when you enquire.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then what the session covers. Example: Yes, a discovery session covers [what it covers].', 'titan-zero-trades-front-desk' ) ),
                'waitlist_note' => array( 'label' => __( 'Do you keep a waitlist for bookings?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm our waitlist options with you when you enquire.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then how the waitlist works. Example: Yes, customers join the waitlist by [how].', 'titan-zero-trades-front-desk' ) ),
                'bulk_bill_note' => array( 'label' => __( 'Do you bill insurers or offer rebates?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm our billing and rebate options with you when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write yes or no, then which billing options you offer. Example: Yes, we bill [insurer or rebate option].', 'titan-zero-trades-front-desk' ) ),
                'health_plan_note' => array( 'label' => __( 'Do you accept health plans?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm which health plans we accept when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'List the health plans you accept. Example: We accept [list of health plans].', 'titan-zero-trades-front-desk' ) ),
                'touchup_window' => array( 'label' => __( 'How long after a job can a customer ask for a touch-up?', 'titan-zero-trades-front-desk' ), 'default' => __( 'We confirm the touch-up window for each job when you book.', 'titan-zero-trades-front-desk' ), 'help' => __( 'Write how long customers have to ask for a touch-up. Example: Customers can ask for a touch-up within [period] of finishing.', 'titan-zero-trades-front-desk' ) ),
            );
        }

        /** The values in effect for this site: the saved value, or the default when blank. */
        public static function vars() {
            $saved = TZFD_Scoped_Store::get( self::VARS_OPTION, array() );
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

        const EDITS_OPTION = 'tz_fd_pack_edits';

        /** Saved edits for one pack, or for all packs when no id is given. */
        public static function edits( $id = null ) {
            $all = TZFD_Scoped_Store::get( self::EDITS_OPTION, array() );
            $all = is_array( $all ) ? $all : array();
            if ( null === $id ) return $all;
            return ( isset( $all[ $id ] ) && is_array( $all[ $id ] ) ) ? $all[ $id ] : array();
        }

        /** Stores one pack's edits, or removes them when none are left. */
        public static function save_edits( $id, array $edits ) {
            $all = self::edits();
            if ( empty( $edits['kb'] ) && empty( $edits['catalog'] ) && empty( $edits['kb_removed'] ) && empty( $edits['kb_added'] ) ) {
                unset( $all[ $id ] );
            } else {
                $all[ $id ] = $edits;
            }
            TZFD_Scoped_Store::update( self::EDITS_OPTION, $all );
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

            // Questions the site removed from the pack, by pack position.
            $pack_kb_count = count( (array) ( $pack['kb'] ?? array() ) );
            $removed = array();
            foreach ( (array) ( $raw['kb_removed'] ?? array() ) as $i ) {
                $i = (int) $i;
                if ( $i >= 0 && $i < $pack_kb_count ) { $removed[] = $i; }
            }
            $out['kb_removed'] = array_values( array_unique( $removed ) );

            // Questions the site added. Each needs both a question and an answer; capped at 20.
            $added = array();
            foreach ( array_slice( (array) ( $raw['kb_added'] ?? array() ), 0, 20 ) as $row ) {
                if ( ! is_array( $row ) ) continue;
                $q = trim( sanitize_text_field( (string) ( $row['q'] ?? '' ) ) );
                $a = trim( sanitize_textarea_field( (string) ( $row['a'] ?? '' ) ) );
                if ( '' !== $q && '' !== $a ) { $added[] = array( 'q' => $q, 'a' => $a ); }
            }
            $out['kb_added'] = $added;

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
            // Added questions follow the pack's own positions, so their indexes never clash with pack rows.
            $pack_kb_count = count( (array) ( $pack['kb'] ?? array() ) );
            foreach ( (array) ( $edits['kb_removed'] ?? array() ) as $i ) {
                unset( $pack['kb'][ (int) $i ] );
            }
            $n = $pack_kb_count;
            foreach ( array_slice( (array) ( $edits['kb_added'] ?? array() ), 0, 20 ) as $row ) {
                if ( ! is_array( $row ) || '' === (string) ( $row['q'] ?? '' ) ) continue;
                $pack['kb'][ $n++ ] = array( 'q' => (string) $row['q'], 'a' => (string) ( $row['a'] ?? '' ), 'keywords' => '', 'priority' => 0 );
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
            if ( ! isset( $_POST['tz_fd_pack_vars_save'] ) ) return;
            TZFD_Admin_Write_Guard::enforce( 150, 65536 );
            if ( ! current_user_can( 'tz_fd_access' ) || TZFD_Scoped_Store::company_id() < 1 ) return;
            $nonce = isset( $_POST['tz_fd_pack_vars_nonce'] ) && is_string( $_POST['tz_fd_pack_vars_nonce'] ) ? tzfd_request_text( 'post', 'tz_fd_pack_vars_nonce' ) : '';
            if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'tz_fd_pack_vars' ) ) return;

            $in    = isset( $_POST['pack_vars'] ) && is_array( $_POST['pack_vars'] ) ? map_deep( wp_unslash( $_POST['pack_vars'] ), 'sanitize_text_field' ) : array();
            if ( count( $in ) > count( self::default_vars() ) + 5 ) { return; }
            $clean = array();
            foreach ( self::default_vars() as $key => $spec ) {
                $clean[ $key ] = isset( $in[ $key ] ) && is_string( $in[ $key ] ) && strlen( $in[ $key ] ) <= 1500 ? sanitize_text_field( $in[ $key ] ) : ''; // Reject nested values; never store the string 'Array'.
            }
            TZFD_Scoped_Store::update( self::VARS_OPTION, $clean );
        }

        /* ============================================================
         *  Install / uninstall
         * ============================================================ */

        public static function install_pack( $id ) {
            if ( TZFD_Scoped_Store::company_id() < 1 ) return new WP_Error( 'no_company', __( 'Choose a company before you install a pack.', 'titan-zero-trades-front-desk' ) );
            $packs = self::available();
            if ( ! isset( $packs[$id] ) ) return new WP_Error( 'not_found', __( 'That pack could not be found. Refresh the page and try again.', 'titan-zero-trades-front-desk' ) );
            $pack = self::apply_edits( self::apply_vars( $packs[$id] ), self::edits( $id ) );

            global $wpdb;
            $rec = self::current_receptionist();

            // 1. Service types + business profile
            $biz = TZFD_Scoped_Store::get('tz_fd_business_profile', array());
            if ( ! empty($pack['service_types']) ) {
                $existing = ! empty($biz['services']) ? array_map('trim', explode(',', $biz['services'] )) : array();
                $merged   = array_values( array_unique( array_merge( $existing, $pack['service_types'] ) ) );
                $biz['services'] = implode(', ', $merged);
            }
            TZFD_Scoped_Store::update( 'tz_fd_business_profile', $biz );
            TZFD_Scoped_Store::update( 'tz_fd_ai_services', $biz['services'] ?? '' );

            // 2. Extra prompt (appended to receptionist system prompts)
            if ( ! empty($pack['extra_prompt']) ) {
                $cur = (string) TZFD_Scoped_Store::get('tz_fd_ai_system_prompt','');
                if ( strpos( $cur, '<!-- pack:'.$id.' -->' ) === false ) {
                    $cur = trim( $cur . "\n\n<!-- pack:{$id} -->\n" . $pack['extra_prompt'] );
                    TZFD_Scoped_Store::update( 'tz_fd_ai_system_prompt', $cur );
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
                    $kb_inserted = $wpdb->insert( $wpdb->prefix.TZFD_Receptionist_Schema::T_KB, array(
                        'company_id' => TZFD_Scoped_Store::company_id(),
                        'receptionist_id' => intval($rec->id),
                        'question'        => sanitize_text_field( is_scalar( $qa['q'] ?? null ) ? (string) $qa['q'] : '' ),
                        'answer'          => wp_kses_post( is_scalar( $qa['a'] ?? null ) ? (string) $qa['a'] : '' ),
                        'keywords'        => sanitize_text_field( is_scalar( $qa['keywords'] ?? null ) ? (string) $qa['keywords'] : '' ),
                        'priority'        => intval( $qa['priority'] ?? 10 ),
                        'active'          => 1,
                    ) );
                    if ( false !== $kb_inserted ) {
                        $kb_created++;
                    }
                }
            }

            // 3b. Without the Receptionist add-on, the pack's reference answers are kept in their own option for the AI assistant.
            if ( ! $rec && ! empty($pack['kb']) ) {
                $store = TZFD_Scoped_Store::get( 'tz_fd_pack_kb', array() );
                if ( ! is_array($store) ) $store = array();
                $store[$id] = array();
                foreach ( $pack['kb'] as $qa ) {
                    $store[$id][] = array(
                        'q'        => sanitize_text_field( is_scalar( $qa['q'] ?? null ) ? (string) $qa['q'] : '' ),
                        'a'        => wp_kses_post( is_scalar( $qa['a'] ?? null ) ? (string) $qa['a'] : '' ),
                        'keywords' => sanitize_text_field( is_scalar( $qa['keywords'] ?? null ) ? (string) $qa['keywords'] : '' ),
                    );
                }
                TZFD_Scoped_Store::update( 'tz_fd_pack_kb', $store );
                $kb_created = count( $store[ $id ] );
            }

            // 4. Catalog items. The price catalogue belongs to Titan Zero Quotes (19 Oct split). Front Desk does not
            // write it. Quotes listens to this action and seeds its own catalogue from the pack.
            $cat_created = 0;
            if ( ! empty( $pack['catalog'] ) ) {
                do_action( 'tz_fd_pack_catalog_items', $pack['catalog'], $id );
                $cat_created = is_array( $pack['catalog'] ) ? count( $pack['catalog'] ) : 0;
            }

            // 5. Staff skills are not written here. Staff are Workspace records, and the legacy staff table is no longer used.

            // 6. Store which pack is installed
            $installed = self::installed();
            $installed[ $id ] = array(
                'id'         => $id,
                'name'       => $pack['name'],
                'installed'  => current_time('mysql'),
                'kb'         => $kb_created,
                'catalog'    => $cat_created,
            );
            TZFD_Scoped_Store::update( self::STATE_OPTION, $installed );

            do_action( 'tz_fd_vertical_pack_installed', $id, $pack );

            return array(
                'ok'      => true,
                'kb'      => $kb_created,
                'catalog' => $cat_created,
                'pack'    => $pack,
            );
        }

        public static function uninstall_pack( $id ) {
            if ( TZFD_Scoped_Store::company_id() < 1 ) return false;
            global $wpdb;
            $installed = self::installed();
            if ( ! isset( $installed[$id] ) ) return false;

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
                // Catalog items belong to Titan Zero Quotes. Quotes removes its own items for this pack.
                if ( ! empty( $pack['catalog'] ) ) {
                    do_action( 'tz_fd_pack_catalog_items_removed', $pack['catalog'], $id );
                }
                // Remove the pack-specific prompt block
                $cur = (string) TZFD_Scoped_Store::get('tz_fd_ai_system_prompt','');
                $cur = preg_replace( '/\n*<!-- pack:'.preg_quote($id,'/').' -->\n.*?(?=<!-- pack:|$)/s', '', $cur );
                TZFD_Scoped_Store::update( 'tz_fd_ai_system_prompt', $cur );
            }

            $kb_store = TZFD_Scoped_Store::get( 'tz_fd_pack_kb', array() );
            if ( is_array($kb_store) && isset($kb_store[$id]) ) { unset( $kb_store[$id] ); TZFD_Scoped_Store::update( 'tz_fd_pack_kb', $kb_store ); }

            unset( $installed[$id] );
            // Report success only when the installed-state write is saved. A pack still marked installed must not fire the uninstall hook.
            if ( ! TZFD_Scoped_Store::update( self::STATE_OPTION, $installed ) ) { return false; }

            do_action( 'tz_fd_vertical_pack_uninstalled', $id, $pack );
            return true;
        }

        /**
         * Reference answers for the AI assistant: the installed packs' Q&A that best match the customer's message.
         * Used when the Receptionist add-on is not active; with it, the answers live in the Receptionist's table.
         */
        public static function knowledge_for_prompt( $text, $max = 6, $max_chars = 1800 ) {
            $active = TZFD_Scoped_Store::get_for_boot( 'tz_fd_addons_active_list', array() );
            if ( ! is_array($active) || ! in_array( 'tzfd-VerticalPacks', $active, true ) ) return '';
            $store = TZFD_Scoped_Store::get( 'tz_fd_pack_kb', array() );
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
            foreach ( $scored as $tz_item ) {
                $block = 'Q: ' . $tz_item[1]['q'] . "\nA: " . trim( wp_strip_all_tags( (string) ($tz_item[1]['a'] ?? '') ) ) . "\n";
                if ( $n >= $max || strlen( $out . $block ) > $max_chars ) break;
                $out .= $block; $n++;
            }
            return trim($out);
        }

        /* ============================================================
         *  Integration with receptionist (quotes read the urgency and service signals through their own hooks)
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
                __( 'Industry packs', 'titan-zero-trades-front-desk' ),
                __( 'Industry packs', 'titan-zero-trades-front-desk' ),
                'tz_fd_access',
                'tzfd-packs',
                array( &$this, 'render' )
            );
        }

        public function handle_actions() {
            if ( ! current_user_can('tz_fd_access') ) return;
            // Restrict mutation handling to the actual packs screen. The
            // installer must not execute on unrelated admin requests.
            $page = isset( $_REQUEST['page'] ) ? sanitize_key( tzfd_request_text( 'request', 'page' ) ) : '';
            if ( 'tzfd-packs' !== $page ) { return; }
            // Mutations must never be performed by a link or a prefetchable GET.
            if ( isset( $_GET['pack_install'] ) || isset( $_GET['pack_uninstall'] ) ) {
                wp_die( esc_html__( 'Pack changes require a POST request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
                return;
            }
            $has_install   = isset( $_POST['pack_install'] );
            $has_uninstall = isset( $_POST['pack_uninstall'] );
            $has_delete    = isset( $_POST['pack_delete'] );
            if ( $has_install || $has_uninstall || $has_delete ) {
                TZFD_Admin_Write_Guard::enforce( 50, 16384 );
                if ( TZFD_Scoped_Store::company_id() < 1 ) {
                    wp_die( esc_html__( 'Select a company first.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
                    return;
                }
                if ( 'POST' !== strtoupper( tzfd_request_text( 'server', 'REQUEST_METHOD' ) ) ) {
                    wp_die( esc_html__( 'Pack changes require a POST request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
                    return;
                }
                if ( 1 < (int) $has_install + (int) $has_uninstall + (int) $has_delete ) {
                    wp_die( esc_html__( 'Conflicting pack actions.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                    return;
                }
                $operation = $has_install ? 'pack_install' : ( $has_delete ? 'pack_delete' : 'pack_uninstall' );
                $pack = isset( $_POST[ $operation ] ) && is_string( $_POST[ $operation ] ) ? sanitize_key( wp_unslash( $_POST[ $operation ] ) ) : '';
                $nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] )
                    ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
                if ( '' === $pack || ! wp_verify_nonce( $nonce, $operation . '_' . $pack ) ) {
                    wp_die( esc_html__( 'Invalid or expired pack action.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
                    return;
                }
                if ( $has_install ) {
                    $res = self::install_pack( $pack );
                    if ( is_wp_error( $res ) ) {
                        wp_safe_redirect( add_query_arg( 'pack_error', $res->get_error_message(), admin_url( 'admin.php?page=tzfd-packs' ) ) );
                    } else {
                        wp_safe_redirect( add_query_arg( array(
                            'pack_installed' => $res['pack']['name'],
                            'kb'             => intval( $res['kb'] ),
                            'catalog'        => intval( $res['catalog'] ),
                        ), admin_url( 'admin.php?page=tzfd-packs' ) ) );
                    }
                    exit;
                }
                if ( $has_delete ) {
                    $deleted = self::delete_uploaded_pack( $pack );
                    wp_safe_redirect( $deleted
                        ? add_query_arg( 'pack_deleted', '1', admin_url( 'admin.php?page=tzfd-packs' ) )
                        : add_query_arg( 'pack_error', __( 'The uploaded pack could not be deleted.', 'titan-zero-trades-front-desk' ), admin_url( 'admin.php?page=tzfd-packs' ) ) );
                    exit;
                }
                self::uninstall_pack( $pack );
                wp_safe_redirect( admin_url( 'admin.php?page=tzfd-packs' ) );
                exit;
            }
            // Reject malformed upload requests before attempting to process uploaded files.
            if ( ! empty( $_FILES ) && 'POST' !== strtoupper( tzfd_request_text( 'server', 'REQUEST_METHOD' ) ) ) {
                wp_die( esc_html__( 'Uploads require a POST request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
                return;
            }
            if ( isset( $_POST['tz_fd_pack_upload_nonce'] ) || ! empty( $_FILES ) ) {
                $nonce = tzfd_request_text( 'post', 'tz_fd_pack_upload_nonce' );
                if ( ! wp_verify_nonce( $nonce, 'tz_fd_pack_upload' ) ) {
                    wp_die( esc_html__( 'Invalid or expired upload request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
                }
            }

            // Only POST requests may mutate industry-pack state. A valid nonce is
            // mandatory even for malformed upload attempts.
            if ( isset( $_POST['tz_fd_pack_upload_nonce'] ) || ! empty( $_FILES ) ) {
                if ( 'POST' !== strtoupper( tzfd_request_text( 'server', 'REQUEST_METHOD' ) ) ) {
                    wp_die( esc_html__( 'Uploads require a POST request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
                    return;
                }
                if ( $has_install || $has_uninstall ) {
                    wp_die( esc_html__( 'Conflicting pack actions.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                    return;
                }
                if ( ! isset( $_POST['tz_fd_pack_upload_nonce'] ) || ! is_string( $_POST['tz_fd_pack_upload_nonce'] ) ||
                     ! wp_verify_nonce( tzfd_request_text( 'post', 'tz_fd_pack_upload_nonce' ), 'tz_fd_pack_upload' ) ) {
                    wp_die( esc_html__( 'Invalid or expired upload request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
                    return;
                }
                TZFD_Admin_Write_Guard::enforce( 50, 16384 );
                if ( TZFD_Scoped_Store::company_id() < 1 ) {
                    wp_die( esc_html__( 'Select a company first.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
                    return;
                }
                $this->handle_upload();
            }
        }

        public function render() {
            if ( ! current_user_can('tz_fd_access') ) return;
            TZFD_Admin_Menu::render_settings_tabs( 'packs' );
            $packs     = self::available();
            $installed = self::installed();

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if ( isset($_GET['pack_installed']) ):
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(
                        /* translators: 1: industry pack name, 2: number of questions and answers added, 3: number of price list items added. */
                        esc_html__('Installed "%1$s". Added %2$d questions and answers, and %3$d price list items.', 'titan-zero-trades-front-desk'),
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
                        esc_html( tzfd_request_text( 'get', 'pack_installed' ) ),
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
                        intval( tzfd_request_text( 'get', 'kb', 0 ) ),
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
                        intval( tzfd_request_text( 'get', 'catalog', 0 ) )
                    ); ?></p>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display value.
                    $tz_installed_pack = tzfd_request_text( 'get', 'pack_installed' );
                    // Slim trade banner for the pack just installed. This page has no pack rows, so the banner shows in this notice.
                    $tz_slim_url = self::slim_banner_url_for_name( $tz_installed_pack, $packs );
                    if ( '' !== $tz_slim_url ) { ?>
                        <img class="tzfd-packs-banner" src="<?php echo esc_url( $tz_slim_url ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: name of an industry pack, such as Plumbing. */ __( 'Banner for the %s pack', 'titan-zero-trades-front-desk' ), $tz_installed_pack ) ); ?>" width="1440" height="220" loading="lazy" decoding="async" />
                    <?php }
                    ?>
                </div>
            <?php endif; ?>
            <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state. */ ?>
            <?php if ( isset($_GET['pack_error']) ): ?>
                <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state. */ ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( tzfd_request_text( 'get', 'pack_error' ) ); ?></p></div>
            <?php endif; ?>
            <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state. */ ?>
            <?php if ( isset($_GET['pack_uploaded']) || isset($_GET['pack_deleted']) ): ?>
                <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state. */ ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( isset($_GET['pack_deleted']) ?__( 'The uploaded pack was deleted.', 'titan-zero-trades-front-desk' ) : __( 'Pack uploaded. You can install it from the list below.', 'titan-zero-trades-front-desk' ) ); ?></p></div>
            <?php endif; ?>

            <div class="wrap">
                <h1>📦 <?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'services' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e('Industry packs','titan-zero-trades-front-desk'); ?></h1>
                <p style="max-width:780px;font-size:14px;line-height:1.6;">
                    <?php esc_html_e('A pack gives your AI receptionist the questions, prices and service types for one trade. Install the packs that match your business. Installing a pack adds its questions and answers, price list items and service types to your site. Removing a pack deletes the items it added. Review every answer and price before customers see them.', 'titan-zero-trades-front-desk'); ?>
                </p>

                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:16px;margin-top:20px;">
                    <?php $uploaded_packs = TZFD_Scoped_Store::get( 'tz_fd_user_packs', array() );
                    foreach ( $packs as $id => $pack ):
                        $is_in       = isset( $installed[$id] );
                        $is_uploaded = is_array( $uploaded_packs ) && isset( $uploaded_packs[ $id ] );
                        ?>
                        <div style="border:1px solid #333a48;border-radius:12px;background:#232834;overflow:hidden;display:flex;flex-direction:column;">
                            <?php if ( '' !== self::trade_banner_url( $id ) ) { ?>
                            <img src="<?php echo esc_url( self::trade_banner_url( $id ) ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: name of an industry pack, such as Plumbing. */ __( 'Banner for the %s pack', 'titan-zero-trades-front-desk' ), $pack['name'] ) ); ?>" width="1544" height="500" loading="lazy" style="display:block;width:100%;max-width:100%;height:auto;" />
                            <?php } ?>
                        <div style="background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:20px;">
                                <div style="font-size:36px;line-height:1;"><?php echo esc_html( $pack['icon'] ?? '📦' ); ?></div>
                                <h3 style="margin:10px 0 4px;color:#fff;"><?php echo esc_html( $pack['name'] ); ?></h3>
                                <p style="margin:0;font-size:12px;color:#a4acba;"><?php echo esc_html( $pack['tagline'] ?? $pack['description'] ?? '' ); ?></p>
                            </div>
                            <div style="padding:16px;flex:1;display:flex;flex-direction:column;">
                                <ul style="list-style:none;padding:0;margin:0 0 12px;font-size:13px;color:#a4acba;line-height:1.7;">
                                    <li>📚 <?php echo count( $pack['kb'] ?? array() ); ?> <?php esc_html_e('questions and answers','titan-zero-trades-front-desk'); ?></li>
                                    <li>💰 <?php echo count( $pack['catalog'] ?? array() ); ?> <?php esc_html_e('price list items','titan-zero-trades-front-desk'); ?></li>
                                    <li>🔧 <?php echo count( $pack['service_types'] ?? array() ); ?> <?php esc_html_e('service types','titan-zero-trades-front-desk'); ?></li>
                                    <?php if ( ! empty($pack['urgency_signals']['emergency']) ): ?>
                                        <li>🚨 <?php esc_html_e('Spots emergency wording','titan-zero-trades-front-desk'); ?></li>
                                    <?php endif; ?>
                                </ul>
                                <?php if ( ! empty($pack['service_types']) ): ?>
                                    <div style="font-size:11px;color:#a4acba;margin-bottom:12px;">
                                        <?php echo esc_html( implode(' · ', array_slice( $pack['service_types'], 0, 4 ) ) ); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top:auto;">
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=tzfd-packs' ) ); ?>"<?php echo $is_in ? ' data-tzfd-confirm="' . esc_attr__( 'Remove this pack? The questions and prices it added to your site will be deleted.', 'titan-zero-trades-front-desk' ) . '"' : ''; ?>>
                                        <?php wp_nonce_field( ( $is_in ? 'pack_uninstall_' : 'pack_install_' ) . $id ); ?>
                                        <?php if ( $is_in ): ?>
                                            <button type="submit" class="button" name="pack_uninstall" value="<?php echo esc_attr( $id ); ?>">
                                                <?php esc_html_e( 'Remove pack (installed)', 'titan-zero-trades-front-desk' ); ?>
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="button button-primary" name="pack_install" value="<?php echo esc_attr( $id ); ?>">
                                                <?php esc_html_e( 'Install this pack', 'titan-zero-trades-front-desk' ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                    <?php if ( $is_uploaded ): ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=tzfd-packs' ) ); ?>" style="margin-top:8px;" data-tzfd-confirm="<?php echo esc_attr__( 'Delete this uploaded pack? It will be removed from this site, and any questions and prices it added will be deleted too.', 'titan-zero-trades-front-desk' ); ?>">
                                            <?php wp_nonce_field( 'pack_delete_' . $id ); ?>
                                            <button type="submit" class="button" name="pack_delete" value="<?php echo esc_attr( $id ); ?>">
                                                <?php esc_html_e( 'Delete uploaded pack', 'titan-zero-trades-front-desk' ); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr style="margin:40px 0 20px;" />

                <h2><?php esc_html_e('Your details and policies','titan-zero-trades-front-desk'); ?></h2>
                <p style="max-width:780px;">
                    <?php esc_html_e('These details fill in the answers from your packs. Enter your own policies, notice periods and prices. Your changes apply only to this site. They do not change the packs that came with the plugin. A pack keeps the details it had when you installed it, so remove it and install it again to use new details. Any text in [square brackets] is a fill-in: replace it with your own details before your site goes live. Check every answer before customers see it.', 'titan-zero-trades-front-desk'); ?>
                </p>
                <form method="post">
                    <?php wp_nonce_field('tz_fd_pack_vars','tz_fd_pack_vars_nonce'); ?>
                    <table class="form-table">
                        <?php $current_vars = self::vars(); foreach ( self::default_vars() as $key => $spec ): ?>
                            <tr>
                                <th scope="row"><label for="pv_<?php echo esc_attr($key); ?>"><?php echo esc_html($spec['label']); ?></label></th>
                                <td><input type="text" class="large-text" id="pv_<?php echo esc_attr($key); ?>" name="pack_vars[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr( $current_vars[ $key ] ); ?>" /></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <p><button class="button button-primary" name="tz_fd_pack_vars_save" value="1"><?php esc_html_e('Save my details','titan-zero-trades-front-desk'); ?></button></p>
                </form>

                <h2><?php esc_html_e('Upload your own pack','titan-zero-trades-front-desk'); ?></h2>
                <p><?php esc_html_e('Got a pack from someone else? Upload its JSON file here. The pack is saved in your WordPress database, and no files are saved to your server.', 'titan-zero-trades-front-desk'); ?></p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('tz_fd_pack_upload','tz_fd_pack_upload_nonce'); ?>
                    <p>
                        <input type="file" name="pack_file" accept="application/json,.json" required />
                        <button class="button"><?php esc_html_e('Upload pack','titan-zero-trades-front-desk'); ?></button>
                    </p>
                    <p style="color:#92400e;font-size:12px;">
                        <?php esc_html_e('Only upload packs from people you trust. A pack can add questions and answers, price list items and instructions for your AI receptionist on this site.', 'titan-zero-trades-front-desk'); ?>
                    </p>
                </form>
            </div>
            <?php
        }

        const MAX_UPLOAD_BYTES = 524288;

        /** Sends the admin back to the packs screen with an error notice. The message is encoded once, by add_query_arg(). */
        private static function upload_failed( $message ) {
            wp_safe_redirect( add_query_arg( 'pack_error', $message, admin_url( 'admin.php?page=tzfd-packs' ) ) );
            exit;
        }

        /** Removes an uploaded pack from the same option it was stored in. Installed packs are uninstalled first. */
        private static function delete_uploaded_pack( $id ) {
            $user_packs = TZFD_Scoped_Store::get( 'tz_fd_user_packs', array() );
            if ( ! is_array( $user_packs ) || ! isset( $user_packs[ $id ] ) ) { return false; }
            if ( self::is_installed( $id ) && ! self::uninstall_pack( $id ) ) { return false; }
            unset( $user_packs[ $id ] );
            if ( ! TZFD_Scoped_Store::update( 'tz_fd_user_packs', $user_packs ) ) { return false; }
            return true;
        }

        private function handle_upload() {
            if ( ! current_user_can( 'tz_fd_access' ) || TZFD_Scoped_Store::company_id() < 1 ||
                 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { return; }
            if ( ! wp_verify_nonce( tzfd_request_text( 'post', 'tz_fd_pack_upload_nonce' ), 'tz_fd_pack_upload' ) ) { return; }
            // The temporary path is a server path: it is sanitised here, checked with is_uploaded_file(), and never output.
            $tmp_path = isset( $_FILES['pack_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['pack_file']['tmp_name'] ) ) : '';
            $file_size = isset( $_FILES['pack_file']['size'] ) && is_int( $_FILES['pack_file']['size'] ) ? intval( $_FILES['pack_file']['size'] ) : 0;
            if ( ! isset( $_FILES['pack_file']['error'] ) || ! is_int( $_FILES['pack_file']['error'] ) ||
                 UPLOAD_ERR_OK !== $_FILES['pack_file']['error'] || $file_size < 1 ) {
                self::upload_failed( __( 'The file could not be uploaded. Check the file and try again.', 'titan-zero-trades-front-desk' ) );
            }
            if ( $file_size > self::MAX_UPLOAD_BYTES ) {
                self::upload_failed( __( 'The file is too large. The limit is 512 KB.', 'titan-zero-trades-front-desk' ) );
            }
            if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
                self::upload_failed( __( 'The file could not be uploaded. Check the file and try again.', 'titan-zero-trades-front-desk' ) );
            }
            // Only a .json file is accepted. The file is parsed as data and never stored or served.
            $tmp_name = isset( $_FILES['pack_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['pack_file']['name'] ) ) : '';
            if ( 'json' !== strtolower( pathinfo( $tmp_name, PATHINFO_EXTENSION ) ) ) {
                self::upload_failed( __( 'Only .json pack files can be uploaded.', 'titan-zero-trades-front-desk' ) );
            }
            $raw      = false;
            if ( is_readable( $tmp_path ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                if ( WP_Filesystem() && isset( $GLOBALS['wp_filesystem'] ) ) $raw = $GLOBALS['wp_filesystem']->get_contents( $tmp_path );
            }
            if ( ! $raw || strlen( $raw ) > self::MAX_UPLOAD_BYTES ) {
                self::upload_failed( __( 'The file could not be read. Check the file and try again.', 'titan-zero-trades-front-desk' ) );
            }
            $tz_data = json_decode( $raw, true, 32 );
            if ( ! TZFD_Pack_Import_Guard::validate( $tz_data ) ) {
                self::upload_failed( __( 'This is not a valid pack file. Check the file and try again.', 'titan-zero-trades-front-desk' ) );
            }
            $tz_data = self::sanitize_pack_data( $tz_data );
            $tz_data['id'] = sanitize_key( $tz_data['id'] );
            $user_packs = TZFD_Scoped_Store::get( 'tz_fd_user_packs', array() );
            if ( ! is_array( $user_packs ) || count( $user_packs ) > 200 ) {
                self::upload_failed( __( 'The pack could not be saved. Delete an uploaded pack to make room, then try again.', 'titan-zero-trades-front-desk' ) );
            }
            if ( in_array( $tz_data['id'], TZFD_Vertical_Registry::ids(), true ) ) {
                self::upload_failed( __( 'This pack ID is used by a built-in pack. Change the pack ID in the file and upload it again.', 'titan-zero-trades-front-desk' ) );
            }
            // Do not silently shadow an installed or extension-provided pack.
            $available_packs = self::available();
            if ( isset( $available_packs[ $tz_data['id'] ] ) && ! isset( $user_packs[ $tz_data['id'] ] ) ) {
                self::upload_failed( __( 'A pack with this ID is already on this site. Change the pack ID in the file and upload it again.', 'titan-zero-trades-front-desk' ) );
            }
            $user_packs[ $tz_data['id'] ] = $tz_data;
            TZFD_Scoped_Store::update( 'tz_fd_user_packs', $user_packs );
            if ( TZFD_Scoped_Store::get( 'tz_fd_user_packs', array() ) !== $user_packs ) {
                self::upload_failed( __( 'The pack could not be saved. Please try again.', 'titan-zero-trades-front-desk' ) );
            }

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
    global $tz_addons_objs_list;
    $tz_addons_objs_list[ $TZFD_Vertical_Packs_obj->get_addon_id() ] = $TZFD_Vertical_Packs_obj;
}
