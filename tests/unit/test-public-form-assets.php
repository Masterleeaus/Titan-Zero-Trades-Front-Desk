<?php
/**
 * TZFD_Public_Form_Assets and the public enquiry and callback forms: the enqueue guard, the required-field marker,
 * the scope and contrast of the form stylesheet, and the wiring in the two shortcode renderers.
 * No WordPress is needed: wp_style_is, wp_enqueue_style and plugins_url are recorded in memory.
 */
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/titan-zero-trades-front-desk/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_style_is' ) ) { function wp_style_is( $handle, $list = 'enqueued' ) { return isset( $GLOBALS['tzfd_test_styles'][ $handle ] ); } }
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
		$GLOBALS['tzfd_test_style_calls'] = ( $GLOBALS['tzfd_test_style_calls'] ?? 0 ) + 1;
		$GLOBALS['tzfd_test_styles'][ $handle ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver );
	}
}

/** Relative luminance and contrast ratio, WCAG 2.x. */
function tzfd_contrast_ratio( $hex_a, $hex_b ) {
	$lum = function ( $hex ) {
		$hex = ltrim( $hex, '#' );
		$c = array_map( function ( $i ) use ( $hex ) { $v = hexdec( substr( $hex, $i, 2 ) ) / 255; return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ); }, array( 0, 2, 4 ) );
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	};
	$a = $lum( $hex_a );
	$b = $lum( $hex_b );
	return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
}

function tzfd_test_public_form_assets() {
	$root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	require_once $root . '/includes/class-tzfd-public-form-assets.php';
	$C = 'TZFD_Public_Form_Assets';

	/* Enqueue guard: both stylesheets load once per page, however many forms render. */
	$GLOBALS['tzfd_test_styles'] = array();
	$GLOBALS['tzfd_test_style_calls'] = 0;
	$C::enqueue();
	tzfd_assert( isset( $GLOBALS['tzfd_test_styles']['tzfd-public-forms'] ), 'enqueue loads the public form stylesheet' );
	tzfd_assert( isset( $GLOBALS['tzfd_test_styles']['tzfdapp-publicstyle'] ), 'enqueue loads the plugin public stylesheet' );
	tzfd_assert_same( 2, $GLOBALS['tzfd_test_style_calls'], 'first enqueue on a page makes exactly two style calls' );
	tzfd_assert_same( array( 'tzfdapp-publicstyle' ), $GLOBALS['tzfd_test_styles']['tzfd-public-forms']['deps'], 'form stylesheet depends on the public stylesheet' );
	tzfd_assert( false !== strpos( $GLOBALS['tzfd_test_styles']['tzfd-public-forms']['src'], 'css/tzfd-public-forms.css' ), 'form stylesheet path is css/tzfd-public-forms.css' );
	tzfd_assert( false !== strpos( $GLOBALS['tzfd_test_styles']['tzfdapp-publicstyle']['src'], 'css/stylepublic.css' ), 'public stylesheet path is css/stylepublic.css' );

	$GLOBALS['tzfd_test_style_calls'] = 0;
	$C::enqueue();
	$C::enqueue();
	tzfd_assert_same( 0, $GLOBALS['tzfd_test_style_calls'], 'second and third calls on the same page add no styles' );

	/* Required marker: visible text, marked up for styling. */
	$m = $C::required_marker();
	tzfd_assert( false !== strpos( $m, '(required)' ), 'required marker carries the visible text (required)' );
	tzfd_assert( false !== strpos( $m, 'class="tzfd-required-marker"' ), 'required marker has the tzfd-required-marker class' );

	/* Wiring: the marker and aria-required sit on each required field, and the enqueue runs only after the company check. */
	$lead = file_get_contents( $root . '/includes/class-tzfd-lead-intake.php' );
	$hand = file_get_contents( $root . '/includes/class-tzfd-human-handoff.php' );
	tzfd_assert_same( 3, substr_count( $lead, 'aria-required="true"' ), 'enquiry form marks name, email and message as aria-required' );
	tzfd_assert_same( 3, substr_count( $lead, '$req . ' ), 'enquiry form prints the required marker on name, email and message' );
	tzfd_assert_same( 2, substr_count( $hand, 'aria-required="true"' ), 'callback form marks name and consent as aria-required' );
	tzfd_assert_same( 2, substr_count( $hand, '$req . ' ), 'callback form prints the required marker on name and consent' );
	tzfd_assert_same( 1, substr_count( $lead, 'TZFD_Public_Form_Assets::enqueue();' ), 'enquiry shortcode enqueues the form assets once' );
	tzfd_assert_same( 1, substr_count( $hand, 'TZFD_Public_Form_Assets::enqueue();' ), 'callback shortcode enqueues the form assets once' );
	foreach ( array( 'enquiry' => $lead, 'callback' => $hand ) as $name => $src ) {
		$gate = strpos( $src, "company_id() < 1 ) { return ''; }" );
		$enq  = strpos( $src, 'TZFD_Public_Form_Assets::enqueue();' );
		tzfd_assert( false !== $gate && false !== $enq && $enq > $gate, $name . ' form enqueues only after it has decided to render' );
	}
	tzfd_assert( false !== strpos( $lead, 'required aria-required="true" maxlength="190" name="name"' ), 'enquiry name keeps its required attribute and field name' );
	tzfd_assert( false !== strpos( $hand, 'required aria-required="true" name="consent" value="1"' ), 'callback consent keeps its required attribute and field name' );

	/* Stylesheet: scoped to the two form classes, no page-wide selectors, a narrow-screen rule, and readable colour pairs. */
	$css = file_get_contents( $root . '/css/tzfd-public-forms.css' );
	$bare = preg_replace( '#/\*.*?\*/#s', '', $css );
	tzfd_assert( false !== strpos( $css, '.tzfd-public-enquiry' ) && false !== strpos( $css, '.tzfd-callback-form' ), 'stylesheet targets both form classes' );
	tzfd_assert( 0 === preg_match( '/(^|\})\s*(html|body|\*)(?![\w-])/m', $bare ),'stylesheet has no rule that starts at html, body or the universal selector' );
	tzfd_assert( false !== strpos( $bare, '@media (max-width: 479px)' ), 'stylesheet has a narrow-screen rule below 480px' );
	tzfd_assert( false !== strpos( $bare, '.tzfd-required-marker' ), 'stylesheet styles the required marker' );
	tzfd_assert( false !== strpos( $bare, 'prefers-color-scheme: dark' ), 'stylesheet has a dark-preference rule for the fields' );
	tzfd_assert( false === strpos( $bare, 'tzfd-hp' ), 'stylesheet does not touch the honeypot class' );

	$pairs = array(
		array( '#1d2327', '#ffffff', 4.5, 'light field text on field background' ),
		array( '#f9fafb', '#111827', 4.5, 'dark field text on field background' ),
		array( '#ffffff', '#1a56db', 4.5, 'button text on button background' ),
	);
	foreach ( $pairs as $p ) {
		tzfd_assert( false !== stripos( $bare, $p[0] ) && false !== stripos( $bare, $p[1] ), 'stylesheet uses the colour pair for ' . $p[3] );
		tzfd_assert( tzfd_contrast_ratio( $p[0], $p[1] ) >= $p[2], 'contrast of ' . $p[3] . ' is at least ' . $p[2] . ':1' );
	}
	tzfd_assert( tzfd_contrast_ratio( '#6b7280', '#ffffff' ) >= 3.0, 'field border on white meets the 3:1 non-text contrast' );
	tzfd_assert( tzfd_contrast_ratio( '#9ca3af', '#111827' ) >= 3.0, 'field border on dark field meets the 3:1 non-text contrast' );
}
