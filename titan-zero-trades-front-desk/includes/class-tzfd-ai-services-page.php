<?php
/**
 * AI Services admin view inside the one installed plugin (Free / Assistant / Agent Team).
 *
 * Shows status, the descriptor comparison, the local downgrade and the hosted connector controls:
 * consent (grant and revoke), event retention and the redacted event log (#4, #261).
 * It does not take payment, card details or account credentials, and it makes no network calls.
 * The "Connect account" control stays disabled until the verified pairing (#46) is live.
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
    const ACTION_DISCONNECT = 'tzfd_ai_connector_disconnect';
    const CONNECTOR_NONCE   = 'tzfd_ai_connector_admin';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 12 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_local_downgrade' ) );
        add_action( 'admin_post_' . self::ACTION_CONSENT, array( __CLASS__, 'handle_consent' ) );
        add_action( 'admin_post_' . self::ACTION_REVOKE, array( __CLASS__, 'handle_revoke' ) );
        add_action( 'admin_post_' . self::ACTION_RETENTION, array( __CLASS__, 'handle_retention' ) );
        add_action( 'admin_post_' . self::ACTION_CLEAR, array( __CLASS__, 'handle_clear_events' ) );
        add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );
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

    /** Opt-out: removes the endpoint, secret, consent and entitlements. Local features and the event log are untouched. */
    public static function handle_disconnect() {
        self::guard_connector_request();
        TZFD_AI_Connector::disconnect();
        self::redirect_notice( 'disconnected' );
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
                        <td><?php echo $status['endpoint_set'] ? esc_html__( 'Set', 'titan-zero-trades-front-desk' ) : esc_html__( 'Not set. Pairing is not available yet.', 'titan-zero-trades-front-desk' ); ?></td></tr>
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

            <?php if ( $status['secret_set'] || $status['endpoint_set'] || $status['consent'] ) : ?>
                <h2><?php esc_html_e( 'Disconnect hosted AI', 'titan-zero-trades-front-desk' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISCONNECT ); ?>" />
                    <?php wp_nonce_field( self::CONNECTOR_NONCE ); ?>
                    <p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Disconnect', 'titan-zero-trades-front-desk' ); ?></button>
                        <span class="description"><?php esc_html_e( 'Removes the stored connection and consent. Bookings, customers and settings stay.', 'titan-zero-trades-front-desk' ); ?></span></p>
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
            'disconnected'      => __( 'Hosted AI disconnected. The stored connection and consent were removed. Local features are unchanged.', 'titan-zero-trades-front-desk' ),
            'retention_saved'   => __( 'Event log retention saved.', 'titan-zero-trades-front-desk' ),
            'retention_invalid' => __( 'Retention must be a whole number of days from 1 to 365.', 'titan-zero-trades-front-desk' ),
            'events_cleared'    => __( 'Hosted AI event log cleared.', 'titan-zero-trades-front-desk' ),
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

    /** @return string */
    private static function label_for( $level, array $levels ) {
        if ( isset( $levels[ $level ]['label'] ) ) {
            return (string) $levels[ $level ]['label'];
        }
        return __( 'Free', 'titan-zero-trades-front-desk' );
    }
}
