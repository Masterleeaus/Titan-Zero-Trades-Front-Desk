<?php
/** Prevents booking forms from recording empty/invalid appointment selections. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Submission_Guard {
    public static function selection_allowed( $fields, $apps ) {
        if ( ! is_array( $fields ) || ! is_array( $apps ) ) { return false; }
        $has_booking_field = false;
        foreach ( $fields as $field ) {
            if ( ! is_object( $field ) ) { return false; }
            if ( isset( $field->ftype ) && 'fapp' === $field->ftype ) { $has_booking_field = true; }
        }
        if ( ! $has_booking_field ) { return true; } // Existing non-booking forms.
        if ( empty( $apps ) ) { return false; }
        foreach ( $apps as $app ) {
            if ( ! is_array( $app ) || ! isset( $app['date'], $app['slot'], $app['service'] ) ) { return false; }
            if ( ! is_string( $app['date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $app['date'] ) ) { return false; }
            $date = explode( '-', $app['date'] );
            if ( ! checkdate( (int) $date[1], (int) $date[2], (int) $date[0] ) ) { return false; }
            if ( ! is_string( $app['slot'] ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d\/([01]\d|2[0-3]):[0-5]\d$/D', $app['slot'] ) ) { return false; }
            if ( strcmp( substr( $app['slot'], 0, 5 ), substr( $app['slot'], 6, 5 ) ) >= 0 ) { return false; }
            if ( ! is_scalar( $app['service'] ) || '' === (string) $app['service'] ) { return false; }
        }
        return true;
    }
}
