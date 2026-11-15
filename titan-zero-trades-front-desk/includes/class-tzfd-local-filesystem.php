<?php
/** Local private-file operations through the WordPress filesystem API. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Local_Filesystem {
    private static $filesystem;

    /** These paths are server-local; an FTP filesystem must not receive private exports. */
    private static function filesystem() {
        if ( ! self::$filesystem ) {
            if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            }
            if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            }
            self::$filesystem = new WP_Filesystem_Direct( null );
        }
        return self::$filesystem;
    }

    public static function make_private( $path ) {
        if ( ! is_string( $path ) || '' === $path || is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }
        return self::filesystem()->chmod( $path, 0600 );
    }

    public static function read( $path ) {
        if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) { return false; }
        return self::filesystem()->get_contents( $path );
    }
}
