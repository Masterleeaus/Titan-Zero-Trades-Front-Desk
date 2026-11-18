<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/*
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/../../includes/class-tzfd-request-scalar.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-request-value.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-stored-data.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-scoped-store.php';

require_once dirname( __DIR__ ) . '/core/base.addon.php';

if( !class_exists( 'TZFD_DashboardWidget' ) )
{
    class TZFD_DashboardWidget extends TZFD_BaseAddon
    {

        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-DashboardWidget";
		protected $name = 'Dashboard Widget: Upcoming appointments';
		protected $description;
        protected $max_image_width = 250;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        /** Keeps the posted value only when it is one of the allowed values; otherwise the fallback. */
        private static function allowed_value( $value, array $allowed, $fallback = '' )
        {
            return in_array( $value, $allowed, true ) ? $value : $fallback;
        }

        /** Dashboard settings belong to the selected Workspace company. */
        private static function setting( $key, $default = '' ) {
            if ( ! TZFD_Scoped_Store::company_id() ) { return $default; }
            $company = TZFD_Scoped_Store::company_id();
            $option = TZFD_Scoped_Store::option_name( $key, $company );
            if ( $company === TZFD_Scoped_Store::legacy_owner() && null === get_option( $option, null ) ) {
                $old_value = get_option( $key, null );
                if ( null !== $old_value ) { add_option( $option, $old_value, '', false ); }
            }
            return TZFD_Scoped_Store::get( $key, $default );
        }

        private static function save_setting( $key, $value ) {
            if ( ! TZFD_Scoped_Store::company_id() ) { return false; }
            return TZFD_Scoped_Store::update( $key, $value );
        }

		public function get_addon_settings()
		{
			if( isset( $_POST[ 'tz_fd_dashboard' ] ) && current_user_can( TZFD_Access::CAP ) && TZFD_Scoped_Store::company_id() > 0 )
			{
				check_admin_referer( 'tz_fd_dashboard_settings', '_tz_fd_nonce_dashboard' );
                if (isset($_POST[ 'tz_fd_dashboard_maxitems' ])) self::save_setting( 'tz_fd_dashboard_maxitems', trim( absint( tzfd_request_text( 'post', 'tz_fd_dashboard_maxitems' ) ) ) );
                if (isset($_POST[ 'tz_fd_dashboard_columns' ])) self::save_setting( 'tz_fd_dashboard_columns', trim( tzfd_request_text( 'post', 'tz_fd_dashboard_columns' ) ) );
                if (isset($_POST[ 'tz_fd_dashboard_columnlabels' ])) self::save_setting( 'tz_fd_dashboard_columnlabels', trim( tzfd_request_text( 'post', 'tz_fd_dashboard_columnlabels' ) ) );
                if (isset($_POST[ 'tz_fd_dashboard_capability' ])) self::save_setting( 'tz_fd_dashboard_capability', self::allowed_value( tzfd_request_text( 'post', 'tz_fd_dashboard_capability' ), array( 'manage_options', 'edit_pages', 'publish_posts', 'edit_posts', 'read' ), 'manage_options' ) );
                if (isset($_POST[ 'tz_fd_dashboard_compactview' ]))
                    self::save_setting( 'tz_fd_dashboard_compactview', self::allowed_value( tzfd_request_text( 'post', 'tz_fd_dashboard_compactview' ), array( 'checked' ) ) );
                else
                    self::save_setting( 'tz_fd_dashboard_compactview', '' );
			}
			?>
			<form method="post">
				<div id="metabox_basic_settings" class="postbox" >
					<h3 class='hndle' style="padding:5px;"><span><?php print esc_html(__('Dashboard Widget: Upcoming appointments', 'titan-zero-trades-front-desk')); ?></span></h3>
					<div class="inside">
						<table cellspacing="0" style="width:100%;">
							<tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Maximum items listed', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<input type="text" name="tz_fd_dashboard_maxitems" value="<?php echo esc_attr(( ( $tzfd_key = self::setting( 'tz_fd_dashboard_maxitems', '10' ) ) !== false ) ? $tzfd_key : '10'); ?>"   size="10" />
								</td>
							</tr>
							<tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Columns', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<input type="text" name="tz_fd_dashboard_columns" value="<?php echo esc_attr(( ( $tzfd_key = self::setting( 'tz_fd_dashboard_columns', 'TIME,SERVICE,data' ) ) !== false ) ? $tzfd_key : 'TIME,SERVICE,data'); ?>"  style="width:80%;" /><br />
                                    <em><?php print esc_html(__('Sample column names', 'titan-zero-trades-front-desk')); ?>: final_price,formname,...<?php print esc_html(__('Click for more info about supported column names', 'titan-zero-trades-front-desk')); ?>.</em>
								</td>
							</tr>
                            <tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Column labels', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<input type="text" name="tz_fd_dashboard_columnlabels" value="<?php echo esc_attr(( ( $tzfd_key = self::setting( 'tz_fd_dashboard_columnlabels', 'Time,Service,Data' ) ) !== false ) ? $tzfd_key : 'Time,Service,Data'); ?>"  style="width:80%;" /><br />
                                    <em><?php print esc_html(__('Visual column names at the top of the table', 'titan-zero-trades-front-desk')); ?>.</em>
								</td>
							</tr>
                            
                            <tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Compact view?', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<input type="checkbox" name="tz_fd_dashboard_compactview" value="checked" <?php echo esc_attr( ( self::setting( 'tz_fd_dashboard_compactview' )  != '' ) ? " checked " : '' ); ?> />
                                    <em><?php print esc_html(__('Joins consecutive time-slots of the same booking', 'titan-zero-trades-front-desk')); ?>.</em>
								</td>
							</tr>
						</table>
						<input type="submit" name="subbtnds" value="<?php esc_attr_e('Save settings', 'titan-zero-trades-front-desk');?>" />
					</div>
					<input type="hidden" name="tz_fd_dashboard" value="1" />
					<input type="hidden" name="_tz_fd_nonce_dashboard" value="<?php echo esc_attr(wp_create_nonce( 'tz_fd_dashboard_settings' )); ?>" />
				</div>
			</form>
			<?php
		}



		/************************ ADDON CODE *****************************/

        /** Table styles for the widget, added to the dashboard through the style API. */
        const TABLE_CSS = '.tzfdbtable {  border-collapse: collapse;border-spacing: 0;width: 100%;} .tzfdbtable .tzfdbth { text-align: left; border: 1px solid #333a48; background-color:#2b3140; color:#f2f4f8; } .tzfdbtable .tzfdbth th, .tzfdbtable td {border: 1px solid #333a48;color:#f2f4f8;text-align: left;padding: 8px;word-break: normal; }.tzfdbheadermth,.tzfdbheadermtd{ text-align: left; border: 1px solid #333a48 !important; background-color:#3a2a2e ;color:#f2f4f8;}';

        /************************ ATTRIBUTES *****************************/


        /************************ CONSTRUCT *****************************/

        function __construct()
        {
			$this->description = $this->tr_apply("The add-on adds a widget to display a list of upcoming appointments in the dashboard welcome page.", 'titan-zero-trades-front-desk' );
            // Check if the plugin is active
			if( !$this->addon_is_active() ) return;

            add_action('wp_dashboard_setup', array(&$this, 'add_dashboard_widgets') );
            add_action('admin_enqueue_scripts', array(&$this, 'enqueue_dashboard_assets') );
        } // End __construct

        /**
         * Adds the table styles to the Dashboard screen.
         */
        public function enqueue_dashboard_assets( $hook_suffix )
        {
            if ( 'index.php' !== $hook_suffix ) { return; }
            wp_register_style( 'tzfd-book-dashboard', false, array(), '2.0.1' );
            wp_add_inline_style( 'tzfd-book-dashboard', self::TABLE_CSS );
            wp_enqueue_style( 'tzfd-book-dashboard' );
        }


        public function add_dashboard_widgets() {
            // Apply capability restriction
            $required_cap = TZFD_Access::CAP; // Front Desk access only: Administrators and Titan Zero Worker/Manager. Editors are excluded.
            if ( current_user_can( $required_cap ) && TZFD_Scoped_Store::company_id() > 0 ) {
        	    wp_add_dashboard_widget('dashboard_widget', __('Scheduling & Enquiries Manager', 'titan-zero-trades-front-desk'), array(&$this, 'pp_DashboardWidget'));
            }
        }

        /************************ PRIVATE METHODS *****************************/



        /**
         * At-a-glance counts and quick links, shown above the appointment list.
         * Counts come from the bookings already loaded by the widget, so no extra
         * booking query is run. Quote counts moved to Titan Zero Quotes (19 Oct split) and are not shown here.
         */
        private function render_glance( $tzfd_events )
        {
            global $wpdb;
            $tzfd_today = current_time( 'Y-m-d' );
            $tzfd_week  = gmdate( 'Y-m-d', strtotime( $tzfd_today . ' +6 days' ) );
            $n_today = 0; $n_week = 0; $n_pending = 0;

            foreach ( (array) $tzfd_events as $tzfd_item )
            {
                if ( ! is_object( $tzfd_item ) || ! isset( $tzfd_item->posted_data ) ) { continue; }
                $tzfd_data = TZFD_Stored_Data::decode_booking( $tzfd_item->posted_data );
                if ( ! is_array( $tzfd_data ) || empty( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) ) continue;
                foreach ( $tzfd_data['apps'] as $tzfd_app )
                {
                    if ( ! is_array( $tzfd_app ) ) { continue; }
                    $date  = isset( $tzfd_app['date'] ) && is_string( $tzfd_app['date'] ) ? $tzfd_app['date'] : '';
                    $state = isset( $tzfd_app['cancelled'] ) && is_string( $tzfd_app['cancelled'] ) ? $tzfd_app['cancelled'] : '';
                    if ( $date === '' || strpos( $state, 'Cancelled' ) === 0 ) continue;
                    if ( $date === $tzfd_today ) $n_today++;
                    if ( $date >= $tzfd_today && $date <= $tzfd_week ) $n_week++;
                    if ( $date >= $tzfd_today && $state === 'Pending' ) $n_pending++;
                }
            }

            $cells = array(
                array( __( 'Today', 'titan-zero-trades-front-desk' ),                    $n_today ),
                array( __( 'Next 7 days', 'titan-zero-trades-front-desk' ),              $n_week ),
                array( __( 'Awaiting approval', 'titan-zero-trades-front-desk' ),        $n_pending ),
            );

            // The Dashboard's top cards already show these counts, so the cells are printed only without them.
            if ( ! ( class_exists( 'TZFD_Dashboard_Figures' ) && TZFD_Dashboard_Figures::shown() ) )
            {
                echo '<div class="tzfd-stats">';
                foreach ( $cells as $cell )
                {
                    echo '<div class="tzfd-stat"><div class="tzfd-stat-n">'.intval( $cell[1] ).'</div>'
                        .'<div class="tzfd-stat-l">'.esc_html( $cell[0] ).'</div></div>';
                }
                echo '</div>';
            }

            // Quick links
            $links = array( array( __( 'Calendars', 'titan-zero-trades-front-desk' ), admin_url( 'admin.php?page=tz_fd_calendar&manage=1' ) ) );
            $company_id = (int) TZFD_Scoped_Store::company_id();
            $first_calendar = $company_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT MIN(id) FROM %i WHERE company_id = %d',
                $wpdb->prefix . TZFD_Tables::FORMS, $company_id
            ) ) : 0;
            if ( $first_calendar )
            {
                $links[] = array( __( 'Add booking', 'titan-zero-trades-front-desk' ),  admin_url( 'admin.php?page=tzfd&cal=' . $first_calendar . '&addbk=1' ) );
                $links[] = array( __( 'Block times', 'titan-zero-trades-front-desk' ),  admin_url( 'admin.php?page=tzfd&cal=' . $first_calendar . '&blocktimes=1' ) );
            }
            echo '<div class="tzfd-actions">';
            foreach ( $links as $link )
            {
                echo '<a class="tzfd-btn tzfd-btn-secondary" href="'.esc_url( $link[1] ).'">'.esc_html( $link[0] ).'</a>';
            }
            echo '</div>';
        }

		/************************ PUBLIC METHODS  *****************************/


		/**
         * print list
         */
		public function pp_DashboardWidget($atts = array())
		{
            global $wpdb, $tzfd_plugin;
            extract( shortcode_atts( array(
	        	'calendar' => '',
	        	'showdelete' => '0', // Still accepted so existing shortcodes keep working; renders nothing.
                'columnlabels' =>  __('Time', 'titan-zero-trades-front-desk').",". __('Service', 'titan-zero-trades-front-desk').",". __('Data', 'titan-zero-trades-front-desk'),
	        	'columns' => 'TIME,SERVICE,data',
                'datefrom' => 'today',
                'dateto' => 'today +1 month',
                'paidonly' => "",
                'maxitems' => "10",
                'datelabel' => __('Date', 'titan-zero-trades-front-desk'),
                'status' => "-1"
	        ), $atts ) );

            // Shortcodes are public input: reject nested values before string operations.
            // Variable variables are limited to the fixed allow-list above, so no other variable can be overwritten.
            foreach ( array( 'columns', 'columnlabels', 'datefrom', 'dateto', 'paidonly', 'maxitems', 'datelabel', 'status' ) as $parameter ) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- fixed allow-list above; no other variable can be overwritten.
                if ( ! is_string( $$parameter ) && ! is_int( $$parameter ) ) { return; }
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- fixed allow-list above; no other variable can be overwritten.
                $$parameter = (string) $$parameter;
            }
            if ( strlen( $columns ) > 500 || strlen( $columnlabels ) > 1000 || strlen( $status ) > 60 ) { return; }
            $configured_columns = self::setting( 'tz_fd_dashboard_columns', $columns );
            $configured_labels = self::setting( 'tz_fd_dashboard_columnlabels', $columnlabels );
            if ( ! is_string( $configured_columns ) || ! is_string( $configured_labels ) ) { return; }
            $columns = str_replace('>','',str_replace('<','',str_replace('%','', $configured_columns)));
            $columnlabels = wp_strip_all_tags( $configured_labels );

            if (self::setting( 'tz_fd_dashboard_compactview' ) != '') $compact = true; else $compact = false; 
            $maxitems = self::setting( 'tz_fd_dashboard_maxitems' );
            if ($maxitems == '')
                $maxitems = 10;
            else
                $maxitems = intval($maxitems);
            $maxitems = max( 1, min( 100, $maxitems ) );
            wp_enqueue_script( "jquery" );

            $company_id = (int) TZFD_Scoped_Store::company_id();
            if ( $company_id <= 0 ) {
                return; // No active company: never show site-wide booking history.
            }
            $calendar = TZFD_Request_Value::id( $calendar );
            // A supplied calendar must be company-owned; malformed values must not become an all-calendar request.
            if ( isset( $atts['calendar'] ) && ( ! is_scalar( $atts['calendar'] ) || ( '' !== (string) $atts['calendar'] && $calendar <= 0 ) ) ) {
                return;
            }
            $from_timestamp = is_string( $datefrom ) && strlen( $datefrom ) <= 64 ? strtotime( $datefrom ) : false;
            $to_timestamp = is_string( $dateto ) && strlen( $dateto ) <= 64 ? strtotime( $dateto ) : false;
            if ( false === $from_timestamp || false === $to_timestamp || $from_timestamp > $to_timestamp ) {
                return;
            }
            $from = gmdate( 'Y-m-d', $from_timestamp );
            $to = gmdate( 'Y-m-d', $to_timestamp );

            $table_messages = $wpdb->prefix . TZFD_Tables::BOOKINGS;
            $table_items    = $wpdb->prefix . TZFD_Tables::FORMS;

            // Table names are bound as %i identifiers and the calendar id as %d. The former raw-SQL filter on this query was removed because raw SQL cannot be prepared.
            if ( $calendar ) {
                $tzfd_events = $wpdb->get_results( $wpdb->prepare(
                    "SELECT %i.* FROM %i INNER JOIN %i ON %i.formid = %i.id WHERE %i.company_id = %d AND %i.company_id = %d AND %i.formid = %d ORDER BY %i.time DESC",
                    $table_messages, $table_messages, $table_items, $table_messages, $table_items,
                    $table_messages, $company_id, $table_items, $company_id, $table_messages, $calendar, $table_messages
                ) );
            } else {
                $tzfd_events = $wpdb->get_results( $wpdb->prepare(
                    "SELECT %i.* FROM %i INNER JOIN %i ON %i.formid = %i.id WHERE %i.company_id = %d AND %i.company_id = %d ORDER BY %i.time DESC",
                    $table_messages, $table_messages, $table_items, $table_messages, $table_items,
                    $table_messages, $company_id, $table_items, $company_id, $table_messages
                ) );
            }

            $this->render_glance( $tzfd_events );
            echo '<h2 class="tzfd-section-title">'.esc_html(__('Next','titan-zero-trades-front-desk'))." ".intval($maxitems)." ".esc_html(__('upcoming appointments','titan-zero-trades-front-desk')).'</h2>';

            // pre-select time-slots
            $tzfd_selection = array();
            foreach((array)$tzfd_events as $tzfd_item)
            {
                if (!is_object($tzfd_item) || !isset($tzfd_item->posted_data, $tzfd_item->notifyto, $tzfd_item->data, $tzfd_item->id)) { continue; }
                $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
                if (is_array($tzfd_data) && isset($tzfd_data["apps"]) && is_array($tzfd_data["apps"]) && (!$paidonly || !empty($tzfd_data['paid'])))
                {
                    foreach($tzfd_data["apps"] as $tzfd_app)
                        if (is_array($tzfd_app) && isset($tzfd_app['date'], $tzfd_app['slot'], $tzfd_app['cancelled'], $tzfd_app['service']) && is_string($tzfd_app['date']) && is_string($tzfd_app['slot']) && is_string($tzfd_app['cancelled']) && is_string($tzfd_app['service'])
                            && $tzfd_app["date"] >= $from && $tzfd_app["date"] <= $to && ($status == '-1' || $status == $tzfd_app["cancelled"]) 
						   && $tzfd_app["cancelled"] != 'Cancelled' && $tzfd_app["cancelled"] != 'Cancelled by customer')
                        {
                            $tzfd_selection[] = array( $tzfd_app["date"]." ".$tzfd_app["slot"], // 0
                                                  $tzfd_app["date"], // 1
                                                  $tzfd_app["slot"], // 2
                                                  $tzfd_data, // 3
                                                  sanitize_email($tzfd_item->notifyto), // 4
                                                  $tzfd_item->data, // 5
                                                  $tzfd_app["cancelled"], // 6
                                                  $tzfd_app["service"], // 7
                                                 '', // 8
                                                 '', // 9
                                                 $tzfd_item->id                      // 10
                                                 );
                        }
                }
            }

            // order time-slots
            usort($tzfd_selection, function ($a, $b) { return ($a[0] > $b[0]?1:-1); } );

            $count_selection = count($tzfd_selection);
            // Join consecutive items of the same booking
            if ( $compact )
            {
                for ($tzfd_i = 0; $tzfd_i < count($tzfd_selection)-1; $tzfd_i++)
                {
                    if (isset($tzfd_selection[$tzfd_i]) && isset($tzfd_selection[$tzfd_i+1]) && $tzfd_selection[$tzfd_i][10] == $tzfd_selection[$tzfd_i+1][10])
                    {
                        $time1 = explode("-",str_replace("/","-",$tzfd_selection[$tzfd_i][2]));
                        $time2 = explode("-",str_replace("/","-",$tzfd_selection[$tzfd_i+1][2]));
                        if (count($time1) >= 2 && count($time2) >= 2 && $time1[1] == $time2[0])
                        {
                            $tzfd_selection[$tzfd_i][2] = $time1[0] ."-".$time2[1];
                            $tzfd_selection[$tzfd_i][0] = $tzfd_selection[$tzfd_i][1]." ".$tzfd_selection[$tzfd_i][2];
                            //unset($tzfd_selection[$tzfd_i+1]);
                            for ($tzfd_j = $tzfd_i+1; $tzfd_j < count($tzfd_selection)-1; $tzfd_j++)
                                $tzfd_selection[$tzfd_j] = $tzfd_selection[$tzfd_j+1];
                            $tzfd_i--;
                            unset($tzfd_selection[count($tzfd_selection)-1]);
                        }
                    }
                }
            }

            // clean fields IDs
            $fields = explode(",",trim($columns));
            for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
                $fields[$tzfd_j] = strtolower(trim($fields[$tzfd_j]));

            $columnlabels = explode(",", $columnlabels);
            $columns = explode(",", $columns);

            if (!count($tzfd_selection))
            {
                if (class_exists('TZFD_Local_Brand')) { TZFD_Local_Brand::empty_state('calendar', __('No upcoming appointments found','titan-zero-trades-front-desk')); } else { echo esc_html(__('No upcoming appointments found','titan-zero-trades-front-desk')); }
                return;
            }



            // list data rows
            $colnum = 0;
            $lastdate = '';
            for($tzfd_i=0; $tzfd_i<count($tzfd_selection) && $tzfd_i<$maxitems; $tzfd_i++)
            {
                $colnum++;
                if ($lastdate != $tzfd_selection[$tzfd_i][1])
                {
                    if ($lastdate != '') echo '</table></div>';
                    $lastdate = $tzfd_selection[$tzfd_i][1];
                    echo '<div style="overflow-x:auto;"><table class="tzfdbtable"><tr>';
                    // list header rows
                    echo '<th class="tzfdbheadermth">'.esc_html($datelabel).'</th>';
                    echo '<td class="tzfdbheadermtd" nowrap>'.esc_html($lastdate).'</td>';
                    echo '<tr>';
                    $colnum = 0;
                    foreach ($columnlabels as $tzfd_item)
                    {
                        $colnum++;
                        echo '<th class="tzfdbheader'.intval($colnum).' tzfdbth">'.esc_html(trim($tzfd_item)).'</th>';
                    }
                    echo '</tr>';
                }
                echo '<tr class="'.($tzfd_selection[$tzfd_i][6]!=''?' tz_fd_cancelled':'').'">';
                for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
                {
                   $nbsp = "\u{00A0}";
                   switch ($fields[$tzfd_j]) {
                        case 'date':
                            $tzfd_value = $tzfd_selection[$tzfd_i][1];
                            break;
                        case 'time':
                            $tzfd_value = $tzfd_selection[$tzfd_i][2];
                            break;
                        case 'email':
                            $tzfd_value = sanitize_email($tzfd_selection[$tzfd_i][4]).$nbsp;
                            break;
                        case 'service':
                            $tzfd_value = $tzfd_selection[$tzfd_i][7].$nbsp;
                            break;
                        case 'cancelled':
                            $tzfd_value = $nbsp;
                            break;
                        case 'data':
                            $message = is_string($tzfd_selection[$tzfd_i][5]) ? $tzfd_selection[$tzfd_i][5] : '';
                            $separator = strpos($message, "\n\n");
                            $tzfd_value = false !== $separator ? substr($message, $separator + 2) : $message;
                            break;
                        case 'paid':
                            $tzfd_value = (!empty($tzfd_selection[$tzfd_i][3]['paid'])?__('Yes','titan-zero-trades-front-desk'):$nbsp);
                            break;
                        default:
                            $field_value = $tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]] ?? '';
                            $tzfd_value = is_scalar($field_value) && '' !== (string)$field_value ? (string)$field_value . $nbsp : $nbsp;
                   }
                    echo '<td class="tzfdbcol'.esc_html($colnum).'">'.esc_html(trim($tzfd_value)).'</td>';
                }
                echo '</tr>';
            }

            if ($lastdate != '') echo '</table></div>';

		} // end pp_DashboardWidget


    } // End Class

    // Main add-on code
    $TZFD_DashboardWidget_obj = new TZFD_DashboardWidget();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $TZFD_DashboardWidget_obj->get_addon_id() ] = $TZFD_DashboardWidget_obj;
}