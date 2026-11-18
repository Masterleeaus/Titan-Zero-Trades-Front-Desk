<?php
/**
 * Issue #273: the AI Services status table shows plain words for every reason, never a raw code such as
 * no_entitlement. Pure PHP; the page file is checked as source because it needs a WordPress admin screen.
 */
require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-services.php';

function tzfd_test_ai_services_reason_text() {
	$C = 'TZFD_AI_Services';

	// Every reason resolve() can return, plus raw states it passes through, must read as words.
	$codes = array(
		'active', 'grace', 'paused_agents', 'no_entitlement', 'unsupported_schema', 'invalid_company',
		'company_mismatch', 'cache_expired', 'no_known_entitlement', 'unknown_state', 'revoked',
		'cancelled', 'over_budget', 'failed_payment', 'some_future_code',
	);
	foreach ( $codes as $code ) {
		$text = $C::reason_text( $code );
		tzfd_assert( '' !== trim( $text ), "reason_text('$code') is not empty" );
		tzfd_assert( 0 === preg_match( '/[a-z]+_[a-z_]+/', $text ), "reason_text('$code') shows no snake_case code: $text" );
	}

	// The reason the screen shows for a site with no hosted plan (the case in the issue).
	$free = $C::resolve( null );
	tzfd_assert_same( 'no_entitlement', $free['reason'], 'an empty site resolves to no_entitlement' );
	tzfd_assert( false !== strpos( $C::reason_text( $free['reason'] ), 'Free level' ), 'no_entitlement says the site is on the Free level' );

	// Raw states that resolve() passes through use the generic sentence, not the state name.
	$revoked = $C::resolve( array( 'schema_version' => 1, 'company_id' => 7, 'expires_at' => PHP_INT_MAX, 'state' => 'revoked', 'entitlements' => array( 'assistant' ) ), 7, 1000 );
	tzfd_assert_same( 'revoked', $revoked['reason'], 'a revoked payload resolves to the revoked state' );
	tzfd_assert_same( $C::reason_text( 'unknown_state' ), $C::reason_text( 'revoked' ), 'raw states map to the generic sentence' );

	// Active levels keep their existing meaning.
	tzfd_assert( false !== strpos( $C::reason_text( 'active' ), 'active' ), 'active reads as active' );

	// Regression guard: the AI Services page renders the mapped text, not the raw reason.
	$page = (string) file_get_contents( dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-services-page.php' );
	tzfd_assert( false !== strpos( $page, "TZFD_AI_Services::reason_text( \$resolved['reason'] )" ), 'AI Services page renders reason_text()' );
	tzfd_assert( false === strpos( $page, "esc_html( \$resolved['reason'] )" ), 'AI Services page no longer echoes the raw reason code' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
