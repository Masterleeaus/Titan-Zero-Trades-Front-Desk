<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';

if( !class_exists( 'TZFD_appLimit' ) )
{
    /**
     * Limits how many appointments a customer can select in one booking submission.
     * Configured per calendar. 0 means unlimited.
     */
    class TZFD_appLimit extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-appLimit";
		protected $name = "Limit the number of appointments per user";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY = 'applimit';

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on limits how many appointments a customer can select in a single booking.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'tzfd_process_data_before_insert', array( $this, 'check_submission' ), 6, 1 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::global_settings_block( self::KEY, self::defaults(), array(
                'max' => array( 'label' => __( 'Maximum appointments per customer', 'titan-zero-trades-front-desk' ), 'type' => 'int', 'help' => __( '0 means no limit.', 'titan-zero-trades-front-desk' ) ),
            ), __( 'Default for new calendars. Each calendar can change these in its own settings.', 'titan-zero-trades-front-desk' ) );
        }

        public static function defaults()
        {
            return array( 'max' => 0 );
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
                'max' => array( 'label' => 'Maximum appointments per booking', 'type' => 'int',
                                'help'  => 'The most appointments one customer can select in a single submission. 0 means no limit.' ),
            ), $values );
            return '';
        } // end get_addon_form_settings

        /************************ HOOKS *****************************/

        /** Runs before insert. Refuses a submission that selects too many appointments. */
        public function check_submission( $tzfd_params )
        {
            if ( defined( 'TZFD_HOUR_BLOCK_TIMES_PROCESS' ) ) { return; }
            if ( is_admin() && current_user_can( 'edit_posts' ) ) { return; }
            if ( ! is_array( $tzfd_params ) || ! isset( $tzfd_params['formid'] ) || ! isset( $tzfd_params['apps'] ) || ! is_array( $tzfd_params['apps'] ) ) { return; }

            $settings = TZFD_Addon_Support::form_settings( self::KEY, (int) $tzfd_params['formid'], self::defaults() );
            $max      = (int) $settings['max'];
            if ( TZFD_Addon_Support::exceeds_limit( count( $tzfd_params['apps'] ), $max ) ) {
                echo esc_html( sprintf( 'Please select a max of %d appointments per customer.', $max ) );
                exit;
            }
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $TZFD_appLimit_obj = new TZFD_appLimit();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $TZFD_appLimit_obj->get_addon_id() ] = $TZFD_appLimit_obj;
}
