<?php
/**
 * WordPress integration checks for lead urgency (issue #29). Not shipped in the plugin.
 * Run on the disposable test site: wp --allow-root eval-file tests/runtime/lead-urgency-runtime.php
 */
global $wpdb;
$GLOBALS['lu_pass'] = 0; $GLOBALS['lu_fail'] = 0;
function lu_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['lu_pass']++; } else { $GLOBALS['lu_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

wp_set_current_user( 1 );
$company = (int) TZFD_Scoped_Store::company_id();
lu_check( 'company resolves for admin', $company > 0, 'company=' . $company );
$table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
$tag   = substr( md5( uniqid( '', true ) ), 0, 10 );
$ids   = array();
$received = gmdate( 'Y-m-d H:i:s' );

// Keep the test site's alert state clean before and after.
delete_option( TZFD_Lead_Urgency::OPTION_LAST . $company );
delete_option( TZFD_Lead_Urgency::OPTION_ALERTS );
delete_option( TZFD_Lead_Urgency::OPTION_RULES );

// 1. Upgrade path: a site activated before this release has no urgency columns. ensure_schema() must add them.
foreach ( array( 'urgency_auto', 'urgency_reason', 'urgency_override' ) as $old_col ) {
    if ( $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $old_col ) ) ) {
        $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN ' . $old_col, $table ) );
    }
}
delete_option( TZFD_Lead_Urgency::SCHEMA_OPTION );
$before_cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
lu_check( 'pre-upgrade table has no urgency columns', ! in_array( 'urgency_auto', $before_cols, true ) );
TZFD_Lead_Urgency::ensure_schema();
$cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
lu_check( 'schema has urgency columns', in_array( 'urgency_auto', $cols, true ) && in_array( 'urgency_reason', $cols, true ) && in_array( 'urgency_override', $cols, true ) );
// Running it again on an upgraded site is a no-op (option-gated).
lu_check( 'ensure_schema is idempotent', true === TZFD_Lead_Urgency::ensure_schema() && get_option( TZFD_Lead_Urgency::SCHEMA_OPTION ) );

$payload = function ( $text, $n ) use ( $company, $tag, $received ) {
    return array( 'company_id' => $company, 'name' => 'RT ' . $n, 'email' => "lu-$tag-$n@example.test", 'enquiry' => $text, 'source' => 'rt', 'received' => $received );
};

// 2. Intake stores urgency and reason for an urgent enquiry.
$urgent_id = TZFD_Lead_Intake::receive( $payload( 'Pipe is flooding the laundry, please come ASAP', 1 ) );
$ids[] = $urgent_id;
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT urgency_auto, urgency_reason, urgency_override FROM %i WHERE id = %d AND company_id = %d', $table, $urgent_id, $company ) );
lu_check( 'urgent intake stored as urgent', $row && 'urgent' === $row->urgency_auto, 'auto=' . ( $row->urgency_auto ?? 'none' ) );
lu_check( 'urgent intake stores its reason', $row && false !== strpos( $row->urgency_reason, 'asap' ) );
lu_check( 'new intake has no override', $row && '' === $row->urgency_override );

// 3. Non-urgent and negated enquiries are stored as normal.
$normal_id = TZFD_Lead_Intake::receive( $payload( 'No rush, quote for next month please', 2 ) );
$ids[] = $normal_id;
$negated_id = TZFD_Lead_Intake::receive( $payload( 'This is not urgent at all', 3 ) );
$ids[] = $negated_id;
$auto = $wpdb->get_col( $wpdb->prepare( 'SELECT urgency_auto FROM %i WHERE id IN (%d, %d) AND company_id = %d ORDER BY id', $table, $normal_id, $negated_id, $company ) );
lu_check( 'non-urgent and negated stored as normal', $auto === array( 'normal', 'normal' ), json_encode( $auto ) );

// 4. A duplicate post returns the stored row and sends no second alert.
$dupe_id = TZFD_Lead_Intake::receive( $payload( 'Pipe is flooding the laundry, please come ASAP', 1 ) );
lu_check( 'duplicate returns the stored id', $dupe_id === $urgent_id, 'first=' . $urgent_id . ' dupe=' . $dupe_id );

// 5. Priority override: admin sets normal, audit row is written, automatic result is kept.
$ok = TZFD_Lead_Urgency::set_override( $urgent_id, 'normal', $company );
lu_check( 'override saves', true === $ok );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT urgency_auto, urgency_override FROM %i WHERE id = %d AND company_id = %d', $table, $urgent_id, $company ) );
lu_check( 'override wins over automatic', $row && 'normal' === TZFD_Lead_Urgency::effective( $row->urgency_auto, $row->urgency_override ) );
lu_check( 'automatic result is kept under override', $row && 'urgent' === $row->urgency_auto );
$audit = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d AND event_action = %s AND entity_type = %s AND entity_id = %d', TZFD_Local_Audit::table_name(), $company, 'enquiry.urgency_override', 'enquiry', $urgent_id ) );
lu_check( 'override writes one audit row', $audit >= 1, 'rows=' . $audit );
lu_check( 'invalid override value is refused', false === TZFD_Lead_Urgency::set_override( $urgent_id, 'bogus', $company ) );
lu_check( 'invalid override leaves value unchanged', 'normal' === $wpdb->get_var( $wpdb->prepare( 'SELECT urgency_override FROM %i WHERE id = %d', $table, $urgent_id ) ) );

// 6. Tenant isolation: another company cannot change the override or see the row.
$other = $company + 999;
lu_check( 'other company cannot set override', false === TZFD_Lead_Urgency::set_override( $urgent_id, 'urgent', $other ) );
lu_check( 'other company override did not change row', 'normal' === $wpdb->get_var( $wpdb->prepare( 'SELECT urgency_override FROM %i WHERE id = %d', $table, $urgent_id ) ) );
$visible = array_map( 'intval', wp_list_pluck( TZFD_Enquiry_Review::list_for_company( $other ), 'id' ) );
lu_check( 'other company list excludes the enquiry', ! in_array( $urgent_id, $visible, true ) );

// 7. Consent: alerts are off by default, so no mail is sent for an urgent enquiry.
$mails = 0;
$capture = function () use ( &$mails ) { $mails++; return true; };
add_filter( 'pre_wp_mail', $capture, 10, 2 );
$ids[] = TZFD_Lead_Intake::receive( $payload( 'Emergency, gas leak smell in the kitchen', 4 ) );
lu_check( 'no alert mail while alerts are off', 0 === $mails, 'mails=' . $mails );

// 8. Opt-in on: one alert for the first urgent enquiry, none inside the throttle window, none for normal.
update_option( TZFD_Lead_Urgency::OPTION_ALERTS, array( 'enabled' => true, 'recipients' => 'alerts-' . $tag . '@example.test', 'throttle_minutes' => 15 ), false );
delete_option( TZFD_Lead_Urgency::OPTION_LAST . $company );
$mails = 0;
$ids[] = TZFD_Lead_Intake::receive( $payload( 'Emergency, gas leak smell in the kitchen', 5 ) );
lu_check( 'opt-in sends one alert for urgent enquiry', 1 === $mails, 'mails=' . $mails );
$ids[] = TZFD_Lead_Intake::receive( $payload( 'Emergency again, flooding now', 6 ) );
lu_check( 'second urgent inside window is throttled', 1 === $mails, 'mails=' . $mails );
$ids[] = TZFD_Lead_Intake::receive( $payload( 'Just a normal quote question', 7 ) );
lu_check( 'normal enquiry sends no alert', 1 === $mails, 'mails=' . $mails );
remove_filter( 'pre_wp_mail', $capture, 10 );

// 9. Triage page renders the urgency column, reason and override control for an admin.
wp_set_current_user( 1 );
ob_start();
TZFD_Enquiry_Review::page();
$html = ob_get_clean();
lu_check( 'triage has Urgency column header', false !== strpos( $html, '>Urgency<' ) );
lu_check( 'triage shows the reason for the urgent enquiry', false !== strpos( $html, 'Urgent wording' ) || false !== strpos( $html, 'asap' ) );
lu_check( 'triage has the override control', false !== strpos( $html, 'name="override"' ) && false !== strpos( $html, 'tzfd-urgency-settings' ) );

// 10. Negative: a user without tz_fd_access cannot open triage or change an override.
$sub = wp_create_user( 'lu_sub_' . $tag, wp_generate_password( 20 ), 'lu-sub-' . $tag . '@example.test' );
$sub_ok = ! is_wp_error( $sub );
if ( $sub_ok ) {
    wp_set_current_user( (int) $sub );
    $died = false;
    $handler = function () { return function ( $message ) { throw new Exception( 'wp_die:' . wp_strip_all_tags( (string) $message ) ); }; };
    add_filter( 'wp_die_handler', $handler );
    try { TZFD_Enquiry_Review::page(); } catch ( Exception $e ) { $died = 0 === strpos( $e->getMessage(), 'wp_die:' ); }
    lu_check( 'subscriber refused on triage page', $died );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $died = false;
    try { TZFD_Lead_Urgency::handle_override(); } catch ( Exception $e ) { $died = 0 === strpos( $e->getMessage(), 'wp_die:' ); }
    lu_check( 'subscriber refused on override handler', $died );
    lu_check( 'refused override left the row unchanged', 'normal' === $wpdb->get_var( $wpdb->prepare( 'SELECT urgency_override FROM %i WHERE id = %d', $table, $urgent_id ) ) );
    // Admin POST without a valid nonce is refused (CSRF check), so the override is not applied.
    wp_set_current_user( 1 );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array( 'enquiry' => (string) $urgent_id, 'override' => 'urgent' );
    $died = false;
    try { TZFD_Lead_Urgency::handle_override(); } catch ( Exception $e ) { $died = 0 === strpos( $e->getMessage(), 'wp_die:' ); }
    lu_check( 'admin POST without nonce is refused', $died );
    lu_check( 'missing-nonce POST left the override unchanged', 'normal' === $wpdb->get_var( $wpdb->prepare( 'SELECT urgency_override FROM %i WHERE id = %d', $table, $urgent_id ) ) );
    $_POST = array();
    wp_set_current_user( (int) $sub );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $died = false;
    try { TZFD_Lead_Urgency::handle_override(); } catch ( Exception $e ) { $died = 0 === strpos( $e->getMessage(), 'wp_die:' ); }
    lu_check( 'handler refuses non-POST requests', $died );
    remove_filter( 'wp_die_handler', $handler );
    wp_set_current_user( 1 );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( (int) $sub );
} else {
    lu_check( 'subscriber user could be created for negative tests', false, $sub->get_error_message() );
}

// Cleanup: remove the enquiries this test created (audit rows are kept as the trace).
foreach ( array_filter( array_unique( $ids ) ) as $id ) {
    $wpdb->delete( $table, array( 'id' => (int) $id, 'company_id' => $company ), array( '%d', '%d' ) );
}
delete_option( TZFD_Lead_Urgency::OPTION_ALERTS );
delete_option( TZFD_Lead_Urgency::OPTION_LAST . $company );

echo "\nSummary: {$GLOBALS['lu_pass']} passed, {$GLOBALS['lu_fail']} failed\n";
