<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';

if ( ! class_exists( 'TZFD_MissedCall' ) )
{
    /**
     * Missed-call text-back, provider-neutral. Any phone system that can send a webhook posts a
     * missed call to this site. The site then sends one SMS with a booking link through an
     * HTTPS endpoint the administrator enters. Nothing is sent to a vendor by default.
     */
    class TZFD_MissedCall extends TZFD_BaseAddon
    {
        protected $addonID = "tzfd-MissedCall";
        protected $name = "Missed-Call Text-Back";
        protected $description;
        public $category = ' Missed-call text-back (included in this plugin version)';
        public $help = '';

        const KEY         = 'missed_call';
        const LOG_OPTION  = 'tzfd_missed_call_log';
        const OPTOUT_OPTION = 'tzfd_sms_optout';
        const MAX_PER_NUMBER = 2;     // texts per caller number per window
        const RATE_WINDOW    = 86400; // 24 hours
        const LOG_MAX       = 100;

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
            $this->description = $this->tr_apply( "When a call is missed, text the caller a booking link. Works with any phone system that can send a webhook and any SMS gateway with an HTTPS endpoint.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'rest_api_init', array( &$this, 'register_routes' ) );
        }

        /************************ SETTINGS *****************************/

        public static function defaults()
        {
            return array(
                'inbound_secret'  => '',
                'sms_url'         => '',
                'sms_auth_header' => 'Authorization',
                'sms_auth_value'  => '',
                'booking_url'     => '',
                'message'         => 'Sorry we missed your call. You can book online here: {link}',
            );
        }

        public static function is_https( $url )
        {
            return (bool) preg_match( '#^https://[^\s/?\#@]+#i', (string) $url );
        }

        /** Returns an error message, or an empty string when the settings are valid. */
        public static function settings_error( array $s )
        {
            if ( '' !== $s['sms_url'] && ! self::is_https( $s['sms_url'] ) ) return 'The SMS address must start with https://.';
            if ( '' !== $s['booking_url'] && ! self::is_https( $s['booking_url'] ) ) return 'The booking address must start with https://.';
            if ( '' !== $s['inbound_secret'] && strlen( $s['inbound_secret'] ) < 32 ) return 'The inbound secret must be at least 32 characters.';
            if ( false === strpos( $s['message'], '{link}' ) ) return 'The text message must include {link}.';
            return '';
        }

        public function get_addon_settings()
        {
            $defaults = self::defaults();
            $notice   = '';
            $request  = TZFD_Addon_Support::global_request( self::KEY );
            if ( null !== $request ) {
                $current = TZFD_Addon_Support::global_settings( self::KEY, $defaults );
                $new = array();
                foreach ( array_keys( $defaults ) as $tzfd_k ) {
                    $new[ $tzfd_k ] = isset( $request[ $tzfd_k ] ) ? sanitize_text_field( (string) $request[ $tzfd_k ] ) : '';
                }
                if ( '' === $new['inbound_secret'] ) $new['inbound_secret'] = $current['inbound_secret']; // blank keeps the stored secret
                if ( '' === $new['sms_auth_value'] ) $new['sms_auth_value'] = $current['sms_auth_value'];
                if ( '' === $new['sms_auth_header'] ) $new['sms_auth_header'] = 'Authorization';

                $err = self::settings_error( $new );
                if ( '' !== $err ) {
                    $notice = '<div class="notice notice-error inline"><p>' . esc_html( $err ) . ' ' . esc_html__( 'Nothing was saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                } else {
                    $new['sms_url']     = esc_url_raw( $new['sms_url'] );
                    $new['booking_url'] = esc_url_raw( $new['booking_url'] );
                    TZFD_Addon_Support::save_global_settings( self::KEY, $defaults, $new );
                    $notice = '<div class="notice notice-success inline"><p>' . esc_html__( 'Missed-call settings saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                }
            }

            $stored = TZFD_Addon_Support::global_settings( self::KEY, $defaults );
            $ready  = '' === self::settings_error( $stored ) && '' !== $stored['sms_url'] && '' !== $stored['inbound_secret'];

            ob_start();
            echo '<div class="tzfd-addons-container"><h2>' . esc_html( $this->name ) . '</h2>';
            echo wp_kses_post( $notice );
            echo '<p>' . esc_html( $ready ? __( 'Status: ready. Missed calls will be texted.', 'titan-zero-trades-front-desk' ) : __( 'Status: not ready. Enter an SMS address and an inbound secret below.', 'titan-zero-trades-front-desk' ) ) . '</p>';
            echo '<p>' . esc_html__( 'Your phone system should post missed calls and incoming texts to:', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( rest_url( 'tzfd/v1/missed-call' ) ) . '</code></p>';
            echo '<form method="post">';
            wp_nonce_field( 'tzfd_global_' . self::KEY, '_tzfd_nonce' );
            echo '<input type="hidden" name="tzfd_save_' . esc_attr( self::KEY ) . '" value="1" />';
            TZFD_Addon_Support::render_table( 'tzfd_global_' . self::KEY . '_', array(
                'sms_url'         => array( 'label' => 'SMS endpoint URL', 'type' => 'text',
                                            'help'  => 'Your SMS gateway, https:// only. Each text is sent as JSON: {"to": "...", "message": "..."}.' ),
                'sms_auth_header' => array( 'label' => 'Auth header name', 'type' => 'text',
                                            'help'  => 'Usually Authorization. Leave blank for Authorization.' ),
                'sms_auth_value'  => array( 'label' => 'Auth header value', 'type' => 'password',
                                            'help'  => 'For example: Bearer your-token. Stored in the site database; leave blank to keep the current value.' ),
                'booking_url'     => array( 'label' => 'Booking page URL', 'type' => 'text',
                                            'help'  => 'Filled in for {link}. Leave blank to use the home page.' ),
                'message'         => array( 'label' => 'Text message', 'type' => 'text',
                                            'help'  => 'Must include {link}.' ),
                'inbound_secret'  => array( 'label' => 'Inbound secret', 'type' => 'password',
                                            'help'  => 'At least 32 characters. Your phone system sends it in the X-TZFD-Secret header. Leave blank to keep the current secret. Nothing is accepted until a secret is set.' ),
            ), array(
                'sms_url'         => $stored['sms_url'],
                'sms_auth_header' => $stored['sms_auth_header'],
                'booking_url'     => $stored['booking_url'],
                'message'         => $stored['message'],
            ) );
            submit_button( __( 'Save settings', 'titan-zero-trades-front-desk' ), 'secondary', 'submit', false );
            echo '</form>';

            $log = get_option( self::LOG_OPTION, array() );
            echo '<h3>' . esc_html__( 'Recent texts', 'titan-zero-trades-front-desk' ) . '</h3>';
            if ( empty( $log ) || ! is_array( $log ) ) {
                echo '<p>' . esc_html__( 'None yet.', 'titan-zero-trades-front-desk' ) . '</p>';
            } else {
                echo '<table class="widefat"><thead><tr><th>Time</th><th>Caller</th><th>Result</th></tr></thead><tbody>';
                foreach ( array_slice( array_reverse( $log ), 0, 20 ) as $row ) {
                    echo '<tr><td>' . esc_html( $row['time'] ) . '</td><td>' . esc_html( $row['caller'] ) . '</td><td>' . esc_html( $row['result'] ) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
            return ob_get_clean();
        }

        /************************ REST *****************************/

        public function register_routes()
        {
            register_rest_route( 'tzfd/v1', '/missed-call', array(
                'methods'             => 'POST',
                'callback'            => array( &$this, 'rest_handle' ),
                'permission_callback' => array( &$this, 'rest_permission' ),
                'args'                => array(
                    'event'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
                    'from'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
                    'status' => array( 'sanitize_callback' => 'sanitize_text_field' ),
                    'body'   => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
                ),
            ) );
        }

        public function rest_permission( $req )
        {
            $stored = TZFD_Addon_Support::global_settings( self::KEY, self::defaults() );
            $given  = (string) $req->get_header( 'x-tzfd-secret' );
            return self::secret_matches( $given, $stored['inbound_secret'] )
                ? true
                : new WP_Error( 'rest_forbidden', 'Invalid or missing secret.', array( 'status' => 401 ) );
        }

        public function rest_handle( $req )
        {
            return new WP_REST_Response( self::handle( (array) $req->get_params() ), 200 );
        }

        /** Constant-time check. Fails closed until an inbound secret of 32+ characters is set. */
        public static function secret_matches( $given, $expected )
        {
            if ( '' === (string) $expected || strlen( (string) $expected ) < 32 ) return false;
            return hash_equals( (string) $expected, (string) $given );
        }

        /************************ HANDLING *****************************/

        /** Handles one event. Returns the result array sent back to the phone system. */
        public static function handle( array $tzfd_params )
        {
            $event  = strtolower( sanitize_text_field( $tzfd_params['event'] ?? '' ) );
            $number = self::normalise_number( $tzfd_params['from'] ?? '' );
            if ( '' === $number ) return array( 'status' => 'ignored', 'reason' => 'no_number' );

            if ( 'inbound_sms' === $event ) {
                $word = strtoupper( trim( (string) ( $tzfd_params['body'] ?? '' ) ) );
                if ( in_array( $word, array( 'STOP', 'UNSUBSCRIBE', 'END', 'CANCEL', 'QUIT' ), true ) ) {
                    self::set_optout( $number, true );
                    return array( 'status' => 'ok', 'result' => 'opted_out' );
                }
                if ( 'START' === $word ) {
                    self::set_optout( $number, false );
                    return array( 'status' => 'ok', 'result' => 'opted_in' );
                }
                return array( 'status' => 'ok', 'result' => 'no_action' );
            }

            if ( 'missed_call' !== $event ) return array( 'status' => 'ignored', 'reason' => 'unknown_event' );
            $status = strtolower( sanitize_text_field( $tzfd_params['status'] ?? 'missed' ) );
            if ( ! in_array( $status, array( 'missed', 'no-answer', 'busy' ), true ) ) {
                return array( 'status' => 'ignored', 'reason' => 'not_missed' );
            }

            if ( self::is_optout( $number ) )   return self::log_and_return( $number, 'opted_out' );
            if ( ! self::within_limit( $number ) ) return self::log_and_return( $number, 'rate_limited' );

            $stored = TZFD_Addon_Support::global_settings( self::KEY, self::defaults() );
            if ( '' === $stored['sms_url'] || ! self::is_https( $stored['sms_url'] ) ) {
                return self::log_and_return( $number, 'not_configured' );
            }

            $link    = '' !== $stored['booking_url'] ? $stored['booking_url'] : home_url( '/' );
            $tzfd_message = str_replace( '{link}', $link, $stored['message'] );

            $headers = array( 'Content-Type' => 'application/json' );
            if ( '' !== $stored['sms_auth_value'] ) {
                $name = '' !== $stored['sms_auth_header'] ? $stored['sms_auth_header'] : 'Authorization';
                $headers[ $name ] = $stored['sms_auth_value'];
            }

            $resp = wp_safe_remote_post( $stored['sms_url'], array(
                'headers'  => $headers,
                'body'     => wp_json_encode( array( 'to' => $number, 'message' => $tzfd_message ) ),
                'timeout'  => 10,
                'blocking' => true,
            ) );
            $ok = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) >= 200 && wp_remote_retrieve_response_code( $resp ) < 300;

            self::count_send( $number );
            return self::log_and_return( $number, $ok ? 'sent' : 'failed' );
        }

        public static function normalise_number( $raw )
        {
            $raw    = trim( (string) $raw );
            $plus   = ( '+' === substr( $raw, 0, 1 ) ) ? '+' : '';
            $digits = preg_replace( '/\D+/', '', $raw );
            return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? $plus . $digits : '';
        }

        private static function is_optout( $number )
        {
            $list = get_option( self::OPTOUT_OPTION, array() );
            return is_array( $list ) && in_array( $number, $list, true );
        }

        private static function set_optout( $number, $on )
        {
            $list = get_option( self::OPTOUT_OPTION, array() );
            if ( ! is_array( $list ) ) $list = array();
            $list = array_values( array_diff( $list, array( $number ) ) );
            if ( $on ) $list[] = $number;
            update_option( self::OPTOUT_OPTION, $list, false );
        }

        private static function rate_key( $number ) { return 'tzfd_mc_' . md5( $number ); }

        private static function within_limit( $number )
        {
            return (int) get_transient( self::rate_key( $number ) ) < self::MAX_PER_NUMBER;
        }

        private static function count_send( $number )
        {
            set_transient( self::rate_key( $number ), (int) get_transient( self::rate_key( $number ) ) + 1, self::RATE_WINDOW );
        }

        private static function log_and_return( $number, $result )
        {
            $log = get_option( self::LOG_OPTION, array() );
            if ( ! is_array( $log ) ) $log = array();
            $log[] = array(
                'time'   => gmdate( 'Y-m-d H:i' ) . ' UTC',
                'caller' => '***' . substr( $number, -4 ),
                'result' => $result,
            );
            if ( count( $log ) > self::LOG_MAX ) $log = array_slice( $log, -self::LOG_MAX );
            update_option( self::LOG_OPTION, $log, false );
            return array( 'status' => 'ok', 'result' => $result );
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $tzfd_missed_call_obj = new TZFD_MissedCall();

    // Add addon object to the objects list
    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $tzfd_missed_call_obj->get_addon_id() ] = $tzfd_missed_call_obj;
}
