<?php
/*
 * Unit harness for TZFD_Answer_Box (issue #20). Runs without WordPress: WP functions are stubbed here.
 * It does NOT replace WordPress integration tests on a real site (not available in this sandbox).
 * Usage: php -d display_errors=1 tests/answer-box/run.php <path-to-class-tzfd-answer-box.php>
 */
error_reporting( E_ALL );
$class_file = $argv[1] ?? '';
if ( '' === $class_file || ! is_file( $class_file ) ) { fwrite( STDERR, "class file required\n" ); exit( 2 ); }

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array(); $GLOBALS['tx'] = array(); $GLOBALS['filters'] = array(); $GLOBALS['services'] = array();
$GLOBALS['tx_ttl'] = array();
function __( $s ) { return $s; }
function esc_html__( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { $s = (string) $s; return preg_match( '#^(https?:)?//|^/#i', $s ) ? htmlspecialchars( $s, ENT_QUOTES ) : ''; }
function esc_url_raw( $s ) { return esc_url( $s ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function wp_hash( $s ) { return hash_hmac( 'sha256', (string) $s, 'test-salt' ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['tx'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tx'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tx'][ $k ] ); return true; }
function add_shortcode( $t, $cb ) { $GLOBALS['shortcodes'][ $t ] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $h ][] = $cb; }
function apply_filters( $h, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $h ] ?? array() as $cb ) { $value = call_user_func_array( $cb, array_merge( array( $value ), $args ) ); }
	return $value;
}
function get_permalink( $id ) { return 'https://example.test/booking/' . (int) $id . '/'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function bloginfo( $x = '' ) { return 'Test'; }
final class TZFD_Local_Services { public static function list( $args = array() ) { return $GLOBALS['services']; } }
final class TZFD_Client_Portal { const OPT_BOOKING_PAGE = 'tz_fd_booking_page_id'; }

require $class_file;
$A = 'TZFD_Answer_Box';

$pass = 0; $fail = 0;
function check( $name, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "ok   $name\n"; } else { $fail++; echo "FAIL $name $detail\n"; }
}
function reset_state() { $GLOBALS['opts'] = array(); $GLOBALS['tx'] = array(); $GLOBALS['services'] = array(); }
$conv = str_repeat( 'a', 32 );
$conv2 = str_repeat( 'b', 32 );

// ---- seed approved knowledge
reset_state();
$count = $A::save_entries( array(
	array( 'kind' => 'faq', 'question' => 'Do you offer free quotes?', 'answer' => 'Yes, quotes are free for standard jobs.', 'keywords' => 'quote,free,estimate', 'approved' => true ),
	array( 'kind' => 'policy', 'question' => 'Cancellation policy', 'answer' => 'Cancel at least 24 hours ahead.', 'keywords' => array( 'cancel', 'cancellation', 'refund' ), 'approved' => true ),
	array( 'kind' => 'faq', 'question' => 'Unapproved draft', 'answer' => 'Should never appear.', 'approved' => false ),
	array( 'kind' => 'faq', 'question' => 'Bad markup', 'answer' => '<script>alert(1)</script>Safe text', 'approved' => true ),
) );
check( 'only approved entries are stored', 3 === $count, "count=$count" );
$A::save_hours( "Mon-Fri 8am to 5pm\nSat 9am to 1pm" );
$GLOBALS['services'] = array( array( 'id' => 7, 'name' => 'Gutter cleaning', 'description' => 'Clear debris from gutters.', 'duration_minutes' => 90 ) );
$A::save_handoff_url( 'https://example.test/contact/' );

// ---- exact / keyword / approved branch
$r = $A::answer( 'Do you offer free quotes?', $conv, 'c1' );
check( 'exact match answers from approved entry', 'approved' === $r['status'] && 'Yes, quotes are free for standard jobs.' === $r['text'], json_encode( $r ) );
$r = $A::answer( 'how much for a free estimate', $conv, 'c1' );
check( 'keyword match answers from approved entry', 'approved' === $r['status'] && false !== strpos( $r['text'], 'quotes are free' ), json_encode( $r ) );
$r = $A::answer( 'What are your hours?', $conv, 'c1' );
check( 'business hours answered from owner text', 'approved' === $r['status'] && false !== strpos( $r['text'], 'Mon-Fri' ), json_encode( $r ) );
$r = $A::answer( 'Do you do gutter cleaning', $conv, 'c1' );
check( 'service catalogue answer without price', 'approved' === $r['status'] && false !== strpos( $r['text'], '90 minutes' ) && false === strpos( $r['text'], '$' ), json_encode( $r ) );
$r = $A::answer( 'what is your cancellation refund rule', $conv, 'c1' );
check( 'policy keywords match', 'approved' === $r['status'] && false !== strpos( $r['text'], '24 hours' ), json_encode( $r ) );
$r = $A::answer( 'Should never appear', $conv, 'c1' );
check( 'unapproved draft is never answered', 'approved' !== $r['status'] || false === strpos( $r['text'], 'Should never' ), json_encode( $r ) );

// ---- cache branch and invalidation
$r = $A::answer( 'Do you offer free quotes?', $conv, 'c1' );
check( 'second identical question served from approved cache', 'cache' === $r['status'], json_encode( $r ) );
$A::save_entries( array( array( 'kind' => 'faq', 'question' => 'Do you offer free quotes?', 'answer' => 'Quotes now cost a small fee.', 'approved' => true ) ) );
$r = $A::answer( 'Do you offer free quotes?', $conv, 'c1' );
check( 'cache invalidated after approved answer changes', 'approved' === $r['status'] && 'Quotes now cost a small fee.' === $r['text'], json_encode( $r ) );
$GLOBALS['services'] = array( array( 'id' => 7, 'name' => 'Gutter cleaning', 'description' => 'Changed.', 'duration_minutes' => 30 ) );
$r = $A::answer( 'Do you do gutter cleaning', $conv, 'c1' );
check( 'cache invalidated when service catalogue changes', false !== strpos( $r['text'], 'Changed.' ) && false !== strpos( $r['text'], '30 minutes' ), json_encode( $r ) );

// ---- refusal and generative branch
$r = $A::answer( 'Do you bake sourdough bread?', $conv, 'c1' );
check( 'unsupported question is refused, not guessed (generation disabled by default)', 'unavailable' === $r['status'] && null !== $r['link'], json_encode( $r ) );
$r = $A::answer( 'Do you open a bank account for me?', $conv, 'c-lowcov' );
check( 'low-coverage question refused', 'unavailable' === $r['status'], json_encode( $r ) );

$calls = 0;
add_filter( 'tzfd_answer_box_daily_generation_cap', function () { return 5; } );
add_filter( 'tzfd_answer_box_generate', function ( $v, $payload ) use ( &$calls ) { $calls++; return array( 'ok' => true, 'text' => 'We may be able to help with that; please contact us.' ); } );
$r = $A::answer( 'Can you fix my boiler', $conv2, 'c2' );
check( 'generative branch returns generated text, escaped later', 'generated' === $r['status'] && 1 === $calls, json_encode( $r ) );
$A::answer( 'Can you paint my fence', $conv2, 'c2' );
$r = $A::answer( 'Can you service my aircon', $conv2, 'c2' );
check( 'at most two generative turns per conversation', 'turns_exhausted' === $r['status'] && 2 === $calls, "status={$r['status']} calls=$calls" );
$r = $A::answer( 'Can you service my aircon', $conv, 'c3' );
check( 'a different conversation gets its own turns', 'generated' === $r['status'], json_encode( $r ) );
$stored = false; foreach ( $GLOBALS['tx'] as $k => $v ) { if ( 0 === strpos( $k, 'tzab_c_' ) && false !== strpos( json_encode( $v ), 'We may be able' ) ) { $stored = true; } }
check( 'generated text is never cached', ! $stored );

// shared daily budget
$GLOBALS['opts']['tzfd_answer_box_pilot_budget'] = array( 'day' => gmdate( 'Y-m-d' ), 'used' => 5 );
$r = $A::answer( 'Can you wash my car', str_repeat( 'd', 32 ), 'c5' );
check( 'shared daily budget stops generation', 'budget_exhausted' === $r['status'], json_encode( $r ) );
$GLOBALS['opts']['tzfd_answer_box_pilot_budget'] = array( 'day' => '2000-01-01', 'used' => 99 );
$r = $A::answer( 'Can you wash my car', str_repeat( 'e', 32 ), 'c5' );
check( 'budget resets on a new day', 'generated' === $r['status'], json_encode( $r ) );

// refused claims in generated text
foreach ( array( 'Our rate is $80 an hour.', 'We are fully insured and licensed.', 'Book at 9am tomorrow.', 'See https://evil.test for prices', 'We guarantee the work.' ) as $bad ) {
	reset_state(); $A::save_entries( array() ); $A::save_handoff_url( 'https://example.test/contact/' );
	add_filter( 'tzfd_answer_box_daily_generation_cap', function () { return 99; } );
	$GLOBALS['filters']['tzfd_answer_box_generate'] = array( function () use ( $bad ) { return array( 'ok' => true, 'text' => $bad ); } );
	$r = $A::answer( 'Random question about widgets', str_repeat( 'f', 32 ), 'c6' );
	check( "unsupported claim refused: $bad", 'unsupported_claim' === $r['status'], json_encode( $r ) );
}

// proxy offline
reset_state(); $A::save_entries( array() ); $A::save_handoff_url( 'https://example.test/contact/' );
add_filter( 'tzfd_answer_box_daily_generation_cap', function () { return 9; } );
$GLOBALS['filters']['tzfd_answer_box_generate'] = array( function () { throw new RuntimeException( 'timeout' ); } );
$r = $A::answer( 'Random question about widgets', str_repeat( '1', 32 ), 'c7' );
check( 'proxy exception handled as proxy_unavailable with handoff', 'proxy_unavailable' === $r['status'] && null !== $r['link'], json_encode( $r ) );
$GLOBALS['filters']['tzfd_answer_box_generate'] = array( function () { return new WP_Error_Stub(); } );
$r = $A::answer( 'Random question about widgets', str_repeat( '2', 32 ), 'c7' );
check( 'malformed proxy reply handled as proxy_unavailable', 'proxy_unavailable' === $r['status'], json_encode( $r ) );
class WP_Error_Stub {}
$GLOBALS['filters']['tzfd_answer_box_generate'] = array();

// ---- markup / XSS
reset_state();
$A::save_entries( array( array( 'kind' => 'faq', 'question' => 'Is it safe', 'answer' => '<img src=x onerror=alert(1)>Yes', 'keywords' => 'safe', 'approved' => true ) ) );
$A::save_handoff_url( 'https://example.test/contact/' );
$_GET = array( 'tzab_q' => 'Is it safe', 'tzab_c' => $conv );
$html = $A::render();
check( 'stored HTML in answers stripped to text', false === stripos( $html, '<img' ) && false === stripos( $html, 'onerror=' ), substr( $html, 0, 400 ) );
check( 'answer rendered as text when plain', false !== strpos( $html, 'Yes' ), '' );
check( 'markdown link is flattened to text', 'Read this' === $A::plain_text( '[Read this](javascript:alert(1))' ) );
$_GET = array( 'tzab_q' => '<script>alert(1)</script>' );
$html = $A::render();
check( 'script in visitor question is never echoed raw', false === stripos( $html, '<script>alert' ), '' );
$_GET = array( 'tzab_q' => str_repeat( 'x', 301 ) );
$r = $A::answer( str_repeat( 'x', 301 ), $conv, 'c8' );
check( 'overlong question refused as invalid', 'invalid' === $r['status'] );
$_GET = array( 'tzab_c' => '<bad>' );
$html = $A::render();
check( 'invalid conversation token replaced with a fresh one', 1 === preg_match( '/name="tzab_c" value="[a-f0-9]{32}"/', $html ) );

// ---- rate limit (anonymous)
reset_state(); $A::save_entries( array( array( 'kind' => 'faq', 'question' => 'Hours', 'answer' => 'Open.', 'approved' => true ) ) );
$hits = 0;
for ( $i = 0; $i < 12; $i++ ) { $r = $A::answer( 'Hours?', str_repeat( '9', 32 ), 'anon-1' ); if ( 'rate_limited' === $r['status'] ) { $hits++; } }
check( 'anonymous client is limited after 10 queries (11th and 12th refused)', 2 === $hits, "hits=$hits" );
$r = $A::answer( 'Hours?', str_repeat( '9', 32 ), 'anon-2' );
check( 'a different client is not limited by someone else', 'rate_limited' !== $r['status'] );
// stored counters must not contain the raw client key
$leak = false; foreach ( array_keys( $GLOBALS['tx'] ) as $k ) { if ( false !== strpos( $k, 'anon-1' ) ) { $leak = true; } }
check( 'rate limit stores only a hash of the client', ! $leak );

// ---- rendering and no-JS form
reset_state(); $_GET = array(); $html = $A::render();
check( 'form is a plain GET form (works without JavaScript)', false !== strpos( $html, 'method="get"' ) );
check( 'input has an associated label and status region', false !== strpos( $html, 'for="tzab-q"' ) && false !== strpos( $html, 'aria-live="polite"' ) );
check( 'no script tags are emitted', false === stripos( $html, '<script' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
