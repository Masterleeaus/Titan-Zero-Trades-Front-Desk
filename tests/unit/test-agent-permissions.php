<?php
function tzfd_test_agent_tool_permissions() {
	$P = 'TZFD_Agent_Tool_Permissions';
	$now = 1800000000;
	$grant = array(
		'company_id' => 'co-1', 'agent_id' => 'agent-1', 'state' => 'active', 'dry_run' => false, 'version' => 3,
		'expires_at' => null, 'tools' => array( 'answer', 'read_pricing', 'check_availability', 'book' ),
		'auto_approve' => array(), 'max_per_hour' => array( 'book' => 2 ),
	);
	$req = function ( $over = array() ) use ( $now ) {
		return array_merge( array(
			'company_id' => 'co-1', 'agent_id' => 'agent-1', 'tool' => 'answer', 'args' => array( 'question' => 'Do you open Sundays?' ),
			'now' => $now, 'receipts_last_hour' => array(), 'approved_by_human' => false,
		), $over );
	};
	$book_args = array( 'service_id' => 'svc-1', 'location_id' => 'loc-a', 'date' => '2026-06-01', 'time' => '09:00', 'customer_id' => 'cust-1' );

	// Least privilege: granted tools run, everything else is denied.
	$d = $P::decide( $grant, $req() );
	tzfd_assert_same( 'allow', $d['decision'], 'granted read tool is allowed' );
	tzfd_assert_same( 3, $d['grant_version'], 'decision records the grant version' );
	tzfd_assert( false === $d['executed'] && true === $d['requires_revalidation'], 'decision never executes and always requires booking revalidation' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'follow_up', 'args' => array( 'customer_id' => 'cust-1', 'channel' => 'sms' ) ) ) );
	tzfd_assert_same( 'tool_not_granted', $d['reason'], 'ungranted tool is denied (least privilege)' );

	// Write and external tools with approval.
	$d = $P::decide( $grant, $req( array( 'tool' => 'book', 'args' => $book_args ) ) );
	tzfd_assert_same( 'needs_approval', $d['decision'], 'book needs owner approval by default' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'book', 'args' => $book_args, 'approved_by_human' => true ) ) );
	tzfd_assert_same( 'allow', $d['decision'], 'book runs once a human approved this action' );
	$auto = array_merge( $grant, array( 'auto_approve' => array( 'book' ) ) );
	$d = $P::decide( $auto, $req( array( 'tool' => 'book', 'args' => $book_args ) ) );
	tzfd_assert_same( 'allow', $d['decision'], 'book runs without approval only when the owner pre-approved it' );

	// Dry run validates everything and executes nothing.
	$dry = array_merge( $grant, array( 'dry_run' => true ) );
	$d = $P::decide( $dry, $req() );
	tzfd_assert_same( 'dry_run', $d['decision'], 'dry run grant returns dry_run' );
	tzfd_assert( false === $d['executed'], 'dry run is not executed' );

	// Typed arguments: injection-shaped input is refused.
	$d = $P::decide( $grant, $req( array( 'args' => array( 'question' => 'x', 'sql' => 'DROP TABLE wp_options' ) ) ) );
	tzfd_assert_same( 'invalid_arguments', $d['reason'], 'unknown argument key (arbitrary SQL attempt) is refused' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'book', 'args' => array_merge( $book_args, array( 'date' => '2026-02-30' ) ) ) ) );
	tzfd_assert_same( 'invalid_arguments', $d['reason'], 'impossible date is refused' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'book', 'args' => array_merge( $book_args, array( 'customer_id' => '1; DROP' ) ) ) ) );
	tzfd_assert_same( 'invalid_arguments', $d['reason'], 'id with SQL metacharacters is refused' );
	$d = $P::decide( $grant, $req( array( 'args' => array( 'question' => "hi\x00there" ) ) ) );
	tzfd_assert_same( 'invalid_arguments', $d['reason'], 'control characters in text are refused' );
	$d = $P::decide( $grant, $req( array( 'args' => array( 'question' => '' ) ) ) );
	tzfd_assert_same( 'invalid_arguments', $d['reason'], 'empty text is refused' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'book', 'args' => array( 'service_id' => 'svc-1' ) ) ) ) ;
	tzfd_assert_same( 'invalid_arguments', $d['reason'], 'missing required argument is refused' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'eval_php' ) ) );
	tzfd_assert_same( 'unknown_tool', $d['reason'], 'model-invented tool name is refused' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'draft_estimate', 'args' => array( 'service_id' => 'svc-1' ) ) ) );
	tzfd_assert_same( 'tool_not_granted', $d['reason'], 'optional-argument tool still needs the grant' );

	// Company switch and agent identity.
	$d = $P::decide( $grant, $req( array( 'company_id' => 'co-2' ) ) );
	tzfd_assert_same( 'company_mismatch', $d['reason'], 'company switch is denied' );
	$d = $P::decide( $grant, $req( array( 'agent_id' => 'agent-9' ) ) );
	tzfd_assert_same( 'agent_mismatch', $d['reason'], 'a different agent cannot use this grant' );

	// Expiry, pause, stop.
	$expired = array_merge( $grant, array( 'expires_at' => $now - 1 ) );
	tzfd_assert_same( 'grant_expired', $P::decide( $expired, $req() )['reason'], 'expired grant is denied' );
	$paused = $P::with_state( $grant, 'paused' );
	tzfd_assert_same( 'agent_paused', $P::decide( $paused, $req() )['reason'], 'paused agent is denied' );
	tzfd_assert( 4 === $paused['version'], 'changing state bumps the version' );
	$stopped = $P::with_state( $paused, 'stopped' );
	tzfd_assert_same( 'agent_stopped', $P::decide( $stopped, $req() )['reason'], 'emergency stop denies everything' );
	tzfd_assert_same( 'agent_stopped', $P::decide( $stopped, $req( array( 'tool' => 'book', 'args' => $book_args, 'approved_by_human' => true ) ) )['reason'], 'stop wins over approval' );
	$resumed = $P::with_state( $stopped, 'active' );
	tzfd_assert_same( 'allow', $P::decide( $resumed, $req() )['decision'], 'resume restores access' );

	// Rate limits: per-tool ceiling from the grant, stricter than the catalogue.
	$d = $P::decide( $grant, $req( array( 'tool' => 'book', 'args' => $book_args, 'approved_by_human' => true, 'receipts_last_hour' => array( 'book' => 2 ) ) ) );
	tzfd_assert_same( 'rate_limited', $d['reason'], 'book stops at the grant ceiling of 2 per hour' );
	$d = $P::decide( $grant, $req( array( 'tool' => 'book', 'args' => $book_args, 'approved_by_human' => true, 'receipts_last_hour' => array( 'book' => 1 ) ) ) );
	tzfd_assert_same( 'allow', $d['decision'], 'one book in the last hour is under the ceiling' );
	$d = $P::decide( $grant, $req( array( 'receipts_last_hour' => array( 'answer' => -1 ) ) ) );
	tzfd_assert_same( 'invalid_usage_count', $d['reason'], 'negative usage count is refused' );

	// Malformed grants and requests are denied, never widened.
	$bad = array_merge( $grant, array( 'tools' => array( 'answer', 'delete_everything' ) ) );
	tzfd_assert_same( 'invalid_grant', $P::decide( $bad, $req() )['reason'], 'grant naming an unknown tool is denied' );
	$bad = array_merge( $grant, array( 'auto_approve' => array( 'follow_up' ) ) );
	tzfd_throws( function () use ( $P, $bad ) { $P::validate_grant( $bad ); }, 'auto-approve of an ungranted tool throws' );
	$bad = array_merge( $grant, array( 'max_per_hour' => array( 'book' => 11 ) ) );
	tzfd_throws( function () use ( $P, $bad ) { $P::validate_grant( $bad ); }, 'ceiling above the catalogue maximum throws' );
	$bad = array_merge( $grant, array( 'state' => 'running' ) );
	tzfd_throws( function () use ( $P, $bad ) { $P::validate_grant( $bad ); }, 'unknown state throws' );
	tzfd_throws( function () use ( $P, $grant ) { $P::with_state( $grant, 'deleted' ); }, 'invalid target state throws' );
	tzfd_assert_same( 'invalid_time', $P::decide( $grant, $req( array( 'now' => '1800000000' ) ) )['reason'], 'string timestamp is refused' );
	tzfd_assert_same( 'invalid_request', $P::decide( $grant, 'answer' )['reason'], 'non-array request is denied' );
	tzfd_assert_same( 'deny', $P::decide( 'nonsense', $req() )['decision'], 'non-array grant is denied' );
	tzfd_assert_same( 'invalid_grant', $P::decide( 'nonsense', $req() )['reason'], 'non-array grant is reported as an invalid grant' );
	tzfd_assert_same( 'unknown_tool', $P::decide( $grant, array( 'tool' => 7 ) )['reason'], 'non-string tool is denied as unknown' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
