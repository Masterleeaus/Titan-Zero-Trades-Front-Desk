<?php
/**
 * Booking page helper for the setup wizard.
 *
 * Lists published pages, creates a new booking page that holds the plugin's
 * booking form shortcode, and checks that a chosen page is valid.
 *
 * @package titan-zero-trades-front-desk
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-booking-view.php';

final class TZFD_Booking_Page {

	/**
	 * Booking form shortcode. Matches TZFD_FrontDeskPlugin::$shorttag (tzfd-main-class.inc.php)
	 * and the add_shortcode() registration in tzfd-bootstrap.inc.php.
	 */
	const SHORTCODE = '[TZFD_FRONT_DESK]';

	/**
	 * Calendars of the current company, keyed by calendar ID, for the wizard's calendar select.
	 *
	 * @return array<int, string>
	 */
	public static function calendar_choices() {
		return TZFD_Booking_View::calendar_names();
	}

	/**
	 * Whether the ID is a calendar of the current company.
	 *
	 * @param int $calendar_id Calendar ID. 0 means the default calendar, which is always valid.
	 * @return bool
	 */
	public static function is_valid_calendar( $calendar_id ) {
		$calendar_id = absint( $calendar_id );
		if ( 0 === $calendar_id ) {
			return true;
		}

		return array_key_exists( $calendar_id, self::calendar_choices() );
	}

	/**
	 * Published pages for the wizard's page picker.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public static function choices() {
		$pages = get_pages(
			array(
				'post_status'  => 'publish',
				'sort_column'  => 'post_title',
				'sort_order'   => 'ASC',
				'number'       => 200,
			)
		);

		$choices = array();
		foreach ( (array) $pages as $page ) {
			$choices[] = array(
				'id'    => absint( $page->ID ),
				'title' => (string) $page->post_title,
			);
		}

		return $choices;
	}

	/**
	 * Create a published page that holds the booking form shortcode.
	 *
	 * @param string $title       Page title. Defaults to "Book an appointment".
	 * @param int    $calendar_id Calendar the page books for. 0 or an unknown ID uses the default calendar.
	 * @return int|WP_Error New page ID, or WP_Error on failure.
	 */
	public static function create( $title = '', $calendar_id = 0 ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'tzfd_forbidden',
				__( 'You do not have permission to create a booking page.', 'titan-zero-trades-front-desk' )
			);
		}

		$title = sanitize_text_field( (string) $title );
		if ( '' === $title ) {
			$title = __( 'Book an appointment', 'titan-zero-trades-front-desk' );
		}

		$calendar_id = absint( $calendar_id );
		$content     = self::SHORTCODE;
		if ( $calendar_id > 0 && self::is_valid_calendar( $calendar_id ) ) {
			$content = '[TZFD_FRONT_DESK calendar="' . $calendar_id . '"]';
		}

		// Empty post_name lets wp_insert_post() build a unique slug, so an existing page is never overwritten.
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => '',
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		if ( 0 === (int) $page_id ) {
			return new WP_Error(
				'tzfd_create_failed',
				__( 'The booking page could not be created.', 'titan-zero-trades-front-desk' )
			);
		}

		return absint( $page_id );
	}

	/**
	 * Permalink for a published page.
	 *
	 * @param int $page_id Page ID.
	 * @return string Permalink, or an empty string if the page is not published.
	 */
	public static function url_for( $page_id ) {
		$page_id = absint( $page_id );
		if ( ! self::is_published_page( $page_id ) ) {
			return '';
		}

		return (string) get_permalink( $page_id );
	}

	/**
	 * Whether the ID is a published page the wizard may use.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	public static function is_valid_choice( $page_id ) {
		return self::is_published_page( absint( $page_id ) );
	}

	/**
	 * Shared check for a published page.
	 *
	 * @param int $page_id Sanitised page ID.
	 * @return bool
	 */
	private static function is_published_page( $page_id ) {
		return $page_id > 0
			&& 'page' === get_post_type( $page_id )
			&& 'publish' === get_post_status( $page_id );
	}
}
