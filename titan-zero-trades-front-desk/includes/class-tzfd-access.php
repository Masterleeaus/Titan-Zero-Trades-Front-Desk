<?php
/**
 * Front Desk access. One capability, granted to administrators and to the two Titan Zero
 * WordPress roles that Workspace adds: titan_zero_worker and titan_zero_manager.
 * Editors and other WordPress roles do not receive it. AI levels play no part here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TZFD_Access {
    const CAP          = 'tz_fd_access';
    const ROLE_WORKER  = 'titan_zero_worker';
    const ROLE_MANAGER = 'titan_zero_manager';

    /** Registers the grant. Called once from tz_fd_boot(), after Workspace is confirmed. */
    public static function register() {
        add_filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 4 );
    }

    /**
     * @param array  $allcaps user's capabilities
     * @param array  $caps    capabilities being checked
     * @param array  $args    check arguments
     * @param object $user    user being checked
     * @return array
     */
    public static function grant( $allcaps, $caps, $args, $user ) {
        if ( ! is_array( $caps ) || ! in_array( self::CAP, $caps, true ) ) {
            return $allcaps;
        }
        if ( ! empty( $allcaps['manage_options'] ) ) {
            $allcaps[ self::CAP ] = true;
            return $allcaps;
        }
        $roles = ( is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) ) ? $user->roles : array();
        if ( array_intersect( array( self::ROLE_WORKER, self::ROLE_MANAGER ), $roles ) ) {
            $allcaps[ self::CAP ] = true;
        }
        return $allcaps;
    }
}
