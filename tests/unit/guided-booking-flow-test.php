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
require_once $tzfd_includes . '/class-tzfd-guided-booking-runtime.php';

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

// Runtime entry points (issue #336): pure helpers behind the read-only REST routes. No WordPress needed.
$R = 'TZFD_Guided_Booking_Runtime';
if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action ) { return 'good-nonce' === $nonce && 'wp_rest' === $action ? 1 : false; }
}
// Permission: the REST nonce is required. Missing, wrong or non-request input is refused (no anonymous access).
$req_stub = function ( $nonce ) {
    return new class( $nonce ) {
        private $n;
        public function __construct( $n ) { $this->n = $n; }
        public function get_header( $name ) { return 'x_wp_nonce' === $name ? $this->n : null; }
    };
};
gbf_check( 'runtime permission: valid REST nonce allowed', true === $R::nonce_permission( $req_stub( 'good-nonce' ) ) );
gbf_check( 'runtime permission: missing nonce refused', false === $R::nonce_permission( $req_stub( null ) ) );
gbf_check( 'runtime permission: empty nonce refused', false === $R::nonce_permission( $req_stub( '' ) ) );
gbf_check( 'runtime permission: wrong nonce refused', false === $R::nonce_permission( $req_stub( 'bad' ) ) );
gbf_check( 'runtime permission: non-request refused', false === $R::nonce_permission( null ) );

$catalog_ids = array( 'deep', 'win' );
$raw_services = array(
    array( 'id' => 'deep', 'name' => 'Deep Clean', 'duration_minutes' => 60 ),
    array( 'id' => 'win', 'name' => 'Window Wash', 'duration_minutes' => 30 ),
);

// Normal: one clear match asks for confirmation; a valid chosen ID moves on.
$step = $R::guided_step( 'I want to book a deep clean', $raw_services );
gbf_check( 'runtime: single match asks confirmation', 'confirm_service' === $step['step'] && array( 'deep' ) === $step['service_ids'] );
$step = $R::guided_step( 'book', $raw_services, 'win' );
gbf_check( 'runtime: valid chosen service shows availability', 'show_availability' === $step['step'] && array( 'win' ) === $step['service_ids'] );

// Negative and tenant-safe: an ID outside the owner's list is never returned or accepted.
$step = $R::guided_step( 'book', $raw_services, 'plumbing-99' );
gbf_check( 'runtime: unknown chosen ID refused', 'choose_service' === $step['step'] && $catalog_ids === $step['service_ids'] );
$step = $R::guided_step( 'book a clean or a wash', $raw_services, 'deep" OR 1=1' );
gbf_check( 'runtime: injected ID refused', 'choose_service' === $step['step'] && $catalog_ids === $step['service_ids'] );
$all_inside = true;
foreach ( array( 'book', 'book a deep clean', 'book something', 'cancel my booking', 'hello' ) as $probe ) {
    foreach ( $R::guided_step( $probe, $raw_services )['service_ids'] as $id ) {
        if ( ! in_array( $id, $catalog_ids, true ) ) { $all_inside = false; }
    }
}
gbf_check( 'runtime: every returned service ID comes from the owner list', $all_inside );

// No services configured: a booking request gets the fallback, never an empty prompt. Change still hands off.
$step = $R::guided_step( 'I want to book', array() );
gbf_check( 'runtime: no services gives fallback for booking', 'fallback' === $step['step'] && array() === $step['service_ids'] );
$step = $R::guided_step( 'cancel my booking', null );
gbf_check( 'runtime: change request still hands off with no services', 'handoff' === $step['step'] );

// Invalid owner list (duplicate IDs, non-array) never fatals and never leaks services.
$step = $R::guided_step( 'book a deep clean', array( $raw_services[0], $raw_services[0] ) );
gbf_check( 'runtime: duplicate owner IDs give fallback', 'fallback' === $step['step'] );
gbf_check( 'runtime: non-array owner list gives fallback', 'fallback' === $R::guided_step( 'book', 'not-a-list' )['step'] );
gbf_check( 'runtime: services_from_filter rejects bad input to empty', array() === $R::services_from_filter( 42 ) );

// Privacy: fixed copy only. A phone number or name in the customer text is never echoed back.
$secret_text = 'book a deep clean, my name is Ann Smith, 0412 345 678';
$step = $R::guided_step( $secret_text, $raw_services );
$echo = json_encode( $step );
gbf_check( 'runtime: customer phone not echoed', false === strpos( $echo, '0412' ) );
gbf_check( 'runtime: customer name not echoed', false === strpos( $echo, 'Ann' ) );
gbf_check( 'runtime: non-string text is unknown and falls back', 'fallback' === $R::guided_step( array( 'book' ), $raw_services )['step'] );

// Cancel assistant runtime entry point: never a change action, never a phone-number lookup.
$policy_ok = array( 'cancel_notice_hours' => 24, 'reschedule_notice_hours' => 12 );
$plan = $R::cancel_plan( 'Please cancel my booking', $policy_ok );
gbf_check( 'runtime cancel: asks for the secure link', 'request_verified_link' === $plan['action'] && 'cancel' === $plan['intent'] );
$plan = $R::cancel_plan( 'Cancel my booking, my number is 0412 345 678', $policy_ok );
gbf_check( 'runtime cancel: a phone number does not authorise a change', 'request_verified_link' === $plan['action'] );
$plan = $R::cancel_plan( 'Can I reschedule to Friday?', $policy_ok );
gbf_check( 'runtime reschedule: asks for the secure link', 'request_verified_link' === $plan['action'] && 'reschedule' === $plan['intent'] );
$plan = $R::cancel_plan( 'Cancel and rebook me please', $policy_ok );
gbf_check( 'runtime: cancel and rebook is handed to staff', 'handoff_to_staff' === $plan['action'] );
$plan = $R::cancel_plan( 'Do you do gutter cleaning?', $policy_ok );
gbf_check( 'runtime: plain enquiry is not applicable', 'not_applicable' === $plan['action'] && '' === $plan['message'] );
$never_change = true;
foreach ( array( 'cancel', 'reschedule', 'cancel and rebook', 'hello', '' ) as $probe ) {
    if ( 0 === strpos( $R::cancel_plan( $probe, $policy_ok )['action'], 'send_' ) ) { $never_change = false; }
}
gbf_check( 'runtime cancel: no input yields a send_* change action', $never_change );
gbf_check( 'runtime cancel: missing policy gives contact wording', false !== strpos( $R::cancel_plan( 'cancel', null )['policy'], 'Please contact us' ) );
gbf_check( 'runtime cancel: policy text uses only validated numbers', false !== strpos( $R::cancel_plan( 'cancel', $policy_ok )['policy'], '24 hours' ) );

echo $GLOBALS['tzfd_gbf_pass'] . " passed, " . $GLOBALS['tzfd_gbf_fail'] . " failed\n";
exit( $GLOBALS['tzfd_gbf_fail'] ? 1 : 0 );
