<?php
/**
 * WordPress runtime checks for the cancel/reschedule assistant REST entry point (issues #25, #336).
 * Run: wp --allow-root eval-file tests/runtime/cancel-assistant-api-tests.php   (disposable test site only)
 * Prints PASS and FAIL lines; the CI step gates on the log.
 */
$tz_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
require_once $tz_inc . 'class-tzfd-cancel-assistant-api.php';

$GLOBALS['caa_pass'] = 0; $GLOBALS['caa_fail'] = 0;
function caa_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['caa_pass']++; } else { $GLOBALS['caa_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}
function caa_request( $params ) {
    $req = new WP_REST_Request( 'POST', '/titan-zero-front-desk/v1/cancel-assistant' );
    $req->set_body_params( $params );
    return $req;
}

$routes = rest_get_server()->get_routes();
caa_check( 'cancel-assistant route is registered', isset( $routes['/titan-zero-front-desk/v1/cancel-assistant'] ) );

$missing = 987654321; // Not a real booking on this site.
$out = TZFD_Cancel_Assistant_API::handle( caa_request( array(
    'message' => 'Please cancel my appointment', 'booking_id' => $missing, 'email' => 'x@example.test',
    'expires' => (string) ( time() + 3600 ), 'token' => str_repeat( 'a', 64 ),
) ) );
caa_check( 'missing booking is refused', 'refuse' === $out['action'], json_encode( $out ) );
caa_check( 'reply is the generic not-found text', false !== strpos( $out['message'], 'could not find' ) );

$out = TZFD_Cancel_Assistant_API::handle( caa_request( array(
    'message' => 'Please cancel my appointment', 'booking_id' => 0, 'email' => '', 'token' => '', 'expires' => 0,
) ) );
caa_check( 'no booking id is refused', 'refuse' === $out['action'], json_encode( $out ) );

$out = TZFD_Cancel_Assistant_API::handle( caa_request( array(
    'message' => 'Hi, what are your hours?', 'booking_id' => $missing,
) ) );
caa_check( 'uncertain or unrelated text hands off or refuses, never changes', in_array( $out['action'], array( 'handoff_to_staff', 'refuse' ), true ), json_encode( $out ) );

$out = TZFD_Cancel_Assistant_API::handle( caa_request( array( 'message' => 'cancel', 'booking_id' => $missing, 'caller_phone' => '+61400000000' ) ) );
caa_check( 'phone number is not an input and is not echoed', false === strpos( json_encode( $out ), '+61400000000' ) );
caa_check( 'response has no booking or email fields', ! isset( $out['booking_id'] ) && ! isset( $out['email'] ) && ! isset( $out['token'] ) );

caa_check( 'policy text falls back when no hours are set', false !== strpos( $out['policy'], 'contact us' ), $out['policy'] );
add_filter( TZFD_Cancel_Assistant_API::POLICY_FILTER, function () { return array( 'cancel_notice_hours' => 48, 'reschedule_notice_hours' => 24 ); } );
caa_check( 'policy text uses owner hours from the filter', false !== strpos( TZFD_Cancel_Assistant_API::policy_text(), '48 hours' ) );
remove_all_filters( TZFD_Cancel_Assistant_API::POLICY_FILTER );

echo "\nSummary: {$GLOBALS['caa_pass']} passed, {$GLOBALS['caa_fail']} failed\n";
