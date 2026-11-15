<?php
/** Pure write-result checks for standalone calendar administration. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Calendar_Write_Result {
    public static function inserted( $result, $id, $error = '' ) {
        return 1 === $result && is_numeric( $id ) && (int) $id > 0 && '' === (string) $error;
    }
    public static function updated( $result, $stored_name, $expected_name, $error = '' ) {
        // A zero-row UPDATE may be a legitimate no-op, but only when read-back confirms the row.
        return ( 0 === $result || 1 === $result ) && '' === (string) $error &&
            is_string( $stored_name ) && is_string( $expected_name ) && hash_equals( $expected_name, $stored_name );
    }
    public static function deleted( $result, $error = '' ) {
        return 1 === $result && '' === (string) $error;
    }
}
