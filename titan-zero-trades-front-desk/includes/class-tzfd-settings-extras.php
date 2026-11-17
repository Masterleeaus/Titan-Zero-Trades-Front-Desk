<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Settings the Settings screen did not offer before: the business profile (General) and the default
 * notification email that new calendars start with (Notifications). Each card saves through its own
 * admin-post handler, so the existing settings forms and their save code are unchanged.
 */
final class TZFD_Settings_Extras {
	const PROFILE_OPTION   = 'tz_fd_business_profile';
	const NOTIFY_OPTION    = 'tz_fd_default_notify_email';
	const CONFIRM_OPTION   = 'tz_fd_default_customer_confirm';
	const RETURN_OPTION    = 'tz_fd_default_return_page';
	const NONCE            = 'tzfd_settings_extras';
	const ACTION           = 'tzfd_settings_extras_save';
	const NOTICE_PREFIX    = 'tzfd_settings_extras_notice_';

	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	/** Fields on the business profile card: key => label. */
	public static function profile_fields() {
		return array(
			'name'       => __( 'Business name', 'titan-zero-trades-front-desk' ),
			'phone'      => __( 'Phone', 'titan-zero-trades-front-desk' ),
			'email'      => __( 'Contact email', 'titan-zero-trades-front-desk' ),
			'address'    => __( 'Business address', 'titan-zero-trades-front-desk' ),
			'website'    => __( 'Website', 'titan-zero-trades-front-desk' ),
			'tax_number' => __( 'Tax or GST number', 'titan-zero-trades-front-desk' ),
			'area'       => __( 'Service area', 'titan-zero-trades-front-desk' ),
		);
	}

	/** Default notification email for new calendars: the stored value, or the fallback when none is set. */
	public static function default_notify_email( $fallback ) {
		$stored = trim( (string) get_option( self::NOTIFY_OPTION, '' ) );
		return '' !== $stored ? $stored : (string) $fallback;
	}

	/** Customer confirmation default for new calendars: 'true' or 'false'. */
	public static function default_customer_confirm( $fallback ) {
		$stored = (string) get_option( self::CONFIRM_OPTION, '' );
		return in_array( $stored, array( 'true', 'false' ), true ) ? $stored : (string) $fallback;
	}

	/** Page customers land on after booking, for new calendars. */
	public static function default_return_page( $fallback ) {
		$stored = trim( (string) get_option( self::RETURN_OPTION, '' ) );
		return '' !== $stored ? $stored : (string) $fallback;
	}

	/* ---------------------------------------------------------------
	 * Cards
	 * ------------------------------------------------------------- */

	public static function render_profile_card() {
		$profile = get_option( self::PROFILE_OPTION, array() );
		$profile = is_array( $profile ) ? $profile : array();
		echo '<div class="tzfd-set-card tzfd-set-card-profile">';
		echo '<h2>' . esc_html__( 'Business profile', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p class="tzfd-set-intro">' . esc_html__( 'Your business details. Shown on the Front Desk dashboard and used on pages and in emails that name the business.', 'titan-zero-trades-front-desk' ) . '</p>';
		self::flash( 'profile' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="section" value="profile" />';
		echo '<table class="form-table"><tbody>';
		foreach ( self::profile_fields() as $key => $label ) {
			$id    = 'tzfd_profile_' . $key;
			$value = isset( $profile[ $key ] ) ? (string) $profile[ $key ] : '';
			echo '<tr valign="top"><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="profile[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" />';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save business profile', 'titan-zero-trades-front-desk' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	public static function render_notify_card() {
		echo '<div class="tzfd-set-card tzfd-set-card-notify">';
		echo '<h2>' . esc_html__( 'Default booking notifications', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p class="tzfd-set-intro">' . esc_html__( 'Where new booking alerts go for calendars you create from now on. Each calendar can still change its own recipients in its settings.', 'titan-zero-trades-front-desk' ) . '</p>';
		self::flash( 'notify' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="section" value="notify" />';
		$confirm = self::default_customer_confirm( 'true' );
		echo '<table class="form-table"><tbody>';
		echo '<tr valign="top"><th scope="row"><label for="tzfd_confirm">' . esc_html__( 'Customer confirmation email', 'titan-zero-trades-front-desk' ) . '</label></th><td>';
		echo '<select id="tzfd_confirm" name="customer_confirm"><option value="true"' . selected( $confirm, 'true', false ) . '>' . esc_html__( 'Yes, send it', 'titan-zero-trades-front-desk' ) . '</option><option value="false"' . selected( $confirm, 'false', false ) . '>' . esc_html__( 'No', 'titan-zero-trades-front-desk' ) . '</option></select>';
		echo '<p class="description">' . esc_html__( 'Whether new calendars email the customer a confirmation after booking.', 'titan-zero-trades-front-desk' ) . '</p></td></tr>';
		echo '<tr valign="top"><th scope="row"><label for="tzfd_return">' . esc_html__( 'Page after booking', 'titan-zero-trades-front-desk' ) . '</label></th><td>';
		echo '<input type="url" class="regular-text" id="tzfd_return" name="return_page" value="' . esc_attr( (string) get_option( self::RETURN_OPTION, '' ) ) . '" placeholder="' . esc_attr( home_url( '/' ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Where the customer goes once the booking is sent. Leave blank for the site home page.', 'titan-zero-trades-front-desk' ) . '</p></td></tr>';
		echo '<tr valign="top"><th scope="row"><label for="tzfd_notify_email">' . esc_html__( 'Alert recipients', 'titan-zero-trades-front-desk' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="tzfd_notify_email" name="notify_email" value="' . esc_attr( (string) get_option( self::NOTIFY_OPTION, '' ) ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Comma separated. Leave blank to use the site admin email.', 'titan-zero-trades-front-desk' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save notification default', 'titan-zero-trades-front-desk' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/* ---------------------------------------------------------------
	 * Save
	 * ------------------------------------------------------------- */

	public static function handle_save() {
		if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You cannot access these settings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( self::NONCE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		if ( 'profile' === $section ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; each value is sanitised below.
			$raw     = isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : array();
			$profile = array();
			foreach ( array_keys( self::profile_fields() ) as $key ) {
				$value = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
				$profile[ $key ] = 'email' === $key ? sanitize_email( $value ) : ( 'website' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value ) );
			}
			$existing = get_option( self::PROFILE_OPTION, array() );
			update_option( self::PROFILE_OPTION, array_merge( is_array( $existing ) ? $existing : array(), $profile ), false );
			self::set_flash( __( 'Business profile saved.', 'titan-zero-trades-front-desk' ), false, 'profile' );
			wp_safe_redirect( admin_url( 'admin.php?page=tz_fd_settings' ) );
			exit;
		}
		if ( 'notify' === $section ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$confirm = isset( $_POST['customer_confirm'] ) && 'false' === sanitize_text_field( wp_unslash( $_POST['customer_confirm'] ) ) ? 'false' : 'true';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$return  = isset( $_POST['return_page'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['return_page'] ) ) ) ) : '';
			update_option( self::CONFIRM_OPTION, $confirm, false );
			update_option( self::RETURN_OPTION, $return, false );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$list  = isset( $_POST['notify_email'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_email'] ) ) : '';
			$valid = array();
			$bad   = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $list ) ) ) as $addr ) {
				$clean = sanitize_email( $addr );
				if ( '' !== $clean && is_email( $clean ) ) { $valid[] = $clean; } else { $bad[] = $addr; }
			}
			if ( ! empty( $bad ) ) {
				self::set_flash( sprintf( /* translators: %s: invalid addresses. */ __( 'Not saved. These are not valid email addresses: %s', 'titan-zero-trades-front-desk' ), implode( ', ', $bad ) ), true, 'notify' );
			} else {
				update_option( self::NOTIFY_OPTION, implode( ', ', $valid ), false );
				self::set_flash( __( 'Default notification email saved.', 'titan-zero-trades-front-desk' ), false, 'notify' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=tz_fd_settings#notifications' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=tz_fd_settings' ) );
		exit;
	}

	/* ---------------------------------------------------------------
	 * One-time notices
	 * ------------------------------------------------------------- */

	private static function set_flash( $message, $error, $section ) {
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), array( 'message' => $message, 'error' => (bool) $error, 'section' => $section ), 60 );
	}

	private static function flash( $section ) {
		$key  = self::NOTICE_PREFIX . get_current_user_id();
		$note = get_transient( $key );
		if ( ! is_array( $note ) || ( $note['section'] ?? '' ) !== $section ) { return; }
		delete_transient( $key );
		printf( '<div class="notice %s inline"><p>%s</p></div>', $note['error'] ? 'notice-error' : 'notice-success', esc_html( $note['message'] ) );
	}
}
