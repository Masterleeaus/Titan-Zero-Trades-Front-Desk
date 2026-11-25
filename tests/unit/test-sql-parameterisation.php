<?php
/**
 * Static SQL parameterisation guard (issue #340, authorisation and SQL review).
 *
 * Static check, no WordPress needed. Every $wpdb query call in the plugin (query, get_results, get_row, get_var,
 * get_col) must pass either a prepare() call or a fixed string. It must not:
 *   - interpolate a PHP variable into the SQL text (only $wpdb core table properties, such as {$wpdb->options},
 *     and the one trusted plugin table name allowed in tzfd_sql_token_allowed(), may be interpolated);
 *   - concatenate a string with a PHP expression;
 *   - pass a bare variable as the SQL, because a variable cannot be checked here.
 * The scanner is first run against known-good and known-bad samples, so a broken pattern cannot pass silently.
 * A minimum call count is asserted so that an empty scan cannot pass either.
 */

/** Returns the plugin PHP files, sorted. */
function tzfd_sql_plugin_files() {
	$root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	$out  = array();
	$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iter as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$out[] = $file->getPathname();
		}
	}
	sort( $out );
	return $out;
}

/**
 * True when an interpolated token inside a SQL string is allowed. $wpdb core table properties are fixed names.
 * The only plugin name allowed is {$table} in class-tzfd-lead-intake.php, which is set from TZFD_Tables::name() in
 * ensure_consent_column() and never from request data.
 */
function tzfd_sql_token_allowed( $file_name, $token ) {
	if ( preg_match( '/^\{\$wpdb->[a-z_]+\}$/', $token ) || preg_match( '/^\$wpdb->[a-z_]+$/', $token ) ) {
		return true;
	}
	// IN-list placeholders built only from '%d' tokens, one per id (class-tzfd-enquiry-triage.php map_for_company).
	return ( 'class-tzfd-lead-intake.php' === $file_name && '{$table}' === $token ) ||
		( 'class-tzfd-enquiry-triage.php' === $file_name && '{$placeholders}' === $token );
}

/**
 * True when a bare SQL variable is accepted. Accepted when the same file assigns that variable from a prepare() call,
 * or when it is a documented exception. tzfd-main-class.inc.php $tzfd_events_query is built by prepare(), then passed
 * through a developer-only filter that the sniff cannot see; the existing annotation there records that contract.
 */
function tzfd_sql_bare_allowed( $src, $file_name, $var ) {
	if ( 'tzfd-main-class.inc.php' === $file_name && 'tzfd_events_query' === $var ) {
		return true;
	}
	return (bool) preg_match( '/\$' . preg_quote( $var, '/' ) . '\s*=\s*\$[A-Za-z_][A-Za-z0-9_]*\s*->\s*prepare\s*\(/', $src );
}

/**
 * Scans one file's source. Returns a list of "file:line detail" strings, one per problem. Adds the number of query
 * calls seen to $calls, so the caller can check the scan was not empty.
 */
function tzfd_sql_scan_text( $src, $file_name, &$calls = 0 ) {
	$out     = array();
	$call_re = '->(?:query|get_results|get_row|get_var|get_col)';
	$line_at = function ( $pos ) use ( $src ) { return substr_count( $src, "\n", 0, $pos ) + 1; };
	$calls  += preg_match_all( '/' . $call_re . '\s*\(/', $src );

	// A double-quoted SQL literal passed to a query call, or to prepare(). Only PHP expands variables in these.
	$literal = '/(' . $call_re . '|->prepare)\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"/';
	if ( preg_match_all( $literal, $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$sql = $x[2][0];
			if ( ! preg_match_all( '/\{\$[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)*/', $sql, $v ) ) {
				continue;
			}
			foreach ( $v[0] as $token ) {
				if ( ! tzfd_sql_token_allowed( $file_name, $token ) ) {
					$out[] = $file_name . ':' . $line_at( $x[0][1] ) . ' interpolates ' . $token . ' into SQL';
				}
			}
		}
	}

	// A query call whose SQL is built by concatenation with a string literal.
	$concat = '/' . $call_re . '\s*\(\s*(?:\'[^\']*\'|"(?:[^"\\\\]|\\\\.)*")\s*\./';
	if ( preg_match_all( $concat, $src, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $x ) {
			$out[] = $file_name . ':' . $line_at( $x[1] ) . ' builds SQL by concatenation';
		}
	}

	// A query call whose first argument is a variable. A direct $obj->prepare() call is not a bare variable.
	$bare = '/' . $call_re . '\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)(?![A-Za-z0-9_])(?!\s*->\s*prepare\s*\()/';
	if ( preg_match_all( $bare, $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			if ( tzfd_sql_bare_allowed( $src, $file_name, $x[1][0] ) ) { continue; }
			$out[] = $file_name . ':' . $line_at( $x[0][1] ) . ' passes $' . $x[1][0] . ' as SQL (not assigned from prepare())';
		}
	}
	return $out;
}

function tzfd_test_sql_parameterisation() {
	// Self-check on samples: the scanner must flag each bad form and accept each good form.
	$bad = array(
		'an interpolated variable' => '$wpdb->get_results( "SELECT * FROM t WHERE id = $id" );',
		'an interpolated table'    => '$wpdb->query( "DELETE FROM {$table} WHERE x=1" );',
		'a concatenation'          => '$wpdb->get_var( "SELECT 1 FROM t WHERE a=" . $a );',
		'a bare variable'          => '$wpdb->get_row( $sql );',
		'a variable in prepare'    => '$wpdb->query( $wpdb->prepare( "UPDATE t SET a=$a WHERE id=%d", 1 ) );',
	);
	foreach ( $bad as $label => $sample ) {
		$calls = 0;
		tzfd_assert( count( tzfd_sql_scan_text( '<?php ' . $sample, 'sample.php', $calls ) ) > 0, 'SQL scanner flags ' . $label . ' (self-check)' );
	}
	$good = array(
		'prepared placeholders' => '$wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id=%d", $t, 1 ) );',
		'a fixed single-quoted' => '$wpdb->get_var( \'SELECT COUNT(*) FROM t\' );',
		'a core table property' => '$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $n ) );',
		'a multi-line prepare'  => "\$wpdb->query(\n\t\$wpdb->prepare(\n\t\t'UPDATE %i SET a=%d', \$t, 1\n\t)\n);",
		'another object prepare' => '$wpdb->get_results( $db->prepare( \'SELECT %d\', 1 ) );',
		'a variable from prepare' => '$query = $db->prepare( \'SELECT * FROM %i WHERE id = %d\', $t, 1 ); $wpdb->get_row( $query );',
	);
	foreach ( $good as $label => $sample ) {
		$calls = 0;
		tzfd_assert( array() === tzfd_sql_scan_text( '<?php ' . $sample, 'sample.php', $calls ), 'SQL scanner accepts ' . $label . ' (self-check)' );
	}
	// The trusted table allowance applies only to its own file.
	$calls = 0;
	tzfd_assert( array() === tzfd_sql_scan_text( '<?php $wpdb->query( "DELETE FROM {$table}" );', 'class-tzfd-lead-intake.php', $calls ), 'SQL scanner accepts the trusted {$table} in its own file (self-check)' );
	$calls = 0;
	tzfd_assert( count( tzfd_sql_scan_text( '<?php $wpdb->query( "DELETE FROM {$table}" );', 'other-file.php', $calls ) ) > 0, 'SQL scanner flags {$table} outside its own file (self-check)' );

	// The real plugin source.
	$calls      = 0;
	$violations = array();
	foreach ( tzfd_sql_plugin_files() as $file ) {
		$src = (string) file_get_contents( $file );
		foreach ( tzfd_sql_scan_text( $src, basename( $file ), $calls ) as $problem ) {
			$violations[] = $problem;
		}
	}
	tzfd_assert( $calls >= 200, "SQL inventory is not empty ($calls query calls found, expected at least 200)" );
	foreach ( $violations as $problem ) {
		tzfd_assert( false, 'SQL parameterisation: ' . $problem );
	}
	tzfd_assert( array() === $violations, 'every plugin query is prepared or fixed (' . count( $violations ) . ' problem(s))' );
}
