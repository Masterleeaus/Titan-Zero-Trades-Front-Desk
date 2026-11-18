<?php
/**
 * Database-backed test for the enquiry triage storage layer. It loads the real plugin classes against a real MySQL or
 * MariaDB server, through a minimal $wpdb shim and a few WordPress function stubs. It is NOT a WordPress install: it
 * does not test the admin screen rendering, nonces or capability checks in WordPress itself.
 *
 * Environment:
 *   TZFD_TEST_DB_NAME  scratch database (required; the test drops and recreates its tables in it).
 *   TZFD_TEST_DB_HOST  host (default localhost). TZFD_TEST_DB_PORT TCP port (default 3306).
 *   TZFD_TEST_DB_USER / TZFD_TEST_DB_PASS  credentials (default root, empty password).
 * Create the scratch database first: bash tests/integration/setup-scratch-dbs.sh (it creates tzfd_triage_test too).
 * Run from the repository root:
 *   TZFD_TEST_DB_HOST=127.0.0.1 TZFD_TEST_DB_USER=root TZFD_TEST_DB_PASS= TZFD_TEST_DB_NAME=tzfd_triage_test \
 *     php tests/integration/enquiry-triage-db-test.php
 */
if ( ! getenv( 'TZFD_TEST_DB_NAME' ) ) { fwrite( STDERR, "Set TZFD_TEST_DB_NAME (scratch database).\n" ); exit( 2 ); }
if ( ! extension_loaded( 'mysqli' ) ) { fwrite( STDERR, "mysqli extension required.\n" ); exit( 2 ); }

$root = dirname( __DIR__, 2 );
$abs  = sys_get_temp_dir() . '/tzfd-abspath-' . getmypid() . '/';
@mkdir( $abs . 'wp-admin/includes', 0777, true );
file_put_contents( $abs . 'wp-admin/includes/upgrade.php', "<?php\n" );
define( 'ABSPATH', $abs );
define( 'MINUTE_IN_SECONDS', 60 );

mysqli_report( MYSQLI_REPORT_OFF ); // $wpdb reports errors through last_error, not exceptions.
$db_name = getenv( 'TZFD_TEST_DB_NAME' );
$db_port = (int) ( getenv( 'TZFD_TEST_DB_PORT' ) ?: ini_get( 'mysqli.default_port' ) );
try {
	$mysqli = new mysqli( getenv( 'TZFD_TEST_DB_HOST' ) ?: 'localhost', getenv( 'TZFD_TEST_DB_USER' ) ?: 'root', (string) getenv( 'TZFD_TEST_DB_PASS' ), $db_name, $db_port );
} catch ( mysqli_sql_exception $e ) {
	fwrite( STDERR, "Connect failed for scratch database '{$db_name}': " . $e->getMessage() . "\n" );
	fwrite( STDERR, "Create it first: bash tests/integration/setup-scratch-dbs.sh (see the header of this file).\n" );
	exit( 2 );
}
if ( $mysqli->connect_errno ) { fwrite( STDERR, 'Connect failed: ' . $mysqli->connect_error . "\n" ); exit( 2 ); }

/** Minimal $wpdb: prepare() with %i %d %s, plus the methods the triage code calls. */
final class TZFD_Test_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $db;
    public function __construct( $db ) { $this->db = $db; }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'; }
    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback( '/%(i|d|s)/', function ( $m ) use ( &$i, $args ) {
            $v = $args[ $i++ ] ?? null;
            if ( 'i' === $m[1] ) { return '`' . str_replace( '`', '``', (string) $v ) . '`'; }
            if ( 'd' === $m[1] ) { return (string) (int) $v; }
            return "'" . $this->db->real_escape_string( (string) $v ) . "'";
        }, $query );
    }
    private function run( $sql ) {
        $this->last_error = '';
        $r = $this->db->query( $sql );
        if ( false === $r ) { $this->last_error = $this->db->error; }
        return $r;
    }
    public function get_results( $sql ) {
        $r = $this->run( $sql );
        if ( ! $r ) { return array(); }
        $out = array();
        while ( $row = $r->fetch_object() ) { $out[] = $row; }
        return $out;
    }
    public function get_row( $sql ) { $r = $this->run( $sql ); return $r ? $r->fetch_object() : null; }
    public function get_var( $sql ) { $r = $this->run( $sql ); if ( ! $r ) { return null; } $row = $r->fetch_row(); return $row[0] ?? null; }
    private function lit( $v ) {
        if ( null === $v ) { return 'NULL'; }
        if ( is_int( $v ) ) { return (string) $v; }
        return "'" . $this->db->real_escape_string( (string) $v ) . "'";
    }
    public function insert( $table, $data ) {
        $cols = implode( ',', array_map( function ( $c ) { return '`' . $c . '`'; }, array_keys( $data ) ) );
        $vals = implode( ',', array_map( array( $this, 'lit' ), array_values( $data ) ) );
        $ok = $this->run( "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})" );
        $this->insert_id = (int) $this->db->insert_id;
        return $ok ? 1 : false;
    }
    public function update( $table, $data, $where ) {
        $set = implode( ',', array_map( function ( $k, $v ) { return '`' . $k . '`=' . $this->lit( $v ); }, array_keys( $data ), array_values( $data ) ) );
        $w   = implode( ' AND ', array_map( function ( $k, $v ) { return '`' . $k . '`=' . $this->lit( $v ); }, array_keys( $where ), array_values( $where ) ) );
        $ok = $this->run( "UPDATE `{$table}` SET {$set} WHERE {$w}" );
        return $ok ? (int) $this->db->affected_rows : false;
    }
    public function query( $sql ) { $r = $this->run( $sql ); return false === $r ? false : true; }
}
$wpdb = new TZFD_Test_WPDB( $mysqli );

// ---- WordPress function stubs (only what the triage path calls) -------------------------
$GLOBALS['tzfd_options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['tzfd_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['tzfd_options'][ $k ] = $v; return true; }
function wp_mail( $to, $subject, $body ) { $GLOBALS['tzfd_mail'][] = array( $to, $subject, $body ); return true; }
function sanitize_email( $e ) { return trim( (string) $e ); }
function is_email( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function dbDelta( $sql ) { global $wpdb; $wpdb->query( $sql ); return array(); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : $ts ); }
function current_time( $type ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_salt( $s = '' ) { return 'test-salt'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { return ''; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_html( $s ); }
function sanitize_key_stub() {}
function current_user_can( $c ) { return true; }
function get_current_user_id() { return 0; }
function add_action( ...$a ) { return true; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_data() { return $this->data; } }

// ---- Load the real classes -------------------------------------------------------------------
$inc = $root . '/titan-zero-trades-front-desk/includes/';
require_once $inc . 'class-tzfd-tables.php';
require_once $inc . 'class-tzfd-local-audit.php';
require_once $inc . 'class-tzfd-transaction-storage.php';
require_once $inc . 'class-tzfd-request-value.php';
require_once $inc . 'class-tzfd-enquiry-triage.php';

// Enquiries table: same column set as TZFD_Business_Schema::run() (tz_enquiries).
$p = $wpdb->prefix;
$mysqli->query( "DROP TABLE IF EXISTS `{$p}tz_enquiry_triage`, `{$p}tz_fd_audit_log`, `{$p}tz_enquiries`" );
$mysqli->query( "CREATE TABLE `{$p}tz_enquiries` (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT, company_id bigint(20) unsigned NOT NULL DEFAULT 0,
    name varchar(190) NOT NULL DEFAULT '', email varchar(190) NOT NULL DEFAULT '', phone varchar(60) NOT NULL DEFAULT '',
    preferred_contact varchar(20) NOT NULL DEFAULT '', enquiry text NOT NULL, campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
    campaign_title varchar(190) NOT NULL DEFAULT '', source varchar(60) NOT NULL DEFAULT '', received_utc datetime NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'new', created datetime NOT NULL, PRIMARY KEY (id), KEY company_id (company_id), KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
TZFD_Local_Audit::install();

$pass = 0;
$fail = array();
function t_check( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; return; }
    $fail[] = $name . ( '' !== $detail ? ' :: ' . $detail : '' );
}

$now_sql = gmdate( 'Y-m-d H:i:s' );
function enquiry( $company, $email, $text ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'tz_enquiries', array(
        'company_id' => $company, 'name' => 'Test', 'email' => $email, 'enquiry' => $text, 'received_utc' => gmdate( 'Y-m-d H:i:s' ),
        'status' => 'new', 'created' => gmdate( 'Y-m-d H:i:s' ), 'source' => 'website-form',
    ) );
    return $wpdb->insert_id;
}
function triage_row( $company, $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND enquiry_id = %d', $wpdb->prefix . 'tz_enquiry_triage', $company, $id ) );
}
function count_rows( $table, $company, $where = '' ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d' . $where, $table, $company ) );
}

$ip_a = hash_hmac( 'sha256', '198.51.100.7', 'test-salt' );
$ip_b = hash_hmac( 'sha256', '198.51.100.99', 'test-salt' );
$spam_text = 'Earn money fast with crypto, see http://a.example www.b.example https://c.example';
$dup_text  = 'I have been a customer for a while and the kitchen tap in the back room has been dripping for about two weeks now, the water pools under the sink, could someone come out and fix the tap before the weekend thanks';

// 1. A normal booking enquiry is triaged, and no spam hold is set.
$e1 = enquiry( 1, 'a@example.com', 'Can I book a plumber on Friday morning?' );
$r1 = TZFD_Enquiry_Triage::record( 1, $e1, 'Can I book a plumber on Friday morning?', 'a@example.com', $ip_a );
$t1 = triage_row( 1, $e1 );
t_check( 'booking triaged and stored', is_array( $r1 ) && $t1 && 'booking' === $t1->primary_intent && 'none' === $t1->spam_state, json_encode( $t1 ) );

// 2. A spam enquiry is held for review and the enquiry row is not changed or deleted.
$e2 = enquiry( 1, 'spam@example.net', $spam_text );
TZFD_Enquiry_Triage::record( 1, $e2, $spam_text, 'spam@example.net', $ip_b );
$t2 = triage_row( 1, $e2 );
$enq2 = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $wpdb->prefix . 'tz_enquiries', $e2 ) );
t_check( 'spam held for review', $t2 && 'held' === $t2->spam_state && 'spam' === $t2->primary_intent, json_encode( $t2 ) );
t_check( 'held enquiry row kept with status new', $enq2 && 'new' === $enq2->status );

// 3. Same contact sends the same message three times: third one is held; each stays in the queue.
$e3 = enquiry( 1, 'repeat@example.com', $dup_text );
TZFD_Enquiry_Triage::record( 1, $e3, $dup_text, 'repeat@example.com', $ip_a );
$e4 = enquiry( 1, 'repeat@example.com', $dup_text );
TZFD_Enquiry_Triage::record( 1, $e4, $dup_text, 'repeat@example.com', $ip_a );
$t4 = triage_row( 1, $e4 );
t_check( 'second identical is a duplicate, not held', $t4 && 'none' === $t4->spam_state && 'identical' === $t4->duplicate_of, json_encode( $t4 ) );
$e5 = enquiry( 1, 'repeat@example.com', $dup_text );
TZFD_Enquiry_Triage::record( 1, $e5, $dup_text, 'repeat@example.com', $ip_a );
$t5 = triage_row( 1, $e5 );
t_check( 'third identical from same contact held', $t5 && 'held' === $t5->spam_state && false !== strpos( (string) $t5->spam_signals, 'repeat_same_contact' ), json_encode( $t5 ) );

// 4. Tenant isolation: company 2 sending the same text from the same address and email is not a duplicate of company 1.
$e6 = enquiry( 2, 'repeat@example.com', $dup_text );
TZFD_Enquiry_Triage::record( 2, $e6, $dup_text, 'repeat@example.com', $ip_a );
$t6 = triage_row( 2, $e6 );
t_check( 'other company not counted as duplicate', $t6 && '' === $t6->duplicate_of && 'none' === $t6->spam_state, json_encode( $t6 ) );

// 5. Company scope on reads: map_for_company only returns this company's rows.
$map = TZFD_Enquiry_Triage::map_for_company( 1, array( $e1, $e2, $e6 ) );
t_check( 'map is company scoped', isset( $map[ $e1 ] ) && isset( $map[ $e2 ] ) && ! isset( $map[ $e6 ] ), json_encode( array_keys( $map ) ) );

// 6. Cross-tenant admin action is refused and changes nothing.
$res = TZFD_Enquiry_Triage::apply( $e2, 'restore_spam', 2, 9 );
$after = triage_row( 1, $e2 );
t_check( 'cross-tenant restore refused', is_wp_error( $res ) && 404 === $res->data['status'] && $after && 'held' === $after->spam_state, json_encode( $res ) );

// 7. Restore in own company works, and is audited in that company only.
$res = TZFD_Enquiry_Triage::apply( $e2, 'restore_spam', 1, 5 );
$after = triage_row( 1, $e2 );
t_check( 'restore succeeds in own company', true === $res && $after && 'restored' === $after->spam_state && 5 === (int) $after->state_by, json_encode( $res ) );
$audit = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d AND event_action = %s AND entity_id = %d', $wpdb->prefix . 'tz_fd_audit_log', 1, 'enquiry.triage.restore_spam', $e2 ) );
$audit_other = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d AND entity_id = %d', $wpdb->prefix . 'tz_fd_audit_log', 2, $e2 ) );
t_check( 'restore audited for company 1 only', 1 === $audit && 0 === $audit_other, "audit={$audit} other={$audit_other}" );

// 8. Invalid transitions are refused.
$res = TZFD_Enquiry_Triage::apply( $e2, 'confirm_spam', 1, 5 );
t_check( 'confirm on a restored enquiry refused', is_wp_error( $res ) && 409 === $res->data['status'] );
$res = TZFD_Enquiry_Triage::apply( $e1, 'restore_spam', 1, 5 );
t_check( 'restore on a non-held enquiry refused', is_wp_error( $res ) && 409 === $res->data['status'] );
$res = TZFD_Enquiry_Triage::apply( $e5, 'confirm_spam', 1, 5 );
$after = triage_row( 1, $e5 );
t_check( 'confirm spam keeps the row', true === $res && $after && 'confirmed' === $after->spam_state && $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', $wpdb->prefix . 'tz_enquiries', $e5 ) ) == 1 );
$res = TZFD_Enquiry_Triage::apply( $e5, 'restore_spam', 1, 5 );
t_check( 'restore from confirmed allowed', true === $res );
$res = TZFD_Enquiry_Triage::apply( $e1, 'delete_everything', 1, 5 );
t_check( 'unknown operation refused', is_wp_error( $res ) && 400 === $res->data['status'] );

// 9. Urgency override is recorded beside the rule result, and the rule result is not lost.
$e7 = enquiry( 1, 'urgent@example.com', 'The pipe has burst, this is urgent, please call me back' );
TZFD_Enquiry_Triage::record( 1, $e7, 'The pipe has burst, this is urgent, please call me back', 'urgent@example.com', $ip_b );
$t7 = triage_row( 1, $e7 );
t_check( 'urgent rule stored', $t7 && 'urgent' === $t7->urgency && false !== strpos( (string) $t7->urgency_reasons, 'explicit_urgent' ), json_encode( $t7 ) );
TZFD_Enquiry_Triage::apply( $e7, 'override_normal', 1, 5 );
$t7b = triage_row( 1, $e7 );
t_check( 'override kept beside rule result', $t7b && 'normal' === $t7b->urgency_override && 'urgent' === $t7b->urgency );
$urgent_audit = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE company_id = %d AND event_action = %s', $wpdb->prefix . 'tz_fd_audit_log', 1, 'enquiry.urgent' ) );
t_check( 'urgent rule audited', $urgent_audit >= 1 );

// 10. Replay: the same enquiry triaged again does not create a second row.
$before = count_rows( $wpdb->prefix . 'tz_enquiry_triage', 1 );
$dup_insert = TZFD_Enquiry_Triage::record( 1, $e1, 'Can I book a plumber on Friday morning?', 'a@example.com', $ip_a );
t_check( 'replay does not add a row', false === $dup_insert && count_rows( $wpdb->prefix . 'tz_enquiry_triage', 1 ) === $before );

// 11. Failure containment: bad inputs return false without throwing.
t_check( 'zero company refused', false === TZFD_Enquiry_Triage::record_safely( 0, $e1, 'x', 'a@example.com', '' ) );
t_check( 'malformed ip hash dropped, not stored', false !== TZFD_Enquiry_Triage::record_safely( 1, enquiry( 1, 'b@example.com', 'Quote please for cleaning' ), 'Quote please for cleaning', 'b@example.com', 'not-a-hash' ) );

// 12. Unicode: the same message in full-width letters is a duplicate of the plain one.
if ( function_exists( 'mb_strtolower' ) && class_exists( 'Normalizer' ) ) {
    $plain = 'Can you come and look at the gate latch this week please thank you';
    $wide  = "\u{FF23}an you come and look at the gate latch this week please thank you";
    $ea = enquiry( 1, 'uni1@example.com', $plain );
    TZFD_Enquiry_Triage::record( 1, $ea, $plain, 'uni1@example.com', '' );
    $eb = enquiry( 1, 'uni2@example.com', $wide );
    TZFD_Enquiry_Triage::record( 1, $eb, $wide, 'uni2@example.com', '' );
    $tb = triage_row( 1, $eb );
    t_check( 'full-width duplicate detected through NFKC', $tb && 'identical' === $tb->duplicate_of, json_encode( $tb ) );
}

// 13. Row rendering escapes stored values and offers the review actions only for a held enquiry.
$html = TZFD_Enquiry_Triage::row_html( $e2, triage_row( 1, $e2 ) );
t_check( 'restored row shows no hold actions', false === strpos( $html, 'Confirm spam' ) );
$e8 = enquiry( 1, 'held@example.com', $spam_text . ' <script>x</script>' );
TZFD_Enquiry_Triage::record( 1, $e8, $spam_text, 'held@example.com', '' );
$html = TZFD_Enquiry_Triage::row_html( $e8, triage_row( 1, $e8 ) );
t_check( 'held row offers restore and confirm', false !== strpos( $html, 'Restore (not spam)' ) && false !== strpos( $html, 'Confirm spam' ) && false !== strpos( $html, 'Held for spam review' ) );
t_check( 'no raw script in list html', false === strpos( $html, '<script' ) );

// 14. Recovery: when the triage table is missing, the enquiry is still saved and triage returns without throwing.
// Once the schema is marked as current again, triage recreates the table and stores the next enquiry normally.
$rec_text = 'Can you come Tuesday for a quote on the gutters';
$e_rec    = enquiry( 1, 'recover@example.com', $rec_text );
$mysqli->query( "DROP TABLE IF EXISTS `{$p}tz_enquiry_triage`" );
$rec_threw = false;
try {
    $rec_result = TZFD_Enquiry_Triage::record_safely( 1, $e_rec, $rec_text, 'recover@example.com', '' );
} catch ( Throwable $ex ) {
    $rec_threw = true;
    $rec_result = null;
}
$rec_row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM %i WHERE id = %d', $wpdb->prefix . 'tz_enquiries', $e_rec ) );
t_check( 'triage failure does not throw', false === $rec_threw );
t_check( 'triage failure leaves the enquiry saved and new', $rec_row && 'new' === $rec_row->status );
t_check( 'triage failure stores no triage row', false === $rec_result );

unset( $GLOBALS['tzfd_options'][ TZFD_Enquiry_Triage::SCHEMA_OPTION ] );
$e_after = enquiry( 1, 'after@example.com', $rec_text );
$after_result = TZFD_Enquiry_Triage::record_safely( 1, $e_after, $rec_text, 'after@example.com', '' );
$after_row    = triage_row( 1, $e_after );
t_check( 'triage resumes after the table is restored', false !== $after_result && $after_row && 'none' === $after_row->spam_state );

echo "Passed: {$pass}\n";
echo 'Failed: ' . count( $fail ) . "\n";
foreach ( $fail as $f ) { echo "  FAIL: {$f}\n"; }
$mysqli->query( "DROP TABLE IF EXISTS `{$p}tz_enquiry_triage`, `{$p}tz_fd_audit_log`, `{$p}tz_enquiries`" );
exit( $fail ? 1 : 0 );
