<?php
/**
 * Unit tests for the Free AI lanes (#35, #36, #39, #40, #41).
 * Run: php tests/unit/ai-free-lanes-test.php
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
	$GLOBALS['tzfd_test_options'] = array();
	$GLOBALS['tzfd_test_filters'] = array();
	$GLOBALS['tzfd_test_filter_calls'] = array();
	$GLOBALS['tzfd_test_fired'] = array();
	$GLOBALS['tzfd_test_caps'] = false;
}
/** Installs a fake hosted connector. $fn receives the envelope and returns the connector's reply. */
function connector( callable $fn ) {
	$GLOBALS['tzfd_test_filters'][ TZFD_Ai_Free_Core::HOSTED_FILTER ] = $fn;
	$GLOBALS['tzfd_test_filter_calls'] = array();
}
function hosted_calls() {
	return $GLOBALS['tzfd_test_filter_calls'][ TZFD_Ai_Free_Core::HOSTED_FILTER ] ?? 0;
}
function fired( $hook ) {
	return array_values( array_filter( $GLOBALS['tzfd_test_fired'], static function ( $f ) use ( $hook ) { return $f[0] === $hook; } ) );
}
function grant() { TZFD_Ai_Free_Core::set_consent( true, 1 ); }
function reply( $env, array $data, $usage = array() ) {
	return array( 'ok' => true, 'company_id' => $env['company_id'], 'data' => $data, 'usage' => $usage );
}

$NOW  = gmmktime( 12, 0, 0, 11, 16, 2026 ); // Monday, ISO week 2026-W47
$CO   = 7;
$FACTS = array(
	array( 'id' => 'svc-1', 'type' => 'service', 'text' => 'Boiler repair. Call-out fee $80.', 'approved' => true ),
	array( 'id' => 'svc-2', 'type' => 'service', 'text' => 'Drain unblocking.', 'approved' => true ),
	array( 'id' => 'pol-1', 'type' => 'policy', 'text' => 'Cancellations need 24 hours notice.', 'approved' => false ),
);

/* ---------- core ---------- */
reset_state();
ok( 'consent off by default', TZFD_Ai_Free_Core::consent_granted() === false );
connector( static function ( $v, $env ) { return reply( $env, array() ); } );
$r = TZFD_Ai_Free_Core::hosted_call( 'faq.weekly', array(), $CO );
ok( 'no hosted call without consent', $r['ok'] === false && $r['error'] === 'consent_required' && hosted_calls() === 0 );
ok( 'operation allow-list refuses unknown op', TZFD_Ai_Free_Core::hosted_call( 'booking.create', array(), $CO )['error'] === 'operation_not_allowed' );
grant();
$seen = null;
connector( static function ( $v, $env ) use ( &$seen ) { $seen = $env; return reply( $env, array( 'x' => 1 ), array( 'input_tokens' => 10, 'output_tokens' => 4 ) ); } );
$r = TZFD_Ai_Free_Core::hosted_call( 'faq.weekly', array( 'name' => 'Jo Bloggs', 'themes' => array( array( 'label' => 'Mail jo@example.com or call 0412 345 678', 'name' => 'x', 'nested' => array( 'phone' => '0400 000 000', 'count' => 3 ) ) ) ), $CO );
ok( 'minimised payload drops private keys at any depth', ! isset( $seen['payload']['name'] ) && ! isset( $seen['payload']['themes'][0]['name'] ) && ! isset( $seen['payload']['themes'][0]['nested']['phone'] ) );
ok( 'minimised payload scrubs contact details from strings', false === strpos( json_encode( $seen['payload'] ), 'jo@example.com' ) && false === strpos( json_encode( $seen['payload'] ), '0412 345 678' ) );
ok( 'envelope carries company id and usage passes through', $r['ok'] && $seen['company_id'] === $CO && $r['usage']['input_tokens'] === 10 );
connector( static function ( $v, $env ) { return array( 'ok' => true, 'company_id' => 999, 'data' => array() ); } );
ok( 'connector echoing another company is refused', TZFD_Ai_Free_Core::hosted_call( 'faq.weekly', array(), $CO )['error'] === 'owner_scope_mismatch' );
unset( $GLOBALS['tzfd_test_filters'][ TZFD_Ai_Free_Core::HOSTED_FILTER ] );
ok( 'no connector gives connector_unavailable', TZFD_Ai_Free_Core::hosted_call( 'faq.weekly', array(), $CO )['error'] === 'connector_unavailable' );
ok( 'ungrounded: $5 is not grounded by $50', count( TZFD_Ai_Free_Core::ungrounded_figures( 'Fee $5.', array( 'Fee $50' ) ) ) === 1 );
ok( 'grounded figure passes', TZFD_Ai_Free_Core::ungrounded_figures( 'Call-out $80.', array( 'Call-out fee $80.' ) ) === array() );
ok( 'invented hours flagged', count( TZFD_Ai_Free_Core::ungrounded_figures( 'Open 8:00am to 5:00pm.', array( 'Boiler repair.' ) ) ) === 2 );
reset_state();
TZFD_Ai_Free_Core::set_consent( true, 1 );
TZFD_Ai_Free_Core::set_consent( false, 2 );
ok( 'withdrawing consent fires the purge action', count( fired( TZFD_Ai_Free_Core::CONSENT_WITHDRAWN_ACTION ) ) === 1 );

/* ---------- #35 weekly FAQ suggestions ---------- */
reset_state();
$themes = array( array( 'label' => 'boiler repair', 'count' => 9 ), array( 'label' => 'drain unblocking', 'count' => 4 ), array( 'label' => 'zero', 'count' => 0 ) );
$r = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW, $themes, $FACTS );
ok( 'weekly batch needs consent and writes no state without it', $r['status'] === 'consent_required' && hosted_calls() === 0 && ! isset( $GLOBALS['tzfd_test_options'][ 'tz_fd_ai_faq_batch_' . $CO . '_2026-W47' ] ) );
grant();
$many = array(
	array( 'question' => 'Do you repair boilers?', 'answer' => 'Yes, boiler repair is offered. Call-out fee $80.', 'fact_ids' => array( 'svc-1' ) ),
	array( 'question' => 'Do you unblock drains?', 'answer' => 'Yes, we offer drain unblocking.', 'fact_ids' => array( 'svc-2' ) ),
	array( 'question' => 'What is the fee?', 'answer' => 'The fee is $5.', 'fact_ids' => array( 'svc-1' ) ), // ungrounded figure
	array( 'question' => 'Cancel policy?', 'answer' => 'Notice is needed.', 'fact_ids' => array( 'pol-9' ) ), // unknown fact
	array( 'question' => 'Any hours?', 'answer' => '<b>Open 8:00am</b> daily here.', 'fact_ids' => array( 'svc-2' ) ), // invented time
	array( 'question' => 'Question six here?', 'answer' => 'Six valid answer text.', 'fact_ids' => array( 'svc-2' ) ),
	array( 'question' => 'Question seven here?', 'answer' => 'Seven valid answer text.', 'fact_ids' => array( 'svc-2' ) ),
	array( 'question' => 'Question eight here?', 'answer' => 'Eight valid answer text.', 'fact_ids' => array( 'svc-2' ) ),
	array( 'question' => 'Question nine here?', 'answer' => 'Nine valid answer text.', 'fact_ids' => array( 'svc-2' ) ),
	array( 'question' => 'Question ten here?', 'answer' => 'Ten valid answer text.', 'fact_ids' => array( 'svc-2' ) ),
);
connector( static function ( $v, $env ) use ( $many ) { return reply( $env, array( 'faqs' => $many ), array( 'input_tokens' => 120, 'output_tokens' => 80 ) ); } );
$r = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW, $themes, $FACTS );
ok( 'batch stores at most five valid items (seven were valid)', $r['status'] === 'done' && $r['added'] === 5 );
ok( 'ungrounded, unknown-fact and invented-time items are dropped and not stored', count( TZFD_Ai_Faq_Suggestions::pending( $CO ) ) === 5 && ! in_array( 'What is the fee?', array_column( TZFD_Ai_Faq_Suggestions::pending( $CO ), 'question' ), true ) );
$r2 = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW + 3600, $themes, $FACTS );
ok( 'same week is idempotent: no second hosted call', $r2['status'] === 'already_ran' && hosted_calls() === 1 );
$r3 = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW + 7 * DAY_IN_SECONDS, $themes, $FACTS );
ok( 'next ISO week runs again', $r3['status'] === 'done' );
ok( 'suggestions never published on their own', TZFD_Ai_Knowledge_Builder::published( $CO ) === array() );

reset_state(); grant();
connector( static function ( $v, $env ) { return array( 'ok' => false, 'error' => 'provider_down' ); } );
$a = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW, $themes, $FACTS );
$b = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW, $themes, $FACTS );
$c = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW, $themes, $FACTS );
$d = TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW, $themes, $FACTS );
ok( 'provider down: retry, retry, then failed, then retry exhausted', $a['status'] === 'retry' && $b['status'] === 'retry' && $c['status'] === 'failed' && $d['status'] === 'retry_exhausted' );
ok( 'provider down: attempts are capped at three calls', hosted_calls() === 3 );
ok( 'provider down: no proposals stored', TZFD_Ai_Faq_Suggestions::pending( $CO ) === array() );

reset_state(); grant();
connector( static function ( $v, $env ) { return reply( $env, array( 'faqs' => array() ) ); } );
ok( 'no approved facts: skipped without a call', TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW + 14 * DAY_IN_SECONDS, $themes, array( array( 'id' => 'x', 'text' => 'y', 'approved' => false ) ) )['status'] === 'skipped' && hosted_calls() === 0 );
ok( 'no question themes: skipped without a call', TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW + 21 * DAY_IN_SECONDS, array(), $FACTS )['status'] === 'skipped' && hosted_calls() === 0 );

reset_state(); grant();
connector( static function ( $v, $env ) { return reply( $env, array( 'faqs' => array( array( 'question' => 'Do you unblock drains?', 'answer' => 'Yes, we offer drain unblocking.', 'fact_ids' => array( 'svc-2' ) ) ) ) ); } );
TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW, $themes, $FACTS );
$p = TZFD_Ai_Faq_Suggestions::pending( $CO )[0];
$tenant = TZFD_Ai_Faq_Suggestions::decide( 8, $p['id'], 'approve', $FACTS, array(), $NOW, 1 );
ok( 'proposal decision refuses another company', $tenant['error'] === 'not_found' );
$rej = TZFD_Ai_Faq_Suggestions::decide( $CO, $p['id'], 'reject', $FACTS, array(), $NOW, 1 );
ok( 'reject marks the proposal only', $rej['status'] === 'rejected' && TZFD_Ai_Knowledge_Builder::published( $CO ) === array() );
TZFD_Ai_Faq_Suggestions::run_weekly( $CO, $NOW + 7 * DAY_IN_SECONDS, $themes, $FACTS );
$p2 = TZFD_Ai_Faq_Suggestions::pending( $CO );
ok( 'second week lands as new pending proposal after rejection', count( $p2 ) === 1 );
$GLOBALS['tzfd_test_caps'] = false;
ok( 'approve needs edit capability', TZFD_Ai_Faq_Suggestions::decide( $CO, $p2[0]['id'], 'approve', $FACTS, array(), $NOW, 1 )['error'] === 'forbidden' );
$GLOBALS['tzfd_test_caps'] = true;
$bad = TZFD_Ai_Faq_Suggestions::decide( $CO, $p2[0]['id'], 'approve', $FACTS, array( 'answer' => 'Fee is $5 for you.' ), $NOW, 1 );
ok( 'edited answer with ungrounded figure is not published', $bad['error'] === 'not_publishable' && in_array( 'ungrounded_figures', $bad['reasons'], true ) );
$ok = TZFD_Ai_Faq_Suggestions::decide( $CO, $p2[0]['id'], 'approve', $FACTS, array( 'answer' => 'Yes, we offer drain unblocking. Please call us.' ), $NOW, 1 );
ok( 'approved edit publishes through knowledge builder', $ok['ok'] && $ok['status'] === 'approved' );
ok( 'approval publishes exactly one entry', count( TZFD_Ai_Knowledge_Builder::published( $CO ) ) === 1 );
ok( 'approving again is refused', TZFD_Ai_Faq_Suggestions::decide( $CO, $p2[0]['id'], 'approve', $FACTS, array(), $NOW, 1 )['error'] === 'already_decided' );

/* ---------- #36 gap detection ---------- */
reset_state();
$vocab = array( 'boiler repair', 'drain unblocking' );
ok( 'gap: no recording without consent', TZFD_Ai_Gap_Detection::record( $CO, 'boiler repair?', false, 0, $vocab, $NOW ) === 'consent_off' && TZFD_Ai_Gap_Detection::report( $CO, $NOW, 30, array(), array() )['total'] === 0 );
grant();
$r = TZFD_Ai_Gap_Detection::record( $CO, 'Can you do boiler repair on Sunday? my name is John 0412 345 678 john@example.com', false, 0, $vocab, $NOW );
ok( 'gap: first unanswered question recorded', $r === 'recorded' );
ok( 'gap: identical repeat within window is a duplicate', TZFD_Ai_Gap_Detection::record( $CO, 'Can you do boiler repair on Sunday? my name is John 0412 345 678 john@example.com', false, 0, $vocab, $NOW + 60 ) === 'duplicate' );
$stored = json_encode( $GLOBALS['tzfd_test_options'] );
ok( 'gap: no name, phone, email or raw question stored', false === strpos( $stored, 'John' ) && false === strpos( $stored, '0412' ) && false === strpos( $stored, 'john@' ) && false === strpos( $stored, 'Sunday' ) );
ok( 'gap: confident FAQ answer is answered', TZFD_Ai_Gap_Detection::record( $CO, 'Drain unblocking hours?', true, 0.9, $vocab, $NOW ) === 'answered' );
ok( 'gap: unmatched question is counted but not topic-labelled', TZFD_Ai_Gap_Detection::record( $CO, 'Where are you based?', false, 0, $vocab, $NOW ) === 'unmatched' );
$rep = TZFD_Ai_Gap_Detection::report( $CO, $NOW, 30, array(), array() );
ok( 'gap: denominator counts every handled question (four here)', $rep['total'] === 4 && $rep['insufficient'] === true && $rep['unanswered_share'] === null );
ok( 'gap: topics under minimum count are not reported', $rep['topics'] === array() );
for ( $i = 0; $i < 6; $i++ ) { TZFD_Ai_Gap_Detection::record( $CO, "boiler repair quote question $i", false, 0, $vocab, $NOW + 1000 + $i * 700 ); }
TZFD_Ai_Gap_Detection::record( $CO, 'drain unblocking price', false, 0, $vocab, $NOW + 9000 );
$rep = TZFD_Ai_Gap_Detection::report( $CO, $NOW + 9000, 30, array(), array() );
ok( 'gap: report shows count and share over the denominator', $rep['topics'][0]['label'] === 'boiler repair' && $rep['topics'][0]['count'] === 7 && $rep['total'] === 11 && $rep['topics'][0]['share'] === round( 7 / 11, 4 ) );
ok( 'gap: coverage filter shows missing topics only', count( TZFD_Ai_Gap_Detection::report( $CO, $NOW + 9000, 30, array( 'coverage' => 'missing' ), array( 'We offer boiler repair.' ) )['topics'] ) === 0 );
$merge = TZFD_Ai_Gap_Detection::merge_topics( $CO, TZFD_Ai_Gap_Detection::key_for( 'drain unblocking' ), TZFD_Ai_Gap_Detection::key_for( 'boiler repair' ) );
ok( 'gap: merging topics adds the source counts into the target', $merge['ok'] === true && TZFD_Ai_Gap_Detection::report( $CO, $NOW + 9000, 30, array(), array() )['topics'][0]['count'] === 8 );
ok( 'gap: window is inclusive of recent days only', TZFD_Ai_Gap_Detection::report( $CO, $NOW + 60 * DAY_IN_SECONDS, 30, array(), array() )['total'] === 0 );
ok( 'gap: other companies are separate', TZFD_Ai_Gap_Detection::report( 8, $NOW, 30, array(), array() )['total'] === 0 );
TZFD_Ai_Free_Core::set_consent( false, $NOW );
ok( 'gap: consent withdrawn purges all gap data', ! isset( $GLOBALS['tzfd_test_options']['tz_fd_ai_gaps'] ) );

/* ---------- #39 knowledge builder ---------- */
reset_state(); $GLOBALS['tzfd_test_caps'] = false;
ok( 'kb: editing refused without capability', TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Do you do boilers?', 'Yes, boiler repair.', array( 'svc-1' ), $NOW, 1 )['error'] === 'forbidden' );
$GLOBALS['tzfd_test_caps'] = true;
$s1 = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Do you repair boilers?', 'Yes, boiler repair is offered.', array( 'svc-1' ), $NOW, 1 );
ok( 'kb: new entry starts as draft and is not served', $s1['ok'] && $s1['status'] === 'draft' && TZFD_Ai_Knowledge_Builder::published( $CO ) === array() );
$s2 = TZFD_Ai_Knowledge_Builder::save_draft( $CO, $s1['id'], 'Do you repair boilers?', 'Yes, boiler repair is offered. Call-out fee $80.', array( 'svc-1' ), $NOW + 5, 1 );
ok( 'kb: each save appends a revision', $s2['ok'] && $s2['id'] === $s1['id'] );
$stored = get_option( TZFD_Ai_Knowledge_Builder::OPTION, array() )[ $CO ]['entries'][ $s1['id'] ];
ok( 'kb: revision history keeps every saved state', count( $stored['revisions'] ) === 2 && $stored['revisions'][0]['answer'] === 'Yes, boiler repair is offered.' );
$pub_bad = TZFD_Ai_Knowledge_Builder::publish( $CO, $s1['id'], array( array( 'id' => 'svc-1', 'text' => 'Boiler repair.', 'approved' => false ) ), $NOW, 1 );
ok( 'kb: publish refused for unapproved source', $pub_bad['ok'] === false && in_array( 'no_approved_source', $pub_bad['reasons'], true ) );
$pub = TZFD_Ai_Knowledge_Builder::publish( $CO, $s1['id'], $FACTS, $NOW, 1 );
ok( 'kb: valid publish serves the entry and bumps version', $pub['ok'] && $pub['version'] === 1 && count( TZFD_Ai_Knowledge_Builder::published( $CO ) ) === 1 );
ok( 'kb: publish fires cache-invalidation action with new version', count( fired( TZFD_Ai_Knowledge_Builder::CHANGED_ACTION ) ) === 1 && fired( TZFD_Ai_Knowledge_Builder::CHANGED_ACTION )[0][1] === array( $CO, 1 ) );
TZFD_Ai_Knowledge_Builder::save_draft( $CO, $s1['id'], 'Do you repair boilers?', 'Yes, boiler repair is offered for homes.', array( 'svc-1' ), $NOW + 10, 1 );
ok( 'kb: editing published text unpublishes it and invalidates caches', TZFD_Ai_Knowledge_Builder::published( $CO ) === array() && TZFD_Ai_Knowledge_Builder::version( $CO ) === 2 && count( fired( TZFD_Ai_Knowledge_Builder::CHANGED_ACTION ) ) === 2 );
$rb = TZFD_Ai_Knowledge_Builder::rollback( $CO, $s1['id'], 1, $NOW + 20, 1 );
$stored = get_option( TZFD_Ai_Knowledge_Builder::OPTION, array() )[ $CO ]['entries'][ $s1['id'] ];
ok( 'kb: rollback restores an earlier revision as a draft and keeps history', $rb['ok'] && $rb['status'] === 'draft' && $stored['answer'] === 'Yes, boiler repair is offered.' && count( $stored['revisions'] ) === 5 );
ok( 'kb: rollback to a missing revision is refused', TZFD_Ai_Knowledge_Builder::rollback( $CO, $s1['id'], 99, $NOW, 1 )['error'] === 'revision_not_found' );

reset_state(); $GLOBALS['tzfd_test_caps'] = true;
$x = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Is it safe? <script>alert(1)</script>', 'Yes <img src=x onerror=alert(2)> we are insured.', array( 'svc-1' ), $NOW, 1 );
$e = get_option( TZFD_Ai_Knowledge_Builder::OPTION, array() )[ $CO ]['entries'][ $x['id'] ];
$html = TZFD_Ai_Knowledge_Builder::render( $e );
ok( 'kb: stored text has no markup', false === strpos( $e['question'] . $e['answer'], '<' ) && false === strpos( $e['question'] . $e['answer'], '>' ) );
ok( 'kb: rendered output is escaped, no live script or handler', false === strpos( $html, '<script' ) && false === strpos( $html, 'onerror=' ) );
ok( 'kb: tenant boundary: other company sees no entries', TZFD_Ai_Knowledge_Builder::published( 8 ) === array() );

reset_state(); $GLOBALS['tzfd_test_caps'] = true;
$a1 = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Do you do drains?', 'Yes, we unblock drains.', array( 'svc-2' ), $NOW, 1 );
$a2 = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Do you do drains?', 'No, we do not.', array( 'svc-2' ), $NOW, 1 );
$a3 = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Cancel?', 'Notice is required.', array( 'svc-2' ), $NOW, 1 );
ok( 'kb: flags same question with different answers as conflict', count( array_filter( TZFD_Ai_Knowledge_Builder::flags( $CO, $FACTS ), static function ( $f ) { return 'conflict' === $f['type']; } ) ) === 2 );
TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Hours?', 'TBD', array( 'svc-2' ), $NOW, 1 );
ok( 'kb: flags placeholder answer as missing details', count( array_filter( TZFD_Ai_Knowledge_Builder::flags( $CO, $FACTS ), static function ( $f ) { return 'missing_details' === $f['type']; } ) ) >= 1 );

reset_state(); grant(); $GLOBALS['tzfd_test_caps'] = true;
$sg = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Do you repair boilers?', 'Yes, boiler repair is offered.', array( 'svc-1' ), $NOW, 1 );
connector( static function ( $v, $env ) { return reply( $env, array( 'suggestion' => 'Yes, boiler repair. Call-out fee $80.' ) ); } );
$sug = TZFD_Ai_Knowledge_Builder::store_suggestion( $CO, $sg['id'], $FACTS, $NOW );
$after = get_option( TZFD_Ai_Knowledge_Builder::OPTION, array() )[ $CO ]['entries'][ $sg['id'] ];
ok( 'kb: AI suggestion stored beside entry, text unchanged', $sug['ok'] && $after['answer'] === 'Yes, boiler repair is offered.' && $after['suggestion']['text'] === 'Yes, boiler repair. Call-out fee $80.' );
connector( static function ( $v, $env ) { return reply( $env, array( 'suggestion' => 'Fee is $999.' ) ); } );
ok( 'kb: ungrounded AI suggestion rejected', TZFD_Ai_Knowledge_Builder::store_suggestion( $CO, $sg['id'], $FACTS, $NOW )['error'] === 'ungrounded_suggestion' );
connector( static function ( $v, $env ) { return array( 'ok' => false, 'error' => 'provider_down' ); } );
ok( 'kb: provider down leaves the entry alone', TZFD_Ai_Knowledge_Builder::store_suggestion( $CO, $sg['id'], $FACTS, $NOW )['error'] === 'provider_down' );

/* ---------- #40 setup check ---------- */
reset_state();
$empty = array( 'business_name' => '', 'greeting' => '', 'services' => array(), 'hours_configured' => false, 'faq_questions' => array(), 'tags' => array(), 'connector_available' => false );
$miss = array_column( TZFD_Ai_Setup_Check::missing( $empty ), 'key' );
ok( 'setup: empty site lists every missing item', in_array( 'business_name', $miss, true ) && in_array( 'services', $miss, true ) && in_array( 'hours', $miss, true ) && in_array( 'hosted_connector', $miss, true ) );
ok( 'setup: AI gate closed on empty site', TZFD_Ai_Setup_Check::ai_gate( $empty )['allowed'] === false );
ok( 'setup: no suggestions and no fatal on empty or absent provider', TZFD_Ai_Setup_Check::suggestions( $empty ) === array() );
ok( 'setup: AI stays off by default', TZFD_Ai_Free_Core::consent_granted() === false );
$enable_blocked = TZFD_Ai_Setup_Check::enable_ai( $empty, true, $NOW );
ok( 'setup: enabling AI lists missing configuration', $enable_blocked['ok'] === false && in_array( 'business_name', $enable_blocked['missing'], true ) && TZFD_Ai_Free_Core::consent_granted() === false );

$fresh = array( 'business_name' => 'Harbour Plumbing', 'greeting' => '', 'services' => array( 'Boiler repair', 'Drain unblocking' ), 'hours_configured' => true, 'faq_questions' => array(), 'tags' => array(), 'connector_available' => true );
$sgs = TZFD_Ai_Setup_Check::suggestions( $fresh );
ok( 'setup: fresh site gets greeting, service FAQs and tags', count( array_filter( $sgs, static function ( $s ) { return 'greeting' === $s['type']; } ) ) === 1 && count( array_filter( $sgs, static function ( $s ) { return 'faq' === $s['type']; } ) ) === 2 && count( array_filter( $sgs, static function ( $s ) { return 'tags' === $s['type']; } ) ) === 1 );
ok( 'setup: suggestion values contain no invented hours or prices', preg_match( '/\d|\$|\b(am|pm)\b/i', json_encode( array_column( $sgs, 'value' ) ) ) === 0 );
ok( 'setup: every suggestion needs confirmation', array_reduce( $sgs, static function ( $c, $s ) { return $c && $s['needs_confirmation'] === true; }, true ) );
$upgraded = $fresh;
$upgraded['greeting'] = 'Welcome to Harbour';
$upgraded['faq_questions'] = array( 'Do you offer boiler repair?' );
$upgraded['tags'] = array( 'boiler-repair' );
$up = TZFD_Ai_Setup_Check::suggestions( $upgraded );
ok( 'setup: upgraded site keeps existing greeting, FAQ and tag', ! in_array( 'greeting', array_column( $up, 'type' ), true ) && count( array_filter( $up, static function ( $s ) { return 'faq' === $s['type'] && 'do you offer boiler repair?' === strtolower( $s['value']['question'] ); } ) ) === 0 && count( array_filter( $up, static function ( $s ) { return 'tags' === $s['type'] && in_array( 'boiler-repair', $s['value'], true ); } ) ) === 0 );
ok( 'setup: AI gate passes once required items exist', TZFD_Ai_Setup_Check::ai_gate( $fresh )['allowed'] === true );
ok( 'setup: enabling AI needs admin confirmation', TZFD_Ai_Setup_Check::enable_ai( $fresh, false, $NOW )['error'] === 'admin_confirmation_required' && TZFD_Ai_Free_Core::consent_granted() === false );
ok( 'setup: confirmed enable records consent', TZFD_Ai_Setup_Check::enable_ai( $fresh, true, $NOW )['ok'] === true && TZFD_Ai_Free_Core::consent_granted() === true );
ok( 'setup: confirm refuses without admin confirmation', TZFD_Ai_Setup_Check::confirm( $sgs[1], array() )['error'] === 'admin_confirmation_required' );
$drain = array_values( array_filter( $sgs, static function ( $s ) { return 'faq' === $s['type'] && 'service:Drain unblocking' === $s['source']; } ) )[0];
$inv = TZFD_Ai_Setup_Check::confirm( $drain, array( 'confirmed' => true, 'answer' => 'Yes, we offer Drain unblocking from $60.' ) );
ok( 'setup: confirm refuses an invented price', $inv['ok'] === false && $inv['error'] === 'ungrounded_figures' );
$okc = TZFD_Ai_Setup_Check::confirm( $drain, array( 'confirmed' => true ) );
ok( 'setup: confirmed FAQ is written only as admin-approved text', $okc['ok'] === true && $okc['value']['question'] === 'Do you offer Drain unblocking?' );

/* ---------- #41 monthly insights ---------- */
class FakeDb {
	public $prefix = 'wp_';
	public $rows = array();
	public $last_args = null;
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function get_results( $prepared, $output = null ) { $this->last_args = $prepared; return $this->rows; }
}
ok( 'insights: invalid month refused', TZFD_Ai_Monthly_Insights::month_window( '2026-13' ) === null && TZFD_Ai_Monthly_Insights::month_window( 'Oct 2026' ) === null );
$w = TZFD_Ai_Monthly_Insights::month_window( '2026-12' );
ok( 'insights: December window ends at 1 January', $w['start'] === '2026-12-01 00:00:00' && $w['end'] === '2027-01-01 00:00:00' );
$db = new FakeDb();
$db->rows = array(
	array( 'status' => 'new', 'source' => 'web', 'n' => 30 ),
	array( 'status' => 'closed', 'source' => 'phone', 'n' => 12 ),
	array( 'status' => 'new', 'source' => 'fax', 'n' => 3 ),
);
$agg = TZFD_Ai_Monthly_Insights::aggregate( $db, $CO, '2026-11' );
ok( 'insights: aggregate counts the whole base and buckets unknown sources as other', $agg['base'] === 45 && $agg['by_source']['other'] === 3 );
ok( 'insights: aggregate is company-scoped with a bounded window and selects no PII', $db->last_args['args'][1] === $CO && $db->last_args['args'][2] === '2026-11-01 00:00:00' && false === stripos( $db->last_args['sql'], 'name' ) && false === stripos( $db->last_args['sql'], 'email' ) && false === stripos( $db->last_args['sql'], 'enquiry' ) );
$small = TZFD_Ai_Monthly_Insights::local_report( '2026-11', array( 'base' => 6, 'by_status' => array( 'new' => 6 ), 'by_source' => array( 'web' => 6 ) ), array() );
ok( 'insights: small base suppresses the report', $small['suppressed'] === true && false !== strpos( $small['text'][0], 'Too few' ) );
$extra = array( array( 'section' => 'Unanswered FAQ topics', 'rows' => array(
	array( 'label' => 'boiler repair', 'count' => 9, 'base' => 45 ),
	array( 'label' => 'tiny', 'count' => 2, 'base' => 45 ),
	array( 'label' => 'no base', 'count' => 9, 'base' => 4 ),
) ) );
$loc = TZFD_Ai_Monthly_Insights::local_report( '2026-11', $agg, $extra );
$topics = array_values( array_filter( $loc['sections'], static function ( $s ) { return 'Unanswered FAQ topics' === $s['section']; } ) )[0]['rows'];
ok( 'insights: rows below minimum cell or base are omitted, not shown', count( $topics ) === 1 && $loc['omitted'] === 2 && $topics[0]['share'] === round( 9 / 45, 4 ) );
ok( 'insights: local report always produced with denominators', $loc['base'] === 45 && $loc['sections'][0]['rows'][0]['base'] === 45 );

reset_state();
$db2 = new FakeDb(); $db2->rows = $db->rows;
$no_consent = TZFD_Ai_Monthly_Insights::report( $CO, '2026-11', $db2, $extra, $NOW );
ok( 'insights: local report works without consent or connector', $no_consent['ok'] && $no_consent['summary'] === null && $no_consent['summary_status'] === 'consent_required' && $no_consent['local']['base'] === 45 );
grant();
connector( static function ( $v, $env ) { return reply( $env, array( 'summary' => 'Enquiries were 45 this month. Boiler repair was 9 of 45 (20.0%).' ), array( 'input_tokens' => 300, 'output_tokens' => 60 ) ); } );
$calls_before = hosted_calls();
$good = TZFD_Ai_Monthly_Insights::report( $CO, '2026-11', $db2, $extra, $NOW );
ok( 'insights: grounded summary accepted', $good['summary_status'] === 'ok' && is_string( $good['summary'] ) );
TZFD_Ai_Monthly_Insights::report( $CO, '2026-11', $db2, $extra, $NOW );
ok( 'insights: one hosted summary per month (generation budget)', hosted_calls() === $calls_before + 1 );
reset_state(); grant();
connector( static function ( $v, $env ) { return reply( $env, array( 'summary' => 'Boiler repair was 120% of enquiries.' ) ); } );
$bad = TZFD_Ai_Monthly_Insights::report( $CO, '2026-11', $db2, $extra, $NOW );
ok( 'insights: summary with a figure not in the report is rejected (no fabrication)', $bad['summary'] === null && $bad['summary_status'] === 'ungrounded_summary' && $bad['local']['base'] === 45 );
reset_state(); grant();
connector( static function ( $v, $env ) { return array( 'ok' => false, 'error' => 'provider_down' ); } );
$f1 = TZFD_Ai_Monthly_Insights::report( $CO, '2026-11', $db2, $extra, $NOW );
$f2 = TZFD_Ai_Monthly_Insights::report( $CO, '2026-11', $db2, $extra, $NOW );
$f3 = TZFD_Ai_Monthly_Insights::report( $CO, '2026-11', $db2, $extra, $NOW );
ok( 'insights: provider down falls back to local report with bounded retries', $f1['summary_status'] === 'provider_down' && $f2['summary_status'] === 'provider_down' && $f3['summary_status'] === 'retry_exhausted' && $f3['local']['base'] === 45 && hosted_calls() === 2 );

/* ---------- #36 answer-flow wiring, consent, retention, merges, page helpers ---------- */
if ( ! class_exists( 'TZFD_Scoped_Store' ) ) {
	class TZFD_Scoped_Store { public static $company = 0; public static function company_id() { return self::$company; } }
}
reset_state();
$G = 7; $T = time(); $DAY = DAY_IN_SECONDS;
$VOCAB = array( 'boiler repair', 'drain unblocking' );
TZFD_Scoped_Store::$company = 0;
do_action( 'tzfd_ai_question_handled', 'boiler repair please', array( 'status' => 'refused', 'source' => 'none' ) );
ok( 'wiring: no company selected means nothing is recorded', ! isset( $GLOBALS['tzfd_test_options']['tz_fd_ai_gaps'] ) );
grant();
TZFD_Scoped_Store::$company = $G;
do_action( 'tzfd_ai_question_handled', 'Do you do boiler repair?', array( 'status' => 'answered', 'source' => 'approved_rule' ) );
$rep = TZFD_Ai_Gap_Detection::report( $G, $T, 30, array(), array() );
ok( 'wiring: approved-rule answer counts as handled, not as a gap', $rep['total'] === 1 && $rep['unanswered'] === 0 );
do_action( 'tzfd_ai_question_handled', 'Where are you based?', array( 'status' => 'refused', 'source' => 'none' ) );
do_action( 'tzfd_ai_question_handled', 'Tell me a joke', array( 'status' => 'limited', 'source' => 'none' ) );
do_action( 'tzfd_ai_question_handled', 'Any offers this week', array( 'status' => 'answered', 'source' => 'model' ) );
$rep = TZFD_Ai_Gap_Detection::report( $G, $T, 30, array(), array() );
ok( 'wiring: refusals, limits and model answers count as unanswered', $rep['total'] === 4 && $rep['unanswered'] === 3 );
ok( 'wiring: offline proxy fallback (refusal) makes no hosted call', hosted_calls() === 0 );
do_action( 'tzfd_ai_question_handled', 'Where are you based?', array( 'status' => 'refused', 'source' => 'none' ) );
$rep = TZFD_Ai_Gap_Detection::report( $G, $T, 30, array(), array() );
ok( 'dedupe: the same unanswered question inside the window adds to total only', $rep['total'] === 5 && $rep['unanswered'] === 3 );
TZFD_Scoped_Store::$company = 0;
TZFD_Ai_Gap_Detection::on_handled( 'x', array( 'status' => 'answered', 'source' => 'approved_rule' ) );
TZFD_Scoped_Store::$company = $G;
$GLOBALS['tzfd_test_options']['tz_fd_ai_consent'] = array( 'granted' => false, 'at' => $T );
$before = json_encode( $GLOBALS['tzfd_test_options']['tz_fd_ai_gaps'] ?? null );
do_action( 'tzfd_ai_question_handled', 'boiler repair now', array( 'status' => 'refused', 'source' => 'none' ) );
ok( 'consent withdrawn: no question is recorded after withdrawal', json_encode( $GLOBALS['tzfd_test_options']['tz_fd_ai_gaps'] ?? null ) === $before );
ok( 'consent withdrawn: report shows nothing even if old data is still stored', TZFD_Ai_Gap_Detection::report( $G, $T, 30, array(), array() )['total'] === 0 );
grant();

/* Topic grouping, count accuracy and retention */
reset_state(); grant();
for ( $i = 0; $i < 4; $i++ ) { TZFD_Ai_Gap_Detection::record( $G, "boiler repair price $i", false, 0, $VOCAB, $T + $i * 700 ); }
for ( $i = 0; $i < 3; $i++ ) { TZFD_Ai_Gap_Detection::record( $G, "drain unblocking hours $i", false, 0, $VOCAB, $T + 5000 + $i * 700 ); }
$rep = TZFD_Ai_Gap_Detection::report( $G, $T + 9000, 30, array(), array() );
ok( 'count: topic counts are exact (4 boiler, 3 drain)', $rep['topics'][0]['count'] === 4 && $rep['topics'][1]['count'] === 3 && $rep['unanswered'] === 7 );
$k_boiler = TZFD_Ai_Gap_Detection::key_for( 'boiler repair' );
$k_drain  = TZFD_Ai_Gap_Detection::key_for( 'drain unblocking' );
ok( 'grouping: merging drain into boiler is allowed', TZFD_Ai_Gap_Detection::merge_topics( $G, $k_drain, $k_boiler )['ok'] === true );
ok( 'grouping: merging back creates a loop and is refused', TZFD_Ai_Gap_Detection::merge_topics( $G, $k_boiler, $k_drain ) === array( 'ok' => false, 'error' => 'merge_cycle' ) );
ok( 'grouping: merging a topic into itself is refused', TZFD_Ai_Gap_Detection::merge_topics( $G, $k_boiler, $k_boiler )['ok'] === false );
ok( 'grouping: merging an unknown topic is refused', TZFD_Ai_Gap_Detection::merge_topics( $G, 'ffffffffffffffff', $k_boiler )['ok'] === false );
ok( 'grouping: merged topic shows the combined count', TZFD_Ai_Gap_Detection::report( $G, $T + 9000, 30, array(), array() )['topics'][0]['count'] === 7 );
TZFD_Ai_Gap_Detection::record( $G, 'boiler repair much later', false, 0, $VOCAB, $T + 40 * $DAY );
$stored = $GLOBALS['tzfd_test_options']['tz_fd_ai_gaps'][ $G ];
ok( 'retention: day counts older than the retention window are dropped on write', array_keys( $stored['days'] ) === array( gmdate( 'Y-m-d', $T + 40 * $DAY ) ) );
ok( 'retention: topics with no days left are dropped on write', ! isset( $stored['topics'][ $k_drain ] ) );
ok( 'retention: report after the window shows no old counts', TZFD_Ai_Gap_Detection::report( $G, $T + 80 * $DAY, 30, array(), array() )['total'] === 0 );

/* Filters and key validation (admin page helpers) */
$f = TZFD_Ai_Gap_Page::parse_filters( array( 'min_count' => '5000', 'search' => str_repeat( 'a', 100 ), 'coverage' => 'bogus' ) );
ok( 'page: min_count is clamped to 1000, search to 60 characters, unknown coverage ignored', $f['min_count'] === 1000 && strlen( $f['search'] ) === 60 && $f['coverage'] === '' );
$f = TZFD_Ai_Gap_Page::parse_filters( array( 'min_count' => '-3', 'coverage' => 'missing' ) );
ok( 'page: negative min_count becomes 0 and allowed coverage is kept', $f['min_count'] === 0 && $f['coverage'] === 'missing' );
ok( 'page: only 16 lowercase hex characters are valid topic keys', TZFD_Ai_Gap_Page::valid_key( $k_boiler ) && ! TZFD_Ai_Gap_Page::valid_key( '../etc/passwd' ) && ! TZFD_Ai_Gap_Page::valid_key( strtoupper( $k_boiler ) ) && ! TZFD_Ai_Gap_Page::valid_key( array( 'x' ) ) );

/* ---------- result ---------- */
echo "Passed: {$GLOBALS['tzfd_pass']}  Failed: {$GLOBALS['tzfd_fail']}\n";
exit( $GLOBALS['tzfd_fail'] > 0 ? 1 : 0 );
