<?php
/**
 * AI Services admin view inside the one installed plugin (Free / Assistant / Agent Team).
 *
 * Shows status, the descriptor comparison and the local downgrade. It does not take payment,
 * card details or account credentials, and it makes no network calls. The "Connect account"
 * control stays disabled until the verified connector (#16, #43) is live.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-ai-services.php';

final class TZFD_AI_Services_Page {
    const SLUG   = 'tzfd-ai-services';
    const ACTION = 'tzfd_ai_services_local_downgrade';
    const NONCE  = 'tzfd_ai_services_local_downgrade';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 12 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_local_downgrade' ) );
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

    public static function render() {
        if ( ! current_user_can( 'tz_fd_access' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this page.', 'titan-zero-trades-front-desk' ), 403 );
        }
        $payload  = get_option( TZFD_AI_Services::OPTION, null );
        $resolved = TZFD_AI_Services::resolve( $payload );
        $desc     = TZFD_AI_Services::descriptors();
        $levels   = isset( $desc['levels'] ) && is_array( $desc['levels'] ) ? $desc['levels'] : array();
        $notice   = isset( $_GET['tzfd_ai'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_ai'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap tzfd-ai-services">
            <h1><?php esc_html_e( 'AI Services', 'titan-zero-trades-front-desk' ); ?></h1>
            <p><?php esc_html_e( 'Scheduling & Enquiries Manager is one plugin. Booking, customers, calendars, rules, FAQ and reports always work on this site. Hosted AI is optional.', 'titan-zero-trades-front-desk' ); ?></p>

            <?php if ( 'local_free' === $notice ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Hosted AI is switched off on this site. Your bookings, customers and settings were not changed.', 'titan-zero-trades-front-desk' ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Current status', 'titan-zero-trades-front-desk' ); ?></h2>
            <table class="widefat striped" style="max-width:52em">
                <tbody>
                    <tr><th scope="row"><?php esc_html_e( 'Hosted level', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php echo esc_html( self::label_for( $resolved['level'], $levels ) ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Reason', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php echo esc_html( $resolved['reason'] ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Local features', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php esc_html_e( 'Always enabled', 'titan-zero-trades-front-desk' ); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e( 'Hosted data consent', 'titan-zero-trades-front-desk' ); ?></th>
                        <td><?php esc_html_e( 'None recorded. Nothing is sent to a hosted service until you connect one.', 'titan-zero-trades-front-desk' ); ?></td></tr>
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

    /** @return string */
    private static function label_for( $level, array $levels ) {
        if ( isset( $levels[ $level ]['label'] ) ) {
            return (string) $levels[ $level ]['label'];
        }
        return __( 'Free', 'titan-zero-trades-front-desk' );
    }
}
