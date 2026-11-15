<?php
/** Strict allowlisted input for legacy calendar creation, rename, delete and clone. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Form_List_Input {
    public static function validate( $request ) {
        if ( ! is_array( $request ) ) { return new WP_Error( 'tzfd_list_bad_request', __( 'Invalid request.', 'titan-zero-trades-front-desk' ) ); }
        $operation = '';
        foreach ( array( 'a', 'u', 'd', 'c' ) as $key ) {
            if ( ! array_key_exists( $key, $request ) ) { continue; }
            // A mutation is never an implicit read-only operation, including empty values.
            if ( '' !== $operation ) { return new WP_Error( 'tzfd_list_conflicting_actions', __( 'Conflicting actions.', 'titan-zero-trades-front-desk' ) ); }
            $operation = $key;
        }
        $id = 0;
        $name = '';
        if ( '' !== $operation ) {
            if ( ! isset( $request['anonce'] ) || ! is_string( $request['anonce'] ) || '' === $request['anonce'] ) {
                return new WP_Error( 'tzfd_list_missing_nonce', __( 'Missing nonce.', 'titan-zero-trades-front-desk' ) );
            }
            if ( 'a' === $operation ) {
                if ( ! is_string( $request['a'] ) || '1' !== $request['a'] ) {
                    return new WP_Error( 'tzfd_list_bad_action', __( 'Invalid creation flag.', 'titan-zero-trades-front-desk' ) );
                }
            } else {
                $id = TZFD_Admin_Request_Ids::positive( $request[ $operation ] );
                if ( null === $id ) { return new WP_Error( 'tzfd_list_bad_id', __( 'Invalid calendar ID.', 'titan-zero-trades-front-desk' ) ); }
            }
            if ( in_array( $operation, array( 'a', 'u' ), true ) ) {
                if ( ! isset( $request['name'] ) || ! is_string( $request['name'] ) || strlen( $request['name'] ) > 512 ) {
                    return new WP_Error( 'tzfd_list_bad_name', __( 'Invalid calendar name.', 'titan-zero-trades-front-desk' ) );
                }
                $name = trim( sanitize_text_field( wp_unslash( $request['name'] ) ) );
                if ( '' === $name || strlen( $name ) > 200 ) {
                    return new WP_Error( 'tzfd_list_bad_name', __( 'Invalid calendar name.', 'titan-zero-trades-front-desk' ) );
                }
            }
        }
        return array( 'operation' => $operation, 'id' => $id, 'name' => $name );
    }
}
