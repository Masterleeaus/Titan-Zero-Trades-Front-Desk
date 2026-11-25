<?php
/**
 * Issue #173: legacy AI data is never deleted automatically. Static checks on the surviving class source
 * (includes/class-tzfd-legacy-privacy.php), run by tests/unit/run.php.
 *   1. No hook that runs on page load (init, admin_init, wp_loaded, plugins_loaded, wp, shutdown, after_setup_theme)
 *      is registered, and no hook name is built at run time.
 *   2. Nothing deletes on load: register() only adds the exporter and eraser filters.
 *   3. The only delete in the class is the exact-email eraser, privacy_erase(). No LIKE pattern is used there, and
 *      no delete_option, wp_delete_* or TRUNCATE/DROP call exists anywhere in the class.
 * The class was split out of class-tzfd-legacy-ai-data.php (removed in #360). Behaviour on a real WordPress site is
 * covered by tests/runtime/legacy-ai-runtime-tests.php.
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

/**
 * Return the body of every function in $src, keyed by function name. Braces are matched, so a body is never cut
 * short by a later method, and non-static methods are included as well.
 */
function tzfd_ai_function_bodies( $src ) {
	$bodies = array();
	if ( ! preg_match_all( '/\bfunction\s+(\w+)\s*\(/', $src, $found, PREG_OFFSET_CAPTURE ) ) {
		return $bodies;
	}
	$len = strlen( $src );
	foreach ( $found[1] as $i => $name_match ) {
		$open = strpos( $src, '{', $found[0][ $i ][1] );
		if ( false === $open ) {
			continue;
		}
		$depth = 0;
		for ( $j = $open; $j < $len; $j++ ) {
			if ( '{' === $src[ $j ] ) {
				++$depth;
			} elseif ( '}' === $src[ $j ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$bodies[ $name_match[0] ] = substr( $src, $open + 1, $j - $open - 1 );
					break;
				}
			}
		}
	}
	return $bodies;
}

$tzfd_ai_src_file = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-legacy-privacy.php';
$tzfd_ai_src      = is_readable( $tzfd_ai_src_file ) ? (string) file_get_contents( $tzfd_ai_src_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file read by the test runner.

tzfd_assert( '' !== $tzfd_ai_src, 'legacy-ai no-auto-delete: class source readable' );

$tzfd_ai_body = tzfd_ai_function_bodies( $tzfd_ai_src );
$tzfd_ai_erase = isset( $tzfd_ai_body['privacy_erase'] ) ? $tzfd_ai_body['privacy_erase'] : '';
tzfd_assert( '' !== $tzfd_ai_erase, 'legacy-ai no-auto-delete: privacy_erase() found in class' );

/* 1. Hooks that fire on every page load, or before an admin screen opens, must not be registered by this class. */
$tzfd_ai_on_load = array( 'init', 'admin_init', 'wp_loaded', 'plugins_loaded', 'wp', 'shutdown', 'after_setup_theme' );
preg_match_all( '/\badd_(?:action|filter)\s*\(\s*([^,)]*)/', $tzfd_ai_src, $tzfd_ai_hook_calls );
$tzfd_ai_hook_names = array();
$tzfd_ai_dynamic    = array();
foreach ( $tzfd_ai_hook_calls[1] as $tzfd_ai_arg ) {
	if ( 1 === preg_match( '/^\s*([\'"])([A-Za-z_]+)\1\s*$/', $tzfd_ai_arg, $tzfd_ai_lit ) ) {
		$tzfd_ai_hook_names[] = $tzfd_ai_lit[2];
	} else {
		$tzfd_ai_dynamic[] = trim( $tzfd_ai_arg );
	}
}
$tzfd_ai_bad = array_values( array_intersect( $tzfd_ai_hook_names, $tzfd_ai_on_load ) );
tzfd_assert( array() === $tzfd_ai_bad, 'legacy-ai no-auto-delete: no on-load hook registered (found: ' . implode( ',', $tzfd_ai_bad ) . ')' );
tzfd_assert( array() === $tzfd_ai_dynamic, 'legacy-ai no-auto-delete: every hook name is a literal (dynamic: ' . implode( ',', $tzfd_ai_dynamic ) . ')' );
tzfd_assert( false === stripos( $tzfd_ai_src, 'register_shutdown_function' ), 'legacy-ai no-auto-delete: no shutdown function registered' );

/* 2. Nothing runs on load: register() only adds the exporter and eraser filters, and never calls the eraser. */
$tzfd_ai_register = isset( $tzfd_ai_body['register'] ) ? $tzfd_ai_body['register'] : '';
tzfd_assert( '' !== $tzfd_ai_register, 'legacy-ai no-auto-delete: register() found in class' );
tzfd_assert( false === stripos( $tzfd_ai_register, 'delete' ) && false === strpos( $tzfd_ai_register, '$wpdb' ), 'legacy-ai no-auto-delete: register() performs no delete and touches no table' );
tzfd_assert( 2 === preg_match_all( '/\bprivacy_erase\b/', $tzfd_ai_src ), 'legacy-ai no-auto-delete: privacy_erase referenced only as its definition and eraser callback' );

/* 3. Delete-capable calls anywhere in the class. Exactly one is allowed: the exact-email DELETE in privacy_erase(). */
$tzfd_ai_delete_pattern = '/\b(?:delete_\w+|wp_delete_\w+|unlink|wp_delete_file)\s*\(|->delete\s*\(|\bDELETE\s+FROM\b|\bTRUNCATE\b|\bDROP\s+TABLE\b/i';
$tzfd_ai_offenders      = array();
foreach ( $tzfd_ai_body as $tzfd_ai_name => $tzfd_ai_body_text ) {
	if ( preg_match_all( $tzfd_ai_delete_pattern, $tzfd_ai_body_text, $tzfd_ai_hits ) ) {
		foreach ( $tzfd_ai_hits[0] as $tzfd_ai_hit ) {
			$tzfd_ai_offenders[] = $tzfd_ai_name . ':' . trim( $tzfd_ai_hit );
		}
	}
}
$tzfd_ai_all_hits = preg_match_all( $tzfd_ai_delete_pattern, $tzfd_ai_src, $tzfd_ai_src_hits );
tzfd_assert( 1 === $tzfd_ai_all_hits && array( 'privacy_erase:DELETE FROM' ) === $tzfd_ai_offenders, 'legacy-ai no-auto-delete: the only delete is the DELETE in privacy_erase (found: ' . implode( ',', $tzfd_ai_offenders ) . ')' );

/* The eraser's delete is exactly one parameterised statement, matched on one lower-cased address and the chat table. */
$tzfd_ai_delete_stmt = '/\$wpdb->query\(\s*\$wpdb->prepare\(\s*\'DELETE FROM %i WHERE LOWER\(%i\) = %s\'\s*,\s*self::chat_table\(\)\s*,\s*\'user_email\'\s*,\s*\$email\s*\)\s*\)/';
tzfd_assert( 1 === preg_match( $tzfd_ai_delete_stmt, $tzfd_ai_erase, $tzfd_ai_stmt, PREG_OFFSET_CAPTURE ), 'legacy-ai no-auto-delete: privacy erase is the exact-address DELETE statement' );

/* The delete must sit behind the email gate, and must not use a LIKE pattern. */
$tzfd_ai_gate_pos = strpos( $tzfd_ai_erase, 'if ( ! self::email_ready( $email ) ) { return' );
$tzfd_ai_del_pos  = isset( $tzfd_ai_stmt[0][1] ) ? $tzfd_ai_stmt[0][1] : -1;
tzfd_assert( false !== $tzfd_ai_gate_pos && $tzfd_ai_del_pos > $tzfd_ai_gate_pos, 'legacy-ai no-auto-delete: privacy erase returns before deleting unless the email is valid' );
tzfd_assert( false === stripos( $tzfd_ai_erase, 'LIKE' ), 'legacy-ai no-auto-delete: privacy erase matches an exact address, not a LIKE pattern' );
tzfd_assert( false !== strpos( $tzfd_ai_erase, 'strtolower( trim( $email_address ) )' ), 'legacy-ai no-auto-delete: privacy erase normalises the address before matching' );
