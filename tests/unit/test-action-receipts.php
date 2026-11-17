<?php
function tzfd_test_action_receipts() {
	$R = 'TZFD_Action_Receipt';
	$secret = str_repeat( 's', 40 );
	$events = array(
		array( 'receipt_id' => 'rc-1', 'tool' => 'check_availability', 'outcome' => 'executed', 'reason' => 'granted', 'actor' => 'agent', 'occurred_at' => 1800000000, 'args' => array( 'date' => '2026-06-01' ) ),
		array( 'receipt_id' => 'rc-2', 'tool' => 'book', 'outcome' => 'needs_approval', 'reason' => 'owner_approval_required', 'actor' => 'agent', 'occurred_at' => 1800000100, 'args' => array( 'customer_id' => 'cust-1', 'notes' => 'Sunday only' ) ),
		array( 'receipt_id' => 'rc-3', 'tool' => 'book', 'outcome' => 'executed', 'reason' => 'granted', 'actor' => 'human', 'occurred_at' => 1800000200, 'args' => array( 'customer_id' => 'cust-1' ) ),
	);
	$chain = TZFD_Fake_Action_Source_Helper::chain( $secret, $events );

	// Verification of the intact chain.
	$ok = $R::verify_chain( $secret, $chain );
	tzfd_assert( true === $ok['valid'] && null === $ok['broken_at'], 'intact chain verifies' );
	tzfd_assert( $R::verify( $secret, $chain[0], $R::GENESIS ), 'first receipt verifies against genesis' );
	tzfd_assert( ! $R::verify( $secret, $chain[1], $R::GENESIS ), 'receipt does not verify against the wrong previous hash' );
	tzfd_assert( ! $R::verify( str_repeat( 'x', 40 ), $chain[0], $R::GENESIS ), 'wrong secret fails verification' );

	// Tampering: edit, delete and reorder are each detected at a known position.
	$edited = $chain;
	$edited[1]['outcome'] = 'executed';
	$v = $R::verify_chain( $secret, $edited );
	tzfd_assert( false === $v['valid'] && 1 === $v['broken_at'], 'edited outcome breaks the chain at that receipt' );
	$deleted = $chain;
	unset( $deleted[1] );
	$v = $R::verify_chain( $secret, array_values( $deleted ) );
	tzfd_assert( false === $v['valid'] && 1 === $v['broken_at'], 'deleted receipt breaks the chain where the gap is' );
	$reordered = array( $chain[0], $chain[2], $chain[1] );
	$v = $R::verify_chain( $secret, $reordered );
	tzfd_assert( false === $v['valid'] && 1 === $v['broken_at'], 'reordered receipts break the chain' );
	$forged = $chain;
	$forged[2]['signature'] = hash_hmac( 'sha256', 'x', $secret );
	tzfd_assert( false === $R::verify_chain( $secret, $forged )['valid'], 'forged signature is detected' );
	$injected = $chain;
	$injected[1]['extra'] = 'x';
	tzfd_assert( false === $R::verify_chain( $secret, $injected )['valid'], 'extra field is detected' );

	// Privacy: raw arguments are never stored, only a digest.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit suite runs without WordPress, so wp_json_encode() is unavailable.
	$serialised = json_encode( $chain );
	tzfd_assert( false === strpos( $serialised, 'Sunday only' ) && false === strpos( $serialised, 'cust-1' ), 'receipt contains no raw arguments' );
	tzfd_assert( 64 === strlen( $chain[1]['args_digest'] ), 'args are stored as SHA-256 digest' );
	tzfd_assert( $R::args_digest( array( 'a' => 1, 'b' => 2 ) ) === $R::args_digest( array( 'b' => 2, 'a' => 1 ) ), 'digest ignores key order' );
	tzfd_assert( $R::args_digest( array( 'a' => 1 ) ) !== $R::args_digest( array( 'a' => 2 ) ), 'digest changes with content' );

	// Nonce replay.
	$seen = array();
	$nonce = str_repeat( 'a1', 16 );
	tzfd_assert( true === $R::claim_nonce( $seen, $nonce ), 'first use of a nonce is accepted' );
	tzfd_assert( false === $R::claim_nonce( $seen, $nonce ), 'replayed nonce is refused' );
	tzfd_assert( false === $R::claim_nonce( $seen, 'not-hex' ), 'malformed nonce is refused' );
	tzfd_assert( false === $R::claim_nonce( $seen, str_repeat( 'a', 8 ) ), 'short nonce is refused' );

	// Invalid input never produces a receipt.
	tzfd_throws( function () use ( $R, $events ) { $R::issue( 'short', array() ); }, 'weak secret is refused' );
	$fields = $chain[0];
	unset( $fields['hash'], $fields['signature'] );
	$bad = $fields; $bad['tool'] = 'eval_php';
	tzfd_throws( function () use ( $R, $secret, $bad ) { $R::issue( $secret, $bad ); }, 'receipt for an unknown tool is refused' );
	$bad = $fields; $bad['outcome'] = 'maybe';
	tzfd_throws( function () use ( $R, $secret, $bad ) { $R::issue( $secret, $bad ); }, 'unknown outcome is refused' );
	$bad = $fields; $bad['reason'] = 'Free text with spaces';
	tzfd_throws( function () use ( $R, $secret, $bad ) { $R::issue( $secret, $bad ); }, 'free-text reason is refused' );
	$bad = $fields; $bad['sequence'] = 0;
	tzfd_throws( function () use ( $R, $secret, $bad ) { $R::issue( $secret, $bad ); }, 'zero sequence is refused' );
}

final class TZFD_Fake_Action_Source_Helper {
	public static function chain( $secret, $events ) {
		return tzfd_fake_chain( $secret, 'co-1', 'agent-1', $events );
	}
}

function tzfd_test_action_receipt_viewer() {
	$V = 'TZFD_Action_Receipt_Viewer';
	$secret = str_repeat( 's', 40 );
	$events = array(
		array( 'receipt_id' => 'rc-1', 'tool' => 'check_availability', 'outcome' => 'executed', 'reason' => 'granted', 'actor' => 'agent', 'occurred_at' => 1800000000 ),
		array( 'receipt_id' => 'rc-2', 'tool' => 'book', 'outcome' => 'needs_approval', 'reason' => 'owner_approval_required', 'actor' => 'agent', 'occurred_at' => 1800000100 ),
		array( 'receipt_id' => 'rc-3', 'tool' => 'book', 'outcome' => 'executed', 'reason' => 'granted', 'actor' => 'human', 'occurred_at' => 1800000200 ),
	);
	$chain = tzfd_fake_chain( $secret, 'co-1', 'agent-1', $events );
	$other = tzfd_fake_chain( $secret, 'co-2', 'agent-9', array( array( 'receipt_id' => 'x-1', 'tool' => 'answer', 'outcome' => 'executed', 'reason' => 'granted', 'actor' => 'agent', 'occurred_at' => 1800000000 ) ) );
	$source = new TZFD_Fake_Action_Source( array_merge( $chain, $other ) );

	$r = $V::rows( $source, 'co-1', $secret );
	tzfd_assert_same( 3, $r['total'], 'viewer shows only this company receipts' );
	tzfd_assert_same( 1, $r['foreign_dropped'], 'foreign receipt is dropped and counted' );
	tzfd_assert( true === $r['chain_valid'] && null === $r['broken_at_sequence'], 'intact history reports a valid chain' );
	tzfd_assert_same( 3, $r['rows'][0]['sequence'], 'rows are newest first' );
	tzfd_assert( true === $r['rows'][2]['verified'], 'intact rows are marked verified' );
	tzfd_assert( true === $r['read_only'], 'viewer is read only' );

	// Tampering shows up in the viewer and marks the affected rows.
	$tampered = $chain;
	$tampered[1]['outcome'] = 'executed';
	$source->set( array_merge( $tampered, $other ) );
	$r = $V::rows( $source, 'co-1', $secret );
	tzfd_assert( false === $r['chain_valid'] && 2 === $r['broken_at_sequence'], 'viewer reports the broken sequence' );
	$by_seq = array();
	foreach ( $r['rows'] as $row ) { $by_seq[ $row['sequence'] ] = $row['verified']; }
	tzfd_assert( true === $by_seq[1] && false === $by_seq[2] && false === $by_seq[3], 'rows before the break verify, rows from the break do not' );
	$html = $V::render( $r );
	tzfd_assert( false !== strpos( $html, 'failed verification from sequence 2' ), 'viewer warns about the broken chain' );

	// Filters and limits.
	$source->set( $chain );
	$books = $V::rows( $source, 'co-1', $secret, array( 'tool' => 'book' ) );
	tzfd_assert_same( 2, $books['total'], 'tool filter keeps book receipts only' );
	$approvals = $V::rows( $source, 'co-1', $secret, array( 'outcome' => 'needs_approval' ) );
	tzfd_assert_same( 'rc-2', $approvals['rows'][0]['receipt_id'], 'outcome filter' );
	$one = $V::rows( $source, 'co-1', $secret, array(), 1 );
	tzfd_assert( true === $one['truncated'] && 1 === count( $one['rows'] ), 'limit truncates and says so' );
	tzfd_throws( function () use ( $V, $source, $secret ) { $V::rows( $source, 'co-1', $secret, array( 'tool' => 'eval_php' ) ); }, 'unknown tool filter throws' );
	tzfd_throws( function () use ( $V, $source, $secret ) { $V::rows( $source, 'co-1', $secret, array(), 500 ); }, 'limit above maximum throws' );
	tzfd_throws( function () use ( $V, $secret ) { $V::rows( new stdClass(), 'co-1', $secret ); }, 'source without the receipt method throws' );
	tzfd_throws( function () use ( $V, $source, $secret ) { $V::rows( $source, 'co 1', $secret ); }, 'malformed company id throws' );

	// Empty state and escaping.
	$empty = $V::rows( new TZFD_Fake_Action_Source( array() ), 'co-1', $secret );
	tzfd_assert( false !== strpos( $V::render( $empty ), 'No agent actions have been recorded yet.' ), 'empty history has an empty state' );
	$hostile = array( 'chain_valid' => true, 'foreign_dropped' => 0, 'rows' => array( array(
		'receipt_id' => 'x', 'sequence' => 1, 'occurred_at' => 1800000000, 'tool' => '<script>alert(1)</script>',
		'outcome' => 'executed', 'reason' => 'granted', 'actor' => 'agent', 'grant_version' => 1, 'verified' => true,
	) ) );
	$html = $V::render( $hostile );
	tzfd_assert( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ), 'rendered HTML escapes hostile values' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
