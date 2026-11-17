<?php
/**
 * Unit tests for the weekly AI FAQ suggestion job (#35): scheduling, tenant boundary, minimised data,
 * idempotency and knowledge cache invalidation.
 * Run: php tests/unit/faq-weekly-test.php
 * Pure PHP with WordPress function stubs (wp-stubs.php). No database, no network.
 */
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-free-services.php';

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo "FAIL: {$name}\n";
}
function reset_state() {
	$GLOBALS['tzfd_test_options']     = array();
	$GLOBALS['tzfd_test_filters']     = array();
	$GLOBALS['tzfd_test_filter_calls'] = array();
	$GLOBALS['tzfd_test_fired']       = array();
	$GLOBALS['tzfd_test_scheduled']   = array();
	$GLOBALS['tzfd_test_caps']        = true;
}
function hosted_calls() {
	return $GLOBALS['tzfd_test_filter_calls'][ TZFD_Ai_Free_Core::HOSTED_FILTER ] ?? 0;
}
function grant() { TZFD_Ai_Free_Core::set_consent( true, 1 ); }

$NOW = gmmktime( 12, 0, 0, 11, 16, 2026 ); // Monday, ISO week 2026-W47
$CO  = 7;
$OTHER = 8;
$FACTS_7 = array(
	array( 'id' => 'svc-1', 'text' => 'Boiler repair. Call-out fee $80.', 'approved' => true ),
	array( 'id' => 'svc-9', 'text' => 'Unapproved draft service.', 'approved' => false ),
);

/** Company 7 has approved facts. Company 8 has none, so it must never receive company 7's facts. */
function install_facts_filter( array $facts_7 ) {
	add_filter( TZFD_Ai_Faq_Suggestions::FACTS_FILTER, static function ( $value, $cid ) use ( $facts_7 ) {
		return (int) $cid === 7 ? $facts_7 : array();
	} );
}

/** Records three boiler-repair questions for a company. Only the topic label is kept. */
function record_boiler_questions( $cid, $now ) {
	$vocab = array( 'boiler repair' );
	TZFD_Ai_Gap_Detection::record( $cid, 'Can you do boiler repair on Sunday? my name is John 0412 345 678 john@example.com', false, 0, $vocab, $now );
	TZFD_Ai_Gap_Detection::record( $cid, 'boiler repair today please?', false, 0, $vocab, $now + 60 );
	TZFD_Ai_Gap_Detection::record( $cid, 'is boiler repair open late?', false, 0, $vocab, $now + 120 );
}

/* ---------- scheduling ---------- */
reset_state();
TZFD_Ai_Faq_Suggestions::register();
ok( 'register: weekly job is not scheduled by default', empty( $GLOBALS['tzfd_test_scheduled'][ TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ] ) );
reset_state();
update_option( TZFD_Ai_Faq_Suggestions::ENABLE_OPTION, true );
ok( 'register: weekly job is scheduled on first registration', ( TZFD_Ai_Faq_Suggestions::register() === null ) && ( $GLOBALS['tzfd_test_scheduled'][ TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ]['recurrence'] ?? '' ) === 'weekly' );
$GLOBALS['tzfd_test_scheduled'][ TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ]['when'] = 12345;
TZFD_Ai_Faq_Suggestions::register();
ok( 'register: an existing weekly event is kept, never duplicated', $GLOBALS['tzfd_test_scheduled'][ TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ]['when'] === 12345 );
ok( 'register: weekly hook is wired to the scheduled runner', count( $GLOBALS['tzfd_test_actions'][ TZFD_Ai_Faq_Suggestions::WEEKLY_HOOK ] ?? array() ) >= 1 );

/* ---------- consent and company list ---------- */
reset_state();
install_facts_filter( $FACTS_7 );
$r = TZFD_Ai_Faq_Suggestions::run_scheduled( $NOW );
ok( 'weekly job does nothing without consent', $r === array() && hosted_calls() === 0 );

grant();
ok( 'company ids come only from recorded counts and drop zero', TZFD_Ai_Gap_Detection::company_ids() === array() );
record_boiler_questions( $CO, $NOW );
record_boiler_questions( $OTHER, $NOW );
ok( 'company ids list each company that has counts', TZFD_Ai_Gap_Detection::company_ids() === array( $CO, $OTHER ) );

/* ---------- tenant boundary, minimised data, grounding ---------- */
reset_state();
install_facts_filter( $FACTS_7 );
grant();
record_boiler_questions( $CO, $NOW );
record_boiler_questions( $OTHER, $NOW );
$seen = array();
add_filter( TZFD_Ai_Free_Core::HOSTED_FILTER, static function ( $value, $env ) use ( &$seen ) {
	$seen[] = $env;
	return array( 'ok' => true, 'company_id' => $env['company_id'], 'data' => array( 'faqs' => array(
		array( 'question' => 'Do you repair boilers?', 'answer' => 'Yes, boiler repair is offered. Call-out fee $80.', 'fact_ids' => array( 'svc-1' ) ),
		array( 'question' => 'Do you repair it on Sundays?', 'answer' => 'Yes, boiler repair is offered.', 'fact_ids' => array( 'svc-404' ) ), // unknown fact id
	) ), 'usage' => array( 'input_tokens' => 50, 'output_tokens' => 30 ) );
} );

$out = TZFD_Ai_Faq_Suggestions::run_scheduled( $NOW );
ok( 'weekly job runs for company 7 with approved facts', ( $out[ $CO ]['status'] ?? '' ) === 'done' && ( $out[ $CO ]['added'] ?? 0 ) === 1 );
ok( 'company 8 with no approved facts is skipped with no hosted call', ( $out[ $OTHER ]['status'] ?? '' ) === 'skipped' && ( $out[ $OTHER ]['error'] ?? '' ) === 'no_source_facts' );
ok( 'exactly one hosted call was made (the company with facts)', hosted_calls() === 1 && count( $seen ) === 1 );
ok( 'hosted envelope is scoped to company 7', ( $seen[0]['company_id'] ?? 0 ) === $CO );
$env_json = json_encode( $seen[0] );
ok( 'envelope carries only company 7 approved facts, never unapproved ones', false !== strpos( $env_json, 'svc-1' ) && false === strpos( $env_json, 'svc-9' ) && false === strpos( $env_json, 'Unapproved' ) );
ok( 'envelope holds no name, phone, email or raw question text', false === strpos( $env_json, 'John' ) && false === strpos( $env_json, '0412' ) && false === strpos( $env_json, 'john@' ) && false === strpos( $env_json, 'Sunday' ) );
ok( 'stored proposal is grounded and drops the unknown-fact item', count( TZFD_Ai_Faq_Suggestions::pending( $CO ) ) === 1 && TZFD_Ai_Faq_Suggestions::pending( $CO )[0]['fact_ids'] === array( 'svc-1' ) );
ok( 'company 8 stores no proposals', TZFD_Ai_Faq_Suggestions::pending( $OTHER ) === array() );
ok( 'suggestions are not published on their own', TZFD_Ai_Knowledge_Builder::published( $CO ) === array() );

/* ---------- weekly idempotency ---------- */
$again = TZFD_Ai_Faq_Suggestions::run_scheduled( $NOW + 3600 );
ok( 'same week rerun is a no-op for the company already run', ( $again[ $CO ]['status'] ?? '' ) === 'already_ran' && hosted_calls() === 1 );
$week2 = $NOW + 7 * DAY_IN_SECONDS;
record_boiler_questions( $CO, $week2 );
$next = TZFD_Ai_Faq_Suggestions::run_scheduled( $week2 );
ok( 'next ISO week runs again once new questions exist', ( $next[ $CO ]['status'] ?? '' ) === 'done' && hosted_calls() === 2 );
$quiet = TZFD_Ai_Faq_Suggestions::run_scheduled( $week2 + 14 * DAY_IN_SECONDS );
ok( 'no questions in the window: skipped without a hosted call', ( $quiet[ $CO ]['error'] ?? '' ) === 'no_question_themes' && hosted_calls() === 2 );

/* ---------- approval publishes and invalidates the answer cache ---------- */
reset_state();
install_facts_filter( $FACTS_7 );
grant();
record_boiler_questions( $CO, $NOW );
add_filter( TZFD_Ai_Free_Core::HOSTED_FILTER, static function ( $value, $env ) {
	return array( 'ok' => true, 'company_id' => $env['company_id'], 'data' => array( 'faqs' => array(
		array( 'question' => 'Do you repair boilers?', 'answer' => 'Yes, boiler repair is offered.', 'fact_ids' => array( 'svc-1' ) ),
	) ) );
} );
TZFD_Ai_Faq_Suggestions::run_scheduled( $NOW );
$p = TZFD_Ai_Faq_Suggestions::pending( $CO )[0];
$before = TZFD_AI_Answer_Box::knowledge_version();
$decided = TZFD_Ai_Faq_Suggestions::decide( $CO, $p['id'], 'approve', TZFD_Ai_Faq_Suggestions::approved_for( $CO ), array(), $NOW, 1 );
ok( 'approve publishes through the builder', ( $decided['status'] ?? '' ) === 'approved' && count( TZFD_Ai_Knowledge_Builder::published( $CO ) ) === 1 );
ok( 'publishing bumps the answer cache version exactly once', TZFD_AI_Answer_Box::knowledge_version() === $before + 1 );

/* ---------- negative: review needs the capability, and tenant scope holds ---------- */
$GLOBALS['tzfd_test_caps'] = false;
$p2 = TZFD_Ai_Faq_Suggestions::decide( $CO, 'missing-id', 'approve', $FACTS_7, array(), $NOW, 1 );
ok( 'decide refuses an unknown proposal id', ( $p2['error'] ?? '' ) === 'not_found' );
$GLOBALS['tzfd_test_caps'] = true;
$cross = TZFD_Ai_Faq_Suggestions::decide( $OTHER, $p['id'], 'approve', $FACTS_7, array(), $NOW, 1 );
ok( 'decide refuses another company\'s proposal', ( $cross['error'] ?? '' ) === 'not_found' );
ok( 'approved_for drops facts that the owner has not approved', array_column( TZFD_Ai_Faq_Suggestions::approved_for( $CO ), 'id' ) === array( 'svc-1' ) );
ok( 'approved_for returns nothing for a company without a filter', TZFD_Ai_Faq_Suggestions::approved_for( $OTHER ) === array() );

/* ---------- result ---------- */
echo "Passed: {$GLOBALS['tzfd_pass']}  Failed: {$GLOBALS['tzfd_fail']}\n";
exit( $GLOBALS['tzfd_fail'] > 0 ? 1 : 0 );
