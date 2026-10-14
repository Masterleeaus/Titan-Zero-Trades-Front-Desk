<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/**
 * AI Suite Setup Wizard — WordPress.org compliant
 *
 * Guides a new install from "plugin activated" to "first AI-answered call".
 * Every step that touches an external service has a live "Test" button.
 *
 * WordPress.org compliance notes (Guideline 8):
 * - This plugin does NOT download, install, or execute any binaries.
 * - Local Server mode detects an Ollama (or compatible) server the USER
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
                return TZFD_Addon_Support::info_block( __( 'The AI Setup Wizard appears in the Front Desk menu once an AI key is saved. Choose an AI option or add your key under Front Desk > Settings > AI.', 'titan-zero-trades-front-desk' ), array( __( 'Open AI settings', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tzfd_settings#ai' ) ) );
            }
            return TZFD_Addon_Support::info_block( __( 'Set up the AI assistant and its API key with the AI setup wizard.', 'titan-zero-trades-front-desk' ), array( __( 'Open the AI Setup Wizard', 'titan-zero-trades-front-desk' ) => admin_url( 'admin.php?page=tzfd-ai-setup' ) ) );
        }

        
        protected $addonID = "tzfd-AISetup";
        protected $name = "AI Setup Wizard";
        protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const STATE_OPTION = 'tzfd_setup_wizard_state';

        /**
         * The official Ollama landing page. We link to it; we never fetch
         * from it programmatically, never download from it, never execute
         * anything retrieved from it.
         */
        const OLLAMA_DOWNLOAD_URL = 'https://ollama.com/download';

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
                "Guided setup for the AI suite. Walks you from install to first answered call with a live test at every step. Works with an AI provider and your own API key, or a local Ollama server you install yourself.",
                'titan-zero-trades-front-desk'
            );
            // The AI page is always in the menu, so its menu entry is registered before the add-on switch is checked.
            add_action( 'admin_menu', array( &$this, 'add_menu' ), 100 );
            add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_wizard_assets' ) );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'admin_init',    array( &$this, 'handle_step_post' ) );
            add_action( 'wp_ajax_tzfd_detect_local', array( &$this, 'ajax_detect_local' ) );
            add_action( 'wp_ajax_tzfd_test_provider', array( &$this, 'ajax_test_provider' ) );
            add_action( 'admin_notices', array( &$this, 'nudge_if_not_setup' ) );

        }

        /* ============================================================
         *  Menu + notices
         * ============================================================ */

        /** Loads the AI step's script and its values on the wizard page. Replaces the inline script tags (R2). */
        public function enqueue_wizard_assets( $hook_suffix ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check, no change is made.
            $tzfd_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            if ( 'tzfd-ai-setup' !== $tzfd_page || ! current_user_can( 'manage_options' ) ) return;

            wp_enqueue_script(
                'tzfd-core-addons-installer',
                plugins_url( 'js/inline/core-addons-installer.js', dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk.php' ),
                array( 'jquery' ),
                '2.0.1',
                true
            );
            wp_localize_script( 'tzfd-core-addons-installer', 'tzfdCoreInstaller', array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'detectNonce' => wp_create_nonce( 'tzfd_detect_local' ),
                'testNonce'   => wp_create_nonce( 'tzfd_test_provider' ),
            ) );
        }

        public function add_menu() {
            // The AI page is always there, so the Front Desk menu's AI item opens it. Until a key is saved it
            // shows the set-up prompt; the guided wizard runs on its steps.
            add_submenu_page(
                'tzfd',
                __( 'AI', 'titan-zero-trades-front-desk' ),
                __( 'AI', 'titan-zero-trades-front-desk' ),
                'manage_options',
                'tzfd-ai-setup',
                array( &$this, 'render_wizard' )
            );
        }

        public function nudge_if_not_setup() {
            if ( ! current_user_can('manage_options') ) return;
            // Front Desk screens only.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check, no change is made.
            $tzfd_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            if ( 0 !== strpos( $tzfd_page, 'tzfd' ) && 0 !== strpos( $tzfd_page, 'titan-zero-trades-front-desk' ) ) return;
            if ( ! TZFD_Addon_Support::ai_configured() ) return;
            if ( get_option( self::STATE_OPTION ) === 'complete' ) return;
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
            if ( ! current_user_can('manage_options') ) return;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step selector.
            if ( ! isset( $_GET['step'] ) ) { $this->render_overview(); return; }

            $steps   = $this->steps();
            $state   = $this->get_state();
            $current = $state['step'] ?? 'welcome';
            if ( ! isset($steps[$current]) ) $current = 'welcome';

            $keys     = array_keys( $steps );
            $idx      = array_search( $current, $keys, true );
            $progress = $idx !== false ? round( ($idx / count($keys)) * 100 ) : 0;
            $flash    = get_transient( 'tzfd_setup_flash' );
            if ( $flash ) delete_transient( 'tzfd_setup_flash' );

            ?>
            <div class="wrap tzfd-wizard">
                <h1>🧠 <?php esc_html_e('AI Suite Setup','titan-zero-trades-front-desk'); ?></h1>

                <div style="background:#e5e7eb;height:8px;border-radius:4px;overflow:hidden;max-width:900px;margin-top:12px;">
                    <div style="height:100%;width:<?php echo intval($progress); ?>%;background:#2563eb;transition:width .3s;"></div>
                </div>
                <p style="color:#a4acba;margin:6px 0 0;">
                    <?php
                    /* translators: 1: current step number, 2: total number of steps. */
                    echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'titan-zero-trades-front-desk' ), (int) $idx + 1, (int) count( $keys ) ) ); ?>
                    — <?php echo esc_html( $steps[$current][0] ); ?>
                </p>

                <?php if ( $flash ): ?>
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
                <?php esc_html_e('This wizard connects an AI provider to the AI assistant and fills in your business basics. Voice calls, SMS, WhatsApp, Messenger, Telegram and email are not part of this version.', 'titan-zero-trades-front-desk'); ?>
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
                        <li><?php esc_html_e('An AI provider — hosted, your own key, or local', 'titan-zero-trades-front-desk'); ?></li>
                        <li><?php esc_html_e('Your business name, services and service area', 'titan-zero-trades-front-desk'); ?></li>
                    </ul>
                </div>
            </div>
            <p style="margin-top:20px;">
                <a class="button button-primary button-hero" href="<?php echo esc_url( $this->step_url('mode') ); ?>"><?php esc_html_e('Get started', 'titan-zero-trades-front-desk'); ?></a>
            </p>
            <?php
        }

        private function step_mode( $state ) {
            $mode = get_option('tzfd_ai_mode','');
            ?>
            <h2><?php esc_html_e('How should we power the AI?','titan-zero-trades-front-desk'); ?></h2>
            <p><?php esc_html_e('Choose how AI inference runs. You can switch at any time from the settings page.', 'titan-zero-trades-front-desk'); ?></p>

            <form method="post">
                <?php wp_nonce_field('tzfd_step_mode','tzfd_wizard_nonce'); ?>
                <input type="hidden" name="tzfd_step" value="mode" />

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">

                    

                    <!-- BYO Cloud -->
                    <label style="border:2px solid <?php echo $mode==='byo'?'#2563eb':'#e5e7eb'; ?>;border-radius:12px;padding:20px;cursor:pointer;display:block;">
                        <input type="radio" name="mode" value="byo" <?php checked($mode,'byo'); ?> style="float:right;" />
                        <h3 style="margin:0 0 8px;">🔑 <?php esc_html_e('Bring your own key','titan-zero-trades-front-desk'); ?></h3>
                        <p style="margin:0 0 12px;color:#a4acba;"><?php esc_html_e('Connect OpenAI, Anthropic, or any OpenAI-compatible provider, using your own key.', 'titan-zero-trades-front-desk'); ?></p>
                        <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.6;">
                            <li><?php esc_html_e('Works with the AI assistant on your booking page', 'titan-zero-trades-front-desk'); ?></li>
                        </ul>
                    </label>

                    <!-- Local Server -->
                    <label style="border:2px solid <?php echo $mode==='local'?'#2563eb':'#e5e7eb'; ?>;border-radius:12px;padding:20px;cursor:pointer;display:block;position:relative;">
                        <input type="radio" name="mode" value="local" <?php checked($mode,'local'); ?> style="float:right;" />
                        <h3 style="margin:0 0 8px;">🖥️ <?php esc_html_e('Local server (Ollama)','titan-zero-trades-front-desk'); ?></h3>
                        <p style="margin:0 0 12px;color:#a4acba;"><?php esc_html_e('Connect to an Ollama or compatible server running on your own machine or network.', 'titan-zero-trades-front-desk'); ?></p>
                        <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.6;">
                            <li><?php esc_html_e('Ollama is a separate product you install yourself', 'titan-zero-trades-front-desk'); ?></li>
                            <li><?php esc_html_e('Works with the AI assistant on your booking page', 'titan-zero-trades-front-desk'); ?></li>
                        </ul>
                    </label>

                </div>

                <p style="margin-top:20px;color:#a4acba;">
                    <strong><?php esc_html_e('Privacy note:', 'titan-zero-trades-front-desk'); ?></strong>
                    <?php esc_html_e('Depending on the mode you choose, prompts may be sent to your chosen provider (BYO) or to a server you control (Local). Full disclosure of all outbound endpoints is in the plugin readme.', 'titan-zero-trades-front-desk'); ?>
                </p>

                <p style="margin-top:20px;">
                    <button class="button button-primary button-hero" type="submit"><?php esc_html_e('Continue', 'titan-zero-trades-front-desk'); ?></button>
                </p>
            </form>
            <?php
        }

        /* ------------------------------------------------------------
         *  AI setup — four mode-specific forms
         * ------------------------------------------------------------ */

        private function step_ai_setup( $state ) {
            $mode = get_option('tzfd_ai_mode','byo');
            ?>
            <h2><?php esc_html_e('Connect the AI','titan-zero-trades-front-desk'); ?></h2>

            <?php if ( $mode === 'local' ) $this->form_local();
            else $this->form_byo();
        }

        /* -------------------- Local (Ollama) -------------------- */
        private function form_local() {
            $models = get_option('tzfd_ai_local_models_cache', array());
            ?>
            <p><?php esc_html_e('Connect the plugin to a local inference server you have already installed. The plugin does not download or install anything — it only talks to a server you control over HTTP.', 'titan-zero-trades-front-desk'); ?></p>

            <div style="background:#f0f6fc;border-left:4px solid #2563eb;padding:14px;margin:16px 0;">
                <h3 style="margin:0 0 8px;"><?php esc_html_e('Step 1 — Install a local server (you do this once)', 'titan-zero-trades-front-desk'); ?></h3>
                <p style="margin:0 0 8px;"><?php esc_html_e('The plugin works with any OpenAI-compatible local server. Ollama is the easiest to set up:', 'titan-zero-trades-front-desk'); ?></p>
                <ol style="line-height:1.8;margin:0;">
                    <li>
                        <?php echo wp_kses_post( sprintf(
                            /* translators: %s: link to the Ollama download page. */
                            esc_html__('Download Ollama from %s and install it on this server (or another machine on your network).', 'titan-zero-trades-front-desk'),
                            '<a href="'.esc_url( self::OLLAMA_DOWNLOAD_URL ).'" target="_blank" rel="noopener noreferrer">ollama.com/download</a>'
                        ) ); ?>
                    </li>
                    <li>
                        <?php esc_html_e('Open a terminal on that machine and run:', 'titan-zero-trades-front-desk'); ?>
                        <pre style="background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;margin:6px 0;overflow-x:auto;">ollama pull llama3.2</pre>
                    </li>
                    <li>
                        <?php esc_html_e('Ollama starts automatically. Confirm it is running with:', 'titan-zero-trades-front-desk'); ?>
                        <pre style="background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;margin:6px 0;overflow-x:auto;">curl http://localhost:11434/api/tags</pre>
                    </li>
                </ol>
                <p style="margin:12px 0 0;color:#a4acba;">
                    <em><?php esc_html_e('That is the entire install. You do it once, outside WordPress. This plugin never downloads or executes anything.', 'titan-zero-trades-front-desk'); ?></em>
                </p>
            </div>

            <div style="background:#f0f6fc;border-left:4px solid #2563eb;padding:14px;margin:16px 0;">
                <h3 style="margin:0 0 8px;"><?php esc_html_e('Step 2 — Point the plugin at it', 'titan-zero-trades-front-desk'); ?></h3>

                <form method="post">
                    <?php wp_nonce_field('tzfd_step_ai_setup','tzfd_wizard_nonce'); ?>
                    <input type="hidden" name="tzfd_step" value="ai_setup" />
                    <input type="hidden" name="mode" value="local" />

                    <table class="form-table">
                        <tr><th><?php esc_html_e('Server type','titan-zero-trades-front-desk'); ?></th>
                            <td>
                                <select id="tzfd_local_kind">
                                    <option value="ollama"    <?php selected( get_option('tzfd_ai_local_kind','ollama'), 'ollama' ); ?>>Ollama</option>
                                    <option value="lmstudio"  <?php selected( get_option('tzfd_ai_local_kind','ollama'), 'lmstudio' ); ?>>LM Studio</option>
                                    <option value="vllm"      <?php selected( get_option('tzfd_ai_local_kind','ollama'), 'vllm' ); ?>>vLLM</option>
                                    <option value="localai"   <?php selected( get_option('tzfd_ai_local_kind','ollama'), 'localai' ); ?>>LocalAI</option>
                                    <option value="custom"    <?php selected( get_option('tzfd_ai_local_kind','ollama'), 'custom' ); ?>>Custom OpenAI-compatible</option>
                                </select>
                            </td></tr>
                        <tr><th><?php esc_html_e('Server URL','titan-zero-trades-front-desk'); ?></th>
                            <td>
                                <input type="text" name="local_base" id="tzfd_local_base" size="45"
                                       value="<?php echo esc_attr( get_option('tzfd_ai_local_base', 'http://localhost:11434') ); ?>" />
                                <button type="button" class="button tzfd-detect-local" style="margin-left:8px;">
                                    <?php esc_html_e('Detect server', 'titan-zero-trades-front-desk'); ?>
                                </button>
                                <p><em>
                                    <?php esc_html_e('Default ports:', 'titan-zero-trades-front-desk'); ?>
                                    Ollama 11434 · LM Studio 1234 · vLLM 8000 · LocalAI 8080.
                                    <?php esc_html_e('If the plugin runs on a different machine, use its LAN IP or a Tailscale hostname.', 'titan-zero-trades-front-desk'); ?>
                                </em></p>
                            </td></tr>
                        <tr><th><?php esc_html_e('Model','titan-zero-trades-front-desk'); ?></th>
                            <td>
                                <select name="local_model" id="tzfd_local_model" style="min-width:320px;">
                                    <?php if ( $models ): ?>
                                        <?php foreach ( $models as $m ): ?>
                                            <option value="<?php echo esc_attr($m); ?>" <?php selected( get_option('tzfd_ai_local_model'), $m ); ?>><?php echo esc_html($m); ?></option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="<?php echo esc_attr( get_option('tzfd_ai_local_model','') ); ?>">
                                            <?php echo esc_html( get_option('tzfd_ai_local_model','') ?: __('— click "Detect server" —', 'titan-zero-trades-front-desk') ); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                                <p><em>
                                    <?php esc_html_e('Examples: llama3.2, qwen2.5:7b, or mistral.', 'titan-zero-trades-front-desk'); ?>
                                </em></p>
                            </td></tr>
                        <tr><th><?php esc_html_e('API key (optional)','titan-zero-trades-front-desk'); ?></th>
                            <td>
                                <input type="password" name="local_key" size="40" autocomplete="new-password" />
                                <p><em><?php esc_html_e('Blank for a default Ollama install. Set this if your server requires authentication.', 'titan-zero-trades-front-desk'); ?></em></p>
                            </td></tr>
                    </table>

                    <p>
                        <button class="button button-primary button-hero" type="submit"><?php esc_html_e('Save + test connection','titan-zero-trades-front-desk'); ?></button>
                        <button class="button tzfd-test-provider" type="button" style="margin-left:8px;">
                            <?php esc_html_e('Test only','titan-zero-trades-front-desk'); ?>
                        </button>
                    </p>
                    <div class="tzfd-test-result" data-for="local" style="margin-top:8px;"></div>
                </form>
            </div>

            <p style="font-size:12px;color:#a4acba;">
                <strong><?php esc_html_e('Outbound endpoints used in this mode:', 'titan-zero-trades-front-desk'); ?></strong>
                <?php esc_html_e('Only the server address you specify above receives requests. No data is sent to the plugin author.', 'titan-zero-trades-front-desk'); ?>
            </p>

            <?php
        }

        /* -------------------- BYO Cloud -------------------- */
        private function form_byo() {
            $p = get_option('tzfd_ai_provider','openai');
            ?>
            <p><?php esc_html_e('Connect OpenAI, Anthropic, or any OpenAI-compatible provider.', 'titan-zero-trades-front-desk'); ?></p>
            <form method="post">
                <?php wp_nonce_field('tzfd_step_ai_setup','tzfd_wizard_nonce'); ?>
                <input type="hidden" name="tzfd_step" value="ai_setup" />
                <input type="hidden" name="mode" value="byo" />
                <table class="form-table">
                    <tr><th><?php esc_html_e('Provider','titan-zero-trades-front-desk'); ?></th>
                        <td>
                            <select name="provider" id="tzfd_provider_pick">
                                <option value="openai"    <?php selected($p,'openai'); ?>>OpenAI</option>
                                <option value="anthropic" <?php selected($p,'anthropic'); ?>>Anthropic (Claude)</option>
                                <option value="custom"    <?php selected($p,'custom'); ?>>Custom / OpenAI-compatible</option>
                            </select>
                        </td></tr>
                    <tr><th><?php esc_html_e('Model','titan-zero-trades-front-desk'); ?></th>
                        <td><input type="text" name="model" size="40" value="<?php echo esc_attr( get_option('tzfd_ai_model', 'gpt-4o-mini') ); ?>" />
                            <p><em><?php esc_html_e('Examples: gpt-4o-mini · claude-sonnet-5-5 · openai/gpt-oss-120b', 'titan-zero-trades-front-desk'); ?></em></p></td></tr>
                    <tr><th><?php esc_html_e('API key','titan-zero-trades-front-desk'); ?></th>
                        <td><input type="password" name="api_key" size="50" autocomplete="new-password" />
                            <p><em><?php esc_html_e('Stored encrypted on your server using your WordPress AUTH_KEY.', 'titan-zero-trades-front-desk'); ?></em></p></td></tr>
                    <tr class="tzfd-custom-base"><th><?php esc_html_e('API base URL','titan-zero-trades-front-desk'); ?></th>
                        <td><input type="text" name="api_base" size="50" value="<?php echo esc_attr( get_option('tzfd_ai_api_base','') ); ?>" placeholder="https://" /></td></tr>
                </table>
                <p>
                    <button class="button button-primary button-hero" type="submit"><?php esc_html_e('Save + test connection','titan-zero-trades-front-desk'); ?></button>
                    <button class="button tzfd-test-provider" type="button" style="margin-left:8px;"><?php esc_html_e('Test only','titan-zero-trades-front-desk'); ?></button>
                </p>
                <div class="tzfd-test-result" data-for="byo" style="margin-top:8px;"></div>
            </form>
            <?php
        }

        private function step_done( $state ) {
            ?>
            <h2><?php esc_html_e('AI assistant set up','titan-zero-trades-front-desk'); ?></h2>
            <p><?php esc_html_e('Your AI provider and business basics are saved. The AI assistant uses them on your booking page once it is switched on in Add-ons.', 'titan-zero-trades-front-desk'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ADDONS ) ); ?>"><?php esc_html_e('Open Add-ons','titan-zero-trades-front-desk'); ?></a>
                <a class="button" href="<?php echo esc_url( $this->step_url('ai_setup') ); ?>"><?php esc_html_e('Provider settings','titan-zero-trades-front-desk'); ?></a>
            </p>
            <?php
        }

        private function step_business( $state ) {
            $tzfd_d = get_option('tzfd_business_profile', array());
            ?>
            <h2><?php esc_html_e('Business basics','titan-zero-trades-front-desk'); ?></h2>
            <p><?php esc_html_e('The AI uses these to answer questions and gate service areas.', 'titan-zero-trades-front-desk'); ?></p>
            <form method="post">
                <?php wp_nonce_field('tzfd_step_business','tzfd_wizard_nonce'); ?>
                <input type="hidden" name="tzfd_step" value="business" />
                <table class="form-table">
                    <tr><th><?php esc_html_e('Business name','titan-zero-trades-front-desk'); ?></th>
                        <td><input type="text" name="biz_name" size="40" value="<?php echo esc_attr( $tzfd_d['name'] ?? get_bloginfo('name') ); ?>" /></td></tr>
                    <tr><th><?php esc_html_e('Services offered','titan-zero-trades-front-desk'); ?></th>
                        <td><input type="text" name="biz_services" size="60" value="<?php echo esc_attr( $tzfd_d['services'] ?? 'Plumbing, HVAC, Electrical, Locksmith, Pest control, Cleaning' ); ?>" /></td></tr>
                    <tr><th><?php esc_html_e('Service area','titan-zero-trades-front-desk'); ?></th>
                        <td><textarea name="biz_area" rows="3" cols="60"><?php echo esc_textarea( $tzfd_d['area'] ?? '' ); ?></textarea></td></tr>
                    <tr><th><?php esc_html_e('Timezone','titan-zero-trades-front-desk'); ?></th>
                        <td><input type="text" name="biz_tz" size="30" value="<?php echo esc_attr( $tzfd_d['timezone'] ?? wp_timezone_string() ); ?>" /></td></tr>
                </table>
                <p><button class="button button-primary" type="submit"><?php esc_html_e('Save + continue','titan-zero-trades-front-desk'); ?></button></p>
            </form>
            <?php
        }

        /* ============================================================
         *  Post handling + savers
         * ============================================================ */

        /**
         * One POST field, unslashed. Returns $default when the field is absent or not a string.
         * Every caller passes the value through a sanitiser before storing it.
         */
        private function post_raw( $key, $default = '' ) {
            // $this->request is only set after a nonce check (handle_step_post and ajax_detect_local).
            if ( ! isset( $this->request[ $key ] ) || ! is_string( $this->request[ $key ] ) ) { return $default; }
            return $this->request[ $key ];
        }

        public function handle_step_post() {
            if ( ! isset($_POST['tzfd_step'], $_POST['tzfd_wizard_nonce']) ) return;
            if ( ! current_user_can('manage_options') ) return;
            $this->request = wp_unslash( $_POST );
            $step = sanitize_key( $this->post_raw('tzfd_step') );
            $tzfd_nonce = sanitize_text_field( $this->post_raw('tzfd_wizard_nonce') );
            if ( ! wp_verify_nonce( $tzfd_nonce, 'tzfd_step_'.$step ) ) return;

            $saver = 'save_'.$step;
            if ( method_exists($this, $saver) ) $this->$saver();
            $this->advance( $step );
        }

        private function save_mode() {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tzfd_wizard_nonce'] ?? '' ) ), 'tzfd_step_mode' ) ) { return; }
            $allowed = array('byo','local');
            $mode = in_array( $this->post_raw('mode', ''), $allowed, true ) ? $this->post_raw('mode') : 'byo';
            update_option( 'tzfd_ai_mode', $mode );
        }

        private function save_ai_setup() {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tzfd_wizard_nonce'] ?? '' ) ), 'tzfd_step_ai_setup' ) ) { return; }
            $mode = sanitize_text_field( $this->post_raw('mode', get_option('tzfd_ai_mode','byo')) );

            if ( $mode === 'local' ) {
                update_option( 'tzfd_ai_local_kind',  sanitize_key( $this->post_raw('local_kind', 'ollama') ) );
                update_option( 'tzfd_ai_local_base',  esc_url_raw( $this->post_raw('local_base', ''), array( 'http', 'https' ) ) );
                update_option( 'tzfd_ai_local_model', sanitize_text_field( $this->post_raw('local_model', '') ) );
                if ( ! empty($_POST['local_key']) ) {
                    update_option( 'tzfd_ai_local_key', $this->encrypt( sanitize_text_field( $this->post_raw('local_key') ) ) );
                }
                // Map onto the assistant's OpenAI-compatible settings so the assistant,
                // The AI assistant talks to this server.
                $local_base  = rtrim( esc_url_raw( $this->post_raw('local_base', ''), array( 'http', 'https' ) ), '/' );
                $local_model = sanitize_text_field( $this->post_raw('local_model', '') );
                if ( ! $local_base || ! $local_model ) {
                    $this->flash( false, __('Enter the server address and pick a model first.', 'titan-zero-trades-front-desk') );
                    return;
                }
                update_option( 'tzfd_ai_provider', 'openai' );
                update_option( 'tzfd_ai_model', $local_model );
                update_option( 'tzfd_ai_api_base', $local_base.'/v1' );
                // Local servers ignore the bearer token; a placeholder keeps the provider active.
                $local_key = ! empty($_POST['local_key']) ? sanitize_text_field( $this->post_raw('local_key') ) : 'local';
                update_option( 'tzfd_ai_api_key', $this->encrypt( $local_key ) );
                return;
            }

            // BYO
            update_option( 'tzfd_ai_provider', sanitize_key( $this->post_raw('provider', 'openai') ) );
            update_option( 'tzfd_ai_model',    sanitize_text_field( $this->post_raw('model', '') ) );
            update_option( 'tzfd_ai_api_base', esc_url_raw( $this->post_raw('api_base', ''), array( 'http', 'https' ) ) );
            if ( ! empty($_POST['api_key']) ) {
                update_option( 'tzfd_ai_api_key', $this->encrypt( sanitize_text_field( $this->post_raw('api_key') ) ) );
            }
        }

        private function save_business() {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tzfd_wizard_nonce'] ?? '' ) ), 'tzfd_step_business' ) ) { return; }
            update_option( 'tzfd_business_profile', array(
                'name'      => sanitize_text_field( $this->post_raw('biz_name', '') ),
                'services'  => sanitize_text_field( $this->post_raw('biz_services', '') ),
                'area'      => sanitize_textarea_field( $this->post_raw('biz_area', '') ),
                'timezone'  => sanitize_text_field( $this->post_raw('biz_tz', '') ),
            ) );
            update_option( 'tzfd_ai_service_area', sanitize_textarea_field( $this->post_raw('biz_area', '') ) );
            update_option( 'tzfd_ai_services',     sanitize_text_field( $this->post_raw('biz_services', '') ) );
        }

        /* ============================================================
         *  AJAX handlers
         * ============================================================ */

        /**
         * Detect a local inference server. This function ONLY makes outbound
         * HTTP GET requests, to the probe path of a listed server type at a URL
         * the admin has entered. It never downloads, writes, or executes anything.
         * It runs only on the admin's own click (nonce and manage_options checked below).
         */
        public function ajax_detect_local() {
            check_ajax_referer( 'tzfd_detect_local' );
            if ( ! current_user_can('manage_options') ) wp_send_json_error( array('error'=>'Forbidden'), 403 );
            $this->request = wp_unslash( $_POST );

            $base = esc_url_raw( $this->post_raw('base', ''), array( 'http', 'https' ) );
            $kind = sanitize_key( $this->post_raw('kind', 'ollama') );
            if ( ! $base ) wp_send_json_error( array('error'=>'No server URL provided.') );

            // SSRF guard — only allow http/https, refuse internal metadata IPs
            $parts = wp_parse_url( $base );
            if ( ! $parts || ! in_array( $parts['scheme'] ?? '', array('http','https'), true ) ) {
                wp_send_json_error( array('error'=>'Only http:// and https:// URLs are allowed.') );
            }
            $host = strtolower( $parts['host'] ?? '' );
            if ( in_array( $host, array('169.254.169.254','metadata.google.internal'), true ) ) {
                wp_send_json_error( array('error'=>'That host is not allowed.') );
            }

            $probe = $this->local_probe_path( $kind );
            if ( '' === $probe ) wp_send_json_error( array('error'=>'Unknown server type. Choose one of the server types in the list.') );
            $url   = rtrim( $base, '/' ) . $probe;

            // Plain wp_remote_get() on purpose: local servers sit on loopback or a LAN address, which
            // wp_safe_remote_get() refuses. The host is the admin's own entry (see the SSRF guard above).
            // Redirects are not followed, so a reply cannot send the probe to another host.
            $resp = wp_remote_get( $url, array(
                'timeout'     => 10,
                'redirection' => 0,
                'headers'     => array( 'Accept' => 'application/json' ),
            ) );

            if ( is_wp_error( $resp ) ) {
                // Try the OpenAI-compatible endpoint as a fallback
                // Same plain request as above, for the OpenAI-compatible probe.
                $resp2 = wp_remote_get( rtrim($base,'/').'/v1/models', array('timeout'=>10, 'redirection'=>0) );
                if ( is_wp_error($resp2) ) {
                    wp_send_json_error( array('error'=>'Could not reach the server. Is it running at ' . $base . '?') );
                }
                $resp = $resp2;
            }

            $code = wp_remote_retrieve_response_code( $resp );
            if ( $code >= 400 ) {
                wp_send_json_error( array('error'=>'Server responded with HTTP ' . intval($code) . '. Check the URL and try again.') );
            }

            $tzfd_data   = json_decode( wp_remote_retrieve_body( $resp ), true );
            $models = array();

            // Ollama shape: { models: [ { name: "llama3.2" } ] }
            if ( isset( $tzfd_data['models'] ) && is_array( $tzfd_data['models'] ) ) {
                foreach ( $tzfd_data['models'] as $m ) {
                    if ( isset($m['name']) ) $models[] = $m['name'];
                }
            }
            // OpenAI shape: { data: [ { id: "llama3.2" } ] }
            if ( empty( $models ) && isset( $tzfd_data['data'] ) && is_array( $tzfd_data['data'] ) ) {
                foreach ( $tzfd_data['data'] as $m ) {
                    if ( isset($m['id']) ) $models[] = $m['id'];
                }
            }

            if ( empty( $models ) ) {
                wp_send_json_error( array('error'=>'Server reachable but no models are installed. On the server, run: ollama pull llama3.2') );
            }

            // Recommend a sensible default
            $recommended = '';
            foreach ( array('llama3.2','qwen2.5:7b','mistral','gemma2','llama3') as $pref ) {
                foreach ( $models as $m ) {
                    if ( stripos( $m, $pref ) !== false ) { $recommended = $m; break 2; }
                }
            }
            if ( ! $recommended ) $recommended = $models[0];

            // Cache the model list for the admin form
            update_option( 'tzfd_ai_local_models_cache', $models, false );

            wp_send_json_success( array(
                'models'      => $models,
                'recommended' => $recommended,
                'server'      => $base,
            ) );
        }

        /** Same resolution the assistant uses: filter first, then the assistant's configured provider. */
        private static function resolve_provider() {
            $p = apply_filters( 'tzfd_ai_provider', null );
            if ( $p instanceof TZFD_AI_Provider ) return $p;
            global $tzfd_addons_objs_list;
            $assistant = $tzfd_addons_objs_list['tzfd-AIAssistant'] ?? null;
            return ( $assistant && method_exists( $assistant, 'get_provider' ) ) ? $assistant->get_provider() : null;
        }

        /** Fire a tiny round-trip against the currently configured provider. */
        public function ajax_test_provider() {
            check_ajax_referer( 'tzfd_test_provider' );
            if ( ! current_user_can('manage_options') ) wp_send_json_error( array('message'=>'Forbidden'), 403 );

            $provider = self::resolve_provider();
            if ( ! $provider ) {
                wp_send_json_error( array('message'=>'No AI provider is configured yet.') );
            }
            $t0  = microtime(true);
            $res = $provider->chat( array(
                array( 'role' => 'system', 'content' => 'Reply with exactly the word OK.' ),
                array( 'role' => 'user',   'content' => 'Ping.' ),
            ), array(), array( 'max_tokens' => 10 ) );
            $ms  = round( (microtime(true) - $t0) * 1000 );

            if ( ! empty($res['error']) ) {
                wp_send_json_error( array('message'=>'Provider error: ' . $res['error']) );
            }
            $text = trim( $res['content'] ?? '' );
            wp_send_json_success( array(
                'message' => sprintf( 'OK — replied "%s" in %d ms.', mb_substr($text, 0, 40), $ms ),
            ) );
        }

        /* ============================================================
         *  Utilities
         * ============================================================ */

        private function get_state() {
            $state = get_option( self::STATE_OPTION, array() );
            if ( ! is_array($state) ) $state = array();
            if ( empty($state['step']) ) $state['step'] = 'welcome';
            return $state;
        }

        private function advance( $from ) {
            $steps = $this->steps();
            $keys  = array_keys($steps);
            $tzfd_i     = array_search( $from, $keys, true );
            $next  = $tzfd_i !== false && isset($keys[$tzfd_i+1]) ? $keys[$tzfd_i+1] : $from;
            update_option( self::STATE_OPTION, array( 'step'=>$next, 'updated'=>time() ) );
            wp_safe_redirect( $this->step_url( $next ) );
            exit;
        }

        /**
         * AI assistant page, as drawn in the mock-up: header, a set-up banner until a key is saved, and cards for the
         * receptionist, how replies are handled, and the booking assistant add-on. The guided wizard opens from here.
         */
        private function render_overview() {
            $connected = TZFD_Addon_Support::ai_configured();
            $provider  = (string) get_option( 'tzfd_ai_provider', 'openai' );
            $key_saved = '' !== (string) get_option( 'tzfd_ai_api_key', '' );
            $addons_url = admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ADDONS );
            echo '<div class="wrap tzfd-admin-page tzfd-page tzfd-page-ai">';
            TZFD_Admin_Layout::header(
                __( 'AI assistant', 'titan-zero-trades-front-desk' ),
                __( 'Connect your AI provider and decide how the assistant supports booking enquiries.', 'titan-zero-trades-front-desk' ),
                '<a class="tzfd-btn tzfd-btn-primary" href="' . esc_url( $this->step_url( 'welcome' ) ) . '">' . esc_html__( 'Configure AI', 'titan-zero-trades-front-desk' ) . '</a>'
            );
            if ( ! $connected ) {
                echo '<div class="tzfd-card tzfd-banner-warn tzfd-banner"><div><strong>' . esc_html__( 'Bring your own AI provider key', 'titan-zero-trades-front-desk' ) . '</strong>';
                echo '<p class="tzfd-muted">' . esc_html__( 'Add a key to turn on AI replies. Keys are stored encrypted.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $this->step_url( 'ai_setup' ) ) . '">' . esc_html__( 'Provider settings', 'titan-zero-trades-front-desk' ) . '</a></div>';
            }
            echo '<div class="tzfd-dash-grid">';
            echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2>' . esc_html__( 'AI receptionist', 'titan-zero-trades-front-desk' ) . '</h2>';
            echo $connected ? '<span class="tzfd-badge tzfd-badge-active">' . esc_html__( 'CONNECTED', 'titan-zero-trades-front-desk' ) . '</span>' : '<span class="tzfd-badge tzfd-badge-pending">' . esc_html__( 'NOT CONNECTED', 'titan-zero-trades-front-desk' ) . '</span>';
            echo '</div><dl class="tzfd-dl">';
            echo '<span>' . esc_html__( 'Provider', 'titan-zero-trades-front-desk' ) . '</span><span>' . esc_html( 'openai' === $provider ? 'OpenAI' : ucfirst( $provider ) ) . '</span>';
            echo '<span>' . esc_html__( 'API key', 'titan-zero-trades-front-desk' ) . '</span><span>' . ( $key_saved ? esc_html__( 'Saved, encrypted', 'titan-zero-trades-front-desk' ) : esc_html__( 'Not saved', 'titan-zero-trades-front-desk' ) ) . '</span>';
            echo '</dl><div class="tzfd-actions"><a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $this->step_url( 'ai_setup' ) ) . '">' . esc_html__( 'Test connection', 'titan-zero-trades-front-desk' ) . '</a></div></div>';

            echo '<div class="tzfd-side-col"><div class="tzfd-card"><h2>' . esc_html__( 'Response behaviour', 'titan-zero-trades-front-desk' ) . '</h2>';
            echo '<p class="tzfd-muted">' . esc_html__( 'The assistant answers enquiries and asks for the details it needs. When a caller needs a person, it emails the site admin.', 'titan-zero-trades-front-desk' ) . '</p>';
            echo '<p><span class="tzfd-badge tzfd-badge-sent">' . esc_html__( 'Answers enquiries', 'titan-zero-trades-front-desk' ) . '</span> <span class="tzfd-badge tzfd-badge-other">' . esc_html__( 'Escalates to a person', 'titan-zero-trades-front-desk' ) . '</span></p></div>';
            echo '<div class="tzfd-card"><div class="tzfd-card-head"><h2>' . esc_html__( 'AI booking assistant', 'titan-zero-trades-front-desk' ) . '</h2><span class="tzfd-badge tzfd-badge-sent">' . esc_html__( 'ADD-ON', 'titan-zero-trades-front-desk' ) . '</span></div>';
            echo '<p class="tzfd-muted">' . esc_html__( 'Helps customers find a service and send a booking request. Optional, and switched on under Add-ons.', 'titan-zero-trades-front-desk' ) . '</p>';
            echo '<a class="tzfd-btn tzfd-btn-secondary" href="' . esc_url( $addons_url ) . '">' . esc_html__( 'View add-ons', 'titan-zero-trades-front-desk' ) . '</a></div></div>';
            echo '</div></div>';
        }

        private function step_url( $step ) {
            return admin_url('admin.php?page=tzfd-ai-setup&step='.rawurlencode($step));
        }

        private function flash( $ok, $msg ) {
            set_transient( 'tzfd_setup_flash', array( 'ok'=>$ok, 'msg'=>$msg ), 60 );
        }

        private function encrypt( $plain ) {
            return TZFD_Addon_Support::secret_encrypt( $plain );
        }

    }

    $TZFD_Setup_Wizard_obj = new TZFD_Setup_Wizard();
    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $TZFD_Setup_Wizard_obj->get_addon_id() ] = $TZFD_Setup_Wizard_obj;
}
