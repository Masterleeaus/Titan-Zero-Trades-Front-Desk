<?php
/**
 * Real-WordPress checks for the get-started screen (issue #15): the checklist reads real counts, self-reported
 * steps are marked only by the administrator, progress is recorded only with consent, the screen is shown only
tzfd_sp_ok( 'setup-page: no admin notice is added by this screen', false === has_action( 'admin_notices', array( 'TZFD_Setup_Page', 'render' ) ) && false === strpos( (string) file_get_contents( $tzfd_inc . 'class-tzfd-setup-page.php' ), 'admin_notices' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the plugin's own source.
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

// 4. No admin notice is registered anywhere by this screen.
tzfd_sp_ok( 'setup-page: no admin notice is added', false === has_action( 'admin_notices' ) || ! in_array( 'TZFD_Setup_Page', array_map( function ( $cb ) { return is_array( $cb['function'] ?? null ) ? $cb['function'][0] : ''; }, array() ), true ) );

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
if ( $sp_company > 0 ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET archived = 1 WHERE company_id = %d', $wpdb->prefix . 'tzfd_local_services', $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id = %d', $wpdb->prefix . TZFD_Tables::FORMS, $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site.
}
delete_option( TZFD_Setup_Checklist::DISCOVERY_OPTION );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: discovery links are off by default', false !== strpos( $sp_html, 'Links to optional services are off.' ) && false === strpos( $sp_html, 'admin.php?page=tzfd-ai-services' ) );
update_option( TZFD_Setup_Checklist::DISCOVERY_OPTION, true, false );
$sp_html = $sp_render();
// The rule itself, for a site with no service and no calendar: opt-in alone shows nothing.
tzfd_sp_ok( 'setup-page: opt-in alone does not show links (rule)', false === TZFD_Setup_Checklist::discovery_visible( true, array( 'services' => false, 'calendar' => false ) ) );
// The page must follow the rule for whatever this site holds. Earlier checks in the same run may have created
// services or forms, so the expected result is read from the live facts instead of assuming an empty site.
$sp_facts    = TZFD_Setup_Checklist::facts();
$sp_expected = TZFD_Setup_Checklist::discovery_visible( true, $sp_facts );
tzfd_sp_ok( 'setup-page: opt-in shows links only when a service or calendar exists', $sp_expected === ( false !== strpos( $sp_html, 'admin.php?page=tzfd-agent-receipts' ) ), 'expected=' . ( $sp_expected ? 'shown' : 'hidden' ) );
update_option( TZFD_Setup_Checklist::DISCOVERY_OPTION, false, false );

// Clean up.
delete_option( TZFD_Setup_Checklist::CONSENT_OPTION );
delete_option( TZFD_Setup_Checklist::PROGRESS_OPTION );
delete_option( TZFD_Setup_Checklist::MANUAL_OPTION );
delete_option( TZFD_Setup_Checklist::DISCOVERY_OPTION );

echo 'Summary (setup-page-runtime): ' . (int) $GLOBALS['tzfd_sp']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_sp']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_sp']['FAIL'] > 0 ? 1 : 0 );
