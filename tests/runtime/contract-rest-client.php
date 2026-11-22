<?php
/**
 * Test helper for contract-rest-tests.sh. Signs contract v1 envelopes and checks signed replies with the
 * shared contract class, so the HTTP test uses the same signing rules as the plugin. TEST ONLY, not shipped.
 *
 * Usage:
 *   php contract-rest-client.php sign <op> <tenant_id> <payload-json> <key> <ts> <nonce> [<v>]
 *   php contract-rest-client.php verify-reply <reply-json-file> <key> <nonce>   (exit 0 when the reply is valid)
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-service-contract.php';
require_once __DIR__ . '/../contract/hosted-double/class-tzfd-hosted-double.php';

$cmd = isset( $argv[1] ) ? $argv[1] : '';
if ( 'sign' === $cmd && count( $argv ) >= 8 ) {
    $payload = json_decode( $argv[4], true );
    if ( ! is_array( $payload ) ) { fwrite( STDERR, "payload must be a JSON object\n" ); exit( 2 ); }
    $version = isset( $argv[8] ) ? $argv[8] : '1.0.0';
    echo TZFD_Hosted_Double::envelope( $argv[2], (int) $argv[3], $payload, $argv[5], (int) $argv[6], $argv[7], $version ), "\n";
    exit( 0 );
}
if ( 'verify-reply' === $cmd && count( $argv ) >= 5 ) {
    $reply = json_decode( (string) file_get_contents( $argv[2] ), true );
    if ( ! is_array( $reply ) || ! isset( $reply['envelope'] ) || ! is_array( $reply['envelope'] ) ) { exit( 1 ); }
    $env = $reply['envelope'];
    if ( ! isset( $env['sig'], $env['nonce'] ) || $env['nonce'] !== $argv[4] || ! is_string( $env['sig'] ) ) { exit( 1 ); }
    $unsigned = $env;
    unset( $unsigned['sig'] );
    exit( hash_equals( TZFD_Service_Contract::sign( $unsigned, $argv[3] ), $env['sig'] ) ? 0 : 1 );
}
fwrite( STDERR, "usage: see header of contract-rest-client.php\n" );
exit( 2 );
