<?php
/**
 * Location admin and view screens for one company (issue #362).
 *
 * Site administrators register locations here (add, rename, activate or deactivate). Staff access then refers to
 * registered locations rather than typed ids. The detail view is read-only: grants at the location and the
 * operation-log rows that name it. Every write is checked for the capability and a nonce, and validated by
 * TZFD_Location_Registry before it is stored. Storage is one option per company, not autoloaded.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-location-registry.php';
require_once __DIR__ . '/class-tzfd-staff-access.php';

final class TZFD_Location_Admin {

	const PAGE          = 'tzfd-locations';
	const CAP           = 'manage_options';
	const OPTION_PREFIX = 'tzfd_locations_';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 135 );
		add_action( 'admin_post_tzfd_location_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_tzfd_location_update', array( __CLASS__, 'handle_update' ) );
	}

	public static function menu() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$parent = class_exists( 'TZFD_Admin_Menu' ) ? TZFD_Admin_Menu::PARENT : '';
		add_submenu_page( $parent, __( 'Locations', 'titan-zero-trades-front-desk' ), __( 'Locations', 'titan-zero-trades-front-desk' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** Validated registry rows for a company, and the number of stored rows skipped as malformed. */
	public static function registry( $company ) {
		$company = (string) $company;
		$raw     = get_option( self::OPTION_PREFIX . $company, array() );
		return TZFD_Location_Registry::load( $raw, $company );
	}

	/** Ids of registered, active locations: the only ids a new staff grant may name. */
	public static function active_ids( $company ) {
		return TZFD_Location_Registry::active_ids( self::registry( $company )['rows'] );
	}

	private static function save_rows( $company, $rows ) {
		update_option( self::OPTION_PREFIX . (string) $company, array_values( $rows ), false );
	}

	private static function company() {
		return class_exists( 'TZFD_Scoped_Store' ) ? (string) TZFD_Scoped_Store::company_id() : '0';
	}

	private static function back( $code, $location = '' ) {
		$args = array( 'page' => self::PAGE, 'tzfd_loc_notice' => sanitize_key( $code ) );
		if ( '' !== $location ) { $args['location'] = $location; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Adds a registered location. Pure storage step, testable without a request: returns the result code.
	 * $input keys: location_id, name.
	 */
	public static function process_add( $company, $input ) {
		$company = (string) $company;
		$id      = isset( $input['location_id'] ) ? (string) $input['location_id'] : '';
		$name    = isset( $input['name'] ) ? (string) $input['name'] : '';
		try {
			$rows = TZFD_Location_Registry::add( self::registry( $company )['rows'], $company, array( 'id' => $id, 'company_id' => $company, 'name' => $name, 'active' => true ) );
		} catch ( InvalidArgumentException $e ) {
			return array( 'ok' => false, 'code' => $e->getMessage(), 'id' => $id );
		}
		self::save_rows( $company, $rows );
		return array( 'ok' => true, 'code' => 'added', 'id' => $id );
	}

	/**
	 * Renames and sets the active flag of a registered location. $input keys: location_id, name, active (bool).
	 */
	public static function process_update( $company, $input ) {
		$company = (string) $company;
		$id      = isset( $input['location_id'] ) ? (string) $input['location_id'] : '';
		$name    = isset( $input['name'] ) ? (string) $input['name'] : '';
		$active  = ! empty( $input['active'] );
		try {
			$rows = TZFD_Location_Registry::update( self::registry( $company )['rows'], $company, $id, $name, $active );
		} catch ( InvalidArgumentException $e ) {
			return array( 'ok' => false, 'code' => $e->getMessage(), 'id' => $id );
		}
		self::save_rows( $company, $rows );
		return array( 'ok' => true, 'code' => 'updated', 'id' => $id );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checks the capability and the nonce first.
	public static function handle_add() {
		self::guard( 'tzfd_location_add' );
		$company = self::company();
		if ( '0' === $company ) { self::back( 'no_business' ); }
		$result = self::process_add( $company, array(
			'location_id' => isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '',
			'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		) );
		self::back( $result['ok'] ? 'added' : $result['code'], $result['id'] );
	}

	public static function handle_update() {
		self::guard( 'tzfd_location_update' );
		$company = self::company();
		if ( '0' === $company ) { self::back( 'no_business' ); }
		$result = self::process_update( $company, array(
			'location_id' => isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '',
			'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'active'      => ! empty( $_POST['active'] ),
		) );
		self::back( $result['ok'] ? 'updated' : $result['code'], $result['id'] );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to manage locations.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action, '_wpnonce' );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice   = isset( $_GET['tzfd_loc_notice'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_loc_notice'] ) ) : '';
		$location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$company = self::company();
		echo '<div class="wrap"><h1>' . esc_html__( 'Locations', 'titan-zero-trades-front-desk' ) . '</h1>';
		if ( '' !== $notice ) { echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>'; }
		if ( '0' === $company ) {
			echo '<p>' . esc_html__( 'Set up your business first. Locations apply to one business at a time.', 'titan-zero-trades-front-desk' ) . '</p></div>';
			return;
		}
		$reg = self::registry( $company );
		if ( $reg['skipped'] > 0 ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( /* translators: %d: number of malformed stored locations. */ __( '%d stored location(s) are malformed and are not shown. Re-add them.', 'titan-zero-trades-front-desk' ), $reg['skipped'] ) ) . '</p></div>';
		}
		if ( '' !== $location ) {
			self::render_detail( $company, $reg['rows'], $location );
		} else {
			self::render_list( $company, $reg['rows'] );
		}
		echo '</div>';
	}

	private static function render_list( $company, $rows ) {
		echo '<h2>' . esc_html__( 'Registered locations', 'titan-zero-trades-front-desk' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No locations are registered yet. Add one below before granting staff access to a location.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Location ID', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Status', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Access grants', 'titan-zero-trades-front-desk' ) . '</th><th></th></tr></thead><tbody>';
			$grants = TZFD_Staff_Access::grants( $company )['grants'];
			foreach ( $rows as $row ) {
				$count = 0;
				foreach ( $grants as $g ) { if ( in_array( $row['id'], $g['location_ids'], true ) ) { $count++; } }
				$url = add_query_arg( array( 'page' => self::PAGE, 'location' => $row['id'] ), admin_url( 'admin.php' ) );
				echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td><code>' . esc_html( $row['id'] ) . '</code></td><td>' . esc_html( $row['active'] ? __( 'Active', 'titan-zero-trades-front-desk' ) : __( 'Inactive', 'titan-zero-trades-front-desk' ) ) . '</td><td>' . esc_html( (string) $count ) . '</td><td><a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'titan-zero-trades-front-desk' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		self::render_unregistered_notice( $company, $rows );
		echo '<h2>' . esc_html__( 'Add a location', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tzfd_location_add', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_location_add">';
		echo '<p><label>' . esc_html__( 'Location ID (letters, digits, - and _; up to 64 characters; cannot change later)', 'titan-zero-trades-front-desk' ) . ' <input type="text" name="location_id" class="regular-text" required pattern="[A-Za-z0-9_\-]{1,64}"></label></p>';
		echo '<p><label>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . ' <input type="text" name="name" class="regular-text" maxlength="120" required></label></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Add location', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
	}

	/** Lists grant location ids that are not registered, so legacy free-text grants stay visible and can be claimed. */
	private static function render_unregistered_notice( $company, $rows ) {
		$used = array();
		foreach ( TZFD_Staff_Access::grants( $company )['grants'] as $g ) { $used = array_merge( $used, $g['location_ids'] ); }
		$missing = TZFD_Location_Registry::unregistered( $rows, $used );
		if ( empty( $missing ) ) { return; }
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'These location IDs are used by existing staff grants but are not registered. The grants still work. Add a location with the same ID to register it.', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( implode( ', ', $missing ) ) . '</code></p></div>';
	}

	private static function render_detail( $company, $rows, $id ) {
		$row = TZFD_Location_Registry::find( $rows, $id );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">&larr; ' . esc_html__( 'All locations', 'titan-zero-trades-front-desk' ) . '</a></p>';
		if ( null === $row ) {
			echo '<p>' . esc_html__( 'That location is not registered.', 'titan-zero-trades-front-desk' ) . '</p>';
			return;
		}
		echo '<h2>' . esc_html( $row['name'] ) . ' <code>' . esc_html( $row['id'] ) . '</code></h2>';
		echo '<p>' . esc_html( $row['active'] ? __( 'Active: new staff grants allowed.', 'titan-zero-trades-front-desk' ) : __( 'Inactive: new staff grants refused. Existing grants and bookings are not changed.', 'titan-zero-trades-front-desk' ) ) . '</p>';

		echo '<h3>' . esc_html__( 'Staff access at this location', 'titan-zero-trades-front-desk' ) . '</h3>';
		$here = array();
		foreach ( TZFD_Staff_Access::grants( $company )['grants'] as $g ) {
			if ( 'company_admin' === $g['role'] || in_array( $row['id'], $g['location_ids'], true ) ) { $here[] = $g; }
		}
		if ( empty( $here ) ) {
			echo '<p>' . esc_html__( 'No staff access reaches this location yet.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'User ID', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Role', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Staff ID', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
			foreach ( $here as $g ) {
				echo '<tr><td>' . esc_html( (string) $g['user_id'] ) . '</td><td>' . esc_html( $g['role'] ) . '</td><td>' . esc_html( (string) ( $g['staff_id'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Recent changes naming this location', 'titan-zero-trades-front-desk' ) . '</h3>';
		$log = array();
		foreach ( TZFD_Staff_Access::log( $company ) as $entry ) {
			if ( in_array( $row['id'], (array) $entry['location_ids'], true ) ) { $log[] = $entry; }
		}
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No changes recorded for this location.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Actor', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Action', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'User', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Role', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 50 ) as $entry ) {
				echo '<tr><td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['at'] ) ) . '</td><td>' . esc_html( (string) $entry['actor_id'] ) . '</td><td>' . esc_html( $entry['action'] ) . '</td><td>' . esc_html( (string) $entry['user_id'] ) . '</td><td>' . esc_html( $entry['role'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Edit location', 'titan-zero-trades-front-desk' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tzfd_location_update', '_wpnonce' );
		echo '<input type="hidden" name="action" value="tzfd_location_update"><input type="hidden" name="location_id" value="' . esc_attr( $row['id'] ) . '">';
		echo '<p><label>' . esc_html__( 'Name', 'titan-zero-trades-front-desk' ) . ' <input type="text" name="name" class="regular-text" maxlength="120" required value="' . esc_attr( $row['name'] ) . '"></label></p>';
		echo '<p><label><input type="checkbox" name="active" value="1"' . checked( $row['active'], true, false ) . '> ' . esc_html__( 'Active (accepts new staff grants)', 'titan-zero-trades-front-desk' ) . '</label></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Save location', 'titan-zero-trades-front-desk' ) . '</button></p></form>';
	}
}
