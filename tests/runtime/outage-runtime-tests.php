<?php
/**
 * Real-WordPress outage checks for the SMS gateway (issue #340, from #151 and #8).
 *
 * When the SMS gateway is down, the telephone delivery filter must return no receipt, must not throw, and must not
 * touch local booking rows. Three gateway failures are simulated at the HTTP layer with pre_http_request, so no
 * request leaves the sandbox: a transport error, an HTTP 503, and a 201 with a body that is not valid JSON.
 *
 * The Twilio constants are defined only inside this PHP process (wp eval-file), which exits after the checks.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/outage-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail".
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	exit( 1 );
}

$GLOBALS['tzfd_outage'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_outage_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_outage'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

global $wpdb;

// Constants for this process only. The sandbox never reaches api.twilio.com: the HTTP filter below answers first.
if ( ! defined( 'TZFD_TWILIO_SMS_ENABLED' ) ) { define( 'TZFD_TWILIO_SMS_ENABLED', true ); }
if ( ! defined( 'TZFD_TWILIO_ACCOUNT_SID' ) ) { define( 'TZFD_TWILIO_ACCOUNT_SID', 'AC' . str_repeat( 'a', 32 ) ); }
if ( ! defined( 'TZFD_TWILIO_AUTH_TOKEN' ) ) { define( 'TZFD_TWILIO_AUTH_TOKEN', str_repeat( 'b', 32 ) ); }
if ( ! defined( 'TZFD_TWILIO_FROM' ) ) { define( 'TZFD_TWILIO_FROM', '+61400000000' ); }

tzfd_outage_ok( 'outage: Twilio transport is configured for the test', class_exists( 'TZFD_Twilio_SMS' ) && TZFD_Twilio_SMS::configured() );

$outage_mode  = 'error';
$outage_calls = 0;
$outage_hosts = array();
$outage_http  = function ( $pre, $args, $url ) use ( &$outage_mode, &$outage_calls, &$outage_hosts ) {
	$outage_calls++;
	$outage_hosts[] = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( 'error' === $outage_mode ) {
		return new WP_Error( 'http_request_failed', 'simulated gateway outage' );
	}
	if ( 'http503' === $outage_mode ) {
		return array( 'headers' => array(), 'body' => '{"message":"unavailable"}', 'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ), 'cookies' => array(), 'filename' => null );
	}
	return array( 'headers' => array(), 'body' => 'not json <html>', 'response' => array( 'code' => 201, 'message' => 'Created' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $outage_http, 10, 3 );

$outage_target = function ( $target, $company, $call, $recipient, $action ) {
	return array(
		'company_id' => 1, 'recipient_id' => 1, 'action' => $action,
		'to' => '+61400000001', 'consent' => true, 'body' => 'Outage check message',
	);
};
add_filter( 'tzfd_twilio_sms_resolve_target_v1', $outage_target, 10, 5 );

$outage_request = array(
	'company_id'   => 1,
	'call_id'      => 1,
	'recipient_id' => 1,
	'action'       => 'callback',
	'delivery_id'  => hash( 'sha256', 'outage-runtime-340' ),
);

$bookings_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$before_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bookings_table}" );

$outage_cases = array(
	'error'   => 'transport error (gateway unreachable)',
	'http503' => 'HTTP 503 from gateway',
	'badjson' => 'HTTP 201 with a body that is not JSON',
);

foreach ( $outage_cases as $mode => $label ) {
	$outage_mode  = $mode;
	$outage_calls = 0;
	$receipt      = 'unset';
	$threw        = '';
	try {
		$receipt = apply_filters( 'tzfd_telephone_deliver_v1', null, $outage_request );
	} catch ( Throwable $e ) {
		$threw = $e->getMessage();
	}
	tzfd_outage_ok( "outage: {$label} returns no receipt", null === $receipt && '' === $threw, '' === $threw ? 'receipt=' . var_export( $receipt, true ) : 'threw: ' . $threw );
	tzfd_outage_ok( "outage: {$label} makes exactly one attempt, to the Twilio host only", 1 === $outage_calls && array( 'api.twilio.com' ) === array_values( array_unique( $outage_hosts ) ), 'calls=' . $outage_calls );
	$outage_hosts = array();
}

// A repeat attempt during the outage must give the same result (no stuck state, no fatal error).
$outage_mode = 'error';
$again       = apply_filters( 'tzfd_telephone_deliver_v1', null, $outage_request );
tzfd_outage_ok( 'outage: repeat attempt after failure still returns no receipt', null === $again );

// Outbound webhook outage: a booking event is queued for cron (no HTTP call on the booking path), and a delivery
// attempt while the endpoint is down fails closed. The endpoint is example.com, a reserved host; pre_http_request answers before any request leaves the sandbox.
$outage_hook_config = function () {
	return array( 'url' => 'https://example.com/tzfd-outage', 'secret' => str_repeat( 'w', 40 ) );
};
add_filter( 'titan_zero_front_desk_webhook_config', $outage_hook_config );

$outage_hook_payload = function ( $booking_id ) {
	return array( 'schema_version' => 1, 'event' => 'booking.created', 'booking_id' => $booking_id, 'form_id' => 1, 'form_name' => 'Outage check', 'appointments' => array() );
};
$hook_queued = null;
$hook_threw  = '';
try {
	$hook_queued = TZFD_Webhooks::enqueue( 'booking.created', $outage_hook_payload( 1 ) );
} catch ( Throwable $e ) {
	$hook_threw = $e->getMessage();
}
tzfd_outage_ok( 'outage: booking.created event is queued while the webhook endpoint is down', true === $hook_queued && '' === $hook_threw, '' !== $hook_threw ? 'threw: ' . $hook_threw : '' );

// Build one well-formed body the same way enqueue() does, then attempt delivery while the endpoint fails.
$hook_canon = ( new ReflectionMethod( 'TZFD_Webhooks', 'canonical_payload' ) );
$hook_canon->setAccessible( true );
$hook_enc = ( new ReflectionMethod( 'TZFD_Webhooks', 'encode_payload' ) );
$hook_enc->setAccessible( true );
$hook_id_m = ( new ReflectionMethod( 'TZFD_Webhooks', 'event_id' ) );
$hook_id_m->setAccessible( true );
$hook_body = $hook_enc->invoke( null, $hook_canon->invoke( null, 'booking.created', $outage_hook_payload( 3 ) ) );
$hook_id   = is_string( $hook_body ) ? $hook_id_m->invoke( null, $hook_body ) : '';

$outage_mode  = 'error';
$outage_calls = 0;
$outage_hosts = array();
$hook_threw   = '';
try {
	TZFD_Webhooks::deliver( $hook_id, $hook_body, 1 );
} catch ( Throwable $e ) {
	$hook_threw = $e->getMessage();
}
tzfd_outage_ok( 'outage: webhook delivery during outage makes one attempt and does not throw', 1 === $outage_calls && '' === $hook_threw, 'calls=' . $outage_calls . ( '' !== $hook_threw ? ' threw: ' . $hook_threw : '' ) );
remove_filter( 'titan_zero_front_desk_webhook_config', $outage_hook_config );

// The local booking store keeps working while the gateway is down.
$probe_tag = 'outage-runtime-340';
$inserted  = $wpdb->insert(
	$bookings_table,
	array(
		'formid'      => 1,
		'time'        => current_time( 'mysql' ),
		'ipaddr'      => '127.0.0.1',
		'notifyto'    => '',
		'data'        => '',
		'posted_data' => serialize( array( 'apps' => array() ) ),
		'whoadded'    => $probe_tag,
		'company_id'  => 1,
	)
);
$probe_id = (int) $wpdb->insert_id;
tzfd_outage_ok( 'outage: local booking row can be written while the gateway is down', false !== $inserted && $probe_id > 0 );
$read_back = (string) $wpdb->get_var( $wpdb->prepare( "SELECT whoadded FROM {$bookings_table} WHERE id = %d", $probe_id ) );
tzfd_outage_ok( 'outage: local booking row reads back', $probe_tag === $read_back );
$wpdb->delete( $bookings_table, array( 'id' => $probe_id ) );
$after_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bookings_table}" );
tzfd_outage_ok( 'outage: gateway failures left the booking count unchanged', $before_count === $after_count, "before={$before_count} after={$after_count}" );

remove_filter( 'pre_http_request', $outage_http, 10 );
remove_filter( 'tzfd_twilio_sms_resolve_target_v1', $outage_target, 10 );

echo "\nPASS {$GLOBALS['tzfd_outage']['PASS']}  FAIL {$GLOBALS['tzfd_outage']['FAIL']}\n";
exit( $GLOBALS['tzfd_outage']['FAIL'] > 0 ? 1 : 0 );
