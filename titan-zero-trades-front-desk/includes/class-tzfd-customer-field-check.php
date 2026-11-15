<?php
/**
 * Server-side checks for customer fields on a public booking. The browser validator is not a control, so a direct
 * POST must pass the same rules. Pure PHP with no WordPress calls, so it can be tested without WordPress.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'TZFD_Customer_Field_Check' ) ) {
    final class TZFD_Customer_Field_Check {
        const MAX_NAME_LENGTH  = 100;
        const MAX_EMAIL_LENGTH = 254;
        const MIN_PHONE_DIGITS = 6;
        const MAX_PHONE_DIGITS = 15;

        /** True when the form item is marked required (the same truthy test the form builder uses). */
        public static function is_required( $item ) {
            if ( ! is_object( $item ) || ! property_exists( $item, 'required' ) ) { return false; }
            return true === $item->required || 1 === $item->required || 'true' === $item->required || '1' === $item->required;
        }

        /**
         * Returns the first error message for the non-appointment fields, or '' when all pass.
         * $fields is a list of arrays: ftype, name, title, required (bool), value (string, as the customer typed it).
         */
        public static function first_error( array $fields ) {
            foreach ( $fields as $field ) {
                $value = isset( $field['value'] ) && is_string( $field['value'] ) ? trim( $field['value'] ) : '';
                $label = isset( $field['title'] ) && '' !== trim( (string) $field['title'] ) ? trim( (string) $field['title'] ) : 'this field';
                $type  = isset( $field['ftype'] ) ? (string) $field['ftype'] : '';

                if ( '' === $value ) {
                    if ( ! empty( $field['required'] ) ) {
                        return 'Please enter ' . $label . '.';
                    }
                    continue;
                }

                if ( 'femail' === $type ) {
                    if ( strlen( $value ) > self::MAX_EMAIL_LENGTH || ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
                        return 'Please enter a valid email address in ' . $label . '.';
                    }
                    continue;
                }

                if ( 'fPhone' === $type ) {
                    if ( ! self::valid_phone( $value ) ) {
                        return 'Please enter a valid phone number in ' . $label . '.';
                    }
                    continue;
                }

                if ( self::looks_like_name( $field ) ) {
                    if ( mb_strlen( $value, 'UTF-8' ) > self::MAX_NAME_LENGTH || ! preg_match( '/\p{L}/u', $value ) ||
                         preg_match( '/[<>]|:\/\//', $value ) ) {
                        return 'Please enter a valid name in ' . $label . '.';
                    }
                }
            }
            return '';
        }

        /** Digits, spaces, brackets, dots, dashes and an optional leading plus, with a sensible digit count. */
        public static function valid_phone( $value ) {
            if ( ! is_string( $value ) || ! preg_match( '/^\+?[0-9 ().\-]+$/D', $value ) ) { return false; }
            $digits = preg_replace( '/\D/', '', $value );
            $count  = strlen( $digits );
            return $count >= self::MIN_PHONE_DIGITS && $count <= self::MAX_PHONE_DIGITS;
        }

        private static function looks_like_name( $field ) {
            $type = isset( $field['ftype'] ) ? (string) $field['ftype'] : '';
            if ( 'ftext' !== $type ) { return false; }
            $name  = isset( $field['name'] ) ? (string) $field['name'] : '';
            $title = isset( $field['title'] ) ? (string) $field['title'] : '';
            return (bool) preg_match( '/name/i', $name . ' ' . $title );
        }
    }
}
