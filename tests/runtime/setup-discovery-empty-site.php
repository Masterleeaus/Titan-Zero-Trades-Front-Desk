<?php
/**
 * Real-WordPress check for the discovery links on the get-started screen (issue #366), run on an empty site.
 *
 * This file is run right after the site is provisioned, before any suite creates a service, a booking form or a
 * booking. The assertions below are about "no service or calendar yet", so they are only valid on that empty site.
 * They are kept out of setup-page-runtime-tests.php, which runs later, after other suites have created records.
 *
 * Runs inside a disposable WordPress site, via WP-CLI:
 *   wp eval-file tests/runtime/setup-discovery-empty-site.php --user=1
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
	tzfd_sp_ok( 'setup-discovery: class file readable', false, 'class-tzfd-setup-page.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-setup-checklist.php';
require_once $tzfd_inc . 'class-tzfd-setup-page.php';

wp_set_current_user( 1 );

$sp_render = function () {
	ob_start();
	TZFD_Setup_Page::render();
	return ob_get_clean();
};

// Activation creates a default booking form, so a fresh site already has a calendar. Clear this company's services
// (archived) and forms (deleted) first, as setup-page-runtime-tests.php does on main. Disposable test site only.
global $wpdb;
$sp_company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
if ( $sp_company > 0 ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET archived = 1 WHERE company_id = %d', $wpdb->prefix . 'tzfd_local_services', $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id = %d', $wpdb->prefix . TZFD_Tables::FORMS, $sp_company ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- disposable test site.
}

// Precondition: the site really is empty for this company. Without this, the check below proves nothing.
$sp_detected = TZFD_Setup_Checklist::facts();
tzfd_sp_ok( 'setup-discovery: site has no service or calendar before the check', empty( $sp_detected['services'] ) && empty( $sp_detected['calendar'] ), 'services=' . ( $sp_detected['services'] ? 'yes' : 'no' ) . ' calendar=' . ( $sp_detected['calendar'] ? 'yes' : 'no' ) );

// 6. Discovery links (#366): off by default, and no link is shown without opt-in.
delete_option( TZFD_Setup_Checklist::DISCOVERY_OPTION );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: discovery links are off by default', false !== strpos( $sp_html, 'Links to optional services are off.' ) && false === strpos( $sp_html, 'admin.php?page=tzfd-ai-services' ) );
update_option( TZFD_Setup_Checklist::DISCOVERY_OPTION, true, false );
$sp_html = $sp_render();
tzfd_sp_ok( 'setup-page: opt-in without any service or calendar shows no links yet', false === strpos( $sp_html, 'admin.php?page=tzfd-agent-receipts' ) );
update_option( TZFD_Setup_Checklist::DISCOVERY_OPTION, false, false );

// Gate coverage, as extra checks alongside the assertion above.
tzfd_sp_ok( 'setup-discovery: gate refuses links with no service or calendar', false === TZFD_Setup_Checklist::discovery_visible( true, array( 'services' => false, 'calendar' => false ) ) );
tzfd_sp_ok( 'setup-discovery: gate refuses links without opt-in', false === TZFD_Setup_Checklist::discovery_visible( false, array( 'services' => true, 'calendar' => true ) ) );

// Clean up.
delete_option( TZFD_Setup_Checklist::DISCOVERY_OPTION );
