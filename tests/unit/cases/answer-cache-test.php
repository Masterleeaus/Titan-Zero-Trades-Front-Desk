<?php
/** Exact answer cache: hit/miss, version invalidation, tenant isolation, TTL, negative answers, key shape. */

$clock = 1000;
$now   = static function () use ( &$clock ) { return $clock; };
$mem   = new TZFD_Test_Memory_Cache();
$cache = new TZFD_Answer_Cache( $mem, 600, $now );

$answer = array( 'status' => 'answered', 'answer' => 'Our call out fee is $80.', 'source' => array( 'type' => 'faq', 'id' => 'faq_a' ) );

t_eq( null, $cache->get( 1, 7, 'r1', 'en', 'call out fee?' ), 'miss before any put' );
t_ok( $cache->put( 1, 7, 'r1', 'en', 'call out fee?', $answer ), 'answered faq result is stored' );
t_eq( $answer, $cache->get( 1, 7, 'r1', 'en', 'call out fee?' ), 'exact repeat is a hit' );

// Normalised query: case, punctuation and spacing do not create a new key.
t_eq( $answer, $cache->get( 1, 7, 'r1', 'en', '  CALL out   FEE!! ' ), 'normalised variant is a hit' );
t_eq( TZFD_Answer_Cache::key( 1, 7, 'r1', 'en', 'call out fee?' ), TZFD_Answer_Cache::key( 1, 7, 'r1', 'en', 'Call out fee' ), 'normalised keys match' );

// Every key component separates entries.
t_eq( null, $cache->get( 2, 7, 'r1', 'en', 'call out fee?' ), 'different site misses' );
t_eq( null, $cache->get( 1, 8, 'r1', 'en', 'call out fee?' ), 'different tenant misses' );
t_eq( null, $cache->get( 1, 7, 'r1', 'fr', 'call out fee?' ), 'different language misses' );
t_eq( null, $cache->get( 1, 7, 'r2', 'en', 'call out fee?' ), 'different knowledge version misses' );

// Version invalidation: a stored answer whose version no longer matches is not served, even if the key collides.
$stored_key = TZFD_Answer_Cache::key( 1, 7, 'r1', 'en', 'call out fee?' );
$mem->items[ $stored_key ]['knowledge_version'] = 'r0';
t_eq( null, $cache->get( 1, 7, 'r1', 'en', 'call out fee?' ), 'stale stored version is refused' );
t_eq( false, isset( $mem->items[ $stored_key ] ), 'stale entry is removed on read' );

// TTL: an entry older than the TTL is a miss.
$cache->put( 1, 7, 'r1', 'en', 'opening hours', array( 'status' => 'answered', 'answer' => 'Open 8 to 5.', 'source' => array( 'type' => 'fact', 'id' => 'business_hours' ) ) );
$clock += 599;
t_ok( null !== $cache->get( 1, 7, 'r1', 'en', 'opening hours' ), 'entry inside TTL is served' );
$clock += 2;
t_eq( null, $cache->get( 1, 7, 'r1', 'en', 'opening hours' ), 'entry past TTL is a miss' );

// Negative and uncertain results are never stored.
t_eq( false, $cache->put( 1, 7, 'r1', 'en', 'refund?', array( 'status' => 'no_answer', 'source' => null ) ), 'no_answer is not cached' );
t_eq( false, $cache->put( 1, 7, 'r1', 'en', 'refund?', array( 'status' => 'clarify', 'source' => null ) ), 'clarify is not cached' );
t_eq( false, $cache->put( 1, 7, 'r1', 'en', 'refund?', array( 'status' => 'answered', 'source' => array( 'type' => 'llm' ) ) ), 'answers without a grounded source are not cached' );
t_eq( null, $cache->get( 1, 7, 'r1', 'en', 'refund?' ), 'negative results are not served from cache' );

// Explicit forget removes a single answer.
$cache->forget( 1, 7, 'r1', 'en', 'call out fee?' );
t_eq( null, $cache->get( 1, 7, 'r1', 'en', 'call out fee?' ), 'forget removes the answer' );

// Keys carry no raw query text and stay within transient key length limits.
$k = TZFD_Answer_Cache::key( 1, 7, 'r1', 'en', 'my email is jane@example.com' );
t_ok( false === strpos( $k, 'jane' ) && strlen( $k ) <= 172, 'key hides query text and fits transient limits' );
