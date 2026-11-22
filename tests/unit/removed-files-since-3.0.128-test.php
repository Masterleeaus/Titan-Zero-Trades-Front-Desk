<?php
/**
 * Issue #443: the 15 files removed since 3.0.128 stay removed, the v3.0.128 baseline still records them,
 * and no shipped plugin file still loads or names them.
 * Pure PHP, no WordPress needed: php tests/unit/removed-files-since-3.0.128-test.php
 * Exits 1 if any check fails.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

$root     = dirname( __DIR__, 2 );
$plugin   = $root . '/titan-zero-trades-front-desk';
$manifest = $root . '/docs/provenance/SHA256SUMS-v3.0.128.txt';
$record   = $root . '/docs/PROVENANCE-RECORD.md';

$removed = array(
	'addons/assistant/ai-assistant.addon.php',
	'addons/assistant/index.html',
	'addons/core/installer.addon.php',
	'css/tzfd-smart-wizards.css',
	'includes/class-tzfd-ai-conversation-integrity.php',
	'includes/class-tzfd-ai-governor.php',
	'includes/class-tzfd-ai-image-input.php',
	'includes/class-tzfd-ai-options.php',
	'includes/class-tzfd-ai-provider-dispatch.php',
	'includes/class-tzfd-receptionist-schema.php',
	'includes/class-tzfd-smart-wizards.php',
	'includes/class-tzfd-workspace-site-ajax.php',
	'js/inline/ai-assistant.js',
	'js/inline/core-addons-installer.js',
	'js/tzfd-smart-wizards.js',
);

$fails  = 0;
$passes = 0;
$check  = function ( $ok, $name ) use ( &$fails, &$passes ) {
	if ( $ok ) { ++$passes; echo "PASS $name\n"; } else { ++$fails; echo "FAIL $name\n"; }
};

// 1. Each removed path is absent from the shipped plugin folder.
foreach ( $removed as $rel ) {
	$check( ! file_exists( $plugin . '/' . $rel ), 'removed file is absent: ' . $rel );
}

// 2. The v3.0.128 baseline manifest still lists each removed path (the record of what was removed).
$baseline = array();
if ( is_readable( $manifest ) ) {
	foreach ( file( $manifest, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( preg_match( '/^([0-9a-f]{64})\s+(.+)$/', $line, $m ) ) {
			$baseline[ $m[2] ] = $m[1];
		}
	}
}
$check( count( $baseline ) === 720, 'v3.0.128 baseline manifest has 720 entries' );
foreach ( $removed as $rel ) {
	$check( isset( $baseline[ 'titan-zero-trades-front-desk/' . $rel ] ) || isset( $baseline[ $rel ] ), 'baseline manifest records: ' . $rel );
}

// 3. No shipped plugin file loads or names a removed file (scan by basename, which is how PHP and JS reference them).
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
$hits  = array();
foreach ( $files as $file ) {
	if ( ! preg_match( '/\.(php|js|html|txt|json)$/i', $file->getFilename() ) ) { continue; }
	// Release notes may list removed files (issue #434 requires it). Only these two root files are exempt.
	if ( in_array( substr( $file->getPathname(), strlen( $plugin ) + 1 ), array( 'readme.txt', 'changelog.txt' ), true ) ) { continue; }
	$body = (string) file_get_contents( $file->getPathname() );
	foreach ( $removed as $rel ) {
		if ( strpos( $body, basename( $rel ) ) !== false ) {
			$hits[] = substr( $file->getPathname(), strlen( $plugin ) + 1 ) . ' names ' . basename( $rel );
		}
	}
}
$check( count( $hits ) === 0, 'no shipped file names a removed file' . ( $hits ? ' | ' . implode( '; ', $hits ) : '' ) );

// 4. The provenance record carries the owner confirmation for issue #443.
$rec = is_readable( $record ) ? (string) file_get_contents( $record ) : '';
$check( strpos( $rec, 'issue #443, comment 7083447940' ) !== false, 'provenance record cites the owner confirmation for #443' );

echo "\n$passes passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
