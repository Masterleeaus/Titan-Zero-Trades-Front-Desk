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
     * Wrap each listener currently registered on a hook so that one failing
     * listener cannot stop the others. Returns the wrappers so they can be restored.
     *
     * @param mixed $registry WP_Hook object for the hook, or null when nothing is registered.
     * @param int   $booking_id Persisted appointment identifier.
     * @param bool  $all_succeeded Set to false when a listener throws.
     * @return array<int, array{0:int,1:mixed,2:callable,3:callable}>
     */
    private static function shield_listeners( $registry, $booking_id, &$all_succeeded ) {
        $wrapped = array();
        if ( ! is_object( $registry ) || ! isset( $registry->callbacks ) || ! is_array( $registry->callbacks ) ) {
            return $wrapped;
        }
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
        return $wrapped;
    }

    /**
     * Put back the original listeners. Never resurrect a listener that removed
     * itself, and do not override a listener replaced during dispatch.
     */
    private static function restore_listeners( $registry, $wrapped ) {
        if ( ! is_object( $registry ) || ! isset( $registry->callbacks ) ) { return; }
        foreach ( $wrapped as $item ) {
            list( $priority, $uid, $original, $shield ) = $item;
            if ( isset( $registry->callbacks[ $priority ][ $uid ]['function'] ) &&
                 $registry->callbacks[ $priority ][ $uid ]['function'] === $shield ) {
                $registry->callbacks[ $priority ][ $uid ]['function'] = $original;
            }
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
     * The booking-created action is tzfd_process_data. Its pre-rename name,
     * tz_fd_process_data, fires right after it with the same arguments (legacy
     * alias, kept for one release as stated in the 3.1.0 changelog entry), and
     * the same per-listener shielding applies to listeners on that name.
     *
     * Hook names are literal at each do_action call so Plugin Check can read them.
     *
     * @param string $hook Action name; must be 'tzfd_process_data'.
     * @param array  $arguments Array of action arguments, optionally references.
     * @param int    $booking_id Persisted appointment identifier.
     * @return bool Whether the actions ran without a listener failure.
     */
    public static function run_booking_created( $hook, $arguments, $booking_id ) {
        if ( 'tzfd_process_data' !== $hook || ! is_array( $arguments ) || ! is_int( $booking_id ) || $booking_id < 1 ) {
            return false;
        }
        global $wp_filter;
        $all_succeeded = true;

        $registry = isset( $wp_filter['tzfd_process_data'] ) ? $wp_filter['tzfd_process_data'] : null;
        $wrapped = self::shield_listeners( $registry, $booking_id, $all_succeeded );
        try {
            do_action_ref_array( 'tzfd_process_data', $arguments );
        } catch ( Throwable $exception ) {
            $all_succeeded = false;
            self::report( $booking_id, 'booking_created_hooks', $exception );
        } finally {
            self::restore_listeners( $registry, $wrapped );
        }

        $legacy_registry = isset( $wp_filter['tz_fd_process_data'] ) ? $wp_filter['tz_fd_process_data'] : null;
        $legacy_wrapped = self::shield_listeners( $legacy_registry, $booking_id, $all_succeeded );
        try {
            do_action_ref_array( 'tz_fd_process_data', $arguments ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy alias: fires the pre-rename hook name for one release
        } catch ( Throwable $exception ) {
            $all_succeeded = false;
            self::report( $booking_id, 'booking_created_hooks', $exception );
        } finally {
            self::restore_listeners( $legacy_registry, $legacy_wrapped );
        }
        return $all_succeeded;
    }
}
