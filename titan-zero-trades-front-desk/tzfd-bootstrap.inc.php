<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';
/* Front Desk boot. Loads with standalone services or optional suite integrations. */
global $tz_plugin, $tz_addons_active_list, $tz_addons_objs_list;
require_once dirname( __FILE__ ) . '/includes/class-tzfd-business-schema.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-scoped-store.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-tenant-storage.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-intent-engine.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-knowledge-store.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-answer-cache.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-business-facts.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-embedding-provider.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-faq-search.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-form-access.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-vertical-registry.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-page.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-builtin-verticals.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-automation.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-status-api.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-addon-loader.php';

// loading add-ons
// -----------------------------------------
global $tz_addons_active_list, // List of addon IDs
	   $tz_addons_objs_list; // List of addon objects

$tz_addons_active_list = array();
if (!is_array($tz_addons_objs_list))
    $tz_addons_objs_list	 = array();

function tz_fd_loading_add_ons()
{
	global $tz_addons_active_list, // List of addon IDs
		   $tz_addons_objs_list; // List of addon objects

    // Get the list of active addons
	$tz_addons_active_list = TZFD_Scoped_Store::get_for_boot( 'tz_fd_addons_active_list', array() );
    if (!is_array($tz_addons_active_list))
        $tz_addons_active_list	 = array();
    
    // --- ONE-TIME CHECK TO ENABLE CACHE ADD-ON ---
    //if ( ! get_option( 'tz_fd_cache_addon_initializeda', false ) ) {
    //    if ( ! in_array( 'tzfd-Cache', $tz_addons_active_list ) ) {
    //        $tz_addons_active_list[] = 'tzfd-Cache';
    //        update_option( 'tz_fd_addons_active_list', $tz_addons_active_list );
    //    }
    //    update_option( 'tz_fd_cache_addon_initializeda', '1' );
    //}
    // ---------------------------------------------
    
	if( !empty( $tz_addons_active_list )
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
        || ( isset( $_GET["page"] ) && tzfd_request_text( 'get', "page" ) == "tzfd" )
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
        || ( isset( $_GET["page"] ) && in_array( tzfd_request_text( 'get', "page" ), array( "tz_fd_addons", "tzfd_addons" ), true ) )
        // The setup wizard's page must exist even when no add-on is switched on, or WordPress shows "not allowed".
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for routing; this code path changes no state.
        || ( isset( $_GET["page"] ) && tzfd_request_text( 'get', "page" ) == "tzfd-setup" )
      )
	{
		$path = dirname( __FILE__ ).'/addons';
		// Group folders are listed by TZFD_Addon_Loader, core first.
		foreach ( TZFD_Addon_Loader::files( $path ) as $tz_addon_file )
		{
			require_once $tz_addon_file;
		}
	}
}



/* initialization / install */

include_once dirname( __FILE__ ) . '/classes/tzfd-base-class.inc.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-scheduling-rules.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-intake-mode.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-admin-menu.php';
TZFD_Admin_Menu::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-ai-services-page.php';
TZFD_AI_Services_Page::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-admin-notices.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-view.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-admin-layout.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-shared-store.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-service-details.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-dashboard-figures.php';
TZFD_Admin_Notices::register();
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
include_once dirname( __FILE__ ) . '/includes/class-tzfd-privacy-exporter.php';
TZFD_Privacy_Exporter::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-resource-admin.php';
TZFD_Resource_Admin::register();
TZFD_Service_Details::register();
require_once dirname( __FILE__ ) . '/includes/class-tzfd-enquiry-review.php';
TZFD_Enquiry_Review::register();
require_once dirname( __FILE__ ) . '/includes/class-tzfd-enquiry-triage.php';
TZFD_Enquiry_Triage::register();
// Booking pipeline: managers, approval and supervisor for assistant and channel bookings.
include_once dirname( __FILE__ ) . '/includes/class-tzfd-pipeline-rules.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-pipeline.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-pipeline-screen.php';
TZFD_Pipeline::register();
TZFD_Pipeline_Notification_Outbox::boot();
require_once dirname( __FILE__ ) . '/includes/class-tzfd-customer-event-outbox.php';
TZFD_Customer_Event_Outbox::boot();
TZFD_Pipeline_Screen::register();



$tz_plugin = new TZFD_Scheduling_Plugin;
add_action('tz_fd_process_data_before_insert', array($tz_plugin, 'begin_capacity_reservation'), PHP_INT_MIN, 1);
add_action('tz_fd_process_data', array($tz_plugin, 'finish_capacity_reservation'), PHP_INT_MIN, 1);
// A rejected/failed submission must release its per-calendar database lock as
// reliably as a successful insert, including invalid customer and insert errors.
add_action('tz_fd_process_data_insert_failed', array($tz_plugin, 'finish_capacity_reservation'), PHP_INT_MIN, 1);


// Customer self-service, status emails, and the messaging and sync framework. These load on every
// site. Each one stays idle until it is used: status emails are sent only when an administrator
// changes a booking's status, and the vendor adapters that the framework classes expect are added
// by separate add-ons.
include_once dirname( __FILE__ ) . '/includes/class-tzfd-customer-cancellation.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-customer-rescheduling.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-client-portal.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-settings-extras.php';
TZFD_Settings_Extras::register();
include_once dirname( __FILE__ ) . '/includes/class-tzfd-status-update-emails.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-addon-records.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-outbox.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-sync-queue.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-sms-provider.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-captcha-gate.php';
include_once dirname( __FILE__ ) . '/includes/class-tzfd-sync-addon.php';
( new TZFD_Customer_Cancellation() )->register();
( new TZFD_Customer_Rescheduling( $tz_plugin ) )->register();
( new TZFD_Client_Portal() )->register();
( new TZFD_Status_Update_Emails() )->register();
TZFD_Outbox::boot();

// Without the GD library the booking CAPTCHA cannot be drawn, so it is skipped and bookings still work.
// Tell the administrator, so the protection is not missing silently.
add_action( 'admin_notices', function () {
    if ( function_exists( 'imagecreatetruecolor' ) || ! current_user_can( 'tz_fd_access' ) ) { return; }
    if ( 'false' === get_option( 'cv_enable_captcha', 'true' ) ) { return; }
    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Titan Zero Front Desk: the PHP GD library is not installed, so the booking CAPTCHA is turned off. Ask your host to enable GD to protect your booking form from automated submissions.', 'titan-zero-trades-front-desk' ) . '</p></div>';
} );
add_action( 'media_buttons', array($tz_plugin, 'insert_button'), 11);
add_action( 'init', array($tz_plugin, 'data_management'));
add_action( 'wp_loaded', array($tz_plugin, 'data_management_loaded'));
// Front Desk boots during plugins_loaded. A callback added at a lower priority than the running one is never called,
// so when plugins_loaded has already started, run the loader now. Otherwise the add-on loader (and the setup wizard
// class) never loads on web requests and admin.php?page=tzfd-setup returns "not allowed".
if ( did_action( 'plugins_loaded' ) ) {
    $tz_plugin->data_management_pluginsloaded();
} else {
    add_action( 'plugins_loaded', array($tz_plugin, 'data_management_pluginsloaded'), -1);
}

if ( is_admin() ) {


    add_action('admin_enqueue_scripts', array($tz_plugin,'insert_adminScripts'), 1);
    add_filter("plugin_action_links_".plugin_basename( dirname( __FILE__ ) . '/titan-zero-front-desk.php' ), array($tz_plugin,'plugin_page_links'));
    add_action('admin_menu', array($tz_plugin,'admin_menu') );
    add_action('enqueue_block_editor_assets', array($tz_plugin,'gutenberg_block'));


} else {
    add_shortcode( $tz_plugin->shorttag, array($tz_plugin, 'filter_content') );
    add_shortcode( 'TZFD_FRONT_DESK_LIST', array($tz_plugin, 'filter_list') );
}

// register gutenberg block
if (function_exists('register_block_type'))
{
    add_action( 'init', 'tz_fd_hour_register_dynamic_block' );

    function tz_fd_hour_register_dynamic_block() {
        global $tz_plugin;

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
            'render_callback' => array( $tz_plugin, 'render_form_admin' ),
        ) );
    }
}

add_filter( 'block_categories_all', 'tz_fd_hour_register_block_category', 10, 2 );

function tz_fd_hour_register_block_category( $categories, $post ) {
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




add_filter('autoptimize_filter_js_exclude', 'tz_fd_hour_autoptimize_filter_js_exclude' );
function tz_fd_hour_autoptimize_filter_js_exclude($excluded)
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

add_filter('litespeed_cache_optimize_js_excludes', 'tz_fd_hour_litespeed_cache_optimize_js_excludes' );
function tz_fd_hour_litespeed_cache_optimize_js_excludes($tz_options)
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
                     "jquery.validate.min.js\njQuery.stringify.js\njquery.validate.js\njquery.js\n".$tz_options;
}

add_filter('option_sbp_settings', 'tz_fd_hour_sbp_fix_conflict' );
function tz_fd_hour_sbp_fix_conflict($tz_option)
{
    if(!is_admin())
    {
       if(is_array($tz_option) && isset($tz_option['jquery_to_footer']))
           unset($tz_option['jquery_to_footer']);
    }
    return $tz_option;
}

function tz_fd_hour_tp_disable_filter()
{
    return false;
}



// prevent non-admin users from using the list shortcode
add_filter( 'content_save_pre', function ( $content ) {

    if ( current_user_can( 'tz_fd_access' ) ) {
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
add_filter( 'sgo_javascript_combine_exclude', 'tz_fd_hour_exclude_js_from_combination' );
function tz_fd_hour_exclude_js_from_combination( $exclude_list ) {
    $exclude_list[] = 'tz_fd_frontdesk_language_file';
    $exclude_list[] = 'tz_fd_frontdesk_builder_script';
    return $exclude_list;
}

// Exclude from SiteGround Speed Optimizer JS Minification
add_filter( 'sgo_js_minify_exclude', 'tz_fd_hour_exclude_js_from_minification' );
    function tz_fd_hour_exclude_js_from_minification( $exclude_list ) {
    $exclude_list[] = 'tz_fd_frontdesk_language_file';
    $exclude_list[] = 'tz_fd_frontdesk_builder_script';
    return $exclude_list;
}
