<?php
/*
Plugin Name: Scheduling & Enquiries Manager
Description: Bookings, calendar, booking pages and phone answering. Operates standalone with optional Titan Zero integrations.
Version: 3.0.128
Requires at least: 6.6
Requires PHP: 7.4
Author: Titan Zero
License: GPL-2.0-only
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: titan-zero-trades-front-desk
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Front Desk owns its WordPress admin navigation. Do not boot the suite Workspace shell.
require_once __DIR__ . '/includes/class-tzfd-customer-admin.php';
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.


if ( ! defined( 'TZFD_VERSION' ) ) {
    define( 'TZFD_VERSION', '3.0.128' );
}

require_once dirname( __FILE__ ) . '/includes/class-tzfd-tables.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-ai-free-services.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-history-reconciliation.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-migration-handoff.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-record-reconciliation.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-call-lifecycle.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-telephony-adapter.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-messaging-replies.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-telephone-delivery.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-twilio-sms.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-twilio-status.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-escalation-ack.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-call-routing.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-escalation-state-store.php';
add_action( 'init', array( 'TZFD_Escalation_State_Store', 'boot' ) );
require_once dirname( __FILE__ ) . '/includes/class-tzfd-call-intake.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-caller-automation.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-telephone-flow.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-call-reliability.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-demand-capacity.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-automation-pattern-discovery.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-workflow-orchestrator.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-workflow-rules.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-event-deduplication.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-inbox.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-inbox-store.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-inbox-dispatch-bridge.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-commit-handoff.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-action-outbox.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-reminder-bridge.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-waitlist-bridge.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-booking-waitlist-acceptance.php';
add_action( 'init', array( 'TZFD_Booking_Action_Outbox', 'boot' ) );
require_once dirname( __FILE__ ) . '/includes/class-tzfd-frontdesk-api.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-suite-gateway.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-ai-connector.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-smart-tasks.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-smart-task-outbox.php';
TZFD_Smart_Task_Outbox::boot();
TZFD_Telephone_Delivery::boot();
TZFD_Twilio_SMS::boot();
require_once dirname( __FILE__ ) . '/includes/class-tzfd-operations-monitor.php';
TZFD_Operations_Monitor::boot();
require_once dirname( __FILE__ ) . '/includes/class-tzfd-smart-task-recovery.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-smart-task-events.php';
TZFD_Smart_Task_Events::register();
// The AI assistant is not part of the free build. Keep the shortcode so pages that
// already contain it render nothing instead of showing raw shortcode text.
add_shortcode( 'tz_fd_ai_assistant', '__return_empty_string' );
require_once dirname( __FILE__ ) . '/includes/class-tzfd-access.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-hosted-ai-connector.php';
require_once dirname( __FILE__ ) . '/includes/class-tzfd-legacy-ai-data.php';
TZFD_Legacy_AI_Data::register();
require_once dirname( __FILE__ ) . '/includes/class-tzfd-lead-intake.php';
// Public AI answer box and service finder (issues #20, #23, #24). Rules-first; no hosted calls by default.
require_once dirname( __FILE__ ) . '/includes/class-tzfd-ai-widget.php';
TZFD_AI_Widget::boot();
require_once dirname( __FILE__ ) . '/includes/class-tzfd-human-handoff.php';

/**
 * Translate persisted values without passing dynamic data to the
 * literal-string translation helpers.
 */
if ( ! function_exists( 'titan_zero_front_desk_translate_legacy_value' ) ) {
    function titan_zero_front_desk_translate_legacy_value( $tz_value ) {
        if ( ! is_string( $tz_value ) && ! is_int( $tz_value ) && ! is_float( $tz_value ) ) {
            return '';
        }
        $tz_value = (string) $tz_value;
        if ( function_exists( 'get_translations_for_domain' ) ) {
            return get_translations_for_domain( 'titan-zero-trades-front-desk' )->translate( $tz_value );
        }
        return $tz_value;
    }
}

//define("TZFD_DYNAMIC_LOADING", true);

// Front-end assets must be enqueued through WordPress; legacy in-content script printing is disabled.
if ( ! defined( 'TZFD_BOOK_DEFER_SCRIPTS_LOADING' ) ) {
    define( 'TZFD_BOOK_DEFER_SCRIPTS_LOADING', true );
}

if ( ! defined( 'TZFD_BOOK_DEFAULT_form_structure' ) ) {
    define('TZFD_BOOK_DEFAULT_form_structure', '[[{"form_identifier":"","name":"fieldname1","shortlabel":"","index":0,"ftype":"fapp","userhelp":"","userhelpTooltip":false,"csslayout":"","title":"Appointment","services":[{"name":"Service 1","price":1,"duration":60}],"openhours":[{"name":"Default","openhours":[{"type":"all","d":"","h1":8,"m1":0,"h2":17,"m2":0}]}],"allOH":[{"name":"Default","openhours":[{"type":"all","d":"","h1":8,"m1":0,"h2":17,"m2":0}]}],"dateFormat":"mm/dd/yy","showDropdown":false,"dropdownRange":"-10:+10","working_dates":[true,true,true,true,true,true,true],"numberOfMonths":1,"firstDay":0,"minDate":"0","maxDate":"","defaultDate":"","invalidDates":"","required":true,"fBuild":{}},{"form_identifier":"","name":"email","shortlabel":"","index":1,"ftype":"femail","userhelp":"","userhelpTooltip":false,"csslayout":"","title":"Email","predefined":"","predefinedClick":false,"required":true,"size":"medium","equalTo":"","fBuild":{}}],[{"title":"","description":"","formlayout":"top_aligned","formtemplate":"tz_fd_m5","evalequations":1,"autocomplete":1}]]');
}


if ( ! defined( 'TZFD_BOOK_DEFAULT_track_IP' ) ) {
    define('TZFD_BOOK_DEFAULT_track_IP', true);
}

if ( ! defined( 'TZFD_BOOK_DEFAULT_fp_subject' ) ) {
    define('TZFD_BOOK_DEFAULT_fp_subject', 'Notification to administrator: Booking request received...');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_fp_inc_additional_info' ) ) {
    define('TZFD_BOOK_DEFAULT_fp_inc_additional_info', 'true');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_fp_return_page' ) ) {
    define('TZFD_BOOK_DEFAULT_fp_return_page', get_site_url());
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_fp_message' ) ) {
    define('TZFD_BOOK_DEFAULT_fp_message', "The following booking request has been received:\n\n<%INFO%>\n\n");
}

if ( ! defined( 'TZFD_BOOK_DEFAULT_cu_enable_copy_to_user' ) ) {
    define('TZFD_BOOK_DEFAULT_cu_enable_copy_to_user', 'true');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cu_user_email_field' ) ) {
    define('TZFD_BOOK_DEFAULT_cu_user_email_field', 'email');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cu_subject' ) ) {
    define('TZFD_BOOK_DEFAULT_cu_subject', 'Confirmation: Your booking has been received...');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cu_message' ) ) {
    define('TZFD_BOOK_DEFAULT_cu_message', "Your appointment is received. We look forward to seeing you then.\n\nThis is a copy of the data sent:\n\n<%INFO%>\n\nBest Regards.");
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_email_format' ) ) {
    define('TZFD_BOOK_DEFAULT_email_format','text');
}

if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_use_validation' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_use_validation', 'true');
}

if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_is_required' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_is_required', 'This field is required.');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_is_email' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_is_email', 'Please enter a valid email address.');
}

if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy', 'Please enter a valid date with this format(mm/dd/yyyy)');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy', 'Please enter a valid date with this format(dd/mm/yyyy)');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_number' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_number', 'Please enter a valid number.');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_digits' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_digits', 'Please enter only digits.');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_max' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_max', 'Please enter a value less than or equal to {0}.');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_min' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_min', 'Please enter a value greater than or equal to {0}.');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_vs_text_maxapp' ) ) {
    define('TZFD_BOOK_DEFAULT_vs_text_maxapp', 'Please select a max of  {0} appointments per customer.');
}

if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_enable_captcha' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_enable_captcha', 'true');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_width' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_width', '180');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_height' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_height', '60');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_chars' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_chars', '5');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_font' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_font', 'font1');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_min_font_size' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_min_font_size', '25');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_max_font_size' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_max_font_size', '35');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_noise' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_noise', '200');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_noise_length' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_noise_length', '4');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_background' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_background', 'ffffff');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_border' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_border', 'ffffff');
}
if ( ! defined( 'TZFD_BOOK_DEFAULT_cv_text_enter_valid_captcha' ) ) {
    define('TZFD_BOOK_DEFAULT_cv_text_enter_valid_captcha', 'Please enter a valid captcha code.');
}

if ( ! defined( 'TZFD_BOOK_REP_ARR' ) ) {
    define('TZFD_BOOK_REP_ARR', '[+arr1237]');
}

if ( ! defined( 'TZFD_SCHCALTITLE_ADMIN' ) ) {
    define ('TZFD_SCHCALTITLE_ADMIN', '%email%');
}
if ( ! defined( 'TZFD_SCHCALCONTENT_ADMIN' ) ) {
    define ('TZFD_SCHCALCONTENT_ADMIN', '%INFO%');
}
if ( ! defined( 'TZFD_SCHCALTITLE_PUBLIC' ) ) {
    define ('TZFD_SCHCALTITLE_PUBLIC', 'Booked');
}
if ( ! defined( 'TZFD_SCHCALCONTENT_PUBLIC' ) ) {
    define ('TZFD_SCHCALCONTENT_PUBLIC', 'This time-slot is booked');
}

/* Standalone services load on plugins_loaded after optional integrations register. */
if ( ! function_exists( 'tz_fd_workspace_ready' ) ) {
    function tz_fd_workspace_ready() {
        // Compatibility probe only. A missing Workspace is never an activation blocker.
        return class_exists( 'TZFD_Local_Business', false ) && class_exists( 'TZFD_Local_Customers', false );
    }
}
require_once __DIR__ . '/includes/class-tzfd-standalone-runtime.php';

/** Seed the default calendar only after the standalone schema is available. */
if ( ! function_exists( 'tz_fd_seed_form_for_member' ) ) {
    function tz_fd_seed_form_for_member() {
        global $wpdb, $tz_plugin;
        if ( ! is_admin() || ! class_exists( 'TZFD_Scoped_Store' ) || TZFD_Scoped_Store::company_id() < 1 || ! is_object( $tz_plugin ) || ! method_exists( $tz_plugin, 'install' ) ) { return; }
        $table = $wpdb->prefix . TZFD_Tables::FORMS;
        $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i', $table ) );
        if ( null === $count || (int) $count > 0 ) { return; }
        $tz_plugin->install( false );
    }
}

/** Boots Front Desk once, after Workspace. */
if ( ! function_exists( 'tz_fd_boot' ) ) {
    function tz_fd_boot() {
        static $booted = false;
        if ( $booted ) { return; }
        TZFD_Standalone_Runtime::boot();
        $booted = true;
        // The public webhook routes are registered here, not at file load, so a site without Workspace exposes no endpoint.
        TZFD_Telephony_Adapter::register();
        TZFD_Messaging_Replies::register();
        TZFD_Twilio_Status::boot();
        TZFD_Access::register();
    TZFD_Lead_Intake::register();
    TZFD_Human_Handoff::register();
        add_action( 'admin_init', 'tz_fd_seed_form_for_member', 30 );
        require_once dirname( __FILE__ ) . '/includes/class-tzfd-connect-bridge.php';
        TZFD_Connect_Bridge::register();
        require_once dirname( __FILE__ ) . '/includes/class-tzfd-leads-bridge.php';
        TZFD_Leads_Bridge::register();
        global $tz_plugin;
        require_once dirname( __FILE__ ) . '/tzfd-bootstrap.inc.php';
    }
}

/** Activation must fail before WordPress records the plugin as active without Workspace. */
if ( ! function_exists( 'tz_fd_activate' ) ) {
    function tz_fd_activate() {
        TZFD_Standalone_Runtime::boot();
        TZFD_Standalone_Runtime::install();
        tz_fd_boot();
        // Slot holds have no automatic installer, so their table was never created. Install it here.
        if ( ! class_exists( 'TZFD_Slot_Holds' ) ) { require_once dirname( __FILE__ ) . '/includes/class-tzfd-slot-holds.php'; }
        if ( class_exists( 'TZFD_Slot_Holds' ) ) { TZFD_Slot_Holds::install(); }
        // Call lifecycle schema is an explicit, authorised install. Failures are shown as an admin notice.
        // Reactivation is the retry path, so a stored failure from an earlier attempt is cleared first.
        delete_option( TZFD_Call_Lifecycle::FAILURE_OPTION );
        TZFD_Call_Lifecycle::run_install();
        if ( class_exists( 'TZFD_Business_Schema' ) ) { TZFD_Business_Schema::run(); }
        global $tz_plugin;
        if ( is_object( $tz_plugin ) && method_exists( $tz_plugin, 'install' ) ) { $tz_plugin->install( false ); }
    }
}

// Scheduled events stop when the plugin is deactivated, so nothing keeps running without it.
// uninstall.php clears the same list when the plugin is deleted; keep the two lists in step.
if ( ! function_exists( 'tz_fd_clear_scheduled_events' ) ) {
    function tz_fd_clear_scheduled_events() {
        $hooks = include dirname( __FILE__ ) . '/includes/tzfd-cron-hooks.php';
        require_once dirname( __FILE__ ) . '/includes/tzfd-cron-clear.php';
        foreach ( $hooks as $hook ) {
            tz_fd_unschedule_all_events( $hook );
        }
    }
}

register_activation_hook( __FILE__, 'tz_fd_activate' );
register_deactivation_hook( __FILE__, 'tz_fd_clear_scheduled_events' );
add_action( 'plugins_loaded', 'tz_fd_boot', 20 );

// Titan Zero UI pack (optional). The plugin works without the ui-pack folder.
if ( file_exists( __DIR__ . '/ui-pack/ui/tz-app-pack.php' ) ) { require_once __DIR__ . '/ui-pack/ui/tz-app-pack.php'; }

// Admin-only, nonce-verified site discovery for the booking customer selector.

// Bundled UI graphics catalogue (admin-only route).
if ( is_admin() ) {
    require_once __DIR__ . '/includes/class-tzfd-ui-graphics.php';
}
