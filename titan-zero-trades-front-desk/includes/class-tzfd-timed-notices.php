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
     * @param array  $tzfd_params     Pre-insert/process parameters (itemnumber, formid, apps).
     * @param string $tzfd_key        Add-on key, used for settings lookup.
     * @param string $hook       Cron hook to fire.
     * @param string $anchor     'start' or 'end' of the appointment.
     * @param int    $direction  -1 for before the anchor, +1 for after it.
     * @param array  $defaults   Settings defaults (must include 'hours').
     * @return int Number of events scheduled.
     */
    public static function schedule_booking( array $tzfd_params, $tzfd_key, $hook, $anchor, $direction, array $defaults ) {
        if ( defined( 'TZFD_HOUR_BLOCK_TIMES_PROCESS' ) ) { return 0; }
        $booking_id = isset( $tzfd_params['itemnumber'] ) ? (int) $tzfd_params['itemnumber'] : 0;
        $formid     = isset( $tzfd_params['formid'] ) ? (int) $tzfd_params['formid'] : 0;
        if ( $booking_id < 1 || ! isset( $tzfd_params['apps'] ) || ! is_array( $tzfd_params['apps'] ) ) { return 0; }

        $settings = TZFD_Addon_Support::form_settings( $tzfd_key, $formid, $defaults );
        $hours    = (int) $settings['hours'];
        if ( $hours <= 0 ) { return 0; }

        $tz        = wp_timezone();
        $now       = time();
        $scheduled = 0;
        foreach ( array_values( $tzfd_params['apps'] ) as $index => $tzfd_app ) {
            if ( ! is_array( $tzfd_app ) ) { continue; }
            $point = TZFD_Addon_Support::slot_boundary( isset( $tzfd_app['date'] ) ? $tzfd_app['date'] : '', isset( $tzfd_app['slot'] ) ? $tzfd_app['slot'] : '', $anchor, $tz );
            if ( false === $point ) { continue; }
            $when = $point->getTimestamp() + (int) $direction * $hours * HOUR_IN_SECONDS;
            if ( $when <= $now + 60 ) { continue; } // Never send a notice whose moment has already passed.
            if ( wp_schedule_single_event( $when, $hook, array( $booking_id, $index ) ) ) {
                $scheduled++;
            }
        }
        return $scheduled;
    }

    /**
     * Send the notice for one appointment if it is still due.
     *
     * @param string $tzfd_key             Add-on key.
     * @param int    $booking_id      Booking row ID.
     * @param int    $index           Appointment index within the booking.
     * @param array  $allowed_status  Statuses that may receive this notice ('' = Approved).
     * @param array  $defaults        Settings defaults including subject and body templates.
     * @return bool True when an email was handed to wp_mail.
     */
    public static function deliver( $tzfd_key, $booking_id, $index, array $allowed_status, array $defaults ) {
        global $wpdb;
        $booking_id = (int) $booking_id;
        $index      = (int) $index;
        if ( $booking_id < 1 || $index < 0 || ! is_object( $wpdb ) ) { return false; }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, formid, notifyto, posted_data FROM {$wpdb->prefix}tzfd_messages WHERE id=%d",
            $booking_id
        ) );
        if ( ! is_object( $row ) ) { return false; }

        $settings = TZFD_Addon_Support::form_settings( $tzfd_key, (int) $row->formid, $defaults );
        if ( (int) $settings['hours'] <= 0 ) { return false; }

        $tzfd_data = TZFD_Stored_Data::decode_booking( $row->posted_data );
        if ( ! is_array( $tzfd_data ) || ! isset( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) || ! isset( $tzfd_data['apps'][ $index ] ) ) { return false; }
        $tzfd_app = $tzfd_data['apps'][ $index ];

        if ( ! in_array( TZFD_Addon_Support::appointment_status( $tzfd_app ), $allowed_status, true ) ) { return false; }

        $email = sanitize_email( (string) $row->notifyto );
        if ( '' === $email || 'BLOCKED@BY.ADMIN' === strtoupper( $email ) ) { return false; }

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
