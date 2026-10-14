<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/*
    Shared Availability Addon
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once dirname( __FILE__ ).'/base.addon.php';

if( !class_exists( 'TZFD_Cache' ) )
{
    class TZFD_Cache extends TZFD_BaseAddon
    {
        public function get_addon_settings()
        {
            return TZFD_Addon_Support::info_block( __( 'This add-on has no settings. Turn it on or off on the Add-ons list.', 'titan-zero-trades-front-desk' ) );
        }

        

        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-Cache";
		protected $name = "Cache Booking Availability";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';


		/************************ ADDON CODE *****************************/

        /************************ ATTRIBUTES *****************************/

        private $form_table = 'tzfd_cache';
        private $_inserted = false;

        /************************ CONSTRUCT *****************************/

        function __construct()
        {            
			$this->description = $this->tr_apply("The add-on keeps a cache of the available times for the booking form display.", 'titan-zero-trades-front-desk' );
            // Check if the plugin is active
			if( !$this->addon_is_active() ) return;      
                      
            add_action( 'tzfd_cache_check', array( &$this, 'cache_check'), 99, 2 ); // $formid, $dataquery
            
            add_action( 'tzfd_cache_store', array( &$this, 'cache_store'), 99, 3 ); // $formid, $dataquery, $dataoutput
            
            add_action( 'tzfd_cache_clean', array( &$this, 'cache_clean'), 99, 1 ); // $formid
                        
            add_action( 'tzfd_update_status', array( &$this, 'update_status' ), 10, 3 );
            add_action( 'tzfd_process_data', array( &$this, 'new_submission' ), 10, 2 );
            add_action( 'tzfd_item_deleted', array( &$this, 'item_deleted' ), 10, 1 );
            
            
            $this->update_database();

        } // End __construct


        /************************ PRIVATE METHODS *****************************/

		/**
         * Create the database tables
         */
        protected function update_database()
		{
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();
            $tzfd_table_name      = $wpdb->prefix . esc_sql( $this->form_table );

            // Note: dbDelta does not need "IF NOT EXISTS"
            $sql = "CREATE TABLE {$tzfd_table_name} (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    formid INT NOT NULL,
                    cachedquery mediumtext,
                    cacheddata mediumtext,
                    UNIQUE KEY id (id)
                ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
            
		} // end update_database


		/************************ PUBLIC METHODS  *****************************/


		/**
         * cache_clean
         */
        public function	cache_clean( $formid )
		{
            global $wpdb, $tzfd_plugin;
            
            $wpdb->query( 
                $wpdb->prepare( 
                    "UPDATE {$wpdb->prefix}tzfd_cache SET cacheddata = '' WHERE formid = %d", 
                    $formid 
                ) 
            ); 
		} // end cache_clean  
        
        

		/**
         * update_status
         */
        public function	update_status($itemnumber, $status = '', $indexonly = '')
		{
            global $wpdb, $tzfd_plugin;
            
            $tzfd_myrows = $wpdb->get_results( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."tzfd_messages WHERE id=%d", $itemnumber) ); 
            
            if (is_array($tzfd_myrows) && count($tzfd_myrows))
                $this->cache_clean( $tzfd_myrows[0]->formid );
            
		} // end update_status  
        
        
		/**
         * item_deleted
         */
        public function	item_deleted($itemnumber)
		{
            global $wpdb, $tzfd_plugin;
            
            $tzfd_myrows = $wpdb->get_results( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."tzfd_messages WHERE id=%d", $itemnumber) ); 
            
            if (is_array($tzfd_myrows) && count($tzfd_myrows))
                $this->cache_clean( $tzfd_myrows[0]->formid );
            
		} // end item_deleted  
        
        
		/**
         * new_submission
         */
        public function	new_submission($tzfd_params, $indexonly = -1 )
		{
            global $wpdb, $tzfd_plugin;
            
            if (isset($tzfd_params[ 'formid' ]))
                $this->cache_clean( intval( $tzfd_params[ 'formid' ] ) );
            
		} // end new_submission          
        
        
		/**
         * cache_check
         */
        /**
         * Cache entries are scoped by viewer: admin screens render customer detail
         * that anonymous visitors must never receive from the same cached row.
         */
        private static function cache_key( $dataquery )
        {
            $scope = is_admin() ? 'admin:' . get_current_user_id() : 'public';
            return md5( $dataquery . '|' . $scope );
        }

        public function	cache_check( $formid, $dataquery )
		{
            global $wpdb, $tzfd_plugin;
            
            $is_cached = $wpdb->get_results( 
                $wpdb->prepare( 
                    "SELECT * FROM {$wpdb->prefix}tzfd_cache WHERE formid = %d AND cachedquery = %s AND cacheddata <> ''", 
                    $formid, 
                    self::cache_key( $dataquery ) 
                ) 
            );
            if (count($is_cached))
            {
                // cacheddata is the JSON feed that cache_store() wrote with wp_json_encode(). Decode it and encode it
                // again so the output is escaped JSON. A row that does not decode is ignored and rebuilt.
                $tzfd_cached = json_decode( (string) $is_cached[0]->cacheddata, true );
                if ( null !== $tzfd_cached )
                {
                    echo wp_json_encode( $tzfd_cached );
                    exit;
                }
            }
		} // end cache_check  
        
        
		/**
         * cache_store
         */
        public function	cache_store( $formid, $dataquery, $dataoutput )
		{
            global $wpdb, $tzfd_plugin;
            
            $queryhash = self::cache_key( $dataquery );
            $is_cached = $wpdb->get_results( 
                $wpdb->prepare( 
                    "SELECT * FROM {$wpdb->prefix}tzfd_cache WHERE formid = %d AND cachedquery = %s", 
                    $formid, 
                    $queryhash 
                ) 
            ); 
            if (!count($is_cached))
                $wpdb->insert($wpdb->prefix."tzfd_cache", array('formid' => intval($formid), 'cachedquery' => ($queryhash) ) ); 
            $wpdb->update($wpdb->prefix."tzfd_cache", array('cacheddata' => ($dataoutput) ), array('formid' => intval($formid), 'cachedquery' => ($queryhash) ) );
		} // end cache_store          
        

		/**
		 * mark the item as paid
		 */
		private function _log($adarray = array())
		{
			// not needed right now
		}
        


    } // End Class

    // Main add-on code
    $TZFD_Cache_obj = new TZFD_Cache();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $TZFD_Cache_obj->get_addon_id() ] = $TZFD_Cache_obj;
}

