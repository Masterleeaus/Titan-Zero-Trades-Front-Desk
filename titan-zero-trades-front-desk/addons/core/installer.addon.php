<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/../../includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/../../includes/class-tzfd-admin-write-guard.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/**
 * AI Suite Setup Wizard — WordPress.org compliant
 *
 * Guides a new install from "plugin activated" to "first AI-answered call".
 * Every step that touches an external service has a live "Test" button.
 *
 * WordPress.org compliance notes (Guideline 8):
 * - This plugin does NOT download, install, or execute any binaries.
 * - AI providers are configured by WordPress Connectors.
 *   installed separately, and communicates with it over HTTP.
 * - All outbound endpoints are disclosed in the admin UI and in readme.txt.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
require_once dirname( __FILE__ ).'/base.addon.php';

if ( ! class_exists( 'TZFD_Setup_Wizard' ) ) {

    class TZFD_Setup_Wizard extends TZFD_BaseAddon
    {
        public function get_addon_settings()
        {
            if ( ! TZFD_Addon_Support::ai_configured() ) {
                return TZFD_Addon_Support::info_block( __( 'The AI Setup Wizard links to WordPress Settings → Connectors. Configure an AI provider there.', 'titan-zero-trades-front-desk' ), array( __( 'Open AI settings', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tz_fd_settings&gotab=ai' ) ) );
            }
            return TZFD_Addon_Support::info_block( __( 'Connect an AI provider through WordPress Settings → Connectors, then test the AI assistant.', 'titan-zero-trades-front-desk' ), array( __( 'Open the AI Setup Wizard', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tzfd-ai-setup' ) ) );
        }

        
        protected $addonID = "tzfd-AISetup";
        protected $name = "AI Setup Wizard";
        protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const STATE_OPTION = 'tz_fd_setup_wizard_state';

        /** Unslashed copy of $_POST. Filled only by the entry points that have already verified a nonce. */
        private $request = array();

        /**
         * The server types the setup form offers, each with the one path the detection helper calls.
         * Returns '' for any other type, so nothing else is probed.
         */
        private function local_probe_path( $kind ) {
            $paths = array(
                'ollama'   => '/api/tags',
                'lmstudio' => '/v1/models',
                'vllm'     => '/v1/models',
                'localai'  => '/v1/models',
                'custom'   => '/v1/models',
            );
            return $paths[ $kind ] ?? '';
        }

        /** Step machine — key => [title, renderer, saver] */
        private function steps() {
            return array(
                'welcome'      => array( 'Welcome',             'step_welcome' ),
                'mode'         => array( 'Choose AI mode',      'step_mode'         ),
                'ai_setup'     => array( 'Connect AI',          'step_ai_setup'     ),
                'business'     => array( 'Business basics',     'step_business'     ),
                'done'         => array( 'Finish',              'step_done'         ),
            );
        }

        function __construct() {
            $this->description = $this->tr_apply(
                "Guided setup for the AI suite. Walks you from install to first answered call with a live test at every step. Uses the WordPress AI Client and provider connectors configured by the site administrator.",
                'titan-zero-trades-front-desk'
            );
            // The AI page is always in the menu, so its menu entry is registered before the add-on switch is checked.
            add_action( 'admin_menu', array( &$this, 'add_menu' ), 100 );
            add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_wizard_assets' ) );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'admin_init',    array( &$this, 'handle_step_post' ) );
            add_action( 'wp_ajax_tz_fd_test_provider', array( &$this, 'ajax_test_provider' ) );
            add_action( 'admin_notices', array( &$this, 'nudge_if_not_setup' ) );

        }

        /* ============================================================
         *  Menu + notices
         * ============================================================ */

        /** Loads the AI step's script and its values on the wizard page. Replaces the inline script tags (R2). */
        public function enqueue_wizard_assets( $hook_suffix ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check, no change is made.
            $tz_page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
            if ( 'tzfd-ai-setup' !== $tz_page || ! current_user_can( 'tz_fd_access' ) ) return;

            wp_enqueue_script(
                'tzfd-core-addons-installer',
                plugins_url( 'js/inline/core-addons-installer.js', dirname( __DIR__, 2 ) . '/titan-zero-front-desk.php' ),
                array( 'jquery' ),
                '2.0.1',
                true
            );
            wp_localize_script( 'tzfd-core-addons-installer', 'tzfdCoreInstaller', array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'testNonce'   => wp_create_nonce( 'tz_fd_test_provider' ),
            ) );
        }

        public function add_menu() {
            // The AI page is always there, so the Workspace menu's AI item opens it. Until a provider is connected it
            // shows the set-up prompt; the guided wizard runs on its steps.
            add_submenu_page(
                'tzfd',
                __( 'AI', 'titan-zero-trades-front-desk' ),
                __( 'AI', 'titan-zero-trades-front-desk' ),
                'tz_fd_access',
                'tzfd-ai-setup',
                array( &$this, 'render_wizard' )
            );
        }

        public function nudge_if_not_setup() {
            if ( ! current_user_can('tz_fd_access') ) return;
            // Workspace screens only.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check, no change is made.
            $tz_page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
            if ( 0 !== strpos( $tz_page, 'tzfd' ) && 0 !== strpos( $tz_page, 'titan-zero-trades-front-desk' ) ) return;
            if ( ! TZFD_Addon_Support::ai_configured() ) return;
            if ( 'done' === $this->get_state()['step'] ) return;
            $screen = get_current_screen();
            if ( $screen && strpos( $screen->id, 'tzfd-ai-setup' ) !== false ) return;
            printf(
                '<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <a href="%s" class="button button-primary" style="margin-left:8px;">%s</a></p></div>',
                esc_html__('AI Suite is almost ready.', 'titan-zero-trades-front-desk'),
                esc_html__('Run the guided setup to connect the AI assistant.', 'titan-zero-trades-front-desk'),
                esc_url( admin_url('admin.php?page=tzfd-ai-setup') ),
                esc_html__('Start setup', 'titan-zero-trades-front-desk')
            );
        }

        /* ============================================================
         *  Renderer
         * ============================================================ */

        public function render_wizard() {
            if ( ! current_user_can('tz_fd_access') ) return;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step selector.
            if ( ! isset( $_GET['step'] ) ) { $this->render_overview(); return; }

            $steps   = $this->steps();
            $state   = $this->get_state();
            $current = $state['step'] ?? 'welcome';
            // A valid step key in the URL is shown for this GET request only. The stored step is not read back or changed here.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step selector; saves are nonce-gated in handle_step_post().
            $requested = sanitize_key( tzfd_request_text( 'get', 'step' ) );
            if ( '' !== $requested && isset( $steps[ $requested ] ) ) { $current = $requested; }
            if ( ! isset($steps[$current]) ) $current = 'welcome';

            $keys     = array_keys( $steps );
            $idx      = array_search( $current, $keys, true );
            $progress = $idx !== false ? round( ($idx / count($keys)) * 100 ) : 0;
            $flash_key = $this->flash_key();
            $flash = get_transient( $flash_key );
            if ( $flash ) { delete_transient( $flash_key ); }

            ?>
            <div class="wrap tzfd-wizard">
                <h1>🧠 <?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'ai-receptionist' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e('AI Suite Setup','titan-zero-trades-front-desk'); ?></h1>

                <div style="background:#e5e7eb;height:8px;border-radius:4px;overflow:hidden;max-width:900px;margin-top:12px;">
                    <div style="height:100%;width:<?php echo intval($progress); ?>%;background:#2563eb;transition:width .3s;"></div>
                </div>
                <p style="color:#a4acba;margin:6px 0 0;">
                    <?php
                    /* translators: 1: current step number, 2: total number of steps. */
                    echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'titan-zero-trades-front-desk' ), (int) $idx + 1, (int) count( $keys ) ) ); ?>
                    — <?php echo esc_html( $steps[$current][0] ); ?>
                </p>

                <?php if ( is_array( $flash ) && isset( $flash['ok'], $flash['msg'] ) && is_scalar( $flash['msg'] ) ): ?>
                    <div class="notice <?php echo $flash['ok'] ? 'notice-success' : 'notice-error'; ?> is-dismissible" style="margin-top:16px;">
                        <p><?php echo esc_html( $flash['msg'] ); ?></p>
                    </div>
                <?php endif; ?>

                <div class="tzfd-wizard-body" style="background:#232834;color:#f2f4f8;border:1px solid #333a48;border-radius:8px;padding:24px;margin-top:16px;max-width:900px;">
                    <?php
                    $method = $steps[$current][1];
                    if ( method_exists( $this, $method ) ) $this->$method( $state );
                    ?>
                </div>

                <div style="margin-top:16px;max-width:900px;display:flex;justify-content:space-between;">
                    <div>
                        <?php if ( $idx > 0 ): $prev = $keys[ $idx - 1 ]; ?>
                            <a class="button" href="<?php echo esc_url( $this->step_url($prev) ); ?>">&larr; <?php esc_html_e('Back','titan-zero-trades-front-desk'); ?></a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ( $idx < count($keys) - 1 ): $next = $keys[ $idx + 1 ]; ?>
                            <a class="button" href="<?php echo esc_url( $this->step_url($next) ); ?>"><?php esc_html_e('Skip for now','titan-zero-trades-front-desk'); ?></a>
                        <?php endif; ?>
                        <?php esc_html_e('Exit to Add-ons','titan-zero-trades-front-desk'); ?>
                    </div>
                </div>
            </div>
            <?php
        }

        /* ============================================================
         *  Steps
         * ============================================================ */

        private function step_welcome( $state ) {
            ?>
            <h2><?php esc_html_e('Welcome','titan-zero-trades-front-desk'); ?></h2>
            <p style="font-size:15px;line-height:1.6;">
                <?php esc_html_e('This wizard helps connect the WordPress AI Client to the AI assistant and fills in your business basics. Voice calls, SMS, WhatsApp, Messenger, Telegram and email are not part of this version.', 'titan-zero-trades-front-desk'); ?>
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
                    <h3 style="margin-top:0;"><?php esc_html_e('What you get','titan-zero-trades-front-desk'); ?></h3>
                    <ul style="line-height:1.7;margin:0;padding-left:20px;">
                        <li><?php esc_html_e('An AI assistant on your booking page', 'titan-zero-trades-front-desk'); ?></li>
                        <li><?php esc_html_e('Answers based on your business basics and services', 'titan-zero-trades-front-desk'); ?></li>
                        <li><?php esc_html_e('A live connection test before you finish', 'titan-zero-trades-front-desk'); ?></li>
                    </ul>
                </div>
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
                    <h3 style="margin-top:0;"><?php esc_html_e('What you need','titan-zero-trades-front-desk'); ?></h3>
                    <ul style="line-height:1.7;margin:0;padding-left:20px;">
                        <li><?php esc_html_e('A provider connector configured in WordPress', 'titan-zero-trades-front-desk'); ?></li>
                        <li><?php esc_html_e('Your business name, services and service area', 'titan-zero-trades-front-desk'); ?></li>
                    </ul>
                </div>
            </div>
            <p style="margin-top:20px;">
                <form method="post" action="<?php echo esc_url( $this->step_url( 'welcome' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'tz_fd_step_welcome', 'tz_fd_wizard_nonce' ); ?>
                    <input type="hidden" name="tz_fd_wizard_step" value="welcome">
                    <button type="submit" class="button button-primary button-hero"><?php esc_html_e('Get started', 'titan-zero-trades-front-desk'); ?></button>
                </form>
            </p>
            <?php
        }

        private function step_mode( $state ) {
            ?>
            <h2><?php esc_html_e('Use WordPress AI Connectors','titan-zero-trades-front-desk'); ?></h2>
            <p><?php esc_html_e('Titan does not collect provider keys. WordPress manages AI providers and credentials under Settings → Connectors.','titan-zero-trades-front-desk'); ?></p>
            <p><?php esc_html_e('WordPress includes connectors for OpenAI, Anthropic and Google. For free-tier services such as Groq, OpenRouter or Cerebras, install a compatible provider connector. Local servers also need a compatible connector.','titan-zero-trades-front-desk'); ?></p>
            <p><a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e('Open WordPress Connectors','titan-zero-trades-front-desk'); ?></a> <a class="button" href="https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compatible-provider connector','titan-zero-trades-front-desk'); ?></a></p>
            <form method="post" action="<?php echo esc_url( $this->step_url( 'mode' ) ); ?>">
                <?php wp_nonce_field( 'tz_fd_step_mode', 'tz_fd_wizard_nonce' ); ?>
                <input type="hidden" name="tz_fd_wizard_step" value="mode">
                <p><button type="submit" class="button"><?php esc_html_e('Continue to connection test','titan-zero-trades-front-desk'); ?> &rarr;</button></p>
            </form>
            <?php
        }

        /* ------------------------------------------------------------
         *  AI setup — four mode-specific forms
         * ------------------------------------------------------------ */

        private function step_ai_setup( $state ) {
            ?>
            <h2><?php esc_html_e('Connect an AI provider','titan-zero-trades-front-desk'); ?></h2>
            <p><?php esc_html_e('Titan uses the WordPress AI Client. Provider keys and model selection are managed in WordPress Settings → Connectors.','titan-zero-trades-front-desk'); ?></p>
            <ol>
                <li><?php esc_html_e('Open Settings → Connectors and connect a supported AI provider.','titan-zero-trades-front-desk'); ?></li>
                <li><?php esc_html_e('For free-tier services such as Groq, OpenRouter or Cerebras, install a compatible provider connector first.','titan-zero-trades-front-desk'); ?></li>
                <li><?php esc_html_e('Return to Titan and use the test button to confirm that text generation works.','titan-zero-trades-front-desk'); ?></li>
            </ol>
            <p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e('Open WordPress Connectors','titan-zero-trades-front-desk'); ?></a> <a class="button" href="https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Compatible-provider connector','titan-zero-trades-front-desk'); ?></a></p>
            <form method="post" action="<?php echo esc_url( $this->step_url( 'ai_setup' ) ); ?>">
                <?php wp_nonce_field( 'tz_fd_step_ai_setup', 'tz_fd_wizard_nonce' ); ?>
                <input type="hidden" name="tz_fd_wizard_step" value="ai_setup">
                <p>
                    <button type="button" class="button tzfd-test-provider"><?php esc_html_e('Test only','titan-zero-trades-front-desk'); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Continue','titan-zero-trades-front-desk'); ?></button>
                </p>
                <div class="tzfd-test-result" aria-live="polite"></div>
            </form>
            <?php
        }

        private function step_business( $state ) {
            $profile = class_exists( 'TZFD_Scoped_Store', false ) ? TZFD_Scoped_Store::get( 'tz_fd_business_profile', array() ) : array();
            $profile = is_array( $profile ) ? $profile : array();
            $value   = static function ( $key ) use ( $profile ) {
                return isset( $profile[ $key ] ) && is_scalar( $profile[ $key ] ) ? (string) $profile[ $key ] : '';
            };
            ?>
            <h2><?php esc_html_e('Business basics','titan-zero-trades-front-desk'); ?></h2>
            <p><?php esc_html_e('The assistant uses your business name, services and service area when it answers enquiries.','titan-zero-trades-front-desk'); ?></p>
            <form method="post" action="<?php echo esc_url( $this->step_url( 'business' ) ); ?>">
                <?php wp_nonce_field( 'tz_fd_step_business', 'tz_fd_wizard_nonce' ); ?>
                <input type="hidden" name="tz_fd_wizard_step" value="business">
                <p>
                    <label for="tzfd_biz_name"><?php esc_html_e('Business name','titan-zero-trades-front-desk'); ?></label><br>
                    <input type="text" id="tzfd_biz_name" name="biz_name" class="regular-text" maxlength="200" value="<?php echo esc_attr( $value( 'name' ) ); ?>">
                </p>
                <p>
                    <label for="tzfd_biz_services"><?php esc_html_e('Services','titan-zero-trades-front-desk'); ?></label><br>
                    <input type="text" id="tzfd_biz_services" name="biz_services" class="regular-text" maxlength="4000" value="<?php echo esc_attr( $value( 'services' ) ); ?>">
                </p>
                <p>
                    <label for="tzfd_biz_area"><?php esc_html_e('Service area','titan-zero-trades-front-desk'); ?></label><br>
                    <textarea id="tzfd_biz_area" name="biz_area" class="large-text" rows="3" maxlength="4000"><?php echo esc_textarea( $value( 'area' ) ); ?></textarea>
                </p>
                <p>
                    <label for="tzfd_biz_tz"><?php esc_html_e('Timezone','titan-zero-trades-front-desk'); ?></label><br>
                    <input type="text" id="tzfd_biz_tz" name="biz_tz" class="regular-text" maxlength="120" value="<?php echo esc_attr( $value( 'timezone' ) ); ?>">
                </p>
                <p><button type="submit" class="button button-primary"><?php esc_html_e('Save and continue','titan-zero-trades-front-desk'); ?></button></p>
            </form>
            <?php
        }

        private function step_done( $state ) {
            ?>
            <h2><?php esc_html_e('Setup complete','titan-zero-trades-front-desk'); ?></h2>
            <p><?php esc_html_e('Your setup steps are saved. You can test the AI connection again at any time from the AI assistant page.','titan-zero-trades-front-desk'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=tzfd-ai-setup' ) ); ?>"><?php esc_html_e('Back to AI assistant','titan-zero-trades-front-desk'); ?></a>
                <a class="button" href="<?php echo esc_url( $this->step_url( 'ai_setup' ) ); ?>"><?php esc_html_e('Test connection','titan-zero-trades-front-desk'); ?></a>
            </p>
            <form method="post" action="<?php echo esc_url( $this->step_url( 'done' ) ); ?>">
                <?php wp_nonce_field( 'tz_fd_step_done', 'tz_fd_wizard_nonce' ); ?>
                <input type="hidden" name="tz_fd_wizard_step" value="done">
                <p><button type="submit" class="button button-primary"><?php esc_html_e('Finish setup','titan-zero-trades-front-desk'); ?></button></p>
            </form>
            <?php
        }

        private function save_ai_setup() {
            if ( ! wp_verify_nonce( tzfd_request_text( 'post', 'tz_fd_wizard_nonce' ), 'tz_fd_step_ai_setup' ) ) { return; }
            // Continue needs a passing connection test for the configured provider. Skip for now is a plain link and never posts here.
            if ( ! self::provider_test_passed() ) {
                $this->flash( false, __( 'Run Test connection first. Continue opens only after the configured AI provider passes the test.', 'titan-zero-trades-front-desk' ) );
                wp_safe_redirect( $this->step_url( 'ai_setup' ) );
                exit;
            }
            // Credentials are owned by WordPress Connectors; legacy plugin-specific fields are ignored.
            $this->flash( true, __( 'Configure your AI provider in WordPress Settings → Connectors.', 'titan-zero-trades-front-desk' ) );
            return true;
        }

        /** Welcome and Mode have nothing to save; their POST only moves the stored step on. */
        private function save_none() {
            return true;
        }

        /**
         * Finish. Writes the same key and value as the onboarding preset (tz_fd_setup_done, 'Y-m-d H:i UTC').
         * Nothing is written unless the wizard has reached this step; advance() then refuses the post.
         */
        private function save_done() {
            if ( 'done' !== (string) ( $this->get_state()['step'] ?? '' ) ) { return true; }
            $value = gmdate( 'Y-m-d H:i' ) . ' UTC';
            TZFD_Scoped_Store::update( 'tz_fd_setup_done', $value );
            return TZFD_Scoped_Store::get( 'tz_fd_setup_done', '' ) === $value;
        }

        /** Reads one field from the verified POST copy set by handle_step_post(). */
        private function post_raw( $key, $default = '' ) {
            return isset( $this->request[ $key ] ) && is_scalar( $this->request[ $key ] ) ? (string) $this->request[ $key ] : (string) $default;
        }

        /**
         * Entry point for wizard form posts (admin_init). Dispatches a step's form to its saver only after that
         * step's own nonce verifies, then moves the wizard on. Non-wizard requests return without any change.
         */
        public function handle_step_post() {
            $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( tzfd_request_text( 'server', 'REQUEST_METHOD' ) ) : '';
            if ( 'POST' !== $method ) { return; }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request; the step nonce is verified below before any change.
            $page = isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
            if ( 'tzfd-ai-setup' !== $page || ! current_user_can( 'tz_fd_access' ) ) { return; }

            $savers = array( 'welcome' => 'save_none', 'mode' => 'save_none', 'ai_setup' => 'save_ai_setup', 'business' => 'save_business', 'done' => 'save_done' );
            $step   = isset( $_POST['tz_fd_wizard_step'] ) ? sanitize_key( tzfd_request_text( 'post', 'tz_fd_wizard_step' ) ) : '';
            if ( ! isset( $savers[ $step ] ) ) { return; }

            $nonce = isset( $_POST['tz_fd_wizard_nonce'] ) && is_string( $_POST['tz_fd_wizard_nonce'] ) ? tzfd_request_text( 'post', 'tz_fd_wizard_nonce' ) : '';
            if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'tz_fd_step_' . $step ) ) {
                $this->flash( false, __( 'Your setup form has expired. Please try again.', 'titan-zero-trades-front-desk' ) );
                wp_safe_redirect( $this->step_url( $step ) );
                exit;
            }

            if ( ! TZFD_Admin_Write_Guard::valid( $method, $_POST, isset( $_SERVER['CONTENT_LENGTH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : null, 24, 16384 ) ) {
                $this->flash( false, __( 'Invalid or oversized setup request.', 'titan-zero-trades-front-desk' ) );
                wp_safe_redirect( $this->step_url( $step ) );
                exit;
            }
            $this->request = wp_unslash( $_POST );
            if ( true !== $this->{$savers[ $step ]}() ) {
                $this->flash( false, __( 'Setup settings could not be saved. Please retry.', 'titan-zero-trades-front-desk' ) );
                wp_safe_redirect( $this->step_url( $step ) );
                exit;
            }
            $this->advance( $step );
        }

        private function save_business() {
            $name = sanitize_text_field( $this->post_raw( 'biz_name', '' ) );
            $services = sanitize_text_field( $this->post_raw( 'biz_services', '' ) );
            $area = sanitize_textarea_field( $this->post_raw( 'biz_area', '' ) );
            $timezone = sanitize_text_field( $this->post_raw( 'biz_tz', '' ) );
            if ( strlen( $name ) > 200 || strlen( $services ) > 4000 || strlen( $area ) > 4000 || strlen( $timezone ) > 120 ) {
                return false;
            }
            $updates = array(
                'tz_fd_business_profile' => array( 'name' => $name, 'services' => $services, 'area' => $area, 'timezone' => $timezone ),
                'tz_fd_ai_service_area' => $area,
                'tz_fd_ai_services' => $services,
            );
            // WordPress update_option() returns false for an unchanged value. Verify
            // the stored value instead of misclassifying a no-op or failed write.
            foreach ( $updates as $key => $value ) {
                TZFD_Scoped_Store::update( $key, $value );
                if ( TZFD_Scoped_Store::get( $key, null ) !== $value ) {
                    return false;
                }
            }
            return true;
        }

        /* ============================================================
         *  AJAX handlers
         * ============================================================ */

        /** Same resolution the assistant uses: filter first, then the assistant's configured provider. */
        private static function resolve_provider() {
            $p = apply_filters( 'tz_fd_ai_provider', null );
            if ( $p instanceof TZFD_AI_Provider ) return $p;
            global $tz_addons_objs_list;
            $assistant = $tz_addons_objs_list['tzfd-AIAssistant'] ?? null;
            return ( $assistant && method_exists( $assistant, 'get_provider' ) ) ? $assistant->get_provider() : null;
        }

        /** Stores only the pass flag, the provider id and a time for the company's latest connection test. */
        private static function record_provider_test( $pass, $provider_id ) {
            if ( ! class_exists( 'TZFD_Scoped_Store', false ) || TZFD_Scoped_Store::company_id() < 1 ) { return; }
            TZFD_Scoped_Store::update( 'tz_fd_ai_last_test', array(
                'pass'     => (bool) $pass,
                'provider' => substr( (string) $provider_id, 0, 120 ),
                'time'     => time(),
            ) );
        }

        /** True only when the latest test passed for the provider configured now, so a change of provider needs a new test. */
        private static function provider_test_passed() {
            if ( ! class_exists( 'TZFD_Scoped_Store', false ) || TZFD_Scoped_Store::company_id() < 1 ) { return false; }
            $provider = self::resolve_provider();
            if ( ! $provider ) { return false; }
            $current = (string) $provider->id();
            $last    = TZFD_Scoped_Store::get( 'tz_fd_ai_last_test', array() );
            return '' !== $current && is_array( $last ) && true === ( $last['pass'] ?? false ) &&
                   $current === (string) ( $last['provider'] ?? '' );
        }

        /** Fire a tiny round-trip against the currently configured provider. */
        public function ajax_test_provider() {
            if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
                wp_send_json_error( array( 'message' => __( 'Method not allowed', 'titan-zero-trades-front-desk' ) ), 405 );
                return;
            }
            if ( ! current_user_can( 'tz_fd_access' ) ||
                ! class_exists( 'TZFD_Scoped_Store', false ) ||
                TZFD_Scoped_Store::company_id() < 1 ) {
                wp_send_json_error( array( 'message' => __( 'Forbidden', 'titan-zero-trades-front-desk' ) ), 403 );
                return;
            }
            check_ajax_referer( 'tz_fd_test_provider' );
            if ( ! TZFD_Admin_Write_Guard::valid( 'POST', $_POST, isset( $_SERVER['CONTENT_LENGTH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : null, 8, 2048 ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid or oversized request.', 'titan-zero-trades-front-desk' ) ), 400 );
                return;
            }

            $provider = self::resolve_provider();
            if ( ! $provider ) {
                self::record_provider_test( false, '' );
                wp_send_json_error( array('message'=>__( 'No AI provider is configured yet.', 'titan-zero-trades-front-desk' )) );
                return;
            }
            $provider_id = (string) $provider->id();
            $t0  = microtime(true);
            try {
                $res = $provider->chat( array(
                    array( 'role' => 'system', 'content' => 'Reply with exactly the word OK.' ),
                    array( 'role' => 'user',   'content' => 'Ping.' ),
                ), array(), array( 'max_tokens' => 10 ) );
            } catch ( \Throwable $e ) {
                self::record_provider_test( false, $provider_id );
                wp_send_json_error( array( 'message' => __( 'The provider connection failed.', 'titan-zero-trades-front-desk' ) ), 502 );
                return;
            }
            if ( ! is_array( $res ) ) {
                self::record_provider_test( false, $provider_id );
                wp_send_json_error( array( 'message' => __( 'Unexpected provider response.', 'titan-zero-trades-front-desk' ) ), 502 );
                return;
            }
            $ms  = round( (microtime(true) - $t0) * 1000 );

            if ( ! empty($res['error']) ) {
                self::record_provider_test( false, $provider_id );
                wp_send_json_error( array( 'message' => __( 'The provider reported an error.', 'titan-zero-trades-front-desk' ) ), 502 );
                return;
            }
            if ( ! isset( $res['content'] ) || ! is_string( $res['content'] ) || strlen( $res['content'] ) > 4096 ) {
                self::record_provider_test( false, $provider_id );
                wp_send_json_error( array( 'message' => __( 'Invalid provider reply.', 'titan-zero-trades-front-desk' ) ), 502 );
                return;
            }
            $text = trim( $res['content'] );
            self::record_provider_test( true, $provider_id );
            wp_send_json_success( array(
                'message' => sprintf( 'OK — replied "%s" in %d ms.', TZFD_Legacy_Compat::substr($text, 0, 40), $ms ),
            ) );
        }

        /* ============================================================
         *  Utilities
         * ============================================================ */

        private function state_key() {
            $company = class_exists( 'TZFD_Scoped_Store', false ) ? (int) TZFD_Scoped_Store::company_id() : 0;
            return $company > 0 ? self::STATE_OPTION . '_c' . $company : '';
        }

        private function flash_key() {
            $company_key = $this->state_key();
            return 'tz_fd_setup_flash_' . md5( $company_key . ':' . (int) get_current_user_id() );
        }

        private function get_state() {
            $key = $this->state_key();
            $state = '' !== $key ? get_option( $key, array() ) : array();
            if ( ! is_array($state) ) $state = array();
            if ( empty($state['step']) ) $state['step'] = 'welcome';
            return $state;
        }

        private function advance( $from ) {
            $steps = $this->steps();
            $keys  = array_keys($steps);
            $key = $this->state_key();
            if ( '' === $key ) {
                $this->flash( false, __( 'Select a business before continuing setup.', 'titan-zero-trades-front-desk' ) );
                wp_safe_redirect( $this->step_url( $from ) );
                exit;
            }
            // Only the stored step can move the wizard, and only one step forward. An earlier posted step keeps its save but leaves the stored step where it is.
            $stored   = (string) ( $this->get_state()['step'] ?? 'welcome' );
            $from_i   = array_search( $from, $keys, true );
            $stored_i = array_search( $stored, $keys, true );
            if ( false === $stored_i ) { $stored_i = 0; $stored = $keys[0]; }
            if ( $from_i !== $stored_i ) {
                if ( false !== $from_i && $from_i > $stored_i ) {
                    $this->flash( false, __( 'Finish the earlier setup steps first.', 'titan-zero-trades-front-desk' ) );
                }
                wp_safe_redirect( $this->step_url( $stored ) );
                exit;
            }
            $next = isset( $keys[ $stored_i + 1 ] ) ? $keys[ $stored_i + 1 ] : $stored;
            $new_state = array( 'step' => $next, 'updated' => time() );
            update_option( $key, $new_state, false );
            if ( get_option( $key, null ) !== $new_state ) {
                $this->flash( false, __( 'Could not save setup progress.', 'titan-zero-trades-front-desk' ) );
                wp_safe_redirect( $this->step_url( $from ) );
                exit;
            }
            wp_safe_redirect( $this->step_url( $next ) );
            exit;
        }

        /**
         * AI assistant page: header, a setup banner until a provider is connected, and cards for the
         * receptionist, how replies are handled, and the booking assistant add-on. The guided wizard opens from here.
         */
        private function render_overview() {
            $connected = TZFD_Addon_Support::ai_configured();
            $addons_url = admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ADDONS );
            echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-ai">';
            TZFD_Admin_Layout::header(
                __( 'AI assistant', 'titan-zero-trades-front-desk' ),
                __( 'Connect a WordPress AI provider and decide how the assistant supports booking enquiries.', 'titan-zero-trades-front-desk' ),
                '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( $this->step_url( 'welcome' ) ) . '">' . esc_html__( 'Configure AI', 'titan-zero-trades-front-desk' ) . '</a>',
                'ai-receptionist'
            );
            if ( ! $connected ) {
                echo '<div class="tzfd-card tzfd-banner-warn tzfd-banner"><div><strong>' . esc_html__( 'Connect an AI provider in WordPress', 'titan-zero-trades-front-desk' ) . '</strong>';
                echo '<p class="tzfd-muted">' . esc_html__( 'Connect a provider under Settings → Connectors to enable AI replies.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $this->step_url( 'ai_setup' ) ) . '">' . esc_html__( 'Provider settings', 'titan-zero-trades-front-desk' ) . '</a></div>';
            }
            echo '<div class="tzfd-dash-grid">';
            $tz_plugin_file = dirname( __DIR__, 2 ) . '/titan-zero-front-desk.php';
            $tz_state_icon  = plugins_url( 'images/ui-graphics/06-Integrations/Connection-States/' . ( $connected ? 'connected' : 'disconnected' ) . '.svg', $tz_plugin_file );
            $tz_feature_icon = plugins_url( 'images/brand/ui-kit/feature-cards/ai-receptionist.svg', $tz_plugin_file );
            echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2><img src="' . esc_url( $tz_feature_icon ) . '" alt="" width="32" height="32" loading="lazy" style="width:32px;max-width:100%;height:auto;vertical-align:middle;margin-right:8px;" />' . esc_html__( 'AI receptionist', 'titan-zero-trades-front-desk' ) . '</h2>';
            echo '<img src="' . esc_url( $tz_state_icon ) . '" alt="" width="20" height="20" loading="lazy" style="width:20px;max-width:100%;height:auto;vertical-align:middle;margin-right:6px;" />';
            echo $connected ? '<span class="tzfd-badge tzfd-badge-active">' . esc_html__( 'CONNECTED', 'titan-zero-trades-front-desk' ) . '</span>' : '<span class="tzfd-badge tzfd-badge-pending">' . esc_html__( 'NOT CONNECTED', 'titan-zero-trades-front-desk' ) . '</span>';
            echo '</div><dl class="tzfd-dl">';
            echo '<span>' . esc_html__( 'Provider', 'titan-zero-trades-front-desk' ) . '</span><span>' . esc_html( 'WordPress AI Connectors' ) . '</span>';
            echo '<span>' . esc_html__( 'Connection', 'titan-zero-trades-front-desk' ) . '</span><span>' . ( $connected ? esc_html__( 'Connector available', 'titan-zero-trades-front-desk' ) : esc_html__( 'Not connected', 'titan-zero-trades-front-desk' ) ) . '</span>';
            echo '</dl><div class="tzfd-actions"><a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $this->step_url( 'ai_setup' ) ) . '">' . esc_html__( 'Test connection', 'titan-zero-trades-front-desk' ) . '</a></div></div>';

            echo '<div class="tzfd-side-col"><div class="tzfd-card"><h2>' . esc_html__( 'Response behaviour', 'titan-zero-trades-front-desk' ) . '</h2>';
            echo '<p class="tzfd-muted">' . esc_html__( 'The assistant answers enquiries and asks for the details it needs. When a caller needs a person, it emails the site admin.', 'titan-zero-trades-front-desk' ) . '</p>';
            echo '<p><span class="tzfd-badge tzfd-badge-sent">' . esc_html__( 'Answers enquiries', 'titan-zero-trades-front-desk' ) . '</span> <span class="tzfd-badge tzfd-badge-other">' . esc_html__( 'Escalates to a person', 'titan-zero-trades-front-desk' ) . '</span></p></div>';
            echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2><img src="' . esc_url( plugins_url( 'images/brand/ui-kit/feature-cards/bookings.svg', $tz_plugin_file ) ) . '" alt="" width="32" height="32" loading="lazy" style="width:32px;max-width:100%;height:auto;vertical-align:middle;margin-right:8px;" />' . esc_html__( 'AI booking assistant', 'titan-zero-trades-front-desk' ) . '</h2><span class="tzfd-badge tzfd-badge-sent">' . esc_html__( 'ADD-ON', 'titan-zero-trades-front-desk' ) . '</span></div>';
            echo '<p class="tzfd-muted">' . esc_html__( 'Helps customers find a service and send a booking request. Optional, and switched on under Add-ons.', 'titan-zero-trades-front-desk' ) . '</p>';
            echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $addons_url ) . '">' . esc_html__( 'View add-ons', 'titan-zero-trades-front-desk' ) . '</a></div></div>';
            echo '</div></div>';
        }

        private function step_url( $step ) {
            return admin_url('admin.php?page=tzfd-ai-setup&step='.rawurlencode($step));
        }

        private function flash( $ok, $msg ) {
            set_transient( $this->flash_key(), array( 'ok'=>$ok, 'msg'=>$msg ), 60 );
        }

        private function encrypt( $plain ) {
            return TZFD_Addon_Support::secret_encrypt( $plain );
        }

    }

    $TZFD_Setup_Wizard_obj = new TZFD_Setup_Wizard();
    global $tz_addons_objs_list;
    $tz_addons_objs_list[ $TZFD_Setup_Wizard_obj->get_addon_id() ] = $TZFD_Setup_Wizard_obj;
}
