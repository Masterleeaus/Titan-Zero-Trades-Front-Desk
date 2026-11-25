<?php
/**
 * Real-WordPress checks for location-scoped staff access (issue #11): who may grant and revoke, merging, company
 * isolation, malformed stored grants, the operation log, and the admin screen's capability check.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/staff-access-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_sa'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_sa_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_sa'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-staff-access.php' ) ) {
	tzfd_sa_ok( 'staff-access: class file readable', false, 'class-tzfd-staff-access.php not found in the active plugin' );
	exit( 1 );
}
require_once $tzfd_inc . 'class-tzfd-location-registry.php';
require_once $tzfd_inc . 'class-tzfd-location-store.php';
require_once $tzfd_inc . 'class-tzfd-staff-access.php';
require_once $tzfd_inc . 'class-tzfd-staff-access-admin.php';

$sa_co  = 'rt-co-11';
$sa_oth = 'rt-co-12';
$sa_now = time();
$SA     = 'TZFD_Staff_Access';
$ROLES  = 'TZFD_Delegated_Roles';

// Start clean.
foreach ( array( $sa_co, $sa_oth ) as $sa_c ) {
	delete_option( 'tzfd_staff_access_g_' . $sa_c );
	delete_option( 'tzfd_staff_access_l_' . $sa_c );
	delete_option( 'tzfd_locations_' . $sa_c );
	delete_option( 'tzfd_location_log_' . $sa_c );
}

// Locations must be registered before a grant can name them (issue #362).
foreach ( array( 'loc-a', 'loc-b' ) as $sa_loc ) {
	$sa_added = TZFD_Location_Store::add( $sa_co, 1, array( 'id' => $sa_loc, 'name' => 'Site ' . $sa_loc, 'timezone' => 'UTC' ), time() );
	tzfd_sa_ok( 'staff-access: location ' . $sa_loc . ' registered', true === $sa_added['ok'] );
}

// Registry isolation (issue #362): each company has its own registry. The same id may be registered by another
// company, but a location registered only for one company is not usable by the other.
$sa_other_add = TZFD_Location_Store::add( $sa_oth, 1, array( 'id' => 'loc-a', 'name' => 'Other company site', 'timezone' => 'UTC' ), time() );
tzfd_sa_ok( 'staff-access: another company can register the same location id', true === $sa_other_add['ok'], $sa_other_add['code'] );
tzfd_sa_ok( 'staff-access: a location registered for one company is not active for another', TZFD_Location_Store::has_active( $sa_co, array( 'loc-b' ) ) && false === TZFD_Location_Store::has_active( $sa_oth, array( 'loc-b' ) ) );

// Disposable users: one staff member, one location admin, one company admin.
$sa_make = function ( $login ) {
	$id = wp_create_user( $login, wp_generate_password( 20, true, true ), $login . '@example.test' );
	return is_wp_error( $id ) ? 0 : (int) $id;
};
$sa_staff = $sa_make( 'rt_staff_11' );
$sa_locad = $sa_make( 'rt_locadmin_11' );
$sa_coadm = $sa_make( 'rt_coadmin_11' );
tzfd_sa_ok( 'staff-access: test users created', $sa_staff > 0 && $sa_locad > 0 && $sa_coadm > 0 );

// 1. Administrator grants a staff role at loc-a.
$sa_r = $SA::assign( $sa_co, 1, true, array( 'company_id' => $sa_co, 'user_id' => $sa_staff, 'role' => 'staff', 'location_ids' => array( 'loc-a' ), 'staff_id' => 'staff-1' ), $sa_now );
tzfd_sa_ok( 'staff-access: administrator can grant a staff role', true === $sa_r['ok'] && 'added' === $sa_r['code'] );
tzfd_sa_ok( 'staff-access: staff may edit its own bookings at its location', $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'edit_bookings', array( 'booking_staff_id' => 'staff-1' ) ) );
tzfd_sa_ok( 'staff-access: staff may not edit another staff member\'s booking', false === $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'edit_bookings', array( 'booking_staff_id' => 'staff-2' ) ) );
tzfd_sa_ok( 'staff-access: staff sees the schedule at its location only', $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'view_schedule' ) && false === $SA::allowed( $sa_co, $sa_staff, 'loc-b', 'view_schedule' ) );
tzfd_sa_ok( 'staff-access: staff cannot approve bookings or see reports (least privilege)', false === $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'approve_bookings' ) && false === $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'view_reports' ) );
tzfd_sa_ok( 'staff-access: grants do not cross companies', false === $SA::allowed( $sa_oth, $sa_staff, 'loc-a', 'view_schedule' ) );
$sa_r = $SA::assign( $sa_co, 1, true, array( 'company_id' => $sa_co, 'user_id' => $sa_staff, 'role' => 'viewer', 'location_ids' => array( 'free-text-id' ), 'staff_id' => null ), $sa_now );
tzfd_sa_ok( 'staff-access: a grant naming an unregistered location is refused', false === $sa_r['ok'] && 'unknown_location' === $sa_r['code'] );

// 2. Administrator makes a location admin for loc-a. That user can then manage staff at loc-a only.
$sa_r = $SA::assign( $sa_co, 1, true, array( 'company_id' => $sa_co, 'user_id' => $sa_locad, 'role' => 'location_admin', 'location_ids' => array( 'loc-a' ), 'staff_id' => null ), $sa_now );
tzfd_sa_ok( 'staff-access: administrator can grant a location admin', true === $sa_r['ok'] );
$sa_r = $SA::assign( $sa_co, $sa_locad, false, array( 'company_id' => $sa_co, 'user_id' => $sa_staff, 'role' => 'viewer', 'location_ids' => array( 'loc-a' ), 'staff_id' => null ), $sa_now );
tzfd_sa_ok( 'staff-access: location admin may grant at its own location', true === $sa_r['ok'] );
$sa_r = $SA::assign( $sa_co, $sa_locad, false, array( 'company_id' => $sa_co, 'user_id' => $sa_staff, 'role' => 'viewer', 'location_ids' => array( 'loc-b' ), 'staff_id' => null ), $sa_now );
tzfd_sa_ok( 'staff-access: location admin may not grant at another location', false === $sa_r['ok'] && 'forbidden' === $sa_r['code'] );
$sa_r = $SA::assign( $sa_co, $sa_locad, false, array( 'company_id' => $sa_co, 'user_id' => $sa_coadm, 'role' => 'company_admin', 'location_ids' => array(), 'staff_id' => null ), $sa_now );
tzfd_sa_ok( 'staff-access: location admin may not grant company admin', false === $sa_r['ok'] && 'forbidden' === $sa_r['code'] );

// 3. A staff member with no manage rights cannot change anything.
$sa_r = $SA::assign( $sa_co, $sa_staff, false, array( 'company_id' => $sa_co, 'user_id' => $sa_coadm, 'role' => 'viewer', 'location_ids' => array( 'loc-a' ), 'staff_id' => null ), $sa_now );
tzfd_sa_ok( 'staff-access: a staff member cannot grant access', false === $sa_r['ok'] && 'forbidden' === $sa_r['code'] );
$sa_r = $SA::revoke( $sa_co, $sa_staff, false, $sa_locad, 'loc-a', $sa_now );
tzfd_sa_ok( 'staff-access: a staff member cannot revoke access', false === $sa_r['ok'] && 'forbidden' === $sa_r['code'] );

// 4. Merging: adding a second location to the same grant keeps the first.
$sa_r = $SA::assign( $sa_co, 1, true, array( 'company_id' => $sa_co, 'user_id' => $sa_staff, 'role' => 'staff', 'location_ids' => array( 'loc-b' ), 'staff_id' => 'staff-1' ), $sa_now );
tzfd_sa_ok( 'staff-access: same user, role and staff id merges locations', true === $sa_r['ok'] && 'merged' === $sa_r['code'] );
tzfd_sa_ok( 'staff-access: merged grant keeps the first location and adds the second', $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'view_schedule' ) && $SA::allowed( $sa_co, $sa_staff, 'loc-b', 'view_schedule' ) );

// 5. Revoke: a location admin cannot remove company admin; an administrator can remove one location.
$sa_r = $SA::assign( $sa_co, 1, true, array( 'company_id' => $sa_co, 'user_id' => $sa_coadm, 'role' => 'company_admin', 'location_ids' => array(), 'staff_id' => null ), $sa_now );
tzfd_sa_ok( 'staff-access: administrator grants company admin', true === $sa_r['ok'] );
$sa_r = $SA::revoke( $sa_co, $sa_locad, false, $sa_coadm, 'loc-a', $sa_now );
tzfd_sa_ok( 'staff-access: location admin cannot remove a company admin grant', false === $sa_r['ok'] && 'forbidden' === $sa_r['code'] );
$sa_r = $SA::revoke( $sa_co, 1, true, $sa_staff, 'loc-b', $sa_now );
tzfd_sa_ok( 'staff-access: administrator revokes one location only', true === $sa_r['ok'] && false === $SA::allowed( $sa_co, $sa_staff, 'loc-b', 'view_schedule' ) && $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'view_schedule' ) );
$sa_r = $SA::revoke( $sa_co, 1, true, $sa_staff, 'loc-b', $sa_now );
tzfd_sa_ok( 'staff-access: revoking a location that is not granted changes nothing', false === $sa_r['ok'] && 'nothing_to_revoke' === $sa_r['code'] );
$sa_r = $SA::revoke( $sa_co, 1, true, $sa_staff, 'loc-a', $sa_now );
tzfd_sa_ok( 'staff-access: removing the last location removes the grant', true === $sa_r['ok'] && false === $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'view_schedule' ) );

// 6. Malformed stored grants grant nothing and are reported.
$sa_stored = get_option( 'tzfd_staff_access_g_' . $sa_co, array() );
$sa_stored[] = array( 'company_id' => $sa_co, 'user_id' => $sa_staff, 'role' => 'administrator', 'location_ids' => array( 'loc-a' ) );
$sa_stored[] = array( 'company_id' => $sa_co, 'user_id' => $sa_staff, 'role' => 'staff', 'location_ids' => array( 'loc-a' ), 'staff_id' => null );
update_option( 'tzfd_staff_access_g_' . $sa_co, $sa_stored, false );
$sa_read = $SA::grants( $sa_co );
tzfd_sa_ok( 'staff-access: malformed stored grants are skipped and counted', 2 === $sa_read['skipped'] );
tzfd_sa_ok( 'staff-access: malformed grants do not widen access', false === $SA::allowed( $sa_co, $sa_staff, 'loc-a', 'view_schedule' ) );
$sa_stored = array_values( array_filter( get_option( 'tzfd_staff_access_g_' . $sa_co, array() ), function ( $g ) {
	return is_array( $g ) && isset( $g['role'] ) && 'administrator' !== $g['role'] && ! ( 'staff' === $g['role'] && null === ( $g['staff_id'] ?? null ) );
} ) );
update_option( 'tzfd_staff_access_g_' . $sa_co, $sa_stored, false );

// 7. Company input is checked before any read or write.
$sa_bad = false;
try {
	$SA::grants( 'bad id with spaces' );
} catch ( InvalidArgumentException $e ) {
	$sa_bad = true;
}
tzfd_sa_ok( 'staff-access: invalid company id is refused', $sa_bad );

// 8. Operation log: every change recorded with ids only, newest first.
$sa_log = $SA::log( $sa_co );
$sa_actions = array_column( $sa_log, 'action' );
tzfd_sa_ok( 'staff-access: operation log records assign and revoke', in_array( 'assign', $sa_actions, true ) && in_array( 'revoke', $sa_actions, true ) );
tzfd_sa_ok( 'staff-access: log rows hold only ids, role and locations', ! empty( $sa_log ) && 0 === count( array_diff( array_keys( $sa_log[0] ), array( 'at', 'actor_id', 'action', 'user_id', 'role', 'location_ids' ) ) ) );
tzfd_sa_ok( 'staff-access: log is newest first', ! empty( $sa_log ) && $sa_log[0]['at'] >= end( $sa_log )['at'] );

// 9. Admin screen: administrator sees it, a subscriber or staff member gets nothing.
wp_set_current_user( 1 );
ob_start();
TZFD_Staff_Access_Admin::render();
$sa_html = ob_get_clean();
tzfd_sa_ok( 'staff-access: administrator sees the screen', false !== strpos( $sa_html, 'Staff access' ) );
wp_set_current_user( $sa_staff );
ob_start();
TZFD_Staff_Access_Admin::render();
$sa_html = ob_get_clean();
tzfd_sa_ok( 'staff-access: a staff user sees nothing on the screen', '' === $sa_html );
wp_set_current_user( 1 );

// Clean up what this suite wrote.
foreach ( array( $sa_co, $sa_oth ) as $sa_c ) {
	delete_option( 'tzfd_staff_access_g_' . $sa_c );
	delete_option( 'tzfd_staff_access_l_' . $sa_c );
	delete_option( 'tzfd_locations_' . $sa_c );
	delete_option( 'tzfd_location_log_' . $sa_c );
}
foreach ( array( $sa_staff, $sa_locad, $sa_coadm ) as $sa_u ) {
	if ( $sa_u > 0 ) { wp_delete_user( $sa_u ); }
}

echo 'Summary (staff-access-runtime): ' . (int) $GLOBALS['tzfd_sa']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_sa']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_sa']['FAIL'] > 0 ? 1 : 0 );
