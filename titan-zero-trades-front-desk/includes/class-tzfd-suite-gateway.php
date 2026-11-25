<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Front Desk to suite-owner API boundary, v1.
 * Owners supply callbacks via the registered filter; this class never touches
 * owner tables or claims that an unavailable owner completed an operation.
 */
final class TZFD_Suite_Gateway {
    const VERSION = 1;
    private static $operations = array(
        'workspace' => array( 'customer.read', 'staff.read', 'service.read', 'site.read', 'vertical.read', 'lead.submit', 'audit.append', 'smart_task.record', 'message.send' ),
        'quotes' => array( 'catalog.read', 'quote.request' ),
        'invoicing' => array( 'deposit.request', 'booking.notify' ),
    );

    public static function operations() { return self::$operations; }

    /** Return a structured failure unless a named owner handles the request. */
    public static function request( $owner, $operation, $company_id, array $payload = array() ) {
        if ( ! is_string( $owner ) || ! isset( self::$operations[ $owner ] ) ||
             ! is_string( $operation ) || ! in_array( $operation, self::$operations[ $owner ], true ) ) {
            return self::failure( 'operation_not_allowed' );
        }
        $id = filter_var( $company_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( false === $id ) { return self::failure( 'company_required' ); }
        // The caller MUST have already authorised access to this company.
        // The owner MUST independently check company scope and permissions.
        $envelope = array(
            'schema_version' => self::VERSION,
            'owner' => $owner,
            'operation' => $operation,
            'company_id' => (int) $id,
            'payload' => $payload,
        );
        $result = null;
        if ( function_exists( 'apply_filters' ) ) {
            $result = apply_filters( 'tzfd_suite_owner_request_v1', null, $envelope );
            $result = apply_filters( 'titan_zero_suite_owner_request_v1', $result, $envelope ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy alias: fires the pre-rename hook name for one release
        }
        if ( ! is_array( $result ) || ! isset( $result['ok'] ) || true !== $result['ok'] ) {
            return self::failure( is_array( $result ) && isset( $result['error'] ) && is_string( $result['error'] )
                ? $result['error'] : 'owner_unavailable' );
        }
        // Never trust an acknowledgement attributed to a different scope/owner.
        if ( ! isset( $result['owner'], $result['company_id'] ) || $owner !== $result['owner'] ||
             (string) (int) $id !== (string) $result['company_id'] ) {
            return self::failure( 'owner_scope_mismatch' );
        }
        return array( 'ok' => true, 'owner' => $owner, 'company_id' => (int) $id,
            'data' => isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array() );
    }
    private static function failure( $error ) {
        return array( 'ok' => false, 'error' => $error );
    }
}
