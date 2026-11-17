<?php
/**
 * Name gate for the Scheduling & Enquiries Manager rename (issue #180).
 *
 * Scans every PHP string literal and inline HTML block in the plugin (comments are ignored)
 * and fails if any still contains a retired plugin name. Also checks that the status emails
 * title, which the admin menu sets for the browser tab, uses the new name.
 *
 * Pure PHP, no WordPress needed:
 *   php tests/unit/user-visible-names-test.php
 * Exits 1 if any check fails.
 */

$root   = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
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

// Retired names. Readme.txt is not scanned: its FAQ lines are a decision still open (issue #180).
$retired = array( 'Titan Front Desk', 'Titan Zero Front Desk', 'Booking Base', 'Titan Zero Trades: Front Desk' );

$hits  = array();
$files = 0;
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' !== $file->getExtension() ) { continue; }
	$files++;
	$path   = $file->getPathname();
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	foreach ( $tokens as $t ) {
		if ( ! is_array( $t ) ) { continue; }
		if ( T_CONSTANT_ENCAPSED_STRING !== $t[0] && T_INLINE_HTML !== $t[0] && T_ENCAPSED_AND_WHITESPACE !== $t[0] ) { continue; }
		foreach ( $retired as $old ) {
			if ( false !== stripos( $t[1], $old ) ) {
				$hits[] = substr( $path, strlen( $root ) + 1 ) . ':' . $t[2] . ' "' . $old . '"';
			}
		}
	}
}
$check( $files > 50, 'plugin PHP files scanned', (string) $files );
$check( array() === $hits, 'no retired plugin name in PHP string literals or inline HTML', implode( ', ', array_slice( $hits, 0, 10 ) ) );

// The status emails title is set on the admin page and must carry the new name.
$menu = (string) file_get_contents( $root . '/includes/class-tzfd-admin-menu.php' );
$check( false !== strpos( $menu, "'Scheduling & Enquiries Manager Status Emails'" ), 'admin menu status emails title uses the new name' );
$check( false === strpos( $menu, 'Titan Zero Front Desk Status Emails' ), 'admin menu has no old status emails title' );

// The redirect header value names the plugin; it must use the new name.
$main = (string) file_get_contents( $root . '/tzfd-main-class.inc.php' );
$check( false !== strpos( $main, "303, 'Scheduling & Enquiries Manager' )" ), 'redirect header names the new plugin' );

echo "Summary: $passes passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
