<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once dirname( __DIR__ ) . '/addons/core/base.addon.php';
require_once __DIR__ . '/class-tzfd-addon-records.php';
require_once __DIR__ . '/class-tzfd-extension-core.php';
require_once __DIR__ . '/class-tzfd-payment-events.php';

/**
 * Shared flow for payment gateway add-ons.
 *
 * A gateway add-on receives webhooks at tzfd/v1/webhook/{SLUG} and does four things:
 *   1. checks the signature (local scheme if the subclass has one, otherwise the verify adapter);
 *   2. turns the provider's event into a normalized event (local parser, otherwise the parse adapter);
 *   3. records a successful payment once per provider reference;
 *   4. emits TZFD_Payment_Events::dispatch_update_attempted(). The core does not change the booking itself.
 *
 * Nothing is recorded or emitted unless the signature checks out. A payment whose amount does not
 * match the expected amount (filter tzfd_gateway_expected_amount) is refused.
 * Subclasses define: const SLUG, $addonID, $name, $tzfd_category, and optionally local_signature_ok()/local_parse().
 */
abstract class TZFD_Gateway_Addon extends TZFD_BaseAddon {

    public $category = 'Payment Gateways Integration';
    const EXPECTED_AMOUNT_FILTER = 'tzfd_gateway_expected_amount';
    const MAX_BODY_BYTES = 262144;

    /** Settings shared by every gateway. Subclasses may extend. */
    public static function defaults() {
        return array( 'mode' => 'test', 'api_key' => '', 'webhook_secret' => '', 'return_url' => '' );
    }

    /** Extra settings fields for one gateway, in render_table() format. */
    protected static function extra_fields() { return array(); }

    protected function gateway_boot() {
        if ( ! $this->addon_is_active() ) { return; }
        TZFD_Addon_Records::ensure_schema();
        TZFD_Extension_Core::register_webhook( static::SLUG, array( $this, 'handle_webhook' ) );
    }

    public function settings() {
        return TZFD_Extension_Core::global_settings( static::SLUG, static::defaults(), array( 'api_key', 'webhook_secret' ) );
    }

    public function get_addon_settings() {
        $stored = $this->settings();
        $status = ( '' !== $stored['webhook_secret'] )
            ? __( 'Status: accepting signed webhooks.', 'titan-zero-trades-front-desk' )
            : __( 'Status: not accepting webhooks yet. Enter the webhook secret below.', 'titan-zero-trades-front-desk' );
        $values = $stored;
        $values['api_key']        = '';
        $values['webhook_secret'] = '';
        $specs = array_merge( array(
            'mode'           => array( 'label' => 'Mode', 'type' => 'text', 'help' => 'test or live. Informational; your adapter decides which keys to use.' ),
            'api_key'        => array( 'label' => 'API key', 'type' => 'password', 'help' => 'Read by your connected adapter. Give it to administrators only.' ),
            'webhook_secret' => array( 'label' => 'Webhook secret', 'type' => 'password', 'help' => 'Used to verify webhook signatures. Blank keeps the stored value.' ),
            'return_url'     => array( 'label' => 'Return page URL', 'type' => 'text', 'help' => 'Where customers land after paying.' ),
        ), static::extra_fields() );
        ob_start();
        TZFD_Extension_Core::render_global_form( $this->name, static::SLUG, $specs, $values, $status );
        return ob_get_clean();
    }

    /** Endpoint callback for tzfd/v1/webhook/{SLUG}. */
    public function handle_webhook( WP_REST_Request $request ) {
        if ( ! $this->addon_is_active() ) { return TZFD_Extension_Core::respond( array( 'ok' => false ), 404 ); }

        $raw = (string) $request->get_body();
        if ( '' === $raw || strlen( $raw ) > self::MAX_BODY_BYTES ) {
            return TZFD_Extension_Core::respond( array( 'ok' => false, 'message' => 'bad_body' ), 400 );
        }
        $settings = $this->settings();
        if ( ! $this->signature_ok( $raw, $request, $settings ) ) {
            return TZFD_Extension_Core::respond( array( 'ok' => false, 'message' => 'bad_signature' ), 401 );
        }

        $event = $this->parse_event( $raw, $request, $settings );
        if ( null === $event || 'succeeded' !== $event['status'] ) {
            return TZFD_Extension_Core::respond( array( 'ok' => true, 'handled' => false ), 200 );
        }
        return $this->record_success( $event );
    }

    /** Subclasses with a local scheme return true or false. Return null to use the verify adapter. */
    protected function local_signature_ok( $raw, $request, array $settings ) { return null; }

    /** Subclasses with a local parser return a normalized event or null. Return null to use the parse adapter. */
    protected function local_parse( $raw, $request, array $settings ) { return null; }

    protected function signature_ok( $raw, $request, array $settings ) {
        $local = $this->local_signature_ok( $raw, $request, $settings );
        if ( null !== $local ) { return (bool) $local; }
        $verdict = TZFD_Extension_Core::adapter( static::SLUG, 'verify', array( 'raw' => $raw, 'request' => $request, 'settings' => $settings ) );
        return true === $verdict;
    }

    protected function parse_event( $raw, $request, array $settings ) {
        $local = $this->local_parse( $raw, $request, $settings );
        if ( null !== $local ) { return $this->normalize_event( $local ); }
        $adapter = TZFD_Extension_Core::adapter( static::SLUG, 'parse', array( 'raw' => $raw, 'request' => $request, 'settings' => $settings ) );
        return is_array( $adapter ) ? $this->normalize_event( $adapter ) : null;
    }

    /** Normalized event: ref, booking_id, amount, currency, status. Null when a field is missing or invalid. */
    protected function normalize_event( array $event ) {
        $ref      = TZFD_Addon_Records::clean_ref( $event['ref'] ?? '' );
        $status   = is_string( $event['status'] ?? null ) ? strtolower( $event['status'] ) : '';
        $amount   = is_numeric( $event['amount'] ?? null ) ? round( (float) $event['amount'], 2 ) : 0.0;
        $currency = TZFD_Extension_Core::currency_code( $event['currency'] ?? '' );
        $booking  = TZFD_Extension_Core::booking_id( $event['booking_id'] ?? 0 );
        if ( '' === $ref || ! in_array( $status, array( 'succeeded', 'failed', 'pending', 'refunded', 'cancelled' ), true ) ) {
            return null;
        }
        return array( 'ref' => $ref, 'status' => $status, 'amount' => $amount, 'currency' => $currency, 'booking_id' => $booking );
    }

    protected function record_success( array $event ) {
        if ( 0 === $event['booking_id'] || $event['amount'] <= 0 || '' === $event['currency'] ) {
            return TZFD_Extension_Core::respond( array( 'ok' => false, 'message' => 'unmatched' ), 422 );
        }
        // Same value as self::EXPECTED_AMOUNT_FILTER; literal keeps the hook name checkable.
        $expected = (float) apply_filters( 'tzfd_gateway_expected_amount', 0, $event['booking_id'], static::SLUG );
        if ( $expected > 0 && abs( $expected - $event['amount'] ) > 0.005 ) {
            return TZFD_Extension_Core::respond( array( 'ok' => false, 'message' => 'amount_mismatch' ), 422 );
        }
        $stored = TZFD_Addon_Records::insert( static::SLUG, 'payment', $event['ref'], $event['booking_id'], array(
            'amount' => $event['amount'], 'currency' => $event['currency'], 'status' => 'succeeded', 'received' => current_time( 'mysql' ),
        ) );
        if ( ! $stored ) {
            return TZFD_Extension_Core::respond( array( 'ok' => true, 'duplicate' => true ), 200 );
        }
        TZFD_Payment_Events::dispatch_update_attempted( $event['booking_id'], 'succeeded' );
        do_action( 'tzfd_gateway_payment_recorded', static::SLUG, $event['booking_id'], $event['amount'], $event['currency'], $event['ref'] );
        return TZFD_Extension_Core::respond( array( 'ok' => true ), 200 );
    }

    /** Payments this gateway has recorded for a booking. */
    public function payments_for( $booking_id ) {
        return TZFD_Addon_Records::for_booking( static::SLUG, 'payment', (int) $booking_id );
    }

    /** Checkout link from the connected adapter, or '' when none is connected. */
    public function checkout_url( $booking_id, $amount, $currency ) {
        $url = TZFD_Extension_Core::adapter( static::SLUG, 'checkout', array(
            'booking_id' => (int) $booking_id,
            'amount'     => (float) $amount,
            'currency'   => TZFD_Extension_Core::currency_code( $currency ),
            'return_url' => $this->settings()['return_url'],
        ) );
        return ( is_string( $url ) && 0 === strpos( $url, 'https://' ) ) ? esc_url( $url ) : '';
    }
}
