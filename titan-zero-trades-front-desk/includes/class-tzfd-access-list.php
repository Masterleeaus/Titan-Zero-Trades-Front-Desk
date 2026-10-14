<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safe decoder for legacy per-calendar WordPress user access lists.
 */
class TZFD_Access_List {
    /**
     * Decode the legacy serialized list without instantiating stored objects.
     *
     * @param mixed $serialized Serialized access-list value.
     * @return int[] Unique positive WordPress user IDs.
     */
    public static function decode( $serialized ) {
        if ( ! is_string( $serialized ) || '' === $serialized ) {
            return array();
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Legacy stored value may be malformed; allowed_classes=false prevents object instantiation and a bad payload returns false.
        $decoded = @unserialize( $serialized, array( 'allowed_classes' => false ) );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $ids = array();
        foreach ( $decoded as $candidate ) {
            if ( ! is_int( $candidate ) && ! is_string( $candidate ) ) {
                continue;
            }

            $id = filter_var(
                $candidate,
                FILTER_VALIDATE_INT,
                array( 'options' => array( 'min_range' => 1 ) )
            );
            if ( false === $id ) {
                continue;
            }

            $ids[ $id ] = $id;
        }

        return array_values( $ids );
    }
}
