<?php
/**
 * Pure check for issue #300: the default booking form must not ship the placeholder service "Service 1".
 *
 * A calendar with no stored form_structure falls back to TZFD_BOOK_DEFAULT_form_structure (titan-zero-front-desk.php),
 * and that fallback is what the admin Add Booking and the public appointment dropdown read. The check decodes the
 * fallback exactly as the plugin does and asserts that the appointment field has no services and no "Service 1" entry.
 */

$tzfd_root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
$tzfd_src  = (string) file_get_contents( $tzfd_root . '/titan-zero-front-desk.php' );

$tzfd_failed = 0;
function tzfd_300_ok( $name, $ok, $detail = '' ) {
	global $tzfd_failed;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ' ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
	if ( ! $ok ) { $tzfd_failed++; }
	return (bool) $ok;
}

// Read the define() literal the same way PHP would, without loading WordPress.
$matched = preg_match( "/define\\(\\s*'TZFD_BOOK_DEFAULT_form_structure'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/s", $tzfd_src, $m )
	|| preg_match( "/define\\(\\s*'TZFD_BOOK_DEFAULT_form_structure'\\s*,\\s*'(.*?)'\\s*\\)\\s*;/s", $tzfd_src, $m );
if ( ! $matched ) {
	tzfd_300_ok( 'default form: template constant found', false, 'define not found in titan-zero-front-desk.php' );
	exit( 1 );
}
$json = str_replace( "\\'", "'", $m[1] );
$form = json_decode( $json, true );
tzfd_300_ok( 'default form: template decodes as JSON', is_array( $form ) );

$appointment = null;
if ( is_array( $form ) && isset( $form[0] ) && is_array( $form[0] ) ) {
	foreach ( $form[0] as $field ) {
		if ( is_array( $field ) && ( $field['ftype'] ?? '' ) === 'fapp' ) { $appointment = $field; break; }
	}
}
tzfd_300_ok( 'default form: has one appointment (fapp) field', null !== $appointment );

$services = is_array( $appointment ) ? ( $appointment['services'] ?? null ) : null;
tzfd_300_ok( 'default form: appointment field has a services list', is_array( $services ) );
tzfd_300_ok( 'default form: new calendar starts with no services', is_array( $services ) && 0 === count( $services ) );

$names = is_array( $services ) ? array_map( function ( $s ) { return is_array( $s ) ? ( $s['name'] ?? '' ) : ''; }, $services ) : array();
tzfd_300_ok( 'default form: no "Service 1" placeholder listed', ! in_array( 'Service 1', $names, true ) );

exit( $tzfd_failed ? 1 : 0 );
