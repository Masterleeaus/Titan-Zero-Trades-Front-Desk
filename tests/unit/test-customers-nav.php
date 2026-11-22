<?php
/**
 * Issue #179 (owner decision): Customers is in the shared Front Desk navigation, after Enquiries, gated by tz_fd_access.
 * Reads the nav definition from source because the UI shell needs the TZFD_UI_Core base class, which these tests do not load.
 */
function tzfd_test_customers_nav() {
	$path = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/ui-pack/ui/tz-app-front-desk.php';
	$src  = (string) file_get_contents( $path );

	preg_match( '/function nav_items\(\) \{(.*?)\n\t\}/s', $src, $m );
	$block = isset( $m[1] ) ? $m[1] : '';
	tzfd_assert( '' !== $block, 'nav_items() is readable from the front-desk UI pack' );

	preg_match_all( "/'page' => '([^']+)'/", $block, $pages );
	$pages = $pages[1];

	$enq = array_search( 'tz_fd_enquiries', $pages, true );
	$cus = array_search( 'tz-customers', $pages, true );
	tzfd_assert( false !== $cus, 'Customers page is listed in the shared navigation' );
	tzfd_assert( false !== $enq && false !== $cus && $cus === $enq + 1, 'Customers sits directly after Enquiries' );

	preg_match( "/'page' => 'tz-customers'.*?'cap' => '([^']+)'/", $block, $cap );
	tzfd_assert_same( 'tz_fd_access', isset( $cap[1] ) ? $cap[1] : '', 'Customers nav item requires tz_fd_access' );
}
