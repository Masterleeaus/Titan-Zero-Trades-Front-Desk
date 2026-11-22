<?php
/**
 * Unit checks for the guided-booking flow step (next_step) and the cancel/reschedule assistant entry point (plan).
 * Covers the change-intent routing defect (issue #336), normal, invalid and tenant-safe inputs. No WordPress needed.
 * Run: php tests/unit/guided-booking-flow-test.php   Not shipped in the plugin package.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
}
$tzfd_includes = getenv( 'TZFD_INCLUDES' ) ? getenv( 'TZFD_INCLUDES' ) : dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes';
require_once $tzfd_includes . '/class-tzfd-guided-booking.php';
require_once $tzfd_includes . '/class-tzfd-cancel-reschedule-assistant.php';

$GLOBALS['tzfd_gbf_pass'] = 0;
$GLOBALS['tzfd_gbf_fail'] = 0;
function gbf_check( $label, $ok ) {
    if ( $ok ) { $GLOBALS['tzfd_gbf_pass']++; return; }
    $GLOBALS['tzfd_gbf_fail']++;
    echo "FAIL: $label\n";
}
function gbf_throws( $callable ) {
    try { $callable(); return false; } catch ( Throwable $e ) { return true; }
}

$G = 'TZFD_Guided_Booking';
$A = 'TZFD_Cancel_Reschedule_Assistant';

$services = $G::normalise_services( array(
    array( 'id' => 'deep', 'name' => 'Deep Clean', 'duration_minutes' => 60 ),
    array( 'id' => 'win', 'name' => 'Window Wash', 'duration_minutes' => 30 ),
) );

// Defect: change wording used to start the booking flow because "appointment" matched the booking rule.
gbf_check( 'reschedule my appointment is change, not book', 'change' === $G::detect_intent( 'I need to reschedule my appointment' ) );
gbf_check( 'cancel my booking is change', 'change' === $G::detect_intent( 'Please cancel my booking' ) );
gbf_check( 'rebook is change', 'change' === $G::detect_intent( 'can you rebook me for Friday' ) );
gbf_check( 'plain book still books', 'book' === $G::detect_intent( 'I want to book a deep clean' ) );

// next_step: change requests hand off and never reach availability.
$step = $G::next_step( 'Please cancel my appointment', $services );
gbf_check( 'change request hands off', 'handoff' === $step['step'] && array() === $step['service_ids'] );
gbf_check( 'handoff message is set', $G::CHANGE_MESSAGE === $step['message'] );

// Unknown intent gets the standard fallback.
$step = $G::next_step( 'what is the weather', $services );
gbf_check( 'unknown intent falls back', 'fallback' === $step['step'] && $G::FALLBACK_MESSAGE === $step['message'] );

// One clear service match asks the customer to confirm it; the service comes only from the catalogue.
$step = $G::next_step( 'I want to book a deep clean', $services );
gbf_check( 'single match asks confirmation', 'confirm_service' === $step['step'] && array( 'deep' ) === $step['service_ids'] );

// No match gives the full catalogue, never an invented service.
$step = $G::next_step( 'I want to book something', $services );
gbf_check( 'no match lists the whole catalogue', 'choose_service' === $step['step'] && array( 'deep', 'win' ) === $step['service_ids'] );

// Two equal matches give a shortlist of both.
$step = $G::next_step( 'book a clean or a wash', $services );
gbf_check( 'several matches give a shortlist', 'choose_service' === $step['step'] && array( 'deep', 'win' ) === $step['service_ids'] );

// A valid chosen service ID moves to availability; an unknown ID is refused, not added.
$step = $G::next_step( 'book', $services, 'win' );
gbf_check( 'valid chosen service shows availability', 'show_availability' === $step['step'] && array( 'win' ) === $step['service_ids'] );
$step = $G::next_step( 'book', $services, 'plumbing-99' );
gbf_check( 'unknown chosen service refused', 'choose_service' === $step['step'] && array( 'deep', 'win' ) === $step['service_ids'] );
$step = $G::next_step( 'book', $services, array( 'win' ) );
gbf_check( 'non-string chosen service refused', 'choose_service' === $step['step'] );

gbf_check( 'invalid service list rejected', gbf_throws( function () use ( $G ) { $G::next_step( 'book', 'not-a-list' ); } ) );
gbf_check( 'duplicate services rejected', gbf_throws( function () use ( $G, $services ) { $G::next_step( 'book', array( $services[0], $services[0] ) ); } ) );

// Cancel/reschedule entry point: plan() composes classify, decide and policy without changing anything.
$policy = array( 'cancel_notice_hours' => 24, 'reschedule_notice_hours' => 12 );
$facts  = array( 'booking_found' => true, 'booking_company_id' => 7, 'request_company_id' => 7, 'ownership_verified' => false );

$plan = $A::plan( 'Please cancel my booking', $facts, $policy );
gbf_check( 'unverified cancel asks for the signed link', 'cancel' === $plan['intent'] && 'request_verified_link' === $plan['action'] );
gbf_check( 'verify message names the link, not the phone', false !== strpos( $plan['message'], 'secure link' ) );
gbf_check( 'plan carries policy text', false !== strpos( $plan['policy'], '24 hours' ) );

$plan = $A::plan( 'Can I reschedule to Friday?', array_merge( $facts, array( 'ownership_verified' => true ) ), $policy );
gbf_check( 'verified reschedule sends reschedule link', 'send_reschedule_link' === $plan['action'] );

$plan = $A::plan( 'Please cancel my booking', array_merge( $facts, array( 'request_company_id' => 8 ) ), $policy );
gbf_check( 'cross-tenant change refused', 'refuse' === $plan['action'] );

$plan = $A::plan( 'Please cancel my booking', array_merge( $facts, array( 'booking_found' => false ) ), $policy );
gbf_check( 'missing booking refused with the same answer as foreign', 'refuse' === $plan['action'] );

$plan = $A::plan( 'Cancel and rebook me please', array_merge( $facts, array( 'ownership_verified' => true ) ), $policy );
gbf_check( 'cancel and rebook is handed to staff', 'handoff_to_staff' === $plan['action'] );

$plan = $A::plan( 'Do you do gutter cleaning?', array_merge( $facts, array( 'ownership_verified' => true ) ), $policy );
gbf_check( 'plain enquiry is not applicable', 'not_applicable' === $plan['action'] && '' === $plan['message'] );

$plan = $A::plan( 'Please cancel my booking', array_merge( $facts, array( 'ownership_verified' => true ) ), array( 'cancel_notice_hours' => -1 ) );
gbf_check( 'bad policy numbers fall back to contact wording', false !== strpos( $plan['policy'], 'Please contact us' ) );

echo $GLOBALS['tzfd_gbf_pass'] . " passed, " . $GLOBALS['tzfd_gbf_fail'] . " failed\n";
exit( $GLOBALS['tzfd_gbf_fail'] ? 1 : 0 );
