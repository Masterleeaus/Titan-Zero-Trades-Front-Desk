<?php
/**
 * Unit test bootstrap for the Free AI foundation (#42, #21, #22).
 * Plain PHP, no WordPress. Run: php tests/unit/run.php
 * Loads the plugin classes under test and supplies in-memory storage and cache doubles.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// Shim for WordPress core, matching its behaviour closely enough for these fixtures: tags removed, then trimmed.
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>?/', '', (string) $s ) ); } }

$plugin_inc = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/';
foreach ( array(
    'class-tzfd-intent-engine.php',
    'class-tzfd-tenant-storage.php',
    'class-tzfd-knowledge-store.php',
    'class-tzfd-answer-cache.php',
    'class-tzfd-business-facts.php',
    'class-tzfd-embedding-provider.php',
    'class-tzfd-faq-search.php',
) as $file ) {
    require_once $plugin_inc . $file;
}

/** In-memory tenant storage. Keeps companies apart exactly as the scoped option store does. */
final class TZFD_Test_Memory_Storage implements TZFD_Tenant_Storage {
    public $data = array();
    public $fail_writes = false;
    public function read( $key, $company, $default ) {
        return array_key_exists( $company . '|' . $key, $this->data ) ? $this->data[ $company . '|' . $key ] : $default;
    }
    public function write( $key, $company, $value ) {
        if ( $this->fail_writes ) { return false; }
        $this->data[ $company . '|' . $key ] = $value;
        return true;
    }
}

/** In-memory cache backend. */
final class TZFD_Test_Memory_Cache implements TZFD_Cache_Backend {
    public $items = array();
    public function get( $key ) { return $this->items[ $key ] ?? false; }
    public function set( $key, $value, $ttl ) { $this->items[ $key ] = $value; return true; }
    public function delete( $key ) { unset( $this->items[ $key ] ); }
}

/** Minimal assertion helpers and result tracking. */
$GLOBALS['tzfd_t_pass'] = 0;
$GLOBALS['tzfd_t_fail'] = 0;
$GLOBALS['tzfd_t_fails'] = array();
function t_ok( $cond, $name ) {
    if ( $cond ) { $GLOBALS['tzfd_t_pass']++; return; }
    $GLOBALS['tzfd_t_fail']++;
    $GLOBALS['tzfd_t_fails'][] = $name;
    echo "FAIL $name\n";
}
function t_eq( $expected, $actual, $name ) {
    t_ok( $expected === $actual, $name . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}
