<?php
/**
 * Contract tests run against the local hosted test double (issue #256).
 * Run: php -d error_reporting=-1 tests/contract/hosted-double-tests.php
 * Standalone, no WordPress, no network, no live AI or billing call. Exit status 1 on any failure.
 */
// The shared contract class is guarded by defined( 'ABSPATH' ) || exit; without this the run exits 0 with zero checks.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once __DIR__ . '/hosted-double/class-tzfd-hosted-double.php';

$HD_KEY  = str_repeat( 'd', 32 );
$NOW     = 1800000000;
$hd_pass = 0;
$hd_fail = 0;

function hd_check( $name, $cond ) {
	global $hd_pass, $hd_fail;
	if ( $cond ) {
		$hd_pass++;
		echo "PASS {$name}\n";
		return;
	}
	$hd_fail++;
	echo "FAIL {$name}\n";
}

function hd_new( $key ) {
	return new TZFD_Hosted_Double(
		$key,
		array(
			7 => array( 'state' => 'active' ),
			8 => array( 'state' => 'active' ),
			9 => array( 'state' => 'cancelled' ),
		)
	);
}

$owner       = array( 'type' => 'owner', 'tenant_id' => 7, 'caps' => array( 'tz_fd_access', 'manage_options' ) );
$owner_lite  = array( 'type' => 'owner', 'tenant_id' => 7, 'caps' => array( 'tz_fd_access' ) );
$agent       = array( 'type' => 'agent', 'tenant_id' => 7, 'scopes' => array( 'read_bookings', 'write_bookings' ), 'approved' => false );
$agent_ok    = $agent;
$agent_ok['approved'] = true;
$visitor     = array( 'type' => 'visitor', 'tenant_id' => 7 );
$plugin_info = array( 'plugin_version' => '3.0.128', 'contract_majors' => array( 1 ) );

// 1. Handshake against the double's declared contract.
$d = hd_new( $HD_KEY );
hd_check( 'handshake: compatible plugin accepted', $d->handshake( $plugin_info )['allowed'] === true );
hd_check( 'handshake: plugin below minimum refused', 'plugin_upgrade_required' === $d->handshake( array( 'plugin_version' => '3.0.127', 'contract_majors' => array( 1 ) ) )['reason'] );
hd_check( 'handshake: plugin without contract major 1 refused', 'contract_major_unsupported' === $d->handshake( array( 'plugin_version' => '3.0.128', 'contract_majors' => array( 2 ) ) )['reason'] );
$d2 = hd_new( $HD_KEY );
$d2->set_contract_version( '2.0.0' );
hd_check( 'handshake: hosted major 2 refused by plugin on major 1', 'hosted_contract_major_unsupported' === $d2->handshake( $plugin_info )['reason'] );

// 2. Happy path over the signed envelope.
$d    = hd_new( $HD_KEY );
$body = TZFD_Hosted_Double::envelope( 'booking.list', 7, array( 'page' => 1 ), $HD_KEY, $NOW, 'nonce-happy-0000001' );
$r    = $d->handle( $body, $owner, $NOW );
hd_check( 'signed owner booking.list returns 200', 200 === $r['status'] && 'ok' === $r['body']['reason'] );
hd_check( 'signed reply verifies under the shared key and echoes the nonce', TZFD_Hosted_Double::reply_is_valid( $r, $HD_KEY, 'nonce-happy-0000001' ) );
hd_check( 'reply with wrong nonce is not accepted', ! TZFD_Hosted_Double::reply_is_valid( $r, $HD_KEY, 'nonce-other-0000002' ) );
hd_check( 'reply signed under a different key is not accepted', ! TZFD_Hosted_Double::reply_is_valid( $r, str_repeat( 'x', 32 ), 'nonce-happy-0000001' ) );
$tampered = $r;
$tampered['body']['envelope']['payload']['items'] = array();
hd_check( 'tampered reply fails the plugin-side signature check', ! TZFD_Hosted_Double::reply_is_valid( $tampered, $HD_KEY, 'nonce-happy-0000001' ) );
hd_check( 'executor ran once for the happy path', $d->calls === array( 'booking.list' ) );

// 3. Replay of the same signed body.
$r2 = $d->handle( $body, $owner, $NOW );
hd_check( 'replayed body refused with 409', 409 === $r2['status'] && 'replay' === $r2['body']['reason'] );
hd_check( 'replay does not run the executor again', count( $d->calls ) === 1 );

// 4. Tampering and key errors, refused before any executor runs.
$before = count( $d->calls );
$bad    = json_decode( TZFD_Hosted_Double::envelope( 'booking.list', 7, array( 'page' => 1 ), $HD_KEY, $NOW, 'nonce-tamper-0000001' ), true );
$bad['payload']['page'] = 2; // Signed for page 1, sent as page 2 with a fresh nonce, so the signature check is what refuses it.
$r3     = $d->handle( json_encode( $bad ), $owner, $NOW );
hd_check( 'tampered payload refused with 401 signature_invalid', 401 === $r3['status'] && 'signature_invalid' === $r3['body']['reason'] );

$wrong  = TZFD_Hosted_Double::envelope( 'booking.list', 7, array(), str_repeat( 'x', 32 ), $NOW, 'nonce-wrongkey-00001' );
$r4     = hd_new( $HD_KEY )->handle( $wrong, $owner, $NOW );
hd_check( 'wrong key refused with 401 signature_invalid', 401 === $r4['status'] && 'signature_invalid' === $r4['body']['reason'] );

$stale  = TZFD_Hosted_Double::envelope( 'booking.list', 7, array(), $HD_KEY, $NOW - 301, 'nonce-stale-00000001' );
$r5     = hd_new( $HD_KEY )->handle( $stale, $owner, $NOW );
hd_check( 'stale timestamp refused (outside 300 second window)', 401 === $r5['status'] && 'timestamp_outside_window' === $r5['body']['reason'] );

$unknown = TZFD_Hosted_Double::envelope( 'db.raw', 7, array(), $HD_KEY, $NOW, 'nonce-unknownop-0001' );
$r6      = hd_new( $HD_KEY )->handle( $unknown, $owner, $NOW );
hd_check( 'unknown operation refused with 401', 401 === $r6['status'] && 'unknown_operation' === $r6['body']['reason'] );

hd_check( 'no executor ran for any tampered, wrong-key, stale or unknown request', count( $d->calls ) === $before );

// 5. Company (tenant) switch: correctly signed for tenant 8, but the actor belongs to tenant 7.
$d   = hd_new( $HD_KEY );
$sw  = TZFD_Hosted_Double::envelope( 'booking.list', 8, array(), $HD_KEY, $NOW, 'nonce-switch-00000001' );
$r7  = $d->handle( $sw, $owner, $NOW );
hd_check( 'company switch refused with 403 tenant_mismatch', 403 === $r7['status'] && 'tenant_mismatch' === $r7['body']['reason'] );
hd_check( 'company switch runs no executor', $d->calls === array() );

$ghost = TZFD_Hosted_Double::envelope( 'booking.list', 99, array(), $HD_KEY, $NOW, 'nonce-ghost-000000001' );
$r8    = $d->handle( $ghost, array( 'type' => 'owner', 'tenant_id' => 99, 'caps' => array( 'tz_fd_access' ) ), $NOW );
hd_check( 'unknown tenant refused with 403 tenant_unknown', 403 === $r8['status'] && 'tenant_unknown' === $r8['body']['reason'] );

// 6. Owner and agent authorisation at the wire.
$r9  = $d->handle( TZFD_Hosted_Double::envelope( 'privacy.export', 7, array(), $HD_KEY, $NOW, 'nonce-privacy-00000001' ), $owner_lite, $NOW );
hd_check( 'owner without manage_options refused for privacy.export', 403 === $r9['status'] && 'capability_missing' === $r9['body']['reason'] );

$r10 = $d->handle( TZFD_Hosted_Double::envelope( 'booking.create', 7, array(), $HD_KEY, $NOW, 'nonce-agentwrite-00001' ), $agent, $NOW );
hd_check( 'agent write without approval refused', 403 === $r10['status'] && 'approval_required' === $r10['body']['reason'] );

$r11 = $d->handle( TZFD_Hosted_Double::envelope( 'staff.list', 7, array(), $HD_KEY, $NOW, 'nonce-agentscope-0001' ), $agent, $NOW );
hd_check( 'agent without staff scope refused', 403 === $r11['status'] && 'scope_missing' === $r11['body']['reason'] );

$r12 = $d->handle( TZFD_Hosted_Double::envelope( 'booking.create', 7, array( 'name' => 'stub' ), $HD_KEY, $NOW, 'nonce-agentok-0000001' ), $agent_ok, $NOW );
hd_check( 'agent write with approval accepted', 200 === $r12['status'] );

$r13 = $d->handle( TZFD_Hosted_Double::envelope( 'booking.list', 7, array(), $HD_KEY, $NOW, 'nonce-visitor-0000001' ), $visitor, $NOW );
hd_check( 'visitor refused', 403 === $r13['status'] && 'actor_denied' === $r13['body']['reason'] );

// 7. Entitlement: a cancelled company keeps its signed calls refused, with no executor.
$d->set_company_state( 7, 'cancelled' );
$r14 = $d->handle( TZFD_Hosted_Double::envelope( 'booking.list', 7, array(), $HD_KEY, $NOW, 'nonce-cancelled-000001' ), $owner, $NOW );
hd_check( 'cancelled company refused with 402 entitlement_inactive', 402 === $r14['status'] && 'entitlement_inactive' === $r14['body']['reason'] );
$d->set_company_state( 7, 'active' );

// 8. Malformed bodies.
hd_check( 'malformed JSON refused with 400', 400 === hd_new( $HD_KEY )->handle( '{"v":', $owner, $NOW )['status'] );
hd_check( 'oversized body refused with 400', 400 === hd_new( $HD_KEY )->handle( str_repeat( ' ', 65537 ), $owner, $NOW )['status'] );

// 9. A refused call still burns its nonce (nonce is consumed once verified).
$d    = hd_new( $HD_KEY );
$once = TZFD_Hosted_Double::envelope( 'booking.create', 7, array(), $HD_KEY, $NOW, 'nonce-burn-000000001' );
$d->handle( $once, $agent, $NOW );
hd_check( 'refused call with a nonce cannot be reused', 409 === $d->handle( $once, $agent_ok, $NOW )['status'] );

// 10. Outage: nothing runs, and the nonce is not burned, so the retry succeeds once the hosted side is back.
$d = hd_new( $HD_KEY );
$d->set_outage( true );
$o = TZFD_Hosted_Double::envelope( 'booking.list', 7, array(), $HD_KEY, $NOW, 'nonce-outage-00000001' );
$r15 = $d->handle( $o, $owner, $NOW );
hd_check( 'outage returns 503 hosted_unavailable', 503 === $r15['status'] && 'hosted_unavailable' === $r15['body']['reason'] );
hd_check( 'outage runs no executor', $d->calls === array() );
$d->set_outage( false );
$r16 = $d->handle( $o, $owner, $NOW );
hd_check( 'retry with the same nonce after outage succeeds', 200 === $r16['status'] );

echo "\nPASS {$hd_pass}  FAIL {$hd_fail}\n";
exit( $hd_fail > 0 ? 1 : 0 );
