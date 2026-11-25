<?php
/**
 * Builds one signed contract v1 envelope for the HTTP checks in service-contract-http-tests.sh.
 * Usage: php service-contract-sign.php <key> <op> <tenant_id> <nonce> [tamper|badkey]
 * Prints the JSON body. "tamper" changes the payload after signing; "badkey" signs with a different key.
 * Test only: not shipped in the plugin.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-service-contract.php';

list( , $key, $op, $tenant, $nonce ) = array_pad( $argv, 5, '' );
$mode = isset( $argv[5] ) ? $argv[5] : '';
$env  = array(
	'v'         => '1.0.0',
	'op'        => $op,
	'tenant_id' => (int) $tenant,
	'ts'        => time(),
	'nonce'     => $nonce,
	'payload'   => array( 'probe' => 'http-check' ),
);
$sign_key = 'badkey' === $mode ? str_repeat( 'x', 32 ) : $key;
$env['sig'] = TZFD_Service_Contract::sign( $env, $sign_key );
if ( 'tamper' === $mode ) {
	$env['payload'] = array( 'probe' => 'changed-after-signing' );
}
echo json_encode( $env ), "\n";
