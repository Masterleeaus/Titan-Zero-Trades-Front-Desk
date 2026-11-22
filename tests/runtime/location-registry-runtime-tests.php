<?php
/**
 * Real-WordPress checks for the location registry and its admin screens (issue #362): admin-only writes, the
 * registry storage (not autoloaded, company-scoped), staff grants that must name registered active locations,
 * legacy free-text grants kept working and flagged, and the rendered list, detail and staff form.
 *
 * Runs inside a disposable WordPress site, via WP-CLI:
 *   wp eval-file tests/runtime/location-registry-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_lr'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_lr_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_lr'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-delegated-roles.php', 'class-tzfd-location-registry.php', 'class-tzfd-staff-access.php', 'class-tzfd-location-admin.php', 'class-tzfd-staff-access-admin.php' ) as $tzfd_file ) {
	if ( ! is_readable( $tzfd_inc . $tzfd_file ) ) {
		tzfd_lr_ok( 'location-registry: class file readable', false, $tzfd_file . ' not found in the active plugin' );
		exit( 1 );
	}
	require_once $tzfd_inc . $tzfd_file;
}

$LR   = 'TZFD_Location_Admin';
$SA   = 'TZFD_Staff_Access';
$REG  = 'TZFD_Location_Registry';
$co   = 'rt-co-362';
$oth  = 'rt-co-363';
$now  = time();
$keys = array( 'tzfd_locations_' . $co, 'tzfd_locations_' . $oth, 'tzfd_staff_access_g_' . $co, 'tzfd_staff_access_l_' . $co, 'tzfd_locations_1', 'tzfd_staff_access_g_1', 'tzfd_staff_access_l_1' );
$saved = array();
foreach ( $keys as $k ) { $saved[ $k ] = get_option( $k, null ); delete_option( $k ); }

// 1. Wiring: both admin-post actions are registered, and the gate is manage_options.
tzfd_lr_ok( 'location-registry: add action is registered', false !== has_action( 'admin_post_tzfd_location_add' ) );
tzfd_lr_ok( 'location-registry: update action is registered', false !== has_action( 'admin_post_tzfd_location_update' ) );
$sub = wp_create_user( 'rt_loc_sub_362', wp_generate_password( 20, true, true ), 'rt-loc-sub-362@example.test' );
$sub = is_wp_error( $sub ) ? 0 : (int) $sub;
tzfd_lr_ok( 'location-registry: a subscriber cannot manage locations', $sub > 0 && ! user_can( $sub, 'manage_options' ) );

// 2. Adding: valid add, stored unautoloaded and company-scoped; duplicates, bad ids and blank names are refused.
$r = $LR::process_add( $co, array( 'location_id' => 'loc-n1', 'name' => 'North Depot' ) );
tzfd_lr_ok( 'location-registry: admin adds a registered location', true === $r['ok'] && 'added' === $r['code'], $r['code'] );
global $wpdb;
$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'tzfd_locations_' . $co ) );
tzfd_lr_ok( 'location-registry: registry option is not autoloaded', 'no' === $autoload, 'autoload=' . var_export( $autoload, true ) );
tzfd_lr_ok( 'location-registry: registry is company-scoped', array() === $LR::registry( $oth )['rows'] && 1 === count( $LR::registry( $co )['rows'] ) );
$r = $LR::process_add( $co, array( 'location_id' => 'loc-n1', 'name' => 'Again' ) );
tzfd_lr_ok( 'location-registry: duplicate id is refused', false === $r['ok'] && 'duplicate_location_id' === $r['code'] );
$r = $LR::process_add( $co, array( 'location_id' => 'loc n2', 'name' => 'Free text id' ) );
tzfd_lr_ok( 'location-registry: id with a space is refused', false === $r['ok'] && 'invalid_location_id' === $r['code'] );
$r = $LR::process_add( $co, array( 'location_id' => 'loc-n3', 'name' => '   ' ) );
tzfd_lr_ok( 'location-registry: blank name is refused', false === $r['ok'] && 'invalid_location_name' === $r['code'] );
tzfd_lr_ok( 'location-registry: refused adds leave the registry unchanged', 1 === count( $LR::registry( $co )['rows'] ) );

// 3. Staff grants must name registered, active locations.
$r = $LR::process_add( $co, array( 'location_id' => 'loc-n2', 'name' => 'South Yard' ) );
$active = $LR::active_ids( $co );
$staff  = array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'staff', 'location_ids' => array( 'loc-n2' ), 'staff_id' => 'st-362' );
$g      = $SA::assign( $co, 1, true, $staff, $now, $active );
tzfd_lr_ok( 'location-registry: grant to a registered active location is accepted', true === $g['ok'], $g['code'] );

$g = $SA::assign( $co, 1, true, array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'viewer', 'location_ids' => array( 'legacy-9' ), 'staff_id' => null ), $now, $active );
tzfd_lr_ok( 'location-registry: free-text location id is refused when the registry is used', false === $g['ok'] && 'unknown_location' === $g['code'], $g['code'] );

$LR::process_update( $co, array( 'location_id' => 'loc-n1', 'name' => 'North Depot', 'active' => false ) );
$g = $SA::assign( $co, 1, true, array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'viewer', 'location_ids' => array( 'loc-n1' ), 'staff_id' => null ), $now, $LR::active_ids( $co ) );
tzfd_lr_ok( 'location-registry: deactivated location refuses new grants', false === $g['ok'] && 'unknown_location' === $g['code'], $g['code'] );

// 4. Legacy grants made before the registry keep working and are flagged, not dropped.
$legacy = array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'viewer', 'location_ids' => array( 'legacy-9' ), 'staff_id' => null );
update_option( 'tzfd_staff_access_g_' . $co, array( $legacy ), false );
tzfd_lr_ok( 'location-registry: legacy grant still grants access', $SA::allowed( $co, $sub, 'legacy-9', 'view_reports' ) );
tzfd_lr_ok( 'location-registry: legacy grant is flagged as unregistered', array( 'legacy-9' ) === TZFD_Location_Registry::unregistered( $LR::registry( $co )['rows'], $legacy['location_ids'] ) );
$g = $SA::assign( $co, 1, true, array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'viewer', 'location_ids' => array( 'legacy-9' ), 'staff_id' => null ), $now );
tzfd_lr_ok( 'location-registry: no list given keeps the pre-registry behaviour', true === $g['ok'], $g['code'] );

// 5. Company isolation and update refusals.
$r = $LR::process_update( $oth, array( 'location_id' => 'loc-n1', 'name' => 'Stolen', 'active' => true ) );
tzfd_lr_ok( 'location-registry: another company cannot update this company\'s location', false === $r['ok'] && 'unknown_location_id' === $r['code'] );
$r = $LR::process_update( $co, array( 'location_id' => 'loc-missing', 'name' => 'X', 'active' => true ) );
tzfd_lr_ok( 'location-registry: update of an unknown location is refused', false === $r['ok'] && 'unknown_location_id' === $r['code'] );

// 6. Admin screens render the registry, the detail view and the staff form (company 1 is the site's business).
$admin_id = 1;
wp_set_current_user( $admin_id );
update_option( 'tzfd_locations_1', array(), false );
$LR::process_add( 1, array( 'location_id' => 'rt-render-1', 'name' => 'Render Site' ) );
$SA::assign( 1, $admin_id, true, array( 'company_id' => '1', 'user_id' => $sub, 'role' => 'viewer', 'location_ids' => array( 'rt-render-1' ), 'staff_id' => null ), $now );

ob_start();
$LR::render();
$list = ob_get_clean();
tzfd_lr_ok( 'location-registry: list screen shows the registered name and id', false !== strpos( $list, 'Render Site' ) && false !== strpos( $list, 'rt-render-1' ) );

$_GET['location'] = 'rt-render-1';
ob_start();
$LR::render();
$detail = ob_get_clean();
tzfd_lr_ok( 'location-registry: detail view lists access, the change log and the edit form', false !== strpos( $detail, 'Staff access at this location' ) && false !== strpos( $detail, 'Recent changes naming this location' ) && false !== strpos( $detail, 'value="tzfd_location_update"' ) );

$_GET['location'] = 'rt-nowhere';
ob_start();
$LR::render();
$missing = ob_get_clean();
tzfd_lr_ok( 'location-registry: detail view for an unknown id says so', false !== strpos( $missing, 'not registered' ) );
unset( $_GET['location'] );

ob_start();
TZFD_Staff_Access_Admin::render();
$form = ob_get_clean();
tzfd_lr_ok( 'location-registry: staff form offers registered locations as checkboxes', false !== strpos( $form, 'name="location_ids[]" value="rt-render-1"' ) && false === strpos( $form, 'name="location_ids" class' ) );
tzfd_lr_ok( 'location-registry: staff grants table names the registered location', false !== strpos( $form, 'Render Site (rt-render-1)' ) );

// Restore the options this test touched.
foreach ( $keys as $k ) {
	if ( null === $saved[ $k ] ) { delete_option( $k ); } else { update_option( $k, $saved[ $k ], false ); }
}
if ( $sub > 0 ) { wp_delete_user( $sub ); }

echo 'Summary (location-registry-runtime): ' . (int) $GLOBALS['tzfd_lr']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_lr']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_lr']['FAIL'] > 0 ? 1 : 0 );
