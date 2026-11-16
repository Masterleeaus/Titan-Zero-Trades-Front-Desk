<?php
/**
 * Upgrade and downgrade data checks for upgrade-from-3.0.128.sh. Runs on the disposable site via WP-CLI.
 *   TZFD_RT_PHASE=seed   (on 3.0.128)  creates bookings and settings, writes a fingerprint file
 *   TZFD_RT_PHASE=verify (after swap)  checks the same bookings and settings survived, with the same content
 * Environment: TZFD_RT_FINGERPRINT (path of the JSON fingerprint file, outside the plugin folder).
 */
if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- run through WP-CLI\n";
	return;
}
global $wpdb;
$tz_phase = getenv( 'TZFD_RT_PHASE' ) ?: '';
$tz_file  = getenv( 'TZFD_RT_FINGERPRINT' ) ?: '';
$tz_table = $wpdb->prefix . 'tz_bookings';
wp_set_current_user( 1 );

if ( 'seed' === $tz_phase ) {
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-tables.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-intake-mode.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-final-booking-write.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-transaction-storage.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-booking-create-transaction.php';
	$tz_ids = array();
	for ( $i = 1; $i <= 3; $i++ ) {
		$tz_params = array( 'formid' => 1, 'name' => 'Upgrade Customer ' . $i, 'email' => "upgrade$i@example.test", 'notes' => 'seeded before upgrade' );
		$tz_row    = array(
			'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => $tz_params['email'],
			'posted_data' => serialize( $tz_params ), 'data' => 'UPGRADE CUSTOMER ' . $i, 'whoadded' => '1',
		);
		$tz_res = TZFD_Booking_Create_Transaction::create( $wpdb, 1, $tz_row, $tz_params, TZFD_Intake_Mode::MODE_INSTANT );
		if ( 'success' !== ( $tz_res['status'] ?? '' ) ) {
			echo 'FAIL seed -- booking ' . $i . ' not created on 3.0.128 (' . ( $tz_res['status'] ?? 'none' ) . ")\n";
			return;
		}
		$tz_ids[] = (int) $tz_res['id'];
	}
	update_option( 'tz_fd_ai_system_prompt', 'upgrade-sentinel-prompt' );
	update_option( 'tz_fd_ai_services', array( 'basic' => true, 'sentinel' => 'upgrade' ) );
	$tz_print = array();
	foreach ( $tz_ids as $tz_id ) {
		$tz_r = $wpdb->get_row( $wpdb->prepare( "SELECT id, company_id, notifyto, data, posted_data FROM $tz_table WHERE id=%d", $tz_id ), ARRAY_A );
		$tz_print[ $tz_id ] = md5( wp_json_encode( $tz_r ) );
	}
	file_put_contents( $tz_file, wp_json_encode( array(
		'ids' => $tz_ids, 'prints' => $tz_print,
		'ai_prompt' => get_option( 'tz_fd_ai_system_prompt' ), 'ai_services' => get_option( 'tz_fd_ai_services' ),
	) ) );
	echo 'PASS seed -- ' . count( $tz_ids ) . " bookings and 2 settings written on 3.0.128\n";
	return;
}

if ( 'verify' === $tz_phase ) {
	$tz_saved = json_decode( (string) file_get_contents( $tz_file ), true );
	if ( ! is_array( $tz_saved ) ) {
		echo "FAIL verify -- no fingerprint file\n";
		return;
	}
	$tz_ok = 0; $tz_bad = array();
	foreach ( $tz_saved['ids'] as $tz_id ) {
		$tz_r = $wpdb->get_row( $wpdb->prepare( "SELECT id, company_id, notifyto, data, posted_data FROM $tz_table WHERE id=%d", $tz_id ), ARRAY_A );
		if ( is_array( $tz_r ) && md5( wp_json_encode( $tz_r ) ) === $tz_saved['prints'][ $tz_id ] ) { $tz_ok++; } else { $tz_bad[] = $tz_id; }
	}
	echo ( empty( $tz_bad ) ? 'PASS' : 'FAIL' ) . ' bookings survive -- ' . $tz_ok . ' of ' . count( $tz_saved['ids'] ) . ' byte-identical' . ( $tz_bad ? ' (changed: ' . implode( ',', $tz_bad ) . ')' : '' ) . "\n";
	$tz_prompt = get_option( 'tz_fd_ai_system_prompt' );
	echo ( $tz_prompt === $tz_saved['ai_prompt'] ? 'PASS' : 'FAIL' ) . " AI prompt setting survives\n";
	echo ( get_option( 'tz_fd_ai_services' ) == $tz_saved['ai_services'] ? 'PASS' : 'FAIL' ) . " AI services setting survives\n";
	$tz_new = array( 'tz_slot_holds', 'tz_calls', 'tz_call_events' );
	foreach ( $tz_new as $tz_bare ) {
		$tz_has = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $tz_bare ) );
		echo ( $tz_has ? 'PASS' : 'FAIL' ) . ' table ' . $tz_bare . " present after activation on this version\n";
	}
	$tz_outbox = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'tz_booking_actions' ) );
	echo ( $tz_outbox ? 'PASS' : 'GAP' ) . " tz_booking_actions present after activation (absent is the known outbox gap)\n";
	// Both versions may report the same number, so the check is which source folder is actually loaded.
	$tz_expect = getenv( 'TZFD_RT_EXPECT_DIR' ) ?: '';
	$tz_active = realpath( WP_PLUGIN_DIR . '/titan-zero-trades-front-desk' );
	echo ( '' !== $tz_expect && realpath( $tz_expect ) === $tz_active ? 'PASS' : 'FAIL' ) . ' active plugin folder is the expected source (' . $tz_active . ")\n";
	echo 'Version reported: ' . ( defined( 'TZFD_VERSION' ) ? TZFD_VERSION : 'unknown' ) . "\n";
	return;
}

echo "FAIL harness -- set TZFD_RT_PHASE to seed or verify\n";
