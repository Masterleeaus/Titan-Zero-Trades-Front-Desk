<?php
/**
 * Source-level guard for the callback form rate limit (issue #151 review). The limit must be claimed through the
 * atomic TZFD_Rate_Limit, never by get_transient() followed by set_transient(), which lets parallel posts pass.
 * Runtime behaviour of TZFD_Rate_Limit itself is covered by the runtime suite against a real database.
 */
function tzfd_test_handoff_rate_limit() {
	$path = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-human-handoff.php';
	$src  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads a repository file.

	tzfd_assert( false === strpos( $src, 'get_transient' ) && false === strpos( $src, 'set_transient' ),
		'handoff rate limit does not use transients (read then write race)' );
	tzfd_assert( 1 === preg_match( '/TZFD_Rate_Limit::allow\(\s*\$key,\s*self::RATE_LIMIT,\s*self::RATE_WINDOW\s*\)/', $src ),
		'handoff claims its slot with TZFD_Rate_Limit::allow using RATE_LIMIT and RATE_WINDOW' );
	tzfd_assert( false !== strpos( $src, "require_once __DIR__ . '/class-tzfd-rate-limit.php';" ),
		'handoff loads the atomic rate limit class before use' );
	tzfd_assert( 1 === preg_match( '/const\s+RATE_LIMIT\s+=\s+3;/', $src ) && 1 === preg_match( '/const\s+RATE_WINDOW\s+=\s+600;/', $src ),
		'handoff limit stays 3 per 600 seconds' );
}
