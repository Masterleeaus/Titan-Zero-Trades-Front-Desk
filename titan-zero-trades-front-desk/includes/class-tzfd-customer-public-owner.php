<?php
/** Resolve tenant ownership for authenticated customer capability links. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * This MUST be called only AFTER the booking-specific HMAC and version/expiry
 * checks have succeeded. Public admin-post_nopriv requests usually do not
 * carry a logged-in Workspace company context. Form ownership is authoritative;
 * a supplied active company context is an additional restriction, never a
 * prerequisite to using a valid customer capability link.
 */
final class TZFD_Customer_Public_Owner {
    public static function resolve( $form_id ) {
        $unavailable = array( 'status' => 'unavailable', 'company' => 0 );
        $mismatch = array( 'status' => 'mismatch', 'company' => 0 );
        $form_id = filter_var( $form_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( false === $form_id || ! class_exists( 'TZFD_Scoped_Store' ) ||
             ! is_callable( array( 'TZFD_Scoped_Store', 'company_for_form' ) ) ||
             ! is_callable( array( 'TZFD_Scoped_Store', 'company_id' ) ) ) {
            return $mismatch;
        }
        try {
            $owner = TZFD_Scoped_Store::company_for_form( (int) $form_id );
            if ( ! is_numeric( $owner ) || (int) $owner < 1 ) { return $unavailable; }
            $owner = (int) $owner;
            $context = (int) TZFD_Scoped_Store::company_id();
            if ( $context < 0 ) { return $mismatch; }
            if ( $context > 0 && $context !== $owner ) { return $mismatch; }
            return array( 'status' => 'matched', 'company' => $owner );
        } catch ( Throwable $error ) {
            return $unavailable;
        }
    }
}
