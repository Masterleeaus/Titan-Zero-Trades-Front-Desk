<?php
/**
 * Fake action source for tests. Stands in for the signed connector's receipt store, which does not exist yet.
 * Holds receipts exactly as given, so tests can tamper with them.
 */
class TZFD_Fake_Action_Source {
	private $receipts;

	public function __construct( array $receipts ) {
		$this->receipts = $receipts;
	}

	public function receipts_for_company( $company_id ) {
		return $this->receipts;
	}

	public function set( array $receipts ) {
		$this->receipts = $receipts;
	}
}

/**
 * Builds a valid receipt chain for one company from a list of events. Each event is an array of fields
 * with receipt_id, tool, outcome, reason, actor, occurred_at. Sequence and hashes are added here.
 */
function tzfd_fake_chain( $secret, $company_id, $agent_id, $events ) {
	$previous = TZFD_Action_Receipt::GENESIS;
	$chain = array();
	foreach ( $events as $i => $event ) {
		$receipt = TZFD_Action_Receipt::issue( $secret, array(
			'receipt_id'    => $event['receipt_id'],
			'company_id'    => $company_id,
			'agent_id'      => $agent_id,
			'tool'          => $event['tool'],
			'outcome'       => $event['outcome'],
			'reason'        => $event['reason'],
			'grant_version' => 1,
			'sequence'      => $i + 1,
			'occurred_at'   => $event['occurred_at'],
			'actor'         => $event['actor'],
			'args_digest'   => TZFD_Action_Receipt::args_digest( $event['args'] ?? array() ),
			'previous_hash' => $previous,
		) );
		$previous = $receipt['hash'];
		$chain[] = $receipt;
	}
	return $chain;
}
