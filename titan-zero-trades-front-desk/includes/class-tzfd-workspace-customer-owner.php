<?php
/** Validate Workspace customer ownership before exposing CRM-linked records. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'TZFD_Workspace_Customer_Owner', false ) ) {
final class TZFD_Workspace_Customer_Owner {
    /** A customer without a declared company is not tenant-authorized. */
    public static function matches( $customer, $company_id ) {
        if ( ! is_array( $customer ) && ! is_object( $customer ) ) { return false; }
        if ( ! is_int( $company_id ) || $company_id < 1 ) { return false; }
        $raw = is_array( $customer ) ? ( $customer['company_id'] ?? null ) : ( $customer->company_id ?? null );
        if ( ! is_int( $raw ) && ! is_string( $raw ) ) { return false; }
        if ( 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', (string) $raw ) ) { return false; }
        return filter_var( (string) $raw, FILTER_VALIDATE_INT ) === $company_id;
    }
}
}
