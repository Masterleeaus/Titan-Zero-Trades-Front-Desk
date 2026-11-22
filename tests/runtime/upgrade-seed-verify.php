<?php
/**
 * Upgrade and downgrade data checks for upgrade-from-3.0.128.sh. Runs on the disposable site via WP-CLI.
 *   TZFD_RT_PHASE=seed   (on 3.0.128)  creates bookings and settings, writes a fingerprint file
 *   TZFD_RT_PHASE=verify (after swap)  checks the same bookings and settings survived, with the same content
 * Environment: TZFD_RT_FINGERPRINT (path of the JSON fingerprint file, outside the plugin folder).
 */
if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- run through WP-CLI\n";
	exit( 1 );
}
global $wpdb;
$tzfd_phase = getenv( 'TZFD_RT_PHASE' ) ?: '';
$tzfd_local_file  = getenv( 'TZFD_RT_FINGERPRINT' ) ?: '';
$tzfd_table = $wpdb->prefix . 'tz_bookings';
wp_set_current_user( 1 );

if ( 'seed' === $tzfd_phase ) {
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-tables.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-intake-mode.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-final-booking-write.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-transaction-storage.php';
	require_once WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-booking-create-transaction.php';
	$tzfd_ids = array();
	for ( $i = 1; $i <= 3; $i++ ) {
		$tzfd_local_params = array( 'formid' => 1, 'name' => 'Upgrade Customer ' . $i, 'email' => "upgrade$i@example.test", 'notes' => 'seeded before upgrade' );
		$tzfd_local_row    = array(
			'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => $tzfd_local_params['email'],
			'posted_data' => serialize( $tzfd_local_params ), 'data' => 'UPGRADE CUSTOMER ' . $i, 'whoadded' => '1', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- posted_data must hold the plugin's own PHP-serialized format
		);
		$tzfd_local_res = TZFD_Booking_Create_Transaction::create( $wpdb, 1, $tzfd_local_row, $tzfd_local_params, TZFD_Intake_Mode::MODE_INSTANT );
		if ( 'success' !== ( $tzfd_local_res['status'] ?? '' ) ) {
			echo esc_html( 'FAIL seed -- booking ' . (int) $i . ' not created on 3.0.128 (' . ( $tzfd_local_res['status'] ?? 'none' ) . ')' ) . "\n";
			exit( 1 );
		}
		$tzfd_ids[] = (int) $tzfd_local_res['id'];
	}
	update_option( 'tz_fd_ai_system_prompt', 'upgrade-sentinel-prompt' );
	update_option( 'tz_fd_ai_services', array( 'basic' => true, 'sentinel' => 'upgrade' ) );
	// Legacy AI state (issue #17): a stored credential and one saved chat must survive the upgrade and the downgrade.
	update_option( 'tz_fd_ai_api_key', 'sk-UPG-sentinel-key', false );
	$tzfd_chat = $wpdb->prefix . 'tz_ai_conversations';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS `$tzfd_chat` ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_email VARCHAR(190) NOT NULL DEFAULT '', messages LONGTEXT NULL, draft LONGTEXT NULL, created DATETIME NULL, PRIMARY KEY (id), KEY user_email (user_email) ) DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site, legacy table shape
	$wpdb->insert( $tzfd_chat, array( 'user_email' => 'upgrade-chat@example.test', 'messages' => 'seeded chat', 'draft' => '', 'created' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site
	$tzfd_print = array();
	foreach ( $tzfd_ids as $tzfd_local_id ) {
		$tzfd_local_r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, company_id, notifyto, data, posted_data FROM %i WHERE id=%d', $tzfd_table, $tzfd_local_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read, bypasses the object cache
		$tzfd_print[ $tzfd_local_id ] = md5( wp_json_encode( $tzfd_local_r ) );
	}
	file_put_contents( $tzfd_local_file, wp_json_encode( array( // phpcs:ignore WordPress.WP.AlternativeFunctions -- scratch fingerprint file outside the plugin, written on the disposable site
		'ids' => $tzfd_ids, 'prints' => $tzfd_print,
		'ai_prompt' => get_option( 'tz_fd_ai_system_prompt' ), 'ai_services' => get_option( 'tz_fd_ai_services' ),
		'ai_key' => get_option( 'tz_fd_ai_api_key' ), 'chat_rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$tzfd_chat`" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site
	) ) );
	echo 'PASS seed -- ' . count( $tzfd_ids ) . " bookings and 2 settings written on 3.0.128\n";
	return;
}

if ( 'verify' === $tzfd_phase ) {
	ob_start(); // Output is buffered so the exit status can reflect any FAIL line before it is printed.
	$tzfd_saved = json_decode( (string) file_get_contents( $tzfd_local_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- scratch fingerprint file outside the plugin, read on the disposable site
	if ( ! is_array( $tzfd_saved ) ) {
		echo "FAIL verify -- no fingerprint file\n";
		exit( 1 );
	}
	$tzfd_ok = 0; $tzfd_local_bad = array();
	foreach ( $tzfd_saved['ids'] as $tzfd_local_id ) {
		$tzfd_local_r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, company_id, notifyto, data, posted_data FROM %i WHERE id=%d', $tzfd_table, $tzfd_local_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read, bypasses the object cache
		if ( is_array( $tzfd_local_r ) && md5( wp_json_encode( $tzfd_local_r ) ) === $tzfd_saved['prints'][ $tzfd_local_id ] ) { $tzfd_ok++; } else { $tzfd_local_bad[] = $tzfd_local_id; }
	}
	echo esc_html( ( empty( $tzfd_local_bad ) ? 'PASS' : 'FAIL' ) . ' bookings survive -- ' . (int) $tzfd_ok . ' of ' . count( $tzfd_saved['ids'] ) . ' byte-identical' . ( $tzfd_local_bad ? ' (changed: ' . implode( ',', array_map( 'intval', $tzfd_local_bad ) ) . ')' : '' ) ) . "\n";
	$tzfd_prompt = get_option( 'tz_fd_ai_system_prompt' );
	echo ( $tzfd_prompt === $tzfd_saved['ai_prompt'] ? 'PASS' : 'FAIL' ) . " AI prompt setting survives\n";
	echo ( get_option( 'tz_fd_ai_services' ) == $tzfd_saved['ai_services'] ? 'PASS' : 'FAIL' ) . " AI services setting survives\n";
	$tzfd_chat = $wpdb->prefix . 'tz_ai_conversations';
	$tzfd_chat_now = $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tzfd_chat ) . "'" ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$tzfd_chat`" ) : -1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site
	echo ( get_option( 'tz_fd_ai_api_key' ) === ( $tzfd_saved['ai_key'] ?? null ) ? 'PASS' : 'FAIL' ) . " AI credential is not removed by activation\n";
	echo ( $tzfd_chat_now === (int) ( $tzfd_saved['chat_rows'] ?? -2 ) ? 'PASS' : 'FAIL' ) . ' saved AI chat rows survive (' . (int) $tzfd_chat_now . " now)\n";
	$tzfd_new = array( 'tz_slot_holds', 'tz_calls', 'tz_call_events' );
	foreach ( $tzfd_new as $tzfd_bare ) {
		$tzfd_has = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $tzfd_bare ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
		echo ( $tzfd_has ? 'PASS' : 'FAIL' ) . ' table ' . esc_html( $tzfd_bare ) . " present after activation on this version\n";
	}
	$tzfd_local_outbox = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'tz_booking_actions' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
	echo ( $tzfd_local_outbox ? 'PASS' : 'FAIL' ) . " tz_booking_actions present after activation on this version\n";
	// Both versions may report the same number, so the check is which source folder is actually loaded.
	$tzfd_expect = getenv( 'TZFD_RT_EXPECT_DIR' ) ?: '';
	$tzfd_active = realpath( WP_PLUGIN_DIR . '/titan-zero-trades-front-desk' );
	echo ( '' !== $tzfd_expect && realpath( $tzfd_expect ) === $tzfd_active ? 'PASS' : 'FAIL' ) . ' active plugin folder is the expected source (' . esc_html( (string) $tzfd_active ) . ")\n";
	echo 'Version reported: ' . esc_html( defined( 'TZFD_VERSION' ) ? TZFD_VERSION : 'unknown' ) . "\n";
	$tzfd_out = (string) ob_get_clean();
	echo $tzfd_out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped line by line above
	exit( preg_match( '/^FAIL /m', $tzfd_out ) ? 1 : 0 );
}

if ( 'schema' === $tzfd_phase ) {
	// Version-neutral check for a base release whose booking schema differs (no shared seed data): the active
	// folder, the version in the plugin header, and (TZFD_RT_CHECK_TABLES=1) the tables the current release creates.
	global $wpdb;
	ob_start();
	$tzfd_expect = getenv( 'TZFD_RT_EXPECT_DIR' ) ?: '';
	$tzfd_active = realpath( WP_PLUGIN_DIR . '/titan-zero-trades-front-desk' );
	echo ( '' !== $tzfd_expect && realpath( $tzfd_expect ) === $tzfd_active ? 'PASS' : 'FAIL' ) . ' active plugin folder is the expected source (' . esc_html( (string) $tzfd_active ) . ")\n";
	$tzfd_want = getenv( 'TZFD_RT_EXPECT_VERSION' ) ?: '';
	$tzfd_read = '';
	foreach ( array( 'titan-zero-front-desk.php', 'titan-zero-trades-front-desk.php' ) as $tzfd_main ) {
		if ( is_file( (string) $tzfd_active . '/' . $tzfd_main ) ) {
			$tzfd_read = (string) ( get_file_data( (string) $tzfd_active . '/' . $tzfd_main, array( 'Version' => 'Version' ) )['Version'] ?? '' );
			break;
		}
	}
	echo ( '' !== $tzfd_want && $tzfd_want === $tzfd_read ? 'PASS' : 'FAIL' ) . ' plugin header version is ' . esc_html( $tzfd_want ) . ' (read ' . esc_html( $tzfd_read ) . ")\n";
	if ( '1' === getenv( 'TZFD_RT_CHECK_TABLES' ) ) {
		foreach ( array( 'tz_bookings', 'tz_slot_holds', 'tz_calls', 'tz_call_events', 'tz_booking_actions' ) as $tzfd_bare ) {
			$tzfd_has = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $tzfd_bare ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
			echo ( $tzfd_has ? 'PASS' : 'FAIL' ) . ' table ' . esc_html( $tzfd_bare ) . " present after activation\n";
		}
	}
	$tzfd_out = (string) ob_get_clean();
	echo $tzfd_out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped line by line above
	exit( preg_match( '/^FAIL /m', $tzfd_out ) ? 1 : 0 );
}

echo "FAIL harness -- set TZFD_RT_PHASE to seed, verify or schema\n";
exit( 1 );
