<?php
/**
 * AI Services admin view inside the one installed plugin (Free / Assistant / Agent Team).
 *
 * Shows status, the descriptor comparison, the local downgrade and the hosted connector controls:
 * consent (grant and revoke), opt-in connect and disconnect, event retention and the redacted event log (#4, #261).
 * It does not take payment, card details or account credentials, and it makes no network calls.
 * Connecting only stores the endpoint, site ID and secret the administrator types in; it sends nothing.
 * The connector secret is never rendered here; only its stored/not-stored flag is shown.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-ai-services.php';
require_once __DIR__ . '/class-tzfd-ai-connector.php';

final class TZFD_AI_Services_Page {
    const SLUG   = 'tzfd-ai-services';
    const ACTION = 'tzfd_ai_services_local_downgrade';
    const NONCE  = 'tzfd_ai_services_local_downgrade';

    const ACTION_CONSENT    = 'tzfd_ai_connector_consent';
    const ACTION_REVOKE     = 'tzfd_ai_connector_revoke';
    const ACTION_RETENTION  = 'tzfd_ai_connector_retention';
    const ACTION_CLEAR      = 'tzfd_ai_connector_clear_events';
    const ACTION_CONNECT    = 'tzfd_ai_connector_connect';
    const ACTION_DISCONNECT = 'tzfd_ai_connector_disconnect';
    const CONNECTOR_NONCE   = 'tzfd_ai_connector_admin';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 12 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_local_downgrade' ) );
        add_action( 'admin_post_' . self::ACTION_CONSENT, array( __CLASS__, 'handle_consent' ) );
        add_action( 'admin_post_' . self::ACTION_REVOKE, array( __CLASS__, 'handle_revoke' ) );
        add_action( 'admin_post_' . self::ACTION_RETENTION, array( __CLASS__, 'handle_retention' ) );
        add_action( 'admin_post_' . self::ACTION_CLEAR, array( __CLASS__, 'handle_clear_events' ) );
        add_action( 'admin_post_' . self::ACTION_CONNECT, array( __CLASS__, 'handle_connect' ) );
        add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );
    }

    /**
     * Opt-in pairing. Stores the endpoint, site ID and secret only when the administrator submits this form.
     * Pairing grants no consent: consent is cleared for the new pairing and must be ticked again.
     */
    public static function handle_connect() {
        self::guard_connector_request();
        $in = self::normalise_connect( array(
            'endpoint' => self::posted_text( 'endpoint' ),
            'site_id'  => self::posted_text( 'site_id' ),
            'secret'   => self::posted_text( 'secret' ),
        ) );
        if ( false === $in || ! TZFD_AI_Connector::configure( $in['endpoint'], $in['site_id'], $in['secret'] ) ) {
            self::redirect_notice( 'connect_invalid' );
        }
        TZFD_AI_Connector::revoke_consent();
        self::redirect_notice( 'connect_saved' );
    }

    /** Removes the endpoint, secret, consent and entitlements. Usage history is kept. Nothing is sent. */
    public static function handle_disconnect() {
        self::guard_connector_request();
        TZFD_AI_Connector::disconnect();
        self::redirect_notice( 'disconnected' );
    }

    /**
     * Checks the posted connection fields. Pure function, no WordPress calls. Returns false when a field is missing,
     * not a string or blank. The connector checks the endpoint scheme, site ID and secret length.
     *
     * @param array $raw Submitted values (untrusted), keys endpoint, site_id, secret.
     * @return array|false
     */
    public static function normalise_connect( array $raw ) {
        $out = array();
        foreach ( array( 'endpoint', 'site_id', 'secret' ) as $key ) {
            if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) { return false; }
            $value = trim( $raw[ $key ] );
            if ( '' === $value ) { return false; }
            $out[ $key ] = $value;
        }
        return $out;
    }

    /** Reads one posted text field. Returns '' when it is absent or not a string. */
    private static function posted_text( $key ) {
        if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { return ''; } // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return (string) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    public static function add_page() {
        add_submenu_page(
            'tzfd',
            __( 'AI Services', 'titan-zero-trades-front-desk' ),
            __( 'AI Services', 'titan-zero-trades-front-desk' ),
            'tz_fd_access',
            self::SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /** Forget the cached hosted entitlement on this site. Local data and features are untouched. */
    public static function handle_local_downgrade() {
        if ( ! current_user_can( 'tz_fd_access' ) ) {
            wp_die( esc_html__( 'You are not allowed to change AI Services.', 'titan-zero-trades-front-desk' ), 403 );
        }
        check_admin_referer( self::NONCE );
        delete_option( TZFD_AI_Services::OPTION );
        wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tzfd_ai' => 'local_free' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Records consent for the scopes the administrator ticked. Nothing is granted by default. */
    public static function handle_consent() {
        self::guard_connector_request();
        $raw    = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? wp_unslash( $_POST['scopes'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $scopes = self::normalise_scopes( $raw, self::allowed_scopes() );
        if ( array() === $scopes ) {
            self::redirect_notice( 'consent_none' );
        }
        TZFD_AI_Connector::grant_consent( $scopes );
        self::redirect_notice( 'consent_saved' );
    }

    /** Immediate revocation. The connector makes no transport call after this. */
    public static function handle_revoke() {
        self::guard_connector_request();
        TZFD_AI_Connector::revoke_consent();
        self::redirect_notice( 'consent_revoked' );
    }

    public static function handle_retention() {
        self::guard_connector_request();
        $raw  = isset( $_POST['retention_days'] ) ? wp_unslash( $_POST['retention_days'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $days = self::normalise_retention( $raw );
        if ( false === $days || ! TZFD_AI_Connector::set_retention_days( $days ) ) {
            self::redirect_notice( 'retention_invalid' );
        }
        self::redirect_notice( 'retention_saved' );
    }

    public static function handle_clear_events() {
        self::guard_connector_request();
        TZFD_AI_Connector::clear_events();
        self::redirect_notice( 'events_cleared' );
    }

    public static function render() {
        if ( ! current_user_can( 'tz_fd_access' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this page.', 'titan-zero-trades-front-desk' ), 403 );
        }
        $payload  = get_option( TZFD_AI_Services::OPTION, null );
        $resolved = TZFD_AI_Services::resolve( $payload );
        $desc     = TZFD_AI_Services::descriptors();
        $levels   = isset( $desc['levels'] ) && is_array( $desc['levels'] ) ? $desc['levels'] : array();
        $notice   = isset( $_GET['tzfd_ai'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_ai'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status   = TZFD_AI_Connector::status();
        $notices  = self::notice_texts();
        $setup    = TZFD_AI_Connector::setup_notice();
        ?>
        <div class="wrap tzfd-ai-services">
            <h1><?php esc_html_e( 'AI Services', 'titan-zero-trades-front-desk' ); ?></h1>
            <p><?php esc_html_e( 'Scheduling & Enquiries Manager is one plugin. Booking, customers, calendars, rules, FAQ and reports always work on this site. Hosted AI is optional.', 'titan-zero-trades-front-desk' ); ?></p>

            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Current status', 'titan-zero-trades-front-desk' ); ?></h2>
            <table class="widefat striped" style="max-width:52em">
                <tbody>
                    <tr><th scope="row"><?php esc_html_e( 'Hosted level', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php echo esc_html( self::label_for( $resolved['level'], $levels ) ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Reason', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php echo esc_html( TZFD_AI_Services::reason_text( $resolved['reason'] ) ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Local features', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php esc_html_e( 'Always enabled', 'titan-zero-trades-front-desk' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Hosted data consent', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php
                            if ( $status['consent'] ) {
                                echo esc_html( sprintf(
                                    /* translators: %s: comma-separated list of data classes */
                                    __( 'Granted for: %s', 'titan-zero-trades-front-desk' ),
                                    implode( ', ', $status['scopes'] )
                                ) );
                            } else {
                                esc_html_e( 'None recorded. Hosted processing stays off until you agree to it.', 'titan-zero-trades-front-desk' );
                            }
                        ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Hosted AI endpoint', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php echo $status['endpoint_set'] ? esc_html__( 'Set', 'titan-zero-trades-front-desk' ) : esc_html__( 'Not set. Free features work without it.', 'titan-zero-trades-front-desk' ); ?></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Hosted levels', 'titan-zero-trades-front-desk' ); ?></h2>
            <table class="widefat striped" style="max-width:52em">
                <thead><tr>
                    <th scope="col"><?php esc_html_e( 'Level', 'titan-zero-trades-front-desk' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'What it adds', 'titan-zero-trades-front-desk' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $levels as $key => $level ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $level['label'] ); ?></td>
                        <td><?php echo esc_html( (string) $level['disclosure'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Hosted AI connection', 'titan-zero-trades-front-desk' ); ?></h2>
            <?php if ( $status['endpoint_set'] || $status['secret_set'] ) : ?>
                <p><?php esc_html_e( 'This site is paired with the hosted service. The secret is stored and is never shown.', 'titan-zero-trades-front-desk' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISCONNECT ); ?>" />
                    <?php wp_nonce_field( self::CONNECTOR_NONCE ); ?>
                    <p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Disconnect', 'titan-zero-trades-front-desk' ); ?></button>
                        <span class="description"><?php esc_html_e( 'Removes the secret and endpoint and clears consent. Local features are unchanged.', 'titan-zero-trades-front-desk' ); ?></span></p>
                </form>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'Pairing is optional and sends nothing by itself. Hosted requests still need consent below.', 'titan-zero-trades-front-desk' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONNECT ); ?>" />
                    <?php wp_nonce_field( self::CONNECTOR_NONCE ); ?>
                    <p>
                        <label for="tzfd-ai-endpoint"><?php esc_html_e( 'Hosted endpoint (https)', 'titan-zero-trades-front-desk' ); ?></label><br />
                        <input type="url" id="tzfd-ai-endpoint" name="endpoint" class="regular-text" required />
                    </p>
                    <p>
                        <label for="tzfd-ai-site-id"><?php esc_html_e( 'Site ID', 'titan-zero-trades-front-desk' ); ?></label><br />
                        <input type="text" id="tzfd-ai-site-id" name="site_id" maxlength="64" pattern="[A-Za-z0-9_.\-]{1,64}" required />
                    </p>
                    <p>
                        <label for="tzfd-ai-secret"><?php esc_html_e( 'Connector secret', 'titan-zero-trades-front-desk' ); ?></label><br />
                        <input type="password" id="tzfd-ai-secret" name="secret" class="regular-text" autocomplete="new-password" minlength="<?php echo esc_attr( (string) TZFD_AI_Connector::MIN_SECRET ); ?>" required />
                    </p>
                    <p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Connect', 'titan-zero-trades-front-desk' ); ?></button></p>
                </form>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Hosted AI consent', 'titan-zero-trades-front-desk' ); ?></h2>
            <p class="description"><?php echo esc_html( $setup['text'] ); ?>
                <a href="<?php echo esc_url( $setup['terms_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms', 'titan-zero-trades-front-desk' ); ?></a>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONSENT ); ?>" />
                <?php wp_nonce_field( self::CONNECTOR_NONCE ); ?>
                <fieldset>
                    <legend class="screen-reader-text"><?php esc_html_e( 'Data the hosted service may receive', 'titan-zero-trades-front-desk' ); ?></legend>
                    <?php foreach ( $status['disclosure']['data_classes'] as $scope ) : ?>
                        <label style="display:block">
                            <input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( in_array( $scope, $status['scopes'], true ) ); ?> />
                            <?php echo esc_html( $scope ); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save consent', 'titan-zero-trades-front-desk' ); ?></button></p>
            </form>
            <?php if ( $status['consent'] ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REVOKE ); ?>" />
                    <?php wp_nonce_field( self::CONNECTOR_NONCE ); ?>
                    <p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Revoke consent', 'titan-zero-trades-front-desk' ); ?></button>
                        <span class="description"><?php esc_html_e( 'Hosted requests stop at once. Local features are unchanged.', 'titan-zero-trades-front-desk' ); ?></span></p>
                </form>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Event log retention', 'titan-zero-trades-front-desk' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RETENTION ); ?>" />
                <?php wp_nonce_field( self::CONNECTOR_NONCE ); ?>
                <p>
                    <label for="tzfd-ai-retention"><?php esc_html_e( 'Keep events for (days)', 'titan-zero-trades-front-desk' ); ?></label>
                    <input type="number" id="tzfd-ai-retention" name="retention_days" min="1" max="<?php echo esc_attr( (string) TZFD_AI_Connector::RETENTION_MAX ); ?>" value="<?php echo esc_attr( (string) $status['retention_days'] ); ?>" />
                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Save retention', 'titan-zero-trades-front-desk' ); ?></button>
                </p>
            </form>

            <h2><?php esc_html_e( 'Hosted AI event log', 'titan-zero-trades-front-desk' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Only the time, feature, result and latency are kept. Prompts, replies and keys are never logged.', 'titan-zero-trades-front-desk' ); ?></p>
            <?php $rows = self::event_rows( TZFD_AI_Connector::events() ); ?>
            <?php if ( array() === $rows ) : ?>
                <p><?php esc_html_e( 'No hosted AI events recorded.', 'titan-zero-trades-front-desk' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:52em">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e( 'When (UTC)', 'titan-zero-trades-front-desk' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Feature', 'titan-zero-trades-front-desk' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Result', 'titan-zero-trades-front-desk' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Latency (ms)', 'titan-zero-trades-front-desk' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $row['at'] ) ); ?></td>
                            <td><?php echo esc_html( $row['feature'] ); ?></td>
                            <td><?php echo esc_html( $row['status'] ); ?></td>
                            <td><?php echo esc_html( (string) $row['latency_ms'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR ); ?>" />
                    <?php wp_nonce_field( self::CONNECTOR_NONCE ); ?>
                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Clear event log', 'titan-zero-trades-front-desk' ); ?></button>
                </form>
            <?php endif; ?>

            <?php self::render_agent_team_panel(); ?>

            <h2><?php esc_html_e( 'Hosted AI changes', 'titan-zero-trades-front-desk' ); ?></h2>
            <?php if ( TZFD_AI_Services::LEVEL_FREE !== $resolved['level'] || null !== $payload ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
                    <?php wp_nonce_field( self::NONCE ); ?>
                    <p>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Switch this site to Free', 'titan-zero-trades-front-desk' ); ?></button>
                        <span class="description"><?php esc_html_e( 'Stops hosted AI on this site. Nothing local is deleted.', 'titan-zero-trades-front-desk' ); ?></span>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Keeps only scopes the connector defines for this build. Pure function, no WordPress calls.
     *
     * @param array $raw     Submitted values (untrusted).
     * @param array $allowed Scopes the disclosure lists.
     * @return string[]
     */
    public static function normalise_scopes( array $raw, array $allowed ) {
        $out = array();
        foreach ( $raw as $scope ) {
            if ( is_string( $scope ) && in_array( $scope, $allowed, true ) ) {
                $out[] = $scope;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Accepts an integer or a decimal digit string from 1 to the connector maximum. Anything else is false.
     *
     * @param mixed $raw
     * @return int|false
     */
    public static function normalise_retention( $raw ) {
        if ( is_string( $raw ) && 1 === preg_match( '/^\d{1,3}$/D', $raw ) ) {
            $raw = (int) $raw;
        }
        if ( ! is_int( $raw ) || $raw < 1 || $raw > TZFD_AI_Connector::RETENTION_MAX ) {
            return false;
        }
        return $raw;
    }

    /**
     * Reduces stored event rows to the four redacted fields the screen may show.
     *
     * @param array $events
     * @return array<int, array{at:int, feature:string, status:string, latency_ms:int}>
     */
    public static function event_rows( array $events ) {
        $rows = array();
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) ) {
                continue;
            }
            $rows[] = array(
                'at'         => isset( $event['at'] ) ? (int) $event['at'] : 0,
                'feature'    => isset( $event['feature'] ) && is_string( $event['feature'] ) ? $event['feature'] : '',
                'status'     => isset( $event['status'] ) && is_string( $event['status'] ) ? $event['status'] : '',
                'latency_ms' => isset( $event['latency_ms'] ) ? max( 0, (int) $event['latency_ms'] ) : 0,
            );
        }
        return $rows;
    }

    /** @return string[] */
    private static function allowed_scopes() {
        $disclosure = TZFD_AI_Connector::disclosure();
        return isset( $disclosure['data_classes'] ) && is_array( $disclosure['data_classes'] ) ? $disclosure['data_classes'] : array();
    }

    /** @return array<string, string> */
    private static function notice_texts() {
        return array(
            'local_free'        => __( 'Hosted AI is switched off on this site. Your bookings, customers and settings were not changed.', 'titan-zero-trades-front-desk' ),
            'consent_saved'     => __( 'Hosted AI consent saved for the scopes you ticked.', 'titan-zero-trades-front-desk' ),
            'consent_none'      => __( 'No consent saved. Tick at least one data class to grant consent.', 'titan-zero-trades-front-desk' ),
            'consent_revoked'   => __( 'Hosted AI consent revoked. No hosted requests will be sent.', 'titan-zero-trades-front-desk' ),
            'retention_saved'   => __( 'Event log retention saved.', 'titan-zero-trades-front-desk' ),
            'retention_invalid' => __( 'Retention must be a whole number of days from 1 to 365.', 'titan-zero-trades-front-desk' ),
            'events_cleared'    => __( 'Hosted AI event log cleared.', 'titan-zero-trades-front-desk' ),
            'connect_saved'     => __( 'Site paired with the hosted service. Consent is cleared: tick the data you agree to below.', 'titan-zero-trades-front-desk' ),
            'connect_invalid'   => __( 'Pairing not saved. The endpoint must start with https, the site ID may use letters, digits, dots, dashes and underscores, and the secret must be at least 32 characters.', 'titan-zero-trades-front-desk' ),
            'disconnected'      => __( 'Site disconnected from the hosted service. No hosted requests will be sent.', 'titan-zero-trades-front-desk' ),
        );
    }

    /** Capability and nonce check for every connector form. Stops the request when either fails. */
    private static function guard_connector_request() {
        if ( ! current_user_can( 'tz_fd_access' ) ) {
            wp_die( esc_html__( 'You are not allowed to change AI Services.', 'titan-zero-trades-front-desk' ), 403 );
        }
        check_admin_referer( self::CONNECTOR_NONCE );
    }

    private static function redirect_notice( $code ) {
        wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tzfd_ai' => $code ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Read-only summary of the agent gateway for this business: state, grant version, granted tools, dry run,
     * and receipts waiting on a human approval. Pause, resume and stop stay on the Agent permissions screen.
     * Nothing here changes a grant, so the panel adds no new write path.
     */
    private static function render_agent_team_panel() {
        echo '<h2>' . esc_html__( 'Agent Team controls', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( ! class_exists( 'TZFD_Agent_Gateway_Option_Store' ) || ! class_exists( 'TZFD_Scoped_Store' ) ) {
            echo '<p class="description">' . esc_html__( 'Agent permissions are not available on this build.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        $company = (string) TZFD_Scoped_Store::company_id();
        if ( '0' === $company ) {
            echo '<p class="description">' . esc_html__( 'Set up your business first. Agent permissions apply to one business at a time.', 'titan-zero-trades-front-desk' ) . '</p>';
            return;
        }
        $store    = new TZFD_Agent_Gateway_Option_Store();
        $grant    = $store->get_grant( $company );
        $state    = is_array( $grant ) && isset( $grant['state'] ) ? (string) $grant['state'] : 'none';
        $tools    = is_array( $grant ) && isset( $grant['tools'] ) && is_array( $grant['tools'] ) ? $grant['tools'] : array();
        $pending  = 0;
        foreach ( $store->receipts_for_company( $company ) as $receipt ) {
            if ( is_array( $receipt ) && 'needs_approval' === ( $receipt['outcome'] ?? '' ) ) {
                $pending++;
            }
        }
        $manage = add_query_arg( array( 'page' => 'tzfd-agent-receipts' ), admin_url( 'admin.php' ) );
        ?>
        <table class="widefat striped" style="max-width:52em">
            <tbody>
                <tr><th scope="row"><?php esc_html_e( 'Agent state', 'titan-zero-trades-front-desk' ); ?></th>
                    <td><strong><?php echo esc_html( $state ); ?></strong><?php if ( is_array( $grant ) ) : ?>
                        &middot; <?php esc_html_e( 'grant version', 'titan-zero-trades-front-desk' ); ?> <?php echo esc_html( (string) ( $grant['version'] ?? 1 ) ); ?>
                    <?php endif; ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Tools granted', 'titan-zero-trades-front-desk' ); ?></th>
                    <td><?php echo esc_html( (string) count( $tools ) ); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Dry run', 'titan-zero-trades-front-desk' ); ?></th>
                    <td><?php echo is_array( $grant ) && ! empty( $grant['dry_run'] ) ?esc_html__( 'On. Changes are recorded, not made.', 'titan-zero-trades-front-desk' ) : esc_html__( 'Off', 'titan-zero-trades-front-desk' ); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Waiting for your approval', 'titan-zero-trades-front-desk' ); ?></th>
                    <td><?php echo esc_html( (string) $pending ); ?></td></tr>
            </tbody>
        </table>
        <p><a class="button button-secondary" href="<?php echo esc_url( $manage ); ?>"><?php esc_html_e( 'Manage agent permissions, approvals and pause or stop', 'titan-zero-trades-front-desk' ); ?></a></p>
        <?php
    }

    /** @return string */
    private static function label_for( $level, array $levels ) {
        if ( isset( $levels[ $level ]['label'] ) ) {
            return (string) $levels[ $level ]['label'];
        }
        return __( 'Free', 'titan-zero-trades-front-desk' );
    }
}
