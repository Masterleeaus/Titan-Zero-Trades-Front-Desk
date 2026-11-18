<?php
/**
 * Read-only data for the Front Desk UI pack. Uses the plugin's own read functions for headline figures,
 * bookings in a date window, and enquiries for the current company. Missing sources show as unavailable.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'TZFD_UI_Front_Desk_Reads', false ) ) { return; }

final class TZFD_UI_Front_Desk_Reads {
	const DOMAIN = 'titan-zero-trades-front-desk';

	public static function company() {
		return class_exists( 'TZFD_Scoped_Store', false ) ? (int) TZFD_Scoped_Store::company_id() : 0;
	}

	public static function dashboard() {
		if ( ! class_exists( 'TZFD_Booking_View', false ) || ! class_exists( 'TZFD_Dashboard_Figures', false ) ) {
			return null;
		}
		$today = TZFD_Booking_View::today();
		$head  = TZFD_Dashboard_Figures::headline( $today );
		$fig   = TZFD_Dashboard_Figures::figures( $today );
		$stats = array(
			array( 'value' => ! empty( $head['enquiries_available'] ) ? (string) $head['enquiries'] : null, 'label' => __( 'New bookings', 'titan-zero-trades-front-desk' ), 'sub' => __( 'From today onward', 'titan-zero-trades-front-desk' ), 'tone' => 'info', 'url' => admin_url( 'admin.php?page=tz_fd_bookings' ) ),
			array( 'value' => ! empty( $head['upcoming_available'] ) ? (string) $head['upcoming'] : null, 'label' => __( 'Upcoming bookings', 'titan-zero-trades-front-desk' ), 'sub' => __( 'Next 7 days, not cancelled', 'titan-zero-trades-front-desk' ), 'tone' => 'accent', 'url' => admin_url( 'admin.php?page=tz_fd_bookings' ) ),
			array( 'value' => ! empty( $head['attention_available'] ) ? (string) $head['attention'] : null, 'label' => __( 'Needs attention', 'titan-zero-trades-front-desk' ), 'sub' => __( 'Awaiting approval, next 7 days', 'titan-zero-trades-front-desk' ), 'tone' => 'warn', 'url' => admin_url( 'admin.php?page=tz_fd_bookings' ) ),
			array( 'value' => ! empty( $fig['bookings_available'] ) ? (string) $fig['bookings'] : null, 'label' => __( 'Bookings today', 'titan-zero-trades-front-desk' ), 'sub' => __( 'Not cancelled', 'titan-zero-trades-front-desk' ), 'tone' => 'ink', 'url' => admin_url( 'admin.php?page=tz_fd_calendar' ) ),
		);
		return array( 'stats' => $stats, 'tables' => array( self::upcoming( $today ), self::enquiries() ) );
	}

	/** Bookings for the next seven days, not cancelled, earliest first, five at most. */
	private static function upcoming( $today ) {
		$found = TZFD_Booking_View::fetch( $today, TZFD_Booking_View::add_days( $today, 6 ) );
		$ok    = ! empty( $found['available'] ) && ! empty( $found['calendar_names_available'] );
		$items = array();
		foreach ( (array) $found['items'] as $item ) {
			if ( 'cancelled' !== $item['status_class'] ) { $items[] = $item; }
		}
		usort( $items, static function ( $a, $b ) {
			return array( $a['date'], $a['start'] ) <=> array( $b['date'], $b['start'] );
		} );
		$rows = array();
		foreach ( array_slice( $items, 0, 5 ) as $item ) {
			$rows[] = array(
				'url'   => admin_url( 'admin.php?page=tz_fd_bookings' ),
				'cells' => array(
					trim( date_i18n( 'D j M', strtotime( $item['date'] . ' 12:00:00 UTC' ) ) . ' ' . (string) $item['start'] ),
					(string) $item['customer'],
					(string) $item['service'],
					array( 'text' => (string) $item['status'], 'badge' => true, 'tone' => self::booking_tone( (string) $item['status_class'] ) ),
				),
			);
		}
		return array(
			'title' => __( 'Upcoming bookings', 'titan-zero-trades-front-desk' ),
			'link'  => array( 'url' => admin_url( 'admin.php?page=tz_fd_bookings' ), 'label' => __( 'All bookings', 'titan-zero-trades-front-desk' ) ),
			'table' => array(
				'caption' => __( 'Upcoming bookings', 'titan-zero-trades-front-desk' ),
				'columns' => array( __( 'Date and time', 'titan-zero-trades-front-desk' ), __( 'Customer', 'titan-zero-trades-front-desk' ), __( 'Service', 'titan-zero-trades-front-desk' ), __( 'Status', 'titan-zero-trades-front-desk' ) ),
				'rows'    => $rows,
				'empty'   => $ok ? __( 'No bookings in the next 7 days.', 'titan-zero-trades-front-desk' ) : __( 'Bookings could not be read. Check the calendar data before relying on this list.', 'titan-zero-trades-front-desk' ),
			),
		);
	}

	/** Latest enquiries for the current company. */
	private static function enquiries() {
		$company = self::company();
		$rows    = array();
		if ( $company > 0 && class_exists( 'TZFD_Enquiry_Review', false ) ) {
			foreach ( (array) TZFD_Enquiry_Review::list_for_company( $company, '', 5 ) as $r ) {
				$rows[] = array(
					'url'   => admin_url( 'admin.php?page=tz_fd_enquiries' ),
					'cells' => array(
						(string) $r->received_utc,
						(string) $r->name,
						wp_trim_words( (string) $r->enquiry, 12 ),
						(string) $r->source,
						array( 'text' => ucfirst( (string) $r->status ), 'badge' => true, 'tone' => self::enquiry_tone( (string) $r->status ) ),
					),
				);
			}
		}
		return array(
			'title' => __( 'Latest enquiries', 'titan-zero-trades-front-desk' ),
			'link'  => array( 'url' => admin_url( 'admin.php?page=tz_fd_enquiries' ), 'label' => __( 'All enquiries', 'titan-zero-trades-front-desk' ) ),
			'table' => array(
				'caption' => __( 'Latest enquiries', 'titan-zero-trades-front-desk' ),
				'columns' => array( __( 'Received (UTC)', 'titan-zero-trades-front-desk' ), __( 'Name', 'titan-zero-trades-front-desk' ), __( 'Enquiry', 'titan-zero-trades-front-desk' ), __( 'Source', 'titan-zero-trades-front-desk' ), __( 'Status', 'titan-zero-trades-front-desk' ) ),
				'rows'    => $rows,
				'empty'   => $company > 0 ? __( 'No enquiries yet.', 'titan-zero-trades-front-desk' ) : __( 'No company is selected, so no enquiries can be shown.', 'titan-zero-trades-front-desk' ),
			),
		);
	}

	private static function booking_tone( $class ) {
		$map = array( 'confirmed' => 'ok', 'pending' => 'warn', 'attended' => 'info', 'cancelled' => 'bad' );
		return isset( $map[ $class ] ) ? $map[ $class ] : 'neutral';
	}

	private static function enquiry_tone( $status ) {
		$map = array( 'new' => 'info', 'reviewed' => 'ok', 'closed' => 'neutral' );
		return isset( $map[ $status ] ) ? $map[ $status ] : 'neutral';
	}
}
