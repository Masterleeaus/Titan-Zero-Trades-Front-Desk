<?php
/**
 * Gate tests for issue #14: signed envelopes, replay, company switch, stop and pause, dry run, approval,
 * rate limits, executor handling and the tamper-evident receipt chain. Runs through tests/unit/run.php.
 */

/** In-memory store with the same contract as TZFD_Agent_Gateway_Option_Store (sequence check on append). */
final class TZFD_Test_Agent_Store {
	public $grants = array();
	public $receipts = array();
	public $nonce_map = array();

	public function get_grant( $company ) { return $this->grants[ $company ] ?? null; }
	public function put_grant( $company, $grant ) { $this->grants[ $company ] = $grant; }
	public function receipts_for_company( $company ) { return $this->receipts[ $company ] ?? array(); }
	public function append_receipt( $company, $receipt ) {
		$chain = $this->receipts[ $company ] ?? array();
		if ( (int) $receipt['sequence'] !== count( $chain ) + 1 ) { throw new RuntimeException( 'receipt_sequence' ); }
		$chain[] = $receipt;
		$this->receipts[ $company ] = $chain;
	}
	public function nonces() { return $this->nonce_map; }
	public function put_nonces( $nonces ) { $this->nonce_map = $nonces; }
}

function tzfd_test_agent_gateway() {
	$G = 'TZFD_Agent_Gateway';
	$R = 'TZFD_Action_Receipt';
	$secret = str_repeat( 'k', 40 );
	$now = 1800000000;
	$counter = 0;

	$base_grant = array(
		'company_id' => 'co-1', 'agent_id' => 'agent-1', 'state' => 'active', 'dry_run' => false, 'version' => 1,
		'expires_at' => null, 'tools' => array( 'answer', 'check_availability', 'book', 'follow_up' ),
		'auto_approve' => array(), 'max_per_hour' => array( 'answer' => 2 ),
	);
	$book_args = array( 'service_id' => 'svc-1', 'location_id' => 'loc-a', 'date' => '2026-06-01', 'time' => '09:00', 'customer_id' => 'cust-1' );

	// Builds and signs an envelope. Each call gets a fresh nonce unless one is given.
	$envelope = function ( $over = array() ) use ( $G, $secret, $now, &$counter ) {
		$counter++;
		$env = array_merge( array(
			'company_id' => 'co-1', 'agent_id' => 'agent-1', 'tool' => 'answer', 'args' => array( 'question' => 'Open Sundays?' ),
			'issued_at' => $now, 'nonce' => sprintf( '%032x', $counter ),
		), $over );
		unset( $env['signature'] );
		$env['signature'] = $G::sign( $secret, $env );
		return $env;
	};

	$calls = array();
	$ok_executor = function ( $payload ) use ( &$calls ) {
		$calls[] = $payload;
		return array( 'ok' => true, 'reason' => 'granted' );
	};

	$fresh = function ( $grant = null ) use ( $base_grant ) {
		$store = new TZFD_Test_Agent_Store();
		$store->put_grant( 'co-1', $grant ?? $base_grant );
		return $store;
	};

	// Allowed read runs the executor and writes an executed receipt.
	$store = $fresh();
	$r = $G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	tzfd_assert_same( 'executed', $r['outcome'], 'granted read tool runs through the executor' );
	tzfd_assert( true === $r['executed'] && 1 === count( $calls ) && 1 === $r['sequence'], 'executor called once, first receipt in the chain' );
	tzfd_assert( $R::verify_chain( $secret, $store->receipts_for_company( 'co-1' ) )['valid'], 'receipt chain verifies after one call' );

	// Tampered envelope: changed arguments and changed tool both fail the signature, and nothing is written.
	$store = $fresh();
	$env = $envelope( array( 'args' => array( 'question' => 'Open Sundays?' ) ) );
	$env['args']['question'] = 'Cancel everything';
	$r = $G::handle( $store, $secret, $env, $now, $ok_executor );
	tzfd_assert_same( 'bad_signature', $r['reason'], 'tampered arguments are refused' );
	tzfd_assert( 0 === count( $store->receipts_for_company( 'co-1' ) ) && false === $r['executed'], 'tampered call writes no receipt and runs nothing' );
	$env = $envelope( array( 'tool' => 'answer' ) );
	$env['tool'] = 'book';
	$r = $G::handle( $store, $secret, $env, $now, $ok_executor );
	tzfd_assert_same( 'bad_signature', $r['reason'], 'tampered tool name is refused' );

	// Signed with the wrong secret.
	$r = $G::handle( $store, str_repeat( 'x', 40 ), $envelope(), $now, $ok_executor );
	tzfd_assert_same( 'bad_signature', $r['reason'], 'envelope signed with another secret is refused' );

	// Replay: the same authenticated envelope is refused the second time and does not run again.
	$store = $fresh();
	$once = $envelope( array( 'nonce' => str_repeat( 'a', 32 ) ) );
	$before = count( $calls );
	$first = $G::handle( $store, $secret, $once, $now, $ok_executor );
	$second = $G::handle( $store, $secret, $once, $now, $ok_executor );
	tzfd_assert( 'executed' === $first['outcome'] && 'replay' === $second['reason'] && $before + 1 === count( $calls ), 'replayed envelope is refused and runs once' );
	tzfd_assert_same( 2, count( $store->receipts_for_company( 'co-1' ) ), 'replay is recorded as a denied receipt' );
	tzfd_assert( $R::verify_chain( $secret, $store->receipts_for_company( 'co-1' ) )['valid'], 'chain still verifies after a replay' );

	// Freshness window: too old and too far in the future are both refused.
	$store = $fresh();
	$r = $G::handle( $store, $secret, $envelope( array( 'issued_at' => $now - 400 ) ), $now, $ok_executor );
	tzfd_assert_same( 'stale_request', $r['reason'], 'envelope older than the window is refused' );
	$r = $G::handle( $store, $secret, $envelope( array( 'issued_at' => $now + 400 ) ), $now, $ok_executor );
	tzfd_assert_same( 'stale_request', $r['reason'], 'envelope from the future beyond the window is refused' );
	$r = $G::handle( $store, $secret, $envelope( array( 'issued_at' => $now - 299 ) ), $now, $ok_executor );
	tzfd_assert_same( 'executed', $r['outcome'], 'envelope just inside the window is accepted' );

	// No grant for the company: nothing runs and nothing is recorded.
	$store = new TZFD_Test_Agent_Store();
	$r = $G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	tzfd_assert_same( 'no_grant', $r['reason'], 'no stored grant means no action' );
	tzfd_assert( 0 === count( $store->receipts ), 'no grant writes no receipt' );

	// Company switch: a grant stored under one company cannot authorise another company's call.
	$store = new TZFD_Test_Agent_Store();
	$store->put_grant( 'co-2', $base_grant );
	$before = count( $calls );
	$r = $G::handle( $store, $secret, $envelope( array( 'company_id' => 'co-2' ) ), $now, $ok_executor );
	tzfd_assert_same( 'company_mismatch', $r['reason'], 'grant whose company differs from the envelope company is refused' );
	tzfd_assert( false === $r['executed'] && $before === count( $calls ), 'company mismatch never runs the executor' );

	// Receipts of one company do not count toward another company's limits.
	$store = $fresh();
	$store->put_grant( 'co-2', array_merge( $base_grant, array( 'company_id' => 'co-2' ) ) );
	$G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	$G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	$r = $G::handle( $store, $secret, $envelope( array( 'company_id' => 'co-2' ) ), $now, $ok_executor );
	tzfd_assert_same( 'executed', $r['outcome'], 'one company\'s usage does not limit another company' );

	// Least privilege: a tool the grant does not list is denied and recorded.
	$store = $fresh();
	$r = $G::handle( $store, $secret, $envelope( array( 'tool' => 'cancel', 'args' => array( 'booking_id' => 'b-1', 'customer_id' => 'cust-1' ) ) ), $now, $ok_executor );
	tzfd_assert_same( 'tool_not_granted', $r['reason'], 'ungranted tool is denied' );
	tzfd_assert_same( 'denied', $r['outcome'], 'denied call is recorded as denied' );

	// Emergency stop and pause, then resume. Each change bumps the grant version.
	$store = $fresh();
	$G::set_state( $store, 'co-1', 'stopped' );
	$r = $G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	tzfd_assert_same( 'agent_stopped', $r['reason'], 'stopped agent runs nothing' );
	$G::set_state( $store, 'co-1', 'paused' );
	$r = $G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	tzfd_assert_same( 'agent_paused', $r['reason'], 'paused agent runs nothing' );
	$resumed = $G::set_state( $store, 'co-1', 'active' );
	tzfd_assert_same( 4, $resumed['version'], 'every state change bumps the grant version' );
	$r = $G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	tzfd_assert_same( 'executed', $r['outcome'], 'resumed agent runs again' );
	$last = end( $store->receipts['co-1'] );
	tzfd_assert_same( 4, $last['grant_version'], 'receipts show the grant version in force' );

	// Dry run: decision recorded, executor never called.
	$store = $fresh( array_merge( $base_grant, array( 'dry_run' => true ) ) );
	$before = count( $calls );
	$r = $G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	tzfd_assert_same( 'dry_run', $r['outcome'], 'dry-run grant records a dry run' );
	tzfd_assert( $before === count( $calls ) && false === $r['executed'], 'dry run never calls the executor' );

	// Owner approval: write tools need approval unless auto-approved.
	$store = $fresh();
	$r = $G::handle( $store, $secret, $envelope( array( 'tool' => 'book', 'args' => $book_args ) ), $now, $ok_executor );
	tzfd_assert_same( 'needs_approval', $r['outcome'], 'book waits for owner approval' );
	$store = $fresh( array_merge( $base_grant, array( 'auto_approve' => array( 'book' ) ) ) );
	$r = $G::handle( $store, $secret, $envelope( array( 'tool' => 'book', 'args' => $book_args ) ), $now, $ok_executor );
	tzfd_assert_same( 'executed', $r['outcome'], 'auto-approved book runs' );

	// Rate limit: answer allows two per hour. A third is refused, and denied calls do not use up the allowance.
	$store = $fresh();
	$G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	$G::handle( $store, $secret, $envelope(), $now + 10, $ok_executor );
	$r = $G::handle( $store, $secret, $envelope(), $now + 20, $ok_executor );
	tzfd_assert_same( 'rate_limited', $r['reason'], 'third answer inside the hour is rate limited' );
	$r = $G::handle( $store, $secret, $envelope( array( 'tool' => 'follow_up', 'args' => array( 'customer_id' => 'cust-1', 'channel' => 'sms' ) ) ), $now + 30, $ok_executor );
	tzfd_assert_same( 'needs_approval', $r['outcome'], 'other tools keep their own limits' );

	// Executor outcomes: no executor, a throwing executor, and an executor that returns a free-text reason.
	$store = $fresh();
	$r = $G::handle( $store, $secret, $envelope(), $now );
	tzfd_assert( 'failed' === $r['outcome'] && false === $r['executed'] && 'no_executor' === $r['reason'], 'allowed call with no executor is recorded as failed, not executed' );
	$store = $fresh();
	$r = $G::handle( $store, $secret, $envelope(), $now, function () { throw new RuntimeException( 'boom' ); } );
	tzfd_assert( 'executor_error' === $r['reason'] && 'failed' === $r['outcome'], 'throwing executor is recorded as failed' );
	$store = $fresh();
	$r = $G::handle( $store, $secret, $envelope(), $now, function () { return array( 'ok' => false, 'reason' => 'Free text with <tags>' ); } );
	tzfd_assert( 'executor_failed' === $r['reason'], 'executor free-text reason is replaced with a fixed one' );

	// Malformed envelopes and weak secrets are refused without a receipt.
	$store = $fresh();
	$bad = $envelope();
	$bad['extra'] = 'x';
	tzfd_assert_same( 'malformed_envelope', $G::handle( $store, $secret, $bad, $now )['reason'], 'envelope with an unknown field is malformed' );
	tzfd_assert_same( 'malformed_envelope', $G::handle( $store, $secret, 'not-an-array', $now )['reason'], 'non-array envelope is malformed' );
	tzfd_assert_same( 'weak_secret', $G::handle( $store, 'short', $envelope(), $now )['reason'], 'weak site secret is refused' );
	tzfd_assert( 0 === count( $store->receipts_for_company( 'co-1' ) ), 'malformed and unauthenticated calls write no receipts' );

	// Tamper-evident chain: editing a stored receipt breaks verification at that point.
	$store = $fresh();
	$G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	$G::handle( $store, $secret, $envelope(), $now + 1, $ok_executor );
	$G::handle( $store, $secret, $envelope(), $now + 2, $ok_executor );
	$chain = $store->receipts_for_company( 'co-1' );
	$chain[1]['outcome'] = 'denied';
	$chain[1]['reason']  = 'tool_not_granted';
	$check = $R::verify_chain( $secret, $chain );
	tzfd_assert( false === $check['valid'] && 1 === $check['broken_at'], 'edited receipt breaks the chain at its position' );
	$store->receipts['co-1'] = $chain;
	$viewed = TZFD_Action_Receipt_Viewer::rows( $store, 'co-1', $secret, array(), 10 );
	tzfd_assert( false === $viewed['chain_valid'] && 2 === $viewed['broken_at_sequence'], 'viewer reports the broken sequence' );
	tzfd_assert( true === $viewed['read_only'] && 3 === $viewed['total'], 'viewer is read-only and lists every receipt' );

	// Viewer keeps other companies' receipts out of the list.
	$store = $fresh();
	$G::handle( $store, $secret, $envelope(), $now, $ok_executor );
	$stray = $store->receipts['co-1'][0];
	$stray['company_id'] = 'co-9';
	$store->receipts['co-1'][] = $stray;
	$viewed = TZFD_Action_Receipt_Viewer::rows( $store, 'co-1', $secret, array(), 10 );
	tzfd_assert( 1 === $viewed['foreign_dropped'] && 1 === $viewed['total'], 'receipt from another company is dropped from the view' );

	// Grant saves: the version only goes up, so an older grant cannot replace a newer one silently.
	$store = new TZFD_Test_Agent_Store();
	$saved = $G::save_grant( $store, $base_grant );
	$saved2 = $G::save_grant( $store, array_merge( $base_grant, array( 'version' => 1, 'tools' => array( 'answer' ) ) ) );
	tzfd_assert( 1 === $saved['version'] && 2 === $saved2['version'], 'saving a grant again bumps the version' );
	tzfd_throws( function () use ( $G, $store ) { $G::save_grant( $store, array( 'company_id' => 'co-1', 'agent_id' => 'agent-1', 'tools' => array( 'drop_tables' ) ) ); }, 'grant with an unknown tool is refused' );
}


// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
