<?php
/**
 * Admin screen for location-scoped staff access (issue #11): grant, merge and revoke roles per location, and the
 * operation log. Every change goes through TZFD_Staff_Access, which applies the actor rules.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-staff-access.php';

final class TZFD_Staff_Access_Admin {

	const PAGE = 'tzfd-staff-access';
	const CAP  = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 136 );
		add_action( 'admin_post_tzfd_staff_assign', array( __CLASS__, 'handle_assign' ) );
		add_action( 'admin_post_tzfd_staff_revoke', array( __CLASS__, 'handle_revoke' ) );
	}

	public static function menu() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$parent = class_exists( 'TZFD_Admin_Menu' ) ? TZFD_Admin_Menu::PARENT : '';
		add_submenu_page( $parent, __( 'Staff access', 'titan-zero-trades-front-desk' ), __( 'Staff access', 'titan-zero-trades-front-desk' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function company() {
		return class_exists( 'TZFD_Scoped_Store' ) ? (string) TZFD_Scoped_Store::company_id() : '0';
	}

	private static function back( $code ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tzfd_staff_notice' => sanitize_key( $code ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() below checks the capability and the nonce first.
	public static function handle_assign() {
		self::guard( 'tzfd_staff_assign' );
		$company = self::company();
		if ( '0' === $company ) { self::back( 'no_business' ); }
		$user_id  = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$role     = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$staff    = isset( $_POST['staff_id'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_id'] ) ) : '';
		$raw_locs = isset( $_POST['location_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['location_ids'] ) ) : '';
		$locations = array_values( array_filter( array_map( 'trim', explode( ',', $raw_locs ) ), 'strlen' ) );
		$grant = array(
			'company_id'   => $company,
			'user_id'      => $user_id,
			'role'         => $role,
			'location_ids' => $locations,
			'staff_id'     => TZFD_Delegated_Roles::ROLE_STAFF === $role ? $staff : null,
		);
		if ( TZFD_Delegated_Roles::ROLE_COMPANY_ADMIN === $role ) { $grant['location_ids'] = array(); }
		$result = TZFD_Staff_Access::assign( $company, get_current_user_id(), true, $grant, time() );
		self::back( $result['ok'] ? 'assigned' : $result['code'] );
	}

	public static function handle_revoke() {
		self::guard( 'tzfd_staff_revoke' );
		$company  = self::company();
		if ( '0' === $company ) { self::back( 'no_business' ); }
		$user_id  = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$location = isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '';
		$result   = TZFD_Staff_Access::revoke( $company, get_current_user_id(), true, $user_id, $location, time() );
		self::back( $result['ok'] ? 'revoked' : $result['code'] );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to change staff access.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action, '_wpnonce' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$notice = isset( $_GET['tzfd_staff_notice'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_staff_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$company = self::company();
		echo '<div class="wrap"><h1>' . esc_html__( 'Staff access', 'titan-zero-trades-front-desk' ) . '</h1>';
		if ( '' !== $notice ) { echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>'; }
		if ( '0' === $company ) {
			echo '<p>' . esc_html__( 'Set up your business first. Staff access applies to one business at a time.', 'titan-zero-trades-front-desk' ) . '</p></div>';
			return;
		}
		$stored = TZFD_Staff_Access::grants( $company );
		if ( $stored['skipped'] > 0 ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( /* translators: %d: number of malformed stored grants. */ __( '%d stored grant(s) are malformed and grant nothing. Revoke and re-add them.', 'titan-zero-trades-front-desk' ), $stored['skipped'] ) ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Current grants', 'titan-zero-trades-front-desk' ) . '</h2>';
		if ( empty( $stored['grants'] ) ) {
			echo '<p>' . esc_html__( 'No staff access has been granted yet.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'User ID', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Role', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Staff ID', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Locations', 'titan-zero-trades-front-desk' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $stored['grants'] as $g ) {
				foreach ( ( empty( $g['location_ids'] ) ? array( '' ) : $g['location_ids'] ) as $loc ) {
					echo '<tr><td>' . esc_html( (string) $g['user_id'] ) . '</td><td>' . esc_html( $g['role'] ) . '</td><td>' . esc_html( (string) ( $g['staff_id'] ?? '' ) ) . '</td><td>' . esc_html( '' === $loc ? __( 'all locations', 'titan-zero-trades-front-desk' ) : $loc ) . '</td><td>';
					if ( '' !== $loc ) {
						echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
						wp_nonce_field( 'tzfd_staff_revoke', '_wpnonce' );
						echo '<input type="hidden" name="action" value="tzfd_staff_revoke"><input type="hidden" name="user_id" value="' . esc_attr( (string) $g['user_id'] ) . '"><input type="hidden" name="location_id" value="' . esc_attr( $loc ) . '">';
						echo '<button class="button-link-delete" type="submit">' . esc_html__( 'Revoke at this location', 'titan-zero-trades-front-desk' ) . '</button></form>';
					}
					echo '</td></tr>';
				}
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Grant access', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tzfd_staff_assign', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_staff_assign">';
		echo '<p><label>' . esc_html__( 'WordPress user ID', 'titan-zero-trades-front-desk' ) . ' <input type="number" name="user_id" min="1" required></label></p>';
		echo '<p><label>' . esc_html__( 'Role', 'titan-zero-trades-front-desk' ) . ' <select name="role">';
		foreach ( array( TZFD_Delegated_Roles::ROLE_COMPANY_ADMIN, TZFD_Delegated_Roles::ROLE_LOCATION_ADMIN, TZFD_Delegated_Roles::ROLE_STAFF, TZFD_Delegated_Roles::ROLE_VIEWER ) as $role ) {
			echo '<option value="' . esc_attr( $role ) . '">' . esc_html( $role ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Location IDs (comma separated; not used for company admin)', 'titan-zero-trades-front-desk' ) . ' <input type="text" name="location_ids" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Staff ID (staff role only)', 'titan-zero-trades-front-desk' ) . ' <input type="text" name="staff_id" class="regular-text"></label></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Save access', 'titan-zero-trades-front-desk' ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Operation log', 'titan-zero-trades-front-desk' ) . '</h2>';
		$log = TZFD_Staff_Access::log( $company );
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No changes recorded yet.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Actor', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Action', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'User', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Role', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Locations', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 50 ) as $row ) {
				echo '<tr><td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $row['at'] ) ) . '</td><td>' . esc_html( (string) $row['actor_id'] ) . '</td><td>' . esc_html( $row['action'] ) . '</td><td>' . esc_html( (string) $row['user_id'] ) . '</td><td>' . esc_html( $row['role'] ) . '</td><td>' . esc_html( implode( ', ', (array) $row['location_ids'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
