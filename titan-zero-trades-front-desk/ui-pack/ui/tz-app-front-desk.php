<?php
/**
 * Titan Zero UI pack: Front Desk. Dashboard, bookings, enquiries and the schedule screens. Read-only wiring.
 * Bookings and enquiries have no single-record screen in the plugin, so their list is the record view.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'TZFD_UI_Front_Desk', false ) ) { return; }

final class TZFD_UI_Front_Desk extends TZFD_UI_Core {
	public static function slug() { return 'front-desk'; }
	public static function label() { return 'Front Desk'; }
	public static function domain() { return 'titan-zero-trades-front-desk'; }
	public static function logo() { return 'front_desk'; }
	public static function view_cap() { return 'tz_fd_access'; }

	public static function options_for( $page ) {
		// The native Front Desk dashboard is authoritative; the UI shell must not render a second dashboard.
		return array();
	}

	public static function nav_items() {
		$d = 'titan-zero-trades-front-desk';
		return array(
			array( 'page' => 'tzfd', 'label' => __( 'Dashboard', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tz_fd_calendar', 'label' => __( 'Calendar', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tz_fd_bookings', 'label' => __( 'Bookings', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tz_fd_enquiries', 'label' => __( 'Enquiries', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
				array( 'page' => 'tz-customers', 'label' => __( 'Customers', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tz_pipeline', 'label' => __( 'Pipeline', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tz_fd_services', 'label' => __( 'Services', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tzfd-resources', 'label' => __( 'Workers', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tzfd-locations', 'label' => __( 'Locations', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tzfd-equipment', 'label' => __( 'Equipment & rooms', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tz_fd_addons', 'label' => __( 'Add-ons', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
			array( 'page' => 'tz_fd_settings', 'label' => __( 'Settings', 'titan-zero-trades-front-desk' ), 'cap' => 'tz_fd_access' ),
		);
	}

	public static function dashboard_data() { return TZFD_UI_Front_Desk_Reads::dashboard(); }

	/** No single-record screen exists for Front Desk records in the plugin. */
	public static function detail_data( $id ) { return null; }
}
