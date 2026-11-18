<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/../includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/class-tzfd-stored-data.php';
require_once __DIR__ . '/class-tzfd-capacity-lock.php';
require_once __DIR__ . '/class-tzfd-customer-public-owner.php';
require_once __DIR__ . '/class-tzfd-customer-transaction.php';
require_once __DIR__ . '/class-tzfd-public-confirmation-guard.php';


if ( ! class_exists( 'TZFD_Customer_Cancellation' ) ) {
    class TZFD_Customer_Cancellation {
        const STATUS = 'Cancelled by customer';
        const ACTION = 'titan_zero_front_desk_customer_cancel';

        private $booking_id = 0;
        private $customer_email = '';
        private $email_format = 'text';

        public function register() {
            add_action( 'tz_fd_process_data', array( $this, 'capture_booking' ), 1000, 1 );
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

        /** Cancellation links are valid for this long from issue. */
        const TOKEN_TTL = 1209600; // 14 days

        /**
         * Version of the booking a link was issued for. Any change to the stored booking (a reschedule rewrites
         * posted_data and data, a cancellation rewrites posted_data) changes it, which invalidates older links.
         * The row must carry posted_data, data, notifyto and formid.
         */
        public static function row_version( $row ) {
            return hash( 'sha256', serialize( array(
                'cancel',
                isset( $row->posted_data ) ? $row->posted_data : null,
                isset( $row->data ) ? $row->data : null,
                isset( $row->notifyto ) ? $row->notifyto : null,
                isset( $row->formid ) ? (int) $row->formid : 0,
            ) ) );
        }

        /** Expiry timestamp for a link issued now. */
        public static function new_expiry( $now = null ) {
            $now = null === $now ? time() : (int) $now;
            return $now + self::TOKEN_TTL;
        }

        /** True while a link issued at $issued is still inside its fixed 14 days. Viewing never changes $issued. */
        public static function is_live( $issued, $now = null ) {
            $issued = (int) $issued;
            $now    = null === $now ? time() : (int) $now;
            return $issued > 0 && $now <= $issued + self::TOKEN_TTL;
        }

        public static function normalize_expiry( $expires ) {
            if ( ! is_int( $expires ) && ! ( is_string( $expires ) && ctype_digit( $expires ) ) ) {
                return 0;
            }
            return (int) $expires > 0 ? (int) $expires : 0;
        }

        public static function create_token( $booking_id, $email, $secret, $version = '', $expires = 0 ) {
            $booking_id = self::normalize_booking_id( $booking_id );
            $email      = self::normalize_email( $email );
            $expires    = self::normalize_expiry( $expires );
            if ( ! $booking_id || '' === $email || ! is_string( $secret ) || '' === $secret ||
                 ! is_string( $version ) || ! preg_match( '/^[a-f0-9]{64}$/D', $version ) || ! $expires ) {
                return '';
            }
            return hash_hmac(
                'sha256',
                'titan-zero-trades-front-desk/customer-cancellation/v2|' . $booking_id . '|' . $email . '|' . $version . '|' . $expires,
                $secret
            );
        }

        /** True only for a well-formed token, issued for this booking version, that has not expired. */
        public static function verify_token( $booking_id, $email, $token, $secret, $version = '', $expires = 0, $now = null ) {
            if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
                return false;
            }
            $expires = self::normalize_expiry( $expires );
            $now     = null === $now ? time() : (int) $now;
            if ( ! $expires || $expires < $now ) {
                return false;
            }
            $expected = self::create_token( $booking_id, $email, $secret, $version, $expires );
            return '' !== $expected && hash_equals( $expected, $token );
        }

        public static function is_cancelable_data( $tzfd_posted_data ) {
            if ( ! is_array( $tzfd_posted_data ) || isset( $tzfd_posted_data['lock'] ) ||
                 ! isset( $tzfd_posted_data['apps'] ) || ! is_array( $tzfd_posted_data['apps'] ) ||
                 empty( $tzfd_posted_data['apps'] ) ) {
                return false;
            }

            $tzfd_local_count = count( $tzfd_posted_data['apps'] );
            for ( $index = 0; $index < $tzfd_local_count; $index++ ) {
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

            $tzfd_local_count = count( $tzfd_posted_data['apps'] );
            for ( $index = 0; $index < $tzfd_local_count; $index++ ) {
                $tzfd_posted_data['apps'][ $index ]['cancelled'] = self::STATUS;
                $tzfd_posted_data[ 'app_status_' . ( $index + 1 ) ] = self::STATUS;
            }
            return $tzfd_posted_data;
        }

        public static function compare_and_swap( $wpdb, $booking_id, $original_serialized, $expected_row = null ) {
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

            // Public cancellation must match the entire snapshot authenticated by its
            // bearer link. A change to form, recipient or appointment text must not
            // allow an old link to cancel a different booking state. Use BINARY for
            // byte-exact comparison regardless of the site's SQL collation.
            if ( null !== $expected_row ) {
                if ( ! is_object( $expected_row ) || ! isset( $expected_row->formid, $expected_row->notifyto, $expected_row->data ) ||
                     (int) $expected_row->formid < 1 || ! is_string( $expected_row->notifyto ) ||
                     ! is_string( $expected_row->data ) || ! isset( $expected_row->posted_data ) ||
                     $expected_row->posted_data !== $original_serialized ) {
                    return null;
                }
                return $wpdb->query( $wpdb->prepare(
                    'UPDATE %i SET posted_data=%s WHERE id=%d AND formid=%d AND BINARY posted_data=BINARY %s AND BINARY data=BINARY %s AND BINARY notifyto=BINARY %s',
                    $wpdb->prefix . TZFD_Tables::BOOKINGS, serialize( $updated_data ), $booking_id,
                    (int) $expected_row->formid, $original_serialized, $expected_row->data, $expected_row->notifyto
                ) );
            }
            // Legacy three-argument internal callers retain the original contract.
            return $wpdb->update(
                $wpdb->prefix . TZFD_Tables::BOOKINGS,
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
                    : __( 'Cancel this booking', 'titan-zero-trades-front-desk' );
                $mail['message'] .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $tzfd_label ) . '</a></p>';
            } else {
                $tzfd_label = function_exists( '__' )
                    ? __( 'To cancel this booking, open the link and confirm:', 'titan-zero-trades-front-desk' )
                    : __( 'To cancel this booking, open the link and confirm:', 'titan-zero-trades-front-desk' );
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

        public function capture_booking( $tzfd_local_params ) {
            $this->clear_booking_context();
            if ( ! is_array( $tzfd_local_params ) || ! isset( $tzfd_local_params['itemnumber'] ) ) {
                return;
            }

            $booking_id = self::normalize_booking_id( $tzfd_local_params['itemnumber'] );
            if ( ! $booking_id ) {
                return;
            }

            // Booking notification enrichment is optional. A failed lookup must not
            // break the primary booking-confirmation email or retain a previous
            // booking's recipient in this shared hook object.
            try {
                global $wpdb;
                $message_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, formid, notifyto FROM %i WHERE id = %d', $message_table, $booking_id ) );
                if ( ! empty( $wpdb->last_error ) || ! is_object( $row ) || empty( $row->formid ) ) {
                    return;
                }
                $customer_email = self::normalize_email( $row->notifyto );
                if ( '' === $customer_email ) {
                    return;
                }
                $forms_table = $wpdb->prefix . TZFD_Tables::FORMS;
                $tzfd_local_form = $wpdb->get_row( $wpdb->prepare(
                    'SELECT id, cu_enable_copy_to_user, cu_emailformat FROM %i WHERE id = %d',
                    $forms_table, (int) $row->formid
                ) );
                if ( ! empty( $wpdb->last_error ) || ! is_object( $tzfd_local_form ) ||
                     'true' !== (string) $tzfd_local_form->cu_enable_copy_to_user ) {
                    return;
                }
                $this->booking_id     = $booking_id;
                $this->customer_email = $customer_email;
                $this->email_format   = 'html' === (string) $tzfd_local_form->cu_emailformat ? 'html' : 'text';
            } catch ( Throwable $tzfd_error ) {
                $this->clear_booking_context();
            }
        }

        public function filter_customer_mail( $mail ) {
            if ( ! $this->booking_id || '' === $this->customer_email ||
                 ! function_exists( 'wp_salt' ) || ! function_exists( 'admin_url' ) ||
                 ! function_exists( 'add_query_arg' ) ) {
                return $mail;
            }

            // The link belongs only to the customer's copy. Other wp_mail calls (for example the admin copy)
            // pass through untouched and keep the context for the customer's message.
            if ( ! is_array( $mail ) || ! isset( $mail['to'] ) ||
                 ! self::is_single_customer_recipient( $mail['to'], $this->customer_email ) ) {
                return $mail;
            }
            // A secondary link must never prevent the base wp_mail call. Clear
            // context once the customer copy is handled, even on failure, so the next unrelated email cannot inherit it.
            try {
                $row = $this->find_booking( $this->booking_id );
                if ( ! is_object( $row ) ) {
                    return $mail;
                }
                $expires = self::new_expiry();
                $token   = self::create_token( $this->booking_id, $this->customer_email, wp_salt( 'auth' ), self::row_version( $row ), $expires );
                if ( '' === $token ) {
                    return $mail;
                }
                $url = add_query_arg(
                    array(
                        'action'     => self::ACTION,
                        'booking_id' => $this->booking_id,
                        'expires'    => $expires,
                        'token'      => $token,
                    ),
                    admin_url( 'admin-post.php' )
                );
                return self::append_customer_link( $mail, $this->customer_email, $url, $this->email_format );
            } catch ( Throwable $tzfd_error ) {
                return $mail;
            } finally {
                $this->clear_booking_context();
            }
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
                ? strtoupper( tzfd_request_text( 'server', 'REQUEST_METHOD' ) )
                : 'GET';
            if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
                $this->respond( 405, __( 'This request cannot be used to cancel a booking.', 'titan-zero-trades-front-desk' ) );
            }

            // The POST mutation gate is unconditional: neither an absent nonce nor a
            // malformed booking ID can bypass verification. GET remains a read-only preview.
            if ( 'POST' === $method ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST nonce (wp_verify_nonce, action tz_fd_cancel_<booking_id>) is verified inside TZFD_Public_Confirmation_Guard::authorized() before any POST field is used.
                if ( ! TZFD_Public_Confirmation_Guard::authorized( $method, $_POST, 'tz_fd_cancel_' ) ) {
                    $this->respond( 403, __( 'Your confirmation has expired or is not valid. Open the cancellation link from your email again and confirm there.', 'titan-zero-trades-front-desk' ) );
                }
            }

            // GET only shows the form. Both methods are authorised by the per-booking token below.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- POST is nonce-checked by the guard above; GET is a read-only preview authorised by the per-booking token below.
            $request = 'POST' === $method ? wp_unslash( $_POST ) : wp_unslash( $_GET );
            $booking_id = isset( $request['booking_id'] ) && ( is_string( $request['booking_id'] ) || is_int( $request['booking_id'] ) )
                ? self::normalize_booking_id( sanitize_text_field( (string) $request['booking_id'] ) )
                : 0;
            $token = isset( $request['token'] ) && is_string( $request['token'] )
                ? sanitize_text_field( $request['token'] )
                : '';
            $expires = isset( $request['expires'] ) && ( is_string( $request['expires'] ) || is_int( $request['expires'] ) )
                ? self::normalize_expiry( sanitize_text_field( (string) $request['expires'] ) ) : 0;
            if ( ! $booking_id || '' === $token || ! $expires ) {
                $this->respond( 404, __( 'This cancellation link is invalid or no longer available.', 'titan-zero-trades-front-desk' ) );
            }

            $tzfd_lookup = self::public_booking_lookup( $GLOBALS['wpdb'], $booking_id );
            if ( 'unavailable' === $tzfd_lookup['status'] ) {
                $this->respond( 503, __( 'Cancellation is temporarily unavailable. Please try again.', 'titan-zero-trades-front-desk' ) );
            }
            $row = $tzfd_lookup['row'];
            if ( ! is_object( $row ) ) {
                $this->respond( 404, __( 'This cancellation link is invalid or no longer available.', 'titan-zero-trades-front-desk' ) );
            }
            $customer_email = self::normalize_email( $row->notifyto );
            // The token is bound to the booking's current version and to its expiry, so a reschedule or an
            // edit, or the passing of the expiry time, makes the link refuse.
            if ( '' === $customer_email ||
                 ! function_exists( 'wp_salt' ) ||
                 ! self::verify_token( $booking_id, $customer_email, $token, wp_salt( 'auth' ), self::row_version( $row ), $expires ) ) {
                $this->respond( 404, __( 'This cancellation link is invalid or no longer available.', 'titan-zero-trades-front-desk' ) );
            }

            // A valid historical email link must not cross a changed company boundary.
            $tzfd_owner = self::public_form_ownership( $row );
            if ( 'unavailable' === $tzfd_owner['status'] ) {
                $this->respond( 503, __( 'Cancellation is temporarily unavailable. Please try again.', 'titan-zero-trades-front-desk' ) );
            }
            $company = $tzfd_owner['company'];
            if ( 'matched' !== $tzfd_owner['status'] ) {
                $this->respond( 404, __( 'This cancellation link is invalid or no longer available.', 'titan-zero-trades-front-desk' ) );
            }
            $tzfd_posted_data = $this->decode_posted_data( $row->posted_data );
            if ( ! self::is_cancelable_data( $tzfd_posted_data ) ) {
                $this->respond( 409, __( 'This booking is no longer eligible for cancellation.', 'titan-zero-trades-front-desk' ) );
            }

            if ( 'GET' === $method ) {
                $this->respond(
                    200,
                    'Please confirm that you want to cancel this booking. No change happens until you confirm below.',
                    $booking_id,
                    $token,
                    true,
                    $expires
                );
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The POST nonce for this booking was verified above, before this read. The value is sanitized here.
            $confirm = isset( $_POST['confirm_cancel'] ) ? tzfd_request_text( 'post', 'confirm_cancel' ) : '';
            if ( '1' !== $confirm ) {
                $this->respond( 400, __( 'Confirm the cancellation using the button below.', 'titan-zero-trades-front-desk' ) );
            }

            $outcome = self::commit_verified( $row, $booking_id, $company );
            if ( 'lock' === $outcome ) {
                $this->respond( 503, __( 'Cancellation could not be checked safely. Your booking has not changed. Please try again.', 'titan-zero-trades-front-desk' ) );
            }
            if ( 'conflict' === $outcome ) {
                $this->respond( 409, __( 'This booking changed and could not be cancelled.', 'titan-zero-trades-front-desk' ) );
            }
            if ( 'ok' !== $outcome ) {
                $this->respond( 503, __( 'Cancellation could not be confirmed safely. Check your booking status before retrying.', 'titan-zero-trades-front-desk' ) );
            }
            $this->respond( 200, __( 'Your booking has been cancelled.', 'titan-zero-trades-front-desk' ) );
        }

        /**
         * Cancels a booking whose signed link has already been verified: capacity lock, compare-and-swap and the
         * customer event staged in one transaction. Shared by the public confirm POST and the SMS two-step confirmation.
         * Returns 'ok', 'lock', 'conflict' or 'failed'. The caller maps each outcome to its own response.
         */
        public static function commit_verified( $row, $booking_id, $company ) {
            global $wpdb;
            // Share the same per-form capacity mutex used by rescheduling,
            // intake, and approval. Never mutate calendar state if the lock
            // cannot be acquired, including on storage/connection failure.
            $tzfd_capacity_lock = TZFD_Capacity_Lock::acquire( $wpdb, (int) $row->formid );
            if ( false === $tzfd_capacity_lock ) {
                return 'lock';
            }
            $tzfd_committed = false;
            $tzfd_result = 'failure';
            try {
                if ( ! TZFD_Customer_Transaction::begin( $wpdb ) ) {
                    $tzfd_result = 'storage';
                } else {
                    try {
                        // Compare-and-swap and notification staging must commit together.
                        $tzfd_local_updated = self::compare_and_swap( $wpdb, $booking_id, $row->posted_data, $row );
                        if ( 1 === $tzfd_local_updated ) {
                            if ( TZFD_Customer_Event_Outbox::stage( $company, $booking_id, (int) $row->formid,
                                'cancelled', self::row_version( $row ) ) ) {
                                $tzfd_result = TZFD_Customer_Transaction::commit( $wpdb );
                                $tzfd_committed = 'committed' === $tzfd_result;
                                if ( ! $tzfd_committed ) { $tzfd_result = 'commit_unknown'; }
                            } else { $tzfd_result = 'stage'; }
                        } else { $tzfd_result = false === $tzfd_local_updated ? 'write' : 'conflict'; }
                    } catch ( Throwable $tzfd_error ) { $tzfd_result = 'write'; }
                    if ( ! $tzfd_committed && 'commit_unknown' !== $tzfd_result ) { TZFD_Customer_Transaction::rollback( $wpdb ); }
                }
            } finally {
                TZFD_Capacity_Lock::release( $wpdb, $tzfd_capacity_lock );
            }
            if ( ! $tzfd_committed ) {
                return 'conflict' === $tzfd_result ? 'conflict' : 'failed';
            }
            TZFD_Customer_Event_Outbox::schedule();
            return 'ok';
        }

        /**
         * Public signed-link lookups must not expose database exceptions or use stale
         * last_error state as evidence of a valid booking. The caller distinguishes
         * infrastructure failure (503) from a genuinely absent record (404).
         */
        public static function public_booking_lookup( $database, $booking_id ) {
            $missing = array( 'status' => 'missing', 'row' => null );
            if ( ! is_object( $database ) || ! isset( $database->prefix ) ||
                 ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ) {
                return array( 'status' => 'unavailable', 'row' => null );
            }
            try {
                $query = $database->prepare(
                    'SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id = %d',
                    $database->prefix . TZFD_Tables::BOOKINGS, $booking_id
                );
                if ( ! is_string( $query ) || '' === $query ) {
                    return array( 'status' => 'unavailable', 'row' => null );
                }
                $row = $database->get_row( $query );
                if ( ! empty( $database->last_error ) ) {
                    return array( 'status' => 'unavailable', 'row' => null );
                }
            } catch ( Throwable $error ) {
                return array( 'status' => 'unavailable', 'row' => null );
            }
            return is_object( $row ) ? array( 'status' => 'found', 'row' => $row ) : $missing;
        }

        /** Fail closed if Workspace owner resolution is unavailable or throws. */
        public static function public_form_ownership( $row ) {
            if ( ! is_object( $row ) || ! isset( $row->formid ) ) {
                return array( 'status' => 'mismatch', 'company' => 0 );
            }
            // The caller has already verified the signed, booking-version-bound
            // customer link. A public request need not have a Workspace session.
            return TZFD_Customer_Public_Owner::resolve( $row->formid );
        }

        private function find_booking( $booking_id ) {
            global $wpdb;
            $message_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
            return $wpdb->get_row(
                $wpdb->prepare("SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id = %d", $message_table, $booking_id)
            );
        }

        private function decode_posted_data( $serialized ) {
            if ( ! is_string( $serialized ) || '' === $serialized ) {
                return false;
            }
            return TZFD_Stored_Data::decode($serialized);
        }

        private function respond( $status, $tzfd_message, $booking_id = 0, $token = '', $show_form = false, $expires = 0 ) {
            if ( function_exists( 'status_header' ) ) {
                status_header( $status );
            }
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            }

            $title = function_exists( '__' )
                ? __( 'Booking cancellation', 'titan-zero-trades-front-desk' )
                : __( 'Booking cancellation', 'titan-zero-trades-front-desk' );
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<meta name="referrer" content="no-referrer">';
            echo '<title>' . esc_html( $title ) . '</title></head><body><main>';
            echo '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $tzfd_message ) . '</p>';

            if ( $show_form ) {
                $button = function_exists( '__' )
                    ? __( 'Confirm cancellation', 'titan-zero-trades-front-desk' )
                    : __( 'Confirm cancellation', 'titan-zero-trades-front-desk' );
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
                echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) $booking_id ) . '">';
                echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
                echo '<input type="hidden" name="expires" value="' . esc_attr( (string) $expires ) . '">';
                wp_nonce_field( 'tz_fd_cancel_' . $booking_id, '_wpnonce', false );
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
