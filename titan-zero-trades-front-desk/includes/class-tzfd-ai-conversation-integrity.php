<?php
/** Bounded, typed validation for AI conversation state before provider/tool execution. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_AI_Conversation_Integrity {
    private static function is_list( $rows ) {
        if ( ! is_array( $rows ) ) { return false; }
        $i = 0;
        foreach ( $rows as $key => $value ) { if ( $key !== $i++ ) { return false; } }
        return true;
    }
    public static function messages( $encoded ) {
        if ( ! is_string( $encoded ) || strlen( $encoded ) > 524288 ) { return null; }
        $rows = json_decode( $encoded, true, 32 );
        if ( JSON_ERROR_NONE !== json_last_error() || ! self::is_list( $rows ) || count( $rows ) > 80 ) { return null; }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || count( $row ) > 12 ||
                ! is_string( $row['role'] ?? null ) || ! in_array( $row['role'], array( 'user', 'assistant', 'tool' ), true ) ||
                ! is_string( $row['content'] ?? null ) || strlen( $row['content'] ) > 16384 ) { return null; }
            if ( isset( $row['tool_calls'] ) ) {
                if ( ! is_array( $row['tool_calls'] ) || count( $row['tool_calls'] ) > 12 ) { return null; }
                foreach ( $row['tool_calls'] as $call ) {
                    if ( ! is_array( $call ) || ! is_string( $call['name'] ?? null ) ||
                        ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]{0,79}$/D', $call['name'] ) ||
                        ! is_array( $call['args'] ?? null ) ) { return null; }
                }
            }
        }
        return $rows;
    }
    public static function draft( $encoded ) {
        if ( ! is_string( $encoded ) || strlen( $encoded ) > 65536 ) { return null; }
        $draft = json_decode( $encoded, true, 12 );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $draft ) ||
             ( self::is_list( $draft ) && $draft !== array() ) || count( $draft ) > 80 ) { return null; }
        return self::valid_values( $draft, 0 ) ? $draft : null;
    }
    private static function valid_values( $values, $depth ) {
        if ( $depth > 5 || ! is_array( $values ) || count( $values ) > 80 ) { return false; }
        foreach ( $values as $key => $value ) {
            if ( is_string( $key ) && strlen( $key ) > 100 ) { return false; }
            if ( is_array( $value ) ) {
                if ( ! self::valid_values( $value, $depth + 1 ) ) { return false; }
            } elseif ( ! is_null( $value ) && ! is_bool( $value ) && ! is_int( $value ) && ! is_float( $value ) &&
                       ( ! is_string( $value ) || strlen( $value ) > 8192 ) ) { return false; }
        }
        return true;
    }
}
