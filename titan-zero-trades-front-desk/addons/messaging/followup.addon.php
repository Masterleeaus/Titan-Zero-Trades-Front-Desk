<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-timed-notices.php';

if( !class_exists( 'TZFD_FollowUp' ) )
{
    /**
     * Follow-up emails sent a set number of hours after each appointment ends.
     * Only approved or attended appointments receive one.
     */
    class TZFD_FollowUp extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-FollowUp";
		protected $name = "FollowUp notifications for bookings";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY  = 'followup';
        const HOOK = 'titan_zero_front_desk_addon_followup_send';

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on emails customers a follow-up a set number of hours after each appointment ends.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'tzfd_process_data', array( $this, 'schedule_followups' ), 40, 1 );
            add_action( self::HOOK, array( $this, 'send_followup' ), 10, 2 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::global_settings_block( self::KEY, self::defaults(), array(
                'hours' => array( 'label' => __( 'Send follow-up after (hours)', 'titan-zero-trades-front-desk' ), 'type' => 'int', 'help' => __( '0 turns follow-ups off.', 'titan-zero-trades-front-desk' ) ),
                'subject' => array( 'label' => __( 'Follow-up email subject', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => '' ),
                'body' => array( 'label' => __( 'Follow-up email message', 'titan-zero-trades-front-desk' ), 'type' => 'textarea', 'help' => __( 'Placeholders:', 'titan-zero-trades-front-desk' ) . ' %booking_id%, %service%, %date%.' ),
            ), __( 'Default for new calendars. Each calendar can change these in its own settings.', 'titan-zero-trades-front-desk' ) );
        }

        public static function defaults()
        {
            return array(
                'hours'   => 0,
                'subject' => 'Thank you for your visit on %date%',
                'body'    => "Hello,\n\nThank you for booking #%booking_id% for %service% on %date%. We hope it went well.\n\nPlease get in touch if we can help with anything else.",
            );
        }

        private static function specs()
        {
            return array(
                'hours'   => array( 'label' => 'Send follow-up hours after the end', 'type' => 'int',
                                    'help'  => '0 turns follow-ups off. Changes apply to bookings saved after the change.' ),
                'subject' => array( 'label' => 'Email subject', 'type' => 'text',
                                    'help'  => 'Placeholders: %booking_id% %formname% %service% %date% %start% %end%' ),
                'body'    => array( 'label' => 'Email body', 'type' => 'textarea',
                                    'help'  => 'Same placeholders as the subject. Plain text is sent.' ),
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
            TZFD_Addon_Support::render_table( 'tzfd_' . self::KEY . '_', self::specs(), $values );
            return '';
        } // end get_addon_form_settings

        /************************ HOOKS *****************************/

        public function schedule_followups( $tzfd_params )
        {
            TZFD_Timed_Notices::schedule_booking( (array) $tzfd_params, self::KEY, self::HOOK, 'end', 1, self::defaults() );
        }

        /** Cron callback. Approved and attended appointments only. */
        public function send_followup( $booking_id, $index )
        {
            return TZFD_Timed_Notices::deliver( self::KEY, $booking_id, $index, array( '', 'Attended' ), self::defaults() );
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $tzfd_FollowUp_obj = new TZFD_FollowUp();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $tzfd_FollowUp_obj->get_addon_id() ] = $tzfd_FollowUp_obj;
}
