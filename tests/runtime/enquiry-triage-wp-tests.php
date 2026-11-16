<?php
/**
 * WordPress integration checks for deterministic enquiry triage (issue #28) on a disposable test site.
 * Run: wp --allow-root eval-file enquiry-triage-wp-tests.php   (not shipped in the plugin)
 * The plugin's includes must be at the path in $tz_inc. Exits non-zero when any check fails.
 */
$tz_inc = getenv( 'TZFD_INCLUDES' ) ? rtrim( getenv( 'TZFD_INCLUDES' ), '/' ) . '/' : '/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-request-value.php', 'class-tzfd-transaction-storage.php', 'class-tzfd-local-audit.php',
    'class-tzfd-business-schema.php', 'class-tzfd-enquiry-triage.php', 'class-tzfd-lead-intake.php', 'class-tzfd-enquiry-review.php' ) as $tz_file ) {
    require_once $tz_inc . $tz_file;
}
global $wpdb;
$GLOBALS['tt_pass'] = 0;
$GLOBALS['tt_fail'] = 0;
function tt_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['tt_pass']++; } else { $GLOBALS['tt_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

$company_a = 9101; // Tenant A: the enquiry owner under test.
$company_b = 9102; // Tenant B: must never see or change tenant A's enquiries.
$table     = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
$audit     = TZFD_Local_Audit::table_name();
$saved_ids = array();

TZFD_Business_Schema::run();
TZFD_Local_Audit::install();
tt_check( 'enquiry table exists', $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table );

// Upgrade path: drop the triage columns, then add them back the way an existing install gets them.
foreach ( array( 'triage_intent', 'triage_queue', 'triage_tags', 'triage_source', 'triage_rules', 'triage_locked' ) as $col ) {
    $wpdb->query( "ALTER TABLE $table DROP COLUMN IF EXISTS $col" );
}
delete_option( TZFD_Enquiry_Triage::DB_OPTION );
tt_check( 'ensure_columns adds triage columns on upgrade', TZFD_Enquiry_Triage::ensure_columns() === true );
$cols = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
tt_check( 'all six triage columns present', count( array_intersect( $cols, array( 'triage_intent', 'triage_queue', 'triage_tags', 'triage_source', 'triage_rules', 'triage_locked' ) ) ) === 6 );
tt_check( 'ensure_columns is idempotent', TZFD_Enquiry_Triage::ensure_columns() === true );

// Intake: a booking enquiry is stored, triaged to the bookings queue and keeps status new.
$payload = static function ( $company, $text, $name = 'Test Customer' ) {
    return array( 'company_id' => $company, 'name' => $name, 'email' => 'cust' . md5( $text . microtime() ) . '@example.test',
        'enquiry' => $text, 'source' => 'website-form' );
};
$book_id = TZFD_Lead_Intake::receive( $payload( $company_a, 'Can I book an appointment on Tuesday?' ) );
$saved_ids[] = $book_id;
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $book_id ) );
tt_check( 'booking enquiry stored', $book_id > 0 && $row );
tt_check( 'booking triaged to booking intent', $row && 'booking' === $row->triage_intent, $row ? $row->triage_intent : 'no row' );
tt_check( 'booking routed to bookings queue', $row && 'bookings' === $row->triage_queue, $row ? $row->triage_queue : '' );
tt_check( 'triage never changes status', $row && 'new' === $row->status );
tt_check( 'rules-sourced rows are not locked', $row && 0 === (int) $row->triage_locked && 'rules' === $row->triage_source );

// Overlapping intents: cancel primary, booking tagged.
$cancel_id = TZFD_Lead_Intake::receive( $payload( $company_a, 'Cancel my booking and rebook for Friday' ) );
$saved_ids[] = $cancel_id;
$row = $wpdb->get_row( $wpdb->prepare( "SELECT triage_intent, triage_queue, triage_tags FROM $table WHERE id = %d", $cancel_id ) );
tt_check( 'overlap: cancel primary, cancellations queue', $row && 'cancel' === $row->triage_intent && 'cancellations' === $row->triage_queue );
tt_check( 'overlap: booking and multi_intent tags', $row && false !== strpos( $row->triage_tags, 'booking' ) && false !== strpos( $row->triage_tags, 'multi_intent' ) );

// Spam: stored, routed to review, never deleted and never declined.
$before_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE company_id = %d", $company_a ) );
$spam_id = TZFD_Lead_Intake::receive( $payload( $company_a, 'WIN BIG at our online casino, click here for free money' ) );
$saved_ids[] = $spam_id;
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $spam_id ) );
tt_check( 'spam is stored, not dropped', $spam_id > 0 && $row );
tt_check( 'spam goes to spam_review queue', $row && 'spam_review' === $row->triage_queue );
tt_check( 'spam keeps status new (no auto-decline)', $row && 'new' === $row->status );
tt_check( 'spam intake adds exactly one row', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE company_id = %d", $company_a ) ) === $before_count + 1 );

// Negative intake: no company means nothing is stored.
tt_check( 'no company, nothing stored', 0 === TZFD_Lead_Intake::receive( $payload( 0, 'Book me in' ) ) );

// Tenant isolation: tenant B cannot triage or override tenant A's enquiry.
$b_result = TZFD_Enquiry_Triage::apply( $book_id, $company_b );
tt_check( 'tenant B cannot reclassify tenant A enquiry', '' === $b_result );
$override_b = TZFD_Enquiry_Triage::override( $book_id, $company_b, 'complaint', array(), 1 );
tt_check( 'tenant B override returns not found', is_wp_error( $override_b ) && 404 === $override_b->get_error_data()['status'] );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT triage_intent, triage_locked FROM $table WHERE id = %d", $book_id ) );
tt_check( 'tenant A row unchanged after tenant B attempts', $row && 'booking' === $row->triage_intent && 0 === (int) $row->triage_locked );
$list_b = TZFD_Enquiry_Review::list_for_company( $company_b );
tt_check( 'tenant B list does not include tenant A rows', ! in_array( (string) $book_id, array_map( static function ( $r ) { return (string) $r->id; }, $list_b ), true ) );

// Queue filter returns only the requested queue for the company.
$spam_rows = TZFD_Enquiry_Review::list_for_company( $company_a, '', 100, 'spam_review' );
tt_check( 'queue filter returns spam only', $spam_rows && count( array_filter( $spam_rows, static function ( $r ) { return 'spam_review' !== $r->triage_queue; } ) ) === 0 );
tt_check( 'invalid queue filter is ignored', count( TZFD_Enquiry_Review::list_for_company( $company_a, '', 100, 'drop_table' ) ) === count( TZFD_Enquiry_Review::list_for_company( $company_a ) ) );

// Administrator override: validated, locked, audited, status unchanged.
$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit" );
$ov = TZFD_Enquiry_Triage::override( $book_id, $company_a, 'complaint', array( 'urgent' ), 1 );
tt_check( 'override succeeds for own tenant', true === $ov, is_wp_error( $ov ) ? $ov->get_error_message() : '' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT triage_intent, triage_queue, triage_tags, triage_source, triage_locked, status FROM $table WHERE id = %d", $book_id ) );
tt_check( 'override sets intent and queue', $row && 'complaint' === $row->triage_intent && 'complaints' === $row->triage_queue );
tt_check( 'override sets admin source and lock', $row && 'admin' === $row->triage_source && 1 === (int) $row->triage_locked );
tt_check( 'override keeps tags with admin_override', $row && false !== strpos( $row->triage_tags, 'admin_override' ) && false !== strpos( $row->triage_tags, 'urgent' ) );
tt_check( 'override does not change status', $row && 'new' === $row->status );
tt_check( 'override writes an audit row', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit" ) === $audit_before + 1 );
tt_check( 'override rejects unknown intent', is_wp_error( TZFD_Enquiry_Triage::override( $book_id, $company_a, 'auto_decline', array(), 1 ) ) );
tt_check( 'override rejects a tag outside the allowlist', is_wp_error( TZFD_Enquiry_Triage::override( $book_id, $company_a, 'booking', array( 'delete_me' ), 1 ) ) );
tt_check( 'override rejects a missing enquiry', is_wp_error( TZFD_Enquiry_Triage::override( 0, $company_a, 'booking', array(), 1 ) ) );

// Safe reclassification: a locked row is not reclassified, an unlocked row is.
tt_check( 'reclassify skips locked row', '' === TZFD_Enquiry_Triage::apply( $book_id, $company_a ) );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT triage_intent FROM $table WHERE id = %d", $book_id ) );
tt_check( 'locked row keeps admin intent', $row && 'complaint' === $row->triage_intent );
$wpdb->update( $table, array( 'status' => 'reviewed' ), array( 'id' => $cancel_id ) );
tt_check( 'reclassify keeps reviewed status', 'cancel' === TZFD_Enquiry_Triage::apply( $cancel_id, $company_a ) );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM $table WHERE id = %d", $cancel_id ) );
tt_check( 'reviewed status survives reclassification', $row && 'reviewed' === $row->status );

// Business rules: an allowlist violation in the stored map is dropped, not used.
update_option( TZFD_Enquiry_Triage::QUEUE_MAP_OPTION, array( 'booking' => 'evil_queue', 'quote' => 'quotes', 'nonsense' => 'bookings' ) );
tt_check( 'queue map rejects a queue outside the allowlist', 'bookings' === TZFD_Enquiry_Triage::queue_for( 'booking' ) );
tt_check( 'queue map accepts an allowlisted change', 'quotes' === TZFD_Enquiry_Triage::queue_for( 'quote' ) );
delete_option( TZFD_Enquiry_Triage::QUEUE_MAP_OPTION );

// Rollback path: switching triage off leaves intake working and the enquiry stored untriaged.
update_option( TZFD_Enquiry_Triage::ENABLED_OPTION, '0' );
$off_id = TZFD_Lead_Intake::receive( $payload( $company_a, 'Cancel my booking please' ) );
$saved_ids[] = $off_id;
$row = $wpdb->get_row( $wpdb->prepare( "SELECT triage_intent, status FROM $table WHERE id = %d", $off_id ) );
tt_check( 'triage off: enquiry still stored', $off_id > 0 && $row && 'new' === $row->status );
tt_check( 'triage off: no triage written', $row && '' === $row->triage_intent );
update_option( TZFD_Enquiry_Triage::ENABLED_OPTION, '1' );

// Duplicate guard still returns the stored row and does not re-triage.
$dupe_payload = $payload( $company_a, 'Can I book an appointment on Tuesday?' );
$dupe_payload['email'] = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM $table WHERE id = %d", $book_id ) );
$dupe_payload['name']  = 'Test Customer';
tt_check( 'duplicate enquiry returns the existing row id', TZFD_Lead_Intake::receive( $dupe_payload ) === $book_id );

// Unauthorised and malformed admin-post requests are refused before any write. wp_die is captured as an exception.
function tt_die_code( $callable ) {
    add_filter( 'wp_die_handler', static function () {
        return static function ( $message, $title = '', $args = array() ) { throw new RuntimeException( 'die:' . ( $args['response'] ?? 0 ) ); };
    } );
    $code = 0;
    try {
        $callable();
    } catch ( RuntimeException $e ) {
        $code = (int) substr( $e->getMessage(), 4 );
    }
    remove_all_filters( 'wp_die_handler' );
    return $code;
}
$sub_user = wp_create_user( 'tzfd_sub_' . wp_rand( 1000, 9999 ), wp_generate_password( 16 ), 'sub' . wp_rand( 1000, 9999 ) . '@example.test' );
$audit_before_refusals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit" );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'enquiry' => (string) $book_id, 'intent' => 'spam', 'tags' => array( 'urgent' ) );
wp_set_current_user( (int) $sub_user );
tt_check( 'subscriber override is refused with 403', 403 === tt_die_code( static function () { TZFD_Enquiry_Triage::handle_override(); } ) );
wp_set_current_user( 1 );
// Grant the capability for this one check so the nonce guard, not the capability guard, is what refuses the request.
$admin_role = get_role( 'administrator' );
$admin_role->add_cap( 'tz_fd_access' );
tt_check( 'override without nonce is refused with 403', 403 === tt_die_code( static function () { TZFD_Enquiry_Triage::handle_override(); } ) );
$admin_role->remove_cap( 'tz_fd_access' );
$_SERVER['REQUEST_METHOD'] = 'GET';
tt_check( 'GET override is refused with 405', 405 === tt_die_code( static function () { TZFD_Enquiry_Triage::handle_override(); } ) );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT triage_intent, triage_source, status FROM $table WHERE id = %d", $book_id ) );
tt_check( 'refused requests change nothing', $row && 'complaint' === $row->triage_intent && 'admin' === $row->triage_source && 'new' === $row->status );
tt_check( 'refused requests write no audit row', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $audit" ) === $audit_before_refusals );
wp_delete_user( (int) $sub_user );

// Cleanup: remove only the rows this run created.
foreach ( $saved_ids as $sid ) { $wpdb->delete( $table, array( 'id' => (int) $sid ) ); }

echo 'Passed: ' . $GLOBALS['tt_pass'] . ', Failed: ' . $GLOBALS['tt_fail'] . "\n";
if ( $GLOBALS['tt_fail'] > 0 ) { exit( 1 ); }
