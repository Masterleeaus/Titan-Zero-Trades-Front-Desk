<?php
/**
 * Standalone uninstall and data-lifecycle check. Not shipped in the plugin.
 * Run: php tests/unit/uninstall-lifecycle-test.php   (exit 0 = all pass)
 *
 * Runs in its own process with a recording fake $wpdb and WordPress stubs. It drives the real
 * TZFD_Uninstaller class and the real cron hook list. Invitation-file deletion runs against a temp folder.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

$plugin = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
require_once $plugin . '/includes/class-tzfd-tables.php';
require_once $plugin . '/includes/class-tzfd-uninstaller.php';

/* ---- tiny assertion helpers ---- */
$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = array();
function t_ok( $cond, $msg ) {
	if ( $cond ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail'][] = $msg; }
}

/* ---- fake database: records every statement, binds %s/%i/%d in order ---- */
class T_Fake_WPDB {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $usermeta = 'wp_usermeta';
	public $blogs = 'wp_blogs';
	public $queries = array();
	public $throw_on_drop_for = null;

	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sdi]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i++ ];
			return is_int( $a ) ? (string) $a : "'" . $a . "'";
		}, $sql );
	}
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( null !== $this->throw_on_drop_for && 0 === strpos( $sql, 'DROP TABLE IF EXISTS ' . $this->throw_on_drop_for ) ) {
			throw new RuntimeException( 'simulated failure' );
		}
		return 1;
	}
	public function drops() {
		$out = array();
		foreach ( $this->queries as $q ) {
			if ( preg_match( "/^DROP TABLE IF EXISTS '([^']+)'$/", $q, $m ) ) { $out[] = $m[1]; }
		}
		return $out;
	}
	public function option_deletes() {
		$out = array();
		foreach ( $this->queries as $q ) {
			if ( preg_match( "/^DELETE FROM wp_options WHERE option_name LIKE '([^']+)'$/", $q, $m ) ) { $out[] = $m[1]; }
		}
		return $out;
	}
}

/* ---- WordPress stubs used by the uninstaller ---- */
$GLOBALS['t_network']   = false;
$GLOBALS['t_sites']     = array( 1 );
$GLOBALS['t_blog_log']  = array();
$GLOBALS['t_unsched']   = array();
$GLOBALS['t_deleted_opts'] = array();
$GLOBALS['t_uploads']   = '';
$GLOBALS['t_wpdb']      = new T_Fake_WPDB();
$wpdb = $GLOBALS['t_wpdb'];

function is_multisite() { return $GLOBALS['t_network']; }
function get_sites( $args ) { return $GLOBALS['t_sites']; }
function get_current_blog_id() { return 1; }
function switch_to_blog( $id ) {
	$GLOBALS['t_blog_log'][] = 'switch:' . $id;
	$GLOBALS['t_wpdb']->prefix = 1 === $id ? 'wp_' : 'wp_' . $id . '_';
}
function restore_current_blog() {
	$GLOBALS['t_blog_log'][] = 'restore';
	$GLOBALS['t_wpdb']->prefix = 'wp_';
}
function wp_unschedule_hook( $hook ) { $GLOBALS['t_unsched'][] = $hook; return 0; }
function delete_option( $name ) { $GLOBALS['t_deleted_opts'][] = $name; return true; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['t_uploads'] ); }
function wp_delete_file( $file ) { return unlink( $file ); }

/* ---- temp uploads folder with one invitation file and one unrelated file ---- */
$tmp = sys_get_temp_dir() . '/tzfd-uninstall-test-' . getmypid();
@mkdir( $tmp . '/titan-zero-trades-front-desk', 0777, true );
file_put_contents( $tmp . '/titan-zero-trades-front-desk/tzfd-booking_12_3.ics', 'x' );
file_put_contents( $tmp . '/titan-zero-trades-front-desk/keep-me.txt', 'x' );
file_put_contents( $tmp . '/unrelated-root-file.ics', 'x' );
$GLOBALS['t_uploads'] = $tmp;

$owned_bare  = TZFD_Tables::owned();
$plan        = TZFD_Uninstaller::tables_to_drop();
$workspace   = array( 'tz_customers', 'tz_sites', 'tz_services', 'tz_staff', 'tz_audit', 'tzfd_customers' );

/* ---- 1. plan: coverage and shell guard ---- */
foreach ( $owned_bare as $bare ) {
	t_ok( in_array( $bare, $plan, true ), "plan drops owned table $bare" );
}
foreach ( array( 'tzfd_local_customers', 'tzfd_local_services', 'tzfd_customer_event_outbox' ) as $bare ) {
	t_ok( in_array( $bare, $plan, true ), "plan drops standalone/outbox table $bare" );
}
foreach ( $workspace as $bare ) {
	t_ok( ! in_array( $bare, $plan, true ), "plan never drops Workspace-owned $bare" );
}
t_ok( count( $plan ) === count( array_unique( $plan ) ), 'plan has no duplicate tables' );
foreach ( $plan as $bare ) {
	t_ok( 1 === preg_match( '/^(tz|tzfd)_[A-Za-z0-9_]+$/D', $bare ), "plan name is a plugin table name: $bare" );
}

/* ---- 2. regression: every table the plugin creates with dbDelta or CREATE TABLE is in the plan ---- */
$created = array(
	'tz_bookings', 'tz_forms', 'tz_cache', 'tz_icalexport', 'tz_SingleDaysSelection',
	'tz_booking_actions', 'tz_escalation_state', 'tz_contact_actions', 'tz_sms_conversations',
	'tz_booking_inbox', 'tzfd_customer_event_outbox', 'tz_pipeline_notification_outbox',
	'tz_slot_holds', 'tzfd_local_customers', 'tzfd_local_services', 'tz_addon_records',
	'tz_smart_task_outbox', 'tz_enquiry_triage', 'tz_pipeline', 'tz_pipeline_events',
	'tz_telephone_delivery', 'tz_enquiries', 'tz_fd_audit_log', 'tz_calls', 'tz_call_events',
);
foreach ( $created as $bare ) {
	t_ok( in_array( $bare, $plan, true ), "created table $bare is dropped on opt-in" );
}
// Notification and call queues named in the task brief.
foreach ( array( 'tz_pipeline_notification_outbox', 'tz_enquiry_triage', 'tz_booking_inbox', 'tz_smart_task_outbox', 'tz_telephone_delivery' ) as $bare ) {
	t_ok( in_array( $bare, $plan, true ), "outbox/triage table $bare is in the plan" );
}

/* ---- 3. default run (no opt-in): nothing destructive beyond cron, transients, invitation files ---- */
$wpdb->queries = array(); $GLOBALS['t_unsched'] = array(); $GLOBALS['t_deleted_opts'] = array();
TZFD_Uninstaller::uninstall_network( false );
t_ok( array() === $wpdb->drops(), 'no opt-in: no table is dropped' );
t_ok( array() === $wpdb->option_deletes(), 'no opt-in: no plugin option is deleted by prefix' );
t_ok( in_array( 'tz_fd_reports_cron', $GLOBALS['t_unsched'], true ), 'no opt-in: scheduled events are cleared' );
t_ok( in_array( 'tzfd_booking_action_dispatch_v1', $GLOBALS['t_unsched'], true ), 'no opt-in: outbox hooks are cleared' );
t_ok( in_array( 'tzfd_customer_event_company_cursor_v1', $GLOBALS['t_deleted_opts'], true ), 'no opt-in: worker cursor option removed' );
$transient_sql = implode( "\n", $wpdb->queries );
t_ok( false !== strpos( $transient_sql, '\\_transient\\_' ) && false !== strpos( $transient_sql, 'timeout' ), 'no opt-in: transient and timeout rows are deleted' );
t_ok( false !== strpos( $transient_sql, "tzfd\\_" ) && false !== strpos( $transient_sql, "tzfd-" ), 'transient sweep includes tzfd_ and tzfd- prefixes' );
t_ok( ! file_exists( $tmp . '/titan-zero-trades-front-desk/tzfd-booking_12_3.ics' ), 'invitation file in plugin folder is removed' );
t_ok( file_exists( $tmp . '/titan-zero-trades-front-desk/keep-me.txt' ), 'other file in plugin folder is kept' );
t_ok( file_exists( $tmp . '/unrelated-root-file.ics' ), 'unrelated uploads-root file is kept' );

/* ---- 4. opt-in on a single site ---- */
@file_put_contents( $tmp . '/titan-zero-trades-front-desk/tzfd-booking_99_1.ics', 'x' );
$wpdb->queries = array(); $GLOBALS['t_deleted_opts'] = array();
TZFD_Uninstaller::uninstall_network( true );
$drops = $wpdb->drops();
foreach ( $plan as $bare ) {
	t_ok( in_array( 'wp_' . $bare, $drops, true ), "opt-in drops wp_$bare" );
}
t_ok( ! in_array( 'wp_tz_customers', $drops, true ), 'opt-in never drops wp_tz_customers' );
t_ok( ! in_array( 'wp_tz_sites', $drops, true ), 'opt-in never drops wp_tz_sites' );
$opt_del = $wpdb->option_deletes();
foreach ( array( 'tz\\_fd\\_%', 'titan\\_zero\\_front\\_desk\\_%', 'tzfd\\_%' ) as $p ) {
	t_ok( in_array( $p, $opt_del, true ), "opt-in deletes options matching $p" );
}
t_ok( ! file_exists( $tmp . '/titan-zero-trades-front-desk/tzfd-booking_99_1.ics' ), 'opt-in still removes invitation files' );

/* ---- 5. multisite: every blog, with its own prefix, restored after each ---- */
$GLOBALS['t_network'] = true; $GLOBALS['t_sites'] = array( 1, 2, 3 );
$GLOBALS['t_blog_log'] = array(); $wpdb->queries = array(); $wpdb->throw_on_drop_for = null;
TZFD_Uninstaller::uninstall_network( true );
$log = $GLOBALS['t_blog_log'];
t_ok( array( 'switch:1', 'restore', 'switch:2', 'restore', 'switch:3', 'restore' ) === $log, 'multisite visits each blog and restores after each' );
$all_drops = $wpdb->drops();
foreach ( array( 'wp_2_', 'wp_3_' ) as $pre ) {
	t_ok( in_array( $pre . 'tz_bookings', $all_drops, true ), "multisite drops $pre" . 'tz_bookings' );
	t_ok( in_array( $pre . 'tzfd_local_customers', $all_drops, true ), "multisite drops $pre" . 'tzfd_local_customers' );
}
t_ok( ! in_array( 'wp_2_tz_customers', $all_drops, true ), 'multisite never drops a site tz_customers' );

/* ---- 6. multisite: a failure on one blog still restores the blog context ---- */
$GLOBALS['t_blog_log'] = array(); $wpdb->queries = array(); $wpdb->throw_on_drop_for = "'wp_2_tz_bookings'";
$threw = false;
try { TZFD_Uninstaller::uninstall_network( true ); } catch ( RuntimeException $e ) { $threw = true; }
t_ok( $threw, 'multisite failure on blog 2 propagates to the caller' );
t_ok( in_array( 'restore', $GLOBALS['t_blog_log'], true ) && 2 === count( array_filter( $GLOBALS['t_blog_log'], function ( $x ) { return 'restore' === $x; } ) ), 'blog context restored after failure' );
$wpdb->throw_on_drop_for = null;

/* ---- 7. deactivation and activation never delete data (source guard) ---- */
$main = file_get_contents( $plugin . '/titan-zero-front-desk.php' );
if ( preg_match( '/function tzfd_clear_scheduled_events\(\)\s*\{(.*?)\n\s{4}\}/s', $main, $m ) ) {
	$body = $m[1];
	t_ok( 1 !== preg_match( '/DROP|DELETE|delete_option|TRUNCATE|wpdb|update_option/i', $body ), 'deactivation handler only unschedules events' );
	t_ok( false !== strpos( $body, 'tzfd_unschedule_all_events' ), 'deactivation handler unschedules through the shared helper' );
} else {
	t_ok( false, 'could not locate tzfd_clear_scheduled_events body' );
}
if ( preg_match( '/function tzfd_activate\(\)\s*\{(.*?)\n\s{4}\}/s', $main, $m ) ) {
	t_ok( 1 !== preg_match( '/DROP TABLE|TRUNCATE|DELETE FROM|uninstall/i', $m[1] ), 'activation handler contains no destructive statement' );
}

/* ---- 8. cron hooks: every scheduled hook in the code is in the shared list ---- */
$hooks = include $plugin . '/includes/tzfd-cron-hooks.php';
foreach ( array(
	'tz_fd_reports_cron', 'tz_fd_ical_remove_file', 'titan_zero_front_desk_addon_autocancel_run',
	'tzfd_booking_action_dispatch_v1', 'tzfd_customer_event_outbox_dispatch', 'tzfd_customer_event_outbox_wake',
	'tzfd_pipeline_notifications_dispatch_v1', 'tzfd_pipeline_notifications_wake_v1', 'titan_zero_front_desk_outbox_run',
	'tzfd_smart_task_outbox_dispatch_v1', 'titan_zero_front_desk_deliver_webhook', 'titan_zero_front_desk_sync_run',
	'tz_fd_pipeline_supervisor', 'tzfd_telephone_delivery_dispatch_v1', 'titan_zero_front_desk_addon_reminder_send',
	'titan_zero_front_desk_addon_followup_send',
) as $hook ) {
	t_ok( in_array( $hook, $hooks, true ), "cron hook $hook is cleared on deactivation and uninstall" );
}

/* ---- report ---- */
$fail = $GLOBALS['t_fail'];
foreach ( $fail as $f ) { echo 'FAIL ' . $f . "\n"; }
echo 'Uninstall lifecycle: passed ' . $GLOBALS['t_pass'] . ', failed ' . count( $fail ) . "\n";

// Remove the temp folder.
foreach ( array_merge( glob( $tmp . '/titan-zero-trades-front-desk/*' ) ?: array(), glob( $tmp . '/*' ) ?: array() ) as $f ) { if ( is_file( $f ) ) { unlink( $f ); } }
@rmdir( $tmp . '/titan-zero-trades-front-desk' );
@rmdir( $tmp );

exit( $fail ? 1 : 0 );
