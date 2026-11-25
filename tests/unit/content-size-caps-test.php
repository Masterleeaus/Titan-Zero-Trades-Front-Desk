<?php
/**
 * Issue #330: content-size caps, as approved by the owner (2026-11-22). Pins main's values exactly.
 *
 * Decision pinned here:
 *  - Business fact value: 2000 characters. A longer value is cut at 2000 and the cut value is what is stored and served.
 *  - FAQ answer: 2000 characters. FAQ question 300, keywords 20 per entry of 40 characters each.
 *  - FAQ entry payload (its JSON form): at most 8192 bytes. An entry over that is refused whole, never truncated.
 *  - Counts are in characters, not bytes, except the payload cap, which is in bytes.
 *  - No local total cap on FAQ entries per site. Business facts are bounded by their four-key allowlist.
 *
 * Pure PHP, in-memory storage, no WordPress needed: php tests/unit/content-size-caps-test.php
 * Exits 1 if any check fails.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

$plugin_inc = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/';
foreach ( array(
	'class-tzfd-intent-engine.php',
	'class-tzfd-tenant-storage.php',
	'class-tzfd-knowledge-store.php',
	'class-tzfd-business-facts.php',
) as $file ) {
	require_once $plugin_inc . $file;
}

final class TZFD_Size_Caps_Memory_Storage implements TZFD_Tenant_Storage {
	public $data = array();
	public function read( $key, $company, $default ) {
		return array_key_exists( $company . '|' . $key, $this->data ) ? $this->data[ $company . '|' . $key ] : $default;
	}
	public function write( $key, $company, $value ) {
		$this->data[ $company . '|' . $key ] = $value;
		return true;
	}
}

$fails  = 0;
$passes = 0;
$check  = function ( $ok, $name ) use ( &$fails, &$passes ) {
	if ( $ok ) { ++$passes; } else { ++$fails; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
};

$manager = static function () { return true; };
$now     = static function () { return 1700000000; };

$faq = static function () use ( $manager ) {
	return new TZFD_Knowledge_Store( new TZFD_Size_Caps_Memory_Storage(), 1, $manager );
};

/* ---- FAQ entries: per-field caps (characters) ---- */
$check( 300 === TZFD_Knowledge_Store::MAX_QUESTION, 'FAQ question cap is 300 characters' );
$check( 2000 === TZFD_Knowledge_Store::MAX_ANSWER, 'FAQ answer cap is 2000 characters' );
$check( 8192 === TZFD_Knowledge_Store::MAX_PAYLOAD_BYTES, 'FAQ payload cap is 8192 bytes' );

$r = $faq()->save_entry( array( 'question' => str_repeat( 'q', 300 ), 'answer' => str_repeat( 'a', 2000 ), 'status' => 'approved' ) );
$check( true === $r['ok'], 'question at 300 and answer at 2000 characters are saved' );

$r = $faq()->save_entry( array( 'question' => str_repeat( 'q', 301 ), 'answer' => 'ok', 'status' => 'approved' ) );
$check( 'invalid' === $r['code'] && false !== strpos( $r['message'], '300' ), 'question of 301 characters is refused with the stated limit' );

$r = $faq()->save_entry( array( 'question' => 'ok?', 'answer' => str_repeat( 'a', 2001 ), 'status' => 'approved' ) );
$check( 'invalid' === $r['code'] && false !== strpos( $r['message'], '2000' ), 'answer of 2001 characters is refused with the stated limit' );

$check( true === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => str_repeat( "\u{00E9}", 2000 ), 'status' => 'approved' ) )['ok'], 'answer of 2000 accented characters (4000 bytes) is accepted: the answer cap counts characters' );

$kw = array_fill( 0, TZFD_Knowledge_Store::MAX_KEYWORDS, 'kw' );
$check( true === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => $kw, 'status' => 'approved' ) )['ok'], '20 keywords are accepted' );
$kw21 = array_map( static function ( $i ) { return 'k' . $i; }, range( 1, 21 ) );
$check( 'invalid' === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => $kw21, 'status' => 'approved' ) )['code'], '21 keywords are refused' );
$check( true === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => array( str_repeat( 'k', 40 ) ), 'status' => 'approved' ) )['ok'], 'a 40-character keyword is accepted' );
$check( 'invalid' === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => array( str_repeat( 'k', 41 ) ), 'status' => 'approved' ) )['code'], 'a 41-character keyword is refused' );

/* ---- FAQ entries: payload cap in bytes, refused whole, never truncated ---- */
$emoji_store = $faq();
$r = $emoji_store->save_entry( array( 'question' => str_repeat( "\u{1F600}", 300 ), 'answer' => str_repeat( "\u{1F600}", 2000 ), 'keywords' => array_fill( 0, 20, str_repeat( 'k', 40 ) ), 'status' => 'approved' ) );
$check( 'invalid' === $r['code'] && false !== strpos( $r['message'], '8192' ), 'an entry over 8192 bytes is refused with the byte limit' );
$check( 0 === count( $emoji_store->entries( true ) ), 'the refused entry is not stored, in full or in part' );
$check( true === $faq()->save_entry( array( 'question' => str_repeat( "\u{1F600}", 300 ), 'answer' => str_repeat( "\u{1F600}", 380 ), 'keywords' => array( str_repeat( "\u{1F600}", 20 ) ), 'status' => 'approved' ) )['ok'], 'an entry exactly at the 8192-byte payload limit is accepted' );

/* ---- FAQ entries: no local per-site count cap ---- */
$store = $faq();
$last  = null;
for ( $i = 1; $i <= 101; $i++ ) {
	$last = $store->save_entry( array( 'question' => 'Question ' . $i . '?', 'answer' => 'Answer ' . $i, 'status' => 'approved' ) );
}
$check( true === $last['ok'] && 101 === count( $store->entries( true ) ), 'a site can hold more than 100 FAQ entries (no local total cap)' );

/* ---- Business facts: 2000-character value cap, cut not refused ---- */
$check( 2000 === TZFD_Business_Facts::MAX_VALUE, 'business fact value cap is 2000 characters' );
$check( 2000 === mb_strlen( TZFD_Business_Facts::clean( str_repeat( 'x', 2500 ) ) ), 'a 2500-character fact value is cut to 2000 characters' );
$check( 2000 === mb_strlen( TZFD_Business_Facts::clean( str_repeat( "\u{00E9}", 2500 ) ) ), 'multibyte fact values are cut by characters, not bytes' );
$check( 1999 === mb_strlen( TZFD_Business_Facts::clean( str_repeat( 'x', 1999 ) ) ), 'a value of 1999 characters is kept whole' );

$facts_mem = new TZFD_Size_Caps_Memory_Storage();
$facts_mem->write( TZFD_Business_Facts::ALLOWLIST['services']['key'], 1, str_repeat( 's', 2500 ) );
$facts = new TZFD_Business_Facts( $facts_mem, 1, $manager, $now );

$pub = $facts->publish( 'services' );
$check( true === $pub['ok'], 'a fact with an over-long setting is published, not refused' );
$got = $facts->get( 'services' );
$check( 'ok' === $got['status'] && 2000 === mb_strlen( $got['value'] ), 'the published fact is served at the 2000-character cap' );

$check( 4 === count( TZFD_Business_Facts::keys() ), 'business facts are bounded by the four-key allowlist' );

echo "\nContent-size caps: passed $passes, failed $fails\n";
exit( $fails > 0 ? 1 : 0 );
