<?php
/**
 * Release gate for issue #9: translation template and text domain are consistent with the release.
 *
 * Pure PHP, no WordPress needed:
 *   php tests/unit/release-i18n-gate-test.php
 * Exits 1 if any check fails.
 */

$root   = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
$slug   = 'titan-zero-trades-front-desk';
$fails  = 0;
$passes = 0;
$check  = function ( $ok, $name, $detail = '' ) use ( &$fails, &$passes ) {
	if ( $ok ) {
		$passes++;
	} else {
		$fails++;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
};

// 1. Version constant, translation template header and readme agree.
$main    = (string) file_get_contents( $root . '/titan-zero-front-desk.php' );
$pot     = (string) file_get_contents( $root . '/languages/titan-zero-trades-front-desk.pot' );
$readme  = (string) file_get_contents( $root . '/readme.txt' );
$version = preg_match( "/define\(\s*'TZFD_VERSION',\s*'([^']+)'/", $main, $m ) ? $m[1] : '';
$check( '' !== $version, 'TZFD_VERSION found in the main plugin file', $version );
$check( false !== strpos( $pot, 'Project-Id-Version: Scheduling & Enquiries Manager ' . $version . '\n' ), 'POT Project-Id-Version matches TZFD_VERSION', $version );
$check( false !== strpos( $pot, 'X-Domain: ' . $slug . '\n' ), 'POT X-Domain is the plugin slug' );
$check( (bool) preg_match( '/^Stable tag:\s*' . preg_quote( $version, '/' ) . '\s*$/m', $readme ), 'readme Stable tag matches TZFD_VERSION' );

// 2. Every translation call with a literal text domain uses the plugin slug.
$functions = '__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e';
$pattern   = '/\b(?:' . $functions . ')\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*\'([^\']*)\'\s*\)/';
$wrong     = array();
$calls     = 0;
$files     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) { continue; }
	$src = (string) file_get_contents( $file->getPathname() );
	if ( preg_match_all( $pattern, $src, $hits ) ) {
		foreach ( $hits[1] as $domain ) {
			$calls++;
			if ( $slug !== $domain ) { $wrong[] = $file->getFilename() . ':' . $domain; }
		}
	}
}
$check( $calls > 1000, 'translation calls found in source', (string) $calls );
$check( array() === $wrong, 'no translation call uses another text domain', implode( ', ', array_slice( $wrong, 0, 5 ) ) );

echo "Summary: $passes passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
