<?php
/**
 * REST binding for the hosted-service contract (issue #285, contract v1).
 *
 * One route receives a signed envelope from the hosted Assistant or Agent Team:
 *   POST /wp-json/titan-zero-front-desk/v1/service-contract
 *
 * Fail-safe rules:
 * - Nothing is accepted until the site has a contract key (option tzfd_hosted_contract_key, at least 32 bytes).
 *   No key means every call is refused and no local feature changes.
 * - The envelope is checked in TZFD_Service_Contract::verify() (structure, version, clock window, nonce, signature).
 *   A signed request is claimed once, so a replay is refused.
 * - The calling actor is an agent whose scopes come from option tzfd_hosted_contract_scopes. Deny by default: no scopes
 *   means every operation is refused. Writes need approval, which a hosted call never has here.
 * - Only operations with a handler in executors() run. Any other allowed operation returns 501.
 *
 * Pairing (the site-side key issue) is still open: see #347 and #349. The key is set by an administrator only.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tzfd-service-contract.php';
require_once __DIR__ . '/class-tzfd-signed-request.php';

final class TZFD_Service_Contract_REST {

	const NAMESPACE_V1  = 'titan-zero-front-desk/v1';
	const ROUTE         = '/service-contract';
	const KEY_OPTION    = 'tzfd_hosted_contract_key';
	const SCOPES_OPTION = 'tzfd_hosted_contract_scopes';

	public static function register() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		// Authentication is the signed envelope itself, checked in handle(). The route is not a WordPress user route.
		register_rest_route( self::NAMESPACE_V1, self::ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Operations that have a handler. Each receives the verified envelope and returns the result payload. */
	public static function executors() {
		return array(
			'capability.check' => static function ( $env ) {
				return array( 'op' => $env['op'], 'allowed' => true );
			},
		);
	}

	/**
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function handle( $req ) {
		$raw    = is_object( $req ) && method_exists( $req, 'get_body' ) ? $req->get_body() : '';
		$parsed = TZFD_Service_Contract::parse( $raw );
		if ( ! $parsed['ok'] ) {
			return self::refuse( 400, $parsed['reason'] );
		}
		$env = $parsed['envelope'];

		$key = self::key();
		if ( '' === $key ) {
			return self::refuse( 403, 'contract_not_paired' );
		}

		$verified = TZFD_Service_Contract::verify( $env, $key, time(), array() );
		if ( ! $verified['ok'] ) {
			$status = 'signature_invalid' === $verified['reason'] ? 401 : 400;
			return self::refuse( $status, $verified['reason'] );
		}

		// Claim the signature once, so the same signed request cannot be run twice.
		if ( ! TZFD_Signed_Request::claim_signature( $env['sig'], time() ) ) {
			return self::refuse( 409, 'replay' );
		}

		$tenant = self::local_tenant();
		$actor  = array(
			'type'      => 'agent',
			'tenant_id' => $tenant,
			'scopes'    => self::scopes(),
			'approved'  => false,
		);
		$auth = TZFD_Service_Contract::authorize( $env['op'], $actor, $env['tenant_id'] );
		if ( ! $auth['allowed'] ) {
			return self::refuse( 403, $auth['reason'] );
		}

		$executors = self::executors();
		if ( ! isset( $executors[ $env['op'] ] ) ) {
			return self::refuse( 501, 'operation_not_implemented' );
		}
		$result = call_user_func( $executors[ $env['op'] ], $env );

		return new WP_REST_Response( array( 'ok' => true, 'op' => $env['op'], 'result' => $result ), 200 );
	}

	/** Stored contract key, or '' when the site is not paired or the key is too short. */
	public static function key() {
		$key = get_option( self::KEY_OPTION, '' );
		return is_string( $key ) && strlen( $key ) >= TZFD_Service_Contract::MIN_KEY_BYTES ? $key : '';
	}

	/** Scopes granted to the hosted agent, limited to the scopes the contract registry knows. */
	public static function scopes() {
		$known  = array();
		foreach ( TZFD_Service_Contract::operations() as $def ) {
			$known[ $def[2] ] = true;
		}
		$stored = get_option( self::SCOPES_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $scope ) {
			if ( is_string( $scope ) && isset( $known[ $scope ] ) ) {
				$out[] = $scope;
			}
		}
		return $out;
	}

	private static function local_tenant() {
		return class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
	}

	private static function refuse( $status, $reason ) {
		return new WP_REST_Response( array( 'ok' => false, 'reason' => (string) $reason ), (int) $status );
	}
}

TZFD_Service_Contract_REST::register();
