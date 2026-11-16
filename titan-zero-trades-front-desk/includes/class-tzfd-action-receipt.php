<?php
/**
 * Immutable, signed action receipts for agent tool calls.
 *
 * Each receipt carries the previous receipt's hash, so editing, deleting or reordering any entry breaks the
 * chain at a known position. Each receipt is also HMAC-signed with a site secret the model never sees. Raw
 * arguments are never stored, only a SHA-256 digest of their canonical JSON. Pure PHP, no WordPress calls.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';
require_once __DIR__ . '/class-tzfd-agent-tool-permissions.php';

final class TZFD_Action_Receipt {

	const MIN_SECRET = 32;
	const GENESIS    = '0000000000000000000000000000000000000000000000000000000000000000';
	const OUTCOMES   = array( 'executed', 'failed', 'denied', 'dry_run', 'needs_approval' );
	const ACTORS     = array( 'agent', 'human' );

	/**
	 * Builds a signed receipt. Throws when the fields are incomplete or the secret is weak.
	 *
	 * @param string $secret Site secret, at least MIN_SECRET characters.
	 * @param array  $fields receipt_id, company_id, agent_id, tool, outcome, reason, grant_version, sequence,
	 *                       occurred_at, actor, args_digest, previous_hash.
	 * @return array Fields plus hash and signature.
	 * @throws InvalidArgumentException
	 */
	public static function issue( $secret, $fields ) {
		self::require_secret( $secret );
		$clean = self::validate_fields( $fields );
		$hash = self::hash_for( $clean );
		$clean['hash'] = $hash;
		$clean['signature'] = hash_hmac( 'sha256', $hash, $secret );
		return $clean;
	}

	/** Verifies one receipt against the previous hash the caller expects. Returns false on any mismatch. */
	public static function verify( $secret, $receipt, $expected_previous_hash ) {
		if ( ! is_string( $secret ) || strlen( $secret ) < self::MIN_SECRET || ! is_array( $receipt ) ) { return false; }
		if ( ! isset( $receipt['hash'], $receipt['signature'], $receipt['previous_hash'] ) ) { return false; }
		if ( ! is_string( $receipt['hash'] ) || ! is_string( $receipt['signature'] ) || ! is_string( $expected_previous_hash ) ) { return false; }
		if ( ! hash_equals( $expected_previous_hash, (string) $receipt['previous_hash'] ) ) { return false; }
		try {
			$fields = self::validate_fields( self::strip( $receipt ) );
		} catch ( InvalidArgumentException $e ) {
			return false;
		}
		$hash = self::hash_for( $fields );
		return hash_equals( $hash, $receipt['hash'] ) && hash_equals( hash_hmac( 'sha256', $hash, $secret ), $receipt['signature'] );
	}

	/**
	 * Verifies a whole company's chain in sequence order, starting from GENESIS. Sequence numbers must run
	 * 1, 2, 3 without gaps.
	 *
	 * @return array{valid: bool, broken_at: int|null}
	 */
	public static function verify_chain( $secret, $receipts ) {
		if ( ! is_array( $receipts ) ) { return array( 'valid' => false, 'broken_at' => 0 ); }
		$previous = self::GENESIS;
		$expected_sequence = 1;
		foreach ( array_values( $receipts ) as $index => $receipt ) {
			$sequence_ok = is_array( $receipt ) && isset( $receipt['sequence'] ) && $receipt['sequence'] === $expected_sequence;
			if ( ! $sequence_ok || ! self::verify( $secret, $receipt, $previous ) ) {
				return array( 'valid' => false, 'broken_at' => $index );
			}
			$previous = $receipt['hash'];
			$expected_sequence++;
		}
		return array( 'valid' => true, 'broken_at' => null );
	}

	/** Digest of the arguments a receipt refers to, so the receipt never holds raw customer data. */
	public static function args_digest( $args ) {
		return hash( 'sha256', self::canonical( is_array( $args ) ? $args : array() ) );
	}

	/**
	 * Claims a nonce once. $seen is a set the caller persists. Returns false when the nonce was already used.
	 * Malformed nonces are refused, so an attacker cannot fill the set with garbage.
	 */
	public static function claim_nonce( &$seen, $nonce ) {
		if ( ! is_string( $nonce ) || ! preg_match( '/^[a-f0-9]{32,64}$/D', $nonce ) || ! is_array( $seen ) ) { return false; }
		if ( isset( $seen[ $nonce ] ) ) { return false; }
		$seen[ $nonce ] = true;
		return true;
	}

	/** Validates receipt fields and returns them in a fixed key set. */
	private static function validate_fields( $fields ) {
		if ( ! is_array( $fields ) ) { throw new InvalidArgumentException( 'fields_not_array' ); }
		$expected = array( 'receipt_id', 'company_id', 'agent_id', 'tool', 'outcome', 'reason', 'grant_version', 'sequence', 'occurred_at', 'actor', 'args_digest', 'previous_hash' );
		foreach ( array_keys( $fields ) as $key ) {
			if ( ! in_array( $key, $expected, true ) ) { throw new InvalidArgumentException( 'unknown_receipt_key' ); }
		}
		foreach ( array( 'receipt_id', 'company_id', 'agent_id' ) as $key ) {
			if ( ! self::is_id( $fields[ $key ] ?? null ) ) { throw new InvalidArgumentException( 'invalid_' . $key ); }
		}
		if ( ! in_array( $fields['tool'] ?? null, array_keys( TZFD_Agent_Tool_Permissions::catalogue() ), true ) ) { throw new InvalidArgumentException( 'invalid_tool' ); }
		if ( ! in_array( $fields['outcome'] ?? null, self::OUTCOMES, true ) ) { throw new InvalidArgumentException( 'invalid_outcome' ); }
		$reason = $fields['reason'] ?? null;
		if ( ! is_string( $reason ) || strlen( $reason ) > 64 || ! preg_match( '/^[a-z_]+$/D', $reason ) ) { throw new InvalidArgumentException( 'invalid_reason' ); }
		foreach ( array( 'grant_version', 'sequence' ) as $key ) {
			if ( ! is_int( $fields[ $key ] ?? null ) || $fields[ $key ] < 1 ) { throw new InvalidArgumentException( 'invalid_' . $key ); }
		}
		if ( ! is_int( $fields['occurred_at'] ?? null ) || $fields['occurred_at'] < 0 ) { throw new InvalidArgumentException( 'invalid_occurred_at' ); }
		if ( ! in_array( $fields['actor'] ?? null, self::ACTORS, true ) ) { throw new InvalidArgumentException( 'invalid_actor' ); }
		if ( ! is_string( $fields['args_digest'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fields['args_digest'] ) ) { throw new InvalidArgumentException( 'invalid_args_digest' ); }
		if ( ! is_string( $fields['previous_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fields['previous_hash'] ) ) { throw new InvalidArgumentException( 'invalid_previous_hash' ); }
		$out = array();
		foreach ( $expected as $key ) { $out[ $key ] = $fields[ $key ]; }
		return $out;
	}

	private static function hash_for( $fields ) {
		return hash( 'sha256', $fields['previous_hash'] . '|' . self::canonical( $fields ) );
	}

	private static function strip( $receipt ) {
		unset( $receipt['hash'], $receipt['signature'] );
		return $receipt;
	}

	/** Canonical JSON: keys sorted recursively, so the same content always hashes the same way. */
	private static function canonical( $value ) {
		return json_encode( self::sorted( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = self::sorted( $item ); }
		return $value;
	}

	private static function require_secret( $secret ) {
		if ( ! is_string( $secret ) || strlen( $secret ) < self::MIN_SECRET ) { throw new InvalidArgumentException( 'weak_secret' ); }
	}

	private static function is_id( $value ) {
		return is_string( $value ) && (bool) preg_match( TZFD_Delegated_Roles::ID_PATTERN, $value );
	}
}
