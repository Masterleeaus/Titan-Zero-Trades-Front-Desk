<?php
/**
 * Keep committed appointments successful when secondary integrations fail.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Post_Commit_Dispatch {
    /** Report only safe identifiers, never provider response text. */
    private static function report( $booking_id, $operation, $exception ) {
        $allowed = array( 'workspace_attachment', 'booking_created_hooks', 'booking_created_listener' );
        $label = in_array( $operation, $allowed, true ) ? $operation : 'post_commit';
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic log for a failed post-commit integration.
        error_log( sprintf( 'Front Desk: post-commit %s failed for booking %d (%s)', $label, $booking_id, get_class( $exception ) ) );
    }

    public static function run( $callback, $booking_id, $operation ) {
        if ( ! is_callable( $callback ) || ! is_int( $booking_id ) || $booking_id < 1 ) { return false; }
        try {
            $callback();
            return true;
        } catch ( Throwable $exception ) {
            self::report( $booking_id, $operation, $exception );
            return false;
        }
    }

    /**
     * Execute the normal WordPress action, but shield each pre-registered
     * listener independently. This preserves WordPress's action context,
     * priorities, accepted_args and dynamically added callbacks. Snapshot
     * wrapping is temporary and all remaining wrappers are restored even
     * if core dispatch itself fails. A callback newly registered during the
     * dispatch is not individually shielded in this invocation.
     *
     * @param string $hook Action name (restricted to booking-created action).
     * @param array  $arguments Array of action arguments, optionally references.
     * @param int    $booking_id Persisted appointment identifier.
     * @return bool Whether the action ran without a listener failure.
     */
    public static function run_booking_created( $hook, $arguments, $booking_id ) {
        if ( 'tz_fd_process_data' !== $hook || ! is_array( $arguments ) || ! is_int( $booking_id ) || $booking_id < 1 ) {
            return false;
        }
        global $wp_filter;
        $registry = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
        $wrapped = array();
        $all_succeeded = true;

        if ( is_object( $registry ) && isset( $registry->callbacks ) && is_array( $registry->callbacks ) ) {
            foreach ( $registry->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $uid => $entry ) {
                    if ( ! isset( $entry['function'] ) || ! is_callable( $entry['function'] ) ) { continue; }
                    $original = $entry['function'];
                    // WP_Hook's do_action_ref_array supplies the booking params
                    // by reference. Preserve that contract for legacy listeners.
                    $shield = static function ( &$params = null, ...$extra ) use ( $original, $booking_id, &$all_succeeded ) {
                        $args = array( &$params );
                        foreach ( $extra as $value ) { $args[] = $value; }
                        try {
                            return call_user_func_array( $original, $args );
                        } catch ( Throwable $exception ) {
                            $all_succeeded = false;
                            TZFD_Post_Commit_Dispatch::report( $booking_id, 'booking_created_listener', $exception );
                            return null;
                        }
                    };
                    $registry->callbacks[ $priority ][ $uid ]['function'] = $shield;
                    $wrapped[] = array( $priority, $uid, $original, $shield );
                }
            }
        }

        try {
            do_action_ref_array( $hook, $arguments );
        } catch ( Throwable $exception ) {
            $all_succeeded = false;
            self::report( $booking_id, 'booking_created_hooks', $exception );
        } finally {
            if ( is_object( $registry ) && isset( $registry->callbacks ) ) {
                foreach ( $wrapped as $item ) {
                    list( $priority, $uid, $original, $shield ) = $item;
                    // Never resurrect a listener that removed itself.
                    // Also do not override a listener replaced during dispatch.
                    if ( isset( $registry->callbacks[ $priority ][ $uid ]['function'] ) &&
                         $registry->callbacks[ $priority ][ $uid ]['function'] === $shield ) {
                        $registry->callbacks[ $priority ][ $uid ]['function'] = $original;
                    }
                }
            }
        }
        return $all_succeeded;
    }
}
