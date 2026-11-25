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

        private $form_table = TZFD_Tables::CACHE;
        private $_inserted = false;

        /** Cached availability older than this many seconds is ignored and rebuilt. Bounds staleness for writes that skip the invalidation hooks. */
        const TTL = 300;
        /** Schema version of the cache table. The table is created or upgraded only when this changes. */
        const SCHEMA_VERSION = '1';
        const SCHEMA_OPTION = 'tz_fd_cache_db_version';

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
            
            
            // Create or upgrade the table only when the schema version changes, not on every page view.
            if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
                $this->update_database();
                update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
            }

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
                    company_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    formid INT NOT NULL,
                    cachedquery mediumtext,
                    cacheddata mediumtext,
                    PRIMARY KEY  (id),
                    KEY company_form (company_id,formid)
                ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
            
		} // end update_database


		/************************ PUBLIC METHODS  *****************************/


		/**
         * cache_clean
         */
        /**
         * Company that owns a calendar, read from the calendar row. Cache rows take this company, so a cache
         * written from a public request, a cron run or an admin request all land in the calendar's own company.
         * Returns 0 when the calendar is not found (rows then match nothing a company can read).
         */
        public static function calendar_company( $formid )
		{
            global $wpdb;
            $company = $wpdb->get_var( $wpdb->prepare(
                'SELECT company_id FROM %i WHERE id = %d',
                $wpdb->prefix . TZFD_Tables::FORMS, intval( $formid )
            ) );
            // Never use company 0 on lookup failure: a failed owner lookup must not
            // select or populate another site's cached availability.
            return ( null === $company || false === $company ) ? 0 : max( 0, (int) $company );
        }

        public function	cache_clean( $formid )
		{
            global $wpdb, $tzfd_plugin;
            
            $company = self::calendar_company( $formid );
            if ( $company < 1 ) { return; }
            $wpdb->query( 
                $wpdb->prepare( 
                    'UPDATE %i SET cacheddata = %s WHERE formid = %d AND company_id = %d', 
                    $wpdb->prefix . TZFD_Tables::CACHE, '', intval( $formid ), $company
                ) 
            ); 
		} // end cache_clean  
        
        

		/**
         * update_status
         */
        public function	update_status($itemnumber, $status = '', $indexonly = '')
		{
            $this->clean_for_booking( $itemnumber );
		} // end update_status

        /**
         * Clears the cache of the calendar that owns a booking. Runs for every request, whatever the company
         * context: the lookup only finds the calendar, and cache_clean() scopes the clean to that calendar's company.
         */
        private function clean_for_booking( $itemnumber )
		{
            global $wpdb;
            if ( (int) $itemnumber < 1 ) { return; }
            $tzfd_myrows = $wpdb->get_results( $wpdb->prepare("SELECT formid FROM %i WHERE id=%d", $wpdb->prefix.TZFD_Tables::BOOKINGS, (int) $itemnumber) );

            if (is_array($tzfd_myrows) && count($tzfd_myrows))
                $this->cache_clean( $tzfd_myrows[0]->formid );
		}
        
        
		/**
         * item_deleted
         */
        public function	item_deleted($itemnumber)
		{
            $this->clean_for_booking( $itemnumber );
		} // end item_deleted
        
        
		/**
         * new_submission
         */
        public function	new_submission($tzfd_local_params, $indexonly = -1 )
		{
            global $wpdb, $tzfd_plugin;
            
            if (isset($tzfd_local_params[ 'formid' ]))
                $this->cache_clean( intval( $tzfd_local_params[ 'formid' ] ) );
            
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
            
            $company = self::calendar_company( $formid );
            if ( $company < 1 ) { return; }
            $is_cached = $wpdb->get_results( 
                $wpdb->prepare( 
                    "SELECT * FROM %i WHERE formid = %d AND company_id = %d AND cachedquery = %s AND cacheddata <> ''", 
                    $wpdb->prefix . TZFD_Tables::CACHE, $formid, $company,
                    self::cache_key( $dataquery ) 
                ) 
            );
            // A failed cache lookup must not crash a public booking form.
            // Ignore the cache and let the authoritative availability reader run.
            if ( ! is_array( $is_cached ) ) { return; }
            if ( count( $is_cached ) )
            {
                // cacheddata is the JSON feed that cache_store() wrote with wp_json_encode(). Decode it and encode it
                // again so the output is escaped JSON. A row that does not decode is ignored and rebuilt.
                // Rows are stored as {stored_at, feed}. A row without a timestamp, or older than TTL, is rebuilt.
                $tzfd_envelope = json_decode( (string) $is_cached[0]->cacheddata, true );
                $tzfd_age = is_array( $tzfd_envelope ) && isset( $tzfd_envelope['stored_at'] ) ? time() - (int) $tzfd_envelope['stored_at'] : -1;
                $tzfd_cached = ( $tzfd_age >= 0 && $tzfd_age < self::TTL && is_string( $tzfd_envelope['feed'] ?? null ) )
                    ? json_decode( $tzfd_envelope['feed'], true ) : null;
                if ( is_array( $tzfd_cached ) )
                {
                    $encoded = wp_json_encode( $tzfd_cached );
                    if ( false !== $encoded ) {
                        header( 'Content-Type: application/json; charset=UTF-8' );
                        header( 'X-Content-Type-Options: nosniff' );
                        header( 'Cache-Control: no-store, private' );
                        echo $encoded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON API response.
                        exit;
                    }
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
            $company = self::calendar_company( $formid );
            if ( $company < 1 || ! is_string( $dataoutput ) || '' === $dataoutput ||
                ! is_array( json_decode( $dataoutput, true ) ) ) { return; }
            $is_cached = $wpdb->get_results( 
                $wpdb->prepare( 
                    "SELECT * FROM %i WHERE formid = %d AND company_id = %d AND cachedquery = %s", 
                    $wpdb->prefix . TZFD_Tables::CACHE, $formid, $company,
                    $queryhash 
                ) 
            ); 
            // A failed existence query must not cause duplicate inserts.
            if ( ! is_array( $is_cached ) ) { return; }
            if ( ! count( $is_cached ) ) {
                $inserted = $wpdb->insert(
                    $wpdb->prefix . TZFD_Tables::CACHE,
                    array( 'company_id' => $company, 'formid' => intval( $formid ), 'cachedquery' => $queryhash )
                );
                if ( false === $inserted ) { return; }
            }
            $tzfd_envelope = wp_json_encode( array( 'stored_at' => time(), 'feed' => $dataoutput ) );
            if ( false === $tzfd_envelope ) { return; }
            $wpdb->update(
                $wpdb->prefix . TZFD_Tables::CACHE,
                array( 'cacheddata' => $tzfd_envelope ),
                array( 'formid' => intval( $formid ), 'company_id' => $company, 'cachedquery' => $queryhash )
            );
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

