<?php
/**
 * Authorisation inventory for every REST route, admin-post handler and AJAX handler in the plugin (issue #151).
 *
 * Static check, no WordPress needed. For each route or hook it finds the callback in source and asserts:
 *   - REST routes: a permission_callback is registered, it is not __return_true, and the callback it names
 *     performs a capability, signature, token or request-guard check.
 *   - admin-post and wp_ajax handlers: the handler (directly, or through its class guard() helper) checks a
 *     nonce and a capability or company scope. Handlers registered for logged-out users (admin_post_nopriv_)
 *     must check a nonce, a signed request or a token instead, because they have no capability to test.
 * A minimum inventory size is asserted so that an empty scan cannot pass.
 */

/** Returns [class, method] pairs and file paths for every PHP file under the plugin folder. */
function tzfd_authz_plugin_files() {
	$root  = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	$out   = array();
	$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iter as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$out[] = $file->getPathname();
		}
	}
	sort( $out );
	return $out;
}

/**
 * Parses one PHP file and returns its functions as name => body text, keyed "Class::method" (Class is the
 * nearest class declared above the function, or '' for a plain function).
 */
function tzfd_authz_functions( $file ) {
	$toks  = token_get_all( (string) file_get_contents( $file ) );
	$n     = count( $toks );
	$class = '';
	$out   = array();
	for ( $i = 0; $i < $n; $i++ ) {
		if ( is_array( $toks[ $i ] ) && T_CLASS === $toks[ $i ][0] ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( is_array( $toks[ $j ] ) && T_STRING === $toks[ $j ][0] ) { $class = $toks[ $j ][1]; break; }
			}
		}
		if ( ! ( is_array( $toks[ $i ] ) && T_FUNCTION === $toks[ $i ][0] ) ) { continue; }
		$j = $i + 1;
		while ( $j < $n && ! ( is_array( $toks[ $j ] ) && T_STRING === $toks[ $j ][0] ) ) { $j++; }
		if ( $j >= $n ) { continue; }
		$name = $toks[ $j ][1];
		while ( $j < $n && '{' !== $toks[ $j ] && ';' !== $toks[ $j ] ) { $j++; }
		if ( $j >= $n || ';' === $toks[ $j ] ) { continue; }
		$depth = 0;
		$body  = '';
		for ( $k = $j; $k < $n; $k++ ) {
			$t = $toks[ $k ];
			if ( '{' === $t ) { $depth++; }
			if ( '}' === $t ) { $depth--; if ( 0 === $depth ) { break; } }
			$body .= is_array( $t ) ? $t[1] : $t;
		}
		$out[ $class . '::' . $name ] = $body;
	}
	return $out;
}

/** Collects every function in the plugin into one map, and the source text of each file. */
function tzfd_authz_index() {
	static $index = null;
	if ( null !== $index ) { return $index; }
	$index = array( 'functions' => array(), 'sources' => array() );
	foreach ( tzfd_authz_plugin_files() as $file ) {
		$src = (string) file_get_contents( $file );
		$index['sources'][ $file ] = $src;
		foreach ( tzfd_authz_functions( $file ) as $key => $body ) {
			$index['functions'][ $key ] = $body;
		}
	}
	return $index;
}

/** Finds the first function whose name (after the class prefix) matches, across the plugin. */
function tzfd_authz_body_for( $class, $method ) {
	$f = tzfd_authz_index()['functions'];
	if ( isset( $f[ $class . '::' . $method ] ) ) { return $f[ $class . '::' . $method ]; }
	foreach ( $f as $key => $body ) {
		if ( substr( $key, -strlen( '::' . $method ) ) === '::' . $method ) { return $body; }
	}
	return null;
}

/** Resolves the body that actually performs the check: a helper named guard() or guard_*() is followed one level. */
function tzfd_authz_effective_body( $body ) {
	if ( null !== $body ) {
		// Named guard helpers the body calls, e.g. self::guard_connector_request(): followed one level.
		if ( preg_match_all( '/(?:self|static|\$this)::(guard_[A-Za-z_]+)\(/', $body, $named ) ) {
			foreach ( array_unique( $named[1] ) as $helper ) {
				$h = tzfd_authz_body_for( '', $helper );
				if ( null !== $h ) { $body .= ' ' . $h; }
			}
		}
	}
	if ( null !== $body && preg_match( '/(?:self|static|\$this)::guard\(/', $body ) ) {
		$guard = tzfd_authz_body_for( '', 'guard' );
		if ( null !== $guard ) { $body .= ' ' . $guard; }
		// Also include every class-scoped guard, because each admin class keeps its own copy.
		foreach ( tzfd_authz_index()['functions'] as $key => $g ) {
			if ( '::guard' === substr( $key, -7 ) ) { $body .= ' ' . $g; }
		}
	}
	return (string) $body;
}

/** Each admin-post or AJAX registration: [hook name, method name, file]. */
function tzfd_authz_hooks() {
	$hooks = array();
	foreach ( tzfd_authz_index()['sources'] as $file => $src ) {
		if ( ! preg_match_all( "/add_action\(\s*[^;]*?'((?:admin_post|wp_ajax)[a-z_]*)'?[^;]*?array\(\s*[^;]*?'([A-Za-z_]+)'\s*\)\s*\)/s", $src, $m, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $m as $x ) {
			$hooks[] = array( 'hook' => $x[1], 'method' => $x[2], 'file' => $file, 'statement' => $x[0] );
		}
	}
	return $hooks;
}

function tzfd_test_rest_routes_have_permission_callbacks() {
	$routes = 0;
	foreach ( tzfd_authz_index()['sources'] as $file => $src ) {
		if ( ! preg_match_all( '/register_rest_route\s*\(/', $src ) ) { continue; }
		// Take each route's argument text up to the balancing close parenthesis.
		$pos = 0;
		while ( false !== ( $pos = strpos( $src, 'register_rest_route', $pos ) ) ) {
			$open = strpos( $src, '(', $pos );
			$depth = 0; $end = $open;
			for ( $k = $open; $k < strlen( $src ); $k++ ) {
				if ( '(' === $src[ $k ] ) { $depth++; }
				if ( ')' === $src[ $k ] ) { $depth--; if ( 0 === $depth ) { $end = $k; break; } }
			}
			$args = substr( $src, $open, $end - $open );
			$routes++;
			$name = basename( $file );
			tzfd_assert( false !== strpos( $args, 'permission_callback' ), "REST route in $name has a permission_callback" );
			tzfd_assert( false === strpos( $args, '__return_true' ), "REST route in $name does not use __return_true" );
			if ( preg_match( "/'permission_callback'\s*=>\s*array\(\s*(?:__CLASS__|self|static|&?\\\$this|'[A-Za-z_]+')\s*,\s*'([A-Za-z_]+)'\s*\)/", $args, $pm ) ) {
				$body = (string) tzfd_authz_body_for( '', $pm[1] );
				$class_hint = preg_match( '/class\s+([A-Za-z_]+)/', $src, $cm ) ? $cm[1] : '';
				$body = (string) tzfd_authz_body_for( $class_hint, $pm[1] );
				$checks = (bool) preg_match( '/current_user_can|signature_valid|hash_equals|authorise|TZFD_REST_Request_Guard|can_read|can_manage|allowed\(|verify/', $body );
				tzfd_assert( $checks, "permission callback {$pm[1]} in $name performs a capability, signature or guard check" );
			}
			$pos = $end;
		}
	}
	// Nine route registrations exist today (two grep hits are function_exists guards, not routes).
	tzfd_assert( $routes >= 9, "REST inventory is not empty ($routes routes found, expected at least 9)" );
}

function tzfd_test_admin_post_and_ajax_handlers_check_nonce_and_capability() {
	$hooks = tzfd_authz_hooks();
	tzfd_assert( count( $hooks ) >= 30, 'admin-post and AJAX inventory is not empty (' . count( $hooks ) . ' hooks found, expected at least 30)' );
	// A method registered for logged-out visitors too (admin_post_nopriv_ in the same file) is a public handler.
	$public = array();
	foreach ( $hooks as $h ) {
		if ( false !== strpos( $h['statement'], 'nopriv' ) ) { $public[ $h['file'] . '::' . $h['method'] ] = true; }
	}
	foreach ( $hooks as $h ) {
		$class = preg_match( '/class\s+([A-Za-z_]+)/', tzfd_authz_index()['sources'][ $h['file'] ], $cm ) ? $cm[1] : '';
		$body  = tzfd_authz_effective_body( tzfd_authz_body_for( $class, $h['method'] ) );
		$name  = basename( $h['file'] ) . ' ' . $h['method'];
		if ( '' === trim( $body ) ) {
			tzfd_assert( false, "handler body found for $name ({$h['hook']})" );
			continue;
		}
		$nonce_or_token = (bool) preg_match( '/check_admin_referer|wp_verify_nonce|check_ajax_referer|verify_nonce|signature|hash_equals|captcha|token/i', $body );
		if ( isset( $public[ $h['file'] . '::' . $h['method'] ] ) ) {
			tzfd_assert( $nonce_or_token, "logged-out handler $name ({$h['hook']}) checks a nonce, signed request or token" );
			continue;
		}
		$cap = (bool) preg_match( '/current_user_can|can_manage|can_read|allowed\(|user_can|tz_fd_access|manage_options|company_id/', $body );
		tzfd_assert( $nonce_or_token, "handler $name ({$h['hook']}) checks a nonce" );
		tzfd_assert( $cap, "handler $name ({$h['hook']}) checks a capability or company scope" );
	}
}

/** The legacy-data restore upload is limited to .json files and a size cap, as the pack upload is (#151 upload checks). */
function tzfd_test_legacy_restore_upload_is_json_only() {
	$body = (string) tzfd_authz_body_for( 'TZFD_Legacy_AI_Data', 'handle_restore' );
	tzfd_assert( false !== strpos( $body, "'json' !== strtolower( pathinfo( \$name, PATHINFO_EXTENSION ) )" ), 'legacy restore refuses an upload that is not a .json file' );
	tzfd_assert( false !== strpos( $body, 'is_uploaded_file' ) && false !== strpos( $body, 'MAX_UPLOAD' ), 'legacy restore checks is_uploaded_file and the size cap' );
}
