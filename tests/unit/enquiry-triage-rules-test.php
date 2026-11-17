<?php
/**
 * Unit tests for the deterministic enquiry triage rules. Run from the repository root:
 *   php -I tests/unit/enquiry-triage-rules-test.php
 * No WordPress is loaded. The rules class is pure PHP.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-enquiry-triage-rules.php';

$R = 'TZFD_Enquiry_Triage_Rules';
$pass = 0;
$fail = array();

function t_check( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; return; }
    $fail[] = $name . ( '' !== $detail ? ' :: ' . $detail : '' );
}

// Fixed clock: 16 Nov 2026 10:00 UTC.
$NOW = strtotime( '2026-11-16 10:00:00 UTC' );

// Builds a prior row the way the storage layer does.
function prior( $text, $email, $ip, $ts ) {
    return array(
        'signature' => TZFD_Enquiry_Triage_Rules::signature( $text ),
        'tokens'    => TZFD_Enquiry_Triage_Rules::tokens( $text ),
        'email'     => $email,
        'ip_hash'   => $ip,
        'ts'        => $ts,
    );
}

// ---- Intent classification ---------------------------------------------------------------
$c = $R::classify_intents( 'Can I book a cleaning on Friday?' );
t_check( 'intent booking', 'booking' === $c['primary'] && in_array( 'booking', $c['intents'], true ), json_encode( $c ) );

$c = $R::classify_intents( 'I want to cancel my booking' );
t_check( 'intent cancel primary over booking', 'cancel' === $c['primary'] && in_array( 'booking', $c['intents'], true ), json_encode( $c ) );

$c = $R::classify_intents( "I don't need a quote, just a repair" );
t_check( 'negation hides quote', ! in_array( 'quote', $c['intents'], true ) && 'service_request' === $c['primary'], json_encode( $c ) );

$c = $R::classify_intents( 'Please do not cancel my visit' );
t_check( 'negation hides cancel', ! in_array( 'cancel', $c['intents'], true ) && 'general' === $c['primary'], json_encode( $c ) );

$c = $R::classify_intents( 'I would like a quote for painting the hallway' );
t_check( 'quote intent', 'quote' === $c['primary'], json_encode( $c ) );

$c = $R::classify_intents( 'I am unhappy with the last visit and want a refund' );
t_check( 'complaint intent', 'complaint' === $c['primary'], json_encode( $c ) );

$c = $R::classify_intents( 'Quote and then cancel if too expensive' );
t_check( 'overlap: cancel beats quote', 'cancel' === $c['primary'] && in_array( 'quote', $c['intents'], true ), json_encode( $c ) );

$c = $R::classify_intents( 'Necesito una cita para reparar la fuga' );
t_check( 'spanish booking and repair', 'booking' === $c['primary'] && in_array( 'service_request', $c['intents'], true ), json_encode( $c ) );

$c = $R::classify_intents( 'Je voudrais un devis pour la cuisine' );
t_check( 'french quote', 'quote' === $c['primary'], json_encode( $c ) );

$c = $R::classify_intents( 'What are your opening hours?' );
t_check( 'general fallback', 'general' === $c['primary'] && array( 'general' ) === $c['intents'], json_encode( $c ) );

$c = $R::classify_intents( "\u{FF23}\u{FF41}\u{FF4E} \u{FF59}\u{FF4F}\u{FF55} \u{FF42}\u{FF4F}\u{FF4F}\u{FF4B}?" );
t_check( 'fullwidth NFKC booking', 'booking' === $c['primary'], json_encode( $c ) );

$c = $R::classify_intents( "can\u{200B} you book" );
t_check( 'zero-width char removed', 'booking' === $c['primary'], json_encode( $c ) );

$c = $R::classify_intents( "I\u{2019}m not sure, can we book" );
t_check( 'curly apostrophe normalised', 'booking' === $c['primary'], json_encode( $c ) );

// ---- Urgency -----------------------------------------------------------------------------
$u = $R::urgency( 'This is urgent, the pipe has burst', $NOW );
t_check( 'explicit urgent', 'urgent' === $u['level'] && in_array( 'explicit_urgent', $u['reasons'], true ), json_encode( $u ) );

$u = $R::urgency( 'Not urgent, whenever you are free', $NOW );
t_check( 'negated urgent is normal', 'normal' === $u['level'] && array() === $u['reasons'], json_encode( $u ) );

$u = $R::urgency( 'Please call me back on my mobile', $NOW );
t_check( 'callback request', in_array( 'callback_request', $u['reasons'], true ), json_encode( $u ) );

$u = $R::urgency( 'Our offer expires 20/11/2026, can you confirm', $NOW );
t_check( 'expiring date within 14 days', in_array( 'expiring_date', $u['reasons'], true ), json_encode( $u ) );

$u = $R::urgency( 'Our offer expires 20/11/2026, can you confirm', strtotime( '2026-12-01 00:00:00 UTC' ) );
t_check( 'expiring date already past window is not urgent by date', ! in_array( 'expiring_date', $u['reasons'], true ), json_encode( $u ) );

$u = $R::urgency( 'Just wondering about your hours', $NOW );
t_check( 'plain enquiry is normal', 'normal' === $u['level'], json_encode( $u ) );

$u = $R::urgency( 'I am furious, you were rude and I want a refund', $NOW );
t_check( 'complaint is an urgency reason', in_array( 'complaint', $u['reasons'], true ), json_encode( $u ) );

// ---- Date parsing -------------------------------------------------------------------------
t_check( 'day-first slash date', $R::dates_in( '20/11/2026', 2026 ) === array( '2026-11-20' ) );
t_check( 'iso date', $R::dates_in( '2026-11-20', 2026 ) === array( '2026-11-20' ) );
t_check( 'day month name default year', $R::dates_in( 'on 20 Nov please', 2026 ) === array( '2026-11-20' ) );
t_check( 'month name day year', $R::dates_in( 'Nov 20 2026', 2026 ) === array( '2026-11-20' ) );
t_check( 'impossible date ignored', $R::dates_in( '31/02/2026', 2026 ) === array(), json_encode( $R::dates_in( '31/02/2026', 2026 ) ) );
t_check( 'word containing month not a date', $R::dates_in( 'marble 5 steps', 2026 ) === array() );
t_check( 'may as a word is not a date', $R::dates_in( 'may I book for 5 people', 2026 ) === array(), json_encode( $R::dates_in( 'may I book for 5 people', 2026 ) ) );

// ---- Quote draft --------------------------------------------------------------------------
$d = $R::quote_draft( "I need a quote for cleaning the office\nSite: 12 Smith St, Melbourne\nDate: 25/11/2026 0412 345 678 jo@example.com", null, 2026 );
t_check( 'draft service', 'cleaning' === $d['service_type'], json_encode( $d ) );
t_check( 'draft date', '2026-11-25' === $d['requested_date'], json_encode( $d ) );
t_check( 'draft site', '12 Smith St, Melbourne' === $d['site'], json_encode( $d ) );
t_check( 'draft never binding', false === $d['binding'] && 'rules' === $d['source'] );
t_check( 'draft has no price fields', 0 === preg_match( '/price|total|amount|cost|labour|labor|material|approv/i', implode( ' ', array_keys( $d ) ) ) );
t_check( 'draft holds no contact data', false === strpos( json_encode( $d ), 'jo@example.com' ) && false === strpos( json_encode( $d ), '0412' ) );
t_check( 'draft incomplete lists missing', $R::quote_draft( 'I want a quote', null, 2026 )['missing'] === array( 'service_type', 'requested_date', 'site' ) );

$d = $R::quote_draft( 'Quote for painting, either 20/11/2026 or 21/11/2026', null, 2026 );
t_check( 'contradictory dates flagged', in_array( 'contradictory_dates', $d['flags'], true ) && '' === $d['requested_date'], json_encode( $d ) );
t_check( 'contradictory date still missing', in_array( 'requested_date', $d['missing'], false ) === false && '' === $d['requested_date'] );

$fixed = $R::apply_correction( $d, array( 'requested_date' => '2026-11-20' ), 7 );
t_check( 'owner correction clears contradiction', array() === $fixed['flags'] && '2026-11-20' === $fixed['requested_date'] && 7 === $fixed['corrected_by'], json_encode( $fixed ) );
t_check( 'owner correction recorded', in_array( 'requested_date', $fixed['corrected'], true ) );

$err = $R::apply_correction( $d, array( 'total_price' => '900' ), 7 );
t_check( 'correction refuses price field', isset( $err['error'] ) && 'field_not_allowed' === $err['error'], json_encode( $err ) );
$err = $R::apply_correction( $d, array( 'email' => 'x@example.com' ), 7 );
t_check( 'correction refuses contact field', isset( $err['error'] ) && 'field_not_allowed' === $err['error'] );
$err = $R::apply_correction( $d, array( 'requested_date' => '2026-02-31' ), 7 );
t_check( 'correction refuses impossible date', isset( $err['error'] ) && 'bad_date' === $err['error'] );

t_check( 'redact email and phone', 'Call [phone] or [email]' === $R::redact( 'Call 0412 345 678 or jo@example.com' ), $R::redact( 'Call 0412 345 678 or jo@example.com' ) );

// ---- Spam and duplicates ------------------------------------------------------------------
$base = array(
    'text' => 'Can you come and fix the kitchen tap on Tuesday morning please',
);

$a = $R::spam_assessment( $base['text'], 'a@example.com', 'ip1', $NOW, array() );
t_check( 'single legit enquiry not held', false === $a['hold'] && 0 === $a['score'], json_encode( $a ) );

$priors = array();
for ( $i = 0; $i < 6; $i++ ) { $priors[] = prior( 'Different request number ' . $i . ' about my own job', 'p' . $i . '@example.com', 'shared-nat', $NOW - 60 ); }
$a = $R::spam_assessment( $base['text'], 'new@example.com', 'shared-nat', $NOW, $priors );
t_check( 'shared NAT burst alone does not hold', false === $a['hold'] && in_array( 'burst_from_address', $a['signals'], true ), json_encode( $a ) );

$a = $R::spam_assessment( $base['text'], 'b@example.com', 'ip2', $NOW, array( prior( $base['text'], 'a@example.com', 'ip1', $NOW - 3600 ) ) );
t_check( 'identical text from another person is a duplicate, not held', false === $a['hold'] && 'identical' === $a['dup_kind'], json_encode( $a ) );

$same = array( prior( $base['text'], 'a@example.com', 'ip1', $NOW - 600 ), prior( $base['text'], 'a@example.com', 'ip1', $NOW - 300 ) );
$a = $R::spam_assessment( $base['text'], 'A@example.com', 'ip1', $NOW, $same );
t_check( 'third identical from same contact held for review', true === $a['hold'] && in_array( 'repeat_same_contact', $a['signals'], true ), json_encode( $a ) );

$long = 'I have been a customer for a while and the kitchen tap in the back room has been dripping for about two weeks now, the water pools under the sink and the cupboard door is starting to swell, could someone come out and fix the tap and check the pipe under the sink before the weekend if possible thanks';
$long2 = str_replace( 'two weeks', 'three weeks', $long );
$a = $R::spam_assessment( $long2, 'c@example.com', 'ip3', $NOW, array( prior( $long, 'a@example.com', 'ip1', $NOW - 3600 ) ) );
t_check( 'near duplicate detected on long text', 'near' === $a['dup_kind'] && in_array( 'near_duplicate', $a['signals'], true ) && false === $a['hold'], json_encode( $a ) );
$a = $R::spam_assessment( 'Can you come and fix the kitchen tap on Wednesday morning please', 'c@example.com', 'ip3', $NOW, array( prior( $base['text'], 'a@example.com', 'ip1', $NOW - 3600 ) ) );
t_check( 'short text one-word change is not a near duplicate (documented limit)', '' === $a['dup_kind'] && false === $a['hold'], json_encode( $a ) );

$a = $R::spam_assessment( $base['text'], 'd@example.com', 'ip4', $NOW, array( prior( $base['text'], 'a@example.com', 'ip1', $NOW - 90000 ) ) );
t_check( 'prior older than 24h ignored', 0 === $a['score'], json_encode( $a ) );

$a = $R::spam_assessment( $base['text'], 'd@example.com', 'ip4', $NOW, array( prior( $base['text'], 'a@example.com', 'ip1', $NOW + 60 ) ) );
t_check( 'small clock skew counted', 1 === $a['score'], json_encode( $a ) );

$a = $R::spam_assessment( $base['text'], 'd@example.com', 'ip4', $NOW, array( prior( $base['text'], 'a@example.com', 'ip1', $NOW + 3600 ) ) );
t_check( 'far future prior ignored', 0 === $a['score'], json_encode( $a ) );

$spam_text = 'Earn money fast with crypto, see http://a.example www.b.example https://c.example';
$a = $R::spam_assessment( $spam_text, 'x@example.com', 'ip9', $NOW, array() );
t_check( 'link stuffing plus spam phrase held', true === $a['hold'] && in_array( 'link_volume', $a['signals'], true ) && in_array( 'spam_phrase', $a['signals'], true ), json_encode( $a ) );

$a = $R::spam_assessment( 'Question about the crypto payment options for my bill', 'y@example.com', 'ip8', $NOW, array() );
t_check( 'spam phrase alone is not held (false positive guard)', false === $a['hold'] && 2 === $a['score'], json_encode( $a ) );

$a = $R::spam_assessment( 'Please see our plan at https://example.com/plan and the photos', 'z@example.com', 'ip7', $NOW, array() );
t_check( 'one link is not spam', false === $a['hold'] && 0 === $a['score'], json_encode( $a ) );

$a = $R::spam_assessment( '!!! $$$ ### @@@ %%% ^^^ &&& ***', 'q@example.com', 'ip6', $NOW, array() );
t_check( 'symbol-only text scores low signal', in_array( 'mostly_symbols', $a['signals'], true ) && false === $a['hold'] );

// Unicode forms of the same message are one signature.
t_check( 'NFKC same signature', $R::signature( "\u{FF23}an you book" ) === $R::signature( 'can you book' ) );
t_check( 'zero-width same signature', $R::signature( "can\u{200B} you book" ) === $R::signature( 'can you book' ) );
t_check( 'case and punctuation same signature', $R::signature( 'CAN YOU BOOK?!' ) === $R::signature( 'can you book' ) );
t_check( 'empty text has no signature', '' === $R::signature( '   ' ) );

// Burst and identical text across Unicode names must not crash.
$a = $R::spam_assessment( "Ren\u{00E9}e wants a cl\u{00E9}aning \u{1F600} please", 'ren@example.com', 'ip5', $NOW, array() );
t_check( 'unicode text evaluates', is_array( $a ) && false === $a['hold'] );

// ---- Full evaluation: deterministic and offline --------------------------------------------
$e1 = $R::evaluate( 'I want to cancel, this is urgent', 'a@example.com', 'ip1', $NOW, array(), null, 2026 );
$e2 = $R::evaluate( 'I want to cancel, this is urgent', 'a@example.com', 'ip1', $NOW, array(), null, 2026 );
t_check( 'evaluate deterministic', $e1 === $e2 );
t_check( 'cancel routes to enquiry queue', 'cancel' === $e1['primary_intent'] && 'enquiry_queue' === $e1['route'] && 'urgent' === $e1['urgency'] );
t_check( 'quote routes to quote review with draft', 'quote_review' === $R::evaluate( 'Quote for gutter cleaning', 'a@x.com', '', $NOW, array(), null, 2026 )['route'] );
$held = $R::evaluate( $spam_text, 'x@example.com', 'ip9', $NOW, array(), null, 2026 );
t_check( 'held spam primary is spam and routes to spam review', 'spam' === $held['primary_intent'] && 'spam_review' === $held['route'] && true === $held['spam']['hold'] );
t_check( 'no quote draft without quote intent', null === $e1['quote_draft'] );

// Offline and no hosted call: the rules file must not reach any network or remote API.
$src = file_get_contents( dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-enquiry-triage-rules.php' );
t_check( 'rules file has no network calls', 0 === preg_match( '/wp_remote_|curl_|file_get_contents\s*\(\s*[\'"]https?:|fsockopen|stream_socket/i', $src ) );

echo "Passed: {$pass}\n";
echo 'Failed: ' . count( $fail ) . "\n";
foreach ( $fail as $f ) { echo "  FAIL: {$f}\n"; }
exit( $fail ? 1 : 0 );
