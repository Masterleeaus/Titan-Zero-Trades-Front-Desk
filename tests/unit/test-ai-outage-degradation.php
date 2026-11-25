<?php
/**
 * Issue #340: outage tests for the optional hosted AI connector.
 *
 * When the AI service is down, slow, failing or broken, the connector must return a structured result with
 * use_local set, never throw into the caller, give back the budget reservation when nothing reached the model,
 * and leave every local feature enabled. Offline: the transport is a fake supplied through the
 * tzfd_ai_connector_transport filter. Runs under tests/unit/run.php, and also directly (php file).
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-connector.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-services.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-central-tenant.php';

if ( ! function_exists( 'tzfd_assert' ) ) {
	// Direct run (php tests/unit/test-ai-outage-degradation.php): minimal assert with the same contract as run.php.
	$GLOBALS['tzfd_tests'] = array( 'pass' => 0, 'fail' => 0, 'failures' => array() );
	function tzfd_assert( $condition, $message ) {
		if ( $condition ) {
			$GLOBALS['tzfd_tests']['pass']++;
		} else {
			$GLOBALS['tzfd_tests']['fail']++;
			$GLOBALS['tzfd_tests']['failures'][] = $message;
		}
	}
}

/**
 * Issue #376 pairing: the hosted call fails closed unless this site holds a signed Central pairing.
 * Mirrors pair_site() in tests/ai-connector/run-ai-connector-tests.php, using the secret configured below.
 */
function tzfd_outage_pair( $site, $secret ) {
	TZFD_Central_Tenant::disconnect();
	$now   = time();
	$nonce = bin2hex( random_bytes( 16 ) );
	$body  = json_encode( array(
		'contract' => 'central-pairing/1', 'site' => $site, 'company_uuid' => '3f2b8c1e-5d4a-4b7e-9c1f-0a1b2c3d4e5f',
		'operations' => array( 'hosted.request' ), 'nonce' => $nonce,
		'issued_at' => $now, 'expires_at' => $now + 600,
		'feature_endpoint' => 'https://central.titanzero.io/api/tzfd/ai',
	) );
	$headers = array(
		'X-TZFD-Timestamp' => (string) $now,
		'X-TZFD-Nonce'     => $nonce,
		'X-TZFD-Signature' => TZFD_Central_Tenant::sign( $secret, (string) $now, $nonce, $body ),
	);
	return TZFD_Central_Tenant::connect( $body, $headers, $site, $secret, $now );
}

/** Resets stored options, filters and the call log, then configures the connector for faq.answer. */
function tzfd_outage_setup( $transport ) {
	$GLOBALS['tzfd_test_options'] = array();
	if ( null === $transport ) {
		unset( $GLOBALS['tzfd_test_filters']['tzfd_ai_connector_transport'] );
	} else {
		$GLOBALS['tzfd_test_filters']['tzfd_ai_connector_transport'] = $transport;
	}
	TZFD_AI_Connector::configure( 'https://ai.example.test/v1/request', 'site-a', str_repeat( 'k', 48 ) );
	TZFD_AI_Connector::grant_consent( array( 'faq.read', 'reports.read', 'customer.read', 'booking.read', 'booking.write' ) );
	TZFD_AI_Connector::set_entitlements( array( 'free_ai', 'assistant', 'agent_team' ) );
	tzfd_outage_pair( 'site-a', str_repeat( 'k', 48 ) );
}

/** Each outage shape a transport can produce, with the status the connector must map it to. */
function tzfd_test_ai_outage_status_matrix() {
	$C    = 'TZFD_AI_Connector';
	$cases = array(
		'transport reports offline'        => array( array( 'error' => 'offline' ), 'offline' ),
		'HTTP 500 from the service'        => array( array( 'status_code' => 500, 'headers' => array(), 'body' => '{"error":"down"}' ), 'service_error' ),
		'HTTP 503 with an empty body'      => array( array( 'status_code' => 503, 'headers' => array(), 'body' => '' ), 'service_error' ),
		'HTTP 200 with an empty body'      => array( array( 'status_code' => 200, 'headers' => array(), 'body' => '' ), 'invalid_response' ),
		'HTTP 200 with an oversize body'   => array( array( 'status_code' => 200, 'headers' => array(), 'body' => str_repeat( 'x', 70000 ) ), 'invalid_response' ),
		'transport returns a non-array'    => array( 'garbage', 'offline' ),
	);
	foreach ( $cases as $label => $pair ) {
		list( $raw, $expect ) = $pair;
		tzfd_outage_setup( function () use ( $raw ) { return $raw; } );
		$r = $C::request( 'faq.answer' );
		tzfd_assert( $expect === $r['status'], "outage '$label' maps to $expect, got " . $r['status'] );
		tzfd_assert( true === $r['use_local'], "outage '$label' sets use_local" );
		tzfd_assert( array() === $r['data'], "outage '$label' returns no data" );
	}
}

/** A transport that throws (for example a broken HTTP layer) must not escape into the booking flow. */
function tzfd_test_ai_outage_transport_throws() {
	$C = 'TZFD_AI_Connector';
	tzfd_outage_setup( function () { throw new RuntimeException( 'http layer exploded' ); } );
	$threw = false;
	try {
		$r = $C::request( 'faq.answer' );
	} catch ( Throwable $e ) {
		$threw = true;
		$r     = array( 'status' => '', 'use_local' => false );
	}
	tzfd_assert( ! $threw, 'a throwing transport does not escape the connector' );
	tzfd_assert( 'offline' === $r['status'] && true === $r['use_local'], 'a throwing transport is reported as offline with use_local' );
	tzfd_assert( 0 === ( $C::usage()['budget']['model_fallback'] ?? 0 ), 'a throwing transport gives its budget unit back' );
}

/** With no transport at all (no WordPress HTTP API), the connector degrades to offline and makes no call. */
function tzfd_test_ai_outage_no_transport() {
	$C = 'TZFD_AI_Connector';
	tzfd_outage_setup( null );
	$r = $C::request( 'faq.answer' );
	tzfd_assert( 'offline' === $r['status'] && true === $r['use_local'], 'no transport degrades to offline with use_local' );
	tzfd_assert( 0 === ( $C::usage()['budget']['model_fallback'] ?? 0 ), 'no transport gives its budget unit back' );
}

/** The local feature gate stays on whatever the service state is, so an outage never disables bookings. */
function tzfd_test_ai_outage_local_features_stay_on() {
	$S = 'TZFD_AI_Services';
	$states = array( null, array(), array( 'state' => 'expired' ), array( 'state' => 'revoked' ), array( 'state' => 'active', 'entitlements' => array( 'agent_team' ) ) );
	foreach ( $states as $i => $resolved ) {
		tzfd_assert( true === $S::local_features_enabled( $resolved ), "local features stay enabled for outage state #$i" );
	}
}

// Direct run: execute the tests here and exit 1 on any failure.
if ( PHP_SAPI === 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	foreach ( array( 'tzfd_test_ai_outage_status_matrix', 'tzfd_test_ai_outage_transport_throws', 'tzfd_test_ai_outage_no_transport', 'tzfd_test_ai_outage_local_features_stay_on' ) as $fn ) {
		$fn();
	}
	$t = $GLOBALS['tzfd_tests'];
	foreach ( $t['failures'] as $failure ) {
		echo 'FAIL ' . $failure . "\n";
	}
	echo 'AI outage degradation: passed ' . $t['pass'] . ', failed ' . $t['fail'] . "\n";
	exit( $t['fail'] > 0 ? 1 : 0 );
}
