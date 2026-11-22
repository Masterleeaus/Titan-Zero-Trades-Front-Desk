<?php
/**
 * Issue #173: legacy AI data is never deleted automatically. Static checks on the class source, run by tests/unit/run.php.
 *   1. No hook that runs on page load (init, admin_init, wp_loaded, plugins_loaded, wp, shutdown) is registered.
 *   2. Option deletes exist only in delete_scope(), which is gated by the backup receipt and the typed phrase.
 *   3. Table deletes exist only in delete_scope() (admin, gated) and privacy_erase() (exact email, on request).
 * Behaviour on a real WordPress site is covered by tests/runtime/legacy-ai-runtime-tests.php.
 */

if ( ! function_exists( 'tzfd_assert' ) ) {
	// Standalone run (php tests/unit/test-legacy-ai-no-auto-delete.php): same contract as run.php, exit 1 on any failure.
	$GLOBALS['tzfd_ai_standalone_fail'] = 0;
	function tzfd_assert( $condition, $message ) {
		if ( $condition ) {
			echo 'PASS ' . $message . "\n";
		} else {
			++$GLOBALS['tzfd_ai_standalone_fail'];
			echo 'FAIL ' . $message . "\n";
		}
	}
	register_shutdown_function( function () {
		exit( $GLOBALS['tzfd_ai_standalone_fail'] > 0 ? 1 : 0 );
	} );
}

$tzfd_ai_src_file = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-legacy-ai-data.php';
$tzfd_ai_src      = is_readable( $tzfd_ai_src_file ) ? (string) file_get_contents( $tzfd_ai_src_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file read by the test runner.

tzfd_assert( '' !== $tzfd_ai_src, 'legacy-ai no-auto-delete: class source readable' );

/* Split the class into method bodies keyed by method name. */
$tzfd_ai_parts = preg_split( '/\n\s*(?:public|private|protected) static function (\w+)\(/', $tzfd_ai_src, -1, PREG_SPLIT_DELIM_CAPTURE );
$tzfd_ai_body  = array();
for ( $tzfd_ai_i = 1, $tzfd_ai_n = count( $tzfd_ai_parts ); $tzfd_ai_i + 1 < $tzfd_ai_n; $tzfd_ai_i += 2 ) {
	$tzfd_ai_body[ $tzfd_ai_parts[ $tzfd_ai_i ] ] = $tzfd_ai_parts[ $tzfd_ai_i + 1 ];
}

/* 1. Hooks that fire on every page load, or before an admin screen opens, must not be registered by this class. */
preg_match_all( "/add_(?:action|filter)\(\s*'([a-z_]+)'/", $tzfd_ai_src, $tzfd_ai_hooks );
$tzfd_ai_on_load = array( 'init', 'admin_init', 'wp_loaded', 'plugins_loaded', 'wp', 'shutdown', 'after_setup_theme' );
$tzfd_ai_bad     = array_values( array_intersect( $tzfd_ai_hooks[1], $tzfd_ai_on_load ) );
tzfd_assert( array() === $tzfd_ai_bad, 'legacy-ai no-auto-delete: no on-load hook registered (found: ' . implode( ',', $tzfd_ai_bad ) . ')' );
tzfd_assert( isset( $tzfd_ai_body['register'] ) && false === strpos( $tzfd_ai_body['register'], 'delete_option(' ) && false === stripos( $tzfd_ai_body['register'], 'DELETE FROM' ), 'legacy-ai no-auto-delete: register() performs no delete' );

/* 2. Option deletes: only in delete_scope(), and only after the receipt check. */
$tzfd_ai_option_deleters = array();
foreach ( $tzfd_ai_body as $tzfd_ai_name => $tzfd_ai_body_text ) {
	if ( false !== strpos( $tzfd_ai_body_text, "delete_option(" ) ) { $tzfd_ai_option_deleters[] = $tzfd_ai_name; }
}
tzfd_assert( array( 'delete_scope' ) === $tzfd_ai_option_deleters, 'legacy-ai no-auto-delete: delete_option only in delete_scope (found in: ' . implode( ',', $tzfd_ai_option_deleters ) . ')' );

$tzfd_ai_scope = isset( $tzfd_ai_body['delete_scope'] ) ? $tzfd_ai_body['delete_scope'] : '';
$tzfd_ai_gate  = strpos( $tzfd_ai_scope, 'backup_current()' );
$tzfd_ai_phr   = strpos( $tzfd_ai_scope, 'PHRASE' );
$tzfd_ai_del   = strpos( $tzfd_ai_scope, 'delete_option(' );
tzfd_assert( false !== $tzfd_ai_gate && false !== $tzfd_ai_phr && false !== $tzfd_ai_del && $tzfd_ai_gate < $tzfd_ai_del && $tzfd_ai_phr < $tzfd_ai_del, 'legacy-ai no-auto-delete: backup and phrase checks run before any option delete' );

/* 3. Row deletes: admin chat delete in delete_scope(), exact-address erase in privacy_erase(), nowhere else. */
$tzfd_ai_row_deleters = array();
foreach ( $tzfd_ai_body as $tzfd_ai_name => $tzfd_ai_body_text ) {
	if ( 1 === preg_match( "/DELETE\s+FROM/i", $tzfd_ai_body_text ) ) { $tzfd_ai_row_deleters[] = $tzfd_ai_name; }
}
sort( $tzfd_ai_row_deleters );
tzfd_assert( array( 'delete_scope', 'privacy_erase' ) === $tzfd_ai_row_deleters, 'legacy-ai no-auto-delete: row deletes only in delete_scope and privacy_erase (found in: ' . implode( ',', $tzfd_ai_row_deleters ) . ')' );

/* The privacy eraser matches an exact, lower-cased address, never a pattern. */
$tzfd_ai_erase = isset( $tzfd_ai_body['privacy_erase'] ) ? $tzfd_ai_body['privacy_erase'] : '';
tzfd_assert( false !== strpos( $tzfd_ai_erase, "LOWER(%i) = %s" ) && false === strpos( $tzfd_ai_erase, 'LIKE' ), 'legacy-ai no-auto-delete: privacy erase matches an exact address, not a LIKE pattern' );
