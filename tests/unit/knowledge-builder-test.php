<?php
/**
 * Unit tests for the knowledge builder editor (#144, #257): editor permissions, revision history, rollback,
 * publish gate, XSS-safe authoring and render, tenant isolation, conflict and missing-detail flags, and
 * grounding of AI suggestions.
 * Run: php tests/unit/knowledge-builder-test.php
 * Pure PHP with WordPress function stubs (wp-stubs.php). No database, no network, no live hosted service:
 * the hosted connector is a test double registered on the request filter.
 */
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-knowledge-builder.php';

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo "FAIL: {$name}\n";
}
function reset_state( $can_edit = true ) {
	$GLOBALS['tzfd_test_options']      = array();
	$GLOBALS['tzfd_test_filters']      = array();
	$GLOBALS['tzfd_test_filter_calls'] = array();
	$GLOBALS['tzfd_test_fired']        = array();
	$GLOBALS['tzfd_test_caps']         = $can_edit;
	TZFD_Ai_Free_Core::set_consent( false, 0 );
}
function raw_company( $cid ) {
	$all = get_option( TZFD_Ai_Knowledge_Builder::OPTION, array() );
	return $all[ (int) $cid ] ?? array( 'version' => 0, 'entries' => array() );
}
function changed_fired_count() {
	$n = 0;
	foreach ( $GLOBALS['tzfd_test_fired'] as $f ) {
		if ( TZFD_Ai_Knowledge_Builder::CHANGED_ACTION === $f[0] ) { $n++; }
	}
	return $n;
}

$NOW   = gmmktime( 12, 0, 0, 11, 18, 2026 );
$CO    = 7;
$OTHER = 8;
$FLAGS_CO = 9;
$FACTS = array(
	array( 'id' => 'svc-1', 'text' => 'Boiler repair. Call-out fee $80.', 'approved' => true ),
	array( 'id' => 'svc-9', 'text' => 'Unapproved draft service. Call-out fee $999.', 'approved' => false ),
);

/* ---------- editor permissions ---------- */
reset_state( false );
$denied = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'How much is a call-out?', 'The call-out fee is $80.', array( 'svc-1' ), $NOW, 1 );
ok( 'permissions: save_draft refused without the editor capability', ( $denied['error'] ?? '' ) === 'forbidden' );
ok( 'permissions: refused save writes nothing', empty( get_option( TZFD_Ai_Knowledge_Builder::OPTION, array() ) ) );
ok( 'permissions: publish refused without the editor capability', ( TZFD_Ai_Knowledge_Builder::publish( $CO, 'kb-missing', $FACTS, $NOW, 1 )['error'] ?? '' ) === 'forbidden' );
ok( 'permissions: rollback refused without the editor capability', ( TZFD_Ai_Knowledge_Builder::rollback( $CO, 'kb-missing', 1, $NOW, 1 )['error'] ?? '' ) === 'forbidden' );

/* ---------- drafts, revisions and publish gate ---------- */
reset_state( true );
$made = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'How much is a call-out?', 'The call-out fee is $80.', array( 'svc-1' ), $NOW, 1 );
ok( 'draft: a new entry is saved as a draft', ( $made['ok'] ?? false ) && 'draft' === ( $made['status'] ?? '' ) );
$id = $made['id'] ?? '';
ok( 'draft: first save writes revision 1', count( raw_company( $CO )['entries'][ $id ]['revisions'] ?? array() ) === 1 );
ok( 'draft: a draft is not visible to visitors', TZFD_Ai_Knowledge_Builder::published( $CO ) === array() );

$pub_blocked = TZFD_Ai_Knowledge_Builder::publish( $CO, $id, array(), $NOW, 1 );
ok( 'publish gate: no approved source blocks publishing', ( $pub_blocked['error'] ?? '' ) === 'not_publishable' && in_array( 'no_approved_source', $pub_blocked['reasons'], true ) );
$unapproved_src = TZFD_Ai_Knowledge_Builder::publish( $CO, $id, array( array( 'id' => 'svc-1', 'text' => 'Boiler repair. Call-out fee $80.', 'approved' => false ) ), $NOW, 1 );
ok( 'publish gate: a fact the owner has not approved is not a source', in_array( 'no_approved_source', $unapproved_src['reasons'] ?? array(), true ) );

$pub = TZFD_Ai_Knowledge_Builder::publish( $CO, $id, $FACTS, $NOW + 1, 1 );
ok( 'publish: approved source publishes the draft', ( $pub['ok'] ?? false ) && 1 === ( $pub['version'] ?? 0 ) );
ok( 'publish: visitors see exactly the published entry', count( TZFD_Ai_Knowledge_Builder::published( $CO ) ) === 1 );
ok( 'publish: bumps the answer-cache version once and fires the change action', 1 === changed_fired_count() );

$ungrounded = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'Is the call-out cheaper?', 'Call-out is $5 today.', array( 'svc-1' ), $NOW + 2, 1 );
$bad_pub = TZFD_Ai_Knowledge_Builder::publish( $CO, $ungrounded['id'], $FACTS, $NOW + 3, 1 );
ok( 'publish gate: an invented figure is refused', in_array( 'ungrounded_figures', $bad_pub['reasons'] ?? array(), true ) );
$placeholder = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'What are the opening hours?', 'TBD, check later.', array( 'svc-1' ), $NOW + 4, 1 );
$ph_pub = TZFD_Ai_Knowledge_Builder::publish( $CO, $placeholder['id'], $FACTS, $NOW + 5, 1 );
ok( 'publish gate: placeholder text is refused', in_array( 'placeholder_text', $ph_pub['reasons'] ?? array(), true ) );

/* ---------- editing a published entry unpublishes it ---------- */
reset_state( true );
$made = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'How much is a call-out?', 'The call-out fee is $80.', array( 'svc-1' ), $NOW, 1 );
$id   = $made['id'];
TZFD_Ai_Knowledge_Builder::publish( $CO, $id, $FACTS, $NOW + 1, 1 );
$edit = TZFD_Ai_Knowledge_Builder::save_draft( $CO, $id, 'How much is a call-out?', 'The call-out fee is $80 on weekdays.', array( 'svc-1' ), $NOW + 2, 2 );
ok( 'edit: changing published text returns it to draft', ( $edit['status'] ?? '' ) === 'draft' );
ok( 'edit: visitors stop seeing the edited text at once', TZFD_Ai_Knowledge_Builder::published( $CO ) === array() );
ok( 'edit: the version bumps so caches drop', TZFD_Ai_Knowledge_Builder::version( $CO ) === 2 );
$revs = raw_company( $CO )['entries'][ $id ]['revisions'];
ok( 'history: every save appends a revision and none are rewritten', count( $revs ) === 3 && array_column( $revs, 'n' ) === array( 1, 2, 3 ) );
ok( 'history: each revision records who saved it', array_column( $revs, 'by' ) === array( 1, 1, 2 ) );

/* ---------- rollback ---------- */
$rb = TZFD_Ai_Knowledge_Builder::rollback( $CO, $id, 1, $NOW + 3, 2 );
ok( 'rollback: restores an earlier revision into a new draft', ( $rb['ok'] ?? false ) && 'draft' === ( $rb['status'] ?? '' ) );
$after = raw_company( $CO )['entries'][ $id ];
ok( 'rollback: restores the earlier answer text', 'The call-out fee is $80.' === $after['answer'] );
ok( 'rollback: history grows and keeps the old revisions', count( $after['revisions'] ) === 4 && $after['revisions'][0]['answer'] === 'The call-out fee is $80.' );
ok( 'rollback: unknown revision is refused', ( TZFD_Ai_Knowledge_Builder::rollback( $CO, $id, 99, $NOW, 2 )['error'] ?? '' ) === 'revision_not_found' );
ok( 'rollback: a rolled-back entry is not published until approved again', TZFD_Ai_Knowledge_Builder::published( $CO ) === array() );

/* ---------- XSS-safe authoring and render ---------- */
reset_state( true );
$xss = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, '<script>alert(1)</script>Boiler question', '<img src=x onerror=alert(1)>Yes we fix boilers.', array( 'svc-1' ), $NOW, 1 );
$stored = raw_company( $CO )['entries'][ $xss['id'] ];
ok( 'xss: stored question and answer carry no markup', false === strpos( $stored['question'], '<' ) && false === strpos( $stored['answer'], '<' ) );
TZFD_Ai_Knowledge_Builder::publish( $CO, $xss['id'], $FACTS, $NOW + 1, 1 );
$html = TZFD_Ai_Knowledge_Builder::render( TZFD_Ai_Knowledge_Builder::published( $CO )[0] );
ok( 'xss: rendered entry has no script or event-handler markup', false === stripos( $html, '<script' ) && false === stripos( $html, 'onerror' ) );
$manual = TZFD_Ai_Knowledge_Builder::render( array( 'question' => '"><script>x</script>', 'answer' => "Tom & Jerry's \"fix\" <b>now</b>" ) );
ok( 'xss: render escapes angle brackets, quotes and ampersands', false === strpos( $manual, '<script>' ) && false !== strpos( $manual, '&lt;script&gt;' ) && false !== strpos( $manual, '&amp;' ) );

/* ---------- tenant isolation ---------- */
reset_state( true );
$mine = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'How much is a call-out?', 'The call-out fee is $80.', array( 'svc-1' ), $NOW, 1 );
TZFD_Ai_Knowledge_Builder::publish( $CO, $mine['id'], $FACTS, $NOW + 1, 1 );
$v_before = TZFD_Ai_Knowledge_Builder::version( $OTHER );
ok( 'tenant: another company cannot publish this entry', ( TZFD_Ai_Knowledge_Builder::publish( $OTHER, $mine['id'], $FACTS, $NOW + 2, 1 )['error'] ?? '' ) === 'not_found' );
ok( 'tenant: another company cannot roll this entry back', ( TZFD_Ai_Knowledge_Builder::rollback( $OTHER, $mine['id'], 1, $NOW + 2, 1 )['error'] ?? '' ) === 'not_found' );
ok( 'tenant: another company cannot edit this entry', ( TZFD_Ai_Knowledge_Builder::save_draft( $OTHER, $mine['id'], 'How much is a call-out?', 'Changed by someone else.', array(), $NOW + 2, 1 )['error'] ?? '' ) === 'not_found' );
ok( 'tenant: another company sees none of this entry', TZFD_Ai_Knowledge_Builder::published( $OTHER ) === array() );
ok( 'tenant: a refused cross-company action leaves the owner entry unchanged', 'The call-out fee is $80.' === raw_company( $CO )['entries'][ $mine['id'] ]['answer'] );
ok( 'tenant: a refused cross-company action does not bump the other version', TZFD_Ai_Knowledge_Builder::version( $OTHER ) === $v_before );

/* ---------- conflict and missing-detail flags ---------- */
reset_state( true );
TZFD_Ai_Knowledge_Builder::save_draft( $FLAGS_CO, null, 'What are your hours?', 'Open 8am to 5pm.', array( 'svc-1' ), $NOW, 1 );
TZFD_Ai_Knowledge_Builder::save_draft( $FLAGS_CO, null, '  what are your HOURS? ', 'Open 9am to 6pm.', array( 'svc-1' ), $NOW + 1, 1 );
$blank = TZFD_Ai_Knowledge_Builder::save_draft( $FLAGS_CO, null, 'Do you do pools?', 'TBD', array( 'svc-1' ), $NOW + 2, 1 );
$fig   = TZFD_Ai_Knowledge_Builder::save_draft( $FLAGS_CO, null, 'What does a repair cost?', 'Repairs start at $450.', array( 'svc-1' ), $NOW + 3, 1 );
$flags = TZFD_Ai_Knowledge_Builder::flags( $FLAGS_CO, $FACTS );
$by_type = array();
foreach ( $flags as $f ) { $by_type[ $f['type'] ][] = $f['id']; }
ok( 'flags: differing answers to the same question are both flagged as conflicts', count( $by_type['conflict'] ?? array() ) === 2 );
ok( 'flags: placeholder answer is flagged as missing details', in_array( $blank['id'], $by_type['missing_details'] ?? array(), true ) );
ok( 'flags: figure with no source is flagged as ungrounded', in_array( $fig['id'], $by_type['ungrounded_figures'] ?? array(), true ) );

/* ---------- AI suggestions never change the entry ---------- */
reset_state( true );
TZFD_Ai_Free_Core::set_consent( true, 1 );
$sug = TZFD_Ai_Knowledge_Builder::save_draft( $CO, null, 'How much is a call-out?', 'The call-out fee is $80.', array( 'svc-1' ), $NOW, 1 );
$no_conn = TZFD_Ai_Knowledge_Builder::store_suggestion( $CO, $sug['id'], $FACTS, $NOW + 1 );
ok( 'suggestion: with no connector the call fails closed', ( $no_conn['ok'] ?? true ) === false );
add_filter( TZFD_Ai_Free_Core::HOSTED_FILTER, static function ( $value, $envelope ) {
	$text = (int) $envelope['company_id'] === 7 ? 'Call-out fee $999 on weekdays.' : 'Call-out fee $80.';
	return array( 'ok' => true, 'company_id' => $envelope['company_id'], 'data' => array( 'suggestion' => $text ), 'usage' => array() );
} );
$invented = TZFD_Ai_Knowledge_Builder::store_suggestion( $CO, $sug['id'], $FACTS, $NOW + 2 );
ok( 'suggestion: an invented figure in the suggestion is rejected', ( $invented['error'] ?? '' ) === 'ungrounded_suggestion' );
ok( 'suggestion: the entry text is unchanged after a suggestion', 'The call-out fee is $80.' === raw_company( $CO )['entries'][ $sug['id'] ]['answer'] );
add_filter( TZFD_Ai_Free_Core::HOSTED_FILTER, static function ( $value, $envelope ) {
	return array( 'ok' => true, 'company_id' => $envelope['company_id'], 'data' => array( 'suggestion' => 'Our call-out fee is $80.' ), 'usage' => array() );
} );
$grounded = TZFD_Ai_Knowledge_Builder::store_suggestion( $CO, $sug['id'], $FACTS, $NOW + 3 );
ok( 'suggestion: a grounded suggestion is stored beside the entry', ( $grounded['ok'] ?? false ) && 'Our call-out fee is $80.' === ( raw_company( $CO )['entries'][ $sug['id'] ]['suggestion']['text'] ?? '' ) );
ok( 'suggestion: storing a suggestion does not change the entry answer', 'The call-out fee is $80.' === raw_company( $CO )['entries'][ $sug['id'] ]['answer'] );
TZFD_Ai_Free_Core::set_consent( false, 0 );

/* ---------- result ---------- */
echo "Passed: {$GLOBALS['tzfd_pass']}  Failed: {$GLOBALS['tzfd_fail']}\n";
exit( $GLOBALS['tzfd_fail'] > 0 ? 1 : 0 );
