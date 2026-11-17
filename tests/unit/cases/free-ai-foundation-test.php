<?php
/**
 * Free AI foundation gap tests (#42). Traceability: this file exercises the shared preconditions that #20, #21,
 * #22, #28, #36 and #39 depend on (answer path, knowledge store, cache, facts). It reuses fs_build(), fs_entry()
 * and TZFD_Test_Provider from faq-search-test.php, which the runner loads first (alphabetical glob order).
 *
 * Covers: interleaved writers (conflict, no lost update), version monotonicity, vector pruning after delete,
 * partial index progress when the proxy drops, zero model calls on known requests with the proxy down,
 * negative-recall phrasings, and fact-driven cache invalidation.
 */

global $fs_now, $manager;

/** Provider that answers for the first $ok_calls embeds, then reports the proxy as unavailable. */
final class TZFD_Test_Flaky_Provider implements TZFD_Embedding_Provider {
    public $calls = 0;
    private $ok_calls;
    public function __construct( $ok_calls ) { $this->ok_calls = (int) $ok_calls; }
    public function embed( $text ) {
        $this->calls++;
        if ( $this->calls > $this->ok_calls ) { return null; }
        return array( 1.0, 0.0, 0.0 );
    }
}

/* ---- interleaved writers: two managers load the same version ---- */
$iw = fs_build();
$id = fs_entry( $iw['store'], 'Do you charge a call out fee?', 'It is $80.', array( 'call out', 'fee' ) );
$v0 = $iw['store']->version();
$writer_a = $iw['store']->save_entry( array( 'id' => $id, 'question' => 'Do you charge a call out fee?', 'answer' => 'A says $90.', 'keywords' => array( 'call out', 'fee' ), 'status' => 'approved' ), $v0 );
$writer_b = $iw['store']->save_entry( array( 'id' => $id, 'question' => 'Do you charge a call out fee?', 'answer' => 'B says $95.', 'keywords' => array( 'call out', 'fee' ), 'status' => 'approved' ), $v0 );
t_eq( 'saved', $writer_a['code'], 'first writer holding the current version is saved' );
t_eq( 'conflict', $writer_b['code'], 'second writer holding the same stale version is refused' );
t_eq( 'A says $90.', $iw['store']->approved()[ $id ]['answer'], 'stale writer does not overwrite the first writer (no lost update)' );
t_eq( $v0 + 1, $iw['store']->version(), 'refused write does not bump the version' );
$retry = $iw['store']->save_entry( array( 'id' => $id, 'question' => 'Do you charge a call out fee?', 'answer' => 'B says $95.', 'keywords' => array( 'call out', 'fee' ), 'status' => 'approved' ), $iw['store']->version() );
t_eq( 'saved', $retry['code'], 'retry with the reloaded version succeeds' );
t_eq( $v0 + 2, $iw['store']->version(), 'version increments once per successful write' );

/* ---- version monotonicity across create, edit, delete and a failed delete ---- */
$mono = fs_build();
$m1   = fs_entry( $mono['store'], 'Do you offer gutter cleaning?', 'Yes.', array( 'gutters' ) );
$seen = array( $mono['store']->version() );
$mono['store']->save_entry( array( 'id' => $m1, 'question' => 'Do you offer gutter cleaning?', 'answer' => 'Yes, twice a year.', 'keywords' => array( 'gutters' ), 'status' => 'approved' ) );
$seen[] = $mono['store']->version();
$mono['store']->delete_entry( $m1 );
$seen[] = $mono['store']->version();
$mono['store']->delete_entry( $m1 );
$seen[] = $mono['store']->version();
t_eq( array( 1, 2, 3, 3 ), $seen, 'version rises by one per successful write and holds on a failed write' );

/* ---- vector pruning after delete ---- */
$pr        = fs_build();
$prov      = new TZFD_Test_Provider();
$search_pr = new TZFD_FAQ_Search( $pr['store'], $pr['facts'], $pr['cache'], $pr['mem'], $prov, array( 'now' => $fs_now, 'can_manage' => $manager, 'site' => 1 ) );
$keep = fs_entry( $pr['store'], 'Do you offer gutter cleaning?', 'Yes.', array( 'gutters' ) );
$gone = fs_entry( $pr['store'], 'Do you charge a call out fee?', 'Yes, $80.', array( 'call out', 'fee' ) );
$first_index = $search_pr->index_entries();
t_eq( 2, $first_index['embedded'], 'both approved entries are embedded on the first run' );
$pr['store']->delete_entry( $gone );
$second_index = $search_pr->index_entries();
t_eq( 1, $second_index['pruned'], 'the deleted entry vector is pruned from the index' );
$stored_index = $pr['mem']->read( TZFD_FAQ_Search::VECTOR_KEY, 7, array() );
t_ok( isset( $stored_index[ $keep ] ) && ! isset( $stored_index[ $gone ] ), 'index keeps the live entry and drops the deleted one' );

/* ---- partial progress when the proxy drops mid-index ---- */
$pp        = fs_build();
$flaky     = new TZFD_Test_Flaky_Provider( 1 );
$search_pp = new TZFD_FAQ_Search( $pp['store'], $pp['facts'], $pp['cache'], $pp['mem'], $flaky, array( 'now' => $fs_now, 'can_manage' => $manager, 'site' => 1 ) );
fs_entry( $pp['store'], 'Do you offer gutter cleaning?', 'Yes.', array( 'gutters' ) );
fs_entry( $pp['store'], 'Do you charge a call out fee?', 'Yes, $80.', array( 'call out', 'fee' ) );
$p1 = $search_pp->index_entries();
t_eq( 'proxy_unavailable', $p1['code'], 'a proxy that drops during indexing is reported' );
t_eq( 1, $p1['embedded'], 'the one vector built before the drop is counted' );
t_eq( 1, count( $pp['mem']->read( TZFD_FAQ_Search::VECTOR_KEY, 7, array() ) ), 'the vector built before the drop is kept for the next run' );
$flaky->calls = 0;
$flaky_back   = new TZFD_Test_Flaky_Provider( 10 );
$search_back  = new TZFD_FAQ_Search( $pp['store'], $pp['facts'], $pp['cache'], $pp['mem'], $flaky_back, array( 'now' => $fs_now, 'can_manage' => $manager, 'site' => 1 ) );
$p2 = $search_back->index_entries();
t_eq( 'indexed', $p2['code'], 'the next run completes once the proxy is back' );
t_eq( 1, $p2['embedded'], 'the next run embeds only the missing entry' );

/* ---- zero model calls on known requests, with the proxy down ---- */
$zm        = fs_build();
$prov_zm   = new TZFD_Test_Provider();
$search_zm = new TZFD_FAQ_Search( $zm['store'], $zm['facts'], $zm['cache'], $zm['mem'], $prov_zm, array( 'now' => $fs_now, 'can_manage' => $manager, 'site' => 1 ) );
fs_entry( $zm['store'], 'Do you charge a call out fee?', 'Yes, the call out fee is $80.', array( 'call out', 'fee' ) );
fs_entry( $zm['store'], 'Are you open on Saturdays?', 'We are open Saturdays 8am to 1pm.', array( 'weekend' ) );
$search_zm->index_entries();
$prov_zm->throw = true;
$prov_zm->calls = 0;
$known = array( 'call out fee', 'How much is the call out fee?', 'what does the call out cost', 'CALL OUT FEE!!', 'Are you open on Saturdays?' );
$all_answered = true;
$all_zero     = true;
foreach ( array_merge( $known, $known ) as $q ) { // second pass exercises the cache path
    $r = $search_zm->answer( $q );
    if ( 'answered' !== $r['status'] ) { $all_answered = false; }
    if ( 0 !== $r['model_calls'] ) { $all_zero = false; }
}
t_ok( $all_answered, 'every known request is answered from approved content' );
t_ok( $all_zero, 'known answered requests report zero model calls, including repeats' );
t_eq( 0, $prov_zm->calls, 'known requests never reach the hosted provider' );

/* ---- negative recall: negated phrasings never receive the affirmative answer ---- */
$nr = fs_build();
fs_entry( $nr['store'], 'Do you charge a call out fee?', 'Yes, the call out fee is $80.', array( 'call out', 'fee' ) );
$negated_queries = array(
    'Do you not charge a call out fee?',
    "Don't you charge a call out fee?",
    'you dont charge a call out fee',
    'Is there no call out fee?',
    'no call out fee',
    'call out fee never',
);
$leaked = array();
foreach ( $negated_queries as $q ) {
    $r = $nr['search']->answer( $q );
    if ( 'answered' === $r['status'] && 'Yes, the call out fee is $80.' === $r['answer'] ) { $leaked[] = $q; }
}
t_eq( array(), $leaked, 'no negated phrasing receives the affirmative fee answer' );

/* ---- a published fact edit invalidates the cached answer ---- */
$fc = fs_build();
$fc['mem']->write( 'tz_fd_ai_service_area', 7, 'Richmond and Fitzroy' );
$fc['facts']->publish( 'service_area' );
$fa1 = $fc['search']->answer( 'which suburbs do you cover' );
$fa2 = $fc['search']->answer( 'which suburbs do you cover' );
t_eq( 'miss', $fa1['cache'], 'first fact answer is a cache miss' );
t_eq( 'hit', $fa2['cache'], 'repeat fact answer is a cache hit' );
$fc['mem']->write( 'tz_fd_ai_service_area', 7, 'Richmond, Fitzroy and Carlton' );
$fa3 = $fc['search']->answer( 'which suburbs do you cover' );
t_eq( 'no_answer', $fa3['status'], 'an edited fact is no longer served until it is published again' );
t_ok( null === $fa3['answer'] && 'manual' === $fa3['fallback'], 'the stale fact gives the manual fallback, not the old value' );
$fc['facts']->publish( 'service_area' );
$fa4 = $fc['search']->answer( 'which suburbs do you cover' );
t_eq( 'miss', $fa4['cache'], 'republishing the edited fact invalidates the earlier cached answer' );
t_ok( false !== strpos( (string) $fa4['answer'], 'Carlton' ), 'the answer after republishing carries the new value' );
