<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/**
 * Unit checks for TZFD_Human_Handoff (issue #27). Pure PHP, no WordPress needed.
 * Run: php -I /home/claude/wt/issue-27/tests/runtime/handoff-unit-tests.php
 * Requires the class from the plugin folder beside this repo checkout.
 */
$root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
require_once $root . '/includes/class-tzfd-human-handoff.php';

$fail = 0;
$pass = 0;
function hh_check( $name, $ok ) {
    global $fail, $pass;
    if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}

$H = 'TZFD_Human_Handoff';

// Low confidence on an AI-needed turn escalates; high confidence does not.
$d = $H::decide( array( 'needs_ai' => true, 'ai_available' => true, 'confidence' => 0.2, 'text' => 'do you do gutters' ) );
hh_check( 'low confidence escalates', $d['escalate'] === true && $d['reasons'] === array( 'low_confidence' ) );
$d = $H::decide( array( 'needs_ai' => true, 'ai_available' => true, 'confidence' => 0.9, 'text' => 'do you do gutters' ) );
hh_check( 'high confidence does not escalate', $d['escalate'] === false && $d['reasons'] === array() );
$d = $H::decide( array( 'needs_ai' => true, 'ai_available' => true, 'text' => 'hi' ) );
hh_check( 'missing confidence treated as low', $d['escalate'] === true && $d['reasons'] === array( 'low_confidence' ) );

// Disabled proxy and exhausted allowance.
$d = $H::decide( array( 'needs_ai' => true, 'ai_available' => false, 'confidence' => 0.99 ) );
hh_check( 'disabled proxy escalates', $d['reasons'] === array( 'ai_unavailable' ) );
$d = $H::decide( array( 'needs_ai' => true, 'ai_available' => true, 'allowance_left' => 0, 'confidence' => 0.99 ) );
hh_check( 'exhausted allowance escalates', $d['reasons'] === array( 'allowance_exhausted' ) );
$d = $H::decide( array( 'needs_ai' => false, 'ai_available' => false, 'text' => 'opening hours?' ) );
hh_check( 'rules-only enquiry with AI off does not escalate', $d['escalate'] === false );

// Policy-sensitive text escalates regardless of confidence, and matches whole words only.
$d = $H::decide( array( 'text' => 'I want a refund today', 'confidence' => 0.99 ) );
hh_check( 'policy term escalates at high confidence', $d['reasons'] === array( 'policy_sensitive' ) );
$d = $H::decide( array( 'text' => 'Refunds please', 'confidence' => 0.99 ) );
hh_check( 'plural policy term matches', $d['reasons'] === array( 'policy_sensitive' ) );
$d = $H::decide( array( 'text' => 'Is the refundable deposit mentioned?', 'confidence' => 0.99 ) );
hh_check( 'substring inside another word does not match', $d['escalate'] === false );
$d = $H::decide( array( 'text' => 'my kitchen tap is leaking', 'needs_ai' => true, 'ai_available' => true, 'confidence' => 0.8 ) );
hh_check( 'ordinary request not escalated', $d['escalate'] === false );

// Multiple reasons come back in fixed order.
$d = $H::decide( array( 'text' => 'legal complaint', 'needs_ai' => true, 'ai_available' => false ) );
hh_check( 'reasons in fixed order', $d['reasons'] === array( 'policy_sensitive', 'ai_unavailable' ) );

// Contact gate: no contact details and missing consent both block storage.
hh_check( 'no contact details refused', $H::contact_gate( array(), true ) === 'no_contact_details' );
hh_check( 'whitespace-only contact refused', $H::contact_gate( array( 'email' => '  ', 'phone' => '' ), true ) === 'no_contact_details' );
hh_check( 'consent missing refused', $H::contact_gate( array( 'email' => 'a@b.co' ), false ) === 'consent_required' );
hh_check( 'consent given as string refused', $H::contact_gate( array( 'email' => 'a@b.co' ), 'yes' ) === 'consent_required' );
hh_check( 'email with consent ok', $H::contact_gate( array( 'email' => 'a@b.co' ), true ) === 'ok' );
hh_check( 'phone with consent ok', $H::contact_gate( array( 'phone' => '0400 000 000' ), true ) === 'ok' );

// Structured enquiry: fields present, refused without contact or consent, PII removed from summary.
$r = $H::build_enquiry( array(
    'text' => 'Call me on 0412 345 678 or mail jo@example.com about a quote for two bathrooms',
    'source' => 'ai-assistant', 'intent' => 'quote', 'confidence' => 0.3,
    'reasons' => array( 'low_confidence', 'bogus' ), 'consent' => true,
    'name' => 'Jo', 'email' => 'jo@example.com', 'phone' => '',
) );
hh_check( 'structured enquiry built', $r['ok'] === true );
$e = $r['enquiry'] ?? array();
hh_check( 'source/intent kept', ( $e['source'] ?? '' ) === 'ai-assistant' && ( $e['intent'] ?? '' ) === 'quote' );
hh_check( 'confidence kept', ( $e['confidence'] ?? null ) === 0.3 );
hh_check( 'unknown reasons dropped', ( $e['reasons'] ?? null ) === array( 'low_confidence' ) );
hh_check( 'email removed from summary', strpos( $e['summary'] ?? '', 'jo@example.com' ) === false );
hh_check( 'phone removed from summary', strpos( $e['summary'] ?? '', '0412' ) === false );
hh_check( 'summary keeps request text', strpos( $e['summary'] ?? '', 'quote for two bathrooms' ) !== false );

$r = $H::build_enquiry( array( 'text' => 'x', 'consent' => true ) );
hh_check( 'no contact refuses build', $r['ok'] === false && $r['reason'] === 'no_contact_details' );
$r = $H::build_enquiry( array( 'text' => 'x', 'consent' => false, 'email' => 'a@b.co' ) );
hh_check( 'no consent refuses build', $r['ok'] === false && $r['reason'] === 'consent_required' );

// Unknown source/intent fall back to safe defaults; confidence is clamped.
$r = $H::build_enquiry( array( 'text' => 'x', 'consent' => true, 'email' => 'a@b.co', 'source' => 'evil', 'intent' => 'drop', 'confidence' => 7 ) );
hh_check( 'unknown source and intent default', $r['enquiry']['source'] === 'ai-handoff' && $r['enquiry']['intent'] === 'other' );
hh_check( 'confidence clamped to 1', $r['enquiry']['confidence'] === 1.0 );

// Summary length cap.
hh_check( 'summary capped', strlen( $H::permitted_summary( str_repeat( 'a', 900 ) ) ) === 500 );

// Rate limit.
hh_check( 'rate limit allows below cap', $H::within_rate_limit( 2 ) === true );
hh_check( 'rate limit blocks at cap', $H::within_rate_limit( 3 ) === false );

// Explicit requests for a person escalate with customer_requested, unless negated.
$d = $H::decide( array( 'text' => 'Can I speak to a person about my booking?', 'needs_ai' => false ) );
hh_check( 'asking for a person escalates', $d['escalate'] === true && $d['reasons'] === array( 'customer_requested' ) );
$d = $H::decide( array( 'text' => 'Please call me back on Thursday', 'needs_ai' => false ) );
hh_check( 'call me back escalates', $d['reasons'] === array( 'customer_requested' ) );
$d = $H::decide( array( 'text' => 'I would like to talk to someone, not a bot', 'needs_ai' => false ) );
hh_check( 'talk to someone escalates', $d['reasons'] === array( 'customer_requested' ) );
$d = $H::decide( array( 'text' => 'Talk to a human please. Refund and a legal complaint', 'needs_ai' => false ) );
hh_check( 'request plus policy keeps fixed order', $d['reasons'] === array( 'policy_sensitive', 'customer_requested' ) );
$d = $H::decide( array( 'text' => "It\xE2\x80\x99s urgent, please talk to a human", 'needs_ai' => false ) );
hh_check( 'curly apostrophe does not break phrase match', $d['reasons'] === array( 'customer_requested' ) );
$d = $H::decide( array( 'text' => 'No need to speak to someone, the price is fine', 'needs_ai' => false ) );
hh_check( 'negated request does not escalate', $d['escalate'] === false );
$d = $H::decide( array( 'text' => "I don't want to speak to a person", 'needs_ai' => false ) );
hh_check( 'dont want to speak is negated', $d['escalate'] === false );
$d = $H::decide( array( 'text' => 'Do you do gutters and what are your opening hours?', 'needs_ai' => false ) );
hh_check( 'ordinary question does not escalate', $d['escalate'] === false );
$d = $H::decide( array( 'text' => 'my real personal details are fine', 'needs_ai' => false ) );
hh_check( 'real person needs the exact phrase', $d['escalate'] === false );
hh_check( 'asks_for_person empty string', $H::asks_for_person( '' ) === false );
$r = $H::build_enquiry( array(
    'text' => 'speak to a person', 'source' => 'ai-assistant', 'intent' => 'callback', 'confidence' => null,
    'reasons' => array( 'low_confidence', 'customer_requested', 'customer_requested' ), 'consent' => true,
    'name' => 'A', 'email' => 'a@example.test', 'phone' => '',
) );
hh_check( 'enquiry reasons keep fixed order without duplicates', $r['ok'] === true && $r['enquiry']['reasons'] === array( 'customer_requested', 'low_confidence' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
