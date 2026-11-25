<?php
/**
 * Calendar screen (issue #179): service-type colours, today's colour, and the narrow-screen grid and toolbar.
 * Pure PHP: the booking view class loads without WordPress, and the CSS is read from admin-layout as text.
 * Run through tests/unit/run.php, which calls every tzfd_test_* function.
 */
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = '' ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }

/** WCAG 2.x contrast ratio between two six-digit hex colours. */
function tzfd_cal_contrast( $hex_a, $hex_b ) {
	$lum = function ( $hex ) {
		$hex = ltrim( $hex, '#' );
		$c   = array_map( function ( $i ) use ( $hex ) { $v = hexdec( substr( $hex, $i, 2 ) ) / 255; return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ); }, array( 0, 2, 4 ) );
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	};
	$a = $lum( $hex_a );
	$b = $lum( $hex_b );
	return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
}

function tzfd_test_calendar_colours() {
	$root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	require_once $root . '/includes/class-tzfd-booking-view.php';
	$V = 'TZFD_Booking_View';

	/* Colour by service type: stable, case and space insensitive, and every slot reachable (eight, not four). */
	$slots    = array();
	$in_range = true;
	for ( $i = 0; $i < 400; $i++ ) {
		$slot = $V::service_colour_slot( 'Service type ' . $i );
		$slots[ $slot ] = true;
		$in_range = $in_range && $slot >= 1 && $slot <= $V::SERVICE_COLOURS;
	}
	tzfd_assert( $in_range, 'every service slot for 400 names is in 1 to ' . $V::SERVICE_COLOURS );
	tzfd_assert_same( $V::SERVICE_COLOURS, count( $slots ), 'every service colour slot is used by some service name' );
	tzfd_assert_same( 8, $V::SERVICE_COLOURS, 'eight service colour slots are defined' );
	tzfd_assert_same( $V::service_colour_slot( 'Drain Clearing' ), $V::service_colour_slot( '  drain clearing ' ), 'service name is compared without case or surrounding spaces' );
	tzfd_assert_same( $V::service_colour_slot( 'Gutter clean' ), $V::service_colour_slot( 'Gutter clean' ), 'same service name always gets the same slot' );
	tzfd_assert_same( $V::service_colour_slot( '' ), $V::service_colour_slot( null ), 'empty and null service names share a stable slot' );

	/* The chip uses the slot function, not the old four-way crc32 mask. */
	$src = file_get_contents( $root . '/includes/class-tzfd-booking-view.php' );
	tzfd_assert( false !== strpos( $src, "' tzfd-svc-' . self::service_colour_slot( \$item['service'] )" ), 'chips take their colour from the service slot' );
	tzfd_assert( false === strpos( $src, 'crc32( (string) $item[\'service\'] ) & 3' ), 'the old four-colour mask is gone' );

	/* Stylesheet: eight service styles, each with readable text on its background. */
	$admin = file_get_contents( $root . '/includes/class-tzfd-admin-layout.php' );
	$start = strpos( $admin, '/* Calendar */' );
	$end   = strpos( $admin, '/* Customer details and search */' );
	$cal   = ( false !== $start && false !== $end ) ? substr( $admin, $start, $end - $start ) : '';
	tzfd_assert( '' !== $cal, 'calendar CSS block is found in admin-layout' );
	preg_match_all( '/\.tzfd-screen \.tzfd-svc-(\d) \{ background:#([0-9a-fA-F]{6}) !important; color:#([0-9a-fA-F]{6}) !important; \}/', $cal, $m, PREG_SET_ORDER );
	$found = array();
	foreach ( $m as $row ) {
		$found[ (int) $row[1] ] = true;
		$ratio = tzfd_cal_contrast( '#' . $row[3], '#' . $row[2] );
		tzfd_assert( $ratio >= 4.5, 'service colour ' . $row[1] . ' text is at least 4.5:1 on its background (got ' . round( $ratio, 2 ) . ')' );
	}
	tzfd_assert_same( range( 1, 8 ), array_keys( $found ), 'stylesheet defines tzfd-svc-1 to tzfd-svc-8, and no more' );

	/* Today: brand cyan, not the old dark red. */
	tzfd_assert( false !== strpos( $cal, '.tzfd-screen .tzfd-cal-today { background:#0c2c3c; }' ), 'today column uses the cyan tint' );
	tzfd_assert( false === stripos( $cal, '#2b1d21' ) && false === stripos( $cal, '#E31B23' ), 'calendar CSS no longer uses the dark red today colour or the red accent fallback' );
	tzfd_assert( tzfd_cal_contrast( '#00cfe8', '#232834' ) >= 4.5, 'today label (cyan) on the panel meets 4.5:1' );

	/* Narrow screens: the hint is hidden on wide screens, shown below 783px, and the toolbar can wrap. */
	$media = strpos( $cal, '@media screen and (max-width:782px)' );
	tzfd_assert( false !== strpos( $cal, '.tzfd-screen .tzfd-cal-hint { display:none;' ), 'scroll hint is hidden by default' );
	tzfd_assert( false !== $media && false !== strpos( substr( $cal, $media ), '.tzfd-screen .tzfd-cal-hint { display:block; }' ), 'scroll hint is shown below 783px' );
	tzfd_assert( false !== strpos( $cal, '.tzfd-screen .tzfd-cal-views { display:flex; flex-wrap:wrap;' ), 'view switch wraps instead of running off the screen' );
	tzfd_assert( false !== strpos( $cal, '.tzfd-screen .tzfd-cal-nav { display:flex; flex-wrap:wrap; gap:6px; align-items:center; min-width:0; max-width:100%; }' ), 'nav (back arrow, today, next) can wrap within the toolbar' );
	tzfd_assert( false !== strpos( $cal, 'min-width:640px; table-layout:fixed;' ) && false !== strpos( $cal, '.tzfd-cal-scroll { overflow-x:auto;' ), 'wide grid still scrolls sideways inside its card' );

	/* The hint is printed above both scrolling grids, with its text. */
	$hint = $V::scroll_hint();
	tzfd_assert( false !== strpos( $hint, 'class="tzfd-cal-hint"' ) && false !== strpos( $hint, 'Swipe sideways to see every day.' ), 'scroll hint markup and text' );
	tzfd_assert_same( 2, substr_count( $src, 'echo wp_kses_post( self::scroll_hint() );' ), 'week and month grids both print the scroll hint' );
}
