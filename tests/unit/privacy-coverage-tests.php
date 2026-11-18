<?php
/**
 * Privacy export and erase coverage for every plugin table that holds personal data.
 *
 * Run: php -d error_reporting=-1 tests/unit/privacy-coverage-tests.php   (no WordPress install needed).
 *
 * The plugin's own SQL runs against an in-memory SQLite database behind a small $wpdb stand-in, so the
 * per-table export and erase rules are exercised for real. MySQL-only behaviour (dbDelta, SHOW TABLES
 * edge cases, InnoDB) is not covered here; the WordPress runtime suite covers it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['tzfd_privacy_results'] = array( 'pass' => 0, 'fail' => 0 );

function tzfd_privacy_check( $name, $ok, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['tzfd_privacy_results']['pass'];
		echo 'PASS ' . $name . "\n";
	} else {
		++$GLOBALS['tzfd_privacy_results']['fail'];
		echo 'FAIL ' . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
	}
}

// ---- WordPress stand-ins ----------------------------------------------------------------------------------------

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return (string) $url; }
function wp_kses_post( $html ) { return (string) $html; }
function _n( $single, $plural, $number, $domain = '' ) { return 1 === (int) $number ? $single : $plural; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return preg_replace( '/[^A-Za-z0-9@._+\-]/', '', (string) $value ); }
function is_email( $value ) { return is_string( $value ) && 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $value ); }
function wp_json_encode( $value, $options = 0, $depth = 512 ) { return json_encode( $value, $options, $depth ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function do_action( $hook, ...$args ) { return null; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function delete_transient( $name ) {
	return $GLOBALS['wpdb']->delete( 'wp_options', array( 'option_name' => '_transient_' . $name ), array( '%s' ) ) > 0;
}

/**
 * $wpdb stand-in backed by SQLite. prepare() follows WordPress placeholder rules (%s, %d, %i). LIKE gets an
 * ESCAPE '\' clause so esc_like() output behaves as it does in MySQL.
 */
class TZFD_Test_Wpdb {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $last_error = '';
	/** Any SQL containing this text fails, to test error handling. */
	public $fail_on   = '';
	private $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%([sdi])/', function ( $m ) use ( &$i, $args ) {
			$value = $args[ $i++ ];
			if ( 'i' === $m[1] ) {
				return '`' . str_replace( '`', '', (string) $value ) . '`';
			}
			if ( 'd' === $m[1] ) {
				return (string) (int) $value;
			}
			return "'" . str_replace( "'", "''", (string) $value ) . "'";
		}, $query );
	}

	private function run( $sql ) {
		$this->last_error = '';
		if ( '' !== $this->fail_on && false !== strpos( $sql, $this->fail_on ) ) {
			$this->last_error = 'simulated failure';
			return false;
		}
		// Portable LIKE: declare the backslash escape, as MySQL does by default.
		$sql = preg_replace_callback( '/LIKE\s+(\'(?:[^\']|\'\')*\')/', function ( $m ) {
			return 'LIKE ' . $m[1] . " ESCAPE '\\'";
		}, $sql );
		try {
			$statement = $this->pdo->query( $sql );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
		return $statement;
	}

	/** SHOW TABLES LIKE is answered from sqlite_master. */
	private function show_tables( $sql ) {
		if ( ! preg_match( "/^SHOW TABLES LIKE '((?:[^']|'')*)'$/", $sql, $m ) ) {
			return null;
		}
		$name = str_replace( array( "''", '\\_' ), array( "'", '_' ), $m[1] );
		$stmt = $this->pdo->prepare( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?" );
		$stmt->execute( array( $name ) );
		$found = $stmt->fetchColumn();
		return false === $found ? null : $found;
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'SHOW TABLES' ) ) {
			return $this->show_tables( $sql );
		}
		$statement = $this->run( $sql );
		if ( false === $statement ) {
			return null;
		}
		$value = $statement->fetchColumn();
		return false === $value ? null : $value;
	}

	public function get_col( $sql ) {
		$statement = $this->run( $sql );
		return false === $statement ? false : $statement->fetchAll( PDO::FETCH_COLUMN, 0 );
	}

	public function get_results( $sql, $output = OBJECT ) {
		$statement = $this->run( $sql );
		if ( false === $statement ) {
			return false;
		}
		return $statement->fetchAll( 'ARRAY_A' === $output ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ );
	}

	public function get_row( $sql, $output = OBJECT ) {
		$rows = $this->get_results( $sql, $output );
		return is_array( $rows ) && isset( $rows[0] ) ? $rows[0] : null;
	}

	/** Runs a DML statement and returns the affected row count, or false. */
	public function query( $sql ) {
		$statement = $this->run( $sql );
		return false === $statement ? false : $statement->rowCount();
	}

	public function delete( $table, $where, $formats = array() ) {
		$clauses = array();
		$values  = array();
		foreach ( $where as $column => $value ) {
			$clauses[] = '`' . $column . '` = ?';
			$values[]  = $value;
		}
		$this->last_error = '';
		try {
			$statement = $this->pdo->prepare( 'DELETE FROM `' . $table . '` WHERE ' . implode( ' AND ', $clauses ) );
			$statement->execute( $values );
			return $statement->rowCount();
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/** Test helper: raw SQL with no placeholders. */
	public function exec( $sql ) {
		$this->pdo->exec( $sql );
	}
}

$GLOBALS['wpdb'] = new TZFD_Test_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

$tzfd_root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/';
require_once $tzfd_root . 'class-tzfd-tables.php';
require_once $tzfd_root . 'class-tzfd-stored-data.php';
require_once $tzfd_root . 'class-tzfd-contact-rules.php';
require_once $tzfd_root . 'class-tzfd-privacy-exporter.php';

// ---- Schema and seed data ---------------------------------------------------------------------------------------

const TZFD_TEST_SCHEMA = array(
	'wp_tz_bookings'                  => 'CREATE TABLE wp_tz_bookings (id INTEGER PRIMARY KEY, company_id INTEGER, formid INTEGER, time TEXT, ipaddr TEXT, notifyto TEXT, posted_data TEXT, data TEXT, whoadded TEXT)',
	'wp_tz_forms'                     => 'CREATE TABLE wp_tz_forms (id INTEGER PRIMARY KEY, form_name TEXT, form_structure TEXT)',
	'wp_tz_enquiries'                 => 'CREATE TABLE wp_tz_enquiries (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT, email TEXT, phone TEXT, preferred_contact TEXT, enquiry TEXT, source TEXT, received_utc TEXT, status TEXT, created TEXT)',
	'wp_tz_enquiry_triage'            => 'CREATE TABLE wp_tz_enquiry_triage (id INTEGER PRIMARY KEY, company_id INTEGER, enquiry_id INTEGER, primary_intent TEXT, intents TEXT, urgency TEXT, spam_state TEXT, ip_hash TEXT, route TEXT, quote_draft TEXT, created TEXT)',
	'wp_tzfd_local_customers'         => 'CREATE TABLE wp_tzfd_local_customers (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT, email TEXT, phone TEXT, address TEXT, notes TEXT, created_at TEXT)',
	'wp_tzfd_local_services'          => 'CREATE TABLE wp_tzfd_local_services (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT, description TEXT, price REAL, duration_minutes INTEGER, gst_applies INTEGER, archived INTEGER)',
	'wp_tz_contact_actions'           => 'CREATE TABLE wp_tz_contact_actions (id INTEGER PRIMARY KEY, company_id INTEGER, dedupe_key TEXT, kind TEXT, source TEXT, phone TEXT, match_status TEXT, customer_id INTEGER, reason TEXT, state TEXT, created_at TEXT, updated_at TEXT)',
	'wp_tz_sms_conversations'         => 'CREATE TABLE wp_tz_sms_conversations (id INTEGER PRIMARY KEY, company_id INTEGER, conversation_key TEXT, turns INTEGER, window_started TEXT, opted_out INTEGER, updated_at TEXT)',
	'wp_tz_booking_actions'           => 'CREATE TABLE wp_tz_booking_actions (id INTEGER PRIMARY KEY, company_id INTEGER, event_hash TEXT, booking_id INTEGER, action_type TEXT, recipient_id INTEGER, state TEXT, available_at TEXT)',
	'wp_tzfd_customer_event_outbox'   => 'CREATE TABLE wp_tzfd_customer_event_outbox (id INTEGER PRIMARY KEY, company_id INTEGER, booking_id INTEGER, form_id INTEGER, event_type TEXT, identity_hash TEXT, payload TEXT, state TEXT, available_at TEXT)',
	'wp_options'                      => 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT)',
);

const TZFD_TEST_JANE = 'jane@example.test';
const TZFD_TEST_BOB  = 'bob@example.test';
const TZFD_TEST_JANE_PHONE = '+61412345678';
const TZFD_TEST_BOB_PHONE  = '+61400111222';
const TZFD_TEST_STRANGER_PHONE = '+61499999999';

/** Drop and recreate every table, then insert one record set for Jane and one for Bob. */
function tzfd_privacy_seed( TZFD_Test_Wpdb $db, $extra_jane_bookings = 0 ) {
	foreach ( array_keys( TZFD_TEST_SCHEMA ) as $table ) {
		$db->exec( 'DROP TABLE IF EXISTS ' . $table );
		$db->exec( TZFD_TEST_SCHEMA[ $table ] );
	}
	$form_structure = json_encode( array( array(
		array( 'name' => 'name', 'title' => 'Full name', 'ftype' => 'ftext' ),
		array( 'name' => 'notes', 'title' => 'Job notes', 'ftype' => 'ftextarea' ),
		array( 'name' => 'captcha', 'title' => 'Captcha', 'ftype' => 'fcaptcha' ),
	) ) );
	$db->exec( "INSERT INTO wp_tz_forms VALUES (1, 'Booking form', '" . str_replace( "'", "''", $form_structure ) . "')" );

	// Bookings: one for Jane (id 1), one for Bob (id 2).
	$posted = function ( $name, $notes ) {
		return serialize( array( 'name' => $name, 'notes' => $notes, 'captcha' => 'x' ) );
	};
	tzfd_privacy_insert( $db, 'wp_tz_bookings', array( 'id' => 1, 'company_id' => 1, 'formid' => 1, 'time' => '2026-01-01 10:00:00', 'ipaddr' => '203.0.113.7', 'notifyto' => TZFD_TEST_JANE, 'posted_data' => $posted( 'Jane Smith', 'Back door key' ), 'data' => 'JANE', 'whoadded' => '1' ) );
	tzfd_privacy_insert( $db, 'wp_tz_bookings', array( 'id' => 2, 'company_id' => 1, 'formid' => 1, 'time' => '2026-01-02 10:00:00', 'ipaddr' => '203.0.113.8', 'notifyto' => TZFD_TEST_BOB, 'posted_data' => $posted( 'Bob Jones', 'Gate code' ), 'data' => 'BOB', 'whoadded' => '1' ) );

	// Enquiries: Jane (upper-case address on purpose) and Bob.
	tzfd_privacy_insert( $db, 'wp_tz_enquiries', array( 'id' => 1, 'company_id' => 1, 'name' => 'Jane Smith', 'email' => 'JANE@example.test', 'phone' => '0412 345 678', 'preferred_contact' => 'phone', 'enquiry' => 'Leaking tap', 'source' => 'site', 'received_utc' => '2026-01-01 09:00:00', 'status' => 'new', 'created' => '2026-01-01 09:00:00' ) );
	tzfd_privacy_insert( $db, 'wp_tz_enquiries', array( 'id' => 2, 'company_id' => 1, 'name' => 'Bob Jones', 'email' => TZFD_TEST_BOB, 'phone' => '0400 111 222', 'preferred_contact' => 'email', 'enquiry' => 'Blocked drain', 'source' => 'site', 'received_utc' => '2026-01-02 09:00:00', 'status' => 'new', 'created' => '2026-01-02 09:00:00' ) );

	// Triage: one note per enquiry.
	tzfd_privacy_insert( $db, 'wp_tz_enquiry_triage', array( 'id' => 1, 'company_id' => 1, 'enquiry_id' => 1, 'primary_intent' => 'book', 'intents' => 'book', 'urgency' => 'high', 'spam_state' => 'none', 'ip_hash' => str_repeat( 'a', 64 ), 'route' => 'staff', 'quote_draft' => 'Quote for Jane', 'created' => '2026-01-01 09:00:01' ) );
	tzfd_privacy_insert( $db, 'wp_tz_enquiry_triage', array( 'id' => 2, 'company_id' => 1, 'enquiry_id' => 2, 'primary_intent' => 'quote', 'intents' => 'quote', 'urgency' => 'normal', 'spam_state' => 'none', 'ip_hash' => str_repeat( 'b', 64 ), 'route' => 'staff', 'quote_draft' => 'Quote for Bob', 'created' => '2026-01-02 09:00:01' ) );

	// Local customers.
	tzfd_privacy_insert( $db, 'wp_tzfd_local_customers', array( 'id' => 1, 'company_id' => 1, 'name' => 'Jane Smith', 'email' => TZFD_TEST_JANE, 'phone' => '0412345678', 'address' => '1 Test St', 'notes' => 'Prefers mornings', 'created_at' => '2026-01-01 08:00:00' ) );
	tzfd_privacy_insert( $db, 'wp_tzfd_local_customers', array( 'id' => 2, 'company_id' => 1, 'name' => 'Bob Jones', 'email' => TZFD_TEST_BOB, 'phone' => '0400111222', 'address' => '2 Test St', 'notes' => '', 'created_at' => '2026-01-02 08:00:00' ) );

	// Local services: business catalogue, no person.
	tzfd_privacy_insert( $db, 'wp_tzfd_local_services', array( 'id' => 1, 'company_id' => 1, 'name' => 'Tap repair', 'description' => 'Replace washer', 'price' => 120.0, 'duration_minutes' => 60, 'gst_applies' => 1, 'archived' => 0 ) );

	// Missed-call and reply records: Jane's number, a stranger's number, Bob's number.
	tzfd_privacy_insert( $db, 'wp_tz_contact_actions', array( 'id' => 1, 'company_id' => 1, 'dedupe_key' => str_repeat( '1', 64 ), 'kind' => 'callback', 'source' => 'missed_call', 'phone' => TZFD_TEST_JANE_PHONE, 'match_status' => 'none', 'reason' => 'missed', 'state' => 'open', 'created_at' => '2026-01-03 10:00:00', 'updated_at' => '2026-01-03 10:00:00' ) );
	tzfd_privacy_insert( $db, 'wp_tz_contact_actions', array( 'id' => 2, 'company_id' => 1, 'dedupe_key' => str_repeat( '2', 64 ), 'kind' => 'callback', 'source' => 'missed_call', 'phone' => TZFD_TEST_STRANGER_PHONE, 'match_status' => 'none', 'reason' => 'missed', 'state' => 'open', 'created_at' => '2026-01-03 11:00:00', 'updated_at' => '2026-01-03 11:00:00' ) );
	tzfd_privacy_insert( $db, 'wp_tz_contact_actions', array( 'id' => 3, 'company_id' => 1, 'dedupe_key' => str_repeat( '3', 64 ), 'kind' => 'callback', 'source' => 'missed_call', 'phone' => TZFD_TEST_BOB_PHONE, 'match_status' => 'none', 'reason' => 'missed', 'state' => 'open', 'created_at' => '2026-01-03 12:00:00', 'updated_at' => '2026-01-03 12:00:00' ) );

	// Text conversations, keyed by hash as the plugin stores them.
	tzfd_privacy_insert( $db, 'wp_tz_sms_conversations', array( 'id' => 1, 'company_id' => 1, 'conversation_key' => TZFD_Contact_Rules::conversation_key( 1, TZFD_TEST_JANE_PHONE ), 'turns' => 2, 'window_started' => '2026-01-03 10:00:00', 'opted_out' => 0, 'updated_at' => '2026-01-03 10:05:00' ) );
	tzfd_privacy_insert( $db, 'wp_tz_sms_conversations', array( 'id' => 2, 'company_id' => 1, 'conversation_key' => TZFD_Contact_Rules::conversation_key( 1, TZFD_TEST_STRANGER_PHONE ), 'turns' => 1, 'window_started' => '2026-01-03 11:00:00', 'opted_out' => 0, 'updated_at' => '2026-01-03 11:05:00' ) );
	tzfd_privacy_insert( $db, 'wp_tz_sms_conversations', array( 'id' => 3, 'company_id' => 1, 'conversation_key' => TZFD_Contact_Rules::conversation_key( 1, TZFD_TEST_BOB_PHONE ), 'turns' => 0, 'window_started' => '2026-01-03 12:00:00', 'opted_out' => 1, 'updated_at' => '2026-01-03 12:05:00' ) );

	// Reminders: one per booking.
	tzfd_privacy_insert( $db, 'wp_tz_booking_actions', array( 'id' => 1, 'company_id' => 1, 'event_hash' => str_repeat( 'r', 63 ) . '1', 'booking_id' => 1, 'action_type' => 'reminder', 'recipient_id' => 1, 'state' => 'pending', 'available_at' => '2026-01-10 09:00:00' ) );
	tzfd_privacy_insert( $db, 'wp_tz_booking_actions', array( 'id' => 2, 'company_id' => 1, 'event_hash' => str_repeat( 'r', 63 ) . '2', 'booking_id' => 2, 'action_type' => 'reminder', 'recipient_id' => 2, 'state' => 'pending', 'available_at' => '2026-01-11 09:00:00' ) );

	// Customer emails: one for Jane's booking, one for Bob's, and one addressed to Jane for a booking that is no longer in the table.
	tzfd_privacy_insert( $db, 'wp_tzfd_customer_event_outbox', array( 'id' => 1, 'company_id' => 1, 'booking_id' => 1, 'form_id' => 1, 'event_type' => 'rescheduled', 'identity_hash' => str_repeat( 'e', 63 ) . '1', 'payload' => json_encode( array( 'to' => TZFD_TEST_JANE, 'subject' => 'New time', 'body' => 'Your visit moved to Monday.', 'format' => 'text' ) ), 'state' => 'pending', 'available_at' => '2026-01-05 09:00:00' ) );
	tzfd_privacy_insert( $db, 'wp_tzfd_customer_event_outbox', array( 'id' => 2, 'company_id' => 1, 'booking_id' => 2, 'form_id' => 1, 'event_type' => 'cancelled', 'identity_hash' => str_repeat( 'e', 63 ) . '2', 'payload' => json_encode( array( 'to' => TZFD_TEST_BOB, 'subject' => 'Cancelled', 'body' => 'Bob visit cancelled.', 'format' => 'text' ) ), 'state' => 'pending', 'available_at' => '2026-01-06 09:00:00' ) );
	tzfd_privacy_insert( $db, 'wp_tzfd_customer_event_outbox', array( 'id' => 3, 'company_id' => 1, 'booking_id' => 99, 'form_id' => 1, 'event_type' => 'reschedule_email', 'identity_hash' => str_repeat( 'e', 63 ) . '3', 'payload' => json_encode( array( 'to' => TZFD_TEST_JANE, 'subject' => 'Follow up', 'body' => 'Second copy.', 'format' => 'text' ) ), 'state' => 'sent', 'available_at' => '2026-01-07 09:00:00' ) );

	// Client-portal sign-in session for each person.
	tzfd_privacy_insert( $db, 'wp_options', array( 'option_name' => '_transient_tz_fd_portal_session_janehash', 'option_value' => serialize( array( 'email' => TZFD_TEST_JANE ) ) ) );
	tzfd_privacy_insert( $db, 'wp_options', array( 'option_name' => '_transient_tz_fd_portal_session_bobhash', 'option_value' => serialize( array( 'email' => TZFD_TEST_BOB ) ) ) );

	// Extra bookings for batching tests.
	for ( $i = 0; $i < $extra_jane_bookings; $i++ ) {
		$id = 100 + $i;
		tzfd_privacy_insert( $db, 'wp_tz_bookings', array( 'id' => $id, 'company_id' => 1, 'formid' => 1, 'time' => '2026-02-01 10:00:00', 'ipaddr' => '', 'notifyto' => TZFD_TEST_JANE, 'posted_data' => $posted( 'Jane Smith', 'Batch ' . $i ), 'data' => 'JANE', 'whoadded' => '1' ) );
		tzfd_privacy_insert( $db, 'wp_tz_booking_actions', array( 'id' => 1000 + $i, 'company_id' => 1, 'event_hash' => str_repeat( 'b', 40 ) . sprintf( '%024d', $i ), 'booking_id' => $id, 'action_type' => 'reminder', 'recipient_id' => 1, 'state' => 'pending', 'available_at' => '2026-02-02 09:00:00' ) );
	}
}

function tzfd_privacy_insert( TZFD_Test_Wpdb $db, $table, array $row ) {
	$columns = array_keys( $row );
	$values  = array();
	foreach ( $row as $value ) {
		// Only real numbers are unquoted; numeric-looking strings such as phone numbers keep their text.
		$values[] = null === $value ? 'NULL' : ( is_int( $value ) || is_float( $value ) ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'" );
	}
	$db->exec( 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')' );
}

function tzfd_privacy_count( TZFD_Test_Wpdb $db, $table, $where = '1 = 1' ) {
	return (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where );
}

/** Export callback for one registered exporter key, first page. */
function tzfd_privacy_export( $key, $email, $page = 1 ) {
	$exporters = TZFD_Privacy_Exporter::register_exporter( array() );
	return call_user_func( $exporters[ 'tzfd-' . $key ]['callback'], $email, $page );
}

function tzfd_privacy_erase( $email, $page = 1 ) {
	$erasers = TZFD_Privacy_Exporter::register_eraser( array() );
	return call_user_func( $erasers['tzfd-bookings']['callback'], $email, $page );
}

/** Item count of one exporter group, across all returned items. */
function tzfd_privacy_items( $result ) {
	return is_array( $result ) && isset( $result['data'] ) ? count( $result['data'] ) : 0;
}

function tzfd_privacy_text( $result ) {
	return json_encode( $result );
}

// ---- Tests -------------------------------------------------------------------------------------------------------

// 1. Registry: every table that holds personal data has an exporter, and the eraser is registered once.
$exporters = TZFD_Privacy_Exporter::register_exporter( array() );
$expected_keys = array(
	'tzfd-bookings', 'tzfd-enquiry_triage', 'tzfd-enquiries', 'tzfd-local_customers',
	'tzfd-missed_call_actions', 'tzfd-sms_conversations', 'tzfd-reminder_outbox', 'tzfd-customer_event_outbox',
);
foreach ( $expected_keys as $key ) {
	tzfd_privacy_check( 'registry: exporter registered for ' . $key, isset( $exporters[ $key ]['callback'] ) );
}
tzfd_privacy_check( 'registry: exactly the expected exporters, nothing extra', array() === array_diff( array_keys( $exporters ), $expected_keys ) );
$erasers = TZFD_Privacy_Exporter::register_eraser( array() );
tzfd_privacy_check( 'registry: eraser registered', isset( $erasers['tzfd-bookings']['callback'] ) );

// 2. Local services hold no personal data: check the schema, and check that it is documented as such.
$no_personal = TZFD_Privacy_Exporter::no_personal_data_tables();
tzfd_privacy_check( 'local services: documented as holding no personal data', isset( $no_personal['tzfd_local_services'] ) );
tzfd_privacy_seed( $wpdb );
$service_columns = array_map( function ( $row ) { return strtolower( $row['name'] ); }, $wpdb->get_results( 'PRAGMA table_info(wp_tzfd_local_services)', ARRAY_A ) );
$contact_like   = array_intersect( $service_columns, array( 'email', 'phone', 'address', 'notes', 'user_email', 'notifyto' ) );
tzfd_privacy_check( 'local services: no contact columns (email, phone, address, notes)', array() === $contact_like, implode( ',', $contact_like ) );
tzfd_privacy_check( 'local services: has no exporter', ! isset( $exporters['tzfd-local_services'] ) );

// 3. Export: Jane's records appear in every table, and nothing belonging to Bob does.
tzfd_privacy_seed( $wpdb );
foreach ( array( 'enquiries', 'enquiry_triage', 'local_customers', 'missed_call_actions', 'sms_conversations', 'reminder_outbox', 'customer_event_outbox', 'bookings' ) as $key ) {
	$result = tzfd_privacy_export( $key, TZFD_TEST_JANE, 1 );
	$text   = tzfd_privacy_text( $result );
	tzfd_privacy_check( 'export ' . $key . ': returns a result for Jane', is_array( $result ) && isset( $result['data'] ), json_encode( $result ) );
	tzfd_privacy_check( 'export ' . $key . ': no Bob records', false === strpos( $text, 'Bob' ) && false === strpos( $text, 'bob@example' ) && false === strpos( $text, '400111222' ) && false === strpos( $text, '0400 111 222' ) && false === strpos( $text, 'Gate code' ) && false === strpos( $text, 'Blocked drain' ) );
}
$enq = tzfd_privacy_export( 'enquiries', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export enquiries: one record for Jane, matched case-insensitively', 1 === tzfd_privacy_items( $enq ) && false !== strpos( tzfd_privacy_text( $enq ), 'Leaking tap' ) );
$triage = tzfd_privacy_export( 'enquiry_triage', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export triage: only the triage note for Jane\'s enquiry', 1 === tzfd_privacy_items( $triage ) && false !== strpos( tzfd_privacy_text( $triage ), 'Quote for Jane' ) && false === strpos( tzfd_privacy_text( $triage ), 'Quote for Bob' ) );
$local = tzfd_privacy_export( 'local_customers', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export local customers: Jane\'s record and notes', 1 === tzfd_privacy_items( $local ) && false !== strpos( tzfd_privacy_text( $local ), 'Prefers mornings' ) );
$calls = tzfd_privacy_export( 'missed_call_actions', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export missed-call records: matched through Jane\'s phone, not the stranger\'s', 1 === tzfd_privacy_items( $calls ) && false !== strpos( tzfd_privacy_text( $calls ), TZFD_TEST_JANE_PHONE ) && false === strpos( tzfd_privacy_text( $calls ), TZFD_TEST_STRANGER_PHONE ) );
$sms = tzfd_privacy_export( 'sms_conversations', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export text conversations: Jane\'s conversation only', 1 === tzfd_privacy_items( $sms ) && false !== strpos( tzfd_privacy_text( $sms ), 'Automated replies sent' ) );
$reminders = tzfd_privacy_export( 'reminder_outbox', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export reminders: the reminder for Jane\'s booking only', 1 === tzfd_privacy_items( $reminders ) );
$emails = tzfd_privacy_export( 'customer_event_outbox', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export customer emails: booking-linked and address-matched rows, with recipient and message', 2 === tzfd_privacy_items( $emails ) && false !== strpos( tzfd_privacy_text( $emails ), 'Your visit moved to Monday.' ) && false !== strpos( tzfd_privacy_text( $emails ), 'Second copy.' ) );
$bookings = tzfd_privacy_export( 'bookings', TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'export bookings: Jane\'s booking with its form field and IP address', 1 === tzfd_privacy_items( $bookings ) && false !== strpos( tzfd_privacy_text( $bookings ), 'Back door key' ) && false !== strpos( tzfd_privacy_text( $bookings ), '203.0.113.7' ) );
tzfd_privacy_check( 'export bookings: captcha field is not exported', false === strpos( tzfd_privacy_text( $bookings ), 'Captcha' ) );

// 4. Export: invalid input and unknown keys match nothing.
tzfd_privacy_check( 'export: invalid email returns no data', array() === tzfd_privacy_export( 'enquiries', 'not-an-email', 1 )['data'] );
tzfd_privacy_check( 'export: empty email returns no data', array() === tzfd_privacy_export( 'sms_conversations', '', 1 )['data'] );

// 5. Export paging: a long list is split into pages of 50.
tzfd_privacy_seed( $wpdb );
for ( $i = 0; $i < 51; $i++ ) {
	tzfd_privacy_insert( $wpdb, 'wp_tz_enquiries', array( 'id' => 500 + $i, 'company_id' => 1, 'name' => 'Jane ' . $i, 'email' => TZFD_TEST_JANE, 'phone' => '', 'preferred_contact' => '', 'enquiry' => 'Bulk ' . $i, 'source' => '', 'received_utc' => '2026-03-01 00:00:00', 'status' => 'new', 'created' => '2026-03-01 00:00:00' ) );
}
$page1 = tzfd_privacy_export( 'enquiries', TZFD_TEST_JANE, 1 );
$page2 = tzfd_privacy_export( 'enquiries', TZFD_TEST_JANE, 2 );
tzfd_privacy_check( 'export paging: first page holds 50 records and is not done', 50 === tzfd_privacy_items( $page1 ) && false === $page1['done'] );
tzfd_privacy_check( 'export paging: second page holds the remaining 2 records and is done', 2 === tzfd_privacy_items( $page2 ) && true === $page2['done'] );

// 6. Erase: removes Jane's rows from every table, keeps Bob's and the unrelated number, and leaves local services alone.
tzfd_privacy_seed( $wpdb );
$services_before = tzfd_privacy_count( $wpdb, 'wp_tzfd_local_services' );
$erased = tzfd_privacy_erase( TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'erase: finishes in one pass for a small record set', true === $erased['done'] );
tzfd_privacy_check( 'erase: reports removed items', true === $erased['items_removed'] && false === $erased['items_retained'], json_encode( $erased ) );
tzfd_privacy_check( 'erase bookings: Jane\'s booking gone, Bob\'s kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tz_bookings', "notifyto = '" . TZFD_TEST_JANE . "'" ) && 1 === tzfd_privacy_count( $wpdb, 'wp_tz_bookings', "notifyto = '" . TZFD_TEST_BOB . "'" ) );
tzfd_privacy_check( 'erase enquiries: Jane\'s enquiry gone (any case), Bob\'s kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tz_enquiries', "LOWER(email) = '" . TZFD_TEST_JANE . "'" ) && 1 === tzfd_privacy_count( $wpdb, 'wp_tz_enquiries' ) );
tzfd_privacy_check( 'erase triage: Jane\'s note gone with its enquiry, Bob\'s kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tz_enquiry_triage', 'enquiry_id = 1' ) && 1 === tzfd_privacy_count( $wpdb, 'wp_tz_enquiry_triage' ) );
tzfd_privacy_check( 'erase local customers: Jane\'s record gone, Bob\'s kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tzfd_local_customers', "email = '" . TZFD_TEST_JANE . "'" ) && 1 === tzfd_privacy_count( $wpdb, 'wp_tzfd_local_customers' ) );
tzfd_privacy_check( 'erase missed-call records: Jane\'s number gone, stranger and Bob kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tz_contact_actions', "phone = '" . TZFD_TEST_JANE_PHONE . "'" ) && 2 === tzfd_privacy_count( $wpdb, 'wp_tz_contact_actions' ) );
tzfd_privacy_check( 'erase text conversations: Jane\'s key gone, stranger and Bob kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tz_sms_conversations', "conversation_key = '" . TZFD_Contact_Rules::conversation_key( 1, TZFD_TEST_JANE_PHONE ) . "'" ) && 2 === tzfd_privacy_count( $wpdb, 'wp_tz_sms_conversations' ) );
tzfd_privacy_check( 'erase reminders: Jane\'s reminder gone, Bob\'s kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tz_booking_actions', 'booking_id = 1' ) && 1 === tzfd_privacy_count( $wpdb, 'wp_tz_booking_actions' ) );
tzfd_privacy_check( 'erase customer emails: Jane\'s two emails gone, Bob\'s kept', 0 === tzfd_privacy_count( $wpdb, 'wp_tzfd_customer_event_outbox', "payload LIKE '%" . TZFD_TEST_JANE . "%'" ) && 1 === tzfd_privacy_count( $wpdb, 'wp_tzfd_customer_event_outbox' ) );
tzfd_privacy_check( 'erase portal sessions: Jane\'s session gone, Bob\'s kept', 0 === tzfd_privacy_count( $wpdb, 'wp_options', "option_name = '_transient_tz_fd_portal_session_janehash'" ) && 1 === tzfd_privacy_count( $wpdb, 'wp_options', "option_name = '_transient_tz_fd_portal_session_bobhash'" ) );
tzfd_privacy_check( 'erase local services: catalogue untouched', $services_before === tzfd_privacy_count( $wpdb, 'wp_tzfd_local_services' ) );
tzfd_privacy_check( 'erase: nothing of Jane is left to export', array() === tzfd_privacy_export( 'enquiries', TZFD_TEST_JANE, 1 )['data'] && array() === tzfd_privacy_export( 'customer_event_outbox', TZFD_TEST_JANE, 1 )['data'] && array() === tzfd_privacy_export( 'missed_call_actions', TZFD_TEST_JANE, 1 )['data'] );

// 7. Erase: invalid input changes nothing.
tzfd_privacy_seed( $wpdb );
$rows_before = tzfd_privacy_count( $wpdb, 'wp_tz_enquiries' ) + tzfd_privacy_count( $wpdb, 'wp_tz_contact_actions' ) + tzfd_privacy_count( $wpdb, 'wp_tz_bookings' );
tzfd_privacy_erase( 'not-an-email', 1 );
tzfd_privacy_erase( '', 1 );
tzfd_privacy_check( 'erase: invalid email removes nothing', $rows_before === tzfd_privacy_count( $wpdb, 'wp_tz_enquiries' ) + tzfd_privacy_count( $wpdb, 'wp_tz_contact_actions' ) + tzfd_privacy_count( $wpdb, 'wp_tz_bookings' ) );

// 8. Erase: bookings go in batches, and each batch takes its own reminders and customer emails with it.
tzfd_privacy_seed( $wpdb, 100 ); // Jane then has 101 bookings: one batch of 100 and one left over.
$first = tzfd_privacy_erase( TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'erase batches: first pass is not done when more than one batch of bookings exists', false === $first['done'] );
tzfd_privacy_check( 'erase batches: first pass removes 100 bookings', 1 === tzfd_privacy_count( $wpdb, 'wp_tz_bookings', "notifyto = '" . TZFD_TEST_JANE . "'" ) );
tzfd_privacy_check( 'erase batches: reminders of the removed bookings are gone, the last one stays', 1 === tzfd_privacy_count( $wpdb, 'wp_tz_booking_actions', 'booking_id >= 100' ) );
tzfd_privacy_check( 'erase batches: other tables untouched until bookings are done', 1 === tzfd_privacy_count( $wpdb, 'wp_tz_enquiries', "LOWER(email) = '" . TZFD_TEST_JANE . "'" ) );
$second = tzfd_privacy_erase( TZFD_TEST_JANE, 2 );
tzfd_privacy_check( 'erase batches: second pass is done and clears the rest', true === $second['done'] && 0 === tzfd_privacy_count( $wpdb, 'wp_tz_bookings', "notifyto = '" . TZFD_TEST_JANE . "'" ) && 0 === tzfd_privacy_count( $wpdb, 'wp_tz_enquiries', "LOWER(email) = '" . TZFD_TEST_JANE . "'" ) );

// 9. Erase: a table that is not installed is skipped, not treated as an error.
tzfd_privacy_seed( $wpdb );
$wpdb->exec( 'DROP TABLE wp_tz_sms_conversations' );
$skipped = tzfd_privacy_erase( TZFD_TEST_JANE, 1 );
tzfd_privacy_check( 'erase: missing table is skipped and the pass completes', true === $skipped['done'] && false === $skipped['items_retained'] );
tzfd_privacy_check( 'erase: the other tables are still cleared', 0 === tzfd_privacy_count( $wpdb, 'wp_tz_enquiries', "LOWER(email) = '" . TZFD_TEST_JANE . "'" ) );

// 10. Erase: a failed delete is reported as retained, with a retry message, and the pass is done (WordPress stops).
tzfd_privacy_seed( $wpdb );
$wpdb->fail_on = 'DELETE FROM `wp_tz_contact_actions`';
$failed = tzfd_privacy_erase( TZFD_TEST_JANE, 1 );
$wpdb->fail_on = '';
tzfd_privacy_check( 'erase failure: reports items retained and asks for a retry', true === $failed['items_retained'] && ! empty( $failed['messages'] ) );
tzfd_privacy_check( 'erase failure: rows that could not be deleted are still there', 3 === tzfd_privacy_count( $wpdb, 'wp_tz_contact_actions' ) );

// 11. Phone-matched records are found only through the email's own enquiry or customer phone. A stranger's number is never matched.
tzfd_privacy_seed( $wpdb );
tzfd_privacy_check( 'identity: a stranger with no record for the address does not match', array() === tzfd_privacy_export( 'missed_call_actions', 'nobody@example.test', 1 )['data'] );

// Legacy hosted-AI chats have their own exporter and eraser, tested in tests/hosted-ai-legacy/legacy-ai-tests.php.

$results = $GLOBALS['tzfd_privacy_results'];
echo "\n" . $results['pass'] . ' passed, ' . $results['fail'] . " failed\n";
exit( $results['fail'] > 0 ? 1 : 0 );
