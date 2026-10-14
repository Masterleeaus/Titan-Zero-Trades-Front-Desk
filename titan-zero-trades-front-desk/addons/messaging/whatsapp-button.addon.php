<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';

if( !class_exists( 'TZFD_WhatsappButton' ) )
{
    /**
     * Shows a "chat on WhatsApp" link under the booking form. Opens a chat with the
     * configured number and a prefilled message. No API account is needed.
     */
    class TZFD_WhatsappButton extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-WhatsappButton";
		protected $name = "WhatsApp open chat button";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY = 'whatsapp';

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on adds a WhatsApp chat button below the booking form.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_filter( 'tzfd_the_form', array( $this, 'append_button' ), 99, 2 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::global_settings_block( self::KEY, self::defaults(), array(
                'number' => array( 'label' => __( 'WhatsApp number', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => __( 'International format, for example +61400000000.', 'titan-zero-trades-front-desk' ) ),
                'message' => array( 'label' => __( 'Pre-filled message', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => '' ),
                'label' => array( 'label' => __( 'Button label', 'titan-zero-trades-front-desk' ), 'type' => 'text', 'help' => '' ),
            ), __( 'Default for new calendars. Each calendar can change these in its own settings.', 'titan-zero-trades-front-desk' ) );
        }

        public static function defaults()
        {
            return array(
                'number'  => '',
                'message' => 'Hello, I have a question about a booking.',
                'label'   => 'Chat on WhatsApp',
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
            TZFD_Addon_Support::render_table( 'tzfd_' . self::KEY . '_', array(
                'number'  => array( 'label' => 'WhatsApp number', 'type' => 'text',
                                    'help'  => 'Full international number, for example 61412345678. Digits only, no + or spaces needed.' ),
                'message' => array( 'label' => 'Prefilled message', 'type' => 'textarea' ),
                'label'   => array( 'label' => 'Button label', 'type' => 'text' ),
            ), $values );
            return '';
        } // end get_addon_form_settings

        /************************ HOOKS *****************************/

        /** Appends the chat link after the form. Adds nothing when no valid number is set. */
        public function append_button( $html, $formid )
        {
            if ( ! is_string( $html ) ) { return $html; }
            $settings = TZFD_Addon_Support::form_settings( self::KEY, (int) $formid, self::defaults() );
            $url      = TZFD_Addon_Support::whatsapp_url( $settings['number'], $settings['message'] );
            if ( '' === $url ) { return $html; }

            return $html . '<p class="tzfd-chat-link" style="margin-top:12px;"><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">' . esc_html( $settings['label'] ) . '</a></p>';
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $tzfd_WhatsappButton_obj = new TZFD_WhatsappButton();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $tzfd_WhatsappButton_obj->get_addon_id() ] = $tzfd_WhatsappButton_obj;
}
