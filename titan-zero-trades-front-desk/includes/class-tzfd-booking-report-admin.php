<?php
/**
 * Booking report screen and CSV export for the administrator (issue #12).
 *
 * Reads the same appointments as the Calendar, through TZFD_Booking_View::fetch() (company scoped), and
 * summarises them per day. The export refuses to write a file when the read was truncated, so a CSV never
 * shows partial counts as complete. It holds dates and counts only.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-booking-view.php';
require_once __DIR__ . '/class-tzfd-booking-reports.php';
require_once __DIR__ . '/class-tzfd-booking-report-summary.php';

final class TZFD_Booking_Report_Admin {

	const PAGE = 'tzfd-booking-report';
	const CAP  = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 137 );
		add_action( 'admin_post_tzfd_booking_report_csv', array( __CLASS__, 'handle_csv' ) );
	}

	public static function menu() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$parent = class_exists( 'TZFD_Admin_Menu' ) ? TZFD_Admin_Menu::PARENT : '';
		add_submenu_page( $parent, __( 'Booking report', 'titan-zero-trades-front-desk' ), __( 'Booking report', 'titan-zero-trades-front-desk' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** Range from the request, defaulting to the last 30 days ending today (UTC). Invalid input falls back to the default. */
	private static function range( $from, $to ) {
		$today = gmdate( 'Y-m-d' );
		$to    = is_string( $to ) && '' !== $to ? $to : $today;
		$from  = is_string( $from ) && '' !== $from ? $from : gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		return array( $from, $to );
	}

	/** @return array{summary: ?array, error: string, truncated: bool} */
	private static function build( $from, $to ) {
		$out = array( 'summary' => null, 'error' => '', 'truncated' => false );
		$read = TZFD_Booking_View::fetch( $from, $to, 0 );
		if ( empty( $read['available'] ) ) { $out['error'] = 'no_company'; return $out; }
		$out['truncated'] = ! empty( $read['truncated'] );
		try {
			$out['summary'] = TZFD_Booking_Report_Summary::summarise( $read['items'], $from, $to );
		} catch ( InvalidArgumentException $e ) {
			$out['error'] = $e->getMessage();
		}
		return $out;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only date range for the report; no state changes.
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		list( $from, $to ) = self::range(
			isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : null,
			isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : null
		);
		$built = self::build( $from, $to );

		echo '<div class="wrap"><h1>' . esc_html__( 'Booking report', 'titan-zero-trades-front-desk' ) . '</h1>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label>' . esc_html__( 'From', 'titan-zero-trades-front-desk' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'titan-zero-trades-front-desk' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> ';
		echo '<button class="button" type="submit">' . esc_html__( 'Show', 'titan-zero-trades-front-desk' ) . '</button></form>';

		if ( 'no_company' === $built['error'] ) {
			echo '<p>' . esc_html__( 'Set up your business first. The report applies to one business at a time.', 'titan-zero-trades-front-desk' ) . '</p></div>';
			return;
		}
		if ( '' !== $built['error'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The report could not be built from the stored appointments, so no figures are shown. Check the date range and try again.', 'titan-zero-trades-front-desk' ) . '</p></div></div>';
			return;
		}
		if ( $built['truncated'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The date range has more stored records than one report can read. Narrow the range. CSV export is off until it fits.', 'titan-zero-trades-front-desk' ) . '</p></div>';
		}
		$summary = $built['summary'];
		$totals  = $summary['totals'];
		echo '<h2>' . esc_html__( 'Appointments', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p>' . esc_html( sprintf(
			/* translators: 1: appointments, 2: cancelled, 3: active. */
			__( '%1$d appointments, %2$d cancelled, %3$d active.', 'titan-zero-trades-front-desk' ),
			$totals['appointments'], $totals['cancelled'], $totals['active']
		) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Appointments', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Cancelled', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Active', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
		foreach ( $summary['days'] as $day ) {
			echo '<tr><td>' . esc_html( $day['date'] ) . '</td><td>' . esc_html( (string) $day['appointments'] ) . '</td><td>' . esc_html( (string) $day['cancelled'] ) . '</td><td>' . esc_html( (string) $day['active'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( ! $built['truncated'] ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'tzfd_booking_report_csv', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'tzfd_booking_report_csv' ) ) . '">' . esc_html__( 'Download CSV', 'titan-zero-trades-front-desk' ) . '</a></p>';
		}

		echo '<h2>' . esc_html__( 'Not measured yet', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p>' . esc_html__( 'This plugin does not store these, so the report does not show them: lead source, first-response time, revenue and refunds, location and service duration (utilisation), and enquiry-to-booking conversion.', 'titan-zero-trades-front-desk' ) . '</p>';
		echo '</div>';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- check_admin_referer() below verifies the nonce.
	public static function handle_csv() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to export this report.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'tzfd_booking_report_csv' );
		list( $from, $to ) = self::range(
			isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : null,
			isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : null
		);
		$built = self::build( $from, $to );
		if ( null === $built['summary'] || $built['truncated'] ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$csv = TZFD_Booking_Reports::csv( array( 'date', 'appointments', 'cancelled', 'active' ), TZFD_Booking_Report_Summary::csv_rows( $built['summary'] ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="booking-report-' . $from . '-to-' . $to . '.csv"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body; cells are neutralised by TZFD_Booking_Reports::csv().
		echo $csv;
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
