<?php
/**
 * Real-WordPress checks for a stored booking row that cannot be read by public availability (issue #183).
 *
 * Owner decision: the row is skipped, not fatal. Public booking keeps working for the calendar, the row is logged, and
 * it is flagged to the admin until it is repaired or removed. These checks cover the detection, the per-calendar flag,
 * and the admin notice. The feed build itself is checked in the browser run recorded on the issue.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/bad-booking-row-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_br'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_br_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_br'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-public-slot-records.php' ) || ! is_readable( $tzfd_inc . 'class-tzfd-stored-data.php' ) ) {
	tzfd_br_ok( 'bad-row: class files readable', false, 'public slot records or stored data class not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-stored-data.php';
require_once $tzfd_inc . 'class-tzfd-public-slot-records.php';

$BR = 'TZFD_Public_Slot_Records';
$br_saved = get_option( $BR::SKIPPED_OPTION, null );
delete_option( $BR::SKIPPED_OPTION );

$br_good = serialize( array( 'apps' => array( array( 'service' => 'Service 1', 'date' => '2026-11-20', 'slot' => '10:30/11:30' ) ) ) );
$br_bad  = 'a:1:{s:4:"apps";';   // damaged: truncated serialized payload
$br_rows = array(
	(object) array( 'id' => 5, 'posted_data' => $br_good ),
	(object) array( 'id' => 9, 'posted_data' => $br_bad ),
);

// 1. Detection: only the unreadable row is reported.
$br_ids = $BR::unreadable_row_ids( $br_rows );
tzfd_br_ok( 'bad-row: only the unreadable row is detected', array( 9 ) === $br_ids, 'got ' . implode( ',', $br_ids ) );

// 2. Flag: the skipped row is recorded for its calendar.
$BR::record_skipped_rows( 7, $br_ids );
$br_flag = get_option( $BR::SKIPPED_OPTION, array() );
tzfd_br_ok( 'bad-row: the skipped row is flagged for its calendar', isset( $br_flag[7] ) && array( 9 ) === $br_flag[7] );

// 3. The flag is replaced on the next build: a repaired row drops out.
$BR::record_skipped_rows( 7, array() );
$br_flag = get_option( $BR::SKIPPED_OPTION, array() );
tzfd_br_ok( 'bad-row: a repaired row clears its flag on the next build', ! isset( $br_flag[7] ) );

// 4. Admin notice: shown to a Front Desk user, names the calendar and the row, and does not hide the problem.
// WP-CLI runs as no user unless one is set; the notice is for administrators (tz_fd_access), as in the other runtime tests.
wp_set_current_user( 1 );
$BR::record_skipped_rows( 7, array( 9 ) );
$br_notice = '';
ob_start();
$BR::admin_notice();
$br_notice = ob_get_clean();
tzfd_br_ok( 'bad-row: admin notice names the calendar and the stored booking', false !== strpos( $br_notice, 'calendar 7: booking 9' ), current_user_can( 'tz_fd_access' ) ? '' : 'current user lacks tz_fd_access' );
tzfd_br_ok( 'bad-row: admin notice says public booking still works', false !== strpos( $br_notice, 'Public booking still works' ) );

// 5. A row that is readable and a calendar with no bad rows produce no notice and no flag.
$BR::record_skipped_rows( 7, array() );
ob_start();
$BR::admin_notice();
$br_none = ob_get_clean();
tzfd_br_ok( 'bad-row: no notice when no row is skipped', '' === trim( $br_none ) );

// Restore the option as it was.
if ( null === $br_saved ) { delete_option( $BR::SKIPPED_OPTION ); } else { update_option( $BR::SKIPPED_OPTION, $br_saved, false ); }

echo "bad-row: {$GLOBALS['tzfd_br']['PASS']} passed, {$GLOBALS['tzfd_br']['FAIL']} failed\n";
exit( $GLOBALS['tzfd_br']['FAIL'] > 0 ? 1 : 0 );
