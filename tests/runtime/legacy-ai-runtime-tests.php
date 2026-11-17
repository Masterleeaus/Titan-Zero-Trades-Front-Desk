<?php
/**
 * Real-WordPress checks for the Legacy AI data screen (issue #17): nothing is deleted on load, deletion needs a
 * current backup and the typed phrase, bookings and settings survive, and the privacy tools cover historic chats.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/legacy-ai-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL|SKIP|GAP name -- detail". No live external service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_lg'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_lg_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_lg'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-legacy-ai-data.php' ) ) {
	tzfd_lg_ok( 'legacy-ai: class file readable', false, 'class-tzfd-legacy-ai-data.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-legacy-ai-data.php';

global $wpdb;
wp_set_current_user( 1 );

$lg_secret  = 'sk-RT-LEGACY-' . wp_generate_password( 12, false );
$lg_chat    = $wpdb->prefix . 'tz_ai_conversations';
$lg_bookings = $wpdb->prefix . 'tz_bookings';

// Seed the state an earlier version leaves behind: credential, provider routing, configuration and chat rows.
update_option( 'tz_fd_ai_api_key', $lg_secret, false );
update_option( 'tz_fd_ai_model', 'model-rt-1', false );
update_option( 'tz_fd_ai_services', 'Boiler repairs; Drain clearing', false );
update_option( 'tz_fd_ai_system_prompt', 'legacy-rt-prompt', false );
$wpdb->query( "CREATE TABLE IF NOT EXISTS `$lg_chat` (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_email VARCHAR(190) NOT NULL DEFAULT '',
	messages LONGTEXT NULL,
	draft LONGTEXT NULL,
	created DATETIME NULL,
	PRIMARY KEY (id), KEY user_email (user_email)
) DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $lg_chat . '` WHERE user_email LIKE %s', 'rt-legacy-%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( array( 'rt-legacy-a@example.test', 'RT-LEGACY-A@example.test', 'rt-legacy-b@example.test' ) as $lg_mail ) {
	$wpdb->insert( $lg_chat, array( 'user_email' => $lg_mail, 'messages' => 'hello', 'draft' => '', 'created' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$lg_chat_rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_chat`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$lg_booking_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_bookings`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 1. Nothing is deleted when WordPress loads or an admin screen opens.
foreach ( array( 'init', 'wp_loaded', 'admin_init' ) as $lg_hook ) {
	do_action( $lg_hook );
}
tzfd_lg_ok( 'legacy-ai: admin_init, init and wp_loaded delete nothing', get_option( 'tz_fd_ai_api_key' ) === $lg_secret && 'model-rt-1' === get_option( 'tz_fd_ai_model' ) && 'Boiler repairs; Drain clearing' === get_option( 'tz_fd_ai_services' ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_chat`" ) === $lg_chat_rows ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 2. Audit and export run read-only apart from the receipt, and the export never holds the credential value.
$lg_audit = TZFD_Legacy_AI_Data::audit();
tzfd_lg_ok( 'legacy-ai: audit counts the seeded chat rows', $lg_chat_rows === (int) $lg_audit['chats']['rows'] );
tzfd_lg_ok( 'legacy-ai: audit is not backed up before an export', false === $lg_audit['current'] );
$lg_json = TZFD_Legacy_AI_Data::export_json();
tzfd_lg_ok( 'legacy-ai: export produced and receipt recorded', '' !== $lg_json && TZFD_Legacy_AI_Data::backup_current() );
tzfd_lg_ok( 'legacy-ai: export holds no credential value', false === strpos( $lg_json, $lg_secret ) );

// 3. Deletion refusals: wrong phrase, and a stale backup after the data changes.
$lg_r = TZFD_Legacy_AI_Data::delete_scope( 'chats', 'delete please' );
tzfd_lg_ok( 'legacy-ai: chat delete refused with wrong phrase', false === $lg_r['ok'] && 'bad_phrase' === $lg_r['code'] );
$wpdb->insert( $lg_chat, array( 'user_email' => 'rt-legacy-late@example.test', 'messages' => 'late', 'draft' => '', 'created' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$lg_r = TZFD_Legacy_AI_Data::delete_scope( 'chats', TZFD_Legacy_AI_Data::PHRASE );
tzfd_lg_ok( 'legacy-ai: chat delete refused when the backup is stale', false === $lg_r['ok'] && 'no_current_backup' === $lg_r['code'] );
$wpdb->delete( $lg_chat, array( 'user_email' => 'rt-legacy-late@example.test' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
TZFD_Legacy_AI_Data::export_json();

// 4. Connection scope removes the credential and provider routing, keeps configuration and chats.
$lg_r = TZFD_Legacy_AI_Data::delete_scope( 'connection', TZFD_Legacy_AI_Data::PHRASE );
tzfd_lg_ok( 'legacy-ai: connection delete removes credential and provider only', true === $lg_r['ok'] && false === get_option( 'tz_fd_ai_api_key', false ) && false === get_option( 'tz_fd_ai_model', false ) && 'Boiler repairs; Drain clearing' === get_option( 'tz_fd_ai_services' ) );
tzfd_lg_ok( 'legacy-ai: connection delete keeps chat rows', (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_chat`" ) === $lg_chat_rows ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 5. Restore from the export: chats come back, the credential is not restored and is flagged for re-entry.
$lg_restore = TZFD_Legacy_AI_Data::restore_from_json( $lg_json );
tzfd_lg_ok( 'legacy-ai: restore re-adds none of the chats that still exist', true === $lg_restore['ok'] && 0 === $lg_restore['chats_inserted'] && $lg_restore['chats_skipped'] >= $lg_chat_rows );
tzfd_lg_ok( 'legacy-ai: restore does not bring back the credential', false === get_option( 'tz_fd_ai_api_key', false ) && $lg_restore['credentials_to_reenter'] >= 1 );

// 6. Chat delete removes chats only; bookings and configuration are untouched. A fresh backup is taken first,
// because the connection delete above changed the data and the old backup no longer matches it.
$lg_json = TZFD_Legacy_AI_Data::export_json();
$lg_r = TZFD_Legacy_AI_Data::delete_scope( 'chats', TZFD_Legacy_AI_Data::PHRASE );
tzfd_lg_ok( 'legacy-ai: chat delete removes every chat row after backup', true === $lg_r['ok'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_chat`" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
tzfd_lg_ok( 'legacy-ai: bookings count unchanged by legacy deletes', (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_bookings`" ) === $lg_booking_rows ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
tzfd_lg_ok( 'legacy-ai: configuration kept after chat delete', 'Boiler repairs; Drain clearing' === get_option( 'tz_fd_ai_services' ) );

// 7. Restore brings the chats back from the export taken just before the chat delete.
$lg_restore = TZFD_Legacy_AI_Data::restore_from_json( $lg_json );
tzfd_lg_ok( 'legacy-ai: restore re-adds the chat rows from the export', true === $lg_restore['ok'] && $lg_chat_rows === (int) $lg_restore['chats_inserted'] && $lg_chat_rows === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_chat`" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$lg_again = TZFD_Legacy_AI_Data::restore_from_json( $lg_json );
tzfd_lg_ok( 'legacy-ai: a second restore adds nothing', 0 === $lg_again['chats_inserted'] );

// 8. Privacy exporter and eraser are registered and cover historic chats for an exact address.
$lg_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$lg_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
tzfd_lg_ok( 'legacy-ai: privacy exporter and eraser registered', isset( $lg_exporters['tzfd-legacy-ai-chats'] ) && isset( $lg_erasers['tzfd-legacy-ai-chats'] ) );
$lg_export = TZFD_Legacy_AI_Data::privacy_export( 'RT-LEGACY-A@example.test', 1 );
tzfd_lg_ok( 'legacy-ai: privacy export matches the address case-insensitively', 2 === count( $lg_export['data'] ) );
$lg_erase = TZFD_Legacy_AI_Data::privacy_erase( 'rt-legacy-a@example.test', 1 );
tzfd_lg_ok( 'legacy-ai: privacy erase removes that address only', true === $lg_erase['items_removed'] && 0 === count( TZFD_Legacy_AI_Data::privacy_export( 'rt-legacy-a@example.test', 1 )['data'] ) && 1 === count( TZFD_Legacy_AI_Data::privacy_export( 'rt-legacy-b@example.test', 1 )['data'] ) );

// Clean up the seeded chat table and rows so the rest of the run starts from the same place.
$wpdb->query( "DROP TABLE IF EXISTS `$lg_chat`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'tz_fd_ai_services' );
delete_option( 'tz_fd_ai_system_prompt' );
delete_option( TZFD_Legacy_AI_Data::RECEIPT );
delete_option( TZFD_Legacy_AI_Data::RESTORE_LOG );

echo 'Summary (legacy-ai-runtime): ' . (int) $GLOBALS['tzfd_lg']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_lg']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_lg']['FAIL'] > 0 ? 1 : 0 );
