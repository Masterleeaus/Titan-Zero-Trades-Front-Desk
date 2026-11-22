<?php
/**
 * Versioned, typed contract between the Scheduling & Enquiries Manager plugin and the
 * optional hosted Titan Zero AI services (Assistant / Agent Team).
 *
 * Pure PHP with no WordPress calls, so it can be exercised from the CLI (tests/contract).
 * It defines: the operation registry, the owner/agent permission rules, the version
 * handshake, and the signed request envelope with replay and malicious-input checks.
 * Nothing here executes remote code, and nothing here gates a local feature.
 */
defined( 'ABSPATH' ) || exit;

final class TZFD_Service_Contract {

    /** Contract version. The major number changes only for breaking operation or envelope changes. */
    const VERSION         = '1.0.0';
    const MAJOR           = 1;
    const MAX_BODY_BYTES  = 65536;
    const MAX_DEPTH       = 6;
    const MAX_STRING      = 10000;
    const CLOCK_SKEW      = 300;
    const MIN_KEY_BYTES   = 32;
    const OWNER_CAP       = 'tz_fd_access';
    const ADMIN_CAP       = 'manage_options';

    /**
     * Operation registry: name => [ kind, required capability, agent scope, approval needed for agents ].
     * Deny by default: an operation that is not listed here is refused.
     */
    public static function operations() {
        return array(
            'service.catalogue.list' => array( 'read',  self::OWNER_CAP, 'read_services',     false ),
            'staff.list'             => array( 'read',  self::OWNER_CAP, 'read_staff',        false ),
            'locations.list'         => array( 'read',  self::OWNER_CAP, 'read_locations',    false ),
            'form_fields.list'       => array( 'read',  self::OWNER_CAP, 'read_forms',        false ),
            'booking.list'           => array( 'read',  self::OWNER_CAP, 'read_bookings',     false ),
            'booking.get'            => array( 'read',  self::OWNER_CAP, 'read_bookings',     false ),
            'booking.create'         => array( 'write', self::OWNER_CAP, 'write_bookings',    true ),
            'booking.status.set'     => array( 'write', self::OWNER_CAP, 'write_bookings',    true ),
            'transaction.list'       => array( 'read',  self::OWNER_CAP, 'read_transactions', false ),
            'report.summary'         => array( 'read',  self::OWNER_CAP, 'read_reports',      false ),
            'settings.get'           => array( 'read',  self::ADMIN_CAP, 'read_settings',     false ),
            'capability.check'       => array( 'read',  self::OWNER_CAP, 'read_settings',     false ),
            'privacy.export'         => array( 'read',  self::ADMIN_CAP, 'privacy_export',    true ),
        );
    }

    /** Result: [ 'allowed' => bool, 'reason' => string ]. */
    private static function result( $allowed, $reason ) {
        return array( 'allowed' => (bool) $allowed, 'reason' => (string) $reason );
    }

    /**
     * Authorise one operation for one actor.
     *
     * @param string $op        Operation name from operations().
     * @param array  $actor     [ 'type' => owner|agent|visitor, 'tenant_id' => int, 'caps' => string[], 'scopes' => string[], 'approved' => bool ].
     * @param mixed  $tenant_id Tenant (company) the request targets.
     */
    public static function authorize( $op, array $actor, $tenant_id ) {
        $ops = self::operations();
        if ( ! is_string( $op ) || ! isset( $ops[ $op ] ) ) {
            return self::result( false, 'unknown_operation' );
        }
        if ( ! is_int( $tenant_id ) || $tenant_id < 1 ) {
            return self::result( false, 'tenant_invalid' );
        }
        $actor_tenant = isset( $actor['tenant_id'] ) ? $actor['tenant_id'] : 0;
        if ( ! is_int( $actor_tenant ) || $actor_tenant !== $tenant_id ) {
            return self::result( false, 'tenant_mismatch' );
        }
        list( , $cap, $scope, $approval_needed ) = $ops[ $op ];
        $type = isset( $actor['type'] ) ? $actor['type'] : '';

        if ( 'owner' === $type ) {
            $caps = isset( $actor['caps'] ) && is_array( $actor['caps'] ) ? $actor['caps'] : array();
            return in_array( $cap, $caps, true )
                ? self::result( true, 'owner_capability' )
                : self::result( false, 'capability_missing' );
        }
        if ( 'agent' === $type ) {
            $scopes = isset( $actor['scopes'] ) && is_array( $actor['scopes'] ) ? $actor['scopes'] : array();
            if ( ! in_array( $scope, $scopes, true ) ) {
                return self::result( false, 'scope_missing' );
            }
            if ( $approval_needed && empty( $actor['approved'] ) ) {
                return self::result( false, 'approval_required' );
            }
            return self::result( true, 'agent_scope' );
        }
        // Visitors and unknown actor types are refused for every operation.
        return self::result( false, 'actor_denied' );
    }

    /**
     * Version handshake. Both sides must share the contract major; the plugin must be at least
     * the minimum plugin version that the hosted service declares.
     *
     * @param array $plugin Keys: plugin_version (x.y.z), contract_majors (int[]).
     * @param array $hosted Keys: contract_version (x.y.z), min_plugin_version (x.y.z).
     */
    public static function handshake( array $plugin, array $hosted ) {
        $pv = isset( $plugin['plugin_version'] ) ? $plugin['plugin_version'] : '';
        $hv = isset( $hosted['contract_version'] ) ? $hosted['contract_version'] : '';
        $mv = isset( $hosted['min_plugin_version'] ) ? $hosted['min_plugin_version'] : '';
        $semver = '/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/';
        if ( ! is_string( $pv ) || ! preg_match( $semver, $pv ) ) {
            return self::result( false, 'plugin_version_invalid' );
        }
        if ( ! is_string( $hv ) || ! preg_match( $semver, $hv ) || ! is_string( $mv ) || ! preg_match( $semver, $mv ) ) {
            return self::result( false, 'hosted_version_invalid' );
        }
        $majors = isset( $plugin['contract_majors'] ) && is_array( $plugin['contract_majors'] ) ? $plugin['contract_majors'] : array();
        if ( ! in_array( self::MAJOR, $majors, true ) ) {
            return self::result( false, 'contract_major_unsupported' );
        }
        if ( (int) explode( '.', $hv )[0] !== self::MAJOR ) {
            return self::result( false, 'hosted_contract_major_unsupported' );
        }
        if ( version_compare( $pv, $mv, '<' ) ) {
            return self::result( false, 'plugin_upgrade_required' );
        }
        return self::result( true, 'compatible' );
    }

    /** Canonical JSON for signing: keys sorted recursively, no escaping of slashes. */
    public static function canonical( $value ) {
        return json_encode( self::sort_keys( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
    }

    private static function sort_keys( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
        if ( ! $is_list ) {
            ksort( $value, SORT_STRING );
        }
        foreach ( $value as $k => $v ) {
            $value[ $k ] = self::sort_keys( $v );
        }
        return $value;
    }

    /** Fields covered by the signature. */
    private static function signed_fields( array $env ) {
        return array(
            'v'         => $env['v'],
            'op'        => $env['op'],
            'tenant_id' => $env['tenant_id'],
            'ts'        => $env['ts'],
            'nonce'     => $env['nonce'],
            'payload'   => $env['payload'],
        );
    }

    /** HMAC-SHA256 over the canonical signed fields. */
    public static function sign( array $env, $key ) {
        return hash_hmac( 'sha256', self::canonical( self::signed_fields( $env ) ), (string) $key );
    }

    /** Parse a raw request body into an envelope array. Returns [ 'ok', 'reason', 'envelope' ]. */
    public static function parse( $raw ) {
        if ( ! is_string( $raw ) || strlen( $raw ) === 0 || strlen( $raw ) > self::MAX_BODY_BYTES ) {
            return array( 'ok' => false, 'reason' => 'body_size_invalid', 'envelope' => null );
        }
        $env = json_decode( $raw, true, self::MAX_DEPTH + 2 );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $env ) ) {
            return array( 'ok' => false, 'reason' => 'json_invalid', 'envelope' => null );
        }
        return array( 'ok' => true, 'reason' => 'parsed', 'envelope' => $env );
    }

    private static function depth_ok( $value, $depth = 0 ) {
        if ( is_string( $value ) ) {
            return strlen( $value ) <= self::MAX_STRING;
        }
        if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
            return true;
        }
        if ( ! is_array( $value ) || $depth >= self::MAX_DEPTH ) {
            return false;
        }
        foreach ( $value as $k => $v ) {
            if ( ! is_string( $k ) && ! is_int( $k ) ) {
                return false;
            }
            if ( ! self::depth_ok( $v, $depth + 1 ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Verify a parsed envelope. Checks run in this order: structure, contract version,
     * operation name, clock window, nonce format, replay, then signature.
     *
     * @param array $env        Parsed envelope.
     * @param string $key       Shared secret held by WordPress (never sent to the browser).
     * @param int   $now        Unix time.
     * @param array $seen_nonces Nonces already accepted in the replay window (set of nonce => true).
     * @return array [ 'ok' => bool, 'reason' => string, 'nonce' => string|null ]
     */
    public static function verify( array $env, $key, $now, array $seen_nonces ) {
        $fail = function ( $reason ) {
            return array( 'ok' => false, 'reason' => $reason, 'nonce' => null );
        };
        foreach ( array( 'v', 'op', 'tenant_id', 'ts', 'nonce', 'payload', 'sig' ) as $field ) {
            if ( ! array_key_exists( $field, $env ) ) {
                return $fail( 'field_missing' );
            }
        }
        if ( ! is_string( $env['v'] ) || ! preg_match( '/\A' . self::MAJOR . '\.\d+\.\d+\z/', $env['v'] ) ) {
            return $fail( 'contract_version_unsupported' );
        }
        if ( ! is_string( $env['op'] ) || ! isset( self::operations()[ $env['op'] ] ) ) {
            return $fail( 'unknown_operation' );
        }
        if ( ! is_int( $env['tenant_id'] ) || $env['tenant_id'] < 1 ) {
            return $fail( 'tenant_invalid' );
        }
        if ( ! is_int( $env['ts'] ) || abs( (int) $now - $env['ts'] ) > self::CLOCK_SKEW ) {
            return $fail( 'timestamp_outside_window' );
        }
        if ( ! is_string( $env['nonce'] ) || ! preg_match( '/\A[A-Za-z0-9_-]{16,128}\z/', $env['nonce'] ) ) {
            return $fail( 'nonce_invalid' );
        }
        if ( isset( $seen_nonces[ $env['nonce'] ] ) ) {
            return $fail( 'replay' );
        }
        if ( ! is_array( $env['payload'] ) || ! self::depth_ok( $env['payload'] ) ) {
            return $fail( 'payload_invalid' );
        }
        if ( ! is_string( $key ) || strlen( $key ) < self::MIN_KEY_BYTES ) {
            return $fail( 'key_invalid' );
        }
        if ( ! is_string( $env['sig'] ) || ! hash_equals( self::sign( $env, $key ), $env['sig'] ) ) {
            return $fail( 'signature_invalid' );
        }
        return array( 'ok' => true, 'reason' => 'verified', 'nonce' => $env['nonce'] );
    }
}
