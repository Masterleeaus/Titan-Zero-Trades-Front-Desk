<?php
/**
 * Real-WordPress checks for the location admin screens (issue #362): the admin-post handlers' capability and
 * nonce gates, create and update through the handlers, the archive refusal while staff access still uses a
 * location, restore, grants at an archived location, the detail view, and company storage that is not autoloaded.
 *
 * The handlers end in wp_safe_redirect() and exit. This harness captures the redirect URL by making the
 * wp_redirect filter throw, so each handler returns its result code to the test without ending the process.
 *
 * Runs inside a disposable WordPress site, via WP-CLI:
 *   wp eval-file tests/runtime/location-admin-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_la'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_la_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_la'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

/** Runs an admin-post handler and returns the notice code it redirects with, or the exception text. */
function tzfd_la_run( $callable ) {
	$GLOBALS['tzfd_la_redirect'] = null;
	$filter = function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	// wp_die() (for example a failed nonce check) would end the process; make it throw so the test can read it.
	$die = function () {
		return function ( $message ) {
			throw new RuntimeException( 'die:' . wp_strip_all_tags( (string) $message ) );
		};
	};
	// Priority -999 runs before WP-CLI's own wp_redirect handler, which would otherwise print a warning.
	add_filter( 'wp_redirect', $filter, -999 );
	add_filter( 'wp_die_handler', $die, 999 );
	try {
		$callable();
		$out = 'no-redirect';
	} catch ( RuntimeException $e ) {
		$out = $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filter, -999 );
		remove_filter( 'wp_die_handler', $die, 999 );
	}
	$query = array();
	if ( 0 === strpos( $out, 'redirect:' ) ) {
		parse_str( (string) wp_parse_url( substr( $out, 9 ), PHP_URL_QUERY ), $query );
	} elseif ( 0 === strpos( $out, 'die:' ) ) {
		$query = array( 'die' => substr( $out, 4 ) );
	}
	return $query;
}

function tzfd_la_post( $action, $fields ) {
	$_POST    = array_merge( array( '_wpnonce' => wp_create_nonce( $action ) ), $fields );
	$_REQUEST = $_POST;
	$_SERVER['REQUEST_METHOD'] = 'POST';
}

function tzfd_la_clear_post() {
	$_POST    = array();
	$_REQUEST = array();
	unset( $_GET['location'], $_GET['tzfd_location_notice'] );
	$_SERVER['REQUEST_METHOD'] = 'GET';
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-delegated-roles.php', 'class-tzfd-location-registry.php', 'class-tzfd-location-store.php', 'class-tzfd-staff-access.php', 'class-tzfd-staff-access-admin.php', 'class-tzfd-location-admin.php' ) as $tzfd_file ) {
	if ( ! is_readable( $tzfd_inc . $tzfd_file ) ) {
		tzfd_la_ok( 'location-admin: class file readable', false, $tzfd_file . ' not found in the active plugin' );
		exit( 1 );
	}
	require_once $tzfd_inc . $tzfd_file;
}

$LA  = 'TZFD_Location_Admin';
$SA  = 'TZFD_Staff_Access';
$ST  = 'TZFD_Location_Store';
$co  = '1'; // The site's single business (TZFD_Scoped_Store::company_id()).
$now = time();
$opt = array( 'tzfd_locations_' . $co, 'tzfd_location_log_' . $co, 'tzfd_staff_access_g_' . $co, 'tzfd_staff_access_l_' . $co );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = empty( $admins ) ? 0 : (int) $admins[0];
tzfd_la_ok( 'location-admin: a site administrator exists for the run', $admin > 0 );
wp_set_current_user( $admin );

$saved = array();
foreach ( $opt as $k ) { $saved[ $k ] = get_option( $k, null ); delete_option( $k ); }

// 1. Wiring and gates.
tzfd_la_ok( 'location-admin: save action is registered', false !== has_action( 'admin_post_tzfd_location_save' ) );
tzfd_la_ok( 'location-admin: status action is registered', false !== has_action( 'admin_post_tzfd_location_status' ) );
$sub = wp_create_user( 'rt_la_sub_362', wp_generate_password( 20, true, true ), 'rt-la-sub-362@example.test' );
$sub = is_wp_error( $sub ) ? 0 : (int) $sub;
tzfd_la_ok( 'location-admin: a subscriber lacks the manage_options gate that every write checks', $sub > 0 && ! user_can( $sub, 'manage_options' ) );

// 2. Create through the handler: stored with autoload off, and refusals leave the registry unchanged.
tzfd_la_post( 'tzfd_location_save', array( 'mode' => 'create', 'location_id' => 'loc-n1', 'name' => 'North Depot', 'timezone' => 'Australia/Melbourne' ) );
$q = tzfd_la_run( array( $LA, 'handle_save' ) );
tzfd_la_ok( 'location-admin: administrator creates a location through the handler', 'created' === ( $q['tzfd_location_notice'] ?? '' ), wp_json_encode( $q ) );
global $wpdb;
$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'tzfd_locations_' . $co ) );
tzfd_la_ok( 'location-admin: the location option is not autoloaded', in_array( $autoload, array( 'no', 'off' ), true ), 'autoload=' . var_export( $autoload, true ) );

tzfd_la_post( 'tzfd_location_save', array( 'mode' => 'create', 'location_id' => 'loc-n1', 'name' => 'Again', 'timezone' => 'Australia/Melbourne' ) );
$q = tzfd_la_run( array( $LA, 'handle_save' ) );
tzfd_la_ok( 'location-admin: duplicate id is refused by the handler', 'duplicate_location_id' === ( $q['tzfd_location_notice'] ?? '' ), wp_json_encode( $q ) );

tzfd_la_post( 'tzfd_location_save', array( 'mode' => 'create', 'location_id' => 'loc n2', 'name' => 'Free text', 'timezone' => 'Australia/Melbourne' ) );
$q = tzfd_la_run( array( $LA, 'handle_save' ) );
tzfd_la_ok( 'location-admin: free-text id with a space is refused', 'invalid_location_id' === ( $q['tzfd_location_notice'] ?? '' ), wp_json_encode( $q ) );

tzfd_la_post( 'tzfd_location_save', array( 'mode' => 'create', 'location_id' => 'loc-n3', 'name' => 'Bad zone', 'timezone' => 'Mars/Olympus' ) );
$q = tzfd_la_run( array( $LA, 'handle_save' ) );
tzfd_la_ok( 'location-admin: unknown time zone is refused', 'invalid_location_timezone' === ( $q['tzfd_location_notice'] ?? '' ), wp_json_encode( $q ) );

$_POST = array( 'mode' => 'create', 'location_id' => 'loc-n4', 'name' => 'Replay', 'timezone' => 'Australia/Melbourne', '_wpnonce' => 'forged' );
$_REQUEST = $_POST;
$q = tzfd_la_run( array( $LA, 'handle_save' ) );
tzfd_la_ok( 'location-admin: a request with a bad nonce is refused before any write', isset( $q['die'] ) && ! isset( $q['tzfd_location_notice'] ) && 1 === count( $ST::locations( $co )['locations'] ), wp_json_encode( $q ) );
tzfd_la_clear_post();

// 3. Staff grants use the registry: registered and active only.
$g = $SA::assign( $co, $admin, true, array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'staff', 'location_ids' => array( 'loc-n1' ), 'staff_id' => 'st-362' ), $now );
tzfd_la_ok( 'location-admin: grant to a registered active location is accepted', true === $g['ok'], $g['code'] );
$g = $SA::assign( $co, $admin, true, array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'viewer', 'location_ids' => array( 'legacy-9' ), 'staff_id' => null ), $now );
tzfd_la_ok( 'location-admin: grant to an unregistered id is refused', false === $g['ok'] && 'unknown_location' === $g['code'], $g['code'] );

// 4. Archive is refused while a grant uses the location; it succeeds once the grant is revoked; restore works.
tzfd_la_post( 'tzfd_location_status', array( 'location_id' => 'loc-n1', 'status_action' => 'archive' ) );
$q = tzfd_la_run( array( $LA, 'handle_status' ) );
tzfd_la_ok( 'location-admin: archive is refused while staff access uses the location', 'location_in_use' === ( $q['tzfd_location_notice'] ?? '' ), wp_json_encode( $q ) );
tzfd_la_ok( 'location-admin: a refused archive leaves the location active', 'active' === $ST::locations( $co )['locations'][0]['status'] );

$rv = $SA::revoke( $co, $admin, true, $sub, 'loc-n1', $now );
tzfd_la_ok( 'location-admin: revoking the grant at the location succeeds', true === $rv['ok'], $rv['code'] );
tzfd_la_post( 'tzfd_location_status', array( 'location_id' => 'loc-n1', 'status_action' => 'archive' ) );
$q = tzfd_la_run( array( $LA, 'handle_status' ) );
tzfd_la_ok( 'location-admin: archive succeeds once no grant uses the location', 'archive' === ( $q['tzfd_location_notice'] ?? '' ), wp_json_encode( $q ) );
$g = $SA::assign( $co, $admin, true, array( 'company_id' => $co, 'user_id' => $sub, 'role' => 'viewer', 'location_ids' => array( 'loc-n1' ), 'staff_id' => null ), $now );
tzfd_la_ok( 'location-admin: an archived location takes no new grant', false === $g['ok'] && 'unknown_location' === $g['code'], $g['code'] );

tzfd_la_post( 'tzfd_location_status', array( 'location_id' => 'loc-n1', 'status_action' => 'restore' ) );
$q = tzfd_la_run( array( $LA, 'handle_status' ) );
tzfd_la_ok( 'location-admin: restore reactivates the location', 'restore' === ( $q['tzfd_location_notice'] ?? '' ) && 'active' === $ST::locations( $co )['locations'][0]['status'], wp_json_encode( $q ) );

// 5. Update through the handler: rename changes the name and logs only field names; the id is fixed.
tzfd_la_post( 'tzfd_location_save', array( 'mode' => 'update', 'location_id' => 'loc-n1', 'name' => 'North Depot (renamed)', 'timezone' => 'Australia/Sydney' ) );
$q = tzfd_la_run( array( $LA, 'handle_save' ) );
$row = $ST::locations( $co )['locations'][0];
tzfd_la_ok( 'location-admin: rename through the handler stores the new name and zone', 'update' === ( $q['tzfd_location_notice'] ?? '' ) && 'North Depot (renamed)' === $row['name'] && 'Australia/Sydney' === $row['timezone'], wp_json_encode( $q ) );
$log = $ST::log( $co );
tzfd_la_ok( 'location-admin: the operation log records ids and field names, not values', ! empty( $log ) && false === strpos( wp_json_encode( $log ), 'North Depot' ) && false === strpos( wp_json_encode( $log ), 'Sydney' ) );

// 6. Screens: list and detail render for the administrator; an unknown id says so.
$_GET['location'] = 'loc-n1';
ob_start();
$LA::render();
$detail = ob_get_clean();
tzfd_la_ok( 'location-admin: detail view shows access, the log and the edit form', false !== strpos( $detail, 'Staff access at this location' ) && false !== strpos( $detail, 'Operation log for this location' ) && false !== strpos( $detail, 'tzfd_location_save' ) );

$_GET['location'] = 'loc-nowhere';
ob_start();
$LA::render();
$missing = ob_get_clean();
tzfd_la_ok( 'location-admin: detail view for an unknown id says it is not registered', false !== strpos( $missing, 'That location is not registered.' ) );
unset( $_GET['location'] );

ob_start();
$LA::render();
$list = ob_get_clean();
tzfd_la_ok( 'location-admin: list view shows the registered name', false !== strpos( $list, 'North Depot (renamed)' ) );

// Restore the options this test touched, remove the disposable user, and clear request state.
foreach ( $opt as $k ) {
	if ( null === $saved[ $k ] ) { delete_option( $k ); } else { update_option( $k, $saved[ $k ], false ); }
}
if ( $sub > 0 ) { wp_delete_user( $sub ); }
tzfd_la_clear_post();

echo 'Summary (location-admin-runtime): ' . (int) $GLOBALS['tzfd_la']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_la']['FAIL'] . " failed\n";
exit( (int) $GLOBALS['tzfd_la']['FAIL'] > 0 ? 1 : 0 );
