<?php
/** Safe scalar extraction from untrusted WordPress request data. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tzfd_request_scalar_text' ) ) {
    /**
     * Sanitize a request field while rejecting array/object payloads.
     * A blank result is intentionally returned for invalid types, allowing
     * existing handlers to reject a missing nonce/action/identifier.
     *
     * @param mixed $value Untrusted raw request value.
     * @return string
     */
    function tzfd_request_scalar_text( $value ) {
        if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
            return '';
        }
        if ( is_float( $value ) && ! is_finite( $value ) ) { return ''; }
        if ( strlen( (string) $value ) > 1048576 ) { return ''; }
        return sanitize_text_field( wp_unslash( (string) $value ) );
    }
}


if ( ! function_exists( 'tzfd_request_text' ) ) {
    /**
     * Read a single scalar at the HTTP boundary. Authorization remains with the handler.
     * Source is explicit so POST mutations cannot accidentally prefer query-string input.
     * Values are unslashed once, sanitized once, and nested payloads are rejected.
     */
    function tzfd_request_text( $source, $key, $default = '' ) {
        if ( ! is_string( $source ) || ( ! is_string( $key ) && ! is_int( $key ) ) ) { return ''; }
        switch ( $source ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- input helper: copies the caller-selected superglobal; the field is unslashed and sanitized below, and nonce/capability stay with the handler.
            case 'get': $input = $_GET; break;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- input helper: copies the caller-selected superglobal; the field is unslashed and sanitized below, and nonce/capability stay with the handler.
            case 'post': $input = $_POST; break;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- input helper: copies the caller-selected superglobal; the field is unslashed and sanitized below, and nonce/capability stay with the handler.
            case 'request': $input = $_REQUEST; break;
            case 'server': $input = $_SERVER; break;
            case 'cookie': $input = $_COOKIE; break;
            default: return '';
        }
        $value = is_array( $input ) && array_key_exists( $key, $input ) && null !== $input[ $key ] ? $input[ $key ] : $default;
        if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) { return ''; }
        if ( is_float( $value ) && ! is_finite( $value ) ) { return ''; }
        if ( strlen( (string) $value ) > 1048576 ) { return ''; }
        return sanitize_text_field( wp_unslash( (string) $value ) );
    }
}
