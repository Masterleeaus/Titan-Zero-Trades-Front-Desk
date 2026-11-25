<?php
/**
 * Standalone checks for TZFD_Guided_Booking. Not shipped in the plugin package.
 * Run: php -l and php <this file>. Exit code 0 means all checks passed.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
}
// TZFD_INCLUDES lets the WordPress run load the installed copy, so class files are not loaded twice from two paths.
$tzfd_includes = getenv( 'TZFD_INCLUDES' ) ? getenv( 'TZFD_INCLUDES' ) : dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes';
require_once $tzfd_includes . '/class-tzfd-guided-booking.php';

// Counters live in $GLOBALS so the count is shared when this file runs under wp eval-file.
$GLOBALS['tzfd_gb_pass'] = 0;
$GLOBALS['tzfd_gb_fail'] = 0;
function check( $label, $ok ) {
    if ( $ok ) { $GLOBALS['tzfd_gb_pass']++; return; }
    $GLOBALS['tzfd_gb_fail']++;
    echo "FAIL: $label\n";
}
function throws( $callable ) {
    try { $callable(); return false; } catch ( Throwable $e ) { return true; }
}

$catalog = TZFD_Resource_Catalog::validate( array(
    'schema_version' => 1,
    'locations'      => array( array( 'id' => 'mel', 'name' => 'Melbourne', 'timezone' => 'Australia/Melbourne', 'enabled' => true ) ),
    'resources'      => array( array(
        'id' => 'tech1', 'name' => 'Tech One', 'kind' => 'provider', 'location_id' => 'mel', 'capacity' => 1, 'enabled' => true,
        'service_ids' => array( 'deep' ),
        'weekly' => array( array( 'day' => 1, 'start' => '09:00', 'end' => '17:00' ) ),
    ) ),
) );
$services = TZFD_Guided_Booking::normalise_services( array(
    array( 'id' => 'deep', 'name' => 'Deep Clean', 'duration_minutes' => 60 ),
    array( 'id' => 'win', 'name' => 'Window Wash', 'duration_minutes' => 30 ),
) );
$service = $services[0];
$values  = TZFD_Guided_Booking::normalise_fields( array( 'name' => 'Ana', 'contact' => 'ana@example.com' ), array( 'name', 'contact' ) );

// Monday 2026-10-05 09:00 AEDT (UTC+11) is 2026-10-04T22:00:00Z.
function criteria( $company = 'acme', $verified = 'acme' ) {
    return array(
        'company_id' => $company, 'verified_company_id' => $verified,
        'snapshot_company_id' => $company, 'snapshot_complete' => true,
        'required_resource_ids' => array( 'tech1' ), 'service_id' => 'deep', 'location_id' => 'mel',
        'start' => '2026-10-04T22:00:00Z', 'end' => '2026-10-05T06:00:00Z',
        'duration_minutes' => 60, 'step_minutes' => 60, 'limit' => 20,
    );
}
$none = array();

// Intent detection (fixed rules).
check( 'book intent', 'book' === TZFD_Guided_Booking::detect_intent( 'I want to book a clean' ) );
check( 'availability intent', 'availability' === TZFD_Guided_Booking::detect_intent( 'are you available on Friday?' ) );
check( 'unrelated text is unknown', 'unknown' === TZFD_Guided_Booking::detect_intent( 'what is the weather' ) );
check( 'non-string is unknown', 'unknown' === TZFD_Guided_Booking::detect_intent( array( 'book' ) ) );
check( 'oversized text is unknown', 'unknown' === TZFD_Guided_Booking::detect_intent( str_repeat( 'book ', 300 ) ) );

// Service matching comes only from the supplied catalogue.
$hits = TZFD_Guided_Booking::match_services( $services, 'I need a deep clean please' );
check( 'service name matched', 1 === count( $hits ) && 'deep' === $hits[0]['id'] );
check( 'no service match gives empty list', array() === TZFD_Guided_Booking::match_services( $services, 'plumbing' ) );

// Service and field validation.
check( 'duplicate service ID rejected', throws( function () use ( $services ) {
    TZFD_Guided_Booking::normalise_services( array( $services[0], $services[0] ) );
} ) );
check( 'bad duration rejected', throws( function () {
    TZFD_Guided_Booking::normalise_services( array( array( 'id' => 'x', 'name' => 'X', 'duration_minutes' => 3 ) ) );
} ) );
check( 'missing required name rejected', throws( function () {
    TZFD_Guided_Booking::normalise_fields( array( 'contact' => 'ana@example.com' ), array( 'name', 'contact' ) );
} ) );
check( 'bad contact rejected', throws( function () {
    TZFD_Guided_Booking::normalise_fields( array( 'name' => 'Ana', 'contact' => 'not-a-contact' ), array( 'contact' ) );
} ) );
check( 'control characters stripped', 'Ana B' === TZFD_Guided_Booking::normalise_fields( array( 'name' => "Ana\x00B" ), array( 'name' ) )['name'] );
check( 'non-array values rejected', throws( function () { TZFD_Guided_Booking::normalise_fields( 'x', array() ); } ) );

// Availability comes only from the engine.
$ok = TZFD_Guided_Booking::propose( $catalog, criteria(), $none );
check( 'engine offers a slot', 'ok' === $ok['status'] && '2026-10-04T22:00:00Z' === $ok['slots'][0]['start'] );
check( 'reservation snapshot unavailable fails closed (provider offline)', 'engine_unavailable' === TZFD_Guided_Booking::propose( $catalog, criteria(), null )['status'] );
check( 'wrong company scope rejected', 'engine_rejected' === TZFD_Guided_Booking::propose( $catalog, criteria( 'acme', 'other' ), $none )['status'] );

// Capacity oversell: a taken slot must not be offered.
$taken = array( array( 'id' => 'r1', 'resource_id' => 'tech1', 'quantity' => 1, 'status' => 'confirmed',
    'start' => '2026-10-04T22:00:00Z', 'end' => '2026-10-04T23:00:00Z' ) );
$after = TZFD_Guided_Booking::propose( $catalog, criteria(), $taken );
check( 'taken slot not offered', 'ok' === $after['status'] && '2026-10-04T23:00:00Z' === $after['slots'][0]['start'] );

// Confirmation shows local time in the location zone (DST: AEST before 4 Oct 2026, AEDT after).
$slot = array( 'start' => '2026-10-04T22:00:00Z', 'end' => '2026-10-04T23:00:00Z', 'resource_ids' => array( 'tech1' ) );
$conf = TZFD_Guided_Booking::confirmation( 'acme', $catalog, 'mel', $service, $slot, $values );
check( 'confirmation shows AEDT local time', false !== strpos( $conf['lines'][1], 'Mon 5 Oct 2026, 09:00 to 10:00 AEDT' ) );
$pre = array( 'start' => '2026-10-03T13:00:00Z', 'end' => '2026-10-03T14:00:00Z', 'resource_ids' => array( 'tech1' ) );
$conf_pre = TZFD_Guided_Booking::confirmation( 'acme', $catalog, 'mel', $service, $pre, $values );
check( 'confirmation shows AEST before DST change', false !== strpos( $conf_pre['lines'][1], 'Sat 3 Oct 2026, 23:00 to 00:00 AEST' ) );
check( 'confirmation does not commit', false === $conf['commit'] && 64 === strlen( $conf['token'] ) );
check( 'unknown location rejected', throws( function () use ( $service, $slot, $values, $catalog ) {
    TZFD_Guided_Booking::confirmation( 'acme', $catalog, 'nope', $service, $slot, $values );
} ) );

// Submit gate: happy path, tampered choice, slot taken since confirmation, tenant mismatch.
$ready = TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), $none, $service, $slot, $values, $conf['token'] );
check( 'valid confirmation is ready (no commit made here)', 'ready' === $ready['status'] && 'Ana' === $ready['payload']['name'] );
$changed = TZFD_Guided_Booking::normalise_fields( array( 'name' => 'Bob', 'contact' => 'ana@example.com' ), array( 'name', 'contact' ) );
check( 'tampered name invalidates token', 'confirmation_mismatch' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), $none, $service, $slot, $changed, $conf['token'] )['status'] );
check( 'missing token refused', 'confirmation_mismatch' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), $none, $service, $slot, $values, null )['status'] );
check( 'slot taken after confirmation refused', 'slot_unavailable' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), $taken, $service, $slot, $values, $conf['token'] )['status'] );
check( 'submit with unavailable snapshot refused', 'engine_unavailable' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), null, $service, $slot, $values, $conf['token'] )['status'] );
check( 'tenant mismatch refused', 'tenant_mismatch' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria( 'acme', 'other' ), $none, $service, $slot, $values, $conf['token'] )['status'] );

// Issue #441 gap checks: cross-tenant token replay, off-grid slot, service swap, malformed company scope.
$other_conf = TZFD_Guided_Booking::confirmation( 'other', $catalog, 'mel', $service, $slot, $values );
check( 'token issued for another company is refused', 'confirmation_mismatch' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), $none, $service, $slot, $values, $other_conf['token'] )['status'] );
$off_grid = array( 'start' => '2026-10-04T22:30:00Z', 'end' => '2026-10-04T23:30:00Z', 'resource_ids' => array( 'tech1' ) );
$off_conf = TZFD_Guided_Booking::confirmation( 'acme', $catalog, 'mel', $service, $off_grid, $values );
check( 'slot not offered by the engine is refused even with a matching token', 'slot_unavailable' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), $none, $service, $off_grid, $values, $off_conf['token'] )['status'] );
$win = $services[1];
check( 'token for one service does not accept another service', 'confirmation_mismatch' === TZFD_Guided_Booking::submit( 'acme', $catalog, criteria(), $none, $win, $slot, $values, $conf['token'] )['status'] );
check( 'malformed company scope throws before any engine work', throws( function () use ( $catalog, $service, $slot, $values ) {
    TZFD_Guided_Booking::submit( 'acme one!', $catalog, criteria( 'acme one!' ), array(), $service, $slot, $values, 'x' );
} ) );

echo $GLOBALS['tzfd_gb_pass'] . " passed, " . $GLOBALS['tzfd_gb_fail'] . " failed\n";
if ( $GLOBALS['tzfd_gb_pass'] !== 31 || $GLOBALS['tzfd_gb_fail'] ) { echo "Check count or failures differ from expected.\n"; exit( 1 ); }
