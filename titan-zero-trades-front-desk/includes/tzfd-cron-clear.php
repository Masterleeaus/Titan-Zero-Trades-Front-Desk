<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tz_fd_unschedule_all_events' ) ) {
    /**
     * Removes every scheduled event for a hook, whatever its arguments.
     * wp_clear_scheduled_hook() only matches events with no arguments, so it
     * leaves behind events such as per-booking webhook retries.
     */
    function tz_fd_unschedule_all_events( $hook ) {
        if ( function_exists( 'wp_unschedule_hook' ) ) { // WordPress 5.7+
            wp_unschedule_hook( $hook );
            return;
        }
        foreach ( (array) _get_cron_array() as $timestamp => $tz_events ) {
            if ( empty( $tz_events[ $hook ] ) ) { continue; }
            foreach ( $tz_events[ $hook ] as $event ) {
                wp_unschedule_event( $timestamp, $hook, $event['args'] );
            }
        }
    }
}
