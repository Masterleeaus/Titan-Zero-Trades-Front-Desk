<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-timed-notices.php';

if( !class_exists( 'TZFD_Reminder' ) )
{
    /**
     * Reminder emails sent a set number of hours before each approved appointment.
     * Configured per calendar in the form editor.
     */
    class TZFD_Reminder extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-Reminder";
		protected $name = "Reminder notifications for bookings";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY  = 'reminder';
        const HOOK = 'titan_zero_front_desk_addon_reminder_send';

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on emails customers a reminder a set number of hours before each approved appointment.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'tzfd_process_data', array( $this, 'schedule_reminders' ), 40, 1 );
            add_action( self::HOOK, array( $this, 'send_reminder' ), 10, 3 );
            // A customer reschedule or a status change (approval, cancellation) re-queues the reminders.
            add_action( 'tzfd_booking_time_changed', array( $this, 'refresh_after_move' ), 20, 3 );
            add_action( 'tzfd_update_status', array( $this, 'refresh_after_status' ), 60, 2 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::global_settings_block( self::KEY, self::defaults(), array(
                'hours' => array( 'label' => __( 'Send reminder before (hours)', 'titan-zero-trades-front-desk' ), 'type' => 'int', 'help' => __( '0 turns reminders off.', 'titan-zero-trades-front-desk' ) ),
                'subject' => array( 'label' => __( 'Reminder email subject', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => '' ),
                'body' => array( 'label' => __( 'Reminder email message', 'titan-zero-trades-front-desk' ), 'type' => 'textarea', 'help' => __( 'Placeholders:', 'titan-zero-trades-front-desk' ) . ' %booking_id%, %service%, %date%, %start%, %end%.' ),
            ), __( 'Default for new calendars. Each calendar can change these in its own settings.', 'titan-zero-trades-front-desk' ) );
        }

        public static function defaults()
        {
            return array(
                'hours'   => 0,
                'subject' => 'Reminder: your appointment on %date% at %start%',
                'body'    => "Hello,\n\nThis is a reminder of your booking #%booking_id% for %service% on %date% from %start% to %end%.\n\nThank you.",
            );
        }

        private static function specs()
        {
            return array(
                'hours'   => array( 'label' => __( 'Send reminder hours before', 'titan-zero-trades-front-desk' ), 'type' => 'int',
                                    'help'  => '0 turns reminders off. Changes apply to bookings saved after the change.' ),
                'subject' => array( 'label' => __( 'Email subject', 'titan-zero-trades-front-desk' ), 'type' => 'text',
                                    'help'  => __( 'Placeholders:', 'titan-zero-trades-front-desk' ) . ' %booking_id% %formname% %service% %date% %start% %end%' ),
                'body'    => array( 'label' => __( 'Email body', 'titan-zero-trades-front-desk' ), 'type' => 'textarea',
                                    'help'  => __( 'Same placeholders as the subject. Plain text is sent.', 'titan-zero-trades-front-desk' ) ),
            );
        }

        public function get_addon_form_settings( $form_id )
        {
            $defaults = self::defaults();
            $request  = TZFD_Addon_Support::form_request( self::KEY, $form_id );
            if ( null !== $request ) {
                TZFD_Addon_Support::save_form_settings( self::KEY, $form_id, $defaults, $request );
            }
            $values = TZFD_Addon_Support::form_settings( self::KEY, $form_id, $defaults );

            echo '<h3>' . esc_html( $this->name ) . '</h3>';
            echo '<input type="hidden" name="TZFD_' . esc_attr( self::KEY ) . '_id" value="' . esc_attr( (string) (int) $form_id ) . '" />';
            TZFD_Addon_Support::render_table( 'tz_fd_' . self::KEY . '_', self::specs(), $values );
            return '';
        } // end get_addon_form_settings

        /************************ HOOKS *****************************/

        /** Called after a booking is stored: schedule one reminder per appointment. */
        public function schedule_reminders( $tzfd_local_params )
        {
            TZFD_Timed_Notices::schedule_booking( (array) $tzfd_local_params, self::KEY, self::HOOK, 'start', -1, self::defaults() );
        }

        /** Booking time changed: drop the queued reminders for the old slot and queue them for the new one. */
        public function refresh_after_move( $booking_id, $form_id, $company_id )
        {
            TZFD_Timed_Notices::clear_booking( self::HOOK, $booking_id, $company_id );
            $this->queue_for_booking( $booking_id, $company_id );
        }

        /** Status changed (for example approved or cancelled): rebuild the queue. Delivery still checks the status. */
        public function refresh_after_status( $booking_id, $status )
        {
            global $wpdb;
            $booking_id = (int) $booking_id;
            if ( $booking_id < 1 || ! is_object( $wpdb ) ) { return; }
            $company_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT company_id FROM %i WHERE id=%d",
                $wpdb->prefix . TZFD_Tables::BOOKINGS,
                $booking_id
            ) );
            if ( $company_id < 1 ) { return; }
            TZFD_Timed_Notices::clear_booking( self::HOOK, $booking_id, $company_id );
            $this->queue_for_booking( $booking_id, $company_id );
        }

        /** Reads the booking's current slots and queues one reminder per appointment, scoped to its company. */
        private function queue_for_booking( $booking_id, $company_id )
        {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, formid, posted_data FROM %i WHERE id=%d AND company_id=%d",
                $wpdb->prefix . TZFD_Tables::BOOKINGS,
                (int) $booking_id,
                (int) $company_id
            ) );
            if ( ! is_object( $row ) ) { return; }
            $tzfd_data = TZFD_Stored_Data::decode_booking( $row->posted_data );
            if ( ! is_array( $tzfd_data ) || ! isset( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) ) { return; }
            TZFD_Timed_Notices::schedule_booking( array( 'itemnumber' => (int) $row->id, 'formid' => (int) $row->formid, 'apps' => $tzfd_data['apps'] ),
                self::KEY, self::HOOK, 'start', -1, self::defaults(), (int) $company_id );
        }

        /** Cron callback. Sends only to approved appointments that are still booked. */
        public function send_reminder( $booking_id, $index, $company_id = 0 )
        {
            return TZFD_Timed_Notices::deliver( self::KEY, $booking_id, $index, array( '' ), self::defaults(), $company_id );
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $tzfd_Reminder_obj = new TZFD_Reminder();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $tzfd_Reminder_obj->get_addon_id() ] = $tzfd_Reminder_obj;
}
