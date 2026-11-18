<?php
/**
 * Database integration tests for missed-call tasks and SMS reply state (issues #30 and #31).
 *
 * Runs against a real MySQL/MariaDB database (unique keys and atomic UPDATEs are what is being tested).
 * It uses a small $wpdb shim and stubs for the few WordPress functions used, so no WordPress install is needed.
 * Environment:
 *   TZFD_TEST_DB_NAME    scratch database name (default tzfd_lane_test).
 *   TZFD_TEST_DB_HOST    TCP host, e.g. 127.0.0.1 for the CI service container. Unset: local socket.
 *   TZFD_TEST_DB_PORT    TCP port when TZFD_TEST_DB_HOST is set (default 3306).
 *   TZFD_TEST_DB_USER / TZFD_TEST_DB_PASS  credentials (default root, empty password) when TZFD_TEST_DB_HOST is set.
 *   TZFD_TEST_DB_SOCKET  socket path when TZFD_TEST_DB_HOST is unset (default /var/run/mysqld/mysqld.sock).
 * The scratch database must exist first. Create it with: bash tests/integration/setup-scratch-dbs.sh
 * Run: php tests/integration/contact-db-tests.php   (from the repository root)
 * The test drops and recreates wp_tz_contact_actions and wp_tz_sms_conversations in that scratch database only.
 */
define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }

$db_name   = getenv( 'TZFD_TEST_DB_NAME' ) ?: 'tzfd_lane_test';
$db_socket = getenv( 'TZFD_TEST_DB_SOCKET' ) ?: '/var/run/mysqld/mysqld.sock';
// TZFD_TEST_DB_HOST (with TZFD_TEST_DB_PASS) connects over TCP, e.g. to a CI service container; unset, the local socket is used.
$db_host = getenv( 'TZFD_TEST_DB_HOST' ) ?: '';
$db_port = (int) ( getenv( 'TZFD_TEST_DB_PORT' ) ?: 3306 );
// mysqli throws on a missing database (PHP 8.1+ default). Catch it so the reason is printed, not an uncaught exception.
try {
	$mysqli = '' !== $db_host
		? new mysqli( $db_host, getenv( 'TZFD_TEST_DB_USER' ) ?: 'root', (string) getenv( 'TZFD_TEST_DB_PASS' ), $db_name, $db_port )
		: new mysqli( 'localhost', 'root', '', $db_name, 0, $db_socket );
} catch ( mysqli_sql_exception $e ) {
	fwrite( STDERR, "DB connect failed for scratch database '{$db_name}': " . $e->getMessage() . "\n" );
	fwrite( STDERR, "Create it first: bash tests/integration/setup-scratch-dbs.sh (see the header of this file).\n" );
	exit( 2 );
}
if ( $mysqli->connect_errno ) { fwrite( STDERR, 'DB connect failed: ' . $mysqli->connect_error . "\n" ); exit( 2 ); }

final class TZ_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    private $m;
    public function __construct( $m ) { $this->m = $m; }
    public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function prepare( $sql, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%(i|d|s)/', function ( $m ) use ( &$i, $args ) {
            $v = $args[ $i++ ];
            if ( 'i' === $m[1] ) { return '`' . str_replace( '`', '', (string) $v ) . '`'; }
            if ( 'd' === $m[1] ) { return (string) (int) $v; }
            return "'" . $this->m->real_escape_string( (string) $v ) . "'";
        }, $sql );
    }
    public function query( $sql ) {
        $r = $this->m->query( $sql );
        if ( false === $r ) { $this->last_error = $this->m->error; return false; }
        if ( true === $r ) { $this->insert_id = $this->m->insert_id; return $this->m->affected_rows; }
        $r->free();
        return 0;
    }
    public function get_var( $sql ) {
        $r = $this->m->query( $sql );
        if ( ! $r ) { return null; }
        $row = $r->fetch_row();
        $r->free();
        return $row ? $row[0] : null;
    }
    public function get_results( $sql, $output = null ) {
        $r = $this->m->query( $sql );
        $rows = array();
        if ( $r ) { while ( $row = $r->fetch_assoc() ) { $rows[] = $row; } $r->free(); }
        return $rows;
    }
}

$GLOBALS['wpdb'] = new TZ_WPDB( $mysqli );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['tz_options'] = array();
$GLOBALS['tz_filters'] = array();
$GLOBALS['tz_company'] = 7;

function get_option( $k, $d = false ) { return $GLOBALS['tz_options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['tz_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['tz_options'][ $k ] ); return true; }
function current_user_can( $cap ) { return true; }
function apply_filters( $hook, $value, ...$args ) {
    return isset( $GLOBALS['tz_filters'][ $hook ] ) ? call_user_func( $GLOBALS['tz_filters'][ $hook ], $value, ...$args ) : $value;
}
function add_action( $hook, $cb, $p = 10, $n = 1 ) {}
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function current_time( $type = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function dbDelta( $sql ) { $GLOBALS['wpdb']->query( $sql ); return array(); }
function __( $text, $domain = '' ) { return $text; }
class WP_Error { public $code; public function __construct( $code = '', $msg = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
final class TZFD_Scoped_Store { public static function company_id() { return $GLOBALS['tz_company']; } }

$base = __DIR__ . '/../../titan-zero-trades-front-desk/includes/';
require_once $base . 'class-tzfd-tables.php';
require_once $base . 'class-tzfd-contact-rules.php';
require_once $base . 'class-tzfd-contact-actions.php';
require_once $base . 'class-tzfd-missed-call-tasks.php';
require_once $base . 'class-tzfd-messaging-replies.php';

// Fresh schema.
$mysqli->query( 'DROP TABLE IF EXISTS wp_tz_contact_actions' );
$mysqli->query( 'DROP TABLE IF EXISTS wp_tz_sms_conversations' );

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function tz_check( $name, $ok ) {
    if ( $ok ) { $GLOBALS['pass']++; } else { $GLOBALS['fail']++; }
    echo esc_html( ( $ok ? 'PASS ' : 'FAIL ' ) . $name ) . "\n";
}
function count_rows( $table, $company ) {
    return (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d', $table, $company ) );
}

$A = 'wp_tz_contact_actions';
$C = 'wp_tz_sms_conversations';

tz_check( 'schema installs and is visible', TZFD_Contact_Actions::install() === true && TZFD_Contact_Actions::installed() );

// Candidate rows for the owner-supplied filter. Company 7 has one customer on the caller's number.
$GLOBALS['tz_filters']['tzfd_missed_call_contact_candidates_v1'] = function ( $rows, $company, $e164 ) {
    if ( 7 === $company ) {
        return array(
            array( 'company_id' => 7, 'customer_id' => 11, 'phone' => '0400 000 000' ),
            array( 'company_id' => 7, 'customer_id' => 12, 'phone' => '+61 400 111 222' ),
            array( 'company_id' => 7, 'customer_id' => 13, 'phone' => '0400111222' ),
        );
    }
    return array();
};

// #30 missed-call tasks.
$r1 = TZFD_Missed_Call_Tasks::record( 7, 'twilio', 'CA-100', '0400 000 000' );
tz_check( 'missed call creates one callback task', is_array( $r1 ) && 'created' === $r1['task'] );
$r2 = TZFD_Missed_Call_Tasks::record( 7, 'twilio', 'CA-100', '0400 000 000' );
tz_check( 'redelivered missed call creates no second task (idempotent)', is_array( $r2 ) && 'duplicate' === $r2['task'] );
tz_check( 'exactly one task row for that call', 1 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = 7 AND kind = %s AND source = %s', $A, 'callback', 'missed_call' ) ) );
$rows = TZFD_Contact_Actions::recent( 7 );
tz_check( 'task shows the number masked to four digits', ! empty( $rows ) && '***0000' === $rows[0]['phone'] );
tz_check( 'single phone match is recorded as a candidate', ! empty( $rows ) && 'candidate' === $rows[0]['match_status'] && 11 === (int) $rows[0]['customer_id'] );

$r3 = TZFD_Missed_Call_Tasks::record( 7, 'twilio', 'CA-101', '0400111222' );
$rows = TZFD_Contact_Actions::recent( 7 );
tz_check( 'two customers on one number is ambiguous, no customer chosen', 'ambiguous' === $rows[0]['match_status'] && 0 === (int) $rows[0]['customer_id'] );

$r4 = TZFD_Missed_Call_Tasks::record( 7, 'twilio', 'CA-102', 'anonymous' );
$rows = TZFD_Contact_Actions::recent( 7 );
tz_check( 'anonymous caller still gets a task, with no callback number', 'no_callback_number' === $rows[0]['state'] && '' === $rows[0]['phone'] );

// Tenant isolation: the same provider and call id for another company is a different task.
$GLOBALS['tz_company'] = 8;
$r5 = TZFD_Missed_Call_Tasks::record( 8, 'twilio', 'CA-100', '0400 000 000' );
tz_check( 'other company, same call id: separate task created (no cross-tenant dedupe)', is_array( $r5 ) && 'created' === $r5['task'] );
tz_check( 'other company does not see candidates from company 7', ! empty( TZFD_Contact_Actions::recent( 8 ) ) && 'unmatched' === TZFD_Contact_Actions::recent( 8 )[0]['match_status'] );
$r6 = TZFD_Missed_Call_Tasks::record( 9, 'twilio', 'CA-200', '0400 000 000' );
tz_check( 'credential for another company is denied before any write', is_wp_error( $r6 ) && 'tzfd_missed_task_tenant' === $r6->get_error_code() );
tz_check( 'denied credential leaves no task row for that company', 0 === count_rows( $A, 9 ) );
$GLOBALS['tz_company'] = 7;

// Schema missing: the write reports an error, never success.
update_option( TZFD_Contact_Actions::SCHEMA_OPTION, 'none' );
$r7 = TZFD_Missed_Call_Tasks::record( 7, 'twilio', 'CA-300', '0400 000 000' );
tz_check( 'schema missing returns 503 so the provider retries', is_wp_error( $r7 ) && 'tzfd_missed_task_unavailable' === $r7->get_error_code() );
update_option( TZFD_Contact_Actions::SCHEMA_OPTION, TZFD_Contact_Actions::SCHEMA_VERSION );
$r8 = TZFD_Missed_Call_Tasks::record( 7, 'twilio', 'CA-300', '0400 000 000' );
tz_check( 'after the schema is back, the retry creates the task', is_array( $r8 ) && 'created' === $r8['task'] );

// #31 turn cap: atomic, three per conversation.
$conv = TZFD_Contact_Rules::conversation_key( 7, '+61400000000' );
$taken = array();
for ( $i = 0; $i < 5; $i++ ) { $taken[] = TZFD_Contact_Actions::claim_turn( 7, $conv ); }
tz_check( 'exactly three automated turns are granted, then the cap holds', array( true, true, true, false, false ) === $taken );
tz_check( 'turns_used reports three', 3 === TZFD_Contact_Actions::turns_used( 7, $conv ) );
TZFD_Contact_Actions::release_turn( 7, $conv );
tz_check( 'releasing a turn whose message failed frees one turn', 2 === TZFD_Contact_Actions::turns_used( 7, $conv ) && TZFD_Contact_Actions::claim_turn( 7, $conv ) );

// Conversation window expires after seven days, restarting the count.
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE %i SET window_started = %s WHERE company_id = 7 AND conversation_key = %s', $C, gmdate( 'Y-m-d H:i:s', time() - 700000 ), $conv ) );
tz_check( 'turn count restarts after the window expires', 0 === TZFD_Contact_Actions::turns_used( 7, $conv ) );

// Opt-out is persisted, and is cleared by START.
tz_check( 'a number with no conversation row is not opted out', false === TZFD_Contact_Actions::is_opted_out( 7, $conv ) );
tz_check( 'opt-out is saved', TZFD_Contact_Actions::set_opt_out( 7, $conv, true ) && TZFD_Contact_Actions::is_opted_out( 7, $conv ) );
tz_check( 'opt-out is company scoped (company 8 does not see it)', false === TZFD_Contact_Actions::is_opted_out( 8, $conv ) );
tz_check( 'START clears the opt-out', TZFD_Contact_Actions::set_opt_out( 7, $conv, false ) && false === TZFD_Contact_Actions::is_opted_out( 7, $conv ) );

// Action claims: a repeated key is a duplicate, and a released claim can be taken again.
$k = TZFD_Contact_Rules::dedupe_key( 7, 'sms_reply', 'twilio|evt-A' );
tz_check( 'first claim is created', 'created' === TZFD_Contact_Actions::claim_action( 7, $k, 'reply', 'sms_reply', '+61400000000', 'none', null, 'book', 'processing' ) );
tz_check( 'second claim of the same event is a duplicate', 'duplicate' === TZFD_Contact_Actions::claim_action( 7, $k, 'reply', 'sms_reply', '+61400000000', 'none', null, 'book', 'processing' ) );
TZFD_Contact_Actions::release_action( 7, $k );
tz_check( 'after a failed attempt is released, the retry is created again', 'created' === TZFD_Contact_Actions::claim_action( 7, $k, 'reply', 'sms_reply', '+61400000000', 'none', null, 'book', 'processing' ) );
TZFD_Contact_Actions::set_action_state( 7, $k, 'done' );
TZFD_Contact_Actions::release_action( 7, $k );
tz_check( 'a finished claim is kept by release_action (only processing claims are removed)', 1 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = 7 AND dedupe_key = %s', $A, $k ) ) );

// Reply events through process(): opt-out, refusal and duplicate prevention. No outbound send is needed on these paths.
$GLOBALS['tz_company'] = 7;
// Not exercised here: process() with STOP also writes to the shared outbox opt-out store (TZFD_Outbox::opt_out), which needs
// the full WordPress data layer. The opt-out flag itself is set directly, as the STOP branch would.
tz_check( 'opt-out flag set for the caller before the reply tests', TZFD_Contact_Actions::set_opt_out( 7, $conv, true ) && TZFD_Contact_Actions::is_opted_out( 7, $conv ) );
$before = count_rows( $A, 7 );
$refused = TZFD_Messaging_Replies::process( 7, 'twilio', 'msg-book-1', '+61400000000', 'book', 'customer_initiated' );
$reply_tasks = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = 7 AND source = %s AND kind <> %s', $A, 'sms_reply', 'reply' ) );
tz_check( 'an opted-out caller gets no reply and no reply-sourced task (only the event claim is stored)', is_array( $refused ) && 'refuse' === $refused['action'] && 0 === $reply_tasks && $before + 1 === count_rows( $A, 7 ) );
$dup = TZFD_Messaging_Replies::process( 7, 'twilio', 'msg-book-1', '+61400000000', 'book', 'customer_initiated' );
tz_check( 'a repeated reply event is a duplicate and sends nothing', is_array( $dup ) && 'duplicate' === $dup['action'] );
$cancel = TZFD_Messaging_Replies::process( 7, 'twilio', 'msg-cancel-1', '+61400000000', 'cancel my booking', 'customer_initiated' );
tz_check( 'opted-out cancel creates no staff task either', is_array( $cancel ) && 'refuse' === $cancel['action'] );
$no_consent = TZFD_Messaging_Replies::process( 7, 'twilio', 'msg-x-1', '+61400999999', 'book', '' );
tz_check( 'reply without consent basis is refused', is_array( $no_consent ) && 'refuse' === $no_consent['action'] );
$wrong = TZFD_Messaging_Replies::process( 9, 'twilio', 'msg-y-1', '+61400999999', 'book', 'customer_initiated' );
tz_check( 'reply for another company is denied before any write', is_wp_error( $wrong ) && 'tzfd_messaging_tenant' === $wrong->get_error_code() && 0 === count_rows( $A, 9 ) );

echo esc_html( "\nSummary: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n" );
$mysqli->close();
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
