<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/*
    SingleDaysSelection Addon
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once dirname( __DIR__ ) . '/core/base.addon.php';

if( !class_exists( 'TZFD_SingleDaysSelection' ) )
{
    class TZFD_SingleDaysSelection extends TZFD_BaseAddon
    {
        /** Keeps the posted value only when it is one of the allowed values; otherwise the fallback. */
        private static function allowed_value( $value, array $allowed, $fallback = '' )
        {
            return in_array( $value, $allowed, true ) ? $value : $fallback;
        }

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::info_block( __( 'This add-on has no settings. Turn it on or off on the Add-ons list.', 'titan-zero-trades-front-desk' ) );
        }

        

        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-SingleDaysSelection";
		protected $name = "Single Days Selection Interface";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

		public function get_addon_form_settings( $form_id )
		{
			global $wpdb, $tzfd_plugin;

			// Insertion in database
			// Nonce and capability are checked in this condition before any request value is read.
			if(
				isset( $_POST[ 'TZFD_SingleDaysSelection_id' ] ) && isset( $_POST['anonce'] ) && current_user_can( 'edit_pages' ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['anonce'] ) ), 'tzfd_actions_admin' )
			)
			{
			    $wpdb->delete( $wpdb->prefix."tzfd_SingleDaysSelection", array( 'formid' => $form_id ), array( '%d' ) );
				$wpdb->insert(
								$wpdb->prefix."tzfd_SingleDaysSelection",
								array(
									'formid' => $form_id,
                                    'autosel_enable'	 => self::allowed_value( sanitize_text_field( wp_unslash( $_POST["sds_autosel_enable"] ?? '' ) ), array( '0', '1' ), '0' ),
									'numberOfDays'	 => absint( sanitize_text_field( wp_unslash( $_POST["sds_numberOfDays"] ?? '' ) ) ),
                                    'numberOfSlots'	 => absint( sanitize_text_field( wp_unslash( $_POST["sds_numberOfSlots"] ?? '' ) ) ),

                                    'calendarCSSClass'	 => sanitize_text_field(wp_unslash( $_POST["sds_calendarCSSClass"] ?? '' )),
                                    'formatDate'	 => sanitize_text_field(wp_unslash( $_POST["sds_formatDate"] ?? '' )),
                                    'str_more_slots'	 => sanitize_text_field(wp_unslash( $_POST["sds_str_more_slots"] ?? '' )),
                                    'str_more_days'	 => sanitize_text_field(wp_unslash( $_POST["sds_str_more_days"] ?? '' )),

								),
								array( '%d', '%d', '%d', '%d',
                                       '%s', '%s', '%s', '%s')
							);
			}


			$tzfd_rows = $wpdb->get_results(
						$wpdb->prepare( "SELECT * FROM %i WHERE formid=%d", $wpdb->prefix."tzfd_SingleDaysSelection", $form_id )  
					);
			if (!count($tzfd_rows))
			{
                $row["autosel_enable"] = 0;
			    $row["numberOfDays"] = 6;
			    $row["numberOfSlots"] = 6;
                $row["calendarCSSClass"] = "custom";
                $row["formatDate"] = "DD dd MM yy";
                $row["str_more_slots"] = "Show more slots";
                $row["str_more_days"] = "Show more days";
			} else {
                $row["autosel_enable"] = $tzfd_rows[0]->autosel_enable;
			    $row["numberOfDays"] = $tzfd_rows[0]->numberOfDays;
			    $row["numberOfSlots"] = $tzfd_rows[0]->numberOfSlots;;
                $row["calendarCSSClass"] = $tzfd_rows[0]->calendarCSSClass;
                $row["formatDate"] = $tzfd_rows[0]->formatDate;
                $row["str_more_slots"] = $tzfd_rows[0]->str_more_slots;
                $row["str_more_days"] = $tzfd_rows[0]->str_more_days;
            }


			?>
			<div id="metabox_basic_settings" class="postbox" >
				<h3 class='hndle' style="padding:5px;"><span><?php print esc_html($this->name); ?></span></h3>
				<div class="inside">
				   <input type="hidden" name="TZFD_SingleDaysSelection_id" value="1" />
                     <?php esc_html_e('Enable alternative time slot selection interface for this booking form?','titan-zero-trades-front-desk'); ?>:<br />
                        <?php $tzfd_option = $row['autosel_enable']; ?>
                        <select name="sds_autosel_enable" id="sds_autosel_enable">
                          <option value="0"<?php if ($tzfd_option != '1') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
                          <option value="1"<?php if ($tzfd_option == '1') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
                         </select><br /><br />

                   <div id="sds_autoselhide_yes" <?php if ($tzfd_option != '1') echo ' style="display:none"'; ?>>

                     <div style="margin-bottom:10px;">
                        <?php esc_html_e('Number of days to display initially','titan-zero-trades-front-desk'); ?>:<br />
                        <input type="text" name="sds_numberOfDays" id="sds_numberOfDays" value="<?php echo esc_attr($row["numberOfDays"]); ?>" size="5"><br />
                     </div>

                     <div style="margin-bottom:10px;">
                     <?php esc_html_e('Number of slots to display initially','titan-zero-trades-front-desk'); ?>:<br />
                        <input type="text" name="sds_numberOfSlots" id="sds_numberOfSlots" value="<?php echo esc_attr($row["numberOfSlots"]); ?>" size="5"><br />
                     </div>

                     <div style="display:none">
                        <?php esc_html_e('CSS class for the calendar','titan-zero-trades-front-desk'); ?>:<br />
                        <input type="text" name="sds_calendarCSSClass" id="sds_calendarCSSClass" value="<?php echo esc_attr($row["calendarCSSClass"]); ?>"><br />
                     </div>

                     <div style="margin-bottom:10px;">
                        <?php esc_html_e('Date format','titan-zero-trades-front-desk'); ?>:<br />
                        <input type="text" name="sds_formatDate" id="sds_formatDate" value="<?php echo esc_attr($row["formatDate"]); ?>"><br />
                        <em><a href="https://api.jqueryui.com/datepicker/#:~:text=into%20a%20string%20value%20with%20a%20specified%20format.-,The%20format%20can%20be%20combinations%20of%20the%20following,-%3A" target="_blank"><?php esc_html_e('click for sample date formats','titan-zero-trades-front-desk'); ?></a></em><br>
                     </div>

                     <div style="margin-bottom:10px;">
                        <?php esc_html_e('Text for "more slots"','titan-zero-trades-front-desk'); ?>:<br />
                        <input type="text" name="sds_str_more_slots" id="sds_str_more_slots" value="<?php echo esc_attr($row["str_more_slots"]); ?>"><br />
                     </div>

                     <div style="margin-bottom:10px;">
                        <?php esc_html_e('Text for "more days"','titan-zero-trades-front-desk'); ?>:<br />
                        <input type="text" name="sds_str_more_days" id="sds_str_more_days" value="<?php echo esc_attr($row["str_more_days"]); ?>"><br />
                     </div>

                   </div>
				</div>
			</div>
			<?php
		} // end get_addon_form_settings



		/************************ ADDON CODE *****************************/

        /************************ ATTRIBUTES *****************************/

        private $form_table = 'tzfd_SingleDaysSelection';
        private $_inserted = false;

        /************************ CONSTRUCT *****************************/

        function __construct()
        {
			$this->description = $this->tr_apply("Alternative time slot selection interface that does not display a calendar, but only available dates.", 'titan-zero-trades-front-desk' );
            // Check if the plugin is active
			if( !$this->addon_is_active() ) return;

            add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_admin_assets' ) );
            add_filter( 'tzfd_the_form', array( &$this, 'enqueue_script' ), 99, 2 );
            add_filter( 'tzfd_the_form', array( &$this, 'insert_style'), 99, 2 );
            add_filter( 'tzfd_csslayout', array( &$this, 'pp_tzfd_csslayout' ), 10, 2);

            $this->update_database();

        } // End __construct

        /**
         * Loads the admin script for the time-slot option on the plugin's admin screens.
         */
        public function enqueue_admin_assets( $hook_suffix )
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value used to choose the admin screen; no state is changed.
            $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            if ( 0 !== strpos( $page, 'tzfd' ) ) { return; }
            wp_enqueue_script( 'tzfd-book-calendarless', plugins_url( 'js/inline/book-calendarless.js', dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk.php' ), array(), '2.0.1', true );
        }


        /************************ PRIVATE METHODS *****************************/

		/**
         * Create the database tables
         */
        protected function update_database() {
            global $wpdb;

            $charset_collate = $wpdb->get_charset_collate();
            $tzfd_table_name      = $wpdb->prefix . 'tzfd_SingleDaysSelection';

            // Note: dbDelta does not use "IF NOT EXISTS"
            $sql = "CREATE TABLE {$tzfd_table_name} (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    formid INT NOT NULL,
                    autosel_enable INT NOT NULL,
                    numberOfDays INT NOT NULL,
                    numberOfSlots INT NOT NULL,
                    calendarCSSClass TEXT,
                    formatDate TEXT,
                    str_more_slots TEXT,
                    str_more_days TEXT,
                    UNIQUE KEY id (id)
                ) $charset_collate;";

            // Include the WordPress upgrade library and run dbDelta
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

        } // end update_database


		/************************ PUBLIC METHODS  *****************************/



        public function pp_tzfd_csslayout ($CSSClass, $formid)
        {
            global $wpdb, $tzfd_plugin;



            $myrow = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE formid=%d AND autosel_enable=1", $wpdb->prefix."tzfd_SingleDaysSelection", $formid ) );  
            if (!count($myrow))
                return $CSSClass;

            return $myrow[0]->calendarCSSClass.' '.$CSSClass;
        }



		/**
         * Enqueues the calendarless script for a form on the page that shows the form (tzfd_the_form).
         * The six settings go through wp_localize_script(); the script body is js/inline/CALENDAR-JS-2-calendarless.js.
         */
        public function	enqueue_script( $form_code, $id )
		{
            global $wpdb;

            $myrow = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE formid=%d AND autosel_enable=1", $wpdb->prefix."tzfd_SingleDaysSelection", $id ) );
            if (!count($myrow))
                return $form_code;

            $handle = 'tzfd-calendarless-' . intval( $id );
            // Same dependency as the form's own script, so the form is set up before this runs.
            $deps = array( 'jquery' );
            $deferred = wp_script_is( 'tzfd_frontdesk_builder_script', 'registered' );
            if ( $deferred ) $deps[] = 'tzfd_frontdesk_builder_script';

            wp_register_script( $handle, plugins_url( 'js/inline/CALENDAR-JS-2-calendarless.js', dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk.php' ), $deps, '2.0.1', true );
            wp_localize_script( $handle, 'tzfdCalendarless', array(
                'numberOfDays'     => intval( $myrow[0]->numberOfDays ),
                'numberOfSlots'    => intval( $myrow[0]->numberOfSlots ),
                'calendarCSSClass' => (string) $myrow[0]->calendarCSSClass,
                'formatDate'       => (string) $myrow[0]->formatDate,
                'str_more_slots'   => (string) $myrow[0]->str_more_slots,
                'str_more_days'    => (string) $myrow[0]->str_more_days,
            ) );

            if ( $deferred ) {
                wp_enqueue_script( $handle );
                return $form_code;
            }
            // Legacy loading prints the form's scripts inside the form markup, so this script is printed in the same place.
            ob_start();
            wp_print_scripts( array( $handle ) );
            $printed = ob_get_clean();
            return $form_code . $printed;
        } // end enqueue_script


		/**
         * Check if the add-on is used in the form, and inserts the insert_style tag
         */
        public function	insert_style( $form_code, $id )
		{
            global $wpdb, $tzfd_plugin;

            $is_enable = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE formid=%d AND autosel_enable=1", $wpdb->prefix."tzfd_SingleDaysSelection", $id ) );  
            if (!count($is_enable))
                return $form_code;
            ob_start();
?>
.datelink{border:1px solid #bbbbbb;border-radius: 15px;margin:5px 0px}
.more_slots{width:100%;text-align:center;padding:5px;border-top:1px solid #bbbbbb !important}
.more_slots a{color:#1278C7;cursor:pointer;display:block}
.datelink > a{text-align:center;display:block;padding:5px;cursor:pointer;}
#tzfdform .slots{border-top:1px solid #bbbbbb !important;}
.datelink.activelink>a{font-weight:bold}
.more_days{border:2px solid #1278C7;border-radius: 15px;padding:5px;margin:5px 0px;text-align:center}
.more_days a{color:#1278C7;cursor:pointer;display:block}
.datelink>a:before{content:"";position:absolute;right:0px;width: 10px;height: 10px;border: none;border-top: 2px solid #1278C7; !important;border-right: 2px solid #1278C7;;background: none;margin: 10px 20px;transform: rotate(135deg);}
.datelink.activelink>a:before {transform: rotate(-45deg) !important;margin: 15px 20px;}
.slots>span{display:none !important}
.slots>br {content: "";}
.slots{padding:10px !important}
#tzfdform .slotsCalendar{display:block}
<?php
            $buffered_contents = ob_get_contents();
            ob_end_clean();


            // The CSS goes through the style API instead of a <style> tag in the form HTML.
            self::enqueue_form_css( $buffered_contents );
			return $form_code;
		} // End insert_recaptcha

        /**
         * Adds the form CSS to a registered handle. Added once per request.
         */
        private static function enqueue_form_css( $css )
        {
            static $added = false;
            if ( $added ) { return; }
            $added = true;
            wp_register_style( 'tzfd-book-calendarless', false, array(), '2.0.1' );
            wp_add_inline_style( 'tzfd-book-calendarless', $css );
            wp_enqueue_style( 'tzfd-book-calendarless' );
        }



    } // End Class

    // Main add-on code
    $TZFD_SingleDaysSelection_obj = new TZFD_SingleDaysSelection();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $TZFD_SingleDaysSelection_obj->get_addon_id() ] = $TZFD_SingleDaysSelection_obj;
}

