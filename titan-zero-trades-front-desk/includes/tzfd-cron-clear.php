<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tzfd_unschedule_all_events' ) ) {
    /**
     * Removes every scheduled event for a hook, whatever its arguments.
     * wp_clear_scheduled_hook() only matches events with no arguments, so it
     * leaves behind events such as per-booking webhook retries.
     */
    function tzfd_unschedule_all_events( $hook ) {
        if ( function_exists( 'wp_unschedule_hook' ) ) { // WordPress 5.7+
            wp_unschedule_hook( $hook );
            return;
        }
        foreach ( (array) _get_cron_array() as $timestamp => $tzfd_events ) {
            if ( empty( $tzfd_events[ $hook ] ) ) { continue; }
            foreach ( $tzfd_events[ $hook ] as $event ) {
                wp_unschedule_event( $timestamp, $hook, $event['args'] );
            }
        }
    }
}
