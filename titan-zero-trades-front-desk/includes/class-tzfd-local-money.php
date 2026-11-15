<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Local_Money {
    public static function currency_code() {
        $currency = strtoupper( (string) get_option( 'tzfd_currency', 'AUD' ) );
        return preg_match( '/^[A-Z]{3}$/D', $currency ) ? $currency : 'AUD';
    }
    public static function parse_amount( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', (string) $value ) ) { return null; }
        return (float) $value;
    }
    public static function format( $amount ) {
        if ( ! is_numeric( $amount ) ) { return ''; }
        return self::currency_code() . ' ' . number_format_i18n( (float) $amount, 2 );
    }
}
