<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-call-recovery.php';

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
        const LOG_OPTION  = 'tz_fd_missed_call_log';
        const OPTOUT_OPTION = 'tz_fd_sms_optout';
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
                'message'         => __( 'Sorry we missed your call. You can book online here: {link}', 'titan-zero-trades-front-desk' ),
                'after_hours_message' => __( 'We are closed right now. Book your next available appointment here: {link}', 'titan-zero-trades-front-desk' ),
                'business_hours' => 'mon=09:00-17:00;tue=09:00-17:00;wed=09:00-17:00;thu=09:00-17:00;fri=09:00-17:00',
            );
        }

        /** Translates a built-in default text when it is sent to a customer. Stored values are never changed. */
        public static function sms_template( $key, $template )
        {
            $defaults = self::defaults();
            if ( ! isset( $defaults[ $key ] ) || $defaults[ $key ] !== $template ) { return $template; }
            if ( 'message' === $key ) {
                return __( 'Sorry we missed your call. You can book online here: {link}', 'titan-zero-trades-front-desk' );
            }
            return __( 'We are closed right now. Book your next available appointment here: {link}', 'titan-zero-trades-front-desk' );
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
            if ( '' !== $s['inbound_secret'] && strlen( $s['inbound_secret'] ) < TZFD_Signed_Request::MIN_SECRET ) return 'The inbound secret is not valid. Generate a new one.';
            if ( false === strpos( $s['message'], '{link}' ) || false === strpos( $s['after_hours_message'], '{link}' ) ) return 'Both text messages must include {link}.';
            if ( false === self::parse_hours( $s['business_hours'] ) ) return 'Invalid business hours; use mon=09:00-17:00;tue=09:00-17:00.';
            return '';
        }

        public function get_addon_settings()
        {
            require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-signed-request.php';
            $defaults = self::defaults();
            $notice   = '';
            $request  = TZFD_Addon_Support::global_request( self::KEY );
            if ( null !== $request ) {
                $current = TZFD_Addon_Support::global_settings( self::KEY, $defaults );
                $new = array();
                foreach ( array_keys( $defaults ) as $tzfd_k ) {
                    $new[ $tzfd_k ] = isset( $request[ $tzfd_k ] ) ? sanitize_text_field( (string) $request[ $tzfd_k ] ) : '';
                }
                // The secret is generated by the plugin, never typed in. Saving keeps it; regenerating replaces it.
                $new['inbound_secret'] = (string) $current['inbound_secret'];
                $regenerated = isset( $request['regenerate'] ) && '1' === $request['regenerate'];
                if ( $regenerated ) $new['inbound_secret'] = TZFD_Signed_Request::new_secret();
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
                    if ( $regenerated ) {
                        // Shown once: the plugin keeps only the value, and the old secret stops working now.
                        $notice .= '<div class="notice notice-warning inline"><p>' . esc_html__( 'New inbound secret (shown once; copy it now):', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( $new['inbound_secret'] ) . '</code> ' . esc_html__( 'Update your phone system. The previous secret no longer works.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                    }
                }
            }

            $stored = TZFD_Addon_Support::global_settings( self::KEY, $defaults );
            $ready  = '' === self::settings_error( $stored ) && '' !== $stored['sms_url'] && '' !== $stored['inbound_secret'];
            $has_secret = '' !== $stored['inbound_secret'];

            ob_start();
            echo '<div class="tzfd-addons-container"><h2>' . esc_html( $this->name ) . '</h2>';
            echo wp_kses_post( $notice );
            echo '<p>' . esc_html( $ready ? __( 'Status: ready. Missed calls will be texted.', 'titan-zero-trades-front-desk' ) : __( 'Status: not ready. Enter an SMS address and an inbound secret below.', 'titan-zero-trades-front-desk' ) ) . '</p>';
            echo '<p>' . esc_html__( 'Your phone system should post missed calls and incoming texts to:', 'titan-zero-trades-front-desk' ) . ' <code>' . esc_html( rest_url( 'titan-zero-front-desk/v1/missed-call' ) ) . '</code></p>';
            echo '<form method="post">';
            wp_nonce_field( 'tz_fd_global_' . self::KEY, '_tz_fd_nonce' );
            echo '<input type="hidden" name="tz_fd_save_' . esc_attr( self::KEY ) . '" value="1" />';
            TZFD_Addon_Support::render_table( 'tz_fd_global_' . self::KEY . '_', array(
                'sms_url'         => array( 'label' => __( 'SMS endpoint URL', 'titan-zero-trades-front-desk' ), 'type' => 'text',
                                            'help'  => __( 'Your SMS gateway, https:// only. Each text is sent as JSON: {"to": "...", "message": "..."}.', 'titan-zero-trades-front-desk' ) ),
                'sms_auth_header' => array( 'label' => __( 'Auth header name', 'titan-zero-trades-front-desk' ), 'type' => 'text',
                                            'help'  => __( 'Usually Authorization. Leave blank for Authorization.', 'titan-zero-trades-front-desk' ) ),
                'sms_auth_value'  => array( 'label' => __( 'Auth header value', 'titan-zero-trades-front-desk' ), 'type' => 'password',
                                            'help'  => __( 'For example: Bearer your-token. Stored encrypted in the site database; leave blank to keep the current value.', 'titan-zero-trades-front-desk' ) ),
                'booking_url'     => array( 'label' => __( 'Booking page URL', 'titan-zero-trades-front-desk' ), 'type' => 'text',
                                            'help'  => __( 'Filled in for {link}. Leave blank to use the home page.', 'titan-zero-trades-front-desk' ) ),
                'after_hours_message' => array( 'label' => __( 'After-hours text', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => __( 'Used outside configured opening hours; must include {link}.', 'titan-zero-trades-front-desk' ) ),
                'business_hours' => array( 'label' => __( 'Business hours', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => __( 'mon=09:00-17:00;tue=09:00-17:00;etc. Omitted days are closed. Uses site timezone.', 'titan-zero-trades-front-desk' ) ),
                'message'         => array( 'label' => __( 'Text message', 'titan-zero-trades-front-desk' ), 'type' => 'text',
                                            'help'  => __( 'Must include {link}.', 'titan-zero-trades-front-desk' ) ),
            ), array(
                'sms_url'         => $stored['sms_url'],
                'sms_auth_header' => $stored['sms_auth_header'],
                'booking_url'     => $stored['booking_url'],
                'message'         => $stored['message'],
                'after_hours_message' => $stored['after_hours_message'],
                'business_hours' => $stored['business_hours'],
            ) );
            submit_button( __( 'Save settings', 'titan-zero-trades-front-desk' ), 'secondary', 'submit', false );
            echo ' <button type="submit" class="button" name="tz_fd_global_' . esc_attr( self::KEY ) . '_regenerate" value="1" onclick="return confirm(\'' . esc_js( __( 'Replace the inbound secret? The current one stops working at once.', 'titan-zero-trades-front-desk' ) ) . '\');">' . esc_html( $has_secret ? __( 'Generate new secret', 'titan-zero-trades-front-desk' ) : __( 'Generate secret', 'titan-zero-trades-front-desk' ) ) . '</button>';
            echo '<p class="description">' . esc_html__( 'Each request must be signed with this secret. The secret is generated here and is not shown again after you leave this page.', 'titan-zero-trades-front-desk' ) . '</p>';
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
            register_rest_route( 'titan-zero-front-desk/v1', '/missed-call', array(
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
            require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-signed-request.php';
            require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-rest-request-guard.php';
            if ( ! TZFD_REST_Request_Guard::check( $req, 'POST', 8192, 20 ) ) {
                return new WP_Error( 'tzfd_missed_call_invalid_request', __( 'Invalid call event.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
            }
            $stored    = TZFD_Addon_Support::global_settings( self::KEY, self::defaults() );
            $secret    = is_array( $stored ) && isset( $stored['inbound_secret'] ) ? $stored['inbound_secret'] : '';
            $body      = $req->get_body();
            $timestamp = $req->get_header( 'x-tzfd-timestamp' );
            $signature = $req->get_header( 'x-tzfd-signature' );
            if ( ! TZFD_Signed_Request::signature_valid( $secret, $timestamp, $signature, is_string( $body ) ? $body : '', time() ) ) {
                return new WP_Error( 'rest_forbidden', __( 'Invalid or missing signature.', 'titan-zero-trades-front-desk' ), array( 'status' => 401 ) );
            }
            if ( ! TZFD_Signed_Request::claim_signature( $signature ) ) {
                return new WP_Error( 'rest_forbidden', __( 'This request was already received.', 'titan-zero-trades-front-desk' ), array( 'status' => 401 ) );
            }
            return true;
        }

        public function rest_handle( $req )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-rest-request-guard.php';
            if ( ! TZFD_REST_Request_Guard::check( $req, 'POST', 8192, 20 ) ) {
                return new WP_Error( 'tzfd_missed_call_invalid_request', __( 'Invalid call event.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
            }
            $body = $req->get_body();
            if ( ! is_string( $body ) || strlen( $body ) > 8192 ) {
                return new WP_Error( 'tzfd_missed_call_payload', __( 'Invalid call event.', 'titan-zero-trades-front-desk' ), array( 'status' => 413 ) );
            }
            $params = $req->get_params();
            if ( ! is_array( $params ) || count( $params ) > 20 ) {
                return new WP_Error( 'tzfd_missed_call_payload', __( 'Invalid call event.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
            }
            foreach ( array( 'event', 'from', 'status', 'body' ) as $field ) {
                if ( isset( $params[ $field ] ) && ( ! is_string( $params[ $field ] ) || strlen( $params[ $field ] ) > ( 'body' === $field ? 512 : 80 ) ) ) {
                    return new WP_Error( 'tzfd_missed_call_payload', __( 'Invalid call event.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
                }
            }
            return new WP_REST_Response( self::handle( $params ), 200 );
        }

        /************************ HANDLING *****************************/

        /** Handles one event. Returns the result array sent back to the phone system. */
        public static function handle( array $tzfd_local_params )
        {
            foreach ( array( 'event', 'from', 'status', 'body' ) as $field ) {
                if ( isset( $tzfd_local_params[ $field ] ) && ( ! is_string( $tzfd_local_params[ $field ] ) || strlen( $tzfd_local_params[ $field ] ) > ( 'body' === $field ? 512 : 80 ) ) ) {
                    return array( 'status' => 'ignored', 'reason' => 'invalid_input' );
                }
            }
            $event  = strtolower( sanitize_text_field( $tzfd_local_params['event'] ?? '' ) );
            $number = self::normalise_number( $tzfd_local_params['from'] ?? '' );
            if ( '' === $number ) return array( 'status' => 'ignored', 'reason' => 'no_number' );

            if ( 'inbound_sms' === $event ) {
                $word = strtoupper( trim( (string) ( $tzfd_local_params['body'] ?? '' ) ) );
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
            $status = strtolower( sanitize_text_field( $tzfd_local_params['status'] ?? 'missed' ) );
            if ( ! in_array( $status, array( 'missed', 'no-answer', 'busy' ), true ) ) {
                return array( 'status' => 'ignored', 'reason' => 'not_missed' );
            }

            $stored = TZFD_Addon_Support::global_settings( self::KEY, self::defaults() );
            $decision = TZFD_Call_Recovery::outcome( $event, $status, self::is_optout( $number ), self::within_limit( $number ), ! empty( $stored['sms_url'] ) && self::is_https( $stored['sms_url'] ) );
            if ( 'eligible' !== $decision ) { return self::log_and_return( $number, $decision ); }
            if ( '' === $stored['sms_url'] || ! self::is_https( $stored['sms_url'] ) ) {
                return self::log_and_return( $number, 'not_configured' );
            }

            $link    = '' !== $stored['booking_url'] ? $stored['booking_url'] : home_url( '/' );
            $hours = self::parse_hours( $stored['business_hours'] ?? '' );
            $after_hours = false !== $hours && TZFD_Call_Recovery::is_after_hours( $hours, null, wp_timezone() );
            $template = $after_hours ? self::sms_template( 'after_hours_message', $stored['after_hours_message'] ) : self::sms_template( 'message', $stored['message'] );
            $tzfd_message = str_replace( '{link}', $link, $template );

            $headers = array( 'Content-Type' => 'application/json' );
            if ( '' !== $stored['sms_auth_value'] ) {
                $name = '' !== $stored['sms_auth_header'] ? $stored['sms_auth_header'] : 'Authorization';
                if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9-]{1,64}$/D', $name ) ||
                    ! is_string( $stored['sms_auth_value'] ) || strlen( $stored['sms_auth_value'] ) > 2048 ||
                    preg_match( '/[\r\n]/', $stored['sms_auth_value'] ) ) {
                    return self::log_and_return( $number, 'invalid_gateway_configuration' );
                }
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

        /** Strict weekly schedule parser. Invalid schedules fail closed rather than guessing hours. */
        public static function parse_hours( $raw ) {
            if ( ! is_string( $raw ) || strlen( $raw ) > 512 ) return false;
            $result = array();
            if ( trim( $raw ) === '' ) return $result;
            foreach ( explode( ';', strtolower( trim( $raw ) ) ) as $part ) {
                if ( ! preg_match( '/^\s*(mon|tue|wed|thu|fri|sat|sun)=((?:[01][0-9]|2[0-3]):[0-5][0-9])-((?:[01][0-9]|2[0-3]):[0-5][0-9])\s*$/D', $part, $m ) || isset( $result[$m[1]] ) ) return false;
                $result[$m[1]] = array( $m[2], $m[3] );
            }
            return $result;
        }

        public static function normalise_number( $raw )
        {
            if ( ! is_string( $raw ) || strlen( $raw ) > 80 ) return '';
            $raw    = trim( $raw );
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

        private static function rate_key( $number ) { return 'tz_fd_mc_' . md5( $number ); }

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
