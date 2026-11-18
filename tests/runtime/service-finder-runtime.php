<?php
/**
 * Runtime checks for the Smart Service Finder against the live tenant lookup (not shipped in the plugin).
 * Run by the WordPress runtime job in CI: wp eval-file tests/runtime/service-finder-runtime.php (not shipped in the plugin).
 */
// Path from WordPress itself, so it matches the file the plugin loads (issue #191: was a hard-coded server path).
$tz_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
require_once $tz_inc . 'class-tzfd-service-finder-core.php';

$GLOBALS['sfr_pass'] = 0; $GLOBALS['sfr_fail'] = 0;
function sfr_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['sfr_pass']++; } else { $GLOBALS['sfr_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

wp_set_current_user( 1 );
$company = (int) TZFD_Scoped_Store::company_id();
sfr_check( 'company resolves for admin', $company > 0, 'company=' . $company );

$owner_of = function ( $calendar_id ) { return TZFD_Scoped_Store::company_for_form( (int) $calendar_id ); };

// Unknown calendar has no owner, so it can never validate, even for a plausible identifier.
sfr_check( 'unknown calendar has no owning company', 0 === (int) $owner_of( 999999 ) );
sfr_check( 'selection for unknown calendar is refused', null === TZFD_Service_Finder_Core::validate_selection( '999999:0:0', $company, array(), $owner_of ) );

// Real calendar 1 (default form). Its owner must match; a different company must be refused.
$owner1 = (int) $owner_of( 1 );
sfr_check( 'calendar 1 has an owning company', $owner1 > 0, 'owner=' . $owner1 );
$row = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT form_structure FROM ' . $GLOBALS['wpdb']->prefix . TZFD_Tables::FORMS . ' WHERE id = %d', 1 ) );
$services = TZFD_Service_Finder_Core::visible_services( 1, (string) $row );
sfr_check( 'live structure of calendar 1 parses to a service list', is_array( $services ) );
$by_cal = array( 1 => $services );
if ( ! empty( $services ) ) {
    $sel = $services[0]['id'];
    sfr_check( 'owner selects its own visible service', null !== TZFD_Service_Finder_Core::validate_selection( $sel, $owner1, $by_cal, $owner_of ) );
    sfr_check( 'other company cannot select the same service', null === TZFD_Service_Finder_Core::validate_selection( $sel, $owner1 + 1, $by_cal, $owner_of ) );
} else {
    sfr_check( 'calendar 1 has visible services to test selection against', false, 'no fapp services in default form' );
}
sfr_check( 'malformed identifier is refused on live site', null === TZFD_Service_Finder_Core::validate_selection( '1:0:0 OR 1=1', $owner1, $by_cal, $owner_of ) );

echo "\nSummary: {$GLOBALS['sfr_pass']} passed, {$GLOBALS['sfr_fail']} failed\n";
