<?php
/**
 * Admin screen for the agent gateway (issue #14): which tools the hosted agent may use, pause, resume and
 * emergency stop, and the read-only action receipt viewer. Every change is capability and nonce checked.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent-tool-permissions.php';
require_once __DIR__ . '/class-tzfd-agent-gateway.php';

final class TZFD_Agent_Gateway_Admin {

	const PAGE = 'tzfd-agent-receipts';
	const CAP  = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 135 );
		add_action( 'admin_post_tzfd_agent_state', array( __CLASS__, 'handle_state' ) );
		add_action( 'admin_post_tzfd_agent_grant', array( __CLASS__, 'handle_grant' ) );
	}

	public static function menu() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$parent = class_exists( 'TZFD_Admin_Menu' ) ? TZFD_Admin_Menu::PARENT : '';
		add_submenu_page( $parent, __( 'Agent permissions and receipts', 'titan-zero-trades-front-desk' ), __( 'Agent permissions', 'titan-zero-trades-front-desk' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** Company this screen manages. Zero means no business is set up yet. */
	private static function company() {
		return class_exists( 'TZFD_Scoped_Store' ) ? (string) TZFD_Scoped_Store::company_id() : '0';
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to change agent permissions.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action, '_wpnonce' );
	}

	private static function back( $code ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tzfd_agent_notice' => sanitize_key( $code ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() checks the capability and check_admin_referer() first.
	public static function handle_state() {
		self::guard( 'tzfd_agent_state' );
		$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		if ( ! in_array( $state, array( 'active', 'paused', 'stopped' ), true ) ) { self::back( 'bad_state' ); }
		try {
			TZFD_Agent_Gateway::set_state( new TZFD_Agent_Gateway_Option_Store(), self::company(), $state );
		} catch ( InvalidArgumentException $e ) {
			self::back( 'no_grant' );
		}
		self::back( 'state_' . $state );
	}

	public static function handle_grant() {
		self::guard( 'tzfd_agent_grant' );
		$store   = new TZFD_Agent_Gateway_Option_Store();
		$company = self::company();
		$current = $store->get_grant( $company );
		$tools   = isset( $_POST['tools'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['tools'] ) ) : array();
		$tools   = array_values( array_intersect( $tools, array_keys( TZFD_Agent_Tool_Permissions::catalogue() ) ) );
		$grant   = array(
			'company_id'   => $company,
			'agent_id'     => is_array( $current ) && isset( $current['agent_id'] ) ? (string) $current['agent_id'] : 'front-desk-agent',
			'state'        => is_array( $current ) && isset( $current['state'] ) ? (string) $current['state'] : 'active',
			'dry_run'      => ! empty( $_POST['dry_run'] ),
			'version'      => is_array( $current ) && isset( $current['version'] ) ? (int) $current['version'] : 1,
			'expires_at'   => null,
			'tools'        => $tools,
			'auto_approve' => array(),
			'max_per_hour' => array(),
		);
		try {
			TZFD_Agent_Gateway::save_grant( $store, $grant );
		} catch ( InvalidArgumentException $e ) {
			self::back( 'grant_invalid' );
		}
		self::back( 'grant_saved' );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$store   = new TZFD_Agent_Gateway_Option_Store();
		$company = self::company();
		$notice  = isset( $_GET['tzfd_agent_notice'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_agent_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$grant   = '0' === $company ? null : $store->get_grant( $company );
		$tool    = isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$outcome = isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$filters = array_filter( array( 'tool' => $tool ?: null, 'outcome' => $outcome ?: null ) );
		try {
			$view = '0' === $company ? null : TZFD_Agent_Gateway::receipts_view( $store, TZFD_Agent_Gateway_Option_Store::secret(), $company, $filters, 50 );
		} catch ( InvalidArgumentException $e ) {
			$view = null;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Agent permissions and receipts', 'titan-zero-trades-front-desk' ) . '</h1>';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( '0' === $company ) {
			echo '<p>' . esc_html__( 'Set up your business first. Agent permissions apply to one business at a time.', 'titan-zero-trades-front-desk' ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Nothing is allowed until you grant it. Tools that change bookings or contact customers need your approval unless you choose otherwise.', 'titan-zero-trades-front-desk' ) . '</p>';

		$state = is_array( $grant ) ? (string) $grant['state'] : 'none';
		echo '<h2>' . esc_html__( 'Current state', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p>' . esc_html__( 'State', 'titan-zero-trades-front-desk' ) . ': <strong>' . esc_html( $state ) . '</strong>'
			. ( is_array( $grant ) ? ' &middot; ' . esc_html__( 'grant version', 'titan-zero-trades-front-desk' ) . ' ' . esc_html( (string) $grant['version'] ) : '' ) . '</p>';
		foreach ( array( 'active' => __( 'Resume', 'titan-zero-trades-front-desk' ), 'paused' => __( 'Pause', 'titan-zero-trades-front-desk' ), 'stopped' => __( 'Emergency stop', 'titan-zero-trades-front-desk' ) ) as $value => $label ) {
			if ( is_array( $grant ) && $value === $state ) { continue; }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
			wp_nonce_field( 'tzfd_agent_state', '_wpnonce' );
			echo '<input type="hidden" name="action" value="tzfd_agent_state"><input type="hidden" name="state" value="' . esc_attr( $value ) . '">';
			echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
		}

		echo '<h2>' . esc_html__( 'Tools the agent may use', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tzfd_agent_grant', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_agent_grant">';
		$granted = is_array( $grant ) ? (array) $grant['tools'] : array();
		foreach ( TZFD_Agent_Tool_Permissions::catalogue() as $name => $spec ) {
			$approval = $spec['approval'] ? __( 'needs your approval', 'titan-zero-trades-front-desk' ) : __( 'no approval needed', 'titan-zero-trades-front-desk' );
			echo '<p><label><input type="checkbox" name="tools[]" value="' . esc_attr( $name ) . '"' . checked( in_array( $name, $granted, true ), true, false ) . '> '
				. esc_html( $name ) . ' <span class="description">(' . esc_html( $spec['risk'] ) . ', ' . esc_html( $approval ) . ')</span></label></p>';
		}
		$dry = is_array( $grant ) && ! empty( $grant['dry_run'] );
		echo '<p><label><input type="checkbox" name="dry_run" value="1"' . checked( $dry, true, false ) . '> '
			. esc_html__( 'Dry run: record what the agent would do, change nothing', 'titan-zero-trades-front-desk' ) . '</label></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Save permissions', 'titan-zero-trades-front-desk' ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Action receipts', 'titan-zero-trades-front-desk' ) . '</h2>';
		if ( is_array( $view ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TZFD_Action_Receipt_Viewer::render() escapes every value it prints.
			echo TZFD_Action_Receipt_Viewer::render( $view );
		}
		echo '</div>';
	}
}
