<?php
/**
 * Unit tests for TZFD_FAQ_Retrieval (issue #21). Runs without WordPress by stubbing the few WP functions used.
 * Run: php -I tests/unit/faq-retrieval-tests.php  (from the worktree root)
 * Not shipped in the plugin.
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['opts'] = array();
$GLOBALS['tr']   = array();
$GLOBALS['filters'] = array();
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opts'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['opts'][ $n ] ); return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function absint( $v ) { return abs( (int) $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function apply_filters( $tag, $value, ...$args ) {
    foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); }
    return $value;
}
function add_filter( $tag, $cb ) { $GLOBALS['filters'][ $tag ][] = $cb; }
function reset_filters() { $GLOBALS['filters'] = array(); }

require dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-scoped-store.php';
require dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-faq-retrieval.php';

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['pass']++; } else { $GLOBALS['fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . PHP_EOL;
}
function reset_all() { $GLOBALS['opts'] = array(); $GLOBALS['tr'] = array(); reset_filters(); }

$R = 'TZFD_FAQ_Retrieval';
$A = 1; // Tenant A
$B = 2; // Tenant B

$faq = array(
    array( 'id' => 'emergency', 'question' => 'Do you offer emergency plumbing on weekends?',
        'answer' => 'Yes, we offer urgent plumbing call-outs seven days a week.', 'keywords' => 'plumbing,emergency,weekend,urgent',
        'exclude' => 'cancel', 'approved' => true ),
    array( 'id' => 'hours', 'question' => 'What are your opening hours?',
        'answer' => 'We are open 8am to 6pm, Monday to Friday.', 'keywords' => 'hours,open,time', 'approved' => true ),
    array( 'id' => 'cancel', 'question' => 'How do I cancel or reschedule a booking?',
        'answer' => 'Use the link in your confirmation email to cancel or reschedule.', 'keywords' => 'cancel,reschedule,booking', 'approved' => true ),
    array( 'id' => 'draft', 'question' => 'Do you do gas fitting?', 'answer' => 'Unapproved draft answer.',
        'keywords' => 'gas', 'approved' => false ),
);

/* ---------- Normalisation and tokens ---------- */
check( 'normalize lower-cases, strips punctuation and tags',
    $R::normalize( '  <b>Hello</b>, WHAT\'s   the PRICE?! ' ) === 'hello whats the price' ,
    $R::normalize( '  <b>Hello</b>, WHAT\'s   the PRICE?! ' ) );
check( 'normalize rejects non-string input', $R::normalize( array( 'x' ) ) === '' && $R::normalize( null ) === '' );
check( 'normalize turns NUL and newlines into separators (no words fused)', $R::normalize( "pl\0umb\ning" ) === 'pl umb ing', $R::normalize( "pl\0umb\ning" ) );
check( 'tokens: prices and cost synonyms map to cost', $R::tokens( 'prices fee' ) === array( 'cost' ) , implode( ',', $R::tokens( 'prices fee' ) ) );
check( 'tokens: stop words dropped', $R::tokens( 'do you offer the plumbing' ) === array( 'offer', 'plumbing' ), implode( ',', $R::tokens( 'do you offer the plumbing' ) ) );
check( 'tokens: negation kept as not (negative cases)', in_array( 'not', $R::tokens( 'do you not charge' ), true ) );
check( "tokens: don't maps to not and charge maps to cost", $R::tokens( $R::normalize( "don't charge" ) ) === array( 'not', 'cost' ), implode( ',', $R::tokens( $R::normalize( "don't charge" ) ) ) );
check( 'tokens: word ending in ss is not stemmed', $R::tokens( 'glass' ) === array( 'glass' ) );

/* ---------- Tenant rules and index ---------- */
reset_all();
check( 'no tenant (company 0) returns none, never reads data', $R::answer( 0, 'opening hours' )['status'] === 'none' );
check( 'save_entries refuses company 0', false === $R::save_entries( 0, $faq ) );
check( 'index_version is 0 before any save', 0 === $R::index_version( $A ) );

$n = $R::save_entries( $A, $faq );
check( 'save keeps all four sanitised entries', 4 === $n, (string) $n );
check( 'save bumps index version to 1', 1 === $R::index_version( $A ) );
check( 'approved_entries excludes the unapproved draft', 3 === count( $R::approved_entries( $A ) ) );

/* ---------- Keyword answers ---------- */
$r = $R::answer( $A, 'Do you do emergency plumbing on the weekend?' );
check( 'keyword path answers a confident query', 'answer' === $r['status'] && 'keyword' === $r['path'] && 'emergency' === $r['entry_id'], json_encode( $r ) );
check( 'answer text is the owner-approved text, no generation', 'Yes, we offer urgent plumbing call-outs seven days a week.' === $r['answer'] );
check( 'synonym gap: price question with no cost entry clarifies instead of answering with hours', 'clarify' === $R::answer( $A, 'what is the price' )['status'], json_encode( $R::answer( $A, 'what is the price' ) ) );
$r = $R::answer( $A, 'What time are you open?' );
check( 'synonym: "time/open" matches hours entry', 'hours' === $r['entry_id'] && 'answer' === $r['status'], json_encode( $r ) );
check( 'synonym: opening hours phrasing answers', 'answer' === $R::answer( $A, 'opening hours please' )['status'] );

/* ---------- Negative and synonym-gap cases ---------- */
$r = $R::answer( $A, 'Do you NOT do emergency plumbing?' );
check( 'negated query does not return the affirmative answer confidently', 'answer' !== $r['status'] || 'emergency' !== $r['entry_id'], json_encode( $r ) );
$r = $R::answer( $A, 'cancel my emergency plumbing' );
check( 'exclude term suppresses the entry (cancel in query)', 'emergency' !== $r['entry_id'], json_encode( $r ) );
$r = $R::answer( $A, 'Do you do gas fitting?' );
check( 'unapproved draft entry is never answered', '' === $r['answer'] && 'gas' !== $r['entry_id'] );
$r = $R::answer( $A, 'Can I buy a horse?' );
check( 'off-topic query asks for clarification, not an answer', 'clarify' === $r['status'] && 'low_confidence' === $r['reason'] && '' === $r['answer'], json_encode( $r ) );
$sug = $R::answer( $A, 'cancel my plumbing' );
check( 'clarification suggestions are approved questions only (no draft text)', ! in_array( 'Do you do gas fitting?', $sug['suggestions'], true ) && ( empty( $sug['suggestions'] ) || in_array( 'How do I cancel or reschedule a booking?', $sug['suggestions'], true ) || in_array( 'Do you offer emergency plumbing on weekends?', $sug['suggestions'], true ) ), json_encode( $sug ) );

/* ---------- Input hardening ---------- */
check( 'empty query asks for clarification', 'clarify' === $R::answer( $A, '   ' )['status'] && 'empty_query' === $R::answer( $A, '   ' )['reason'] );
check( 'non-string query asks for clarification', 'clarify' === $R::answer( $A, array( 'x' ) )['status'] );
$long = str_repeat( 'emergency ', 60 );
check( 'overlong query is refused with clarification', 'query_too_long' === $R::answer( $A, $long )['reason'] );
$inj = $R::answer( $A, "<script>alert(1)</script> emergency plumbing ' OR 1=1 --" );
check( 'markup and SQL-like input never reaches the output', false === strpos( json_encode( $inj ), '<script' ) && false === strpos( $inj['answer'], '<' ), json_encode( $inj ) );
check( 'junk tokens dilute coverage, so the noisy query asks for clarification rather than guessing', 'clarify' === $inj['status'], json_encode( $inj ) );

/* ---------- Cache: hit, invalidation, tenant isolation ---------- */
reset_all();
$R::save_entries( $A, $faq );
$first  = $R::answer( $A, 'opening hours' );
$second = $R::answer( $A, 'opening hours' );
check( 'first call is keyword path', 'keyword' === $first['path'] );
check( 'second identical call is a cache hit', 'cache' === $second['path'] && $second['entry_id'] === $first['entry_id'] );

$R::save_entries( $A, array( array( 'id' => 'hours', 'question' => 'What are your opening hours?', 'answer' => 'Now 9 to 5.', 'keywords' => 'hours,open', 'approved' => true ) ) );
$after = $R::answer( $A, 'opening hours' );
check( 'update invalidates cache (version changed, fresh answer)', 'keyword' === $after['path'] && 'Now 9 to 5.' === $after['answer'], json_encode( $after ) );

$R::delete_entry( $A, 'hours' );
$gone = $R::answer( $A, 'opening hours' );
check( 'delete invalidates cache: deleted answer no longer returned', '' === $gone['answer'] && 'hours' !== $gone['entry_id'], json_encode( $gone ) );
check( 'delete of unknown id returns false', false === $R::delete_entry( $A, 'nope' ) );

reset_all();
$R::save_entries( $A, $faq );
$R::save_entries( $B, array( array( 'id' => 'hours', 'question' => 'What are your opening hours?', 'answer' => 'Tenant B hours.', 'keywords' => 'hours', 'approved' => true ) ) );
$ra = $R::answer( $A, 'opening hours' );
$rb = $R::answer( $B, 'opening hours' );
check( 'tenant A answers with A text', 'We are open 8am to 6pm, Monday to Friday.' === $ra['answer'], $ra['answer'] );
check( 'tenant B answers with B text (no leakage)', 'Tenant B hours.' === $rb['answer'], $rb['answer'] );
check( 'tenant B cannot see A-only entry', '' === $R::answer( $B, 'emergency plumbing weekend' )['answer'] );
check( 'deleting in tenant B leaves tenant A intact', $R::delete_entry( $B, 'hours' ) && 'answer' === $R::answer( $A, 'opening hours' )['status'] );
check( 'tenant B consent is separate from tenant A', false === $R::has_embedding_consent( $B ) && false === $R::has_embedding_consent( $A ) );

/* ---------- Embedding stage: consent, cap, privacy, provider failure ---------- */
reset_all();
$R::save_entries( $A, $faq );
$calls = 0;
add_filter( 'tzfd_faq_embedding_provider', function ( $v, $q, $questions ) use ( &$calls ) {
    $calls++;
    // Query embeds close to the hours entry; entries listed in input order.
    $vecs = array_map( function ( $qq ) use ( $q ) { return stripos( $qq, 'hours' ) !== false ? array( 1.0, 0.0 ) : array( 0.0, 1.0 ); }, $questions );
    return array( 'query' => array( 0.99, 0.05 ), 'entries' => $vecs );
} );
$r = $R::answer( $A, 'when do you trade' );
check( 'no consent: embedding provider never called', 0 === $calls && 'clarify' === $r['status'], "calls=$calls reason={$r['reason']}" );

$R::set_embedding_consent( $A, true );
$r = $R::answer( $A, 'when do you trade' );
check( 'consent + low keyword score: embedding path answers', 'answer' === $r['status'] && 'embedding' === $r['path'] && 'hours' === $r['entry_id'], json_encode( $r ) );
check( 'embedding call counted for this month', 1 === $R::embedding_calls_this_month( $A ) );

$r = $R::answer( $A, 'Email me at jane@example.com about a quote' );
check( 'private email in query is never sent to provider', 'private_detail_not_sent' === $r['reason'] && 1 === $R::embedding_calls_this_month( $A ), json_encode( $r ) );
$r = $R::answer( $A, 'call me on 0412 345 678 when you trade' );
check( 'phone-like digits in query are never sent to provider', 'private_detail_not_sent' === $r['reason'] );

$calls = 0;
$r = $R::answer( $A, 'when do you trade' ); // Cached from earlier.
check( 'repeat of an embedding answer is served from cache, no new call', 'cache' === $r['path'] && 0 === $calls );

$R::set_embedding_consent( $A, false );
$r = $R::answer( $A, 'when do you trade' );
check( 'revoking consent stops the cached embedding answer from being served', 'cache' !== $r['path'] && 'clarify' === $r['status'], json_encode( $r ) );

/* Monthly cap */
reset_all();
$R::save_entries( $A, $faq );
$R::set_embedding_consent( $A, true );
add_filter( 'tzfd_faq_embedding_provider', function ( $v, $q, $questions ) {
    return array( 'query' => array( 0.0, 1.0 ), 'entries' => array_map( function () { return array( 1.0, 0.0 ); }, $questions ) );
} );
$capHit = null;
for ( $i = 0; $i < 205; $i++ ) {
    $r = $R::answer( $A, "unmatched widget number $i" );
    if ( 'monthly_embedding_cap' === $r['reason'] ) { $capHit = $i; break; }
}
check( 'monthly cap stops embedding calls at 200', 200 === $R::embedding_calls_this_month( $A ) && 200 === $capHit, 'calls=' . $R::embedding_calls_this_month( $A ) . ' capAt=' . var_export( $capHit, true ) );

/* Proxy down: provider returns nothing, throws, or returns malformed data */
reset_all();
$R::save_entries( $A, $faq );
$R::set_embedding_consent( $A, true );
add_filter( 'tzfd_faq_embedding_provider', function () { return null; } );
$r = $R::answer( $A, 'when do you trade' );
check( 'proxy returns null: clarification, no crash', 'proxy_unavailable' === $r['reason'] && 'clarify' === $r['status'] );

reset_all();
$R::save_entries( $A, $faq );
$R::set_embedding_consent( $A, true );
add_filter( 'tzfd_faq_embedding_provider', function () { throw new \RuntimeException( 'timeout' ); } );
$r = $R::answer( $A, 'when do you trade' );
check( 'proxy throws: clarification, keyword path still works for confident queries', 'proxy_unavailable' === $r['reason'] && 'answer' === $R::answer( $A, 'emergency plumbing weekend' )['status'] );

reset_all();
$R::save_entries( $A, $faq );
$R::set_embedding_consent( $A, true );
add_filter( 'tzfd_faq_embedding_provider', function () { return array( 'query' => array( 1, 0 ), 'entries' => array( 'bad' ) ); } );
$r = $R::answer( $A, 'when do you trade' );
check( 'malformed provider output is rejected', 'proxy_unavailable' === $r['reason'] );

/* Confident keyword answers never call the provider */
reset_all();
$R::save_entries( $A, $faq );
$R::set_embedding_consent( $A, true );
$hit = 0;
add_filter( 'tzfd_faq_embedding_provider', function ( $v ) use ( &$hit ) { $hit++; return $v; } );
$R::answer( $A, 'Do you offer emergency plumbing on weekends?' );
check( 'confident keyword answer does not call the embedding provider', 0 === $hit );

/* Helpers */
check( 'cosine of identical vectors is 1', abs( $R::cosine( array( 1, 2 ), array( 1, 2 ) ) - 1.0 ) < 1e-9 );
check( 'cosine rejects zero or mismatched vectors', null === $R::cosine( array( 0, 0 ), array( 1, 1 ) ) && null === $R::cosine( array( 1 ), array( 1, 2 ) ) );

echo PHP_EOL . "Passed: {$GLOBALS['pass']}  Failed: {$GLOBALS['fail']}" . PHP_EOL;
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
