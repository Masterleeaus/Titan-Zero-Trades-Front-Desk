<?php
/**
 * Real-WordPress checks for the agent gateway (issue #14): the option store, signed envelopes, replay, stop and
 * pause, company switch, dry run, executor handling, the receipt chain in the database, and admin capability.
 *
 * Runs inside a disposable WordPress site, via WP-CLI, from tests/runtime/run-wp-runtime.sh:
 *   wp eval-file tests/runtime/agent-gateway-runtime-tests.php --user=1
 * Output: one line per check, "PASS|FAIL name -- detail". No hosted agent or live service is called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "FAIL harness -- not running inside WordPress (use wp eval-file)\n";
	return;
}

$GLOBALS['tzfd_ag'] = array( 'PASS' => 0, 'FAIL' => 0 );

function tzfd_ag_ok( $name, $ok, $detail = '' ) {
	$status = $ok ? 'PASS' : 'FAIL';
	$GLOBALS['tzfd_ag'][ $status ]++;
	echo esc_html( $status . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) ) . "\n";
	return (bool) $ok;
}

$tzfd_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
if ( ! is_readable( $tzfd_inc . 'class-tzfd-agent-gateway.php' ) ) {
	tzfd_ag_ok( 'agent-gateway: class file readable', false, 'class-tzfd-agent-gateway.php not found in the active plugin' );
	return;
}
require_once $tzfd_inc . 'class-tzfd-agent-gateway.php';
require_once $tzfd_inc . 'class-tzfd-agent-gateway-admin.php';

wp_set_current_user( 1 );
global $wpdb;

$ag_store  = new TZFD_Agent_Gateway_Option_Store();
$ag_secret = TZFD_Agent_Gateway_Option_Store::secret();
$ag_now    = time();
$ag_n      = 0;
$ag_calls  = array();
$ag_exec   = function ( $payload ) use ( &$ag_calls ) {
	$ag_calls[] = $payload;
	return array( 'ok' => true, 'reason' => 'granted' );
};
$ag_env = function ( $over = array() ) use ( $ag_secret, $ag_now, &$ag_n ) {
	$ag_n++;
	$env = array_merge( array(
		'company_id' => 'rt-co-1', 'agent_id' => 'rt-agent', 'tool' => 'answer', 'args' => array( 'question' => 'Open Sundays?' ),
		'issued_at' => $ag_now, 'nonce' => sprintf( '%032x', 0xabc000 + $ag_n ),
	), $over );
	unset( $env['signature'] );
	$env['signature'] = TZFD_Agent_Gateway::sign( $ag_secret, $env );
	return $env;
};

// Start clean for this company.
foreach ( array( 'rt-co-1', 'rt-co-2' ) as $ag_co ) {
	delete_option( 'tzfd_agent_grant_' . $ag_co );
	delete_option( 'tzfd_agent_receipts_' . $ag_co );
}
delete_option( 'tzfd_agent_nonces' );

$ag_grant = array(
	'company_id' => 'rt-co-1', 'agent_id' => 'rt-agent', 'state' => 'active', 'dry_run' => false, 'version' => 1,
	'expires_at' => null, 'tools' => array( 'answer', 'book' ), 'auto_approve' => array(), 'max_per_hour' => array( 'answer' => 2 ),
);

// 1. Saving a grant: validated, stored per company, version bumps on each save.
$ag_saved = TZFD_Agent_Gateway::save_grant( $ag_store, $ag_grant );
tzfd_ag_ok( 'agent-gateway: grant saved to its own company option', is_array( $ag_store->get_grant( 'rt-co-1' ) ) && 1 === $ag_saved['version'] );
TZFD_Agent_Gateway::save_grant( $ag_store, $ag_grant );
tzfd_ag_ok( 'agent-gateway: saving again bumps the version', 2 === (int) $ag_store->get_grant( 'rt-co-1' )['version'] );
tzfd_ag_ok( 'agent-gateway: second company has no grant by default', null === $ag_store->get_grant( 'rt-co-2' ) );

// 2. Allowed call runs the executor and is recorded in the database.
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env(), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: allowed read runs once and is recorded as executed', 'executed' === $ag_r['outcome'] && 1 === count( $ag_calls ) );
$ag_chain = $ag_store->receipts_for_company( 'rt-co-1' );
tzfd_ag_ok( 'agent-gateway: receipt chain stored and verifies', 1 === count( $ag_chain ) && TZFD_Action_Receipt::verify_chain( $ag_secret, $ag_chain )['valid'] );

// 3. Tampered, replayed and stale envelopes.
$ag_before = count( $ag_calls );
$ag_bad    = $ag_env();
$ag_bad['args']['question'] = 'Cancel all bookings';
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_bad, $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: tampered envelope refused, nothing runs, nothing written', 'bad_signature' === $ag_r['reason'] && $ag_before === count( $ag_calls ) && 1 === count( $ag_store->receipts_for_company( 'rt-co-1' ) ) );
$ag_once = $ag_env( array( 'nonce' => str_repeat( 'b', 32 ) ) );
TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_once, $ag_now, $ag_exec );
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_once, $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: replayed envelope refused and recorded', 'replay' === $ag_r['reason'] && 'denied' === $ag_r['outcome'] );
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env( array( 'issued_at' => $ag_now - 900 ) ), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: stale envelope refused', 'stale_request' === $ag_r['reason'] );
$ag_nonces = get_option( 'tzfd_agent_nonces', array() );
tzfd_ag_ok( 'agent-gateway: nonces persisted for replay checks', is_array( $ag_nonces ) && isset( $ag_nonces[ str_repeat( 'b', 32 ) ] ) );

// 4. Pause, emergency stop and resume.
TZFD_Agent_Gateway::set_state( $ag_store, 'rt-co-1', 'paused' );
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env(), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: paused agent runs nothing', 'agent_paused' === $ag_r['reason'] );
TZFD_Agent_Gateway::set_state( $ag_store, 'rt-co-1', 'stopped' );
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env(), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: emergency stop runs nothing', 'agent_stopped' === $ag_r['reason'] );
TZFD_Agent_Gateway::set_state( $ag_store, 'rt-co-1', 'active' );
// A book call needs owner approval, so after resume it reaches the approval step rather than the state check.
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env( array( 'tool' => 'book', 'args' => array( 'service_id' => 'svc-1', 'location_id' => 'loc-a', 'date' => '2026-06-01', 'time' => '09:00', 'customer_id' => 'cust-1' ) ) ), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: resume lets calls past the state check', 'needs_approval' === $ag_r['outcome'] && 'owner_approval_required' === $ag_r['reason'] );

// 5. Company switch: a grant stored under company 2 that names company 1 cannot authorise company 2.
$ag_switch = $ag_grant;
$ag_store->put_grant( 'rt-co-2', $ag_switch );
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env( array( 'company_id' => 'rt-co-2' ) ), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: company switch refused', 'company_mismatch' === $ag_r['reason'] && false === $ag_r['executed'] );
$ag_co2 = $ag_store->receipts_for_company( 'rt-co-2' );
tzfd_ag_ok( 'agent-gateway: switch attempt is recorded under the requested company only', 1 === count( $ag_co2 ) && 'rt-co-2' === $ag_co2[0]['company_id'] && 'company_mismatch' === $ag_co2[0]['reason'] );

// 6. Rate limit: answer allows two an hour. Count what was executed in this run, not the earlier denials.
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env(), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: hourly ceiling enforced across stored receipts', 'rate_limited' === $ag_r['reason'] );

// 7. Dry run and approval, on a second grant version.
TZFD_Agent_Gateway::save_grant( $ag_store, array_merge( $ag_grant, array( 'dry_run' => true, 'max_per_hour' => array() ) ) );
$ag_before = count( $ag_calls );
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env( array( 'tool' => 'book', 'args' => array( 'service_id' => 'svc-1', 'location_id' => 'loc-a', 'date' => '2026-06-01', 'time' => '09:00', 'customer_id' => 'cust-1' ) ) ), $ag_now, $ag_exec );
tzfd_ag_ok( 'agent-gateway: dry run records and does not execute', 'dry_run' === $ag_r['outcome'] && $ag_before === count( $ag_calls ) );

// 8. No executor registered: allowed calls are recorded as failed, never as executed.
TZFD_Agent_Gateway::save_grant( $ag_store, array_merge( $ag_grant, array( 'max_per_hour' => array() ) ) );
$ag_r = TZFD_Agent_Gateway::handle( $ag_store, $ag_secret, $ag_env( array( 'args' => array( 'question' => 'Are you insured?' ) ) ), $ag_now );
tzfd_ag_ok( 'agent-gateway: allowed call without an executor is failed, not executed', 'failed' === $ag_r['outcome'] && 'no_executor' === $ag_r['reason'] );

// 9. Receipt chain tamper in the database is caught by the viewer.
$ag_chain = $ag_store->receipts_for_company( 'rt-co-1' );
$ag_chain[0]['outcome'] = 'denied';
update_option( 'tzfd_agent_receipts_rt-co-1', $ag_chain, false );
$ag_view = TZFD_Agent_Gateway::receipts_view( $ag_store, $ag_secret, 'rt-co-1', array(), 50 );
tzfd_ag_ok( 'agent-gateway: edited stored receipt breaks verification in the viewer', false === $ag_view['chain_valid'] && 1 === (int) $ag_view['broken_at_sequence'] );

// The append-only store refuses a receipt that would replace an existing sequence.
$ag_refused = false;
try {
	$ag_store->append_receipt( 'rt-co-1', array( 'sequence' => 1 ) );
} catch ( RuntimeException $e ) {
	$ag_refused = true;
}
tzfd_ag_ok( 'agent-gateway: store refuses to write an existing sequence number', $ag_refused );

// 10. Admin: only a manager can see or change the screen. The subscriber from provisioning gets nothing.
tzfd_ag_ok( 'agent-gateway: admin menu registered', false !== has_action( 'admin_menu', array( 'TZFD_Agent_Gateway_Admin', 'menu' ) ) );
$ag_sub = get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $ag_sub ) ) {
	wp_set_current_user( (int) $ag_sub[0] );
	ob_start();
	TZFD_Agent_Gateway_Admin::render();
	$ag_html = ob_get_clean();
	tzfd_ag_ok( 'agent-gateway: subscriber sees nothing on the permissions screen', '' === $ag_html );
	wp_set_current_user( 1 );
} else {
	tzfd_ag_ok( 'agent-gateway: subscriber check skipped (no subscriber on this site)', true );
}
ob_start();
TZFD_Agent_Gateway_Admin::render();
$ag_html = ob_get_clean();
tzfd_ag_ok( 'agent-gateway: administrator sees the permissions screen', false !== strpos( $ag_html, 'Agent permissions and receipts' ) );

// Clean up what this suite wrote.
foreach ( array( 'rt-co-1', 'rt-co-2' ) as $ag_co ) {
	delete_option( 'tzfd_agent_grant_' . $ag_co );
	delete_option( 'tzfd_agent_receipts_' . $ag_co );
}
delete_option( 'tzfd_agent_nonces' );

echo 'Summary (agent-gateway-runtime): ' . (int) $GLOBALS['tzfd_ag']['PASS'] . ' passed, ' . (int) $GLOBALS['tzfd_ag']['FAIL'] . " failed\n";
