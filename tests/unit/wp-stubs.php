<?php
/**
 * In-memory WordPress function stubs for the Free AI lane unit tests.
 * Only the functions the lane classes call are defined. Behaviour matches WordPress for these inputs.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

$GLOBALS['tzfd_test_options'] = array();
$GLOBALS['tzfd_test_filters'] = array();
$GLOBALS['tzfd_test_actions'] = array();
$GLOBALS['tzfd_test_caps']    = false;

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['tzfd_test_options'] ) ? $GLOBALS['tzfd_test_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['tzfd_test_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['tzfd_test_options'][ $name ] );
	return true;
}
function add_action( $hook, $cb ) {
	$GLOBALS['tzfd_test_actions'][ $hook ][] = $cb;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['tzfd_test_fired'][] = array( $hook, $args );
	foreach ( $GLOBALS['tzfd_test_actions'][ $hook ] ?? array() as $cb ) {
		call_user_func_array( $cb, $args );
	}
}
function add_filter( $hook, $cb ) {
	$GLOBALS['tzfd_test_filters'][ $hook ] = $cb;
}
function apply_filters( $hook, $value, ...$args ) {
	if ( ! isset( $GLOBALS['tzfd_test_filters'][ $hook ] ) ) {
		return $value;
	}
	$GLOBALS['tzfd_test_filter_calls'][ $hook ] = ( $GLOBALS['tzfd_test_filter_calls'][ $hook ] ?? 0 ) + 1;
	return call_user_func( $GLOBALS['tzfd_test_filters'][ $hook ], $value, ...$args );
}
function current_user_can( $cap ) {
	return (bool) $GLOBALS['tzfd_test_caps'];
}
function wp_strip_all_tags( $s ) {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function __( $s, $domain = '' ) {
	return $s;
}
