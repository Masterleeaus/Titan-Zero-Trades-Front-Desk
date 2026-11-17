<?php
/**
 * Release metadata check for issue #9. Confirms that the plugin header, the TZFD_VERSION constant,
 * the readme.txt Stable tag and its latest changelog entry agree, that the text domain matches the
 * plugin slug, and that the readme follows WordPress.org limits (at most five tags).
 * Pure PHP, no WordPress needed. Run: php tests/release/check-release-metadata.php
 * Exits 0 when every check passes, 1 otherwise.
 */

$plugin_dir = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
$main_file  = $plugin_dir . '/titan-zero-front-desk.php';
$readme     = $plugin_dir . '/readme.txt';

$failures = array();
$passes   = 0;

$check = static function ( $ok, $message ) use ( &$failures, &$passes ) {
	if ( $ok ) {
		++$passes;
	} else {
		$failures[] = $message;
	}
};

$main   = (string) file_get_contents( $main_file );
$readme_text = (string) file_get_contents( $readme );

$header = static function ( $text, $field ) {
	return preg_match( '/^\s*\*?\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/mi', $text, $m ) ? $m[1] : null;
};

$header_version   = $header( $main, 'Version' );
$header_domain    = $header( $main, 'Text Domain' );
$header_requires  = $header( $main, 'Requires at least' );
$header_php       = $header( $main, 'Requires PHP' );

$const_version = preg_match( "/define\(\s*'TZFD_VERSION',\s*'([^']+)'\s*\)/", $main, $m ) ? $m[1] : null;

$stable_tag   = preg_match( '/^Stable tag:\s*(\S+)/mi', $readme_text, $m ) ? $m[1] : null;
$readme_requires = preg_match( '/^Requires at least:\s*(\S+)/mi', $readme_text, $m ) ? $m[1] : null;
$readme_php      = preg_match( '/^Requires PHP:\s*(\S+)/mi', $readme_text, $m ) ? $m[1] : null;
$readme_tags_line = preg_match( '/^Tags:\s*(.+)$/mi', $readme_text, $m ) ? $m[1] : '';

$changelog_top = null;
if ( preg_match( '/^== Changelog ==\s*$(.*?)^== /ms', $readme_text, $section ) ) {
	if ( preg_match( '/^= (\d+(?:\.\d+)+) =\s*$/m', $section[1], $v ) ) {
		$changelog_top = $v[1];
	}
}

$check( null !== $header_version, 'Plugin header has no Version field.' );
$check( null !== $const_version, 'TZFD_VERSION constant not found in the main plugin file.' );
$check( null !== $stable_tag, 'readme.txt has no Stable tag.' );
$check( null !== $changelog_top, 'readme.txt Changelog has no versioned entry.' );
$check( $header_version === $const_version, sprintf( 'Header Version (%s) differs from TZFD_VERSION (%s).', $header_version, $const_version ) );
$check( $header_version === $stable_tag, sprintf( 'Header Version (%s) differs from readme Stable tag (%s).', $header_version, $stable_tag ) );
$check( $header_version === $changelog_top, sprintf( 'Header Version (%s) differs from the latest readme changelog entry (%s).', $header_version, $changelog_top ) );
$check( $header_requires === $readme_requires, sprintf( 'Header Requires at least (%s) differs from readme (%s).', $header_requires, $readme_requires ) );
$check( $header_php === $readme_php, sprintf( 'Header Requires PHP (%s) differs from readme (%s).', $header_php, $readme_php ) );
$check( basename( $plugin_dir ) === $header_domain, sprintf( 'Text Domain (%s) does not match the plugin folder slug (%s).', $header_domain, basename( $plugin_dir ) ) );

$tags = array_filter( array_map( 'trim', explode( ',', $readme_tags_line ) ) );
$check( count( $tags ) > 0 && count( $tags ) <= 5, sprintf( 'readme.txt has %d tags; WordPress.org allows at most 5.', count( $tags ) ) );

$check( (bool) preg_match( '/^=== .+ ===$/m', $readme_text ), 'readme.txt has no === Plugin name === title line.' );

foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Changelog' ) as $required ) {
	$check( (bool) preg_match( '/^== ' . preg_quote( $required, '/' ) . ' ==\s*$/m', $readme_text ), "readme.txt is missing the == $required == section." );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . PHP_EOL );
	}
	fwrite( STDERR, sprintf( "%d passed, %d failed.\n", $passes, count( $failures ) ) );
	exit( 1 );
}

echo sprintf( "Release metadata OK: %d checks passed (version %s).\n", $passes, $header_version );
exit( 0 );
