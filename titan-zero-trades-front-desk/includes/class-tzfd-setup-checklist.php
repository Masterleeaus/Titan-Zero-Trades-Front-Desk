<?php
/**
 * First-run checklist for the free plugin (issue #15). Pure PHP for the rules; WordPress reads only in facts().
 *
 * Some steps are detected from stored data (services, a calendar, any booking, any customer record). Others
 * cannot be detected from this plugin (operating hours and reminder settings are not stored as one value), so
 * they are marked by the administrator and labelled as self-reported. Nothing is marked done automatically
 * for a self-reported step.
 *
 * Progress is recorded locally, and only when the administrator has turned on progress recording. Nothing is
 * sent anywhere. Turning it off stops new records; existing records can be cleared from the same screen.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Setup_Checklist {

	const CONSENT_OPTION  = 'tzfd_setup_progress_consent';
	const PROGRESS_OPTION = 'tzfd_setup_progress';
	const MANUAL_OPTION   = 'tzfd_setup_manual';
	const DISCOVERY_OPTION = 'tzfd_setup_discovery';

	/**
	 * Discovery links to optional services. Off by default, and shown only after the administrator opts in AND the
	 * plugin is in use (see discovery_visible()). Each link opens an admin screen; none asks for registration.
	 * There is no separate Pro plugin, so the "pro" link points to local features that are included in this plugin.
	 *
	 * @return array key => label, hint, page (admin.php?page= slug).
	 */
	public static function discovery_links() {
		return array(
			'pro'       => array( 'label' => __( 'Local reports and staff access', 'titan-zero-trades-front-desk' ), 'hint' => __( 'Included in this plugin at no extra cost. There is no separate Pro plugin.', 'titan-zero-trades-front-desk' ), 'page' => 'tzfd-booking-report' ),
			'ai'        => array( 'label' => __( 'Titan Zero AI Assistant (optional, hosted, paid)', 'titan-zero-trades-front-desk' ), 'hint' => __( 'Off until you connect it and record consent. Booking works without it.', 'titan-zero-trades-front-desk' ), 'page' => 'tzfd-ai-services' ),
			'agent'     => array( 'label' => __( 'Titan Zero AI Agent Team (optional, hosted, paid)', 'titan-zero-trades-front-desk' ), 'hint' => __( 'Acts only through the tools you grant. Review its receipts before you grant more.', 'titan-zero-trades-front-desk' ), 'page' => 'tzfd-agent-receipts' ),
		);
	}

	/**
	 * Links show only when the administrator has opted in (default off, so a null or missing option is off) and the
	 * plugin is activated in use: at least one service or calendar exists. Nothing is shown before that.
	 *
	 * @param mixed $opt_in   Stored discovery option; only a literal true counts.
	 * @param array $detected step => bool from facts().
	 */
	public static function discovery_visible( $opt_in, $detected ) {
		$detected = is_array( $detected ) ? $detected : array();
		return true === $opt_in && ( ! empty( $detected['services'] ) || ! empty( $detected['calendar'] ) );
	}

	/** Step key => label, how it is known, and what it means. Order is the order on screen. */
	public static function steps() {
		return array(
			'services'     => array( 'label' => __( 'Service catalogue', 'titan-zero-trades-front-desk' ), 'source' => 'detected', 'hint' => __( 'Add the services you offer. At least one service must exist.', 'titan-zero-trades-front-desk' ) ),
			'calendar'     => array( 'label' => __( 'Staff and calendar', 'titan-zero-trades-front-desk' ), 'source' => 'detected', 'hint' => __( 'Create a calendar (booking form) for the people or places you book.', 'titan-zero-trades-front-desk' ) ),
			'hours'        => array( 'label' => __( 'Operating hours', 'titan-zero-trades-front-desk' ), 'source' => 'self', 'hint' => __( 'Set your own hours. None are assumed.', 'titan-zero-trades-front-desk' ) ),
			'form'         => array( 'label' => __( 'Publish the booking form', 'titan-zero-trades-front-desk' ), 'source' => 'self', 'hint' => __( 'Place the booking form on a page customers can reach. This is not checked automatically.', 'titan-zero-trades-front-desk' ) ),
			'test_booking' => array( 'label' => __( 'Make a test booking', 'titan-zero-trades-front-desk' ), 'source' => 'detected', 'hint' => __( 'Book once from the public form to check the whole path. Any booking counts, so a real one works too.', 'titan-zero-trades-front-desk' ) ),
			'customer'     => array( 'label' => __( 'View a customer record', 'titan-zero-trades-front-desk' ), 'source' => 'detected', 'hint' => __( 'Open a customer record to see what is stored.', 'titan-zero-trades-front-desk' ) ),
			'reminders'    => array( 'label' => __( 'Configure reminders', 'titan-zero-trades-front-desk' ), 'source' => 'self', 'hint' => __( 'Choose when customers get reminders, or leave them off.', 'titan-zero-trades-front-desk' ) ),
		);
	}

	/**
	 * @param array $detected step => bool, from facts(). Steps not listed are not done.
	 * @param array $manual   step => bool, self-reported by the administrator.
	 * @return array steps (key => label, source, done), done_count, total, complete.
	 */
	public static function evaluate( $detected, $manual ) {
		$detected = is_array( $detected ) ? $detected : array();
		$manual   = is_array( $manual ) ? $manual : array();
		$out      = array();
		$done     = 0;
		foreach ( self::steps() as $key => $step ) {
			$is_done = 'detected' === $step['source'] ? ! empty( $detected[ $key ] ) : ! empty( $manual[ $key ] );
			$out[ $key ] = array_merge( $step, array( 'done' => $is_done ) );
			if ( $is_done ) { $done++; }
		}
		$total = count( $out );
		return array( 'steps' => $out, 'done_count' => $done, 'total' => $total, 'complete' => $done === $total );
	}

	/**
	 * Records the first time each step is seen done, but only with consent. Returns the new record. Without
	 * consent, the stored record is returned unchanged and nothing is added.
	 *
	 * @param bool  $consent  Administrator turned progress recording on.
	 * @param array $existing step => unix time of first completion.
	 * @param array $steps    Output of evaluate()['steps'].
	 * @param int   $now      Unix seconds.
	 */
	public static function record( $consent, $existing, $steps, $now ) {
		$existing = is_array( $existing ) ? $existing : array();
		if ( true !== $consent ) { return $existing; }
		foreach ( (array) $steps as $key => $step ) {
			if ( ! empty( $step['done'] ) && ! isset( $existing[ $key ] ) && isset( self::steps()[ $key ] ) ) {
				$existing[ $key ] = (int) $now;
			}
		}
		return $existing;
	}

	/**
	 * Measures from the stored progress record, for this site only. Nothing is measured without consent, so a
	 * site that has not turned progress records on gets measured => false and no figures.
	 *
	 * Only well-formed entries count: a step key in the catalogue with a positive whole-number unix time. Anything
	 * else in the stored option (text, zero, negative, unknown keys) is ignored, never read as a time.
	 *
	 * completion_percent:       recorded steps as a whole percentage of the catalogue.
	 * configured_at:            unix time the last of the six non-booking steps was first seen done, or null.
	 * first_booking_at:         unix time the test_booking step was first seen done, or null.
	 * seconds_to_first_booking: from the earliest recorded step to the first booking, never negative; null if unknown.
	 * converted:                the first booking was seen on or after configuration (configured_at), or false.
	 *                           Candidate definition: its wording is an owner decision (issue #367, T7).
	 *
	 * @param array $record  step => unix time of first completion (see record()).
	 * @param bool  $consent Administrator turned progress recording on.
	 * @return array
	 */
	public static function measures( $record, $consent ) {
		$steps = self::steps();
		if ( true !== $consent ) {
			return array( 'measured' => false, 'steps_done' => null, 'total' => count( $steps ), 'completion_percent' => null, 'complete' => null, 'configured_at' => null, 'first_booking_at' => null, 'seconds_to_first_booking' => null, 'converted' => null );
		}
		$record = is_array( $record ) ? $record : array();
		$known  = array();
		foreach ( $record as $key => $time ) {
			if ( ! isset( $steps[ $key ] ) ) { continue; }
			if ( is_int( $time ) && $time > 0 ) {
				$known[ $key ] = $time;
			} elseif ( is_string( $time ) && ctype_digit( $time ) && (int) $time > 0 ) {
				$known[ $key ] = (int) $time;
			}
		}
		$total   = count( $steps );
		$done    = count( $known );
		$first   = $done > 0 ? min( $known ) : null;
		$booking = isset( $known['test_booking'] ) ? $known['test_booking'] : null;

		$configured_keys = array_diff( array_keys( $steps ), array( 'test_booking' ) );
		$configured_at   = null;
		if ( count( array_intersect_key( $known, array_flip( $configured_keys ) ) ) === count( $configured_keys ) ) {
			$configured_at = max( array_intersect_key( $known, array_flip( $configured_keys ) ) );
		}

		return array(
			'measured'                  => true,
			'steps_done'                => $done,
			'total'                     => $total,
			'completion_percent'        => (int) round( 100 * $done / $total ),
			'complete'                  => $done === $total,
			'configured_at'             => $configured_at,
			'first_booking_at'          => $booking,
			'seconds_to_first_booking'  => ( null !== $booking && null !== $first ) ? max( 0, $booking - $first ) : null,
			'converted'                 => null !== $configured_at && null !== $booking && $booking >= $configured_at,
		);
	}

	/**
	 * Current company facts for detected steps. Reads only counts, never names, emails or other customer fields.
	 * Each read is guarded so that a missing table or company returns false for that step, not an error.
	 *
	 * @return array step => bool
	 */
	public static function facts() {
		global $wpdb;
		$company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
		$out     = array( 'services' => false, 'calendar' => false, 'test_booking' => false, 'customer' => false );
		if ( $company < 1 ) { return $out; }
		if ( class_exists( 'TZFD_Local_Services' ) ) {
			$out['services'] = ! empty( TZFD_Local_Services::list( array( 'limit' => 1 ) ) );
		}
		if ( class_exists( 'TZFD_Tables' ) ) {
			$forms = $wpdb->prefix . TZFD_Tables::FORMS;
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d', $forms, $company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts only.
			$out['calendar'] = null !== $count && (int) $count > 0;
			$bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;
			$count    = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d', $bookings, $company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts only.
			$out['test_booking'] = null !== $count && (int) $count > 0;
			$customers = $wpdb->prefix . 'tzfd_local_customers';
			$count     = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d', $customers, $company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts only.
			$out['customer'] = null !== $count && (int) $count > 0;
		}
		return $out;
	}
}
