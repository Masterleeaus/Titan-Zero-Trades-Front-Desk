<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Legacy_Compat {
    public static function substr( $value, $start, $length = null ) {
        $value = (string) $value;
        if ( function_exists( 'mb_substr' ) ) { return null === $length ? mb_substr( $value, $start ) : mb_substr( $value, $start, $length ); }
        return null === $length ? substr( $value, $start ) : substr( $value, $start, $length );
    }
}
