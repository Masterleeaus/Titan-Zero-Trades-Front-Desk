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
			'posted_data' => serialize( $tz_params ), 'data' => 'UPGRADE CUSTOMER ' . $i, 'whoadded' => '1', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- posted_data must hold the plugin's own PHP-serialized format
		);
		$tz_res = TZFD_Booking_Create_Transaction::create( $wpdb, 1, $tz_row, $tz_params, TZFD_Intake_Mode::MODE_INSTANT );
		if ( 'success' !== ( $tz_res['status'] ?? '' ) ) {
			echo esc_html( 'FAIL seed -- booking ' . (int) $i . ' not created on 3.0.128 (' . ( $tz_res['status'] ?? 'none' ) . ')' ) . "\n";
			exit( 1 );
		}
		$tz_ids[] = (int) $tz_res['id'];
	}
	update_option( 'tz_fd_ai_system_prompt', 'upgrade-sentinel-prompt' );
	update_option( 'tz_fd_ai_services', array( 'basic' => true, 'sentinel' => 'upgrade' ) );
	// Legacy AI state (issue #17): a stored credential and one saved chat must survive the upgrade and the downgrade.
	update_option( 'tz_fd_ai_api_key', 'sk-UPG-sentinel-key', false );
	$tz_chat = $wpdb->prefix . 'tz_ai_conversations';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS `$tz_chat` ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_email VARCHAR(190) NOT NULL DEFAULT '', messages LONGTEXT NULL, draft LONGTEXT NULL, created DATETIME NULL, PRIMARY KEY (id), KEY user_email (user_email) ) DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site, legacy table shape
	$wpdb->insert( $tz_chat, array( 'user_email' => 'upgrade-chat@example.test', 'messages' => 'seeded chat', 'draft' => '', 'created' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site
	$tz_print = array();
	foreach ( $tz_ids as $tz_id ) {
		$tz_r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, company_id, notifyto, data, posted_data FROM %i WHERE id=%d', $tz_table, $tz_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read, bypasses the object cache
		$tz_print[ $tz_id ] = md5( wp_json_encode( $tz_r ) );
	}
	file_put_contents( $tz_file, wp_json_encode( array( // phpcs:ignore WordPress.WP.AlternativeFunctions -- scratch fingerprint file outside the plugin, written on the disposable site
		'ids' => $tz_ids, 'prints' => $tz_print,
		'ai_prompt' => get_option( 'tz_fd_ai_system_prompt' ), 'ai_services' => get_option( 'tz_fd_ai_services' ),
		'ai_key' => get_option( 'tz_fd_ai_api_key' ), 'chat_rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$tz_chat`" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site
	) ) );
	echo 'PASS seed -- ' . count( $tz_ids ) . " bookings and 2 settings written on 3.0.128\n";
	return;
}

if ( 'verify' === $tz_phase ) {
	ob_start(); // Output is buffered so the exit status can reflect any FAIL line before it is printed.
	$tz_saved = json_decode( (string) file_get_contents( $tz_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- scratch fingerprint file outside the plugin, read on the disposable site
	if ( ! is_array( $tz_saved ) ) {
		echo "FAIL verify -- no fingerprint file\n";
		exit( 1 );
	}
	$tz_ok = 0; $tz_bad = array();
	foreach ( $tz_saved['ids'] as $tz_id ) {
		$tz_r = $wpdb->get_row( $wpdb->prepare( 'SELECT id, company_id, notifyto, data, posted_data FROM %i WHERE id=%d', $tz_table, $tz_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw row read, bypasses the object cache
		if ( is_array( $tz_r ) && md5( wp_json_encode( $tz_r ) ) === $tz_saved['prints'][ $tz_id ] ) { $tz_ok++; } else { $tz_bad[] = $tz_id; }
	}
	echo esc_html( ( empty( $tz_bad ) ? 'PASS' : 'FAIL' ) . ' bookings survive -- ' . (int) $tz_ok . ' of ' . count( $tz_saved['ids'] ) . ' byte-identical' . ( $tz_bad ? ' (changed: ' . implode( ',', array_map( 'intval', $tz_bad ) ) . ')' : '' ) ) . "\n";
	$tz_prompt = get_option( 'tz_fd_ai_system_prompt' );
	echo ( $tz_prompt === $tz_saved['ai_prompt'] ? 'PASS' : 'FAIL' ) . " AI prompt setting survives\n";
	echo ( get_option( 'tz_fd_ai_services' ) == $tz_saved['ai_services'] ? 'PASS' : 'FAIL' ) . " AI services setting survives\n";
	$tz_chat = $wpdb->prefix . 'tz_ai_conversations';
	$tz_chat_now = $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tz_chat ) . "'" ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$tz_chat`" ) : -1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable site
	echo ( get_option( 'tz_fd_ai_api_key' ) === ( $tz_saved['ai_key'] ?? null ) ? 'PASS' : 'FAIL' ) . " AI credential is not removed by activation\n";
	echo ( $tz_chat_now === (int) ( $tz_saved['chat_rows'] ?? -2 ) ? 'PASS' : 'FAIL' ) . ' saved AI chat rows survive (' . (int) $tz_chat_now . " now)\n";
	$tz_new = array( 'tz_slot_holds', 'tz_calls', 'tz_call_events' );
	foreach ( $tz_new as $tz_bare ) {
		$tz_has = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $tz_bare ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
		echo ( $tz_has ? 'PASS' : 'FAIL' ) . ' table ' . esc_html( $tz_bare ) . " present after activation on this version\n";
	}
	$tz_outbox = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'tz_booking_actions' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
	echo ( $tz_outbox ? 'PASS' : 'FAIL' ) . " tz_booking_actions present after activation on this version\n";
	// Both versions may report the same number, so the check is which source folder is actually loaded.
	$tz_expect = getenv( 'TZFD_RT_EXPECT_DIR' ) ?: '';
	$tz_active = realpath( WP_PLUGIN_DIR . '/titan-zero-trades-front-desk' );
	echo ( '' !== $tz_expect && realpath( $tz_expect ) === $tz_active ? 'PASS' : 'FAIL' ) . ' active plugin folder is the expected source (' . esc_html( (string) $tz_active ) . ")\n";
	echo 'Version reported: ' . esc_html( defined( 'TZFD_VERSION' ) ? TZFD_VERSION : 'unknown' ) . "\n";
	$tz_out = (string) ob_get_clean();
	echo $tz_out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped line by line above
	exit( preg_match( '/^FAIL /m', $tz_out ) ? 1 : 0 );
}

if ( 'schema' === $tz_phase ) {
	// Version-neutral check for a base release whose booking schema differs (no shared seed data): the active
	// folder, the version in the plugin header, and (TZFD_RT_CHECK_TABLES=1) the tables the current release creates.
	global $wpdb;
	ob_start();
	$tz_expect = getenv( 'TZFD_RT_EXPECT_DIR' ) ?: '';
	$tz_active = realpath( WP_PLUGIN_DIR . '/titan-zero-trades-front-desk' );
	echo ( '' !== $tz_expect && realpath( $tz_expect ) === $tz_active ? 'PASS' : 'FAIL' ) . ' active plugin folder is the expected source (' . esc_html( (string) $tz_active ) . ")\n";
	$tz_want = getenv( 'TZFD_RT_EXPECT_VERSION' ) ?: '';
	$tz_read = '';
	foreach ( array( 'titan-zero-front-desk.php', 'titan-zero-trades-front-desk.php' ) as $tz_main ) {
		if ( is_file( (string) $tz_active . '/' . $tz_main ) ) {
			$tz_read = (string) ( get_file_data( (string) $tz_active . '/' . $tz_main, array( 'Version' => 'Version' ) )['Version'] ?? '' );
			break;
		}
	}
	echo ( '' !== $tz_want && $tz_want === $tz_read ? 'PASS' : 'FAIL' ) . ' plugin header version is ' . esc_html( $tz_want ) . ' (read ' . esc_html( $tz_read ) . ")\n";
	if ( '1' === getenv( 'TZFD_RT_CHECK_TABLES' ) ) {
		foreach ( array( 'tz_bookings', 'tz_slot_holds', 'tz_calls', 'tz_call_events', 'tz_booking_actions' ) as $tz_bare ) {
			$tz_has = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $tz_bare ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, must not be cached
			echo ( $tz_has ? 'PASS' : 'FAIL' ) . ' table ' . esc_html( $tz_bare ) . " present after activation\n";
		}
	}
	$tz_out = (string) ob_get_clean();
	echo $tz_out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped line by line above
	exit( preg_match( '/^FAIL /m', $tz_out ) ? 1 : 0 );
}

echo "FAIL harness -- set TZFD_RT_PHASE to seed, verify or schema\n";
exit( 1 );
