<?php
/**
 * Issue #434 gate: every public name and plugin file removed since 3.0.128 is listed in the
 * 3.1.0 entry of changelog.txt and readme.txt. The expected names are in
 * tests/unit/fixtures/removed-api-3.0.128.txt, which tools/release/removed-api-diff.sh generates.
 *
 * Pure PHP, no WordPress needed:
 *   php tests/unit/removed-api-changelog-test.php
 * Exits 1 if any check fails.
 */

$root    = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
$fixture = __DIR__ . '/fixtures/removed-api-3.0.128.txt';
$fails   = 0;
$passes  = 0;
$check   = function ( $ok, $name, $detail = '' ) use ( &$fails, &$passes ) {
	if ( $ok ) {
		++$passes;
	} else {
		++$fails;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
};

/**
 * Returns the text of the 3.1.0 entry: from its heading up to the next version heading.
 */
$entry_310 = function ( $text ) {
	$start = strpos( $text, '= 3.1.0 =' );
	if ( false === $start ) {
		return '';
	}
	$next = preg_match( '/^= \d+(?:\.\d+)+ =\s*$/m', $text, $m, PREG_OFFSET_CAPTURE, $start + 9 )
		? $m[0][1]
		: strlen( $text );
	return substr( $text, $start, $next - $start );
};

/**
 * Returns the names a changelog section does not mention. A name counts as mentioned when it
 * appears as a whole word (no letter, digit or underscore on either side).
 */
$missing_names = function ( $section, $names ) {
	$missing = array();
	foreach ( $names as $name ) {
		if ( ! preg_match( '/(?<![A-Za-z0-9_])' . preg_quote( $name, '/' ) . '(?![A-Za-z0-9_])/', $section ) ) {
			$missing[] = $name;
		}
	}
	return $missing;
};

$lines = array_filter( array_map( 'trim', (array) file( $fixture ) ) );
$pairs = array();
foreach ( $lines as $line ) {
	$parts = explode( ' ', $line, 2 );
	if ( 2 === count( $parts ) ) {
		$pairs[] = array( 'kind' => $parts[0], 'name' => $parts[1] );
	}
}
$names = array_map( function ( $p ) { return $p['name']; }, $pairs );

$check( count( $pairs ) > 0, 'Fixture lists removed names', count( $pairs ) . ' entries' );
$check( in_array( 'tz_fd_ai_provider', $names, true ), 'Fixture includes the tz_fd_ai_provider filter (issue #434 example)' );
$check( in_array( 'includes/class-tzfd-ai-governor.php', $names, true ), 'Fixture includes the removed AI governor file (issue #434 example)' );

foreach ( array( 'changelog.txt', 'readme.txt' ) as $file ) {
	$text    = (string) file_get_contents( $root . '/' . $file );
	$section = $entry_310( $text );
	$check( '' !== $section, "$file has a 3.1.0 entry" );

	$missing = $missing_names( $section, $names );
	$check(
		array() === $missing,
		"$file 3.1.0 entry lists every removed name",
		array() === $missing ? count( $names ) . ' names' : 'missing: ' . implode( ', ', array_slice( $missing, 0, 20 ) )
	);

	$check( false !== strpos( $section, 'issue #434' ), "$file 3.1.0 entry cites issue #434" );
}

// Negative check: the same matcher must flag a name that is absent, and must not match a
// longer name that only contains it.
$sample = "* Removed functions and methods (1): chat_extra.";
$check( array( 'chat' ) === $missing_names( $sample, array( 'chat' ) ), 'Matcher flags a name missing from a section' );
$check( array() === $missing_names( "* Removed functions and methods (1): chat.", array( 'chat' ) ), 'Matcher accepts a listed name' );

echo "\n$passes passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
