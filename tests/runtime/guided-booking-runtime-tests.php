<?php
/**
 * WordPress integration checks for the guided-booking core (TZFD_Guided_Booking) under a real WordPress run (issue #440).
 * Run: wp --allow-root eval-file guided-booking-runtime-tests.php   (not shipped in the plugin)
 *
 * The class has no caller in the plugin on main (wiring to the booking form is open under #24). This file is the
 * caller: it loads the installed plugin copy, then drives the flow the way the form handler will: next_step(),
 * propose(), confirmation(), submit(). It uses the real slot engine (TZFD_Slot_Search) and the real catalogue
 * validator. It never writes to the database and calls no external service.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$gbr_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
require_once $gbr_inc . 'class-tzfd-guided-booking.php';

$GLOBALS['gbr_pass'] = 0;
$GLOBALS['gbr_fail'] = 0;
function gbr_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['gbr_pass']++; } else { $GLOBALS['gbr_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

$G = 'TZFD_Guided_Booking';

gbr_check( 'runs inside WordPress with the installed plugin copy', function_exists( 'wp_json_encode' ) && class_exists( $G ) );

$services = $G::normalise_services( array(
    array( 'id' => 'deep', 'name' => 'Deep Clean', 'duration_minutes' => 60 ),
    array( 'id' => 'win', 'name' => 'Window Wash', 'duration_minutes' => 30 ),
) );

$catalog = TZFD_Resource_Catalog::validate( array(
    'schema_version' => 1,
    'locations'      => array( array( 'id' => 'mel', 'name' => 'Melbourne', 'timezone' => 'Australia/Melbourne', 'enabled' => true ) ),
    'resources'      => array( array(
        'id' => 'tech1', 'name' => 'Tech One', 'kind' => 'provider', 'location_id' => 'mel', 'capacity' => 1, 'enabled' => true,
        'service_ids' => array( 'deep' ),
        'weekly' => array( array( 'day' => 1, 'start' => '09:00', 'end' => '17:00' ) ),
    ) ),
) );

// Monday 2026-10-05 09:00 AEDT is 2026-10-04T22:00:00Z, the same window the unit test uses.
$criteria = function ( $company = 'acme', $verified = 'acme' ) {
    return array(
        'company_id' => $company, 'verified_company_id' => $verified,
        'snapshot_company_id' => $company, 'snapshot_complete' => true,
        'required_resource_ids' => array( 'tech1' ), 'service_id' => 'deep', 'location_id' => 'mel',
        'start' => '2026-10-04T22:00:00Z', 'end' => '2026-10-05T06:00:00Z',
        'duration_minutes' => 60, 'step_minutes' => 60, 'limit' => 20,
    );
};
$none = array();

// 1. Change wording is handed off through the real entry point, never reaching availability (defect #336 / #401).
$step = $G::next_step( 'I need to reschedule my appointment', $services );
gbr_check( 'change request hands off in WordPress', 'handoff' === $step['step'] && array() === $step['service_ids'], $step['step'] );
$step = $G::next_step( 'Please cancel my booking', $services );
gbr_check( 'cancel request hands off in WordPress', 'handoff' === $step['step'] );

// 2. Happy path: one clear service match, engine slot, confirmation, submit gate accepts the exact choice.
$step = $G::next_step( 'I want to book a deep clean', $services );
gbr_check( 'single match asks the customer to confirm the service', 'confirm_service' === $step['step'] && array( 'deep' ) === $step['service_ids'], $step['step'] );

$offer = $G::propose( $catalog, $criteria(), $none );
gbr_check( 'engine offers a slot from the catalogue', 'ok' === $offer['status'] && '2026-10-04T22:00:00Z' === $offer['slots'][0]['start'], $offer['status'] );

$service = $services[0];
$values  = $G::normalise_fields( array( 'name' => 'Ana', 'contact' => 'ana@example.com' ), array( 'name', 'contact' ) );
$slot    = array( 'start' => '2026-10-04T22:00:00Z', 'end' => '2026-10-04T23:00:00Z', 'resource_ids' => array( 'tech1' ) );

$conf = $G::confirmation( 'acme', $catalog, 'mel', $service, $slot, $values );
gbr_check( 'confirmation shows local Melbourne time', false !== strpos( $conf['lines'][1], 'Mon 5 Oct 2026, 09:00 to 10:00 AEDT' ), $conf['lines'][1] );
gbr_check( 'confirmation does not commit', false === $conf['commit'] && is_string( $conf['token'] ) && '' !== $conf['token'] );

$ready = $G::submit( 'acme', $catalog, $criteria(), $none, $service, $slot, $values, $conf['token'] );
gbr_check( 'submit accepts the exact confirmed choice', 'ready' === $ready['status'], $ready['status'] );

// 3. Negative paths at the submit gate.
$changed = $G::normalise_fields( array( 'name' => 'Bob', 'contact' => 'ana@example.com' ), array( 'name', 'contact' ) );
gbr_check( 'tampered name invalidates the token', 'confirmation_mismatch' === $G::submit( 'acme', $catalog, $criteria(), $none, $service, $slot, $changed, $conf['token'] )['status'] );
gbr_check( 'missing token is refused', 'confirmation_mismatch' === $G::submit( 'acme', $catalog, $criteria(), $none, $service, $slot, $values, null )['status'] );

$taken = array( array( 'id' => 'r1', 'resource_id' => 'tech1', 'quantity' => 1, 'status' => 'confirmed',
    'start' => '2026-10-04T22:00:00Z', 'end' => '2026-10-04T23:00:00Z' ) );
gbr_check( 'slot taken after confirmation is refused', 'slot_unavailable' === $G::submit( 'acme', $catalog, $criteria(), $taken, $service, $slot, $values, $conf['token'] )['status'] );
gbr_check( 'unavailable reservation snapshot fails closed', 'engine_unavailable' === $G::submit( 'acme', $catalog, $criteria(), null, $service, $slot, $values, $conf['token'] )['status'] );
gbr_check( 'tenant mismatch is refused before any slot work', 'tenant_mismatch' === $G::submit( 'acme', $catalog, $criteria( 'acme', 'other' ), $none, $service, $slot, $values, $conf['token'] )['status'] );

echo 'Summary: ' . $GLOBALS['gbr_pass'] . " passed, " . $GLOBALS['gbr_fail'] . " failed (guided-booking-runtime-tests)\n";
