<?php
/**
 * Pure tests for empty-state artwork (issue #178): each empty state uses artwork whose caption names its own
 * item type. Workers and Equipment no longer reuse the Customers and Services illustrations.
 */
require_once __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url ) { return (string) $url; } }
if ( ! function_exists( 'plugin_dir_url' ) ) { function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/titan-zero-trades-front-desk/'; } }

function tzfd_test_empty_state_artwork() {
	$root    = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	$art_dir = $root . '/images/ui-graphics/05-Empty-States/';
	require_once $root . '/includes/class-tzfd-local-brand.php';

	$render = function ( $type ) {
		ob_start();
		TZFD_Local_Brand::empty_state( $type, 'message' );
		return ob_get_clean();
	};
	$asset_for = function ( $type ) use ( $render ) {
		preg_match( '#05-Empty-States/([a-z-]+\.png)#', $render( $type ), $m );
		return isset( $m[1] ) ? $m[1] : '';
	};

	$expected = array(
		'workers'   => array( 'workers-empty.png', 'No team members yet' ),
		'equipment' => array( 'equipment-empty.png', 'No equipment or rooms yet' ),
		'customers' => array( 'users-empty.png', 'No customers' ),
		'services'  => array( 'briefcase-empty.png', 'No services' ),
	);
	foreach ( $expected as $type => $pair ) {
		list( $file, $caption ) = $pair;
		tzfd_assert_same( $file, $asset_for( $type ), "empty state '$type' uses $file" );
		tzfd_assert( is_file( $art_dir . $file ), "artwork file $file exists" );
		$svg = str_replace( '.png', '.svg', $file );
		tzfd_assert( is_file( $art_dir . $svg ), "source $svg exists" );
		if ( is_file( $art_dir . $svg ) ) {
			tzfd_assert( false !== strpos( file_get_contents( $art_dir . $svg ), '>' . $caption . '<' ), "artwork $svg is captioned '$caption'" );
		}
	}

	tzfd_assert( false === strpos( file_get_contents( $art_dir . 'workers-empty.svg' ), 'No customers' ), 'workers artwork does not say No customers' );
	tzfd_assert( false === strpos( file_get_contents( $art_dir . 'equipment-empty.svg' ), 'No services' ), 'equipment artwork does not say No services' );
}
