<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once dirname( __DIR__ ) . '/addons/core/base.addon.php';
require_once __DIR__ . '/class-tzfd-addon-records.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';
require_once __DIR__ . '/class-tzfd-sync-queue.php';
require_once __DIR__ . '/class-tzfd-bookings.php';

/**
 * Shared flow for the CRM and calendar add-ons (Mailchimp, Salesforce, Zoom, Google Calendar).
 *
 * When a booking is made and the add-on is enabled, the add-on queues one sync job for it. Jobs that
 * carry contact details need the customer's consent first (the consent field must be a yes value).
 * Only the fields the admin maps are sent. The vendor call is made by the adapter tzfd_{SLUG}_sync,
 * through TZFD_Sync_Queue. Nothing leaves the site while no adapter is connected.
 */
abstract class TZFD_Sync_Addon extends TZFD_BaseAddon
{
    const SLUG         = '';
    const CRON         = 'titan_zero_front_desk_sync_run';
    const MAX_MAPPINGS = 20;

    public static function defaults()
    {
        return array(
            'mode'          => 'test',
            'api_key'       => '',
            'enabled'       => '0',
            'consent_field' => 'consent',
            'email_field'   => 'email',
            'field_map'     => '',
        );
    }

    /** Extra settings rows for one add-on, in render_table() format. */
    protected function extra_specs() { return array(); }

    /** Whether jobs for this add-on wait for the consent field. */
    protected function needs_consent() { return true; }

    /** Whether jobs for this add-on need the customer's email address. */
    protected function needs_email() { return true; }

    protected function sync_boot()
    {
        if ( ! $this->addon_is_active() ) { return; }
        TZFD_Addon_Records::ensure_schema();
        add_action( 'tzfd_process_data', array( $this, 'queue_booking' ), 45, 1 );
        add_action( self::CRON, array( $this, 'run_queue' ) );
        if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + 600, 'hourly', self::CRON ); }
    }

    public function run_queue()
    {
        TZFD_Sync_Queue::process( static::SLUG );
    }

    public function settings()
    {
        return TZFD_Extension_Core::global_settings( static::SLUG, static::defaults(), array( 'api_key' ) );
    }

    /** Queues one sync job for a new booking, when the add-on is enabled and its conditions are met. */
    public function queue_booking( $tzfd_params )
    {
        if ( ! $this->addon_is_active() || ! is_array( $tzfd_params ) ) { return; }
        $settings = $this->settings();
        if ( '1' !== (string) $settings['enabled'] ) { return; }
        $booking_id = (int) ( $tzfd_params['itemnumber'] ?? 0 );
        if ( $booking_id <= 0 ) { return; }
        if ( $this->needs_consent() && ! self::is_consent_given( $tzfd_params, $settings['consent_field'] ) ) { return; }
        $email = sanitize_email( self::field( $tzfd_params, $settings['email_field'] ) );
        if ( $this->needs_email() && '' === $email ) { return; }
        $payload = $this->build_payload( $tzfd_params, $settings, $email );
        if ( ! is_array( $payload ) ) { return; } // the add-on is missing a setting it needs, so nothing is queued
        TZFD_Sync_Queue::enqueue( static::SLUG, $booking_id, $payload );
    }

    /** The data a job carries. Add-ons override this to send only what their service needs. */
    protected function build_payload( $tzfd_params, array $settings, $email )
    {
        return array( 'email' => $email, 'fields' => self::mapped_fields( $tzfd_params, $settings['field_map'] ) );
    }

    /** A submitted value by field name, or '' when missing. Only letters, digits, - and _ are accepted in the name. */
    public static function field( $tzfd_params, $name )
    {
        $name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name );
        return ( '' !== $name && isset( $tzfd_params[ $name ] ) && is_scalar( $tzfd_params[ $name ] ) ) ? (string) $tzfd_params[ $name ] : '';
    }

    public static function is_consent_given( $tzfd_params, $field )
    {
        return in_array( strtolower( trim( self::field( $tzfd_params, $field ) ) ), array( '1', 'yes', 'on', 'true' ), true );
    }

    /** Parses "remote_field,form_field" lines into remote_field => submitted value. */
    public static function mapped_fields( $tzfd_params, $text )
    {
        $out = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
            if ( count( $out ) >= self::MAX_MAPPINGS ) { break; }
            $pair = array_map( 'trim', explode( ',', $line, 2 ) );
            if ( count( $pair ) < 2 || '' === $pair[0] || '' === $pair[1] ) { continue; }
            $tzfd_key = preg_replace( '/[^A-Za-z0-9_\-]/', '', $pair[0] );
            if ( '' === $tzfd_key ) { continue; }
            $out[ $tzfd_key ] = sanitize_text_field( self::field( $tzfd_params, $pair[1] ) );
        }
        return $out;
    }

    /** First appointment of a submission: date (Y-m-d), start and end (HH:MM). Empty strings when there is none. */
    public static function first_slot( $tzfd_params )
    {
        foreach ( TZFD_Bookings::submission_slots( $tzfd_params ) as $tzfd_app ) {
            $times = explode( '/', $tzfd_app['slot'] );
            return array( 'date' => $tzfd_app['date'], 'start' => $times[0] ?? '', 'end' => $times[1] ?? '' );
        }
        return array( 'date' => '', 'start' => '', 'end' => '' );
    }

    public function get_addon_settings()
    {
        $stored = $this->settings();
        $status = $this->has_adapter()
            ? __( 'Status: adapter connected.', 'titan-zero-trades-front-desk' )
            : __( 'Status: no adapter connected. Jobs wait in the queue and nothing is sent.', 'titan-zero-trades-front-desk' );
        $values = $stored;
        $values['api_key'] = '';
        $specs = array_merge( array(
            'mode'          => array( 'label' => 'Mode', 'type' => 'text', 'help' => 'test or live. Passed to the adapter.' ),
            'api_key'       => array( 'label' => 'API key or token', 'type' => 'password', 'help' => 'Read by your connected adapter only.' ),
            'enabled'       => array( 'label' => 'Sync new bookings', 'type' => 'bool', 'help' => 'Queues a sync job for each new booking.' ),
            'consent_field' => array( 'label' => 'Consent field name', 'type' => 'text', 'help' => 'Form field that must be ticked (1, yes, on or true) before contact details are sent.' ),
            'email_field'   => array( 'label' => 'Email field name', 'type' => 'text', 'help' => 'Form field that holds the email address.' ),
            'field_map'     => array( 'label' => 'Field mapping', 'type' => 'textarea', 'help' => 'One per line: remote_field,form_field. Only mapped fields are sent.' ),
        ), $this->extra_specs() );
        ob_start();
        TZFD_Extension_Core::render_global_form( $this->name, static::SLUG, $specs, $values, $status );
        return ob_get_clean();
    }

    public function has_adapter()
    {
        return false !== has_filter( 'tzfd_' . static::SLUG . '_sync' );
    }
}
