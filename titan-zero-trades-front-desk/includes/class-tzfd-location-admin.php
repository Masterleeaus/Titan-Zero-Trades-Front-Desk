<?php
/**
 * Location admin and view screens (issue #362). Locations are registered here, once, and every staff grant must
 * then name one of them. Changes are limited to site administrators. Archiving is refused while a staff grant
 * still uses the location, so access is always revoked explicitly first.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-location-registry.php';
require_once __DIR__ . '/class-tzfd-location-store.php';
require_once __DIR__ . '/class-tzfd-staff-access.php';

final class TZFD_Location_Admin {

	const PAGE = 'tzfd-locations';
	const CAP  = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 135 );
		add_action( 'admin_post_tzfd_location_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_tzfd_location_status', array( __CLASS__, 'handle_status' ) );
	}

	public static function menu() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$parent = class_exists( 'TZFD_Admin_Menu' ) ? TZFD_Admin_Menu::PARENT : '';
		add_submenu_page( $parent, __( 'Locations', 'titan-zero-trades-front-desk' ), __( 'Locations', 'titan-zero-trades-front-desk' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function company() {
		return class_exists( 'TZFD_Scoped_Store' ) ? (string) TZFD_Scoped_Store::company_id() : '0';
	}

	private static function back( $code, $location = '' ) {
		$args = array( 'page' => self::PAGE, 'tzfd_location_notice' => sanitize_key( $code ) );
		if ( '' !== $location ) { $args['location'] = $location; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checks the nonce first.
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() below checks the capability and the nonce first.
	public static function handle_save() {
		self::guard( 'tzfd_location_save' );
		$company = self::company();
		if ( '0' === $company ) { self::back( 'no_business' ); }
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$id   = self::text( 'location_id' );
		$name = self::text( 'name' );
		$tz   = self::text( 'timezone' );
		if ( 'create' === $mode ) {
			$result = TZFD_Location_Store::add( $company, get_current_user_id(), array( 'id' => $id, 'name' => $name, 'timezone' => $tz, 'status' => TZFD_Location_Registry::STATUS_ACTIVE ), time() );
		} elseif ( 'update' === $mode ) {
			$result = TZFD_Location_Store::update( $company, get_current_user_id(), $id, array( 'name' => $name, 'timezone' => $tz ), time() );
		} else {
			self::back( 'unknown_mode' );
		}
		self::back( $result['code'], $id );
	}

	public static function handle_status() {
		self::guard( 'tzfd_location_status' );
		$company = self::company();
		if ( '0' === $company ) { self::back( 'no_business' ); }
		$id     = self::text( 'location_id' );
		$action = isset( $_POST['status_action'] ) ? sanitize_key( wp_unslash( $_POST['status_action'] ) ) : '';
		if ( ! in_array( $action, array( 'archive', 'restore' ), true ) ) { self::back( 'unknown_action', $id ); }
		if ( 'archive' === $action && self::grants_using( $company, $id ) > 0 ) { self::back( 'location_in_use', $id ); }
		$status = 'archive' === $action ? TZFD_Location_Registry::STATUS_ARCHIVED : TZFD_Location_Registry::STATUS_ACTIVE;
		$result = TZFD_Location_Store::update( $company, get_current_user_id(), $id, array( 'status' => $status ), time() );
		self::back( $result['code'], $id );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to change locations.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action, '_wpnonce' );
	}

	/** Number of stored staff grants that name this location. */
	private static function grants_using( $company, $location_id ) {
		$count = 0;
		foreach ( TZFD_Staff_Access::grants( $company )['grants'] as $grant ) {
			if ( in_array( $location_id, $grant['location_ids'], true ) ) { $count++; }
		}
		return $count;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$notice = isset( $_GET['tzfd_location_notice'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_location_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$view   = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$company = self::company();
		echo '<div class="wrap"><h1>' . esc_html__( 'Locations', 'titan-zero-trades-front-desk' ) . '</h1>';
		if ( '' !== $notice ) { echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( str_replace( '_', ' ', $notice ) ) . '</p></div>'; }
		if ( '0' === $company ) {
			echo '<p>' . esc_html__( 'Set up your business first. Locations apply to one business at a time.', 'titan-zero-trades-front-desk' ) . '</p></div>';
			return;
		}
		$loaded = TZFD_Location_Store::locations( $company );
		if ( $loaded['skipped'] > 0 ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( /* translators: %d: number of malformed stored locations. */ __( '%d stored location(s) are malformed and are not used. Recreate them.', 'titan-zero-trades-front-desk' ), $loaded['skipped'] ) ) . '</p></div>';
		}
		$found = '' === $view ? null : TZFD_Location_Registry::find( $loaded['locations'], $view );
		if ( '' !== $view && null === $found ) {
			echo '<p>' . esc_html__( 'That location is not registered.', 'titan-zero-trades-front-desk' ) . ' <a href="' . esc_url( self::list_url() ) . '">' . esc_html__( 'Back to locations', 'titan-zero-trades-front-desk' ) . '</a></p></div>';
			return;
		}
		if ( null !== $found ) {
			self::render_view( $company, $found );
		} else {
			self::render_list( $loaded['locations'] );
		}
		echo '</div>';
	}

	private static function list_url() {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
	}

	private static function render_list( $locations ) {
		echo '<h2>' . esc_html__( 'Registered locations', 'titan-zero-trades-front-desk' ) . '</h2>';
		if ( empty( $locations ) ) {
			echo '<p>' . esc_html__( 'No locations are registered yet. Staff access can only be granted at a registered location.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'ID', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Time zone', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
			foreach ( $locations as $item ) {
				$link = add_query_arg( array( 'page' => self::PAGE, 'location' => $item['id'] ), admin_url( 'admin.php' ) );
				echo '<tr><td><a href="' . esc_url( $link ) . '">' . esc_html( $item['name'] ) . '</a></td><td>' . esc_html( $item['id'] ) . '</td><td>' . esc_html( $item['timezone'] ) . '</td><td>' . esc_html( $item['status'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . esc_html__( 'Add a location', 'titan-zero-trades-front-desk' ) . '</h2>';
		self::render_form( 'create', null );
	}

	private static function render_view( $company, $location ) {
		$id = $location['id'];
		echo '<p><a href="' . esc_url( self::list_url() ) . '">&larr; ' . esc_html__( 'All locations', 'titan-zero-trades-front-desk' ) . '</a></p>';
		echo '<h2>' . esc_html( $location['name'] ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'ID', 'titan-zero-trades-front-desk' ) . '</th><td>' . esc_html( $id ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Time zone', 'titan-zero-trades-front-desk' ) . '</th><td>' . esc_html( $location['timezone'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th><td>' . esc_html( $location['status'] ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Staff access at this location', 'titan-zero-trades-front-desk' ) . '</h2>';
		$here = array();
		foreach ( TZFD_Staff_Access::grants( $company )['grants'] as $grant ) {
			if ( in_array( $id, $grant['location_ids'], true ) ) { $here[] = $grant; }
		}
		if ( empty( $here ) ) {
			echo '<p>' . esc_html__( 'No staff access is granted at this location.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'User ID', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Role', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Staff ID', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
			foreach ( $here as $grant ) {
				echo '<tr><td>' . esc_html( (string) $grant['user_id'] ) . '</td><td>' . esc_html( $grant['role'] ) . '</td><td>' . esc_html( (string) ( $grant['staff_id'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Edit location', 'titan-zero-trades-front-desk' ) . '</h2>';
		self::render_form( 'update', $location );

		echo '<h2>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</h2>';
		$next   = TZFD_Location_Registry::STATUS_ACTIVE === $location['status'] ? 'archive' : 'restore';
		$label  = 'archive' === $next ? __( 'Archive location', 'titan-zero-trades-front-desk' ) : __( 'Restore location', 'titan-zero-trades-front-desk' );
		echo '<p>' . esc_html__( 'Archiving keeps the record and its history. It is refused while staff access still uses this location; revoke that access first.', 'titan-zero-trades-front-desk' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tzfd_location_status', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_location_status"><input type="hidden" name="location_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="status_action" value="' . esc_attr( $next ) . '">';
		echo '<p><button class="button" type="submit">' . esc_html( $label ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Operation log for this location', 'titan-zero-trades-front-desk' ) . '</h2>';
		$rows = array_values( array_filter( TZFD_Location_Store::log( $company ), function ( $row ) use ( $id ) {
			return isset( $row['location_id'] ) && $row['location_id'] === $id;
		} ) );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No changes recorded for this location yet.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Actor', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Action', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Fields', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 50 ) as $row ) {
				echo '<tr><td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $row['at'] ) ) . '</td><td>' . esc_html( (string) $row['actor_id'] ) . '</td><td>' . esc_html( $row['action'] ) . '</td><td>' . esc_html( implode( ', ', (array) $row['fields'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/** Create form (no location) or edit form (location is fixed; only name and time zone change). */
	private static function render_form( $mode, $location ) {
		$is_new = null === $location;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tzfd_location_save', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_location_save"><input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
		if ( $is_new ) {
			echo '<p><label>' . esc_html__( 'Location ID (letters, digits, - and _; fixed after saving)', 'titan-zero-trades-front-desk' ) . ' <input type="text" name="location_id" class="regular-text" maxlength="64" required></label></p>';
		} else {
			echo '<input type="hidden" name="location_id" value="' . esc_attr( $location['id'] ) . '">';
		}
		echo '<p><label>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . ' <input type="text" name="name" class="regular-text" maxlength="120" required value="' . esc_attr( $is_new ? '' : $location['name'] ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Time zone', 'titan-zero-trades-front-desk' ) . ' <select name="timezone">' . wp_timezone_choice( $is_new ? 'UTC' : $location['timezone'] ) . '</select></label></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html( $is_new ? __( 'Add location', 'titan-zero-trades-front-desk' ) : __( 'Save changes', 'titan-zero-trades-front-desk' ) ) . '</button></p></form>';
	}
}
