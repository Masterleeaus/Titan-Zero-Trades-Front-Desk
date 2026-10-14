<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/class-tzfd-stored-data.php';


if ( ! class_exists( 'TZFD_Status_Update_Emails' ) ) {
    final class TZFD_Status_Update_Emails {
        const OPTION = 'titan_zero_front_desk_status_update_email_settings';
        const OPTION_GROUP = 'titan_zero_front_desk_status_update_email_settings';
        const PAGE = 'tzfd-status-update-emails';
        const STATUS_HOOK = 'titan_zero_front_desk_booking_status_persisted';

        public function register() {
            add_action( self::STATUS_HOOK, array( $this, 'send_status_email' ), 10, 3 );

            if ( function_exists( 'is_admin' ) && is_admin() ) {
                add_action( 'admin_init', array( $this, 'register_settings' ) );
                add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
            }
        }

        public static function default_settings() {
            return array(
                'subject' => 'Booking status update for booking #%booking_id%',
                'body'    => "%status_update%\n\nBooking reference: #%booking_id%",
            );
        }

        public function sanitize_settings( $input ) {
            $input = is_array( $input ) ? $input : array();
            $subject = isset( $input['subject'] ) && is_string( $input['subject'] )
                ? $input['subject']
                : '';
            $body = isset( $input['body'] ) && is_string( $input['body'] )
                ? $input['body']
                : '';

            if ( function_exists( 'wp_unslash' ) ) {
                $subject = wp_unslash( $subject );
                $body = wp_unslash( $body );
            }

            $subject = function_exists( 'sanitize_text_field' )
                ? sanitize_text_field( $subject )
                : '';
            $body = function_exists( 'sanitize_textarea_field' )
                ? sanitize_textarea_field( $body )
                : '';

            $defaults = self::default_settings();
            if ( '' === trim( $subject ) ) {
                $subject = $defaults['subject'];
            }
            if ( '' === trim( $body ) ) {
                $body = $defaults['body'];
            }

            return array(
                'subject' => $subject,
                'body'    => $body,
            );
        }

        public static function normalize_booking_id( $tzfd_value ) {
            if ( ! is_int( $tzfd_value ) && ! is_string( $tzfd_value ) ) {
                return 0;
            }

            $tzfd_value = (string) $tzfd_value;
            if ( ! preg_match( '/^[0-9]+$/D', $tzfd_value ) ) {
                return 0;
            }

            $digits = ltrim( $tzfd_value, '0' );
            if ( '' === $digits ) {
                return 0;
            }

            $validated = filter_var(
                $digits,
                FILTER_VALIDATE_INT,
                array( 'options' => array( 'min_range' => 1 ) )
            );
            return false === $validated ? 0 : (int) $validated;
        }

        public static function normalize_index_selector( $indexonly ) {
            if ( -1 === $indexonly || '-1' === $indexonly ) {
                return -1;
            }
            if ( is_int( $indexonly ) ) {
                return $indexonly >= 0 ? $indexonly : null;
            }
            if ( ! is_string( $indexonly ) || ! preg_match( '/^(0|[1-9][0-9]*)$/D', $indexonly ) ) {
                return null;
            }

            $validated = filter_var(
                $indexonly,
                FILTER_VALIDATE_INT,
                array( 'options' => array( 'min_range' => 0 ) )
            );
            return false === $validated ? null : (int) $validated;
        }

        public static function decode_posted_data( $serialized ) {
            if ( ! is_string( $serialized ) || '' === $serialized ) {
                return false;
            }

            try {
                // Only saved status fields are needed. Preserve compatibility with
                // unrelated legacy fields without ever returning inert objects.
                $envelope = TZFD_Stored_Data::project( $serialized, array( 'apps' ) );
                if ( ! is_array( $envelope ) || ! isset( $envelope['apps'] ) || ! is_array( $envelope['apps'] ) ) {
                    return false;
                }
                $keys = array( 'apps' );
                for ( $tzfd_i = 1; $tzfd_i <= count( $envelope['apps'] ); $tzfd_i++ ) { $keys[] = 'app_status_' . $tzfd_i; }
                $decoded = TZFD_Stored_Data::project( $serialized, $keys );
            } catch ( Throwable $error ) {
                return false;
            }
            return is_array( $decoded ) ? $decoded : false;
        }

        public static function status_is_saved( $tzfd_posted_data, $status, $indexonly ) {
            if ( ! is_array( $tzfd_posted_data ) || ! is_string( $status ) ||
                 ! isset( $tzfd_posted_data['apps'] ) || ! is_array( $tzfd_posted_data['apps'] ) ||
                 empty( $tzfd_posted_data['apps'] ) ) {
                return false;
            }

            $tzfd_count = count( $tzfd_posted_data['apps'] );
            if ( array_keys( $tzfd_posted_data['apps'] ) !== range( 0, $tzfd_count - 1 ) ) {
                return false;
            }

            $selector = self::normalize_index_selector( $indexonly );
            if ( null === $selector ) {
                return false;
            }

            $indexes = -1 === $selector ? range( 0, $tzfd_count - 1 ) : array( $selector );
            foreach ( $indexes as $index ) {
                if ( ! array_key_exists( $index, $tzfd_posted_data['apps'] ) ||
                     ! is_array( $tzfd_posted_data['apps'][ $index ] ) ) {
                    return false;
                }

                $flat_key = 'app_status_' . ( $index + 1 );
                if ( ! array_key_exists( 'cancelled', $tzfd_posted_data['apps'][ $index ] ) ||
                     ! array_key_exists( $flat_key, $tzfd_posted_data ) ) {
                    return false;
                }

                $nested_status = $tzfd_posted_data['apps'][ $index ]['cancelled'];
                $flat_status = $tzfd_posted_data[ $flat_key ];
                if ( ! is_string( $nested_status ) || ! is_string( $flat_status ) ||
                     $status !== $nested_status || $status !== $flat_status ) {
                    return false;
                }
            }

            return true;
        }

        public static function single_recipient( $recipient ) {
            if ( ! is_string( $recipient ) ) {
                return '';
            }

            $recipient = trim( $recipient );
            if ( '' === $recipient || preg_match( '/[,\s;<>]/', $recipient ) ||
                 ! function_exists( 'is_email' ) || ! is_email( $recipient ) ) {
                return '';
            }
            return $recipient;
        }

        public function send_status_email( $booking_id, $status, $indexonly = '-1' ) {
            if ( ! function_exists( 'is_admin' ) || ! is_admin() ||
                 ! function_exists( 'current_user_can' ) ||
                 ! current_user_can( 'manage_options' ) ||
                 ! function_exists( 'wp_mail' ) ) {
                return;
            }

            $booking_id = self::normalize_booking_id( $booking_id );
            if ( ! $booking_id || ! is_string( $status ) ||
                 ! function_exists( 'sanitize_text_field' ) ) {
                return;
            }

            $normalized_status = sanitize_text_field( $status );
            if ( $normalized_status !== $status ) {
                return;
            }

            global $wpdb;
            if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ||
                 ! is_string( $wpdb->prefix ) || ! method_exists( $wpdb, 'prepare' ) ||
                 ! method_exists( $wpdb, 'get_row' ) ) {
                return;
            }

            $table = $wpdb->prefix . 'tzfd_messages';
            if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
                return;
            }

            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT id, notifyto, posted_data FROM %i WHERE id = %d", $table, $booking_id)
            );
            if ( ! is_object( $row ) ) {
                return;
            }

            $recipient = self::single_recipient( isset( $row->notifyto ) ? $row->notifyto : null );
            $tzfd_posted_data = self::decode_posted_data(
                isset( $row->posted_data ) ? $row->posted_data : null
            );
            if ( '' === $recipient || ! is_array( $tzfd_posted_data ) ||
                 ! self::status_is_saved( $tzfd_posted_data, $status, $indexonly ) ) {
                return;
            }

            $display_status = '' === $status ? 'Approved' : $status;
            $selector = self::normalize_index_selector( $indexonly );
            $partial = -1 !== $selector;
            $scope = $partial ? 'Appointment #' . ( $selector + 1 ) : 'Your booking';
            $status_update = $scope . ' status is now: ' . $display_status . '.';
            if ( $partial ) {
                $status_update .= ' This update applies only to this appointment.';
                foreach ( $tzfd_posted_data['apps'] as $appointment ) {
                    if ( is_array( $appointment ) && isset( $appointment['cancelled'] ) &&
                         'Pending' === $appointment['cancelled'] ) {
                        $status_update .= ' This booking still has appointments pending review.';
                        break;
                    }
                }
                // Legacy templates retain their token, but it must not imply a whole-booking status.
                $display_status = $scope . ': ' . $display_status . ' (selected appointment only)';
            }
            $settings = $this->get_settings();
            $replace = array(
                '%status%'     => $display_status,
                '%booking_id%' => (string) $booking_id,
                '%status_update%' => $status_update,
                '%appointment_index%' => $partial ? (string) ( $selector + 1 ) : 'all',
            );
            $subject = str_replace( array_keys( $replace ), array_values( $replace ), $settings['subject'] );
            $body = str_replace( array_keys( $replace ), array_values( $replace ), $settings['body'] );
            if ( $partial && false === strpos( $settings['body'], '%status_update%' ) ) {
                $body = $status_update . "\n\n" . $body;
            }
            $subject = sanitize_text_field( $subject );
            $body = sanitize_textarea_field( $body );

            wp_mail(
                $recipient,
                $subject,
                $body,
                array( 'Content-Type: text/plain; charset=UTF-8' )
            );
        }

        public function register_settings() {
            if ( ! function_exists( 'register_setting' ) ) {
                return;
            }

            register_setting(
                self::OPTION_GROUP,
                self::OPTION,
                array(
                    'type'              => 'array',
                    'default'           => self::default_settings(),
                    'sanitize_callback' => array( $this, 'sanitize_settings' ),
                    'show_in_rest'      => false,
                )
            );
        }

        public function register_settings_page() {
            if ( ! function_exists( 'add_options_page' ) ) {
                return;
            }

            add_submenu_page(
                '',
                __( 'Titan Zero Trades: Front Desk Status Emails', 'titan-zero-trades-front-desk' ),
                __( 'Titan Zero Trades: Front Desk Status Emails', 'titan-zero-trades-front-desk' ),
                'manage_options',
                self::PAGE,
                array( $this, 'render_settings_page' )
            );
        }

        public function render_settings_page() {
            if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
                return;
            }
            TZFD_Admin_Menu::render_settings_tabs( 'emails' );

            $settings = $this->get_settings();
            echo '<div class="wrap"><h1>' .
                esc_html__( 'Titan Zero Trades: Front Desk Status Emails', 'titan-zero-trades-front-desk' ) .
                '</h1><form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
            settings_fields( self::OPTION_GROUP );
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th scope="row"><label for="tzfd-status-email-subject">' .
                esc_html__( 'Subject', 'titan-zero-trades-front-desk' ) .
                '</label></th><td><input class="regular-text" type="text" id="tzfd-status-email-subject" name="' .
                esc_attr( self::OPTION ) . '[subject]" value="' . esc_attr( $settings['subject'] ) . '"></td></tr>';
            echo '<tr><th scope="row"><label for="tzfd-status-email-body">' .
                esc_html__( 'Message', 'titan-zero-trades-front-desk' ) .
                '</label></th><td><textarea class="large-text" rows="8" id="tzfd-status-email-body" name="' .
                esc_attr( self::OPTION ) . '[body]">' . esc_textarea( $settings['body'] ) .
                '</textarea><p class="description">' .
                esc_html__( 'Plain text only. No appointment or intake details are included.', 'titan-zero-trades-front-desk' ) .
                ' ' . esc_html__( 'Supported placeholders:', 'titan-zero-trades-front-desk' ) .
                ' <code>%status%</code>, <code>%booking_id%</code>, <code>%status_update%</code>, <code>%appointment_index%</code>.' .
                '</p></td></tr>';
            echo '</tbody></table>';
            submit_button();
            echo '</form></div>';
        }

        private function get_settings() {
            $defaults = self::default_settings();
            $stored = function_exists( 'get_option' )
                ? get_option( self::OPTION, $defaults )
                : $defaults;
            $stored = is_array( $stored ) ? $stored : array();

            $subject = isset( $stored['subject'] ) && is_string( $stored['subject'] ) &&
                function_exists( 'sanitize_text_field' )
                ? sanitize_text_field( $stored['subject'] )
                : '';
            $body = isset( $stored['body'] ) && is_string( $stored['body'] ) &&
                function_exists( 'sanitize_textarea_field' )
                ? sanitize_textarea_field( $stored['body'] )
                : '';

            return array(
                'subject' => '' !== trim( $subject ) ? $subject : $defaults['subject'],
                'body'    => '' !== trim( $body ) ? $body : $defaults['body'],
            );
        }
    }
}

