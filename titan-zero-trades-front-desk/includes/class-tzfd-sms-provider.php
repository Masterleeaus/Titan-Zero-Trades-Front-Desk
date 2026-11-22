<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once dirname( __DIR__ ) . '/addons/core/base.addon.php';
require_once __DIR__ . '/class-tzfd-addon-records.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';
require_once __DIR__ . '/class-tzfd-outbox.php';

/**
 * Shared wiring for the SMS provider add-ons (Twilio, Clickatell, MMD Smart, SMSBroadcast, Text-Em-All).
 *
 * The providers share one delivery path, so they behave the same way:
 *  - sends queued texts through the outbox (filter tz_fd_sms_send) using the adapter tz_fd_{SLUG}_send.
 *    With no adapter connected the text stays queued and nothing is sent;
 *  - can queue a booking confirmation text when the customer gave a phone number (notify setting);
 *  - STOP replies: handle_inbound() would opt a number out, but the generic webhook route is removed, so inbound
 *    STOP replies are NOT received until a route is re-homed (open, see NOTES.md).
 * Vendor request formats and sender rules belong to the connected adapter, not to this file.
 */
abstract class TZFD_Sms_Provider_Addon extends TZFD_BaseAddon
{
    public $category = 'SMS Delivery / Text Messaging';
    const SLUG = '';
    const MAX_INBOUND_BYTES = 65536;

    public static function defaults()
    {
        return array(
            'mode'           => 'test',
            'sender'         => '',
            'api_key'        => '',
            'webhook_secret' => '',
            'notify'         => '0',
            'phone_field'    => 'phone',
            'template'       => 'Your appointment is booked. Reply STOP to opt out.',
        );
    }

    protected function sms_boot()
    {
        if ( ! $this->addon_is_active() ) { return; }
        TZFD_Outbox::boot();
        add_filter( 'tz_fd_sms_send', array( $this, 'send' ), 10, 2 );
        add_action( 'tz_fd_process_data', array( $this, 'queue_booking_text' ), 40, 1 );
        // The generic webhook route was removed (19 Oct, decision). handle_inbound() is not reachable until a route is re-homed.
    }

    public function settings()
    {
        return TZFD_Extension_Core::global_settings( static::SLUG, static::defaults(), array( 'api_key', 'webhook_secret' ) );
    }

    /** Outbox sender. Returns null (not handled) when this add-on is off or its adapter is not connected. */
    public function send( $sent, $msg )
    {
        if ( null !== $sent ) { return $sent; }
        if ( ! $this->addon_is_active() || ! is_array( $msg ) ) { return null; }
        $to   = TZFD_Outbox::normalize_phone( $msg['to'] ?? '' );
        $body = trim( (string) ( $msg['body'] ?? '' ) );
        if ( '' === $to || '' === $body || strlen( $body ) > TZFD_Outbox::MAX_SMS_LEN ) { return false; }

        $settings = $this->settings();
        $result   = TZFD_Extension_Core::adapter( static::SLUG, 'send', array(
            'to' => $to, 'body' => $body, 'sender' => $settings['sender'], 'mode' => $settings['mode'],
        ) );
        return null === $result ? null : ( true === $result );
    }

    /** Queues the confirmation text for a new booking, when enabled and a phone number was given. */
    public function queue_booking_text( $tzfd_local_params )
    {
        if ( ! $this->addon_is_active() || ! is_array( $tzfd_local_params ) ) { return; }
        $settings = $this->settings();
        if ( '1' !== (string) $settings['notify'] ) { return; }
        $field = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $settings['phone_field'] );
        $phone = ( '' !== $field && isset( $tzfd_local_params[ $field ] ) && is_scalar( $tzfd_local_params[ $field ] ) ) ? (string) $tzfd_local_params[ $field ] : '';
        TZFD_Outbox::queue( 'sms', $phone, (string) $settings['template'], (int) ( $tzfd_local_params['itemnumber'] ?? 0 ), static::SLUG );
    }

    /** Inbound webhook: records a STOP reply once the request has been verified. */
    public function handle_inbound( WP_REST_Request $request )
    {
        if ( ! $this->addon_is_active() ) { return TZFD_Extension_Core::respond( array( 'ok' => false ), 404 ); }
        $raw = (string) $request->get_body();
        if ( '' === $raw || strlen( $raw ) > self::MAX_INBOUND_BYTES ) {
            return TZFD_Extension_Core::respond( array( 'ok' => false, 'message' => 'bad_body' ), 400 );
        }
        $verified = TZFD_Extension_Core::adapter( static::SLUG, 'verify', array(
            'raw' => $raw, 'request' => $request, 'settings' => $this->settings(),
        ) );
        if ( true !== $verified ) {
            return TZFD_Extension_Core::respond( array( 'ok' => false, 'message' => 'bad_signature' ), 401 );
        }
        $in   = self::parse_inbound( $raw );
        $from = TZFD_Outbox::normalize_phone( $in['from'] );
        if ( '' !== $from && TZFD_Outbox::is_stop_keyword( $in['body'] ) ) {
            if ( ! TZFD_Outbox::opt_out( $from ) ) {
                // Never acknowledge an unsubscribe that was not persisted.
                return TZFD_Extension_Core::respond( array( 'ok' => false, 'message' => 'optout_storage_unavailable' ), 503 );
            }
            return TZFD_Extension_Core::respond( array( 'ok' => true, 'opted_out' => true ), 200 );
        }
        return TZFD_Extension_Core::respond( array( 'ok' => true, 'handled' => false ), 200 );
    }

    /** Reads the sender and text from a JSON or form-encoded body. Keys are matched case-insensitively. */
    public static function parse_inbound( $raw )
    {
        $pairs = json_decode( (string) $raw, true );
        if ( ! is_array( $pairs ) ) {
            $pairs = array();
            parse_str( (string) $raw, $pairs );
        }
        $pairs = array_change_key_case( $pairs, CASE_LOWER );
        return array(
            'from' => is_scalar( $pairs['from'] ?? null ) ? (string) $pairs['from'] : '',
            'body' => is_scalar( $pairs['body'] ?? null ) ? (string) $pairs['body'] : '',
        );
    }

    public function get_addon_settings()
    {
        $stored = $this->settings();
        $status = ( '' !== $stored['webhook_secret'] )
            ? __( 'Status: STOP replies are accepted once the verify adapter is connected.', 'titan-zero-trades-front-desk' )
            : __( 'Status: no webhook secret saved. Inbound STOP replies are not accepted.', 'titan-zero-trades-front-desk' );
        $values = $stored;
        $values['api_key']        = '';
        $values['webhook_secret'] = '';
        $specs = array(
            'mode'           => array( 'label' => __( 'Mode', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => 'test or live. Passed to the adapter.' ),
            'sender'         => array( 'label' => __( 'Sender ID or number', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => 'Passed to the adapter. Your provider sets the rules.' ),
            'api_key'        => array( 'label' => __( 'API key', 'titan-zero-trades-front-desk' ), 'type' => 'password', 'help' => 'Read by your connected adapter only.' ),
            'webhook_secret' => array( 'label' => __( 'Webhook secret', 'titan-zero-trades-front-desk' ), 'type' => 'password', 'help' => 'Used by the verify adapter. Blank keeps the stored value.' ),
            'notify'         => array( 'label' => __( 'Text a confirmation when a customer books', 'titan-zero-trades-front-desk' ), 'type' => 'bool', 'help' => 'Turn on for one SMS provider only, so customers do not get two texts.' ),
            'phone_field'    => array( 'label' => __( 'Phone field name', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => 'The form field that holds the mobile number, for example phone.' ),
            'template'       => array( 'label' => __( 'Confirmation text', 'titan-zero-trades-front-desk' ), 'type' => 'textarea', 'help' => 'Keep personal details out of this text. Up to 1000 characters.' ),
        );
        ob_start();
        TZFD_Extension_Core::render_global_form( $this->name, static::SLUG, $specs, $values, $status );
        return ob_get_clean();
    }
}
