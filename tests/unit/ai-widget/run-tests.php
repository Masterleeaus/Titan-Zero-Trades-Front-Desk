<?php
/**
 * Unit and fixture tests for the public AI answer box, service finder and booking assistant
 * (issues #20, #23, #24). Standalone: WordPress functions are stubbed in memory.
 *
 * Run: php tests/unit/ai-widget/run-tests.php
 * Not shipped in the plugin.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_filters'] = array();
$GLOBALS['t_options'] = array();
$GLOBALS['t_transients'] = array();

function add_filter( $hook, $cb ) { $GLOBALS['t_filters'][ $hook ] = $cb; }
function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['t_filters'][ $hook ] ) ) {
		return call_user_func_array( $GLOBALS['t_filters'][ $hook ], array_merge( array( $value ), $args ) );
	}
	return $value;
}
function do_action( $hook, ...$args ) { $GLOBALS['t_actions'][] = $hook; }
function add_shortcode( $tag, $cb ) { $GLOBALS['t_shortcodes'][ $tag ] = $cb; }
function get_option( $k, $d = false ) { return $GLOBALS['t_options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['t_transients'][ $k ] = $v; return true; }
function wp_hash( $data ) { return hash_hmac( 'sha256', (string) $data, 'test-salt' ); }
function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>?/', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( wp_strip_all_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $s ) { return (string) $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function get_current_blog_id() { return 1; }
function plugins_url( $p, $f ) { return 'https://example.test/' . $p; }
function wp_enqueue_style( ...$a ) { $GLOBALS['t_styles'][] = $a[0]; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

$inc = __DIR__ . '/../../../titan-zero-trades-front-desk/includes/';
require_once __DIR__ . '/../fixtures/fake-rate-limit-wpdb.php';
$GLOBALS['wpdb'] = new TZ_Fake_Rate_Limit_Wpdb(); // The rate limiter counts in wp_options through $wpdb.
require_once $inc . 'class-tzfd-ai-widget.php';

$GLOBALS['fail'] = 0;
$GLOBALS['pass'] = 0;
function check( $name, $ok, $detail = '' ) {
	if ( $ok ) { $GLOBALS['pass']++; echo "PASS $name\n"; } else { $GLOBALS['fail']++; echo "FAIL $name" . ( '' !== $detail ? " -- $detail" : '' ) . "\n"; }
}
function reset_state() {
	$GLOBALS['t_filters'] = array();
	$GLOBALS['t_options'] = array();
	$GLOBALS['t_transients'] = array();
	$GLOBALS['wpdb']->rows = array();
}

$faq = array(
	array( 'id' => 'hours', 'keywords' => array( 'opening hours', 'hours', 'open' ), 'questions' => array( 'what are your opening hours' ), 'answer' => 'We are open Monday to Friday, 8am to 5pm.' ),
	array( 'id' => 'cancel', 'keywords' => array( 'cancel', 'cancellation' ), 'answer' => 'Please cancel at least 24 hours before your booking.' ),
	array( 'id' => 'areas', 'keywords' => array( 'suburb', 'area', 'service area' ), 'answer' => 'We cover the inner west and east suburbs.' ),
	array( 'id' => 'parking', 'keywords' => array( 'parking' ), 'answer' => 'Street parking is available on most streets.' ),
	array( 'id' => 'parking2', 'keywords' => array( 'parking' ), 'answer' => 'Use the car park behind the shop.' ),
);
$with_faq = function () use ( $faq ) {
	add_filter( 'tzfd_ai_approved_answers', function () use ( $faq ) { return $faq; } );
};

// ---- normalize -----------------------------------------------------------------------------
$n = TZFD_AI_Answer_Box::normalize( "  What's your  OPENING\x00 hours?? " );
check( 'normalize lowercases, strips control and punctuation', 'what s your opening hours' === $n, $n );
check( 'normalize rejects invalid UTF-8', '' === TZFD_AI_Answer_Box::normalize( "\xC3\x28" ) );

// ---- answer box: approved rule branch --------------------------------------------------------
reset_state(); $with_faq();
$r = TZFD_AI_Answer_Box::answer( 'What are your opening hours?', 'v1' );
check( 'exact approved question is answered', 'answered' === $r['status'] && 'hours' === $r['entry_id'] && 'approved_rule' === $r['source'], json_encode( $r ) );
check( 'answer text comes from approved entry', false !== strpos( $r['answer'], 'Monday to Friday' ) );

reset_state(); $with_faq();
$r = TZFD_AI_Answer_Box::answer( 'do you serve the north suburb?', 'v1' );
check( 'keyword match answers from approved rule', 'areas' === $r['entry_id'], json_encode( $r ) );

reset_state(); $with_faq();
$r = TZFD_AI_Answer_Box::answer( 'is there parking', 'v1' );
check( 'tie between two approved entries refuses with handoff', 'refused' === $r['status'] && true === $r['handoff'] );

reset_state(); $with_faq();
$r = TZFD_AI_Answer_Box::answer( 'I do not want to cancel', 'v1' );
check( 'negated keyword-only match is refused, not answered', 'refused' === $r['status'], json_encode( $r ) );

// ---- refusals and no invented answers -------------------------------------------------------
reset_state(); $with_faq();
$model_calls = 0;
add_filter( 'tzfd_ai_fallback_enabled', function () { return true; } );
add_filter( 'tzfd_ai_generative_fallback', function ( $v ) use ( &$model_calls ) { $model_calls++; return 'Invented answer'; } );
$r = TZFD_AI_Answer_Box::answer( 'how much is a deep clean for a 3 bedroom flat', 'v1' );
check( 'price question with no approved entry is refused', 'refused' === $r['status'] );
check( 'price question never reaches the model', 0 === $model_calls, "calls=$model_calls" );

reset_state(); $with_faq();
$model_calls = 0;
$r = TZFD_AI_Answer_Box::answer( 'do you have anything free on friday afternoon', 'v1' );
check( 'availability question is refused by default', 'refused' === $r['status'] && 0 === $model_calls );

reset_state(); $with_faq();
$r = TZFD_AI_Answer_Box::answer( 'zxq wibble frobnicate', 'v1' );
check( 'unknown question with fallback disabled is refused, handoff true', 'refused' === $r['status'] && true === $r['handoff'] );

// ---- generative fallback budget and offline proxy -------------------------------------------
reset_state(); $with_faq();
$model_calls = 0;
add_filter( 'tzfd_ai_fallback_enabled', function () { return true; } );
add_filter( 'tzfd_ai_generative_fallback', function ( $v, $norm ) use ( &$model_calls ) { $model_calls++; return '<b>Sure</b> we can help with that.'; } );
$a = TZFD_AI_Answer_Box::answer( 'can you help with a leaking roof gutter', 'visitor-A' );
$b = TZFD_AI_Answer_Box::answer( 'can you help with a broken fence panel', 'visitor-A' );
for ( $i = 2; $i < 20; $i++ ) { TZFD_AI_Answer_Box::answer( "can you help with fallback job $i", 'visitor-A' ); }
// Simulate the 10-minute general request window passing, so only the hourly fallback budget is tested here.
unset( $GLOBALS['wpdb']->rows[ TZFD_Rate_Limit::option_name( TZFD_AI_Answer_Box::RATE_PREFIX . md5( 'visitor-A' ) ) ] );
$c = TZFD_AI_Answer_Box::answer( 'can you help with a blocked chimney flue', 'visitor-A' );
check( 'first two generative turns are answered by model', 'model' === $a['source'] && 'model' === $b['source'], json_encode( array( $a, $b ) ) );
check( 'twenty generative turns per hour allowed for one visitor, twenty-first refused', 'refused' === $c['status'] && 20 === $model_calls, "calls=$model_calls" );
check( 'model answer text is stripped of markup', 'Sure we can help with that.' === $a['answer'], $a['answer'] );
$d = TZFD_AI_Answer_Box::answer( 'can you help with a broken gate latch', 'visitor-B' );
check( 'fallback budget is per visitor', 'model' === $d['source'] );

reset_state(); $with_faq();
$model_calls = 0;
add_filter( 'tzfd_ai_fallback_enabled', function () { return true; } );
add_filter( 'tzfd_ai_generative_fallback', function ( $v ) use ( &$model_calls ) { $model_calls++; return new WP_Error_Stub(); } );
$r = TZFD_AI_Answer_Box::answer( 'can you help with a gate latch', 'visitor-C' );
check( 'proxy offline (non-string) becomes a handoff refusal', 'refused' === $r['status'] && true === $r['handoff'] && 1 === $model_calls );

// ---- cache and invalidation ------------------------------------------------------------------
reset_state(); $with_faq();
$first = TZFD_AI_Answer_Box::answer( 'opening hours', 'v2' );
$second = TZFD_AI_Answer_Box::answer( 'opening hours', 'v3' );
check( 'second identical question is served from cache', false === $first['cached'] && true === $second['cached'] );
$ver_before = TZFD_AI_Answer_Box::knowledge_version();
TZFD_AI_Answer_Box::invalidate();
check( 'invalidate bumps knowledge version', TZFD_AI_Answer_Box::knowledge_version() === $ver_before + 1 );
$third = TZFD_AI_Answer_Box::answer( 'opening hours', 'v4' );
check( 'stale cache entry is unreachable after invalidation', false === $third['cached'] );

reset_state();
$changed = array( array( 'id' => 'hours', 'keywords' => array( 'opening hours' ), 'answer' => 'Now open weekends too.' ) );
add_filter( 'tzfd_ai_approved_answers', function () use ( &$changed ) { return $changed; } );
$first = TZFD_AI_Answer_Box::answer( 'opening hours', 'v5' );
$changed = array( array( 'id' => 'hours', 'keywords' => array( 'opening hours' ), 'answer' => 'Closed on Sundays.' ) );
TZFD_AI_Answer_Box::invalidate();
$edited = TZFD_AI_Answer_Box::answer( 'opening hours', 'v6' );
check( 'edited FAQ propagates after invalidation', 'Closed on Sundays.' === $edited['answer'], $edited['answer'] );

// ---- rate limit and input validation --------------------------------------------------------
reset_state(); $with_faq();
$allowed = 0;
for ( $i = 0; $i < 25; $i++ ) {
	$r = TZFD_AI_Answer_Box::answer( 'opening hours ' . $i, 'flood' );
	if ( 'limited' !== $r['status'] ) { $allowed++; }
}
check( 'unauthenticated rate limit caps requests per visitor', 20 === $allowed, "allowed=$allowed" );
check( 'rate limit does not affect other visitors', 'answered' === TZFD_AI_Answer_Box::answer( 'opening hours', 'other' )['status'] );

reset_state(); $with_faq();
check( 'overlong question is invalid', 'invalid' === TZFD_AI_Answer_Box::answer( str_repeat( 'a', 301 ), 'v7' )['status'] );
check( 'empty question is invalid', 'invalid' === TZFD_AI_Answer_Box::answer( '   ', 'v8' )['status'] );
check( 'non-string question is invalid', 'invalid' === TZFD_AI_Answer_Box::answer( array( 'x' ), 'v9' )['status'] );

// ---- malformed approved entries are dropped -------------------------------------------------
reset_state();
add_filter( 'tzfd_ai_approved_answers', function () {
	return array(
		array( 'id' => '../../x', 'keywords' => array( 'bad' ), 'answer' => 'no' ),
		array( 'id' => 'ok-1', 'keywords' => array( 'good' ), 'answer' => '<script>alert(1)</script>Safe text' ),
		array( 'id' => 'empty', 'keywords' => array(), 'answer' => 'no keywords' ),
		array( 'id' => 'long', 'keywords' => array( 'long' ), 'answer' => str_repeat( 'x', 601 ) ),
		'not-an-array',
	);
} );
$entries = TZFD_AI_Answer_Box::approved_entries();
check( 'only well-formed entries survive validation', 1 === count( $entries ) && 'ok-1' === $entries[0]['id'], json_encode( $entries ) );
check( 'approved answer has tags stripped at ingestion', 'alert(1)Safe text' === $entries[0]['answer'], $entries[0]['answer'] );

// ---- service finder ---------------------------------------------------------------------------
$services = array(
	array( 'id' => 1, 'name' => 'Plumbing repair', 'description' => 'Fix leaks and blocked drains', 'archived' => 0 ),
	array( 'id' => 2, 'name' => 'Deep clean', 'description' => 'Full house clean', 'archived' => 0 ),
	array( 'id' => 3, 'name' => 'Regular cleaning', 'description' => 'Weekly tidy and mop', 'archived' => 0 ),
	array( 'id' => 4, 'name' => 'Old pool service', 'description' => 'Retired', 'archived' => 1 ),
	array( 'id' => 5, 'name' => 'Garden mowing', 'description' => 'Lawn and hedge', 'archived' => 0 ),
);

$m = TZFD_Service_Finder::match( 'my tap is broken and needs a fix', $services );
check( 'synonym maps to one service', 'one' === $m['status'] && 1 === $m['matches'][0]['id'], json_encode( $m ) );

$m = TZFD_Service_Finder::match( 'I need someone to tidy my place', $services );
check( 'synonym "tidy" finds both cleaning services (many)', 'many' === $m['status'] && array( 2, 3 ) === array_column( $m['matches'], 'id' ), json_encode( $m ) );

$m = TZFD_Service_Finder::match( 'xyzzy frobnicate', $services );
check( 'no match returns none', 'none' === $m['status'] && empty( $m['matches'] ) );

$m = TZFD_Service_Finder::match( 'book the old pool service', $services );
check( 'archived service is never matched', ! in_array( 4, array_column( $m['matches'], 'id' ), true ), json_encode( $m ) );

$m = TZFD_Service_Finder::match( str_repeat( 'clean ', 80 ), $services );
check( 'overlong input is invalid', 'invalid' === $m['status'] );

$m = TZFD_Service_Finder::match( '<script>alert(1)</script> lawn', $services );
check( 'markup-laden input still matches by concept only', 'one' === $m['status'] && 5 === $m['matches'][0]['id'], json_encode( $m ) );

$m = TZFD_Service_Finder::match( 'deep clean please', $services );
check( 'name match outranks description match', 'one' === $m['status'] && 2 === $m['matches'][0]['id'], json_encode( $m ) );

check( 'validate_selection accepts a visible ID', 2 === TZFD_Service_Finder::validate_selection( '2', $services ) );
check( 'validate_selection rejects archived ID', 0 === TZFD_Service_Finder::validate_selection( 4, $services ) );
check( 'validate_selection rejects unknown ID', 0 === TZFD_Service_Finder::validate_selection( 99, $services ) );
check( 'validate_selection rejects injected string', 0 === TZFD_Service_Finder::validate_selection( '1 OR 1=1', $services ) );
check( 'validate_selection rejects zero and arrays', 0 === TZFD_Service_Finder::validate_selection( '0', $services ) && 0 === TZFD_Service_Finder::validate_selection( array( 2 ), $services ) );

// ---- booking assistant (no invented slots) --------------------------------------------------
$today = '2026-11-16';
$p = TZFD_Booking_Assistant::plan( 2, '2026-11-20', '', $services, $today );
check( 'no feed connected means no invented slots', 'no_slots_feed' === $p['status'] && array() === $p['slots'], json_encode( $p ) );

reset_state();
add_filter( 'tzfd_ai_booking_slots', function () { return array( '09:00/10:00', '14:00/15:00', '25:00/26:00', '11:00/10:30', 42 ); } );
$p = TZFD_Booking_Assistant::plan( 2, '2026-11-20', '', $services, $today );
check( 'engine slots are listed and malformed ones dropped', array( '09:00/10:00', '14:00/15:00' ) === $p['slots'], json_encode( $p['slots'] ) );
check( 'listing options does not confirm anything', 'choose_slot' === $p['status'] && '' === $p['slot'] );

$p = TZFD_Booking_Assistant::plan( 2, '2026-11-20', '12:00/13:00', $services, $today );
check( 'slot not returned by engine is rejected', 'slot_not_offered' === $p['status'] && '' === $p['slot'] );

$p = TZFD_Booking_Assistant::plan( 2, '2026-11-20', '09:00/10:00', $services, $today );
check( 'offered slot moves to confirm, not submit', 'confirm' === $p['status'] && '09:00/10:00' === $p['slot'] && 'confirm_before_submit' === $p['message'] );

$p = TZFD_Booking_Assistant::plan( 4, '2026-11-20', '09:00/10:00', $services, $today );
check( 'archived service cannot be booked', 'invalid' === $p['status'] && 0 === $p['service_id'] );

foreach ( array( '2026-11-15', '2027-12-01', '2026-02-30', '2026-11-1', 'tomorrow', '2026-11-20T10:00' ) as $bad ) {
	$p = TZFD_Booking_Assistant::plan( 2, $bad, '', $services, $today );
	check( "invalid date rejected: $bad", 'invalid' === $p['status'] && 'date_not_valid' === $p['message'] );
}
check( 'leap day in range is valid', TZFD_Booking_Assistant::valid_date( '2028-02-29', '2028-01-10' ) );

reset_state();
$handoff = TZFD_Booking_Assistant::handoff_url( '', array( 'service_id' => 2, 'date' => '', 'slot' => '' ) );
check( 'no booking page configured gives no link', '' === $handoff );
$handoff = TZFD_Booking_Assistant::handoff_url( 'https://example.test/book/', array( 'service_id' => 2, 'date' => '2026-11-20', 'slot' => '09:00/10:00' ) );
check( 'handoff carries the validated selection', false !== strpos( $handoff, 'tzfd_service=2' ) && false !== strpos( $handoff, 'tzfd_slot=09%3A00%2F10%3A00' ), $handoff );

// ---- widget rendering --------------------------------------------------------------------------
reset_state(); $with_faq();
$_GET = array( 'tzfd_ai_q' => 'What are your <img src=x onerror=alert(1)> opening hours?' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$html = TZFD_AI_Widget::render();
check( 'widget renders a labelled search field', false !== strpos( $html, '<label for="tzfd-ai-1-q">' ) && false !== strpos( $html, 'type="search"' ) );
check( 'widget answer region is a polite live region', false !== strpos( $html, 'role="status" aria-live="polite"' ) );
check( 'widget has no script tags (works without JavaScript)', false === stripos( $html, '<script' ) );
check( 'widget markup in query is stripped, never rendered', false === strpos( $html, '<img' ) && false === stripos( $html, 'onerror' ) );
check( 'widget answers from approved rule', false !== strpos( $html, 'Monday to Friday' ) );

$_GET = array();
$html = TZFD_AI_Widget::render();
check( 'widget without a question shows no answer text', false === strpos( $html, 'Monday to Friday' ) && false !== strpos( $html, 'class="tzfd-ai-answer" role="status"' ) );

check( 'answer_html escapes model-style markup', false !== strpos( TZFD_AI_Widget::answer_html( array( 'status' => 'answered', 'answer' => '<a href="x">hi</a>', 'source' => 'approved_rule' ) ), '&lt;a href=' ) );
check( 'model answers carry an automated-answer note', false !== strpos( TZFD_AI_Widget::answer_html( array( 'status' => 'answered', 'answer' => 'hi', 'source' => 'model' ) ), 'Automated answer' ) );

// ---- summary --------------------------------------------------------------------------------
echo "\nPassed: {$GLOBALS['pass']}  Failed: {$GLOBALS['fail']}\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );

class WP_Error_Stub {}
