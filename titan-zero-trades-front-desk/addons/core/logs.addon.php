<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/../../includes/class-tzfd-request-scalar.php';
require_once __DIR__.'/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';

if( !class_exists( 'TZFD_ActivityLogsWidget' ) )
{
    /**
     * Keeps a log of booking changes (created, status changed, deleted) and shows
     * the most recent entries in the calendars list. Keeps the newest 500 entries.
     */
    class TZFD_ActivityLogsWidget extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
        protected $addonID = "tzfd-ActivityLogsWidget";
        protected $name = 'Activity Logs';
        protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const OPTION = 'titan_zero_front_desk_activity_log';
        const MAX_ENTRIES = 500;
        const SHOW_ENTRIES = 100;

        /************************ CONSTRUCT *****************************/
        public function __construct()
        {
            $this->description = $this->tr_apply("The add-on logs booking additions, status changes and deletions, and shows the latest entries.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'tz_fd_process_data', array( $this, 'log_created' ), 40, 1 );
            add_action( 'tzfd_update_status', array( $this, 'log_status' ), 40, 2 );
            add_action( 'tzfd_item_deleted', array( $this, 'log_deleted' ), 40, 1 );
        } // End __construct

        /************************ LOG STORAGE *****************************/

        public static function entries()
        {
            $log = get_option( self::OPTION, array() );
            return is_array( $log ) ? $log : array();
        }

        public static function record( $event, $booking_id, $formid, $detail = '' )
        {
            $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
            $tzfd_local_entry = array(
                'time'    => current_time( 'mysql' ),
                'event'   => (string) $event,
                'booking' => (int) $booking_id,
                'form'    => (int) $formid,
                'user'    => $user_id,
                'detail'  => (string) $detail,
            );
            $log = TZFD_Addon_Support::append_log( self::entries(), $tzfd_local_entry, self::MAX_ENTRIES );
            update_option( self::OPTION, $log, false );
        }

        /************************ HOOKS *****************************/

        public function log_created( $tzfd_local_params )
        {
            if ( ! is_array( $tzfd_local_params ) ) { return; }
            $tzfd_local_count = isset( $tzfd_local_params['apps'] ) && is_array( $tzfd_local_params['apps'] ) ? count( $tzfd_local_params['apps'] ) : 0;
            $name  = isset( $tzfd_local_params['formname'] ) && is_string( $tzfd_local_params['formname'] ) ? $tzfd_local_params['formname'] : '';
            self::record( 'created', isset( $tzfd_local_params['itemnumber'] ) ? $tzfd_local_params['itemnumber'] : 0, isset( $tzfd_local_params['formid'] ) ? $tzfd_local_params['formid'] : 0,
                trim( $name . ' (' . $tzfd_local_count . ' appointment' . ( 1 === $tzfd_local_count ? '' : 's' ) . ')' ) );
        }

        public function log_status( $booking_id, $status = '' )
        {
            $tzfd_label = ( '' === (string) $status ) ? 'Approved' : (string) $status;
            self::record( 'status', $booking_id, 0, 'Status set to ' . $tzfd_label );
        }

        public function log_deleted( $booking_id )
        {
            self::record( 'deleted', $booking_id, 0, '' );
        }

        /************************ SETTINGS AND VIEWER *****************************/

        public function get_addon_settings()
        {
            $action = 'tz_fd_global_' . self::OPTION;
            if ( isset( $_POST['tz_fd_clear_log'] ) && isset( $_POST['_tz_fd_nonce'] ) && is_string( $_POST['_tz_fd_nonce'] ) && current_user_can( 'tz_fd_access' ) &&
                 wp_verify_nonce( tzfd_request_text( 'post', '_tz_fd_nonce' ), $action ) ) {
                update_option( self::OPTION, array(), false );
            }

            $entries = array_reverse( self::entries() );
            $shown   = array_slice( $entries, 0, self::SHOW_ENTRIES );

            ob_start();
            echo '<div class="tzfd-addons-container"><h2>' . esc_html( $this->name ) . '</h2>';
            /* translators: 1: number of entries shown, 2: total entries in the log, 3: maximum entries the log keeps. */
            echo '<p>' . esc_html( sprintf( __( 'Showing the latest %1$d of %2$d entries. The log keeps the newest %3$d.', 'titan-zero-trades-front-desk' ), count( $shown ), count( $entries ), self::MAX_ENTRIES ) ) . '</p>';
            if ( empty( $shown ) ) {
                echo '<p><em>' . esc_html__( 'No activity has been logged yet.', 'titan-zero-trades-front-desk' ) . '</em></p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Event', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Booking', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Calendar', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'User', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Details', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
                foreach ( $shown as $tzfd_local_entry ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( isset( $tzfd_local_entry['time'] ) ? $tzfd_local_entry['time'] : '' ) . '</td>';
                    echo '<td>' . esc_html( self::event_label( isset( $tzfd_local_entry['event'] ) ? $tzfd_local_entry['event'] : '' ) ) . '</td>';
                    echo '<td>#' . esc_html( (string) ( isset( $tzfd_local_entry['booking'] ) ? (int) $tzfd_local_entry['booking'] : 0 ) ) . '</td>';
                    echo '<td>' . esc_html( (string) ( isset( $tzfd_local_entry['form'] ) ? (int) $tzfd_local_entry['form'] : 0 ) ) . '</td>';
                    echo '<td>' . esc_html( self::user_label( isset( $tzfd_local_entry['user'] ) ? (int) $tzfd_local_entry['user'] : 0 ) ) . '</td>';
                    echo '<td>' . esc_html( isset( $tzfd_local_entry['detail'] ) && is_scalar( $tzfd_local_entry['detail'] ) ? (string) $tzfd_local_entry['detail'] : '' ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '<form method="post" style="margin-top:12px;">';
            wp_nonce_field( $action, '_tz_fd_nonce' );
            echo '<input type="hidden" name="tz_fd_clear_log" value="1" />';
            submit_button( __( 'Clear log', 'titan-zero-trades-front-desk' ), 'secondary', 'submit', false );
            echo '</form></div>';
            return ob_get_clean();
        }

        private static function event_label( $event )
        {
            switch ( $event ) {
                case 'created': return __( 'Booking created', 'titan-zero-trades-front-desk' );
                case 'status':  return __( 'Status changed', 'titan-zero-trades-front-desk' );
                case 'deleted': return __( 'Booking deleted', 'titan-zero-trades-front-desk' );
            }
            return (string) $event;
        }

        private static function user_label( $user_id )
        {
            if ( $user_id <= 0 ) { return __( 'Visitor or system', 'titan-zero-trades-front-desk' ); }
            $tzfd_user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
            return ( is_object( $tzfd_user ) && isset( $tzfd_user->user_login ) ) ? (string) $tzfd_user->user_login : '#' . $user_id;
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $TZFD_ActivityLogsWidget_obj = new TZFD_ActivityLogsWidget();

    // Add addon object to the objects list
    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $TZFD_ActivityLogsWidget_obj->get_addon_id() ] = $TZFD_ActivityLogsWidget_obj;
}
