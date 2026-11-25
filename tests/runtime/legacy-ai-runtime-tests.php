<?php
/**
 * Real-WordPress checks for the privacy exporter and eraser that cover historic booking assistant chats (issue #17,
 * kept by owner decision for #360): the tools are registered, match an exact address case-insensitively, erase that
 * address only, and never delete anything on load.
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
if ( ! is_readable( $tzfd_inc . 'class-tzfd-legacy-privacy.php' ) ) {
	tzfd_lg_ok( 'legacy-ai: privacy class file readable', false, 'class-tzfd-legacy-privacy.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-legacy-privacy.php';

global $wpdb;
wp_set_current_user( 1 );

$lg_chat     = $wpdb->prefix . 'tz_ai_conversations';
$lg_bookings = $wpdb->prefix . 'tz_bookings';

// Seed the historic chat table with rows for two addresses, one written in upper case.
$wpdb->query( "CREATE TABLE IF NOT EXISTS `$lg_chat` (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_email VARCHAR(190) NOT NULL DEFAULT '',
	messages LONGTEXT NULL,
	draft LONGTEXT NULL,
	created DATETIME NULL,
	PRIMARY KEY (id), KEY user_email (user_email)
) DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $lg_chat . '` WHERE user_email LIKE %s', 'rt-legacy-%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( array( 'rt-legacy-a@example.test', 'RT-LEGACY-A@example.test', 'rt-legacy-b@example.test', 'rt-legacy-c@example.test' ) as $lg_mail ) {
	$wpdb->insert( $lg_chat, array( 'user_email' => $lg_mail, 'messages' => 'hello', 'draft' => '', 'created' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$lg_chat_rows    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_chat`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$lg_booking_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_bookings`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 1. Nothing is deleted when WordPress loads or an admin screen opens.
foreach ( array( 'init', 'wp_loaded', 'admin_init' ) as $lg_hook ) {
	do_action( $lg_hook );
}
tzfd_lg_ok( 'legacy-ai: admin_init, init and wp_loaded delete no historic chats', (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_chat`" ) === $lg_chat_rows ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 2. Privacy exporter and eraser are registered.
$lg_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$lg_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
tzfd_lg_ok( 'legacy-ai: privacy exporter and eraser registered', isset( $lg_exporters['tzfd-legacy-ai-chats'] ) && isset( $lg_erasers['tzfd-legacy-ai-chats'] ) );

// 3. Export matches the exact address case-insensitively and nothing else.
$lg_export = TZFD_Legacy_Privacy::privacy_export( 'RT-LEGACY-A@example.test', 1 );
tzfd_lg_ok( 'legacy-ai: privacy export matches the address case-insensitively', 2 === count( $lg_export['data'] ) );

// 4. Erase removes that address only and leaves bookings and other people's chats alone.
$lg_erase = TZFD_Legacy_Privacy::privacy_erase( 'rt-legacy-a@example.test', 1 );
tzfd_lg_ok( 'legacy-ai: privacy erase removes that address only', true === $lg_erase['items_removed'] && 0 === count( TZFD_Legacy_Privacy::privacy_export( 'rt-legacy-a@example.test', 1 )['data'] ) && 1 === count( TZFD_Legacy_Privacy::privacy_export( 'rt-legacy-b@example.test', 1 )['data'] ) );
tzfd_lg_ok( 'legacy-ai: bookings count unchanged by privacy erase', (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$lg_bookings`" ) === $lg_booking_rows ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 5. A refused erase changes nothing and says so. The table is read-locked on this connection, so the DELETE is refused:
//    the eraser must report items_retained, delete no row and leave the export intact. A retry then removes the address.
$wpdb->query( 'LOCK TABLES `' . $lg_chat . '` READ' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- forces a refused write for the rollback check
$lg_refused = TZFD_Legacy_Privacy::privacy_erase( 'rt-legacy-b@example.test', 1 );
$wpdb->query( 'UNLOCK TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases the lock taken above
$lg_refused_rows = count( TZFD_Legacy_Privacy::privacy_export( 'rt-legacy-b@example.test', 1 )['data'] );
tzfd_lg_ok( 'legacy-ai: a refused erase reports items_retained and deletes nothing', true === $lg_refused['items_retained'] && false === $lg_refused['items_removed'] && 1 === $lg_refused_rows, 'retained=' . wp_json_encode( $lg_refused['items_retained'] ) . ' rows=' . $lg_refused_rows );
$lg_retry = TZFD_Legacy_Privacy::privacy_erase( 'rt-legacy-b@example.test', 1 );
tzfd_lg_ok( 'legacy-ai: a retry after a refused erase removes that address', true === $lg_retry['items_removed'] && 0 === count( TZFD_Legacy_Privacy::privacy_export( 'rt-legacy-b@example.test', 1 )['data'] ) );

// 6. An erase inside a transaction that is rolled back restores the rows. This needs a transactional table engine.
$lg_engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $lg_chat ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- engine lookup
tzfd_lg_ok( 'legacy-ai: the chat table uses a transactional engine', 'innodb' === strtolower( $lg_engine ), 'engine=' . $lg_engine );
$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback check
$lg_tx        = TZFD_Legacy_Privacy::privacy_erase( 'rt-legacy-c@example.test', 1 );
$lg_tx_gone   = 0 === count( TZFD_Legacy_Privacy::privacy_export( 'rt-legacy-c@example.test', 1 )['data'] );
$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rollback check
$lg_tx_back   = 1 === count( TZFD_Legacy_Privacy::privacy_export( 'rt-legacy-c@example.test', 1 )['data'] );
tzfd_lg_ok( 'legacy-ai: an erase rolled back with its transaction restores the rows', true === $lg_tx['items_removed'] && $lg_tx_gone && $lg_tx_back );

// Clean up the seeded chat table so the rest of the run starts from the same place.
$wpdb->query( "DROP TABLE IF EXISTS `$lg_chat`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

echo 'Summary (legacy-ai-runtime): ' . (int) $GLOBALS['tzfd_lg']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_lg']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_lg']['FAIL'] > 0 ? 1 : 0 );
