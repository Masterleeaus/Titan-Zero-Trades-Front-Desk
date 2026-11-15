<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** In single-business standalone mode, company 1 is a site-local immutable scope. */
final class TZFD_Local_Business {
    public static function id() { return 1; }
    public static function default_id() { return 1; }
    public static function name() { return (string) get_option( 'tzfd_business_name', get_bloginfo( 'name' ) ); }
}
