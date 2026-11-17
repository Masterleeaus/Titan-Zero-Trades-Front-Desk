<?php
// Standalone checks for (1) two-step SMS cancellation and (2) 14-day reschedule links.
// Run: php tests/sms-cancel-reschedule-checks.php. No WordPress needed; the database paths are not exercised here.
define( 'ABSPATH', __DIR__ . '/' );

function __( $s, $d = '' ) { return $s; }
function wp_salt( $scheme = 'auth' ) { return 'test-auth-salt-0123456789abcdef0123'; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { return true; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return true; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }

require_once __DIR__ . '/../titan-zero-trades-front-desk/includes/class-tzfd-customer-rescheduling.php';
require_once __DIR__ . '/../titan-zero-trades-front-desk/includes/class-tzfd-sms-cancel-confirm.php';
require_once __DIR__ . '/../titan-zero-trades-front-desk/includes/class-tzfd-contact-rules.php';

$fail = 0;
function check( $name, $ok ) {
	global $fail;
	fwrite( STDOUT, ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL );
	if ( ! $ok ) { $fail++; }
}

$secret  = 'shh-test-secret-0123456789abcdef0123';
$version = str_repeat( 'a1', 32 );
$email   = 'jo@example.test';
$t0      = 1700000000;
$ttl     = 1209600;

// ---------- Part 2: reschedule links ----------
fwrite( STDOUT, '-- reschedule links' . PHP_EOL );
$link = TZFD_Customer_Rescheduling::issue( 42, 7, $email, $version, $secret, $t0 );
check( 'R1 issue returns token, issue time and expiry', is_array( $link ) && 64 === strlen( $link['token'] ) );
check( 'R2 expiry is exactly 14 days after issue', is_array( $link ) && $link['expires'] === $t0 + $ttl && $ttl === 14 * 86400 );
check( 'R3 token verifies at issue time', TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], $version, $secret, $link['issued'], $link['expires'], $t0 ) );
check( 'R4 token verifies just before expiry', TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], $version, $secret, $t0, $t0 + $ttl, $t0 + $ttl - 1 ) );
check( 'R5 token is refused one second after expiry', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], $version, $secret, $t0, $t0 + $ttl, $t0 + $ttl + 1 ) );
check( 'R6 token issued far in the future is refused', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], $version, $secret, $t0 + 3600, $t0 + 3600 + $ttl, $t0 ) );
check( 'R7 token without an issue time is refused', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], $version, $secret, 0, $t0 + $ttl, $t0 ) );
check( 'R8 token without an expiry is refused', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], $version, $secret, $t0, 0, $t0 ) );
check( 'R9 token with a stretched expiry is never produced', '' === TZFD_Customer_Rescheduling::token( 42, 7, $email, $version, $secret, $t0, $t0 + $ttl + 60 ) );
check( 'R10 old-format token (no issue time) is never produced', '' === TZFD_Customer_Rescheduling::token( 42, 7, $email, $version, $secret ) );
check( 'R11 token is refused for another booking', ! TZFD_Customer_Rescheduling::verify_token( 43, 7, $email, $link['token'], $version, $secret, $t0, $t0 + $ttl, $t0 ) );
check( 'R12 token is refused for another form', ! TZFD_Customer_Rescheduling::verify_token( 42, 8, $email, $link['token'], $version, $secret, $t0, $t0 + $ttl, $t0 ) );
check( 'R13 token is refused for another e-mail', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, 'other@example.test', $link['token'], $version, $secret, $t0, $t0 + $ttl, $t0 ) );
check( 'R14 token is refused after the booking version changes', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], str_repeat( 'c3', 32 ), $secret, $t0, $t0 + $ttl, $t0 ) );
check( 'R15 token is refused with the wrong secret', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $link['token'], $version, 'another-secret-0123456789abcdef0123', $t0, $t0 + $ttl, $t0 ) );
$flipped = substr_replace( $link['token'], '0' === $link['token'][0] ? '1' : '0', 0, 1 );
check( 'R16 a flipped character is refused', ! TZFD_Customer_Rescheduling::verify_token( 42, 7, $email, $flipped, $version, $secret, $t0, $t0 + $ttl, $t0 ) );
check( 'R17 an empty secret issues no link', null === TZFD_Customer_Rescheduling::issue( 42, 7, $email, $version, '', $t0 ) );
check( 'R18 e-mail case does not change the token', $link['token'] === TZFD_Customer_Rescheduling::token( 42, 7, strtoupper( $email ), $version, $secret, $t0, $t0 + $ttl ) );
check( 'R19 cancellation links keep their 14-day expiry', 1209600 === TZFD_Customer_Cancellation::TOKEN_TTL );

// ---------- Part 1: SMS two-step cancellation ----------
fwrite( STDOUT, '-- SMS cancel confirmation' . PHP_EOL );
check( 'S1 code is valid for 15 minutes', 900 === TZFD_SMS_Cancel_Confirm::CODE_TTL );
check( 'S2 five wrong codes lock', 5 === TZFD_SMS_Cancel_Confirm::MAX_WRONG );
check( 'S3 a bare six-digit reply is a code', '123456' === TZFD_SMS_Cancel_Confirm::extract_code( '123456' ) );
check( 'S4 a code inside a sentence is found', '654321' === TZFD_SMS_Cancel_Confirm::extract_code( 'YES 654321 thanks' ) );
check( 'S5 seven digits are not a code', '' === TZFD_SMS_Cancel_Confirm::extract_code( '1234567' ) );
check( 'S6 five digits are not a code', '' === TZFD_SMS_Cancel_Confirm::extract_code( '12345' ) );
check( 'S7 YES is recognised in any case', TZFD_SMS_Cancel_Confirm::says_yes( 'yes' ) && TZFD_SMS_Cancel_Confirm::says_yes( 'Yes please 123456' ) );
check( 'S8 yesterday is not YES', ! TZFD_SMS_Cancel_Confirm::says_yes( 'yesterday please' ) );

$conv = hash( 'sha256', 'conversation|1|+61412345678' );
$d1   = TZFD_SMS_Cancel_Confirm::digest( '123456', $conv, 42, $secret );
check( 'S9 digest is keyed and stable', 64 === strlen( $d1 ) && $d1 === TZFD_SMS_Cancel_Confirm::digest( '123456', $conv, 42, $secret ) );
check( 'S10 digest is not the code itself', false === strpos( $d1, '123456' ) );
check( 'S11 digest is bound to the conversation', $d1 !== TZFD_SMS_Cancel_Confirm::digest( '123456', hash( 'sha256', 'x' ), 42, $secret ) );
check( 'S12 digest is bound to the booking', $d1 !== TZFD_SMS_Cancel_Confirm::digest( '123456', $conv, 43, $secret ) );
check( 'S13 digest of a non-code is empty', '' === TZFD_SMS_Cancel_Confirm::digest( '12345', $conv, 42, $secret ) && '' === TZFD_SMS_Cancel_Confirm::digest( '123456', $conv, 42, '' ) );

$now = 1700100000;
$ch  = TZFD_SMS_Cancel_Confirm::new_challenge( 1, 42, $version, $d1, $now );
check( 'S14 new challenge expires 15 minutes after issue, with no wrong attempts', $ch['expires'] === $now + 900 && 0 === $ch['wrong'] );
check( 'S15 challenge stores no code', ! in_array( '123456', $ch, true ) );

$r = TZFD_SMS_Cancel_Confirm::evaluate( $ch, '', false, $now + 10 );
check( 'S16 a YES with no code asks for the code, and counts nothing', 'need_code' === $r['step'] && 0 === $r['challenge']['wrong'] );

$r = TZFD_SMS_Cancel_Confirm::evaluate( $ch, $d1, false, $now + 10 );
check( 'S17 the right code without YES asks for YES, and keeps the challenge open', 'need_yes' === $r['step'] && is_array( $r['challenge'] ) );

$r = TZFD_SMS_Cancel_Confirm::evaluate( $ch, $d1, true, $now + 900 );
check( 'S18 the right code and YES at the last second confirms', 'confirmed' === $r['step'] && null === $r['challenge'] );

$r = TZFD_SMS_Cancel_Confirm::evaluate( $ch, $d1, true, $now + 901 );
check( 'S19 the right code and YES one second late is refused as expired', 'expired' === $r['step'] && null === $r['challenge'] );

$wrong = TZFD_SMS_Cancel_Confirm::digest( '000000', $conv, 42, $secret );
$c = $ch;
$steps = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$r = TZFD_SMS_Cancel_Confirm::evaluate( $c, $wrong, true, $now + $i );
	$steps[] = $r['step'];
	$c = $r['challenge'];
}
check( 'S20 four wrong codes stay open, the fifth locks', array( 'wrong', 'wrong', 'wrong', 'wrong', 'locked' ) === $steps && 5 === $c['wrong'] );

$r = TZFD_SMS_Cancel_Confirm::evaluate( $c, $d1, true, $now + 6 );
check( 'S21 a locked challenge refuses even the right code and YES', 'locked' === $r['step'] && is_array( $r['challenge'] ) );

$r = TZFD_SMS_Cancel_Confirm::evaluate( $ch, $wrong, false, $now + 2 );
check( 'S22 a wrong code is counted even when YES is absent', 'wrong' === $r['step'] && 1 === $r['challenge']['wrong'] );

$phone_row = array( 'apps' => array( array( 'id' => 1 ) ), 'phone' => '0412 345 678', 'nested' => array( 'Mobile' => '+61 412-345-678' ) );
check( 'S23 a stored phone matches the caller in any formatting', TZFD_SMS_Cancel_Confirm::data_has_phone( $phone_row, '+61412345678' ) );
check( 'S24 a different number does not match', ! TZFD_SMS_Cancel_Confirm::data_has_phone( $phone_row, '+61400000001' ) );
check( 'S25 an empty caller number matches nothing', ! TZFD_SMS_Cancel_Confirm::data_has_phone( $phone_row, '' ) );

check( 'S26 CANCEL on its own is still a carrier opt-out, not a cancel', 'stop' === TZFD_Contact_Rules::classify_reply( 'CANCEL' ) );
check( 'S27 STOP still opts out and sends nothing', 'opt_out' === TZFD_Contact_Rules::decide_reply( 'stop', array( 'consent_basis' => 'customer_initiated' ) )['action'] );
$d = TZFD_Contact_Rules::decide_reply( 'cancel', array( 'consent_basis' => 'customer_initiated', 'turns_used' => 0 ) );
check( 'S28 a cancel text asks for the code, and never names a cancellation action', 'cancel_code_sent' === $d['template'] && null === $d['task'] && ! in_array( $d['action'], array( 'cancel', 'cancel_booking', 'cancelled' ), true ) );
check( 'S29 a cancel text with no consent is refused', 'refuse' === TZFD_Contact_Rules::decide_reply( 'cancel', array( 'consent_basis' => '' ) )['action'] );
check( 'S30 the code request text carries the opt-out line', false !== strpos( TZFD_Contact_Rules::template_text( 'cancel_code_sent', 'x' ), 'Reply STOP to opt out' ) );

fwrite( STDOUT, ( $fail ? "FAILED: $fail" : 'ALL PASS' ) . PHP_EOL );
exit( $fail ? 1 : 0 );
