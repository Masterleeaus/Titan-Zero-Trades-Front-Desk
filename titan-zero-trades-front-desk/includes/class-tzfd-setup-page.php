<?php
/**
 * Get-started screen (issue #15): the first-run checklist, optional progress recording with consent, and a plain
 * description of the optional hosted services. No notices on other screens, no registration, no countdowns.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-setup-checklist.php';

final class TZFD_Setup_Page {

	const PAGE = 'tzfd-get-started';
	const CAP  = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_post_tzfd_setup_manual', array( __CLASS__, 'handle_manual' ) );
		add_action( 'admin_post_tzfd_setup_consent', array( __CLASS__, 'handle_consent' ) );
	}

	public static function menu() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$parent = class_exists( 'TZFD_Admin_Menu' ) ? TZFD_Admin_Menu::PARENT : '';
		add_submenu_page( $parent, __( 'Get started', 'titan-zero-trades-front-desk' ), __( 'Get started', 'titan-zero-trades-front-desk' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to change setup settings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action, '_wpnonce' );
	}

	private static function back( $code ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tzfd_setup_notice' => sanitize_key( $code ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() checks the capability and the nonce first.
	public static function handle_manual() {
		self::guard( 'tzfd_setup_manual' );
		$known = array_keys( TZFD_Setup_Checklist::steps() );
		$ticked = isset( $_POST['manual'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['manual'] ) ) : array();
		$manual = array();
		foreach ( $known as $key ) {
			if ( 'self' === TZFD_Setup_Checklist::steps()[ $key ]['source'] ) { $manual[ $key ] = in_array( $key, $ticked, true ); }
		}
		update_option( TZFD_Setup_Checklist::MANUAL_OPTION, $manual, false );
		self::back( 'saved' );
	}

	public static function handle_consent() {
		self::guard( 'tzfd_setup_consent' );
		$action = isset( $_POST['progress'] ) ? sanitize_key( wp_unslash( $_POST['progress'] ) ) : '';
		if ( 'on' === $action ) {
			update_option( TZFD_Setup_Checklist::CONSENT_OPTION, true, false );
			self::back( 'progress_on' );
		}
		if ( 'off' === $action ) {
			update_option( TZFD_Setup_Checklist::CONSENT_OPTION, false, false );
			self::back( 'progress_off' );
		}
		if ( 'clear' === $action ) {
			delete_option( TZFD_Setup_Checklist::PROGRESS_OPTION );
			self::back( 'progress_cleared' );
		}
		self::back( 'bad_request' );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$notice = isset( $_GET['tzfd_setup_notice'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_setup_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$manual = get_option( TZFD_Setup_Checklist::MANUAL_OPTION, array() );
		$state  = TZFD_Setup_Checklist::evaluate( TZFD_Setup_Checklist::facts(), is_array( $manual ) ? $manual : array() );
		$consent = true === get_option( TZFD_Setup_Checklist::CONSENT_OPTION, false );
		$record  = get_option( TZFD_Setup_Checklist::PROGRESS_OPTION, array() );
		$record  = TZFD_Setup_Checklist::record( $consent, is_array( $record ) ? $record : array(), $state['steps'], time() );
		if ( $consent ) { update_option( TZFD_Setup_Checklist::PROGRESS_OPTION, $record, false ); }

		echo '<div class="wrap"><h1>' . esc_html__( 'Get started', 'titan-zero-trades-front-desk' ) . '</h1>';
		if ( '' !== $notice ) { echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>'; }

		echo '<h2>' . esc_html__( 'Setup checklist', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p>' . esc_html( sprintf(
			/* translators: 1: steps done, 2: total steps. */
			__( '%1$d of %2$d steps done.', 'titan-zero-trades-front-desk' ),
			$state['done_count'], $state['total']
		) ) . '</p>';
		echo '<ol>';
		foreach ( $state['steps'] as $key => $step ) {
			$status = $step['done'] ? __( 'Done', 'titan-zero-trades-front-desk' ) : __( 'To do', 'titan-zero-trades-front-desk' );
			$source = 'self' === $step['source'] ? ' (' . __( 'you mark this', 'titan-zero-trades-front-desk' ) . ')' : '';
			echo '<li><strong>' . esc_html( $step['label'] ) . '</strong>: ' . esc_html( $status ) . esc_html( $source ) . '<br><span class="description">' . esc_html( $step['hint'] ) . '</span></li>';
		}
		echo '</ol>';

		echo '<h3>' . esc_html__( 'Mark the steps you have done yourself', 'titan-zero-trades-front-desk' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tzfd_setup_manual', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_setup_manual">';
		foreach ( TZFD_Setup_Checklist::steps() as $key => $step ) {
			if ( 'self' !== $step['source'] ) { continue; }
			echo '<p><label><input type="checkbox" name="manual[]" value="' . esc_attr( $key ) . '"' . checked( ! empty( $manual[ $key ] ), true, false ) . '> ' . esc_html( $step['label'] ) . '</label></p>';
		}
		echo '<p><button class="button" type="submit">' . esc_html__( 'Save', 'titan-zero-trades-front-desk' ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Setup progress records', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p>' . esc_html__( 'If you turn this on, the plugin keeps the date each step was first seen done, on this site only. Nothing is sent anywhere. You can turn it off at any time, and clear the records.', 'titan-zero-trades-front-desk' ) . '</p>';
		echo '<p>' . esc_html( $consent ? __( 'Progress records are on.', 'titan-zero-trades-front-desk' ) : __( 'Progress records are off. Nothing is recorded.', 'titan-zero-trades-front-desk' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( 'tzfd_setup_consent', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_setup_consent"><input type="hidden" name="progress" value="' . esc_attr( $consent ? 'off' : 'on' ) . '">';
		echo '<button class="button" type="submit">' . esc_html( $consent ? __( 'Turn progress records off', 'titan-zero-trades-front-desk' ) : __( 'Turn progress records on', 'titan-zero-trades-front-desk' ) ) . '</button></form>';
		if ( ! empty( $record ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
			wp_nonce_field( 'tzfd_setup_consent', '_wpnonce' );
			echo '<input type="hidden" name="action" value="tzfd_setup_consent"><input type="hidden" name="progress" value="clear">';
			echo '<button class="button" type="submit">' . esc_html__( 'Clear the records', 'titan-zero-trades-front-desk' ) . '</button></form>';
		}

		echo '<h2>' . esc_html__( 'Optional hosted services', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p>' . esc_html__( 'Booking, the calendar, customer records, reports, exports and staff access all work with this plugin alone. Nothing here is needed to take bookings.', 'titan-zero-trades-front-desk' ) . '</p>';
		echo '<ul>';
		echo '<li><strong>' . esc_html__( 'Titan Zero AI Assistant (hosted, paid).', 'titan-zero-trades-front-desk' ) . '</strong> '
			. esc_html__( 'On by default. Answers customer questions from the business details you publish. Nothing is sent until you connect a hosted service on the Integrations tab. Once connected, the customer question text and the approved business details needed to answer it are sent to that service. Without a connection, the plugin answers from its own rules.', 'titan-zero-trades-front-desk' )
			. self::ai_terms_html() . '</li>';
		echo '<li><strong>' . esc_html__( 'Titan Zero AI Agent Team (hosted, paid).', 'titan-zero-trades-front-desk' ) . '</strong> '
			. esc_html__( 'Acts only through the tools you grant on the Agent permissions screen. Each action it tries is recorded in a receipt you can read there.', 'titan-zero-trades-front-desk' ) . '</li>';
		echo '</ul>';
		echo '</div>';
	}
	/** Terms link for the hosted AI service. The URL comes from the tzfd_ai_terms_url filter; none is shipped. */
	private static function ai_terms_html() {
		$url = function_exists( 'apply_filters' ) ? (string) apply_filters( 'tzfd_ai_terms_url', '' ) : '';
		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) { return ''; }
		return ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms of the hosted AI service', 'titan-zero-trades-front-desk' ) . '</a>.';
	}
}
