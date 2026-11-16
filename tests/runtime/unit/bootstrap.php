<?php
/**
 * Minimal WordPress stand-ins for the pure-PHP unit tests in this folder.
 *
 * These stubs exist only so the plugin's logic classes can be loaded and called
 * without a WordPress install. They are NOT a substitute for the on-site checks
 * in ../runtime-tests.php: no SQL is interpreted, and the fake database below
 * only models the statements the tested classes issue.
 *
 * Not shipped in the plugin. Run through run-unit-tests.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/tzfd-unit-wp/' );
}

$GLOBALS['tzfd_options'] = array();
$GLOBALS['tzfd_user_caps'] = array();
$GLOBALS['tzfd_now'] = 1800000000;

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function apply_filters( $hook, $value ) { return $value; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_salt( $scheme = 'auth' ) { return str_repeat( 's', 64 ); }
function is_email( $email ) { return is_string( $email ) && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $email ) { return is_string( $email ) ? strtolower( trim( $email ) ) : ''; }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s', $GLOBALS['tzfd_now'] ); }
function delete_transient( $key ) { return true; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['tzfd_user_caps'][ $cap ] ); }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['tzfd_options'] ) ? $GLOBALS['tzfd_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['tzfd_options'][ $name ] = $value;
    return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
    if ( array_key_exists( $name, $GLOBALS['tzfd_options'] ) ) { return false; }
    $GLOBALS['tzfd_options'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['tzfd_options'][ $name ] );
    return true;
}

class WP_Error {
    public $code;
    public $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
}

/**
 * Fake wpdb. Models:
 *  - prepare(): substitutes %i (backtick identifier), %d, %s, %f.
 *  - START TRANSACTION / COMMIT / ROLLBACK over an in-memory table.
 *    Inserts and updates are staged until COMMIT, so a rollback leaves committed rows unchanged.
 *  - Any other statement is answered by the first matching handler registered with when().
 * Every executed statement is recorded in $queries.
 */
class TZFD_Fake_Wpdb {
    public $prefix = 'wp_';
    public $dbname = 'unit_test_db';
    public $last_error = '';
    public $insert_id = 0;
    /** @var string[] */
    public $queries = array();
    /** @var array<int, array{0:string,1:callable}> */
    private $handlers = array();
    /** @var array<string, array<int, array<string, mixed>>> committed rows by table */
    public $committed = array();
    private $staged = null;
    private $next_id = 1;
    /** Set to true to make COMMIT return false, simulating a lost acknowledgement. */
    public $fail_commit = false;
    /** Engine reported by information_schema lookups. */
    public $engine = 'InnoDB';

    public function when( $pattern, callable $handler ) {
        $this->handlers[] = array( $pattern, $handler );
    }

    public function prepare( $query, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback( '/%([idsf])/', function ( $m ) use ( &$i, $args ) {
            $value = $args[ $i++ ] ?? null;
            switch ( $m[1] ) {
                case 'i': return '`' . str_replace( '`', '', (string) $value ) . '`';
                case 'd': return (string) (int) $value;
                case 'f': return (string) (float) $value;
                default:  return "'" . addslashes( (string) $value ) . "'";
            }
        }, $query );
    }

    private function run( $sql ) {
        $this->queries[] = $sql;
        foreach ( $this->handlers as $handler ) {
            if ( preg_match( $handler[0], $sql ) ) { return call_user_func( $handler[1], $sql, $this ); }
        }
        return null;
    }

    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( 'START TRANSACTION' === $sql ) {
            $this->staged = $this->committed;
            return 0;
        }
        if ( 'COMMIT' === $sql ) {
            if ( $this->fail_commit ) { $this->staged = null; return false; }
            if ( null !== $this->staged ) { $this->committed = $this->staged; }
            $this->staged = null;
            return 0;
        }
        if ( 'ROLLBACK' === $sql ) {
            $this->staged = null;
            return 0;
        }
        return $this->run( $sql );
    }

    /** Table rows are read from the staged copy when a transaction is open. */
    private function table_view() {
        return null !== $this->staged ? $this->staged : $this->committed;
    }

    /** Writes go to the open transaction when there is one, otherwise straight to committed rows. */
    private function &writable() {
        if ( null !== $this->staged ) { return $this->staged; }
        return $this->committed;
    }

    public function insert( $table, $data ) {
        $name = str_replace( $this->prefix, '', $table );
        $id = $this->next_id++;
        $data['id'] = $id;
        $target = &$this->writable();
        $target[ $name ][] = $data;
        $this->insert_id = $id;
        return 1;
    }

    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $name = str_replace( $this->prefix, '', $table );
        $target = &$this->writable();
        $changed = 0;
        foreach ( $target[ $name ] ?? array() as $idx => $row ) {
            foreach ( $where as $k => $v ) {
                if ( ! isset( $row[ $k ] ) || (string) $row[ $k ] !== (string) $v ) { continue 2; }
            }
            $target[ $name ][ $idx ] = array_merge( $row, $data );
            $changed++;
        }
        return $changed;
    }

    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( preg_match( '/^SELECT ENGINE FROM information_schema/', $sql ) ) { return $this->engine; }
        $r = $this->run_quiet( $sql );
        return is_scalar( $r ) ? $r : null;
    }

    public function get_row( $sql ) {
        $this->queries[] = $sql;
        if ( preg_match( '/^SELECT id, posted_data FROM .*WHERE id = (\d+) AND company_id = (\d+)/s', $sql, $m ) ) {
            foreach ( $this->table_view()['tz_bookings'] ?? array() as $row ) {
                if ( (string) $row['id'] === $m[1] && (string) $row['company_id'] === $m[2] ) {
                    return (object) array( 'id' => $row['id'], 'posted_data' => $row['posted_data'] );
                }
            }
            return null;
        }
        $r = $this->run_quiet( $sql );
        return is_object( $r ) || is_array( $r ) ? $r : null;
    }

    public function get_results( $sql ) {
        $this->queries[] = $sql;
        $r = $this->run_quiet( $sql );
        return is_array( $r ) ? $r : null;
    }

    private function run_quiet( $sql ) {
        foreach ( $this->handlers as $handler ) {
            if ( preg_match( $handler[0], $sql ) ) { return call_user_func( $handler[1], $sql, $this ); }
        }
        return null;
    }

    /** Rows committed to a table, as the test sees them after the transaction ends. */
    public function rows( $table ) {
        return $this->committed[ $table ] ?? array();
    }

    public function seed( $table, array $rows ) {
        foreach ( $rows as $row ) {
            $this->committed[ $table ][] = $row;
            if ( isset( $row['id'] ) && $row['id'] >= $this->next_id ) { $this->next_id = $row['id'] + 1; }
        }
    }
}

// Plugin root: TZFD_PLUGIN_DIR if set, otherwise the plugin folder in this repository.
$tzfd_plugin_root = getenv( 'TZFD_PLUGIN_DIR' ) ?: dirname( __DIR__, 3 ) . '/titan-zero-trades-front-desk';
$GLOBALS['tzfd_plugin_inc'] = rtrim( $tzfd_plugin_root, '/' ) . '/includes/';

foreach ( array(
    'class-tzfd-lifecycle-rules.php',
    'class-tzfd-booking-policy.php',
    'class-tzfd-demand-capacity.php',
    'class-tzfd-stored-data.php',
    'class-tzfd-tables.php',
    'class-tzfd-capacity-lock.php',
    'class-tzfd-booking-capacity-reader.php',
    'class-tzfd-final-booking-write.php',
    'class-tzfd-transaction-storage.php',
    'class-tzfd-intake-mode.php',
    'class-tzfd-booking-create-transaction.php',
    'class-tzfd-resource-catalog.php',
    'class-tzfd-resource-availability.php',
    'class-tzfd-slot-holds.php',
    'class-tzfd-customer-cancellation.php',
    'class-tzfd-customer-rescheduling.php',
    'class-tzfd-migrations.php',
) as $file ) {
    require_once $GLOBALS['tzfd_plugin_inc'] . $file;
}
