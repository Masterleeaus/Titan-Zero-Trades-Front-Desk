<?php
/**
 * Regression tests for the hosted AI connector stub (issue #2) and the privacy exporter and eraser for historic
 * booking assistant chats (issue #17, owner decision for #360: the exporter and eraser stay; the legacy AI screen,
 * backup-gated deletion and restore are removed).
 * Run: php tests/hosted-ai-legacy/legacy-ai-tests.php   (no WordPress install needed; WordPress functions are stubbed).
 */

// ---- Minimal WordPress stubs -------------------------------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['OPTS']    = array();
$GLOBALS['HOOKS']   = array();
$GLOBALS['FILTERS'] = array();

class WP_Error {
    public $code; public $message;
    public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo $s; }
function _n( $single, $plural, $n, $d = '' ) { return 1 === (int) $n ? $single : $plural; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_email( $e ) { return is_string( $e ) && 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $e ); }
function add_action( $h, $cb, $p = 10 ) { $GLOBALS['HOOKS'][] = $h; }
function add_filter( $h, $cb, $p = 10 ) { $GLOBALS['HOOKS'][] = $h; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function apply_filters( $tag, $value, ...$args ) {
    return isset( $GLOBALS['FILTERS'][ $tag ] ) ? $GLOBALS['FILTERS'][ $tag ]( $value, ...$args ) : $value;
}

// ---- Fake $wpdb answering the exact SQL shapes the privacy class issues -----------------------------------------
class FakeWpdb {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $last_error = '';
    public $table = 'wp_tz_ai_conversations';
    public $columns = array( 'id', 'user_email', 'messages', 'draft', 'created' );
    public $rows = array();
    public $exists = true;

    public function prepare( $sql, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%[sdi]/', function ( $m ) use ( &$args, &$i ) {
            $v = $args[ $i++ ];
            // Match WordPress $wpdb->prepare() (6.1+): %d is an integer, %i is a backtick-quoted identifier, %s a quoted string.
            if ( '%d' === $m[0] ) { return (string) (int) $v; }
            if ( '%i' === $m[0] ) { return '`' . str_replace( '`', '``', (string) $v ) . '`'; }
            return "'" . addslashes( (string) $v ) . "'";
        }, $sql );
    }
    public function get_var( $sql ) {
        if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/", $sql, $m ) ) {
            return $this->exists && $m[1] === $this->table ? $this->table : null;
        }
        if ( preg_match( "/LOWER\(`user_email`\) = '([^']*)'/", $sql, $m ) ) {
            $n = 0; foreach ( $this->rows as $r ) { if ( strtolower( $r['user_email'] ) === $m[1] ) { ++$n; } }
            return $n;
        }
        throw new RuntimeException( 'Unhandled get_var: ' . $sql );
    }
    public function get_col( $sql ) {
        if ( false !== strpos( $sql, 'SHOW COLUMNS FROM' ) ) { return $this->columns; }
        throw new RuntimeException( 'Unhandled get_col: ' . $sql );
    }
    public function get_results( $sql, $fmt = null ) {
        if ( preg_match( "/LOWER\(`user_email`\) = '([^']*)'.* LIMIT (\d+) OFFSET (\d+)/", $sql, $m ) ) {
            $hits = array_values( array_filter( $this->rows, function ( $r ) use ( $m ) { return strtolower( $r['user_email'] ) === $m[1]; } ) );
            return array_slice( $hits, (int) $m[3], (int) $m[2] );
        }
        throw new RuntimeException( 'Unhandled get_results: ' . $sql );
    }
    public function query( $sql ) {
        if ( preg_match( "/DELETE FROM `" . preg_quote( $this->table, '/' ) . "` WHERE LOWER\(`user_email`\) = '([^']*)'/", $sql, $m ) ) {
            $before = count( $this->rows );
            $this->rows = array_values( array_filter( $this->rows, function ( $r ) use ( $m ) { return strtolower( $r['user_email'] ) !== $m[1]; } ) );
            return $before - count( $this->rows );
        }
        throw new RuntimeException( 'Unhandled query: ' . $sql );
    }
    public function insert( $table, $row ) {
        if ( $table !== $this->table ) { return false; }
        $this->rows[] = $row; return 1;
    }
}

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-hosted-ai-connector.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-legacy-privacy.php';

// ---- Tiny test runner ----------------------------------------------------------------------------------------------
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function check( $name, $ok, $detail = '' ) {
    if ( $ok ) { ++$GLOBALS['pass']; } else { ++$GLOBALS['fail']; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}
function reset_state() {
    global $wpdb;
    $GLOBALS['OPTS'] = array();
    $GLOBALS['FILTERS'] = array();
    $wpdb->exists = true;
    $wpdb->rows = array(
        array( 'id' => 1, 'user_email' => 'a@example.test', 'messages' => '[]', 'draft' => '{}', 'created' => '2026-01-01' ),
        array( 'id' => 2, 'user_email' => 'A@example.test', 'messages' => '[]', 'draft' => '{}', 'created' => '2026-01-02' ),
        array( 'id' => 3, 'user_email' => 'b@example.test', 'messages' => '[]', 'draft' => '{}', 'created' => '2026-01-03' ),
    );
}

// ---- 1. Hosted connector stub -------------------------------------------------------------------------------------
reset_state();
check( 'connector: unavailable by default', ! TZFD_Hosted_AI_Connector::is_available() );
$r = TZFD_Hosted_AI_Connector::request( 'assistant', array( 'text' => 'hi' ) );
check( 'connector: request refused without consent', is_wp_error( $r ) && 'tzfd_hosted_ai_no_consent' === $r->code );
$GLOBALS['OPTS']['tzfd_hosted_ai_consent'] = 'granted';
$r = TZFD_Hosted_AI_Connector::request( 'assistant', array() );
check( 'connector: consent alone is not a connection', is_wp_error( $r ) && 'tzfd_hosted_ai_not_connected' === $r->code );
$r = TZFD_Hosted_AI_Connector::request( 'rogue_capability', array() );
check( 'connector: unknown capability rejected', is_wp_error( $r ) && 'tzfd_hosted_ai_unknown_capability' === $r->code );
$GLOBALS['FILTERS']['tzfd_hosted_ai_connector'] = function ( $c ) {
    return new class() { public $calls = 0; public function send( $cap, $payload ) { return array( 'ok' => true, 'capability' => $cap ); } };
};
$r = TZFD_Hosted_AI_Connector::request( 'free_ai', array() );
check( 'connector: registered connector with consent gets the request', is_array( $r ) && true === $r['ok'] && 'free_ai' === $r['capability'] );
unset( $GLOBALS['OPTS']['tzfd_hosted_ai_consent'] );
check( 'connector: revoking consent blocks again', is_wp_error( TZFD_Hosted_AI_Connector::request( 'free_ai', array() ) ) );

// ---- 2. Privacy export for historic chats ----------------------------------------------------------------------
reset_state();
$p = TZFD_Legacy_Privacy::privacy_export( 'A@example.test', 1 );
check( 'privacy export: matches case-insensitively and returns both rows', 2 === count( $p['data'] ) && true === $p['done'] );
check( 'privacy export: rows are grouped under the legacy chat group', 'tzfd-legacy-ai-chats' === $p['data'][0]['group_id'] );
check( 'privacy export: invalid email returns nothing', array() === TZFD_Legacy_Privacy::privacy_export( 'not-an-email', 1 )['data'] );
check( 'privacy export: a non-integer page returns nothing', array() === TZFD_Legacy_Privacy::privacy_export( 'a@example.test', 'two' )['data'] );
check( 'privacy export: an address with no chats returns nothing', array() === TZFD_Legacy_Privacy::privacy_export( 'nobody@example.test', 1 )['data'] );

// ---- 3. Privacy erase: exact address only, no backup copy ------------------------------------------------------
reset_state();
$e = TZFD_Legacy_Privacy::privacy_erase( 'a@example.test', 1 );
check( 'privacy erase: deletes both chats for the address and keeps other people\'s chats', true === $e['items_removed'] && false === $e['items_retained'] && 1 === count( $wpdb->rows ) && 'b@example.test' === $wpdb->rows[0]['user_email'] );
check( 'privacy erase: no chats left for the address', 0 === count( TZFD_Legacy_Privacy::privacy_export( 'a@example.test', 1 )['data'] ) );
check( 'privacy erase: no backup or export is written', ! array_key_exists( 'tzfd_legacy_ai_backup_receipt', $GLOBALS['OPTS'] ) );
$e = TZFD_Legacy_Privacy::privacy_erase( 'nobody@example.test', 1 );
check( 'privacy erase: an address with no chats removes nothing', false === $e['items_removed'] && false === $e['items_retained'] );

// ---- 4. Outage: the chat table is missing ------------------------------------------------------------------------
reset_state();
$wpdb->exists = false;
check( 'outage: privacy export with no table returns no rows', array() === TZFD_Legacy_Privacy::privacy_export( 'a@example.test', 1 )['data'] );
$e = TZFD_Legacy_Privacy::privacy_erase( 'a@example.test', 1 );
check( 'outage: privacy erase with no table is a clean no-op', false === $e['items_removed'] && false === $e['items_retained'] && 3 === count( $wpdb->rows ) );
$wpdb->exists = true;

// ---- 5. Static guarantees: nothing deletes on load, no network, no credential fields ----------------------------
$files = array(
    dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-legacy-privacy.php',
    dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-hosted-ai-connector.php',
);
$src = implode( "\n", array_map( 'file_get_contents', $files ) );
check( 'static: no admin_init or init hook in the new files', false === strpos( $src, "'admin_init'" ) && false === strpos( $src, "'init'" ) );
check( 'static: no HTTP client calls in the new files', ! preg_match( '/wp_remote_|curl_|fsockopen|stream_context_create/', $src ) );
check( 'static: privacy class has no admin screen, option or restore code', ! preg_match( '/add_submenu_page|admin_post_|update_option|add_option|delete_option|tz_fd_ai_/', file_get_contents( $files[0] ) ) );
check( 'static: connector has no credential fields', false === strpos( file_get_contents( $files[1] ), 'api_key' ) );
reset_state();
TZFD_Legacy_Privacy::register();
check( 'static: register() adds only the privacy filters, no admin_init or init hook', ! in_array( 'admin_init', $GLOBALS['HOOKS'], true ) && ! in_array( 'init', $GLOBALS['HOOKS'], true ) && in_array( 'wp_privacy_personal_data_exporters', $GLOBALS['HOOKS'], true ) && in_array( 'wp_privacy_personal_data_erasers', $GLOBALS['HOOKS'], true ) );

echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
