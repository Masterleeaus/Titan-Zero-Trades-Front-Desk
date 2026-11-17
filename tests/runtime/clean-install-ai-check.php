<?php
/**
 * Clean-install check for issue #2: a freshly activated Front Desk free edition makes no AI
 * call and shows no AI credential prompt.
 *
 * Run on a disposable WordPress site straight after plugin activation, before any add-on or
 * option is set by a harness:
 *   wp --allow-root --path=<wp> eval-file tests/runtime/clean-install-ai-check.php
 *
 * Not shipped in the plugin. Prints FAIL lines and exits non-zero if any check fails.
 */
if ( ! defined( 'WP_PLUGIN_DIR' ) ) { echo "FAIL harness -- run through WP-CLI\n"; exit( 1 ); }

$GLOBALS['ci_pass'] = 0;
$GLOBALS['ci_fail'] = 0;
function ci_check( $name, $ok, $detail = '' ) {
	if ( $ok ) { $GLOBALS['ci_pass']++; } else { $GLOBALS['ci_fail']++; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

$tz_connector = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-connector.php';
if ( ! class_exists( 'TZFD_AI_Connector' ) && is_readable( $tz_connector ) ) { require_once $tz_connector; }
ci_check( 'connector class loads on a clean install', class_exists( 'TZFD_AI_Connector' ) );

// 1. No AI connection, secret, consent or entitlement is stored by default.
$status = class_exists( 'TZFD_AI_Connector' ) ? TZFD_AI_Connector::status() : array();
ci_check( 'AI connector reports not connected', isset( $status['connected'] ) && false === $status['connected'] );
ci_check( 'AI connector has no consent recorded', isset( $status['consent'] ) && false === $status['consent'] );
ci_check( 'AI connector has no entitlement', empty( $status['entitlements'] ) );
ci_check( 'no AI connector secret stored', false === get_option( 'tzfd_ai_connector_secret', false ) || '' === get_option( 'tzfd_ai_connector_secret', '' ) );
ci_check( 'no legacy AI API key option stored', false === get_option( 'tz_fd_ai_api_key', false ) && false === get_option( 'tz_fd_ai_local_key', false ) );

// 2. No AI cron job is scheduled.
$ai_hooks = array();
foreach ( (array) _get_cron_array() as $events ) {
	foreach ( (array) $events as $hook => $unused ) {
		if ( preg_match( '/(^|_)ai(_|$)|assistant|agent_team/i', (string) $hook ) ) { $ai_hooks[] = $hook; }
	}
}
ci_check( 'no AI cron event scheduled', array() === $ai_hooks, implode( ',', $ai_hooks ) );

// 3. Calling any hosted AI feature makes no outbound request. The transport seam records every attempt.
$transport_calls = 0;
$probe = function ( $pre, $request ) use ( &$transport_calls ) {
	$transport_calls++;
	return array( 'error' => 'offline' );
};
add_filter( 'tzfd_ai_connector_transport', $probe, 10, 2 );
$refused = 0;
if ( class_exists( 'TZFD_AI_Connector' ) ) {
	foreach ( array_keys( TZFD_AI_Connector::features() ) as $feature ) {
		$result = TZFD_AI_Connector::request( $feature, array( 'question' => 'clean install probe' ) );
		if ( isset( $result['status'] ) && 'consent_required' === $result['status'] ) { $refused++; }
	}
}
remove_filter( 'tzfd_ai_connector_transport', $probe, 10 );
ci_check( 'no transport call for any hosted AI feature', 0 === $transport_calls, 'calls=' . $transport_calls );
ci_check( 'every hosted AI feature refused without consent', class_exists( 'TZFD_AI_Connector' ) && $refused === count( TZFD_AI_Connector::features() ) );

echo 'Summary: ' . $GLOBALS['ci_pass'] . ' passed, ' . $GLOBALS['ci_fail'] . " failed\n";
if ( $GLOBALS['ci_fail'] > 0 ) { exit( 1 ); }
