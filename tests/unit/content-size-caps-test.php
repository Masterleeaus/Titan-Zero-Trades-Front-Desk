<?php
/**
 * Issue #330: content-size caps for the knowledge store (FAQ entries) and business facts.
 *
 * Decision pinned here (proposed default, for owner review):
 *  - Per entry: FAQ question 300 characters, FAQ answer 1000 characters, 20 keywords of 40 characters each.
 *    Business fact values 500 characters (longer values are cut at 500).
 *  - Per site: no local total cap on FAQ entries (the 100-entry figure is a hosted pilot target only).
 *    Business facts are bounded by their four-key allowlist. Locally executed rules and bookings stay uncapped.
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

/* ---- FAQ entries: per-entry caps ---- */
$faq = static function () use ( $manager ) {
	return new TZFD_Knowledge_Store( new TZFD_Size_Caps_Memory_Storage(), 1, $manager );
};

$q = str_repeat( 'q', TZFD_Knowledge_Store::MAX_QUESTION );
$a = str_repeat( 'a', TZFD_Knowledge_Store::MAX_ANSWER );

$r = $faq()->save_entry( array( 'question' => $q, 'answer' => $a, 'status' => 'approved' ) );
$check( true === $r['ok'], 'question at the 300-character cap and answer at the 1000-character cap are saved' );

$r = $faq()->save_entry( array( 'question' => $q . 'x', 'answer' => 'ok', 'status' => 'approved' ) );
$check( 'invalid' === $r['code'] && false !== strpos( $r['message'], '300' ), 'question of 301 characters is refused with the stated limit' );

$r = $faq()->save_entry( array( 'question' => 'ok?', 'answer' => $a . 'x', 'status' => 'approved' ) );
$check( 'invalid' === $r['code'] && false !== strpos( $r['message'], '1000' ), 'answer of 1001 characters is refused with the stated limit' );

// The caps count characters, not bytes: 1000 two-byte characters fit.
$accent = str_repeat( 'é', TZFD_Knowledge_Store::MAX_ANSWER );
$r      = $faq()->save_entry( array( 'question' => 'ok?', 'answer' => $accent, 'status' => 'approved' ) );
$check( true === $r['ok'], '1000 multibyte characters (2000 bytes) are accepted: the cap is in characters' );

$kw = array_fill( 0, TZFD_Knowledge_Store::MAX_KEYWORDS, 'kw' );
$check( true === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => $kw, 'status' => 'approved' ) )['ok'], '20 keywords are accepted' );
$kw21 = array_map( static function ( $i ) { return 'k' . $i; }, range( 1, TZFD_Knowledge_Store::MAX_KEYWORDS + 1 ) );
$check( 'invalid' === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => $kw21, 'status' => 'approved' ) )['code'], '21 keywords are refused' );
$check( true === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => array( str_repeat( 'k', TZFD_Knowledge_Store::MAX_KEYWORD ) ), 'status' => 'approved' ) )['ok'], 'a 40-character keyword is accepted' );
$check( 'invalid' === $faq()->save_entry( array( 'question' => 'ok?', 'answer' => 'a', 'keywords' => array( str_repeat( 'k', TZFD_Knowledge_Store::MAX_KEYWORD + 1 ) ), 'status' => 'approved' ) )['code'], 'a 41-character keyword is refused' );

/* ---- FAQ entries: no local per-site count cap ---- */
$store = $faq();
$last  = null;
for ( $i = 1; $i <= 101; $i++ ) {
	$last = $store->save_entry( array( 'question' => 'Question ' . $i . '?', 'answer' => 'Answer ' . $i, 'status' => 'approved' ) );
}
$check( true === $last['ok'] && 101 === count( $store->entries( true ) ), 'a site can hold more than 100 FAQ entries (no local total cap)' );

/* ---- Business facts: per-value cap ---- */
$facts_mem   = new TZFD_Size_Caps_Memory_Storage();
$facts_mem->write( TZFD_Business_Facts::ALLOWLIST['services']['key'], 1, str_repeat( 's', 600 ) );
$facts = new TZFD_Business_Facts( $facts_mem, 1, $manager, $now );

$check( TZFD_Business_Facts::MAX_VALUE === 500, 'business fact value cap is 500 characters' );
$check( 500 === mb_strlen( TZFD_Business_Facts::clean( str_repeat( 'x', 600 ) ) ), 'a 600-character fact value is cut to 500 characters' );
$check( 500 === mb_strlen( TZFD_Business_Facts::clean( str_repeat( 'é', 800 ) ) ), 'multibyte fact values are cut by characters, not bytes' );
$check( str_repeat( 'x', 500 ) === TZFD_Business_Facts::clean( str_repeat( 'x', 500 ) ), 'a value of exactly 500 characters is kept whole' );

$pub = $facts->publish( 'services' );
$check( true === $pub['ok'], 'a fact with a long setting can still be published' );
$got = $facts->get( 'services' );
$check( 'ok' === $got['status'] && 500 === mb_strlen( $got['value'] ), 'the published fact is served at the capped length' );

$check( 4 === count( TZFD_Business_Facts::keys() ), 'business facts are bounded by the four-key allowlist' );

echo "\nContent-size caps: passed $passes, failed $fails\n";
exit( $fails > 0 ? 1 : 0 );
