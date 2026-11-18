<?php
/**
 * CLI contract tests for TZFD_Service_Contract. Run: php tests/contract/service-contract-tests.php
 * Not shipped in the plugin package (lives outside the plugin folder).
 */
define( 'TZFD_CONTRACT_TEST', 1 );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-service-contract.php';

$C       = 'TZFD_Service_Contract';
$key     = str_repeat( 'k', 32 );
$now     = 1800000000;
$pass    = 0;
$fail    = 0;
$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/contract-v1.json' ), true );

function check( $name, $cond ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; return; }
    $fail++;
    echo "FAIL: {$name}\n";
}

$owner = array( 'type' => 'owner', 'tenant_id' => 7, 'caps' => array( 'tz_fd_access', 'manage_options' ) );
$owner_limited = array( 'type' => 'owner', 'tenant_id' => 7, 'caps' => array( 'tz_fd_access' ) );
$agent = array( 'type' => 'agent', 'tenant_id' => 7, 'scopes' => array( 'read_bookings', 'write_bookings', 'privacy_export' ), 'approved' => false );
$agent_ok = $agent; $agent_ok['approved'] = true;

// Registry is frozen to the v1 fixture; any change must bump the contract major.
check( 'registry matches v1 fixture', $C::operations() === $fixture['operations'] );
foreach ( $C::operations() as $name => $def ) {
    check( "op {$name} has valid kind", in_array( $def[0], array( 'read', 'write' ), true ) );
    check( "op {$name} has a scope", is_string( $def[2] ) && '' !== $def[2] );
}

// Authorisation: owner.
check( 'owner reads bookings', $C::authorize( 'booking.list', $owner, 7 )['allowed'] );
check( 'owner writes bookings', $C::authorize( 'booking.status.set', $owner, 7 )['allowed'] );
check( 'owner without admin cap denied settings', ! $C::authorize( 'settings.get', $owner_limited, 7 )['allowed'] );
check( 'owner without admin cap denied privacy export', ! $C::authorize( 'privacy.export', $owner_limited, 7 )['allowed'] );
check( 'owner denied other tenant', ! $C::authorize( 'booking.list', $owner, 8 )['allowed'] );
check( 'tenant 0 denied', ! $C::authorize( 'booking.list', $owner, 0 )['allowed'] );
check( 'tenant string denied', ! $C::authorize( 'booking.list', $owner, '7' )['allowed'] );
check( 'unknown op denied', ! $C::authorize( 'booking.delete_all', $owner, 7 )['allowed'] );
check( 'null op denied', ! $C::authorize( null, $owner, 7 )['allowed'] );
check( 'visitor denied read', ! $C::authorize( 'service.catalogue.list', array( 'type' => 'visitor', 'tenant_id' => 7 ), 7 )['allowed'] );
check( 'unknown actor type denied', ! $C::authorize( 'booking.list', array( 'type' => 'root', 'tenant_id' => 7, 'caps' => array( 'tz_fd_access' ) ), 7 )['allowed'] );
check( 'owner with string caps array ignored', ! $C::authorize( 'booking.list', array( 'type' => 'owner', 'tenant_id' => 7, 'caps' => 'tz_fd_access' ), 7 )['allowed'] );

// Authorisation: agent.
check( 'agent scoped read allowed', $C::authorize( 'booking.list', $agent, 7 )['allowed'] );
check( 'agent unscoped op denied', ! $C::authorize( 'staff.list', $agent, 7 )['allowed'] );
check( 'agent write without approval denied', ! $C::authorize( 'booking.create', $agent, 7 )['allowed'] );
check( 'agent write with approval allowed', $C::authorize( 'booking.create', $agent_ok, 7 )['allowed'] );
check( 'agent privacy export without approval denied', ! $C::authorize( 'privacy.export', $agent, 7 )['allowed'] );
check( 'agent other tenant denied', ! $C::authorize( 'booking.list', array_merge( $agent, array( 'tenant_id' => 9 ) ), 7 )['allowed'] );
check( 'agent with no scopes denied', ! $C::authorize( 'booking.list', array( 'type' => 'agent', 'tenant_id' => 7 ), 7 )['allowed'] );

// Handshake.
$hosted = array( 'contract_version' => '1.2.0', 'min_plugin_version' => '3.0.128' );
check( 'handshake compatible', $C::handshake( array( 'plugin_version' => '3.0.128', 'contract_majors' => array( 1 ) ), $hosted )['allowed'] );
check( 'handshake newer plugin ok', $C::handshake( array( 'plugin_version' => '3.1.0', 'contract_majors' => array( 1 ) ), $hosted )['allowed'] );
check( 'handshake plugin too old', 'plugin_upgrade_required' === $C::handshake( array( 'plugin_version' => '3.0.127', 'contract_majors' => array( 1 ) ), $hosted )['reason'] );
check( 'handshake major unsupported by plugin', 'contract_major_unsupported' === $C::handshake( array( 'plugin_version' => '3.0.128', 'contract_majors' => array( 2 ) ), $hosted )['reason'] );
check( 'handshake hosted major mismatch', 'hosted_contract_major_unsupported' === $C::handshake( array( 'plugin_version' => '3.0.128', 'contract_majors' => array( 1 ) ), array( 'contract_version' => '2.0.0', 'min_plugin_version' => '3.0.128' ) )['reason'] );
check( 'handshake rejects malformed plugin version', 'plugin_version_invalid' === $C::handshake( array( 'plugin_version' => '3.0', 'contract_majors' => array( 1 ) ), $hosted )['reason'] );
check( 'handshake rejects leading zero', 'plugin_version_invalid' === $C::handshake( array( 'plugin_version' => '03.0.1', 'contract_majors' => array( 1 ) ), $hosted )['reason'] );
check( 'handshake rejects array version', 'plugin_version_invalid' === $C::handshake( array( 'plugin_version' => array( '3.0.1' ), 'contract_majors' => array( 1 ) ), $hosted )['reason'] );
check( 'handshake rejects hosted min malformed', 'hosted_version_invalid' === $C::handshake( array( 'plugin_version' => '3.0.1', 'contract_majors' => array( 1 ) ), array( 'contract_version' => '1.0.0', 'min_plugin_version' => 'latest' ) )['reason'] );
check( 'handshake rejects missing fields', ! $C::handshake( array(), array() )['allowed'] );
check( 'handshake rejects major passed as string', ! $C::handshake( array( 'plugin_version' => '3.0.128', 'contract_majors' => array( '1' ) ), $hosted )['allowed'] );

// Signed envelope.
$base = array( 'v' => '1.0.0', 'op' => 'booking.list', 'tenant_id' => 7, 'ts' => $now, 'nonce' => 'abcdefghijklmnop1', 'payload' => array( 'page' => 1 ) );
$env  = $base; $env['sig'] = $C::sign( $base, $key );

check( 'signature vector matches fixture', $C::sign( $fixture['signature_vector']['envelope'], $fixture['signature_vector']['key'] ) === $fixture['signature_vector']['sig'] );
check( 'canonical key order independent', $C::canonical( array( 'b' => 1, 'a' => 2 ) ) === $C::canonical( array( 'a' => 2, 'b' => 1 ) ) );
check( 'valid envelope verifies', $C::verify( $env, $key, $now, array() )['ok'] );
check( 'nonce returned on success', 'abcdefghijklmnop1' === $C::verify( $env, $key, $now, array() )['nonce'] );
check( 'wrong key rejected', 'signature_invalid' === $C::verify( $env, str_repeat( 'x', 32 ), $now, array() )['reason'] );
check( 'short key rejected', 'key_invalid' === $C::verify( $env, 'short', $now, array() )['reason'] );
check( 'replayed nonce rejected', 'replay' === $C::verify( $env, $key, $now, array( 'abcdefghijklmnop1' => true ) )['reason'] );
check( 'tampered tenant rejected', 'signature_invalid' === $C::verify( array_merge( $env, array( 'tenant_id' => 8 ) ), $key, $now, array() )['reason'] );
check( 'tampered op rejected', 'signature_invalid' === $C::verify( array_merge( $env, array( 'op' => 'privacy.export' ) ), $key, $now, array() )['reason'] );
check( 'tampered payload rejected', 'signature_invalid' === $C::verify( array_merge( $env, array( 'payload' => array( 'page' => 2 ) ) ), $key, $now, array() )['reason'] );
check( 'stale timestamp rejected', 'timestamp_outside_window' === $C::verify( $env, $key, $now + 301, array() )['reason'] );
check( 'future timestamp rejected', 'timestamp_outside_window' === $C::verify( $env, $key, $now - 301, array() )['reason'] );
check( 'skew edge accepted', $C::verify( $env, $key, $now + 300, array() )['ok'] );
check( 'unknown op rejected', 'unknown_operation' === $C::verify( array_merge( $env, array( 'op' => 'db.raw' ) ), $key, $now, array() )['reason'] );
check( 'major 2 version rejected', 'contract_version_unsupported' === $C::verify( array_merge( $env, array( 'v' => '2.0.0' ) ), $key, $now, array() )['reason'] );
check( 'float tenant rejected', 'tenant_invalid' === $C::verify( array_merge( $env, array( 'tenant_id' => 7.0 ) ), $key, $now, array() )['reason'] );
check( 'short nonce rejected', 'nonce_invalid' === $C::verify( array_merge( $env, array( 'nonce' => 'short' ) ), $key, $now, array() )['reason'] );
check( 'nonce with spaces rejected', 'nonce_invalid' === $C::verify( array_merge( $env, array( 'nonce' => 'abcdefghij klmnop' ) ), $key, $now, array() )['reason'] );
check( 'missing sig field rejected', 'field_missing' === $C::verify( array_diff_key( $env, array( 'sig' => 1 ) ), $key, $now, array() )['reason'] );
check( 'non-string sig rejected', 'signature_invalid' === $C::verify( array_merge( $env, array( 'sig' => 123 ) ), $key, $now, array() )['reason'] );

// Malicious payloads (signed correctly so only the payload rule can refuse them).
$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => array( 'g' => 1 ) ) ) ) ) ) );
$bad_payloads = array(
    'deep nesting' => $deep,
    'oversized string' => array( 'q' => str_repeat( 'a', 10001 ) ),
    'object payload' => 'not-an-array',
);
foreach ( $bad_payloads as $label => $payload ) {
    $e = $base; $e['payload'] = $payload; $e['sig'] = $C::sign( $e, $key );
    $r = $C::verify( $e, $key, $now, array() );
    check( "payload rejected: {$label}", ! $r['ok'] && 'payload_invalid' === $r['reason'] );
}
$e = $base; $e['payload'] = array( 'x' => new stdClass() ); $e['sig'] = 'x';
check( 'non-scalar payload rejected', ! $C::verify( $e, $key, $now, array() )['ok'] );

// Envelope parsing: size, JSON, depth.
check( 'empty body rejected', 'body_size_invalid' === $C::parse( '' )['reason'] );
check( 'oversized body rejected', 'body_size_invalid' === $C::parse( str_repeat( ' ', 65537 ) )['reason'] );
check( 'malformed json rejected', 'json_invalid' === $C::parse( '{"v":' )['reason'] );
check( 'json scalar rejected', 'json_invalid' === $C::parse( '"abc"' )['reason'] );
check( 'nested json bomb rejected', 'json_invalid' === $C::parse( str_repeat( '[', 40 ) . str_repeat( ']', 40 ) )['reason'] );
check( 'valid json parsed', $C::parse( json_encode( $env ) )['ok'] );

// Negative test for a non-array envelope in verify().
check( 'envelope missing all fields rejected', 'field_missing' === $C::verify( array(), $key, $now, array() )['reason'] );

echo "{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
