<?php
/**
 * Unit tests for AI-guided first-run setup (#40).
 * Run: php tests/unit/ai-setup-check-test.php
 * Pure PHP with WordPress function stubs (wp-stubs.php). No database, no network.
 */
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-setup-check.php';

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo "FAIL: {$name}\n";
}
function reset_state() {
	$GLOBALS['tzfd_test_options'] = array();
	$GLOBALS['tzfd_test_filters'] = array();
	$GLOBALS['tzfd_test_filter_calls'] = array();
	$GLOBALS['tzfd_test_fired'] = array();
	$GLOBALS['tzfd_test_caps'] = false;
}
function keys_of( array $items ) {
	return array_map( static function ( $i ) { return $i['key']; }, $items );
}
function required_keys( array $state ) {
	return array_values( array_map( static function ( $i ) { return $i['key']; },
		array_filter( TZFD_Ai_Setup_Check::missing( $state ), static function ( $i ) { return $i['required']; } ) ) );
}
function suggestions_of( array $suggestions, $type ) {
	return array_values( array_filter( $suggestions, static function ( $s ) use ( $type ) { return $s['type'] === $type; } ) );
}

$EMPTY_NEW = array(
	'business_name'     => '',
	'greeting'          => '',
	'services'          => array(),
	'hours_configured'  => false,
	'faq_questions'     => array(),
	'tags'              => array(),
	'connector_available' => false,
);
$READY = array(
	'business_name'       => 'Harbour Plumbing',
	'greeting'            => 'Welcome to Harbour Plumbing.',
	'services'            => array( 'Boiler repair', 'Drain unblocking' ),
	'hours_configured'    => true,
	'faq_questions'       => array(),
	'tags'                => array(),
	'connector_available' => true,
);

/* ---------- consent and gate: off by default, no key needed ---------- */
reset_state();
ok( 'consent is off by default on a fresh site', TZFD_Ai_Free_Core::consent_granted() === false );

$req = required_keys( $EMPTY_NEW );
ok( 'empty new site: business name, services and hours are required', $req === array( 'business_name', 'services', 'hours' ) );
ok( 'empty new site: greeting is optional', ! in_array( 'greeting', $req, true ) );
$all = keys_of( TZFD_Ai_Setup_Check::missing( array_merge( $EMPTY_NEW, array( 'connector_available' => false ) ) ) );
ok( 'no-key onboarding: missing hosted connector is reported, not required for booking', in_array( 'hosted_connector', $all, true ) && ! in_array( 'hosted_connector', $req, true ) );

$gate = TZFD_Ai_Setup_Check::ai_gate( $EMPTY_NEW );
ok( 'gate blocks AI on an empty site and lists every missing item', $gate['allowed'] === false && $gate['missing'] === array( 'business_name', 'services', 'hours', 'hosted_connector' ) );

$no_connector = array_merge( $READY, array( 'connector_available' => false ) );
$gate = TZFD_Ai_Setup_Check::ai_gate( $no_connector );
ok( 'gate still blocks AI when the hosted connection is absent, even with all required fields', $gate['allowed'] === false && $gate['missing'] === array( 'hosted_connector' ) );

$gate = TZFD_Ai_Setup_Check::ai_gate( $READY );
ok( 'gate allows AI only when every required item is present', $gate['allowed'] === true && $gate['missing'] === array() );

/* ---------- enabling AI: gate, then explicit confirmation, consent stored only then ---------- */
reset_state();
$r = TZFD_Ai_Setup_Check::enable_ai( $EMPTY_NEW, true, 100 );
ok( 'enable_ai refuses with missing configuration', $r['ok'] === false && $r['error'] === 'missing_configuration' && in_array( 'services', $r['missing'], true ) );
ok( 'refused enable_ai writes no consent', TZFD_Ai_Free_Core::consent_granted() === false );

$r = TZFD_Ai_Setup_Check::enable_ai( $READY, false, 100 );
ok( 'enable_ai without administrator confirmation is refused', $r['ok'] === false && $r['error'] === 'admin_confirmation_required' );
ok( 'unconfirmed enable_ai writes no consent', TZFD_Ai_Free_Core::consent_granted() === false );

$r = TZFD_Ai_Setup_Check::enable_ai( $READY, true, 100 );
ok( 'confirmed enable_ai with a complete gate turns consent on', $r['ok'] === true && TZFD_Ai_Free_Core::consent_granted() === true );

/* ---------- guided recovery: the list shrinks as each step is completed ---------- */
$step = $EMPTY_NEW;
$step['business_name'] = 'Harbour Plumbing';
ok( 'recovery: after naming the business, services and hours remain', required_keys( $step ) === array( 'services', 'hours' ) );
$step['services'] = array( 'Boiler repair' );
ok( 'recovery: after adding a service, only hours remain', required_keys( $step ) === array( 'hours' ) );
$step['hours_configured'] = true;
ok( 'recovery: after setting hours, nothing required remains', required_keys( $step ) === array() );

/* ---------- suggestions: new site ---------- */
$sugg = TZFD_Ai_Setup_Check::suggestions( array_merge( $READY, array( 'greeting' => '' ) ) );
$greet = suggestions_of( $sugg, 'greeting' );
ok( 'new site gets one greeting suggestion built from the business name', count( $greet ) === 1 && false !== strpos( $greet[0]['value'], 'Harbour Plumbing' ) );
$faqs = suggestions_of( $sugg, 'faq' );
ok( 'new site gets one FAQ suggestion per service', count( $faqs ) === 2 && $faqs[0]['value']['question'] === 'Do you offer Boiler repair?' );
$tags = suggestions_of( $sugg, 'tags' );
ok( 'enquiry tags are slugged from service names', count( $tags ) === 1 && $tags[0]['value'] === array( 'boiler-repair', 'drain-unblocking' ) );
ok( 'every suggestion needs confirmation before anything is written', array_reduce( $sugg, static function ( $c, $s ) { return $c && $s['needs_confirmation'] === true; }, true ) );

/* ---------- suggestions: upgraded site keeps existing values ---------- */
$upgraded = array_merge( $READY, array(
	'faq_questions' => array( 'do you offer boiler repair?' ),
	'tags'          => array( 'boiler-repair' ),
) );
$sugg = TZFD_Ai_Setup_Check::suggestions( $upgraded );
ok( 'upgraded site: existing greeting is not replaced', suggestions_of( $sugg, 'greeting' ) === array() );
$faq_q = array_map( static function ( $s ) { return $s['value']['question']; }, suggestions_of( $sugg, 'faq' ) );
ok( 'upgraded site: an existing FAQ is not duplicated, case-insensitively', $faq_q === array( 'Do you offer Drain unblocking?' ) );
$tag_values = suggestions_of( $sugg, 'tags' );
ok( 'upgraded site: an existing tag is not suggested again', $tag_values[0]['value'] === array( 'drain-unblocking' ) );

/* ---------- no invented facts ---------- */
$sugg = TZFD_Ai_Setup_Check::suggestions( array_merge( $EMPTY_NEW, array( 'business_name' => 'Harbour Plumbing', 'services' => array( 'Boiler repair' ) ) ) );
$figures = array();
foreach ( $sugg as $s ) {
	$text = is_array( $s['value'] ) ? json_encode( $s['value'] ) : (string) $s['value'];
	$figures = array_merge( $figures, TZFD_Ai_Free_Core::ungrounded_figures( $text, array( 'Harbour Plumbing', 'Boiler repair' ) ) );
}
ok( 'suggestions invent no figures, hours or prices', $figures === array() );

/* ---------- confirm: admin edits, grounding, sanitising ---------- */
$faq = array( 'type' => 'faq', 'value' => array( 'question' => 'Do you offer Boiler repair?', 'answer' => 'Yes, we offer Boiler repair. Please contact us for details.' ), 'source' => 'service:Boiler repair' );
$r = TZFD_Ai_Setup_Check::confirm( $faq, array() );
ok( 'confirm refuses without explicit confirmation', $r['ok'] === false && $r['error'] === 'admin_confirmation_required' );

$r = TZFD_Ai_Setup_Check::confirm( $faq, array( 'confirmed' => true ) );
ok( 'confirmed FAQ from a service name is accepted', $r['ok'] === true && $r['value']['question'] === 'Do you offer Boiler repair?' );

$r = TZFD_Ai_Setup_Check::confirm( $faq, array( 'confirmed' => true, 'answer' => 'Yes, the call-out fee is $80.' ) );
ok( 'edited FAQ answer with an invented price is refused', $r['ok'] === false && $r['error'] === 'ungrounded_figures' );

$r = TZFD_Ai_Setup_Check::confirm( $faq, array( 'confirmed' => true, 'answer' => '<script>alert("x")</script>Yes we do boiler repairs.' ) );
ok( 'FAQ answer HTML is stripped before it is stored', $r['ok'] === true && false === strpos( $r['value']['answer'], '<' ) && false === strpos( $r['value']['answer'], 'script>' ) );
$r = TZFD_Ai_Setup_Check::confirm( $faq, array( 'confirmed' => true, 'answer' => 'Yes, we fix it. <b>Call</b> us for details.' ) );
ok( 'FAQ answer with an inline tag is stored without angle brackets', $r['ok'] === true && false === strpos( $r['value']['answer'], '<' ) && false === strpos( $r['value']['answer'], '>' ) );

$r = TZFD_Ai_Setup_Check::confirm( $faq, array( 'confirmed' => true, 'answer' => 'Hi' ) );
ok( 'too-short FAQ answer is refused', $r['ok'] === false && $r['error'] === 'too_short' );

$greeting = array( 'type' => 'greeting', 'value' => 'Hello, thanks for contacting Harbour Plumbing.', 'source' => 'business_name' );
$r = TZFD_Ai_Setup_Check::confirm( $greeting, array( 'confirmed' => true, 'value' => '   ' ) );
ok( 'empty greeting edit is refused', $r['ok'] === false && $r['error'] === 'empty' );

$tags = array( 'type' => 'tags', 'value' => array( 'boiler-repair' ), 'source' => 'services' );
$r = TZFD_Ai_Setup_Check::confirm( $tags, array( 'confirmed' => true, 'value' => array( 'Boiler Repair!', 'boiler repair', 'Emergency Call Out' ) ) );
ok( 'tags are slugged and de-duplicated on confirm', $r['ok'] === true && $r['value'] === array( 'boiler-repair', 'emergency-call-out' ) );

$many = array();
for ( $i = 0; $i < 12; $i++ ) { $many[] = 'tag ' . $i; }
$r = TZFD_Ai_Setup_Check::confirm( $tags, array( 'confirmed' => true, 'value' => $many ) );
ok( 'tags are capped at MAX_TAGS', $r['ok'] === true && count( $r['value'] ) === TZFD_Ai_Setup_Check::MAX_TAGS );

$r = TZFD_Ai_Setup_Check::confirm( array( 'type' => 'unknown', 'value' => 'x' ), array( 'confirmed' => true ) );
ok( 'unknown suggestion type is refused', $r['ok'] === false && $r['error'] === 'unknown_type' );

/* ---------- report ---------- */
$GLOBALS['tzfd_fail'] === 0
	? printf( "AI setup-check tests: passed %d, failed 0\n", $GLOBALS['tzfd_pass'] )
	: printf( "AI setup-check tests: passed %d, failed %d\n", $GLOBALS['tzfd_pass'], $GLOBALS['tzfd_fail'] );
exit( $GLOBALS['tzfd_fail'] > 0 ? 1 : 0 );
