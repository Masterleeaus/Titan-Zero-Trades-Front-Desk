<?php
/**
 * Unit checks for TZFD_Service_Finder_Wiring (booking form render hook). Pure PHP, no WordPress needed.
 * Run: php tests/unit/service-finder-wiring-tests.php   (exit 0 when every check passes)
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['sfw_filters'] = array();
$GLOBALS['sfw_pass']    = 0;
$GLOBALS['sfw_fail']    = 0;

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['sfw_filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['sfw_filters'][ $hook ] ?? array() as $cb ) {
		if ( 'tzfd_service_finder_services' === $hook ) { $value = $cb( $value, ...$args ); }
	}
	return $value;
}
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function __( $s, $d = '' ) { return $s; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function get_permalink() { return 'https://example.test/book/?page_id=7'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url ); }
function add_query_arg( $args, $url ) {
	$parts = parse_url( $url );
	$query = array();
	if ( isset( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
	$query = array_merge( $query, $args );
	return $parts['scheme'] . '://' . $parts['host'] . $parts['path'] . '?' . http_build_query( $query );
}

require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-service-finder-wiring.php';

function sfw_check( $name, $ok, $detail = '' ) {
	if ( $ok ) { $GLOBALS['sfw_pass']++; } else { $GLOBALS['sfw_fail']++; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

$W = 'TZFD_Service_Finder_Wiring';

// Catalogue fixture: 5 and 8 are visible; 6 is archived; 9 has no name.
$catalogue = array(
	array( 'id' => 5, 'name' => 'Deep Clean', 'description' => 'Full house deep clean', 'archived' => 0 ),
	array( 'id' => 6, 'name' => 'Old Carpet Wash', 'description' => '', 'archived' => 1 ),
	array( 'id' => 8, 'name' => 'Plumbing Repair', 'description' => 'Leaks and blocked drains', 'archived' => 0 ),
	array( 'id' => 9, 'name' => '   ', 'description' => '', 'archived' => 0 ),
);
add_filter( 'tzfd_service_finder_services', function () use ( $catalogue ) { return $catalogue; } );

$form = '<form method="post" action="/book/"><input type="text" name="name" /></form>';
$two  = '<form method="post"><input name="a" /></form><form method="post"><input name="b" /></form>';

function sfw_run( $get, $html ) {
	$_GET = $get;
	return TZFD_Service_Finder_Wiring::filter_form( $html, 1 );
}
function sfw_hidden_count( $out ) { return substr_count( $out, 'name="tzfd_service_id"' ); }

// 1. No request: panel added, no hidden field, original form kept after the panel.
$out = sfw_run( array(), $form );
sfw_check( 'panel rendered above the form', 0 === strpos( $out, '<div class="tzfd-svc-finder"' ) && false !== strpos( $out, $form ) );
sfw_check( 'no hidden service field without a selection', 0 === sfw_hidden_count( $out ) );
sfw_check( 'no results region without a search', false === strpos( $out, 'tzfd-svc-results' ) );
sfw_check( 'page ID carried as hidden field on the search form', false !== strpos( $out, 'name="page_id" value="7"' ) );

// 2. Valid selection adds the hidden field inside the booking form.
$out = sfw_run( array( 'tzfd_service' => '5' ), $form );
sfw_check( 'valid selection writes hidden service ID', 1 === sfw_hidden_count( $out ) && false !== strpos( $out, 'name="tzfd_service_id" value="5"' ) );
sfw_check( 'hidden field sits inside the booking form tag', false !== strpos( $out, '<form method="post" action="/book/"><input type="hidden" name="tzfd_service_id" value="5" />' ) );
sfw_check( 'each booking form gets the hidden field', 2 === sfw_hidden_count( sfw_run( array( 'tzfd_service' => '5' ), $two ) ) );

// 3. Invalid selections never reach the form.
foreach ( array( '99', '6', '9', 'abc', '5;<script>', '0', '-5', '5 OR 1=1', '' ) as $bad ) {
	$out = sfw_run( array( 'tzfd_service' => $bad ), $form );
	sfw_check( 'selection rejected: ' . ( '' === $bad ? '(empty)' : $bad ), 0 === sfw_hidden_count( $out ) );
}
sfw_check( 'array input for selection rejected', 0 === sfw_hidden_count( sfw_run( array( 'tzfd_service' => array( '5' ) ), $form ) ) );

// 4. Search: one match shows a deep link that carries the selection and keeps the page ID.
$out = sfw_run( array( 'tzfd_svc_q' => 'I need a deep clean' ), $form );
sfw_check( 'single match offers a booking link', false !== strpos( $out, 'Book Deep Clean' ) );
sfw_check( 'link carries service ID and page ID', false !== strpos( $out, 'page_id=7' ) && false !== strpos( $out, 'tzfd_service=5' ) );
sfw_check( 'search alone does not write the hidden field', 0 === sfw_hidden_count( $out ) );
sfw_check( 'archived service never offered', false === strpos( sfw_run( array( 'tzfd_svc_q' => 'carpet wash' ), $form ), 'Old Carpet Wash' ) );

// 5. No match gives a plain message and no link.
$out = sfw_run( array( 'tzfd_svc_q' => 'garden lawn mowing' ), $form );
sfw_check( 'no match shows fallback message', false !== strpos( $out, 'No matching service was found' ) && false === strpos( $out, 'Book ' ) );

// 6. Markup escaping: the search text is never echoed raw.
$out = sfw_run( array( 'tzfd_svc_q' => '<script>alert(1)</script>' ), $form );
sfw_check( 'search text is escaped', false === strpos( $out, '<script' ) && false !== strpos( $out, '&lt;script&gt;' ) );

// 7. Length limit on the search text.
$_GET = array( 'tzfd_svc_q' => str_repeat( 'x', 500 ) );
$out  = TZFD_Service_Finder_Wiring::filter_form( $form, 1 );
sfw_check( 'search text limited to 300 characters', false !== strpos( $out, 'value="' . str_repeat( 'x', 300 ) . '"' ) && false === strpos( $out, str_repeat( 'x', 301 ) ) );

// 8. Non-HTML input is returned unchanged; a non-array catalogue is treated as empty.
sfw_check( 'non-string form output returned unchanged', null === TZFD_Service_Finder_Wiring::filter_form( null, 1 ) );
add_filter( 'tzfd_service_finder_services', function () { return 'not-an-array'; } );
$_GET = array( 'tzfd_service' => '5' );
$out  = TZFD_Service_Finder_Wiring::filter_form( $form, 1 );
sfw_check( 'non-array catalogue gives no selection', 0 === sfw_hidden_count( $out ) );

echo "\n{$GLOBALS['sfw_pass']} passed, {$GLOBALS['sfw_fail']} failed\n";
exit( $GLOBALS['sfw_fail'] > 0 ? 1 : 0 );
