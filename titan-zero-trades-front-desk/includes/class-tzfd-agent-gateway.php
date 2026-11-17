<?php
/**
 * WordPress-side gate for hosted agent tool calls (issue #14).
 *
 * The hosted AI Agent proposes a signed tool-call envelope. This gate, inside the Free plugin, is the only place
 * that decides whether a call may run:
 *   1. Envelope shape and HMAC signature (a tampered envelope is refused and writes no receipt).
 *   2. Freshness window and single-use nonce (a replay is refused and recorded).
 *   3. Stored grant for the envelope's company (no grant means no receipt and a deny).
 *   4. TZFD_Agent_Tool_Permissions::decide() (least privilege, company and agent match, stop, pause, rate limit,
 *      dry run, owner approval).
 *   5. A registered executor runs only on allow. Without one the call is recorded as failed, never as executed.
 *   6. An append-only, chained, signed receipt is written for every authenticated call that has a grant.
 *
 * The model never supplies code or SQL: only a catalogue tool name and typed arguments reach this gate.
 * The store is injected (see TZFD_Agent_Gateway_Option_Store for the WordPress implementation).
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-agent-tool-permissions.php';
require_once __DIR__ . '/class-tzfd-action-receipt.php';
require_once __DIR__ . '/class-tzfd-action-receipt-viewer.php';

final class TZFD_Agent_Gateway {

	const WINDOW         = 300;   // seconds either side of issued_at that an envelope is accepted.
	const NONCE_CAP      = 5000;  // nonces kept; over the cap the gate refuses rather than forgetting one.
	const HOUR           = 3600;
	const EXECUTE_FILTER = 'tzfd_agent_execute_tool';
	const ENVELOPE_KEYS  = array( 'company_id', 'agent_id', 'tool', 'args', 'issued_at', 'nonce', 'signature' );
	/** Receipts that passed policy count toward the hourly ceiling. Denied calls do not, so they cannot lock out a tool. */
	const COUNTED        = array( 'executed', 'failed', 'dry_run', 'needs_approval' );

	/**
	 * Signs an envelope. The hosted side uses the same contract: HMAC-SHA256 over canonical JSON of every field
	 * except signature, keyed with a request key derived from the site secret.
	 *
	 * @param string $secret   Site secret, at least TZFD_Action_Receipt::MIN_SECRET characters.
	 * @param array  $envelope Envelope without a signature.
	 * @return string Lower-case hex signature.
	 * @throws InvalidArgumentException
	 */
	public static function sign( $secret, $envelope ) {
		unset( $envelope['signature'] );
		return hash_hmac( 'sha256', TZFD_Action_Receipt::canonical_json( $envelope ), self::request_key( $secret ) );
	}

	/**
	 * Handles one envelope and returns the decision. Never executes anything except through the executor filter.
	 *
	 * @param object $store    Store with get_grant, put_grant, receipts_for_company, append_receipt, nonces, put_nonces.
	 * @param string $secret   Site secret.
	 * @param mixed  $envelope Decoded envelope from the hosted service.
	 * @param int    $now      Unix seconds.
	 * @param callable|null $executor Runs an allowed call: receives the payload array, returns array( 'ok' => bool, 'reason' => string ).
	 *                                Null uses the EXECUTE_FILTER filter when one is registered.
	 * @return array decision, reason, outcome, executed, receipt_id, sequence.
	 */
	public static function handle( $store, $secret, $envelope, $now, $executor = null ) {
		$none = array( 'decision' => TZFD_Agent_Tool_Permissions::DECISION_DENY, 'reason' => '', 'outcome' => '', 'executed' => false, 'receipt_id' => null, 'sequence' => null );

		// 1. Shape and signature. Nothing is written for a call that is not authenticated.
		if ( ! self::shape_ok( $envelope ) ) { return self::refuse( $none, 'malformed_envelope' ); }
		if ( ! is_string( $secret ) || strlen( $secret ) < TZFD_Action_Receipt::MIN_SECRET ) { return self::refuse( $none, 'weak_secret' ); }
		if ( ! hash_equals( self::sign( $secret, $envelope ), (string) $envelope['signature'] ) ) { return self::refuse( $none, 'bad_signature' ); }

		$company = (string) $envelope['company_id'];
		$tool    = (string) $envelope['tool'];
		$args    = $envelope['args'];

		// 2. Grant for this company. Without one there is nothing to record against, so no receipt is written.
		$grant_raw = $store->get_grant( $company );
		if ( null === $grant_raw ) { return self::refuse( $none, 'no_grant' ); }
		try {
			$grant = TZFD_Agent_Tool_Permissions::validate_grant( $grant_raw );
		} catch ( InvalidArgumentException $e ) {
			return self::refuse( $none, 'invalid_grant' );
		}
		if ( ! isset( TZFD_Agent_Tool_Permissions::catalogue()[ $tool ] ) ) { return self::refuse( $none, 'unknown_tool' ); }

		// 3. Freshness, then single use.
		if ( abs( (int) $now - (int) $envelope['issued_at'] ) > self::WINDOW ) {
			return self::record( $store, $secret, $company, $envelope, $grant, $now, array( 'decision' => 'deny', 'reason' => 'stale_request' ), $executor );
		}
		$nonces = self::pruned_nonces( (array) $store->nonces(), $now );
		if ( isset( $nonces[ $envelope['nonce'] ] ) ) {
			return self::record( $store, $secret, $company, $envelope, $grant, $now, array( 'decision' => 'deny', 'reason' => 'replay' ), $executor );
		}
		if ( count( $nonces ) >= self::NONCE_CAP ) {
			return self::record( $store, $secret, $company, $envelope, $grant, $now, array( 'decision' => 'deny', 'reason' => 'nonce_capacity' ), $executor );
		}
		$nonces[ $envelope['nonce'] ] = (int) $envelope['issued_at'];
		$store->put_nonces( $nonces );

		// 4. Policy decision. Usage is counted from this company's receipts only.
		$usage = array();
		foreach ( (array) $store->receipts_for_company( $company ) as $receipt ) {
			if ( ! is_array( $receipt ) || ( $receipt['company_id'] ?? null ) !== $company ) { continue; }
			if ( ( $receipt['occurred_at'] ?? 0 ) <= $now - self::HOUR || ! in_array( $receipt['outcome'] ?? '', self::COUNTED, true ) ) { continue; }
			$name = (string) ( $receipt['tool'] ?? '' );
			$usage[ $name ] = ( $usage[ $name ] ?? 0 ) + 1;
		}
		$decision = TZFD_Agent_Tool_Permissions::decide( $grant, array(
			'company_id' => $company, 'agent_id' => (string) $envelope['agent_id'], 'tool' => $tool, 'args' => $args,
			'now' => (int) $now, 'receipts_last_hour' => $usage, 'approved_by_human' => false,
		) );
		return self::record( $store, $secret, $company, $envelope, $grant, $now, $decision, $executor );
	}

	/**
	 * Changes the state of a company's grant (active, paused or stopped). Stop is the emergency control.
	 * Returns the stored grant. Bumps the grant version, so every receipt shows which version was in force.
	 *
	 * @throws InvalidArgumentException When there is no grant or the state is unknown.
	 */
	public static function set_state( $store, $company, $state ) {
		$current = $store->get_grant( (string) $company );
		if ( null === $current ) { throw new InvalidArgumentException( 'no_grant' ); }
		$next = TZFD_Agent_Tool_Permissions::with_state( $current, $state );
		$store->put_grant( (string) $company, $next );
		return $next;
	}

	/**
	 * Validates and stores a grant. Version goes up on every save, so an older grant can never be restored silently.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function save_grant( $store, $grant ) {
		$clean = TZFD_Agent_Tool_Permissions::validate_grant( $grant );
		$old   = $store->get_grant( (string) $clean['company_id'] );
		if ( is_array( $old ) && isset( $old['version'] ) && (int) $old['version'] >= $clean['version'] ) {
			$clean['version'] = (int) $old['version'] + 1;
		}
		$store->put_grant( (string) $clean['company_id'], $clean );
		return $clean;
	}

	/**
	 * Runs the executor for an allowed call. Only a registered callback can make an action happen, and a call with
	 * no callback is recorded as failed. The callback receives typed arguments and returns array( 'ok' => bool, 'reason' => string ).
	 */
	private static function execute( $executor, $payload ) {
		if ( null === $executor ) {
			if ( ! function_exists( 'has_filter' ) || ! has_filter( self::EXECUTE_FILTER ) ) { return array( 'ok' => false, 'reason' => 'no_executor' ); }
			$executor = static function ( $p ) { return apply_filters( TZFD_Agent_Gateway::EXECUTE_FILTER, null, $p ); };
		}
		try {
			$result = call_user_func( $executor, $payload );
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'reason' => 'executor_error' );
		}
		if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
			$reason = is_array( $result ) && isset( $result['reason'] ) && is_string( $result['reason'] ) ? $result['reason'] : 'executor_failed';
			return array( 'ok' => false, 'reason' => preg_match( '/^[a-z_]{1,64}$/D', $reason ) ? $reason : 'executor_failed' );
		}
		return array( 'ok' => true, 'reason' => 'granted' );
	}

	/** Writes the chained receipt for a decision and returns the result. Runs the executor only for allow. */
	private static function record( $store, $secret, $company, $envelope, $grant, $now, $decision, $executor = null ) {
		$tool = (string) $envelope['tool'];
		$args = isset( $decision['args'] ) ? $decision['args'] : $envelope['args'];
		$verdict = (string) $decision['decision'];
		$reason  = (string) $decision['reason'];

		if ( TZFD_Agent_Tool_Permissions::DECISION_ALLOW === $verdict ) {
			$run = self::execute( $executor, array( 'company_id' => $company, 'agent_id' => (string) $envelope['agent_id'], 'tool' => $tool, 'args' => $args, 'now' => (int) $now ) );
			$outcome = $run['ok'] ? 'executed' : 'failed';
			$reason  = $run['reason'];
		} elseif ( TZFD_Agent_Tool_Permissions::DECISION_DRY_RUN === $verdict ) {
			$outcome = 'dry_run';
		} elseif ( TZFD_Agent_Tool_Permissions::DECISION_NEEDS_APPROVAL === $verdict ) {
			$outcome = 'needs_approval';
		} else {
			$outcome = 'denied';
		}

		$stored = self::append( $store, $secret, $company, $envelope, (int) $grant['version'], $outcome, $reason, (int) $now );
		return array(
			'decision'   => $verdict,
			'reason'     => $reason,
			'outcome'    => $outcome,
			'executed'   => 'executed' === $outcome,
			'receipt_id' => $stored['receipt_id'],
			'sequence'   => $stored['sequence'],
		);
	}

	private static function append( $store, $secret, $company, $envelope, $grant_version, $outcome, $reason, $now ) {
		$chain = (array) $store->receipts_for_company( $company );
		$sequence = 0;
		$previous = TZFD_Action_Receipt::GENESIS;
		foreach ( $chain as $receipt ) {
			if ( is_array( $receipt ) && isset( $receipt['sequence'], $receipt['hash'] ) && (int) $receipt['sequence'] > $sequence ) {
				$sequence = (int) $receipt['sequence'];
				$previous = (string) $receipt['hash'];
			}
		}
		$sequence++;
		$receipt_id = substr( hash( 'sha256', 'rcpt|' . $company . '|' . $envelope['nonce'] ), 0, 32 );
		$fields = array(
			'receipt_id' => $receipt_id, 'company_id' => $company, 'agent_id' => (string) $envelope['agent_id'],
			'tool' => (string) $envelope['tool'], 'outcome' => $outcome, 'reason' => $reason, 'grant_version' => $grant_version,
			'sequence' => $sequence, 'occurred_at' => (int) $now, 'actor' => 'agent',
			'args_digest' => TZFD_Action_Receipt::args_digest( $envelope['args'] ), 'previous_hash' => $previous,
		);
		$signed = TZFD_Action_Receipt::issue( $secret, $fields );
		$store->append_receipt( $company, $signed );
		return array( 'receipt_id' => $receipt_id, 'sequence' => $sequence );
	}

	/** Receipt rows for the admin viewer. Verification and filters come from TZFD_Action_Receipt_Viewer. */
	public static function receipts_view( $store, $secret, $company, $filters = array(), $limit = 50 ) {
		return TZFD_Action_Receipt_Viewer::rows( $store, (string) $company, $secret, $filters, $limit );
	}

	private static function request_key( $secret ) {
		if ( ! is_string( $secret ) || strlen( $secret ) < TZFD_Action_Receipt::MIN_SECRET ) { throw new InvalidArgumentException( 'weak_secret' ); }
		return hash_hmac( 'sha256', 'tzfd-agent-request-v1', $secret );
	}

	private static function pruned_nonces( $nonces, $now ) {
		$keep = array();
		foreach ( $nonces as $nonce => $issued ) {
			if ( is_string( $nonce ) && (int) $issued >= (int) $now - 2 * self::WINDOW ) { $keep[ $nonce ] = (int) $issued; }
		}
		return $keep;
	}

	private static function refuse( $none, $reason ) {
		$none['reason'] = $reason;
		return $none;
	}

	private static function shape_ok( $envelope ) {
		if ( ! is_array( $envelope ) || count( $envelope ) !== count( self::ENVELOPE_KEYS ) || array_diff( array_keys( $envelope ), self::ENVELOPE_KEYS ) ) { return false; }
		if ( ! is_string( $envelope['company_id'] ) || ! preg_match( TZFD_Delegated_Roles::ID_PATTERN, $envelope['company_id'] ) ) { return false; }
		if ( ! is_string( $envelope['agent_id'] ) || ! preg_match( TZFD_Delegated_Roles::ID_PATTERN, $envelope['agent_id'] ) ) { return false; }
		if ( ! is_string( $envelope['tool'] ) || ! is_array( $envelope['args'] ) || ! is_int( $envelope['issued_at'] ) ) { return false; }
		if ( ! is_string( $envelope['nonce'] ) || ! preg_match( '/^[a-f0-9]{32,64}$/D', $envelope['nonce'] ) ) { return false; }
		return is_string( $envelope['signature'] ) && (bool) preg_match( '/^[a-f0-9]{64}$/D', $envelope['signature'] );
	}
}

/**
 * WordPress option store for the gate. Each company has its own option, so one company's grant, receipts and
 * nonces never load with another's. Receipts are append-only: there is no method that edits or deletes one.
 */
final class TZFD_Agent_Gateway_Option_Store {

	const NONCES = 'tzfd_agent_nonces';

	public function get_grant( $company ) {
		$value = get_option( self::grant_key( $company ), null );
		return is_array( $value ) ? $value : null;
	}

	public function put_grant( $company, $grant ) {
		update_option( self::grant_key( $company ), $grant, false );
	}

	public function receipts_for_company( $company ) {
		$value = get_option( self::receipt_key( $company ), array() );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Adds one receipt at the end of the chain. Refuses a receipt whose sequence is not the next one, so an
	 * existing entry can never be replaced by appending.
	 *
	 * @throws RuntimeException
	 */
	public function append_receipt( $company, $receipt ) {
		$chain = $this->receipts_for_company( $company );
		if ( (int) $receipt['sequence'] !== count( $chain ) + 1 ) { throw new RuntimeException( 'receipt_sequence' ); }
		$chain[] = $receipt;
		if ( ! update_option( self::receipt_key( $company ), $chain, false ) ) {
			throw new RuntimeException( 'receipt_write_failed' );
		}
	}

	public function nonces() {
		$value = get_option( self::NONCES, array() );
		return is_array( $value ) ? $value : array();
	}

	public function put_nonces( $nonces ) {
		update_option( self::NONCES, $nonces, false );
	}

	/** Site secret for request and receipt signing. Created once, never shown on screen. */
	public static function secret() {
		$secret = get_option( 'tzfd_agent_secret', '' );
		if ( ! is_string( $secret ) || strlen( $secret ) < TZFD_Action_Receipt::MIN_SECRET ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'tzfd_agent_secret', $secret, false );
		}
		return $secret;
	}

	private static function grant_key( $company ) {
		return 'tzfd_agent_grant_' . self::safe( $company );
	}

	private static function receipt_key( $company ) {
		return 'tzfd_agent_receipts_' . self::safe( $company );
	}

	private static function safe( $company ) {
		$company = (string) $company;
		if ( ! preg_match( TZFD_Delegated_Roles::ID_PATTERN, $company ) ) { throw new InvalidArgumentException( 'invalid_company_id' ); }
		return $company;
	}
}
