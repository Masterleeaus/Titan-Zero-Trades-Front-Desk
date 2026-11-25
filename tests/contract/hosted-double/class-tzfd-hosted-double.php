<?php
/**
 * Local test double of the hosted Titan Zero AI service (issue #256).
 *
 * TEST ONLY. This file lives under tests/, is never shipped in the plugin, opens no network
 * connection, calls no AI provider and no billing service. It answers the contract v1
 * handshake and signed operation requests in-process, so the contract tests can run in CI
 * without a hosted server. Executors return fixed fake data; no customer data is used.
 *
 * It is not the production hosted server. The production server component is still not in
 * this repository (see issue #16 and issue #256).
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'TZFD_CONTRACT_TEST' ) ) {
	define( 'TZFD_CONTRACT_TEST', 1 );
}
require_once dirname( __DIR__, 3 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-service-contract.php';

final class TZFD_Hosted_Double {

	const PLUGIN_MIN = '3.0.128';

	/** @var string */
	private $key;
	/** @var array tenant_id => [ 'state' => active|cancelled ] */
	private $companies;
	/** @var string */
	private $contract_version = '1.0.0';
	/** @var bool */
	private $outage = false;
	/** @var array nonce => true, accepted within the replay window */
	private $seen = array();

	/** Operations executed by the double, in order. Tests use this to prove a refused call never ran. */
	public $calls = array();

	/**
	 * @param string $key       Shared secret, at least 32 bytes.
	 * @param array  $companies tenant_id => [ 'state' => ... ].
	 */
	public function __construct( $key, array $companies ) {
		$this->key       = $key;
		$this->companies = $companies;
	}

	public function set_outage( $on ) {
		$this->outage = (bool) $on;
	}

	public function set_company_state( $tenant_id, $state ) {
		$this->companies[ $tenant_id ]['state'] = $state;
	}

	public function set_contract_version( $version ) {
		$this->contract_version = (string) $version;
	}

	/** Descriptor the hosted side declares in the handshake. */
	public function hosted_descriptor() {
		return array(
			'contract_version'    => $this->contract_version,
			'min_plugin_version'  => self::PLUGIN_MIN,
		);
	}

	/** Version handshake, delegated to the real contract rules. */
	public function handshake( array $plugin ) {
		return TZFD_Service_Contract::handshake( $plugin, $this->hosted_descriptor() );
	}

	/**
	 * Handle one raw request body from the plugin side.
	 *
	 * Order: outage, parse, verify (structure, version, op, clock, nonce, replay, payload, signature),
	 * authorise (actor, tenant, scope, approval), company entitlement, executor. Only a verified
	 * request consumes its nonce, so an outage or a malformed body does not burn it.
	 *
	 * @param string $raw   Raw JSON body.
	 * @param array  $actor Actor the plugin is acting for.
	 * @param int    $now   Unix time on the hosted side.
	 * @return array [ 'status' => int, 'body' => array ]
	 */
	public function handle( $raw, array $actor, $now ) {
		if ( $this->outage ) {
			return $this->error( 503, 'hosted_unavailable' );
		}
		$parsed = TZFD_Service_Contract::parse( $raw );
		if ( ! $parsed['ok'] ) {
			return $this->error( 400, $parsed['reason'] );
		}
		$env    = $parsed['envelope'];
		$verify = TZFD_Service_Contract::verify( $env, $this->key, $now, $this->seen );
		if ( ! $verify['ok'] ) {
			return $this->error( 'replay' === $verify['reason'] ? 409 : 401, $verify['reason'] );
		}
		$this->seen[ $env['nonce'] ] = true;

		$auth = TZFD_Service_Contract::authorize( $env['op'], $actor, $env['tenant_id'] );
		if ( ! $auth['allowed'] ) {
			return $this->error( 403, $auth['reason'] );
		}
		if ( ! isset( $this->companies[ $env['tenant_id'] ] ) ) {
			return $this->error( 403, 'tenant_unknown' );
		}
		if ( 'active' !== $this->companies[ $env['tenant_id'] ]['state'] ) {
			return $this->error( 402, 'entitlement_inactive' );
		}

		$this->calls[] = $env['op'];
		return $this->reply( $env, $this->execute( $env['op'], $env['tenant_id'], $env['payload'] ) );
	}

	/** Fixed fake results. Never reads or writes real data. */
	private function execute( $op, $tenant_id, array $payload ) {
		$result = array( 'stub' => true, 'op' => $op, 'tenant_id' => $tenant_id, 'items' => array() );
		if ( 'booking.list' === $op ) {
			$result['items'] = array( array( 'id' => 1, 'status' => 'pending' ) );
		}
		return $result;
	}

	/** Signed reply: the plugin checks the signature with the same key and matches the nonce. */
	private function reply( array $env, array $result ) {
		$signed = array(
			'v'         => $env['v'],
			'op'        => $env['op'],
			'tenant_id' => $env['tenant_id'],
			'ts'        => $env['ts'],
			'nonce'     => $env['nonce'],
			'payload'   => $result,
		);
		$signed['sig'] = TZFD_Service_Contract::sign( $signed, $this->key );
		return array( 'status' => 200, 'body' => array( 'status' => 200, 'reason' => 'ok', 'envelope' => $signed ) );
	}

	private function error( $status, $reason ) {
		return array( 'status' => $status, 'body' => array( 'status' => $status, 'reason' => $reason ) );
	}

	/** Plugin-side check of a reply: signature under the shared key and the nonce of the request. */
	public static function reply_is_valid( array $reply, $key, $nonce ) {
		if ( 200 !== ( $reply['status'] ?? 0 ) || ! isset( $reply['body']['envelope'] ) ) {
			return false;
		}
		$env = $reply['body']['envelope'];
		if ( ! isset( $env['sig'], $env['nonce'] ) || $env['nonce'] !== $nonce ) {
			return false;
		}
		$unsigned = $env;
		unset( $unsigned['sig'] );
		return is_string( $env['sig'] ) && hash_equals( TZFD_Service_Contract::sign( $unsigned, $key ), $env['sig'] );
	}

	/** Build and sign a request body the way the plugin would. */
	public static function envelope( $op, $tenant_id, array $payload, $key, $ts, $nonce, $version = '1.0.0' ) {
		$env        = array(
			'v'         => $version,
			'op'        => $op,
			'tenant_id' => $tenant_id,
			'ts'        => $ts,
			'nonce'     => $nonce,
			'payload'   => $payload,
		);
		$env['sig'] = TZFD_Service_Contract::sign( $env, $key );
		return json_encode( $env ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test harness, no WordPress.
	}
}
