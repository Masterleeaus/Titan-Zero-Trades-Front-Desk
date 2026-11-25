<?php
/**
 * Real-WordPress checks for the get-started screen (issue #15): the checklist reads real counts, self-reported
 * steps are marked only by the administrator, progress is recorded only with consent, the screen is shown only
 * to administrators, and the Get started screen shows no admin notice (issue #366).
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/setup-page-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_sp'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_sp_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_sp'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-setup-page.php' ) ) {
	tzfd_sp_ok( 'setup-page: class file readable', false, 'class-tzfd-setup-page.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-setup-checklist.php';
require_once $tzfd_inc . 'class-tzfd-setup-page.php';

wp_set_current_user( 1 );
delete_option( TZFD_Setup_Checklist::CONSENT_OPTION );
delete_option( TZFD_Setup_Checklist::PROGRESS_OPTION );
delete_option( TZFD_Setup_Checklist::MANUAL_OPTION );

$sp_render = function () {
	ob_start();
	TZFD_Setup_Page::render();
	return ob_get_clean();
};

// 1. Checklist on an empty site: every detected step is to do, and the count is shown.
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: administrator sees the checklist', false !== strpos( $sp_html, 'Setup checklist' ) && false !== strpos( $sp_html, 'of 7 steps done.' ) );
tzfd_sp_ok( 'setup-page: operating hours is marked as self-reported', false !== strpos( $sp_html, 'Operating hours</strong>: To do (you mark this)' ) );

// 2. Self-reported steps are marked only by the administrator's save, and show as done.
update_option( TZFD_Setup_Checklist::MANUAL_OPTION, array( 'hours' => true, 'reminders' => false, 'form' => false ), false );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: a self-reported step shows done once marked', false !== strpos( $sp_html, 'Operating hours</strong>: Done (you mark this)' ) );
tzfd_sp_ok( 'setup-page: a self-reported step is not done by default', false !== strpos( $sp_html, 'Configure reminders</strong>: To do (you mark this)' ) );

// 3. Progress records: off by default, nothing written; on, done steps are stamped; off again, nothing new.
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: progress records are off by default', false !== strpos( $sp_html, 'Progress records are off. Nothing is recorded.' ) && false === get_option( TZFD_Setup_Checklist::PROGRESS_OPTION, false ) );
update_option( TZFD_Setup_Checklist::CONSENT_OPTION, true, false );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: progress records on shows the state', false !== strpos( $sp_html, 'Progress records are on.' ) );
$sp_rec = get_option( TZFD_Setup_Checklist::PROGRESS_OPTION, array() );
tzfd_sp_ok( 'setup-page: a done self-reported step is stamped once records are on', isset( $sp_rec['hours'] ) && ! isset( $sp_rec['reminders'] ) );
$sp_first = $sp_rec['hours'] ?? 0;
update_option( TZFD_Setup_Checklist::CONSENT_OPTION, false, false );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: turning records off keeps the stored record', $sp_first === ( get_option( TZFD_Setup_Checklist::PROGRESS_OPTION, array() )['hours'] ?? -1 ) );
// 3b. Setup measures (issue #367) show only with consent and a progress record present. The record survives
// turning records off (checked above), so the measures are hidden by consent alone, not by an empty record.
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: measures are hidden while progress records are off', false === strpos( $sp_html, 'Setup measures for this site' ) );
update_option( TZFD_Setup_Checklist::CONSENT_OPTION, true, false );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: measures show with consent and a record', false !== strpos( $sp_html, 'Setup measures for this site' ) && false !== strpos( $sp_html, 'Setup completion:' ) );
update_option( TZFD_Setup_Checklist::CONSENT_OPTION, false, false );

// 4. No admin notice is added by this screen's own source (static check of the plugin file).
tzfd_sp_ok( 'setup-page: class adds no admin notice', false === strpos( (string) file_get_contents( $tzfd_inc . 'class-tzfd-setup-page.php' ), 'admin_notices' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the plugin's own source.

// 4b. The Get started screen shows no admin notice, even when a Front Desk reminder is due (issue #366).
// Positive control first: the speed reminder shows on another Front Desk screen, so the empty result below means something.
delete_user_meta( 1, 'tz_fd_notice_dismissed_speed' );
$sp_get_before = isset( $_GET['page'] ) ? $_GET['page'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test harness.
$_GET['page']  = 'tzfd-booking-report';
ob_start();
TZFD_Admin_Notices::render();
$sp_control = ob_get_clean();
tzfd_sp_ok( 'setup-page: control, a Front Desk reminder shows on another screen', false !== strpos( $sp_control, 'Booking availability can be cached' ) );
$_GET['page'] = TZFD_Setup_Page::PAGE;
ob_start();
TZFD_Admin_Notices::render();
TZFD_Public_Slot_Records::admin_notice();
$sp_notices = ob_get_clean();
tzfd_sp_ok( 'setup-page: Get started screen shows no admin notice', '' === $sp_notices );
if ( null === $sp_get_before ) { unset( $_GET['page'] ); } else { $_GET['page'] = $sp_get_before; }

// 5. A subscriber sees nothing.
$sp_sub = get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $sp_sub ) ) {
	wp_set_current_user( (int) $sp_sub[0] );
	tzfd_sp_ok( 'setup-page: subscriber sees nothing', '' === $sp_render() );
	wp_set_current_user( 1 );
} else {
	tzfd_sp_ok( 'setup-page: subscriber check skipped (no subscriber on this site)', true );
}

// 6. Discovery links (#366): off by default, and no link is shown without opt-in.
// Earlier suites on this disposable site create services and forms for the same company. The check below is about a
// site with no service and no calendar, so clear them for this company first (services are archived, forms deleted).
global $wpdb;
$sp_company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
// The suites after this file (wp-runtime-tests.php: A18 and B13 submit bookings through form 1) need the calendar row
// and the active services the provisioned site started with. Snapshot them here and put them back at the end of this file.
$sp_forms_t         = $wpdb->prefix . TZFD_Tables::FORMS;
$sp_svc_t           = $wpdb->prefix . 'tzfd_local_services';
$sp_forms_before    = array();
$sp_services_active = array();
if ( $sp_company > 0 ) {
	$sp_forms_before    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d', $sp_forms_t, $sp_company ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site, snapshot for restore.
	$sp_services_active = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE company_id = %d AND archived = 0', $sp_svc_t, $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site, snapshot for restore.
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET archived = 1 WHERE company_id = %d', $sp_svc_t, $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id = %d', $sp_forms_t, $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site.
}
delete_option( TZFD_Setup_Checklist::DISCOVERY_OPTION );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: discovery links are off by default', false !== strpos( $sp_html, 'Links to optional services are off.' ) && false === strpos( $sp_html, 'admin.php?page=tzfd-ai-services' ) );
update_option( TZFD_Setup_Checklist::DISCOVERY_OPTION, true, false );
$sp_html = $sp_render();
// The rule itself, for a site with no service and no calendar: opt-in alone shows nothing.
tzfd_sp_ok( 'setup-page: opt-in alone does not show links (rule)', false === TZFD_Setup_Checklist::discovery_visible( true, array( 'services' => false, 'calendar' => false ) ) );
tzfd_sp_ok( 'setup-page: discovery is not visible without a service or calendar', false === TZFD_Setup_Checklist::discovery_visible( true, array( 'services' => false, 'calendar' => false ) ) );
// The page must follow the rule for whatever this site holds. Earlier checks in the same run may have created
// services or forms, so the expected result is read from the live facts instead of assuming an empty site.
$sp_facts = TZFD_Setup_Checklist::facts();
tzfd_sp_ok( 'setup-page: opt-in shows receipt links only when a service or calendar exists', ( ! empty( $sp_facts['services'] ) || ! empty( $sp_facts['calendar'] ) ) === ( false !== strpos( $sp_html, 'admin.php?page=tzfd-agent-receipts' ) ), 'services=' . wp_json_encode( $sp_facts['services'] ) . ' calendar=' . wp_json_encode( $sp_facts['calendar'] ) );
update_option( TZFD_Setup_Checklist::DISCOVERY_OPTION, false, false );

// Put back the calendar rows and the active services this file cleared, so the later suites see the same site.
if ( $sp_company > 0 ) {
	foreach ( $sp_forms_before as $sp_form_row ) {
		$wpdb->replace( $sp_forms_t, $sp_form_row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- restores the row this file cleared.
	}
	if ( ! empty( $sp_services_active ) ) {
		$sp_ids = implode( ',', array_map( 'absint', $sp_services_active ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET archived = 0 WHERE company_id = %d AND id IN (' . $sp_ids . ')', $sp_svc_t, $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- ids are absint values from this file's own snapshot.
	}
}

// Clean up.
delete_option( TZFD_Setup_Checklist::CONSENT_OPTION );
delete_option( TZFD_Setup_Checklist::PROGRESS_OPTION );
delete_option( TZFD_Setup_Checklist::MANUAL_OPTION );
delete_option( TZFD_Setup_Checklist::DISCOVERY_OPTION );

echo 'Summary (setup-page-runtime): ' . (int) $GLOBALS['tzfd_sp']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_sp']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_sp']['FAIL'] > 0 ? 1 : 0 );
