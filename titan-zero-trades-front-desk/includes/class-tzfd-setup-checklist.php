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
