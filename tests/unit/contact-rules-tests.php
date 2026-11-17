<?php
/**
 * Unit tests for TZFD_Contact_Rules (issues #30 and #31). Pure PHP, no WordPress needed.
 * Run: php tests/unit/contact-rules-tests.php   (from the repository root)
 * The database and REST paths are not covered here. They need a WordPress test site (see tests/runtime).
 */
define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-contact-rules.php';

$GLOBALS['tz_pass'] = 0;
$GLOBALS['tz_fail'] = 0;
function tz_check( $name, $ok ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo esc_html( ( $ok ? 'PASS ' : 'FAIL ' ) . $name ) . "\n";
}
$R = 'TZFD_Contact_Rules';

// --- Phone normalisation (format, country, caller privacy) ---
tz_check( 'E.164 with plus kept', $R::normalise_e164( '+61 400 000 000' ) === '+61400000000' );
tz_check( 'international 00 prefix converted', $R::normalise_e164( '0061400000000' ) === '+61400000000' );
tz_check( 'AU national 0-prefix uses default country', $R::normalise_e164( '0400 000 000' ) === '+61400000000' );
tz_check( 'punctuation stripped', $R::normalise_e164( '(02) 9999-8888' ) === '+61299998888' );
tz_check( 'anonymous caller refused', $R::normalise_e164( 'anonymous' ) === '' );
tz_check( 'restricted caller refused', $R::normalise_e164( 'Restricted' ) === '' );
tz_check( 'empty refused', $R::normalise_e164( '' ) === '' );
tz_check( 'digits with no country code refused, not guessed', $R::normalise_e164( '400000000' ) === '' );
tz_check( 'too short refused', $R::normalise_e164( '+61 12' ) === '' );
tz_check( 'too long refused', $R::normalise_e164( '+61 4000 0000 0000 00' ) === '' );
tz_check( 'non-string refused', $R::normalise_e164( 61400000000 ) === '' );
tz_check( 'injection text refused', $R::normalise_e164( '+61400000000; DROP' ) === '' );
tz_check( 'plus followed by zero refused', $R::normalise_e164( '+0123456789' ) === '' );
tz_check( 'default country override honoured', $R::normalise_e164( '0412345678', '64' ) === '+64412345678' );

// --- Contact matching: a phone match is a candidate, not authentication ---
$co = 7;
$rows = array(
    array( 'company_id' => 7, 'customer_id' => 11, 'phone' => '0400 000 000' ),
);
$m = $R::match_contacts( $co, '+61400000000', $rows );
tz_check( 'single match is a candidate', 'candidate' === $m['status'] && 11 === $m['customer_id'] );
tz_check( 'no number means no match', 'no_number' === $R::match_contacts( $co, '', $rows )['status'] );
tz_check( 'unknown number unmatched', 'unmatched' === $R::match_contacts( $co, '+61999999999', $rows )['status'] );
$two = array(
    array( 'company_id' => 7, 'customer_id' => 11, 'phone' => '+61400000000' ),
    array( 'company_id' => 7, 'customer_id' => 12, 'phone' => '0400000000' ),
);
$m = $R::match_contacts( $co, '+61400000000', $two );
tz_check( 'two customers on one number is ambiguous, no id returned', 'ambiguous' === $m['status'] && null === $m['customer_id'] );
$two_same = array(
    array( 'company_id' => 7, 'customer_id' => 11, 'phone' => '+61400000000' ),
    array( 'company_id' => 7, 'customer_id' => 11, 'phone' => '0400000000' ),
);
tz_check( 'same customer listed twice is still one candidate', 'candidate' === $R::match_contacts( $co, '+61400000000', $two_same )['status'] );
$foreign = array( array( 'company_id' => 99, 'customer_id' => 5, 'phone' => '+61400000000' ) );
tz_check( 'wrong-tenant row never matches (tenant denial)', 'unmatched' === $R::match_contacts( $co, '+61400000000', $foreign )['status'] );
$bad = array( array( 'company_id' => 7, 'customer_id' => '11', 'phone' => '+61400000000' ), 'junk', null );
tz_check( 'malformed rows ignored, not fatal', 'unmatched' === $R::match_contacts( $co, '+61400000000', $bad )['status'] );

// --- Idempotency keys ---
$k1 = $R::dedupe_key( 7, 'missed_call', 'twilio|CA1' );
tz_check( 'same event yields same key', $k1 === $R::dedupe_key( 7, 'missed_call', 'twilio|CA1' ) );
tz_check( 'key differs per company (no cross-tenant collision)', $k1 !== $R::dedupe_key( 8, 'missed_call', 'twilio|CA1' ) );
tz_check( 'key differs per scope', $k1 !== $R::dedupe_key( 7, 'sms_reply', 'twilio|CA1' ) );
tz_check( 'key is 64 hex characters', 1 === preg_match( '/^[a-f0-9]{64}$/', $k1 ) );
tz_check( 'identity rejects bad provider slug', ! $R::valid_identity( 7, 'Bad Provider', 'e1234567' ) );
tz_check( 'identity rejects zero company', ! $R::valid_identity( 0, 'twilio', 'e1234567' ) );
tz_check( 'identity accepts good values', $R::valid_identity( 7, 'twilio', 'e1234567' ) );

// --- Reply classification ---
tz_check( 'book keyword', $R::classify_reply( 'I want to book' ) === 'book' );
tz_check( 'quote keyword', $R::classify_reply( 'Can I get a quote?' ) === 'quote' );
tz_check( 'call me keyword', $R::classify_reply( 'please call me back' ) === 'call_me' );
tz_check( 'cancel keyword', $R::classify_reply( 'cancel my Tuesday job' ) === 'cancel' );
tz_check( 'STOP is opt-out', $R::classify_reply( ' stop. ' ) === 'stop' );
tz_check( 'bare CANCEL is a carrier opt-out', $R::classify_reply( 'CANCEL' ) === 'stop' );
tz_check( 'START is opt-in', $R::classify_reply( 'START' ) === 'start' );
tz_check( 'YES is not opt-in', $R::classify_reply( 'YES' ) === 'unknown' );
tz_check( 'two intents are ambiguous', $R::classify_reply( 'book and cancel' ) === 'ambiguous' );
tz_check( 'unrelated text is unknown', $R::classify_reply( 'thanks for the great service' ) === 'unknown' );
tz_check( 'oversize reply is unknown, not processed', $R::classify_reply( str_repeat( 'book ', 100 ) ) === 'unknown' );
tz_check( 'invalid UTF-8 is unknown', $R::classify_reply( "book \xC3\x28" ) === 'unknown' );
tz_check( 'non-string is unknown', $R::classify_reply( null ) === 'unknown' );

// --- Decisions ---
$ok = array( 'opted_out' => false, 'consent_basis' => 'customer_initiated', 'turns_used' => 0, 'fallback_intent' => null );
$d = $R::decide_reply( 'book', $ok );
tz_check( 'book replies with the booking link template', 'respond' === $d['action'] && 'book_link' === $d['template'] && true === $d['uses_turn'] );

$d = $R::decide_reply( 'cancel', $ok );
tz_check( 'cancel never cancels from one text: it asks for the two-step code confirmation', 'respond' === $d['action'] && null === $d['task'] && 'cancel_code_sent' === $d['template'] && true === $d['uses_turn'] );
tz_check( 'no decision ever names a cancellation action', ! in_array( $d['action'], array( 'cancel', 'cancel_booking', 'cancelled' ), true ) );

$d = $R::decide_reply( 'stop', $ok );
tz_check( 'STOP opts out and sends nothing', 'opt_out' === $d['action'] && null === $d['template'] && false === $d['uses_turn'] );

$d = $R::decide_reply( 'book', array_merge( $ok, array( 'opted_out' => true ) ) );
tz_check( 'opted-out caller gets no reply and no task', 'refuse' === $d['action'] && null === $d['template'] && null === $d['task'] );

$d = $R::decide_reply( 'book', array_merge( $ok, array( 'consent_basis' => '' ) ) );
tz_check( 'missing consent basis gets no reply', 'refuse' === $d['action'] );

$d = $R::decide_reply( 'quote', array_merge( $ok, array( 'consent_basis' => 'marketing_list' ) ) );
tz_check( 'a consent basis other than customer_initiated gets no reply', 'refuse' === $d['action'] );

$d = $R::decide_reply( 'unknown', $ok );
tz_check( 'unknown text goes to staff review, uses no turn', 'staff_review' === $d['action'] && 'staff_review' === $d['task'] && false === $d['uses_turn'] );

$d = $R::decide_reply( 'ambiguous', array_merge( $ok, array( 'fallback_intent' => 'quote' ) ) );
tz_check( 'ambiguous with fallback uses the fallback intent and one turn', 'respond' === $d['action'] && 'quote' === $d['intent'] && true === $d['uses_turn'] );

$d = $R::decide_reply( 'ambiguous', array_merge( $ok, array( 'fallback_intent' => 'cancel_booking' ) ) );
tz_check( 'fallback returning a non-allowed intent is refused', 'staff_review' === $d['action'] );

$d = $R::decide_reply( 'ambiguous', array_merge( $ok, array( 'fallback_intent' => 'book', 'turns_used' => 3 ) ) );
tz_check( 'fallback cannot exceed the turn cap', 'staff_review' === $d['action'] );

// Turn cap: three automated turns, then only staff tasks.
$turns = 0; $sent = 0;
for ( $i = 0; $i < 5; $i++ ) {
    $d = $R::decide_reply( 'book', array_merge( $ok, array( 'turns_used' => $turns ) ) );
    if ( 'respond' === $d['action'] ) { $sent++; $turns++; }
}
tz_check( 'three automated turns per conversation, then the cap holds', 3 === $sent && 3 === $turns );

$d = $R::decide_reply( 'cancel', array_merge( $ok, array( 'turns_used' => 3 ) ) );
tz_check( 'after the cap a cancel request still becomes a staff task, with no reply', 'turn_limit' === $d['action'] && 'staff_review' === $d['task'] && false === $d['uses_turn'] );

$d = $R::decide_reply( 'book', array_merge( $ok, array( 'turns_used' => 3 ) ) );
tz_check( 'after the cap a booking request gets a staff task and no reply', 'turn_limit' === $d['action'] && 'staff_review' === $d['task'] );

// Template text: fixed, carries only the link, and always has the opt-out line.
$t = $R::template_text( 'book_link', 'https://example.test/book' );
tz_check( 'template includes the link and opt-out line', false !== strpos( $t, 'https://example.test/book' ) && false !== strpos( $t, 'Reply STOP to opt out' ) );
tz_check( 'unknown template key gives empty text', '' === $R::template_text( 'nope', 'x' ) );
tz_check( 'every template keeps the opt-out line', 10 === count( array_filter( array_map( function ( $k ) use ( $R ) {
    return false !== strpos( $R::template_text( $k, 'x' ), 'Reply STOP to opt out' ) ? 1 : 0;
}, array( 'book_link', 'cancel_received', 'cancel_code_sent', 'cancel_need_code', 'cancel_need_yes', 'cancel_wrong_code', 'cancel_locked', 'cancel_done', 'quote_received', 'callback_received' ) ) ) ) );

echo esc_html( "\nSummary: {$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n" );
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
