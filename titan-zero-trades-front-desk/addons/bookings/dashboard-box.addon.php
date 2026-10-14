<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/*
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-stored-data.php';

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

		public function get_addon_settings()
		{
			if( isset( $_POST[ 'tzfd_dashboard' ] ) && current_user_can( 'manage_options' ) )
			{
				check_admin_referer( 'tzfd_dashboard_settings', '_tzfd_nonce_dashboard' );
                if (isset($_POST[ 'tzfd_dashboard_maxitems' ])) update_option( 'tzfd_dashboard_maxitems', trim( absint( sanitize_text_field( wp_unslash( $_POST[ 'tzfd_dashboard_maxitems' ] ?? '' ) ) ) ) );
                if (isset($_POST[ 'tzfd_dashboard_columns' ])) update_option( 'tzfd_dashboard_columns', trim( sanitize_text_field(wp_unslash( $_POST[ 'tzfd_dashboard_columns' ] ?? '' )) ) );
                if (isset($_POST[ 'tzfd_dashboard_columnlabels' ])) update_option( 'tzfd_dashboard_columnlabels', trim( sanitize_text_field(wp_unslash( $_POST[ 'tzfd_dashboard_columnlabels' ] ?? '' )) ) );
                if (isset($_POST[ 'tzfd_dashboard_capability' ])) update_option( 'tzfd_dashboard_capability', self::allowed_value( sanitize_text_field( wp_unslash( $_POST[ 'tzfd_dashboard_capability' ] ?? '' ) ), array( 'manage_options', 'edit_pages', 'publish_posts', 'edit_posts', 'read' ), 'manage_options' ) );
                if (isset($_POST[ 'tzfd_dashboard_compactview' ]))
                    update_option( 'tzfd_dashboard_compactview', self::allowed_value( sanitize_text_field( wp_unslash( $_POST[ 'tzfd_dashboard_compactview' ] ?? '' ) ), array( 'checked' ) ) );
                else
                    update_option( 'tzfd_dashboard_compactview', '' );
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
									<input type="text" name="tzfd_dashboard_maxitems" value="<?php echo esc_attr(( ( $tzfd_key = get_option( 'tzfd_dashboard_maxitems' ) ) !== false ) ? $tzfd_key : '10'); ?>"   size="10" />
								</td>
							</tr>
							<tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Columns', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<input type="text" name="tzfd_dashboard_columns" value="<?php echo esc_attr(( ( $tzfd_key = get_option( 'tzfd_dashboard_columns' ) ) !== false ) ? $tzfd_key : 'TIME,SERVICE,data'); ?>"  style="width:80%;" /><br />
                                    <em><?php print esc_html(__('Sample column names', 'titan-zero-trades-front-desk')); ?>: final_price,formname,...<?php print esc_html(__('Click for more info about supported column names', 'titan-zero-trades-front-desk')); ?>.</em>
								</td>
							</tr>
                            <tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Column labels', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<input type="text" name="tzfd_dashboard_columnlabels" value="<?php echo esc_attr(( ( $tzfd_key = get_option( 'tzfd_dashboard_columnlabels' ) ) !== false ) ? $tzfd_key : 'Time,Service,Data'); ?>"  style="width:80%;" /><br />
                                    <em><?php print esc_html(__('Visual column names at the top of the table', 'titan-zero-trades-front-desk')); ?>.</em>
								</td>
							</tr>
                            <tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Required User Level', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<?php $cap = get_option( 'tzfd_dashboard_capability', 'manage_options' ); ?>
									<select name="tzfd_dashboard_capability">
										<option value="manage_options" <?php selected( $cap, 'manage_options' ); ?>><?php esc_html_e('Administrator', 'titan-zero-trades-front-desk'); ?></option>
										<option value="edit_pages" <?php selected( $cap, 'edit_pages' ); ?>><?php esc_html_e('Editor', 'titan-zero-trades-front-desk'); ?></option>
										<option value="publish_posts" <?php selected( $cap, 'publish_posts' ); ?>><?php esc_html_e('Author', 'titan-zero-trades-front-desk'); ?></option>
										<option value="edit_posts" <?php selected( $cap, 'edit_posts' ); ?>><?php esc_html_e('Contributor', 'titan-zero-trades-front-desk'); ?></option>
										<option value="read" <?php selected( $cap, 'read' ); ?>><?php esc_html_e('Subscriber', 'titan-zero-trades-front-desk'); ?></option>
									</select><br />
                                    <em><?php print esc_html(__('Minimum user role required to see the dashboard widget.', 'titan-zero-trades-front-desk')); ?></em>
								</td>
							</tr>
                            <tr>
								<td style="white-space:nowrap;width:200px;" valign="top"><?php esc_html_e('Compact view?', 'titan-zero-trades-front-desk');?>:</td>
								<td>
									<input type="checkbox" name="tzfd_dashboard_compactview" value="checked" <?php echo esc_attr( ( get_option( 'tzfd_dashboard_compactview' )  != '' ) ? " checked " : '' ); ?> />
                                    <em><?php print esc_html(__('Joins consecutive time-slots of the same booking', 'titan-zero-trades-front-desk')); ?>.</em>
								</td>
							</tr>
						</table>
						<input type="submit" name="subbtnds" value="<?php esc_attr_e('Save settings', 'titan-zero-trades-front-desk');?>" />
					</div>
					<input type="hidden" name="tzfd_dashboard" value="1" />
					<input type="hidden" name="_tzfd_nonce_dashboard" value="<?php echo esc_attr(wp_create_nonce( 'tzfd_dashboard_settings' )); ?>" />
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
            $required_cap = get_option('tzfd_dashboard_capability', 'manage_options');
            if ( current_user_can( $required_cap ) ) {
        	    wp_add_dashboard_widget('dashboard_widget', __('Titan Zero Trades: Front Desk', 'titan-zero-trades-front-desk'), array(&$this, 'pp_DashboardWidget'));
            }
        }

        /************************ PRIVATE METHODS *****************************/



        /**
         * At-a-glance counts and quick links, shown above the appointment list.
         * Counts come from the bookings already loaded by the widget, so no extra
         * booking query is run. Quote counts are read only when the quotes table exists.
         */
        private function render_glance( $tzfd_events )
        {
            global $wpdb;
            $tzfd_today = current_time( 'Y-m-d' );
            $tzfd_week  = gmdate( 'Y-m-d', strtotime( $tzfd_today . ' +6 days' ) );
            $n_today = 0; $n_week = 0; $n_pending = 0;

            foreach ( (array) $tzfd_events as $tzfd_item )
            {
                $tzfd_data = TZFD_Stored_Data::decode_booking( $tzfd_item->posted_data );
                if ( ! is_array( $tzfd_data ) || empty( $tzfd_data['apps'] ) || ! is_array( $tzfd_data['apps'] ) ) continue;
                foreach ( $tzfd_data['apps'] as $tzfd_app )
                {
                    $date  = isset( $tzfd_app['date'] ) ? (string) $tzfd_app['date'] : '';
                    $state = isset( $tzfd_app['cancelled'] ) ? (string) $tzfd_app['cancelled'] : '';
                    if ( $date === '' || strpos( $state, 'Cancelled' ) === 0 ) continue;
                    if ( $date === $tzfd_today ) $n_today++;
                    if ( $date >= $tzfd_today && $date <= $tzfd_week ) $n_week++;
                    if ( $date >= $tzfd_today && $state === 'Pending' ) $n_pending++;
                }
            }

            // Quotes: only when the quotes engine is loaded and its table exists.
            $q_draft = null; $q_sent = null; $quotes_on = false;
            if ( class_exists( 'TZFD_Quotes_Engine' ) )
            {
                $tzfd_quotes_table = $wpdb->prefix . TZFD_Quotes_Engine::T_QUOTES;
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tzfd_quotes_table ) ) === $tzfd_quotes_table )
                {
                    $quotes_on = true;
                    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $tzfd_quotes_table ) );
                    $q_draft = 0; $q_sent = 0;
                    foreach ( (array) $rows as $row )
                    {
                        if ( $row->status === 'draft' ) $q_draft = (int) $row->n;
                        if ( $row->status === 'sent' )  $q_sent  = (int) $row->n;
                    }
                }
            }

            $cells = array(
                array( __( 'Today', 'titan-zero-trades-front-desk' ),                    $n_today ),
                array( __( 'Next 7 days', 'titan-zero-trades-front-desk' ),              $n_week ),
                array( __( 'Awaiting approval', 'titan-zero-trades-front-desk' ),        $n_pending ),
            );
            if ( $quotes_on )
            {
                $cells[] = array( __( 'Quotes to send', 'titan-zero-trades-front-desk' ),          $q_draft );
                $cells[] = array( __( 'Quotes waiting for customer', 'titan-zero-trades-front-desk' ), $q_sent );
            }

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
            $links = array( array( __( 'Calendars', 'titan-zero-trades-front-desk' ), admin_url( 'admin.php?page=tzfd_calendar&manage=1' ) ) );
            $first_calendar = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM %i', $wpdb->prefix . 'tzfd_forms' ) );
            if ( $first_calendar )
            {
                $links[] = array( __( 'Add booking', 'titan-zero-trades-front-desk' ),  admin_url( 'admin.php?page=tzfd&cal=' . $first_calendar . '&addbk=1' ) );
                $links[] = array( __( 'Block times', 'titan-zero-trades-front-desk' ),  admin_url( 'admin.php?page=tzfd&cal=' . $first_calendar . '&blocktimes=1' ) );
            }
            if ( $quotes_on )
            {
                $links[] = array( __( 'New quote', 'titan-zero-trades-front-desk' ), admin_url( 'admin.php?page=tzfd-quotes&qtab=new' ) );
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

            $columns = str_replace('>','',str_replace('<','',str_replace('%','',get_option( 'tzfd_dashboard_columns', $columns))));
            $columnlabels = wp_strip_all_tags(get_option( 'tzfd_dashboard_columnlabels', $columnlabels));

            if (get_option( 'tzfd_dashboard_compactview' ) != '') $compact = true; else $compact = false; 
            $maxitems = get_option( 'tzfd_dashboard_maxitems' );
            if ($maxitems == '')
                $maxitems = 10;
            else
                $maxitems = intval($maxitems);
            wp_enqueue_script( "jquery" );

            $from = gmdate( 'Y-m-d', strtotime( $datefrom ) );
            $to   = gmdate( 'Y-m-d', strtotime( $dateto ) );

            $table_messages = $wpdb->prefix . "tzfd_messages";
            $table_items    = $wpdb->prefix . "tzfd_forms";

            // Table names are bound as %i identifiers and the calendar id as %d. The former raw-SQL filter on this query was removed because raw SQL cannot be prepared.
            if ( $calendar ) {
                $tzfd_events = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM %i INNER JOIN %i ON %i.formid = %i.id WHERE formid = %d ORDER BY time DESC",
                    $table_messages, $table_items, $table_messages, $table_items, $calendar
                ) );
            } else {
                $tzfd_events = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM %i INNER JOIN %i ON %i.formid = %i.id ORDER BY time DESC",
                    $table_messages, $table_items, $table_messages, $table_items
                ) );
            }

            $this->render_glance( $tzfd_events );
            echo '<h2 class="tzfd-section-title">'.esc_html(__('Next','titan-zero-trades-front-desk'))." ".intval($maxitems)." ".esc_html(__('upcoming appointments','titan-zero-trades-front-desk')).'</h2>';

            // pre-select time-slots
            $tzfd_selection = array();
            foreach($tzfd_events as $tzfd_item)
            {
                $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
                if (is_array($tzfd_data) && isset($tzfd_data["apps"]) && is_array($tzfd_data["apps"]) && (!$paidonly || $tzfd_data['paid']))
                {
                    foreach($tzfd_data["apps"] as $tzfd_app)
                        if ($tzfd_app["date"] >= $from && $tzfd_app["date"] <= $to && ($status == '-1' || $status == $tzfd_app["cancelled"])
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
                        if ($time1[1] == $time2[0])
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
                echo esc_html(__('No upcoming appointments found','titan-zero-trades-front-desk'));
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
                echo '<tr class="'.($tzfd_selection[$tzfd_i][6]!=''?' tzfd_cancelled':'').'">';
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
                            $tzfd_value = substr($tzfd_selection[$tzfd_i][5],strpos($tzfd_selection[$tzfd_i][5],"\n\n")+2);
                            break;
                        case 'paid':
                            $tzfd_value = ($tzfd_selection[$tzfd_i][3]['paid']?__('Yes','titan-zero-trades-front-desk'):$nbsp);
                            break;
                        default:
                            $tzfd_value = (!isset($tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]]) || $tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]]==''?$nbsp:$tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]]).$nbsp;
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