<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/class-tzfd-stored-data.php';


if ( ! class_exists( 'TZFD_Customer_Cancellation' ) ) {
    class TZFD_Customer_Cancellation {
        const STATUS = 'Cancelled by customer';
        const ACTION = 'titan_zero_front_desk_customer_cancel';

        private $booking_id = 0;
        private $customer_email = '';
        private $email_format = 'text';

        public function register() {
            add_action( 'tzfd_process_data', array( $this, 'capture_booking' ), 1000, 1 );
            add_filter( 'wp_mail', array( $this, 'filter_customer_mail' ), 10, 1 );
            add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_public_request' ) );
            add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_public_request' ) );
        }

        public static function normalize_booking_id( $booking_id ) {
            if ( ! is_int( $booking_id ) && ! is_string( $booking_id ) ) {
                return 0;
            }
            $validated = filter_var(
                $booking_id,
                FILTER_VALIDATE_INT,
                array( 'options' => array( 'min_range' => 1 ) )
            );
            return false === $validated ? 0 : (int) $validated;
        }

        public static function normalize_email( $email ) {
            if ( ! is_string( $email ) ) {
                return '';
            }
            $email = strtolower( trim( $email ) );
            if ( function_exists( 'sanitize_email' ) && sanitize_email( $email ) !== $email ) {
                return '';
            }
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
        }

        public static function create_token( $booking_id, $email, $secret ) {
            $booking_id = self::normalize_booking_id( $booking_id );
            $email      = self::normalize_email( $email );
            if ( ! $booking_id || '' === $email || ! is_string( $secret ) || '' === $secret ) {
                return '';
            }
            return hash_hmac(
                'sha256',
                'titan-zero-trades-front-desk/customer-cancellation/v1|' . $booking_id . '|' . $email,
                $secret
            );
        }

        public static function verify_token( $booking_id, $email, $token, $secret ) {
            if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
                return false;
            }
            $expected = self::create_token( $booking_id, $email, $secret );
            return '' !== $expected && hash_equals( $expected, $token );
        }

        public static function is_cancelable_data( $tzfd_posted_data ) {
            if ( ! is_array( $tzfd_posted_data ) || isset( $tzfd_posted_data['lock'] ) ||
                 ! isset( $tzfd_posted_data['apps'] ) || ! is_array( $tzfd_posted_data['apps'] ) ||
                 empty( $tzfd_posted_data['apps'] ) ) {
                return false;
            }

            $tzfd_count = count( $tzfd_posted_data['apps'] );
            for ( $index = 0; $index < $tzfd_count; $index++ ) {
                if ( ! isset( $tzfd_posted_data['apps'][ $index ] ) ||
                     ! is_array( $tzfd_posted_data['apps'][ $index ] ) ) {
                    return false;
                }

                $tzfd_app = $tzfd_posted_data['apps'][ $index ];
                $app_status = array_key_exists( 'cancelled', $tzfd_app ) ? $tzfd_app['cancelled'] : '';
                if ( ! is_string( $app_status ) || ! in_array( $app_status, array( '', 'Pending' ), true ) ) {
                    return false;
                }

                $flat_key = 'app_status_' . ( $index + 1 );
                if ( array_key_exists( $flat_key, $tzfd_posted_data ) ) {
                    $flat_status = $tzfd_posted_data[ $flat_key ];
                    if ( ! is_string( $flat_status ) ||
                         $flat_status !== $app_status ||
                         ! in_array( $flat_status, array( '', 'Pending' ), true ) ) {
                        return false;
                    }
                }
            }
            return true;
        }

        public static function with_cancelled_status( $tzfd_posted_data ) {
            if ( ! self::is_cancelable_data( $tzfd_posted_data ) ) {
                return false;
            }

            $tzfd_count = count( $tzfd_posted_data['apps'] );
            for ( $index = 0; $index < $tzfd_count; $index++ ) {
                $tzfd_posted_data['apps'][ $index ]['cancelled'] = self::STATUS;
                $tzfd_posted_data[ 'app_status_' . ( $index + 1 ) ] = self::STATUS;
            }
            return $tzfd_posted_data;
        }

        public static function compare_and_swap( $wpdb, $booking_id, $original_serialized ) {
            $booking_id = self::normalize_booking_id( $booking_id );
            if ( ! $booking_id || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ||
                 ! method_exists( $wpdb, 'update' ) || ! is_string( $original_serialized ) ||
                 '' === $original_serialized ) {
                return null;
            }

            $tzfd_posted_data = TZFD_Stored_Data::decode($original_serialized);
            $updated_data = self::with_cancelled_status( $tzfd_posted_data );
            if ( ! is_array( $updated_data ) ) {
                return null;
            }

            return $wpdb->update(
                $wpdb->prefix . 'tzfd_messages',
                array( 'posted_data' => serialize( $updated_data ) ),
                array(
                    'id'          => $booking_id,
                    'posted_data' => $original_serialized,
                ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        }

        public static function append_customer_link( $mail, $customer_email, $url, $format = 'text' ) {
            if ( ! is_array( $mail ) || ! isset( $mail['to'], $mail['message'] ) ||
                 ! is_string( $mail['message'] ) || ! is_string( $url ) || '' === $url ||
                 ! self::is_single_customer_recipient( $mail['to'], $customer_email ) ||
                 ! self::customer_only_headers( isset( $mail['headers'] ) ? $mail['headers'] : '' ) ) {
                return $mail;
            }

            if ( 'html' === $format ) {
                $tzfd_label = function_exists( '__' )
                    ? __( 'Cancel this booking', 'titan-zero-trades-front-desk' )
                    : 'Cancel this booking';
                $mail['message'] .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $tzfd_label ) . '</a></p>';
            } else {
                $tzfd_label = function_exists( '__' )
                    ? __( 'To cancel this booking, open the link and confirm:', 'titan-zero-trades-front-desk' )
                    : 'To cancel this booking, open the link and confirm:';
                $mail['message'] .= "\n\n" . $tzfd_label . "\n" . $url;
            }
            return $mail;
        }

        /** Never disclose a bearer link to recipients added through mail headers. */
        private static function customer_only_headers( $headers ) {
            if ( is_string( $headers ) ) { $headers = array( $headers ); }
            if ( ! is_array( $headers ) ) { return false; }
            foreach ( $headers as $tzfd_key => $line ) {
                if ( ! is_int( $tzfd_key ) || ! is_string( $line ) ||
                    preg_match( '/(^|[\r\n])\s*(?:cc|bcc|to)\s*:/i', $line ) ) { return false; }
            }
            return true;
        }

        public static function is_single_customer_recipient( $to, $customer_email ) {
            if ( is_array( $to ) ) {
                if ( 1 !== count( $to ) ) {
                    return false;
                }
                $recipient = reset( $to );
            } else {
                $recipient = $to;
            }

            return '' !== self::normalize_email( $customer_email ) &&
                   self::normalize_email( $recipient ) === self::normalize_email( $customer_email );
        }

        public function capture_booking( $tzfd_params ) {
            $this->clear_booking_context();
            if ( ! is_array( $tzfd_params ) || ! isset( $tzfd_params['itemnumber'] ) ) {
                return;
            }

            $booking_id = self::normalize_booking_id( $tzfd_params['itemnumber'] );
            if ( ! $booking_id ) {
                return;
            }

            global $wpdb;
            $message_table = $wpdb->prefix . 'tzfd_messages';
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT id, formid, notifyto FROM %i WHERE id = %d", $message_table, $booking_id)
            );
            if ( ! is_object( $row ) || empty( $row->formid ) ) {
                return;
            }

            $customer_email = self::normalize_email( $row->notifyto );
            if ( '' === $customer_email ) {
                return;
            }

            $forms_table = $wpdb->prefix . 'tzfd_forms';
            $tzfd_form = $wpdb->get_row(
                $wpdb->prepare("SELECT id, cu_enable_copy_to_user, cu_emailformat FROM %i WHERE id = %d", $forms_table, (int) $row->formid)
            );
            if ( ! is_object( $tzfd_form ) || 'true' !== (string) $tzfd_form->cu_enable_copy_to_user ) {
                return;
            }

            $this->booking_id     = $booking_id;
            $this->customer_email = $customer_email;
            $this->email_format   = 'html' === (string) $tzfd_form->cu_emailformat ? 'html' : 'text';
        }

        public function filter_customer_mail( $mail ) {
            if ( ! $this->booking_id || '' === $this->customer_email ||
                 ! function_exists( 'wp_salt' ) || ! function_exists( 'admin_url' ) ||
                 ! function_exists( 'add_query_arg' ) ) {
                return $mail;
            }

            $token = self::create_token( $this->booking_id, $this->customer_email, wp_salt( 'auth' ) );
            if ( '' === $token ) {
                return $mail;
            }

            $url = add_query_arg(
                array(
                    'action'     => self::ACTION,
                    'booking_id' => $this->booking_id,
                    'token'      => $token,
                ),
                admin_url( 'admin-post.php' )
            );
            $updated_mail = self::append_customer_link(
                $mail,
                $this->customer_email,
                $url,
                $this->email_format
            );

            if ( $updated_mail !== $mail ) {
                $this->clear_booking_context();
            }
            return $updated_mail;
        }

        public function handle_public_request() {
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            }
            if ( ! headers_sent() ) {
                header( 'Referrer-Policy: no-referrer' );
                header( 'X-Robots-Tag: noindex, nofollow', true );
            }

            $method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
                ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
                : 'GET';
            if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
                $this->respond( 405, 'This request cannot be used to cancel a booking.' );
            }

            // The confirm POST changes data. Its nonce, tied to this booking, is checked before any POST value is read or used.
            if ( 'POST' === $method && ! wp_verify_nonce(
                isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '',
                'tzfd_cancel_' . ( isset( $_POST['booking_id'] ) ? self::normalize_booking_id( sanitize_text_field( wp_unslash( $_POST['booking_id'] ) ) ) : 0 )
            ) ) {
                $this->respond( 403, esc_html__( 'Your confirmation has expired or is not valid. Open the cancellation link from your email again and confirm there.', 'titan-zero-trades-front-desk' ) );
            }

            // GET only shows the form. Both methods are authorised by the per-booking token below.
            $request = 'POST' === $method ? wp_unslash( $_POST ) : wp_unslash( $_GET );
            $booking_id = isset( $request['booking_id'] )
                ? self::normalize_booking_id( sanitize_text_field( $request['booking_id'] ) )
                : 0;
            $token = isset( $request['token'] ) && is_string( $request['token'] )
                ? sanitize_text_field( $request['token'] )
                : '';
            if ( ! $booking_id || '' === $token ) {
                $this->respond( 404, 'This cancellation link is invalid or no longer available.' );
            }

            $row = $this->find_booking( $booking_id );
            if ( ! is_object( $row ) ) {
                $this->respond( 404, 'This cancellation link is invalid or no longer available.' );
            }
            $customer_email = self::normalize_email( $row->notifyto );
            if ( '' === $customer_email ||
                 ! function_exists( 'wp_salt' ) ||
                 ! self::verify_token( $booking_id, $customer_email, $token, wp_salt( 'auth' ) ) ) {
                $this->respond( 404, 'This cancellation link is invalid or no longer available.' );
            }

            $tzfd_posted_data = $this->decode_posted_data( $row->posted_data );
            if ( ! self::is_cancelable_data( $tzfd_posted_data ) ) {
                $this->respond( 409, 'This booking is no longer eligible for cancellation.' );
            }

            if ( 'GET' === $method ) {
                $this->respond(
                    200,
                    'Please confirm that you want to cancel this booking. No change happens until you confirm below.',
                    $booking_id,
                    $token,
                    true
                );
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The POST nonce for this booking was verified above, before this read. The value is sanitized here.
            $confirm = isset( $_POST['confirm_cancel'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_cancel'] ) ) : '';
            if ( '1' !== $confirm ) {
                $this->respond( 400, 'Confirm the cancellation using the button below.' );
            }

            global $wpdb;
            $tzfd_updated = self::compare_and_swap( $wpdb, $booking_id, $row->posted_data );
            if ( null === $tzfd_updated ) {
                $this->respond( 409, 'This booking is no longer eligible for cancellation.' );
            }
            if ( false === $tzfd_updated ) {
                $this->respond( 503, 'Cancellation could not be completed. Contact the booking administrator.' );
            }
            if ( 1 !== $tzfd_updated ) {
                $this->respond( 409, 'The booking changed and could not be cancelled. Contact the booking administrator.' );
            }

            do_action( 'tzfd_update_status', $booking_id, self::STATUS );
            $this->respond( 200, 'Your booking has been cancelled.' );
        }

        private function find_booking( $booking_id ) {
            global $wpdb;
            $message_table = $wpdb->prefix . 'tzfd_messages';
            return $wpdb->get_row(
                $wpdb->prepare("SELECT id, notifyto, posted_data FROM %i WHERE id = %d", $message_table, $booking_id)
            );
        }

        private function decode_posted_data( $serialized ) {
            if ( ! is_string( $serialized ) || '' === $serialized ) {
                return false;
            }
            return TZFD_Stored_Data::decode($serialized);
        }

        private function respond( $status, $tzfd_message, $booking_id = 0, $token = '', $show_form = false ) {
            if ( function_exists( 'status_header' ) ) {
                status_header( $status );
            }
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            }

            $title = function_exists( '__' )
                ? __( 'Booking cancellation', 'titan-zero-trades-front-desk' )
                : 'Booking cancellation';
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<meta name="referrer" content="no-referrer">';
            echo '<title>' . esc_html( $title ) . '</title></head><body><main>';
            echo '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $tzfd_message ) . '</p>';

            if ( $show_form ) {
                $button = function_exists( '__' )
                    ? __( 'Confirm cancellation', 'titan-zero-trades-front-desk' )
                    : 'Confirm cancellation';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
                echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) $booking_id ) . '">';
                echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
                wp_nonce_field( 'tzfd_cancel_' . $booking_id, '_wpnonce', false );
                echo '<button type="submit" name="confirm_cancel" value="1">' . esc_html( $button ) . '</button>';
                echo '</form>';
            }

            echo '</main></body></html>';
            exit;
        }

        private function clear_booking_context() {
            $this->booking_id     = 0;
            $this->customer_email = '';
            $this->email_format   = 'text';
        }
    }
}
