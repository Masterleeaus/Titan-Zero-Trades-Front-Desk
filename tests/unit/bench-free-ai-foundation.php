<?php
/**
 * Benchmark for the Free AI foundation (#42). Not part of the assertion runner. Run by hand:
 *   php tests/unit/bench-free-ai-foundation.php
 * Reports cold (rules, cache miss) and warm (exact cache hit) answer time, and peak memory, for the 100-entry
 * pilot sizing target and a 1000-entry stress size. The 100-entry figure is a hosted pilot target, not a local
 * limit: the store has no cap. Numbers depend on the host; this script asserts nothing about speed.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
require __DIR__ . '/bootstrap.php';

$clock   = 1767225600;
$now     = static function () use ( &$clock ) { return $clock; };
$manager = static function () { return true; };

/** Builds one tenant with $entries approved FAQ entries. */
function bench_build( $entries ) {
    global $now, $manager;
    $mem   = new TZFD_Test_Memory_Storage();
    $store = new TZFD_Knowledge_Store( $mem, 7, $manager );
    $facts = new TZFD_Business_Facts( $mem, 7, $manager, $now );
    $cache = new TZFD_Answer_Cache( new TZFD_Test_Memory_Cache(), 600, $now );
    $search = new TZFD_FAQ_Search( $store, $facts, $cache, $mem, null, array( 'now' => $now, 'can_manage' => $manager, 'site' => 1 ) );
    $topics = array( 'call out fee', 'gutter cleaning', 'weekend hours', 'emergency plumbing', 'oven repair', 'pool service' );
    for ( $i = 0; $i < $entries; $i++ ) {
        $topic = $topics[ $i % count( $topics ) ] . ' item ' . $i;
        $store->save_entry( array(
            'question' => 'Do you offer ' . $topic . '?',
            'answer'   => 'Yes, answer number ' . $i . '.',
            'keywords' => array( 'item' . $i, $topics[ $i % count( $topics ) ] ),
            'status'   => 'approved',
        ) );
    }
    return array( 'store' => $store, 'search' => $search, 'mem' => $mem );
}

foreach ( array( 100, 1000 ) as $size ) {
    $mem_before = memory_get_usage();
    $t0         = microtime( true );
    $b          = bench_build( $size );
    $build_ms   = ( microtime( true ) - $t0 ) * 1000;

    $queries = array();
    for ( $i = 0; $i < 200; $i++ ) {
        $queries[] = 'do you offer item' . ( ( $i * 7 ) % $size ) . ' repair';
    }

    $t1 = microtime( true );
    $miss_calls = 0;
    foreach ( $queries as $q ) {
        $r = $b['search']->answer( $q );
        $miss_calls += $r['model_calls'];
    }
    $cold_ms = ( microtime( true ) - $t1 ) * 1000;

    $t2 = microtime( true );
    $hits = 0;
    foreach ( $queries as $q ) {
        $r = $b['search']->answer( $q );
        if ( 'hit' === $r['cache'] ) { $hits++; }
    }
    $warm_ms = ( microtime( true ) - $t2 ) * 1000;

    $mem_used_mb = ( memory_get_usage() - $mem_before ) / 1048576;
    $peak_mb     = memory_get_peak_usage() / 1048576;

    printf(
        "entries=%-5d build=%.1fms  cold(200 q)=%.1fms (%.3fms/q)  warm(200 q)=%.1fms  cache_hits=%d  model_calls=%d  heap_delta=%.2fMB  peak=%.2fMB\n",
        $size, $build_ms, $cold_ms, $cold_ms / 200, $warm_ms, $hits, $miss_calls, $mem_used_mb, $peak_mb
    );
}
