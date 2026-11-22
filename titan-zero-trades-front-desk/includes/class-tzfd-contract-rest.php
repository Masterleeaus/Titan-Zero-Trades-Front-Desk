<?php
/**
 * REST binding for the hosted-service contract v1 (issue #349, parent #285).
 *
 * One route: POST /titan-zero-front-desk/v1/contract. The body is a signed envelope as defined in
 * class-tzfd-service-contract.php. The route fails closed:
 *   - no server-side credentials for the envelope's company (filter tzfd_contract_credentials_v1) => 401,
 *   - bad signature, stale clock, unknown operation or replayed nonce => 401 / 409,
 *   - actor not allowed the operation (capability, scope, approval, tenant) => 403,
 *   - no executor registered for the operation (filter tzfd_contract_executor_v1) => 501.
 * The route itself runs no business logic. Executors are added by the owner's code through the filter.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-service-contract.php';
require_once __DIR__ . '/class-tzfd-rest-request-guard.php';

final class TZFD_Contract_REST {
    const REST_NAMESPACE = 'titan-zero-front-desk/v1';
    const ROUTE          = '/contract';
    const MAX_BYTES      = 65536;
    const MAX_FIELDS     = 40;
    const NONCE_PREFIX   = 'tzfd_cnonce_';
    /** Twice the clock skew: a signed request can be seen again only while its timestamp is still in the window. */
    const NONCE_TTL      = 600;

    /** Verified request handed from the permission step to the callback within one REST request. */
    private static $context = null;

    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes() {
        register_rest_route( self::REST_NAMESPACE, self::ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'receive' ),
            // The signed body must verify, and the actor must be allowed the operation, before the callback runs.
            'permission_callback' => array( __CLASS__, 'authorise' ),
        ) );
    }

    /** Permission step. Returns true, or a WP_Error carrying the HTTP status. */
    public static function authorise( $request ) {
        self::$context = null;
        if ( ! TZFD_REST_Request_Guard::check( $request, 'POST', self::MAX_BYTES, self::MAX_FIELDS, 'application/json' ) ) {
            return self::error( 'tzfd_contract_bad_request', 'Invalid contract request.', 400 );
        }
        $parsed = TZFD_Service_Contract::parse( $request->get_body() );
        if ( ! $parsed['ok'] ) {
            return self::error( 'tzfd_contract_bad_request', 'Invalid contract request.', 400 );
        }
        $env    = $parsed['envelope'];
        $tenant = isset( $env['tenant_id'] ) && is_int( $env['tenant_id'] ) ? $env['tenant_id'] : 0;

        // Keys are server-side only. Without a resolver for this company, nothing runs.
        $creds = $tenant > 0 ? apply_filters( 'tzfd_contract_credentials_v1', null, $tenant ) : null;
        if ( ! self::valid_credentials( $creds, $tenant ) ) {
            return self::error( 'tzfd_contract_unauthorised', 'Invalid contract request.', 401 );
        }

        $verify = TZFD_Service_Contract::verify( $env, $creds['secret'], time(), array() );
        if ( ! $verify['ok'] ) {
            return self::error( 'tzfd_contract_' . $verify['reason'], 'Invalid contract request.', 401 );
        }

        // Atomic claim of the nonce. A verified request consumes its nonce, even if it is refused below.
        if ( ! add_option( self::NONCE_PREFIX . md5( $env['nonce'] ), (string) time(), '', 'no' ) ) {
            return self::error( 'tzfd_contract_replay', 'Invalid contract request.', 409 );
        }
        self::prune_nonces();

        $actor         = $creds['actor'];
        $actor['tenant_id'] = $tenant;
        $auth = TZFD_Service_Contract::authorize( $env['op'], $actor, $tenant );
        if ( ! $auth['allowed'] ) {
            return self::error( 'tzfd_contract_' . $auth['reason'], 'Not allowed.', 403 );
        }

        self::$context = array( 'env' => $env, 'key' => $creds['secret'] );
        return true;
    }

    /** Callback: runs the bound executor for the verified operation and returns a signed reply. */
    public static function receive( $request ) {
        $ctx = self::$context;
        self::$context = null;
        if ( null === $ctx ) {
            return self::error( 'tzfd_contract_unauthorised', 'Invalid contract request.', 401 );
        }
        $env    = $ctx['env'];
        $result = apply_filters( 'tzfd_contract_executor_v1', null, $env['op'], $env['tenant_id'], $env['payload'] );
        if ( null === $result ) {
            return self::error( 'tzfd_contract_operation_not_bound', 'Operation not available on this site.', 501 );
        }
        if ( is_wp_error( $result ) ) {
            return self::error( 'tzfd_contract_operation_failed', 'Operation failed.', 502 );
        }
        if ( ! is_array( $result ) ) {
            return self::error( 'tzfd_contract_operation_failed', 'Operation failed.', 502 );
        }
        return new WP_REST_Response( array(
            'status'   => 200,
            'reason'   => 'ok',
            'envelope' => self::signed_reply( $env, $result, $ctx['key'] ),
        ), 200 );
    }

    /** Signed reply with the same fields as the request, so the hosted side verifies it with the same key. */
    public static function signed_reply( array $env, array $result, $key ) {
        $signed = array(
            'v'         => $env['v'],
            'op'        => $env['op'],
            'tenant_id' => $env['tenant_id'],
            'ts'        => $env['ts'],
            'nonce'     => $env['nonce'],
            'payload'   => $result,
        );
        $signed['sig'] = TZFD_Service_Contract::sign( $signed, $key );
        return $signed;
    }

    private static function valid_credentials( $creds, $tenant ) {
        return is_array( $creds ) && isset( $creds['secret'], $creds['actor'] ) &&
            is_string( $creds['secret'] ) && strlen( $creds['secret'] ) >= TZFD_Service_Contract::MIN_KEY_BYTES &&
            is_array( $creds['actor'] ) && isset( $creds['actor']['type'] ) &&
            ( ! isset( $creds['actor']['tenant_id'] ) || $creds['actor']['tenant_id'] === $tenant );
    }

    /** Remove nonce claims older than the replay window. Values are the claim time. */
    private static function prune_nonces() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
            $wpdb->esc_like( self::NONCE_PREFIX ) . '%',
            time() - self::NONCE_TTL
        ) );
    }

    private static function error( $code, $message, $status ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }
}
