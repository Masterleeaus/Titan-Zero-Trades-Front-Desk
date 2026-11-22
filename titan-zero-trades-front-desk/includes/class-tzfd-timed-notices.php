<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-addon-support.php';
require_once __DIR__ . '/class-tzfd-stored-data.php';

/**
 * Schedules and sends one email per appointment at a fixed offset from the
 * appointment start (reminders) or end (follow-ups). Used by the reminder and
 * follow-up add-ons. GPL-2.0-only.
 *
 * Events are scheduled when a booking is saved. Each event re-checks the
 * booking when it fires, so cancelled or still-pending appointments are skipped.
 */
final class TZFD_Timed_Notices {

    /**
     * Schedule one event per appointment in a freshly saved booking.
     *
     * @param array  $tzfd_local_params     Pre-insert/process parameters (itemnumber, formid, apps).
     * @param string $tzfd_key        Add-on key, used for settings lookup.
     * @param string $hook       Cron hook to fire.
     * @param string $anchor     'start' or 'end' of the appointment.
     * @param int    $direction  -1 for before the anchor, +1 for after it.
     * @param array  $defaults   Settings defaults (must include 'hours').
     * @param int    $company_id 0 uses the request's company. Pass the booking's company when no request context exists.
     * @return int Number of events scheduled. Event args: booking id, appointment index, company id.
     */
    public static function schedule_booking( array $tzfd_local_params, $tzfd_key, $hook, $anchor, $direction, array $defaults, $company_id = 0 ) {
        if ( defined( 'TZFD_HOUR_BLOCK_TIMES_PROCESS' ) ) { return 0; }
        $booking_id = isset( $tzfd_local_params['itemnumber'] ) ? (int) $tzfd_local_params['itemnumber'] : 0;
        $formid     = isset( $tzfd_local_params['formid'] ) ? (int) $tzfd_local_params['formid'] : 0;
        if ( $booking_id < 1 || ! isset( $tzfd_local_params['apps'] ) || ! is_array( $tzfd_local_params['apps'] ) ) { return 0; }
        // Cron runs later with no user or company, so the company is written into each event now.
        $company = (int) $company_id > 0 ? (int) $company_id : ( class_exists( 'TZFD_Scoped_Store' ) ? TZFD_Scoped_Store::company_id() : 0 );
        if ( $company < 1 || $formid < 1 ||
             TZFD_Scoped_Store::company_for_form( $formid ) !== $company ) { return 0; }

        // A caller that passes the company has no request context (for example a customer's reschedule), so read that company's scoped settings.
        $settings = (int) $company_id > 0
            ? self::settings_for_company( $tzfd_key, $formid, $company, $defaults )
            : TZFD_Addon_Support::form_settings( $tzfd_key, $formid, $defaults );
        $hours    = (int) $settings['hours'];
        if ( $hours <= 0 ) { return 0; }

        $tz        = wp_timezone();
        $now       = time();
        $scheduled = 0;
        foreach ( array_values( $tzfd_local_params['apps'] ) as $index => $tzfd_app ) {
            if ( ! is_array( $tzfd_app ) ) { continue; }
            $point = TZFD_Addon_Support::slot_boundary( isset( $tzfd_app['date'] ) ? $tzfd_app['date'] : '', isset( $tzfd_app['slot'] ) ? $tzfd_app['slot'] : '', $anchor, $tz );
            if ( false === $point ) { continue; }
            $when = $point->getTimestamp() + (int) $direction * $hours * HOUR_IN_SECONDS;
            if ( $when <= $now + 60 ) { continue; } // Never send a notice whose moment has already passed.
            if ( wp_schedule_single_event( $when, $hook, array( $booking_id, $index, $company ) ) ) {
                $scheduled++;
            }
        }
        return $scheduled;
    }

    /** Form settings merged over site settings for one verified company, without relying on request context. */
    private static function settings_for_company( $tzfd_key, $formid, $company_id, array $defaults ) {
        // Same legacy fallback as TZFD_Addon_Support::form_settings_for_job(), without its logged-in guard:
        // status changes from an admin request also rebuild the queue, and must not fall back to defaults.
        $legacy        = TZFD_Addon_Support::OPTION_PREFIX . sanitize_key( $tzfd_key );
        $form_name     = $legacy . '_form_' . (int) $formid;
        $global_name   = $legacy . '_global';
        $form_option   = TZFD_Scoped_Store::option_name( $form_name, (int) $company_id );
        $global_option = TZFD_Scoped_Store::option_name( $global_name, (int) $company_id );
        $form_stored   = get_option( $form_option, null );
        $global_stored = get_option( $global_option, null );
        if ( null === $form_stored ) {
            $form_stored = get_option( $form_name, array() );
            if ( is_array( $form_stored ) && $form_stored ) { add_option( $form_option, $form_stored, '', false ); }
        }
        if ( null === $global_stored && (int) $company_id === (int) TZFD_Scoped_Store::legacy_owner() ) {
            $global_stored = get_option( $global_name, array() );
            if ( is_array( $global_stored ) && $global_stored ) { add_option( $global_option, $global_stored, '', false ); }
        }
        return TZFD_Addon_Support::merge_stored( $form_stored, TZFD_Addon_Support::merge_stored( $global_stored, $defaults ) );
    }

    /**
     * Remove every queued event for one booking on a hook. Used before a booking's time or status changes,
     * so an event for the old slot cannot fire. Matches on the booking id and company in the event args.
     *
     * @return int Number of events removed.
     */
    public static function clear_booking( $hook, $booking_id, $company_id ) {
        $booking_id = (int) $booking_id;
        $company_id = (int) $company_id;
        if ( $booking_id < 1 || $company_id < 1 || ! function_exists( '_get_cron_array' ) ) { return 0; }
        $removed = 0;
        foreach ( _get_cron_array() as $timestamp => $hooks ) {
            if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) { continue; }
            foreach ( $hooks[ $hook ] as $event ) {
                $args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
                if ( isset( $args[0], $args[2] ) && (int) $args[0] === $booking_id && (int) $args[2] === $company_id ) {
                    if ( wp_unschedule_event( (int) $timestamp, $hook, $args ) ) { $removed++; }
                }
            }
        }
        return $removed;
    }

    /**
     * Send the notice for one appointment if it is still due.
     *
     * @param string $tzfd_key             Add-on key.
     * @param int    $booking_id      Booking row ID.
     * @param int    $index           Appointment index within the booking.
     * @param array  $allowed_status  Statuses that may receive this notice ('' = Approved).
     * @param array  $defaults        Settings defaults including subject and body templates.
     * @param int    $company_id      Company the booking belongs to, from the cron event args. Required.
     * @return bool True when an email was handed to wp_mail.
     */
    public static function deliver( $tzfd_key, $booking_id, $index, array $allowed_status, array $defaults, $company_id = 0 ) {
        global $wpdb;
        $booking_id = (int) $booking_id;
        $index      = (int) $index;
        $company_id = (int) $company_id;
        if ( $booking_id < 1 || $index < 0 || $company_id < 1 || ! is_object( $wpdb ) ) { return false; }

        // Runs from cron, with no user or company context. The company comes from the event args written at save
        // time, and the read is scoped to it. A job without a company is refused.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, formid, notifyto, posted_data FROM %i WHERE id=%d AND company_id=%d",
            $wpdb->prefix . TZFD_Tables::BOOKINGS,
            $booking_id,
            $company_id
        ) );
        if ( ! is_object( $row ) ) { return false; }

        // The cron request has no interactive Workspace selection. Confirm the
        // authoritative form owner and resolve the saved settings for that owner.
        // Never fall back to a site's or another company's settings.
        if ( ! class_exists( 'TZFD_Scoped_Store' ) ||
             TZFD_Scoped_Store::company_for_form( (int) $row->formid ) !== $company_id ) { return false; }
        $settings = TZFD_Addon_Support::form_settings_for_job( $tzfd_key, (int) $row->formid, $company_id, $defaults );
        if ( ! isset( $settings['hours'] ) || ! is_numeric( $settings['hours'] ) || (int) $settings['hours'] <= 0 ) { return false; }

        $tzfd_data = TZFD_Stored_Data::decode_booking( $row->posted_data );
        if ( ! is_array( $tzfd_data ) || ! isset( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) || ! isset( $tzfd_data['apps'][ $index ] ) || ! is_array( $tzfd_data['apps'][ $index ] ) ) { return false; }
        $tzfd_app = $tzfd_data['apps'][ $index ];

        if ( ! in_array( TZFD_Addon_Support::appointment_status( $tzfd_app ), $allowed_status, true ) ) { return false; }

        if ( ! isset( $row->notifyto ) || ! is_string( $row->notifyto ) ) { return false; }
        $email = sanitize_email( $row->notifyto );
        if ( ! is_email( $email ) || 'BLOCKED@BY.ADMIN' === strtoupper( $email ) ) { return false; }

        $tz    = wp_timezone();
        $start = TZFD_Addon_Support::slot_boundary( isset( $tzfd_app['date'] ) ? $tzfd_app['date'] : '', isset( $tzfd_app['slot'] ) ? $tzfd_app['slot'] : '', 'start', $tz );
        $end   = TZFD_Addon_Support::slot_boundary( isset( $tzfd_app['date'] ) ? $tzfd_app['date'] : '', isset( $tzfd_app['slot'] ) ? $tzfd_app['slot'] : '', 'end', $tz );
        if ( false === $start || false === $end ) { return false; }

        $values = array(
            'booking_id' => $booking_id,
            'formname'   => isset( $tzfd_data['formname'] ) && is_string( $tzfd_data['formname'] ) ? $tzfd_data['formname'] : '',
            'service'    => isset( $tzfd_app['service'] ) && is_string( $tzfd_app['service'] ) ? $tzfd_app['service'] : '',
            'date'       => wp_date( get_option( 'date_format' ), $start->getTimestamp(), $tz ),
            'start'      => wp_date( get_option( 'time_format' ), $start->getTimestamp(), $tz ),
            'end'        => wp_date( get_option( 'time_format' ), $end->getTimestamp(), $tz ),
        );

        $subject = TZFD_Addon_Support::single_line( TZFD_Addon_Support::fill_template( $settings['subject'], $values ) );
        $body    = TZFD_Addon_Support::fill_template( $settings['body'], $values );

        return (bool) wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }
}
