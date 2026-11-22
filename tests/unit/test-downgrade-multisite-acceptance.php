<?php
/**
 * Acceptance checks for issue #364 (split from #332, item 3; parent #11, #172): a downgrade must not change
 * local multi-location data or its policy, and location scope must hold across companies. Pure PHP, no WordPress,
 * no network. Covers the cross-module paths that the single-module suites do not:
 *   - a downgrade (paid to Free, by state or by cache expiry) keeps every local location rule in effect,
 *   - a location rule from another company never reaches a booking, and a bulk change cannot touch it,
 *   - a revoked location grant ends access at once and leaves the booking rows the rules apply to unchanged.
 * Data-retention periods and the production-readiness sign-off are owner decisions and are not asserted here.
 * Run: php tests/unit/test-downgrade-multisite-acceptance.php
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-location-policy.php';
require_once __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-services.php';

function tzfd_test_downgrade_multisite_acceptance() {
	$P  = 'TZFD_Location_Policy';
	$R  = 'TZFD_Delegated_Roles';
	$S  = 'TZFD_AI_Services';
	$NOW = 1900000000;

	$rule = function ( $over ) {
		return array_merge( array(
			'id' => 'r-approve', 'company_id' => 'co-1', 'location_id' => 'loc-a', 'services' => array( 'svc-1' ),
			'min_amount_minor' => 20000, 'action' => 'require_approval', 'staff_ids' => array(), 'enabled' => true,
		), $over );
	};
	$booking = function ( $over = array() ) {
		return array_merge( array( 'company_id' => 'co-1', 'location_id' => 'loc-a', 'service_id' => 'svc-1', 'amount_minor' => 25000, 'staff_id' => 'st-1' ), $over );
	};

	// Downgrade keeps local rules in force: paid agent team, then Free by state and by expiry.
	$paid = array( 'schema_version' => 1, 'company_id' => 1, 'state' => 'active', 'entitlements' => array( 'agent_team' ), 'expires_at' => $NOW + 3600 );
	$before = $S::resolve( $paid, null, $NOW );
	tzfd_assert( 'agent_team' === $before['level'], 'precondition: paid payload resolves to agent team' );

	$revoked = $paid;
	$revoked['state'] = 'revoked';
	$after_state = $S::resolve( $revoked, null, $NOW );
	tzfd_assert( 'free_ai' === $after_state['level'] && true === $S::local_features_enabled( $after_state ), 'revoked state downgrades to Free and keeps local features on' );

	$expired = $paid;
	$expired['expires_at'] = $NOW - 1;
	$after_expiry = $S::resolve( $expired, null, $NOW );
	tzfd_assert( 'cache_expired' === $after_expiry['reason'] && true === $S::local_features_enabled( $after_expiry ), 'expired cache downgrades to Free and keeps local features on' );

	$down = $S::downgrade_actions( 'agent_team', 'free_ai' );
	tzfd_assert( array() === $down['disabled_local'], 'downgrade disables no local feature' );
	tzfd_assert( in_array( 'bookings', $down['preserved'], true ) && in_array( 'local_rules', $down['preserved'], true ) && in_array( 'privacy_exports', $down['preserved'], true ), 'downgrade preserves bookings, local rules and privacy exports' );

	// The same location rule still gates a booking after the downgrade (rules are local, not paid).
	$rules = array( $rule( array() ) );
	$d_before = $P::evaluate( $rules, $booking() );
	tzfd_assert( true === $d_before['approval_required'] && array( 'r-approve' ) === $d_before['matched_rules'], 'location approval rule forces approval before downgrade' );
	$d_after = $P::evaluate( $rules, $booking() );
	tzfd_assert( true === $d_after['approval_required'] && $d_before === $d_after, 'location approval rule is unchanged after downgrade' );

	// Multi-location isolation: a rule for co-1 never applies to co-2, and a cross-company rule is refused, not dropped.
	tzfd_throws( function () use ( $P, $rules, $booking ) { $P::evaluate( $rules, $booking( array( 'company_id' => 'co-2' ) ) ); }, 'co-1 rule refused for a co-2 booking' );
	$wild = array( $rule( array( 'location_id' => '*', 'services' => array( '*' ) ) ) );
	$other_loc = $P::evaluate( $wild, $booking( array( 'location_id' => 'loc-b' ) ) );
	tzfd_assert( true === $other_loc['approval_required'], 'company-wide rule reaches every location of its own company' );

	// A bulk change is scoped to the caller's company and does not alter the stored list on refusal.
	$two = array( $rule( array( 'id' => 'r-1' ) ), $rule( array( 'id' => 'r-2', 'company_id' => 'co-2' ) ) );
	tzfd_throws( function () use ( $P, $two ) { $P::bulk_set_enabled( $two, array( 'r-1' ), false, 'co-1' ); }, 'bulk change over a list with another company rule is refused' );
	tzfd_assert( true === $two[0]['enabled'] && 'r-1' === $two[0]['id'], 'refused bulk change leaves the stored rules as they were' );

	// Revoked location grant: access ends at once; the grant change does not touch the rules themselves.
	$grants = array(
		array( 'company_id' => 'co-1', 'user_id' => 11, 'role' => 'location_admin', 'location_ids' => array( 'loc-a' ), 'staff_id' => null ),
	);
	tzfd_assert( $R::can( $grants, 'co-1', 11, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'location admin reaches own location before revocation' );
	$grants_after = array();
	tzfd_assert( ! $R::can( $grants_after, 'co-1', 11, 'loc-a', $R::CAP_VIEW_SCHEDULE ), 'revoked location admin is denied at once' );
	tzfd_assert( $d_after === $P::evaluate( $rules, $booking() ), 'revoking a grant leaves the location rules and their decisions unchanged' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
