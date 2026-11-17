<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions -- standalone CLI harness: prints plain test text to a terminal, never HTML.
/**
 * Checks for the read-only local monthly insights page (issue #41). Pure PHP with small WordPress stubs. No database,
 * no network. Run from the repository root: php -I tests/unit/monthly-insights-page-test.php
 *
 * Aggregation, suppression, grounding and budget rules are in tests/unit/ai-free-lanes-test.php. These checks cover the
 * page: default month, escaping of stored labels, the company scope and the capability gate.
 */
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-free-services.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-monthly-insights.php';

if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return htmlspecialchars( $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return $s; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type ) { return strtotime( '2026-11-16 00:00:00 UTC' ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $format, $ts ) { return gmdate( $format, $ts ); } }
if ( ! function_exists( 'add_submenu_page' ) ) { function add_submenu_page( ...$args ) { return true; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $msg = '', $title = '', $args = array() ) { throw new RuntimeException( 'wp_die' ); } }
if ( ! class_exists( 'TZFD_Local_Business', false ) ) {
	class TZFD_Local_Business { public static $id = 7; public static function id() { return self::$id; } public static function default_id() { return 7; } }
}

$GLOBALS['tzfd_fail'] = 0;
$GLOBALS['tzfd_pass'] = 0;
function mp_ok( $name, $cond ) {
	if ( $cond ) { $GLOBALS['tzfd_pass']++; return; }
	$GLOBALS['tzfd_fail']++;
	echo 'FAIL: ' . $name . "\n";
}

class MpFakeDb {
	public $prefix = 'wp_';
	public $rows   = array();
	public $args   = null;
	public function prepare( $sql, ...$args ) { $this->args = $args; return $sql; }
	public function get_results( $sql, $output = null ) { return $this->rows; }
}

$M = 'TZFD_Ai_Monthly_Insights';

// Default month: the last complete month in site time (clock is 16 Nov 2026).
mp_ok( 'requested valid month is used', '2026-10' === $M::page_month( '2026-10' ) );
mp_ok( 'missing request uses last complete month', '2026-10' === $M::page_month( '' ) );
mp_ok( 'invalid request uses last complete month', '2026-10' === $M::page_month( '2026-13' ) );

// Render: counts with denominators, company scope, escaped labels.
$db = new MpFakeDb();
$db->rows = array(
	array( 'status' => 'new', 'source' => 'web', 'n' => 30 ),
	array( 'status' => 'closed', 'source' => 'phone', 'n' => 12 ),
	array( 'status' => '<script>x</script>', 'source' => 'web', 'n' => 8 ),
);
$GLOBALS['wpdb']        = $db;
$GLOBALS['tzfd_test_caps'] = true;
ob_start();
$M::render_page();
$html = ob_get_clean();
mp_ok( 'page shows the status section', false !== strpos( $html, 'Enquiries by status' ) );
mp_ok( 'page shows the denominator in the text', false !== strpos( $html, 'of 50' ) );
mp_ok( 'stored labels are escaped, not run as markup', false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ) );
mp_ok( 'page is scoped to the current company', 7 === $db->args[1] );
mp_ok( 'page reads only the month window', '2026-10-01 00:00:00' === $db->args[2] && '2026-11-01 00:00:00' === $db->args[3] );
mp_ok( 'page output has no contact fields', false === stripos( $html, '@' ) );

// Company without a valid scope: the page says so and shows no counts.
TZFD_Local_Business::$id = 0;
ob_start();
$M::render_page();
$none = ob_get_clean();
mp_ok( 'no company shows an error and no table', false !== strpos( $none, 'could not be built' ) && false === strpos( $none, '<table' ) );
TZFD_Local_Business::$id = 7;

// Capability gate: a user without tz_fd_access is refused before any query runs.
$GLOBALS['tzfd_test_caps'] = false;
$refused = false;
try {
	ob_start();
	$M::render_page();
	ob_end_clean();
} catch ( RuntimeException $e ) {
	ob_end_clean();
	$refused = 'wp_die' === $e->getMessage();
}
mp_ok( 'user without the capability is refused', $refused );

echo 'Passed: ' . $GLOBALS['tzfd_pass'] . "\n";
echo 'Failed: ' . $GLOBALS['tzfd_fail'] . "\n";
exit( 0 === $GLOBALS['tzfd_fail'] ? 0 : 1 );
