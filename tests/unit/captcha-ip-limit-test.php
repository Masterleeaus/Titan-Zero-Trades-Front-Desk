<?php
/**
 * Issue #196: the CAPTCHA per-address failure limit stays in production, and a test-only override exists.
 * Pure PHP, in-memory transients, no WordPress needed: php tests/unit/captcha-ip-limit-test.php
 * Exits 1 if any check fails. Runs in its own process because the override is set with define().
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

$GLOBALS['tzfd_captcha_store'] = array();
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return array_key_exists( $key, $GLOBALS['tzfd_captcha_store'] ) ? $GLOBALS['tzfd_captcha_store'][ $key ] : false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['tzfd_captcha_store'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
}

require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-captcha.php';

$fails  = 0;
$passes = 0;
$check  = function ( $ok, $name ) use ( &$fails, &$passes ) {
	if ( $ok ) { ++$passes; } else { ++$fails; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
};

/** Issue a challenge with a known code and answer it. Returns true when the answer was accepted. */
$answer = function ( $id, $code, $client ) {
	set_transient( 'tzfd-captcha-' . $id, $code, TZFD_Captcha::CHALLENGE_TTL );
	return TZFD_Captcha::check_answer( $id, 'wrong-' . $id, $client );
};

$addr_a = md5( 'client-a' );
$addr_b = md5( 'client-b' );

// 1. Production value with neither constant set.
$check( 20 === TZFD_Captcha::ip_max_failures(), 'production limit is 20 when no test constants are set' );

// 2. Production behaviour: 20 wrong answers from one address across fresh challenges, then even a correct answer is refused.
for ( $i = 0; $i < 20; $i++ ) { $answer( 'p' . $i, 'abcdefg', $addr_a ); }
$check( 20 === (int) get_transient( 'tzfd-captcha-ip-' . $addr_a ), 'twenty wrong answers are counted against the address' );
set_transient( 'tzfd-captcha-p-correct', 'abcdefg', TZFD_Captcha::CHALLENGE_TTL );
$check( false === TZFD_Captcha::check_answer( 'p-correct', 'abcdefg', $addr_a ), 'production: address at 20 failures is refused even with the right code' );

// 3. Override value without the mode switch is ignored.
define( 'TZFD_CAPTCHA_TEST_IP_MAX_FAILURES', 2 );
$check( 20 === TZFD_Captcha::ip_max_failures(), 'override value alone (no test mode) does not change the production limit' );

// 4. Mode switch on: the override applies.
define( 'TZFD_CAPTCHA_TEST_MODE', true );
$check( 2 === TZFD_Captcha::ip_max_failures(), 'test mode plus override sets the limit to 2' );

$answer( 't1', 'abcdefg', $addr_b );
$answer( 't2', 'abcdefg', $addr_b );
set_transient( 'tzfd-captcha-t3', 'abcdefg', TZFD_Captcha::CHALLENGE_TTL );
$check( false === TZFD_Captcha::check_answer( 't3', 'abcdefg', $addr_b ), 'test override: two failures block the address on a correct answer' );

// 5. Other addresses are not blocked by that address's failures.
set_transient( 'tzfd-captcha-t4', 'abcdefg', TZFD_Captcha::CHALLENGE_TTL );
$check( true === TZFD_Captcha::check_answer( 't4', 'abcdefg', md5( 'client-c' ) ), 'a different address still passes a correct answer' );

echo "Summary: $passes passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
