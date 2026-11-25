<?php
/**
 * Helper for oversell-http-tests.sh (issue #389, runtime A18). Runs inside WordPress via WP-CLI:
 *   TZFD_OS_MODE=setup   wp eval-file tests/runtime/oversell-state.php --user=1
 *   TZFD_OS_MODE=count   TZFD_OS_DATE=<Y-m-d> TZFD_OS_SLOT=<start/end> wp eval-file ... (occupied bookings on that slot)
 *   TZFD_OS_MODE=restore wp eval-file ... (puts back the calendar row that setup changed, and removes the test page)
 *
 * Setup makes calendar 1 one that takes a single booking per slot (capacity 1, 60 minute visits), publishes a page
 * with the calendar shortcode, and prints KEY=value lines for the shell script. The original row is kept in an option
 * until restore runs. The booking CAPTCHA is left as it is: the shell script answers it the way a visitor does.
 *   TZFD_OS_MODE=answer  TZFD_OS_CHALLENGE=<id> (prints the answer the server holds for a challenge id from the
 *                        visitor's rand_code cookie; the only route to the answer without reading the image)
 * Driven by tests/runtime/oversell-http-tests.sh. Not shipped in the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-stored-data.php', 'class-tzfd-scheduling-rules.php' ) as $tzfd_file ) {
	if ( is_readable( $tzfd_inc . $tzfd_file ) ) {
		require_once $tzfd_inc . $tzfd_file;
	}
}

global $wpdb;
$mode     = (string) getenv( 'TZFD_OS_MODE' );
$forms    = $wpdb->prefix . TZFD_Tables::FORMS;
$bookings = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$backup   = 'tzfd_oversell_test_backup';
$form_id  = 1;

if ( 'setup' === $mode ) {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT form_structure, cv_enable_captcha FROM %i WHERE id=%d', $forms, $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup read
	if ( ! is_array( $row ) ) {
		echo "FAIL oversell setup -- calendar 1 does not exist\n";
		exit( 1 );
	}
	update_option( $backup, $row, false );

	$decoded = json_decode( (string) $row['form_structure'] );
	$seeded  = false;
	if ( is_array( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
		foreach ( $decoded[0] as $field ) {
			if ( is_object( $field ) && isset( $field->ftype ) && 'fapp' === $field->ftype ) {
				$field->services = array( (object) array(
					'name' => 'Oversell visit', 'price' => 0, 'duration' => 60, 'capacity' => 1, 'pb' => 0, 'pa' => 0, 'ohindex' => 0,
				) );
				$seeded = true;
			}
		}
	}
	if ( ! $seeded ) {
		echo "FAIL oversell setup -- calendar 1 has no appointment field\n";
		exit( 1 );
	}
	$wpdb->update( $forms, array( 'form_structure' => wp_json_encode( $decoded ) ), array( 'id' => $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture write

	$page_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Oversell test booking form',
		'post_content' => '[TZFD_FRONT_DESK id="' . $form_id . '"]',
	) );
	if ( ! is_int( $page_id ) || $page_id < 1 ) {
		echo "FAIL oversell setup -- could not publish the form page\n";
		exit( 1 );
	}
	update_option( $backup . '_page', $page_id, false );

	echo 'TZFD_OS_PAGE=' . $page_id . "\n";
	echo 'TZFD_OS_DATE=' . gmdate( 'Y-m-d', strtotime( '+30 days' ) ) . "\n";
	exit( 0 );
}

if ( 'answer' === $mode ) {
	// The answer the server stored for one challenge. Only the test harness calls this; the check itself is unchanged.
	$challenge = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) getenv( 'TZFD_OS_CHALLENGE' ) ) );
	$answer    = '' !== $challenge ? get_transient( 'tzfd-captcha-' . $challenge ) : false;
	echo 'TZFD_OS_CODE=' . ( is_string( $answer ) ? $answer : '' ) . "\n";
	exit( 0 );
}

if ( 'count' === $mode ) {
	$date = (string) getenv( 'TZFD_OS_DATE' );
	$slot = (string) getenv( 'TZFD_OS_SLOT' );
	$occupied = 0;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, posted_data FROM %i WHERE formid=%d AND posted_data LIKE %s', $bookings, $form_id, '%' . $wpdb->esc_like( $date ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read, bypasses the object cache
	foreach ( (array) $rows as $row ) {
		$data = TZFD_Stored_Data::decode_booking( (string) $row['posted_data'] );
		if ( ! is_array( $data ) || empty( $data['apps'] ) || ! is_array( $data['apps'] ) ) {
			continue;
		}
		$locked = isset( $data['lock'] );
		foreach ( $data['apps'] as $app ) {
			if ( ( $app['date'] ?? '' ) === $date && ( $app['slot'] ?? '' ) === $slot && TZFD_Scheduling_Rules::is_occupied_status( $app['cancelled'] ?? '', $locked ) ) {
				$occupied++;
			}
		}
	}
	echo 'TZFD_OS_OCCUPIED=' . $occupied . "\n";
	echo 'TZFD_OS_BOOKINGS=' . count( (array) $rows ) . "\n";
	exit( 0 );
}

if ( 'restore' === $mode ) {
	$row = get_option( $backup );
	if ( is_array( $row ) ) {
		$wpdb->update( $forms, array( 'form_structure' => (string) $row['form_structure'], 'cv_enable_captcha' => (string) $row['cv_enable_captcha'] ), array( 'id' => $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture restore
	}
	$page_id = (int) get_option( $backup . '_page' );
	if ( $page_id > 0 ) {
		wp_delete_post( $page_id, true );
	}
	delete_option( $backup );
	delete_option( $backup . '_page' );
	echo "TZFD_OS_RESTORED=1\n";
	exit( 0 );
}

echo "FAIL harness -- unknown TZFD_OS_MODE\n";
exit( 1 );
