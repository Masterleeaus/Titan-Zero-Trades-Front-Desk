<?php
/**
 * Regression guard for issue #191: the runtime test files must not depend on a hard-coded server path,
 * and each of the three files the issue names must be wired into a CI step that gates on its output.
 * Pure PHP, no WordPress needed: php tests/unit/runtime-191-paths-test.php
 * Exits 1 if any check fails.
 */

$repo    = dirname( __DIR__, 2 );
$runtime = $repo . '/tests/runtime';
$wf      = (string) file_get_contents( $repo . '/.github/workflows/tests.yml' );
$fails   = 0;
$passes  = 0;
$check   = function ( $ok, $name ) use ( &$fails, &$passes ) {
	if ( $ok ) {
		++$passes;
	} else {
		++$fails;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
};

$files = array( 'cancel-reschedule-assistant-tests.php', 'quote-request-classifier-tests.php', 'service-finder-runtime.php' );

// 1. No file in tests/runtime uses the old hard-coded server path.
$hard = array();
foreach ( glob( $runtime . '/*' ) as $path ) {
	if ( is_file( $path ) && false !== strpos( (string) file_get_contents( $path ), '/var/www' ) ) {
		$hard[] = basename( $path );
	}
}
$check( array() === $hard, 'no tests/runtime file contains a hard-coded /var/www path' . ( $hard ? ' (' . implode( ', ', $hard ) . ')' : '' ) );

// 2. Each named file exists and is referenced by the tests workflow.
foreach ( $files as $file ) {
	$check( is_file( $runtime . '/' . $file ), "$file exists" );
	$check( false !== strpos( $wf, $file ), "$file is named in .github/workflows/tests.yml" );
}

// 3. The WordPress step gates on FAIL lines and requires at least one PASS line.
$check( false !== strpos( $wf, 'grep -q \'^FAIL \' "$LOG"' ) && false !== strpos( $wf, 'No PASS lines: the files did not run' ), 'WordPress step for #191 files fails on FAIL lines and on zero PASS lines' );

// 4. The pure-PHP file runs as a plain CLI check with the plugin's own includes folder.
$check( false !== strpos( (string) file_get_contents( $runtime . '/quote-request-classifier-tests.php' ), "dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/" ), 'quote-request classifier default path resolves inside this checkout' );

echo "Summary: $passes passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
