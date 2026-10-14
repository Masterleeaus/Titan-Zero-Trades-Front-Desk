<?php
/**
 * Lists the add-on files to load. Add-ons live in group folders under addons/. Each group is read in
 * the order below. "core" comes first because it holds the base class the other add-ons extend.
 * Folders that are not listed here (for example packs, which holds data files) are never loaded.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Addon_Loader {

    const GROUPS = array( 'core', 'bookings', 'messaging', 'quotes', 'assistant' );

    /**
     * Full paths of the PHP files to load from $addons_dir, group by group, names sorted within each group.
     * Only files longer than ".php" with a .php extension are returned. Hidden entries are skipped.
     *
     * @param string $addons_dir  The addons folder, without a trailing slash.
     * @return string[]
     */
    public static function files( $addons_dir ) {
        $files = array();
        foreach ( self::GROUPS as $group ) {
            $folder = rtrim( (string) $addons_dir, '/\\' ) . '/' . $group;
            if ( ! is_dir( $folder ) ) {
                continue;
            }
            $names = scandir( $folder );
            if ( false === $names ) {
                continue;
            }
            foreach ( $names as $name ) {
                if ( '' === $name || '.' === $name[0] || strlen( $name ) <= 3 ) {
                    continue;
                }
                if ( 'php' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
                    continue;
                }
                if ( is_file( $folder . '/' . $name ) ) {
                    $files[] = $folder . '/' . $name;
                }
            }
        }
        return $files;
    }
}
