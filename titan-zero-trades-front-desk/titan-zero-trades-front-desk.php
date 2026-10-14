<?php
/*
Plugin Name: Titan Zero Trades: Front Desk
Plugin URI: https://titanzero.io/
Description: Appointments, service quotes and a guided setup for trades and service businesses. Free, with no account or licence key.
Version: 2.0.1
Requires at least: 6.2
Requires PHP: 7.4
Author: Titan Zero
Author URI: https://titanzero.io/
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: titan-zero-trades-front-desk
*/

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

include_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-status-api.php';

/**
 * Translate persisted values without passing dynamic data to the
 * literal-string translation helpers.
 */
function titan_zero_front_desk_translate_legacy_value( $tzfd_value ) {
    if ( ! is_string( $tzfd_value ) && ! is_int( $tzfd_value ) && ! is_float( $tzfd_value ) ) {
        return '';
    }
    $tzfd_value = (string) $tzfd_value;
    if ( function_exists( 'get_translations_for_domain' ) ) {
        return get_translations_for_domain( 'titan-zero-trades-front-desk' )->translate( $tzfd_value );
    }
    return $tzfd_value;
}

//define("TZFD_DYNAMIC_LOADING", true);

define('TZFD_BOOK_DEFER_SCRIPTS_LOADING', (get_option('TZFD_LOAD_SCRIPTS',"1") == "1"?true:false));

define('TZFD_BOOK_DEFAULT_form_structure', '[[{"form_identifier":"","name":"fieldname1","shortlabel":"","index":0,"ftype":"fapp","userhelp":"","userhelpTooltip":false,"csslayout":"","title":"Appointment","services":[{"name":"Service 1","price":1,"duration":60}],"openhours":[{"name":"Default","openhours":[{"type":"all","d":"","h1":8,"m1":0,"h2":17,"m2":0}]}],"allOH":[{"name":"Default","openhours":[{"type":"all","d":"","h1":8,"m1":0,"h2":17,"m2":0}]}],"dateFormat":"mm/dd/yy","showDropdown":false,"dropdownRange":"-10:+10","working_dates":[true,true,true,true,true,true,true],"numberOfMonths":1,"firstDay":0,"minDate":"0","maxDate":"","defaultDate":"","invalidDates":"","required":true,"fBuild":{}},{"form_identifier":"","name":"email","shortlabel":"","index":1,"ftype":"femail","userhelp":"","userhelpTooltip":false,"csslayout":"","title":"Email","predefined":"","predefinedClick":false,"required":true,"size":"medium","equalTo":"","fBuild":{}}],[{"title":"","description":"","formlayout":"top_aligned","formtemplate":"tzfd_m5","evalequations":1,"autocomplete":1}]]');


define('TZFD_BOOK_DEFAULT_track_IP', true);

define('TZFD_BOOK_DEFAULT_fp_subject', 'Notification to administrator: Booking request received...');
define('TZFD_BOOK_DEFAULT_fp_inc_additional_info', 'true');
define('TZFD_BOOK_DEFAULT_fp_return_page', get_site_url());
define('TZFD_BOOK_DEFAULT_fp_message', "The following booking request has been received:\n\n<%INFO%>\n\n");

define('TZFD_BOOK_DEFAULT_cu_enable_copy_to_user', 'true');
define('TZFD_BOOK_DEFAULT_cu_user_email_field', 'email');
define('TZFD_BOOK_DEFAULT_cu_subject', 'Confirmation: Your booking has been received...');
define('TZFD_BOOK_DEFAULT_cu_message', "Your appointment is received. We look forward to seeing you then.\n\nThis is a copy of the data sent:\n\n<%INFO%>\n\nBest Regards.");
define('TZFD_BOOK_DEFAULT_email_format','text');

define('TZFD_BOOK_DEFAULT_vs_use_validation', 'true');

define('TZFD_BOOK_DEFAULT_vs_text_is_required', 'This field is required.');
define('TZFD_BOOK_DEFAULT_vs_text_is_email', 'Please enter a valid email address.');

define('TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy', 'Please enter a valid date with this format(mm/dd/yyyy)');
define('TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy', 'Please enter a valid date with this format(dd/mm/yyyy)');
define('TZFD_BOOK_DEFAULT_vs_text_number', 'Please enter a valid number.');
define('TZFD_BOOK_DEFAULT_vs_text_digits', 'Please enter only digits.');
define('TZFD_BOOK_DEFAULT_vs_text_max', 'Please enter a value less than or equal to {0}.');
define('TZFD_BOOK_DEFAULT_vs_text_min', 'Please enter a value greater than or equal to {0}.');
define('TZFD_BOOK_DEFAULT_vs_text_maxapp', 'Please select a max of  {0} appointments per customer.');

define('TZFD_BOOK_DEFAULT_cv_enable_captcha', 'true');
define('TZFD_BOOK_DEFAULT_cv_width', '180');
define('TZFD_BOOK_DEFAULT_cv_height', '60');
define('TZFD_BOOK_DEFAULT_cv_chars', '5');
define('TZFD_BOOK_DEFAULT_cv_font', 'font1');
define('TZFD_BOOK_DEFAULT_cv_min_font_size', '25');
define('TZFD_BOOK_DEFAULT_cv_max_font_size', '35');
define('TZFD_BOOK_DEFAULT_cv_noise', '200');
define('TZFD_BOOK_DEFAULT_cv_noise_length', '4');
define('TZFD_BOOK_DEFAULT_cv_background', 'ffffff');
define('TZFD_BOOK_DEFAULT_cv_border', 'ffffff');
define('TZFD_BOOK_DEFAULT_cv_text_enter_valid_captcha', 'Please enter a valid captcha code.');

define('TZFD_BOOK_REP_ARR', '[+arr1237]');

define ('TZFD_SCHCALTITLE_ADMIN', '%email%');
define ('TZFD_SCHCALCONTENT_ADMIN', '%INFO%');
define ('TZFD_SCHCALTITLE_PUBLIC', 'Booked');
define ('TZFD_SCHCALCONTENT_PUBLIC', 'This time-slot is booked');

include_once dirname( __FILE__ ) . '/includes/class-tzfd-addon-loader.php';

// loading add-ons
// -----------------------------------------
global $tzfd_addons_active_list, // List of addon IDs
	   $tzfd_addons_objs_list; // List of addon objects

$tzfd_addons_active_list = array();
if (!is_array($tzfd_addons_objs_list))
    $tzfd_addons_objs_list	 = array();

function tzfd_loading_add_ons()
{
	global $tzfd_addons_active_list, // List of addon IDs
		   $tzfd_addons_objs_list; // List of addon objects

    // Get the list of active addons
	$tzfd_addons_active_list = get_option( 'tzfd_addons_active_list', array() );
    if (!is_array($tzfd_addons_active_list))
        $tzfd_addons_active_list	 = array();
    
    // --- ONE-TIME CHECK TO ENABLE CACHE ADD-ON ---
    //if ( ! get_option( 'tzfd_cache_addon_initializeda', false ) ) {
    //    if ( ! in_array( 'tzfd-Cache', $tzfd_addons_active_list ) ) {
    //        $tzfd_addons_active_list[] = 'tzfd-Cache';
    //        update_option( 'tzfd_addons_active_list', $tzfd_addons_active_list );
    //    }
    //    update_option( 'tzfd_cache_addon_initializeda', '1' );
    //}
    // ---------------------------------------------
    
	if( !empty( $tzfd_addons_active_list )
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
        || ( isset( $_GET["page"] ) && sanitize_text_field( wp_unslash( $_GET["page"] ?? '' ) ) == "tzfd" )
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
        || ( isset( $_GET["page"] ) && sanitize_text_field( wp_unslash( $_GET["page"] ?? '' ) ) == "tzfd_addons" )
        // The setup wizard's page must exist even when no add-on is switched on, or WordPress shows "not allowed".
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for routing; this code path changes no state.
        || ( isset( $_GET["page"] ) && sanitize_text_field( wp_unslash( $_GET["page"] ?? '' ) ) == "tzfd-setup" )
      )
	{
		$path = dirname( __FILE__ ).'/addons';
		// Group folders are listed by TZFD_Addon_Loader, core first.
		foreach ( TZFD_Addon_Loader::files( $path ) as $tzfd_addon_file )
		{
			require_once $tzfd_addon_file;
		}
	}
}



/* initialization / install */

include_once dirname( __FILE__ ) . '/classes/tzfd-base-class.inc.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-scheduling-rules.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-intake-mode.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-admin-menu.php';
TZFD_Admin_Menu::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-admin-notices.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-ai-options.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-brand.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-view.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-admin-layout.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-service-details.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-money.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-payments.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-dashboard-figures.php';
TZFD_Money::register();
TZFD_Payments::register();
TZFD_Admin_Notices::register();
TZFD_Brand::register();
TZFD_Admin_Layout::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-addon-support.php';
include_once dirname( __FILE__ ) . '/tzfd-main-class.inc.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-scheduling-plugin.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-scheduling-preview.php';
TZFD_Scheduling_Preview::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-integration-events.php';
TZFD_Integration_Events::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-webhooks.php';
TZFD_Webhooks::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-payment-events.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-privacy-exporter.php';
TZFD_Privacy_Exporter::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-resource-admin.php';
TZFD_Resource_Admin::register();
TZFD_Service_Details::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-crm.php';
TZFD_CRM::register();
// Booking pipeline: managers, approval and supervisor for assistant and channel bookings.
include_once dirname( __FILE__ ) . '/includes/class-tzfd-pipeline-rules.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-pipeline.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-pipeline-screen.php';
TZFD_Pipeline::register();
TZFD_Pipeline_Screen::register();


// Schema class used by the receptionist features. Tables are created on activation and again
// when their schema version changes. The quotes tables belong to the Quotes add-on.
include_once dirname( __FILE__ ) . '/includes/class-tzfd-receptionist-schema.php';
register_activation_hook(__FILE__, array('TZFD_Receptionist_Schema', 'maybe_install'));
add_action('init', array('TZFD_Receptionist_Schema', 'maybe_install'));

$tzfd_plugin = new TZFD_Scheduling_Plugin;
add_action('tzfd_process_data_before_insert', array($tzfd_plugin, 'begin_capacity_reservation'), PHP_INT_MIN, 1);
add_action('tzfd_process_data', array($tzfd_plugin, 'finish_capacity_reservation'), PHP_INT_MIN, 1);

register_activation_hook(__FILE__, array($tzfd_plugin,'install') );

// Customer self-service, status emails, and the messaging and sync framework. These load on every
// site. Each one stays idle until it is used: status emails are sent only when an administrator
// changes a booking's status, and the vendor adapters that the framework classes expect are added
// by separate add-ons.
include_once dirname( __FILE__ ) . '/includes/class-tzfd-customer-cancellation.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-customer-rescheduling.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-client-portal.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-status-update-emails.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-addon-records.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-outbox.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-sync-queue.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-sms-provider.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-gateway-addon.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-captcha-gate.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-sync-addon.php';
( new TZFD_Customer_Cancellation() )->register();
( new TZFD_Customer_Rescheduling( $tzfd_plugin ) )->register();
( new TZFD_Client_Portal() )->register();
( new TZFD_Status_Update_Emails() )->register();
TZFD_Outbox::boot();

// Scheduled events stop when the plugin is deactivated, so nothing keeps running without it.
// uninstall.php clears the same list when the plugin is deleted; keep the two lists in step.
function tzfd_clear_scheduled_events() {
    $hooks = include dirname( __FILE__ ) . '/includes/tzfd-cron-hooks.php';
    require_once dirname( __FILE__ ) . '/includes/tzfd-cron-clear.php';
    foreach ( $hooks as $hook ) {
        tzfd_unschedule_all_events( $hook );
    }
}
register_deactivation_hook( __FILE__, 'tzfd_clear_scheduled_events' );
add_action( 'media_buttons', array($tzfd_plugin, 'insert_button'), 11);
add_action( 'init', array($tzfd_plugin, 'data_management'));
add_action( 'wp_loaded', array($tzfd_plugin, 'data_management_loaded'));
add_action( 'plugins_loaded', array($tzfd_plugin, 'data_management_pluginsloaded'), -1);

if ( is_admin() ) {

	// The AI page is in the Front Desk menu on every admin screen, so its file always loads.
	require_once dirname( __FILE__ ).'/addons/core/installer.addon.php';

    add_action('admin_enqueue_scripts', array($tzfd_plugin,'insert_adminScripts'), 1);
    add_filter("plugin_action_links_".plugin_basename(__FILE__), array($tzfd_plugin,'plugin_page_links'));
    add_action('admin_menu', array($tzfd_plugin,'admin_menu') );
    add_action('enqueue_block_editor_assets', array($tzfd_plugin,'gutenberg_block'));


} else {
    add_shortcode( $tzfd_plugin->shorttag, array($tzfd_plugin, 'filter_content') );
    add_shortcode( 'TZFD_FRONT_DESK_LIST', array($tzfd_plugin, 'filter_list') );
}

// register gutenberg block
if (function_exists('register_block_type'))
{
    add_action( 'init', 'tzfdhour_register_dynamic_block' );

    function tzfdhour_register_dynamic_block() {
        global $tzfd_plugin;

        register_block_type( 'tzfdhour/form-rendering', array(
            'api_version'     => 3,
            'attributes'      => array(
                'formId'      => array(
                    'type' => 'string',
                ),
                'instanceId'  => array(
                    'type' => 'string',
                ),
            ),
            'render_callback' => array( $tzfd_plugin, 'render_form_admin' ),
        ) );
    }
}

add_filter( 'block_categories_all', 'tzfdhour_register_block_category', 10, 2 );

function tzfdhour_register_block_category( $categories, $post ) {
    return array_merge(
        $categories,
        array(
            array(
                'slug'  => 'tzfdhour',
                'title' => __( 'Titan Zero Trades: Front Desk', 'titan-zero-trades-front-desk' ),
            ),
        )
    );
}




add_filter('autoptimize_filter_js_exclude', 'tzfdhour_autoptimize_filter_js_exclude' );
function tzfdhour_autoptimize_filter_js_exclude($excluded)
{
    return $excluded.",jquery.ui.datepicker-fr-CA.js,".
                     "jquery.ui.datepicker-es_ES.js,".
                     "jquery.ui.datepicker-no.js,".
                     "jquery.ui.datepicker-he_IL.js,".
                     "jquery.ui.datepicker-pt_BR.js,".
                     "jquery.ui.datepicker-ro_RO.js,".
                     "jquery.ui.datepicker-ru_RU.js,".
                     "jquery.ui.datepicker-sk_SK.js,".
                     "jquery.ui.datepicker-sl_SI.js,".
                     "jquery.ui.datepicker-sr_SR.js,".
                     "jquery.ui.datepicker-sv_SE.js,".
                     "jquery.ui.datepicker-tr_TR.js,".
                     "jquery.ui.datepicker-zh_CN.js,".
                     "jquery.ui.datepicker-zh_TW.js,".
                     "jquery.ui.datepicker-nl_NL.js,".
                     "jquery.ui.datepicker-pl_PL.js,".
                     "jquery.ui.datepicker-pt_PT.js,".
                     "jquery.ui.datepicker-bg_BG.js,".
                     "jquery.ui.datepicker-bs_BA.js,".
                     "jquery.ui.datepicker-cs_CZ.js,".
                     "jquery.ui.datepicker-da_DK.js,".
                     "jquery.ui.datepicker-de_DE.js,".
                     "jquery.ui.datepicker-eo_EO.js,".
                     "jquery.ui.datepicker-fa_IR.js,".
                     "jquery.ui.datepicker-fr_FR.js,".
                     "jquery.ui.datepicker-gl_ES.js,".
                     "jquery.ui.datepicker-hi_IN.js,".
                     "jquery.ui.datepicker-hu_HU.js,".
                     "jquery.ui.datepicker-hy_AM.js,".
                     "jquery.ui.datepicker-id_ID.js,".
                     "jquery.ui.datepicker-it_IT.js,".
                     "jquery.ui.datepicker-ka_GE.js,".
                     "jquery.ui.datepicker-ko_KR.js,".
                     "jquery.ui.datepicker-lt_LT.js,".
                     "jquery.ui.datepicker-ms_MY.js,".
                     "jquery.ui.datepicker-nb_NO.js,".
                     "jquery.ui.datepicker-be_BY.js,".
                     "jquery.ui.datepicker-mk_MK.js,".
                     "jquery.ui.datepicker-ml_IN.js,".
                     "jquery.ui.datepicker-az.js,".
                     "jquery.ui.datepicker-af.js,".
                     "jquery.ui.datepicker-ar.js,".
                     "jquery.ui.datepicker-ca.js,".
                     "jquery.ui.datepicker-el.js,".
                     "jquery.ui.datepicker-et.js,".
                     "jquery.ui.datepicker-eu.js,".
                     "jquery.ui.datepicker-fi.js,".
                     "jquery.ui.datepicker-hr.js,".
                     "jquery.ui.datepicker-ja.js,".
                     "jquery.ui.datepicker-lv.js,".
                     "jquery.ui.datepicker-sq.js,".
                     "jquery.ui.datepicker-ta.js,".
                     "jquery.ui.datepicker-th.js,".
                     "jquery.ui.datepicker-uk.js,".
                     "jquery.ui.datepicker-vi.js,".
                     "jquery.validate.min.js,jQuery.stringify.js,jquery.validate.js";
}


// code for compatibility with third party scripts

add_filter('litespeed_cache_optimize_js_excludes', 'tzfdhour_litespeed_cache_optimize_js_excludes' );
function tzfdhour_litespeed_cache_optimize_js_excludes($tzfd_options)
{
    return  "jquery.ui.datepicker-fr-CA.js\n".
                     "jquery.ui.datepicker-es_ES.js\n".
                     "jquery.ui.datepicker-no.js\n".
                     "jquery.ui.datepicker-he_IL.js\n".
                     "jquery.ui.datepicker-pt_BR.js\n".
                     "jquery.ui.datepicker-ro_RO.js\n".
                     "jquery.ui.datepicker-ru_RU.js\n".
                     "jquery.ui.datepicker-sk_SK.js\n".
                     "jquery.ui.datepicker-sl_SI.js\n".
                     "jquery.ui.datepicker-sr_SR.js\n".
                     "jquery.ui.datepicker-sv_SE.js\n".
                     "jquery.ui.datepicker-tr_TR.js\n".
                     "jquery.ui.datepicker-zh_CN.js\n".
                     "jquery.ui.datepicker-zh_TW.js\n".
                     "jquery.ui.datepicker-nl_NL.js\n".
                     "jquery.ui.datepicker-pl_PL.js\n".
                     "jquery.ui.datepicker-pt_PT.js\n".
                     "jquery.ui.datepicker-bg_BG.js\n".
                     "jquery.ui.datepicker-bs_BA.js\n".
                     "jquery.ui.datepicker-cs_CZ.js\n".
                     "jquery.ui.datepicker-da_DK.js\n".
                     "jquery.ui.datepicker-de_DE.js\n".
                     "jquery.ui.datepicker-eo_EO.js\n".
                     "jquery.ui.datepicker-fa_IR.js\n".
                     "jquery.ui.datepicker-fr_FR.js\n".
                     "jquery.ui.datepicker-gl_ES.js\n".
                     "jquery.ui.datepicker-hi_IN.js\n".
                     "jquery.ui.datepicker-hu_HU.js\n".
                     "jquery.ui.datepicker-hy_AM.js\n".
                     "jquery.ui.datepicker-id_ID.js\n".
                     "jquery.ui.datepicker-it_IT.js\n".
                     "jquery.ui.datepicker-ka_GE.js\n".
                     "jquery.ui.datepicker-ko_KR.js\n".
                     "jquery.ui.datepicker-lt_LT.js\n".
                     "jquery.ui.datepicker-ms_MY.js\n".
                     "jquery.ui.datepicker-nb_NO.js\n".
                     "jquery.ui.datepicker-be_BY.js\n".
                     "jquery.ui.datepicker-mk_MK.js\n".
                     "jquery.ui.datepicker-ml_IN.js\n".
                     "jquery.ui.datepicker-az.js\n".
                     "jquery.ui.datepicker-af.js\n".
                     "jquery.ui.datepicker-ar.js\n".
                     "jquery.ui.datepicker-ca.js\n".
                     "jquery.ui.datepicker-el.js\n".
                     "jquery.ui.datepicker-et.js\n".
                     "jquery.ui.datepicker-eu.js\n".
                     "jquery.ui.datepicker-fi.js\n".
                     "jquery.ui.datepicker-hr.js\n".
                     "jquery.ui.datepicker-ja.js\n".
                     "jquery.ui.datepicker-lv.js\n".
                     "jquery.ui.datepicker-sq.js\n".
                     "jquery.ui.datepicker-ta.js\n".
                     "jquery.ui.datepicker-th.js\n".
                     "jquery.ui.datepicker-uk.js\n".
                     "jquery.ui.datepicker-vi.js\n".
                     "jquery.validate.min.js\njQuery.stringify.js\njquery.validate.js\njquery.js\n".$tzfd_options;
}

add_filter('option_sbp_settings', 'tzfdhour_sbp_fix_conflict' );
function tzfdhour_sbp_fix_conflict($tzfd_option)
{
    if(!is_admin())
    {
       if(is_array($tzfd_option) && isset($tzfd_option['jquery_to_footer']))
           unset($tzfd_option['jquery_to_footer']);
    }
    return $tzfd_option;
}

function tzfdhour_tp_disable_filter()
{
    return false;
}

// elementor integration
include_once dirname( __FILE__ ) . '/controllers/elementor/tzfd-elementor-widget.inc.php';

add_action( 'init', function(){
    add_filter( 'get_post_metadata', function( $tzfd_v, $object_id, $meta_key, $single, $meta_type = '' ){
        if ( '_elementor_element_cache' == $meta_key ) {
            global $wpdb;
            if ( $wpdb->get_var( $wpdb->prepare( 
                'SELECT COUNT(*) FROM ' . $wpdb->postmeta . ' WHERE post_id=%d AND meta_key="_elementor_element_cache" AND meta_value LIKE %s;', 
                $object_id, 
                '%orm_structure%' 
            ) ) ) {
                return false;
            }
        }
        return $tzfd_v;
    }, 10, 5 );
} );


// prevent non-admin users from using the list shortcode
add_filter( 'content_save_pre', function ( $content ) {

    if ( current_user_can( 'manage_options' ) ) {
        return $content;
    }
    
    $content = preg_replace(
        '/\[TZFD_FRONT_DESK_LIST[^\]]*\]/',
        '',
        $content
    );

    return $content;
});


// Exclude from SiteGround Speed Optimizer JS Combination
add_filter( 'sgo_javascript_combine_exclude', 'tzfdhour_exclude_js_from_combination' );
function tzfdhour_exclude_js_from_combination( $exclude_list ) {
    $exclude_list[] = 'tzfd_frontdesk_language_file';
    $exclude_list[] = 'tzfd_frontdesk_builder_script';
    return $exclude_list;
}

// Exclude from SiteGround Speed Optimizer JS Minification
add_filter( 'sgo_js_minify_exclude', 'tzfdhour_exclude_js_from_minification' );
    function tzfdhour_exclude_js_from_minification( $exclude_list ) {
    $exclude_list[] = 'tzfd_frontdesk_language_file';
    $exclude_list[] = 'tzfd_frontdesk_builder_script';
    return $exclude_list;
}
