<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-stored-data.php';

if( !class_exists( 'TZFD_AutoCancelStatusManagement' ) )
{
    /**
     * Cancels pending bookings that have not been approved within a set number
     * of hours. Runs hourly through WP-Cron, per calendar.
     */
    class TZFD_AutoCancelStatusManagement extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-AutoCancelStatus";
		protected $name = "Automatically cancel pending bookings";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY  = 'autocancel';
        const HOOK = 'titan_zero_front_desk_addon_autocancel_run';
        const BATCH = 200;

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on cancels pending bookings that are not approved within a set number of hours.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_action( self::HOOK, array( $this, 'run_auto_cancel' ) );
            if ( ! wp_next_scheduled( self::HOOK ) ) {
                wp_schedule_event( time() + 300, 'hourly', self::HOOK );
            }
        } // End __construct

        /************************ SETTINGS *****************************/

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::global_settings_block( self::KEY, self::defaults(), array(
                'hours' => array( 'label' => __( 'Cancel pending bookings after (hours)', 'titan-zero-trades-front-desk' ), 'type' => 'int', 'help' => __( '0 turns this off.', 'titan-zero-trades-front-desk' ) ),
            ), __( 'Default for new calendars. Each calendar can change these in its own settings.', 'titan-zero-trades-front-desk' ) );
        }

        public static function defaults()
        {
            return array( 'hours' => 0 );
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
                'hours' => array( 'label' => 'Cancel pending bookings after (hours)', 'type' => 'int',
                                  'help'  => '0 turns this off. Only bookings where every appointment is still Pending are cancelled.' ),
            ), $values );
            return '';
        } // end get_addon_form_settings

        /************************ CRON *****************************/

        /**
         * Cancel stale pending bookings for every calendar with a positive limit.
         * Uses the core status update, so status hooks and emails still run.
         *
         * @return int Number of bookings cancelled in this run.
         */
        public function run_auto_cancel()
        {
            global $wpdb, $tzfd_plugin;
            if ( ! is_object( $wpdb ) || ! is_object( $tzfd_plugin ) || ! method_exists( $tzfd_plugin, 'update_status' ) ) {
                return 0;
            }

            $tz        = wp_timezone();
            $now       = time();
            $tzfd_cancelled = 0;
            $forms     = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM %i', $wpdb->prefix . 'tzfd_forms' ) );
            foreach ( (array) $forms as $tzfd_form ) {
                $formid   = (int) $tzfd_form->id;
                $settings = TZFD_Addon_Support::form_settings( self::KEY, $formid, self::defaults() );
                $hours    = (int) $settings['hours'];
                if ( $hours <= 0 ) { continue; }

                $tzfd_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, time, posted_data FROM %i WHERE formid=%d AND posted_data LIKE %s ORDER BY id ASC LIMIT %d",
                    $wpdb->prefix . 'tzfd_messages',
                    $formid,
                    '%Pending%',
                    self::BATCH
                ) );
                foreach ( (array) $tzfd_rows as $row ) {
                    if ( ! TZFD_Addon_Support::is_older_than( $row->time, $hours, $tz, $now ) ) { continue; }
                    $tzfd_data = TZFD_Stored_Data::decode_booking( $row->posted_data );
                    if ( ! is_array( $tzfd_data ) || empty( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) ) { continue; }
                    if ( ! TZFD_Addon_Support::all_have_status( $tzfd_data['apps'], 'Pending' ) ) { continue; }
                    if ( false !== $tzfd_plugin->update_status( (int) $row->id, 'Cancelled' ) ) {
                        $tzfd_cancelled++;
                    }
                }
            }
            return $tzfd_cancelled;
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $TZFD_AutoCancelStatusManagement_obj = new TZFD_AutoCancelStatusManagement();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $TZFD_AutoCancelStatusManagement_obj->get_addon_id() ] = $TZFD_AutoCancelStatusManagement_obj;
}
