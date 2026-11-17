<?php
/**
 * Regression tests for the hosted AI connector stub and the legacy AI data tool (issues #2 and #17).
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
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_email( $e ) { return is_string( $e ) && 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $e ); }
function current_user_can( $c ) { return true; }
function add_action( $h, $cb, $p = 10 ) { $GLOBALS['HOOKS'][] = $h; }
function add_filter( $h, $cb, $p = 10 ) { $GLOBALS['HOOKS'][] = $h; }
function add_submenu_page( ...$a ) { return true; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTS'][ $n ] = $v; return true; }
function add_option( $n, $v, $dep = '', $a = 'yes' ) { if ( array_key_exists( $n, $GLOBALS['OPTS'] ) ) { return false; } $GLOBALS['OPTS'][ $n ] = $v; return true; }
function delete_option( $n ) { if ( ! array_key_exists( $n, $GLOBALS['OPTS'] ) ) { return false; } unset( $GLOBALS['OPTS'][ $n ] ); return true; }
function apply_filters( $tag, $value, ...$args ) {
    return isset( $GLOBALS['FILTERS'][ $tag ] ) ? $GLOBALS['FILTERS'][ $tag ]( $value, ...$args ) : $value;
}

// ---- Fake $wpdb answering the exact SQL shapes the plugin issues ---------------------------------------------
class FakeWpdb {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $last_error = '';
    public $table = 'wp_tz_ai_conversations';
    public $columns = array( 'id', 'user_email', 'messages', 'draft', 'created' );
    public $rows = array();
    public $exists = true;

    public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
    public function prepare( $sql, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%[sdi]/', function ( $m ) use ( &$args, &$i ) {
            $v = $args[ $i++ ];
            return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
        }, $sql );
    }
    private function sql_value( $sql, $pattern ) {
        return preg_match( $pattern, $sql, $m ) ? $m : null;
    }
    public function get_var( $sql ) {
        if ( $m = $this->sql_value( $sql, "/SHOW TABLES LIKE '([^']*)'/" ) ) {
            return $this->exists && $m[1] === $this->table ? $this->table : null;
        }
        if ( $m = $this->sql_value( $sql, '/WHERE `id` = (\d+)/' ) ) {
            foreach ( $this->rows as $r ) { if ( (int) $r['id'] === (int) $m[1] ) { return 1; } }
            return 0;
        }
        if ( $m = $this->sql_value( $sql, "/LOWER\(`user_email`\) = '([^']*)'/" ) ) {
            $n = 0; foreach ( $this->rows as $r ) { if ( strtolower( $r['user_email'] ) === $m[1] ) { ++$n; } }
            return $n;
        }
        if ( false !== strpos( $sql, 'COALESCE(MAX' ) ) {
            $max = 0; foreach ( $this->rows as $r ) { $max = max( $max, (int) $r['id'] ); }
            return $max;
        }
        if ( false !== strpos( $sql, 'SELECT COUNT(*) FROM `' . $this->table . '`' ) ) { return count( $this->rows ); }
        throw new RuntimeException( 'Unhandled get_var: ' . $sql );
    }
    public function get_col( $sql ) {
        if ( false !== strpos( $sql, 'SELECT option_name FROM' ) ) {
            $names = array();
            foreach ( array_keys( $GLOBALS['OPTS'] ) as $n ) { if ( 0 === strpos( $n, 'tz_fd_ai_' ) ) { $names[] = $n; } }
            sort( $names );
            return $names;
        }
        if ( false !== strpos( $sql, 'SHOW COLUMNS FROM' ) ) { return $this->columns; }
        throw new RuntimeException( 'Unhandled get_col: ' . $sql );
    }
    public function get_results( $sql, $fmt = null ) {
        if ( preg_match( "/LOWER\(`user_email`\) = '([^']*)'.* LIMIT (\d+) OFFSET (\d+)/", $sql, $m ) ) {
            $hits = array_values( array_filter( $this->rows, function ( $r ) use ( $m ) { return strtolower( $r['user_email'] ) === $m[1]; } ) );
            return array_slice( $hits, (int) $m[3], (int) $m[2] );
        }
        if ( false !== strpos( $sql, 'SELECT * FROM `' . $this->table . '`' ) ) { return $this->rows; }
        throw new RuntimeException( 'Unhandled get_results: ' . $sql );
    }
    public function query( $sql ) {
        if ( preg_match( "/DELETE FROM `" . preg_quote( $this->table, '/' ) . "` WHERE LOWER\(`user_email`\) = '([^']*)'/", $sql, $m ) ) {
            $before = count( $this->rows );
            $this->rows = array_values( array_filter( $this->rows, function ( $r ) use ( $m ) { return strtolower( $r['user_email'] ) !== $m[1]; } ) );
            return $before - count( $this->rows );
        }
        if ( false !== strpos( $sql, 'DELETE FROM `' . $this->table . '`' ) ) {
            $n = count( $this->rows ); $this->rows = array(); return $n;
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
$wpdb->options = 'wp_options';

require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-hosted-ai-connector.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-legacy-ai-data.php';

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
    $GLOBALS['OPTS']['tz_fd_ai_api_key']  = 'sk-TEST-SECRET-9f3a';
    $GLOBALS['OPTS']['tz_fd_ai_model']    = 'model-x';
    $GLOBALS['OPTS']['tz_fd_ai_api_base'] = 'https://api.example.test/v1?key=QUERYSECRET';
    $GLOBALS['OPTS']['tz_fd_ai_services'] = 'Boiler repairs; Drain clearing';
    $GLOBALS['OPTS']['tz_fd_ai_rogue_token'] = 'tok-ROGUE-777';
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

// ---- 2. Classification ------------------------------------------------------------------------------------------
check( 'classify: api key is credential', 'credential' === TZFD_Legacy_AI_Data::classify( 'tz_fd_ai_api_key' ) );
check( 'classify: unknown secret-like name is credential', 'credential' === TZFD_Legacy_AI_Data::classify( 'tz_fd_ai_rogue_token' ) );
check( 'classify: model is provider', 'provider' === TZFD_Legacy_AI_Data::classify( 'tz_fd_ai_model' ) );
check( 'classify: services is configuration', 'configuration' === TZFD_Legacy_AI_Data::classify( 'tz_fd_ai_services' ) );
check( 'classify: migration marker kept as marker', 'marker' === TZFD_Legacy_AI_Data::classify( 'tz_fd_ai_connector_migration_20261015' ) );

// ---- 3. Audit shows presence and format, never values ----------------------------------------------------------
reset_state();
$audit = TZFD_Legacy_AI_Data::audit();
$json_audit = json_encode( $audit );
check( 'audit: no credential value appears', false === strpos( $json_audit, 'sk-TEST-SECRET-9f3a' ) );
check( 'audit: no rogue token value appears', false === strpos( $json_audit, 'tok-ROGUE-777' ) );
check( 'audit: counts chat rows', 3 === $audit['chats']['rows'] );
check( 'audit: no backup yet', false === $audit['current'] );

// ---- 4. Export: redaction and receipt ---------------------------------------------------------------------------
reset_state();
$export = TZFD_Legacy_AI_Data::export_json();
check( 'export: produces JSON', is_string( $export ) && '' !== $export );
check( 'export: credential value absent', false === strpos( $export, 'sk-TEST-SECRET-9f3a' ) );
check( 'export: query-string key in provider URL absent', false === strpos( $export, 'QUERYSECRET' ) );
check( 'export: rogue token absent', false === strpos( $export, 'tok-ROGUE-777' ) );
check( 'export: chats included', 3 === substr_count( $export, '"user_email"' ) );
check( 'export: receipt recorded and current', TZFD_Legacy_AI_Data::backup_current() );
$payload = json_decode( $export, true );
$cred = null; foreach ( $payload['options'] as $o ) { if ( 'tz_fd_ai_api_key' === $o['name'] ) { $cred = $o; } }
check( 'export: credential marked redacted with format', $cred && true === $cred['redacted'] && null === $cred['value'] && false !== strpos( $cred['format'], 'plain' ) );

// ---- 5. Deletion gates: receipt, phrase, scope ------------------------------------------------------------------
reset_state();
$res = TZFD_Legacy_AI_Data::delete_scope( 'connection', TZFD_Legacy_AI_Data::PHRASE );
check( 'delete: refused with no backup', false === $res['ok'] && 'no_current_backup' === $res['code'] );
TZFD_Legacy_AI_Data::export_json();
$res = TZFD_Legacy_AI_Data::delete_scope( 'connection', 'delete legacy ai data' );
check( 'delete: refused with wrong phrase', false === $res['ok'] && 'bad_phrase' === $res['code'] );
$res = TZFD_Legacy_AI_Data::delete_scope( 'everything', TZFD_Legacy_AI_Data::PHRASE );
check( 'delete: unknown scope refused', false === $res['ok'] && 'bad_scope' === $res['code'] );
$GLOBALS['OPTS']['tz_fd_ai_services'] = 'Changed after backup';
$res = TZFD_Legacy_AI_Data::delete_scope( 'chats', TZFD_Legacy_AI_Data::PHRASE );
check( 'delete: stale backup refused after data changes', false === $res['ok'] && 'no_current_backup' === $res['code'] );
reset_state();
TZFD_Legacy_AI_Data::export_json();
$res = TZFD_Legacy_AI_Data::delete_scope( 'connection', TZFD_Legacy_AI_Data::PHRASE );
check( 'delete: connection scope removes credential and provider only', true === $res['ok'] && $res['removed'] >= 2 );
check( 'delete: credential option gone', ! array_key_exists( 'tz_fd_ai_api_key', $GLOBALS['OPTS'] ) );
check( 'delete: configuration kept', 'Boiler repairs; Drain clearing' === ( $GLOBALS['OPTS']['tz_fd_ai_services'] ?? null ) );
check( 'delete: chats kept after connection scope', 3 === count( $wpdb->rows ) );

// ---- 6. Chat delete, then restore from export -------------------------------------------------------------------
reset_state();
$export = TZFD_Legacy_AI_Data::export_json();
$res = TZFD_Legacy_AI_Data::delete_scope( 'chats', TZFD_Legacy_AI_Data::PHRASE );
check( 'chats delete: removes all saved chat rows', true === $res['ok'] && 0 === count( $wpdb->rows ) && 3 === $res['removed'] );
check( 'chats delete: bookings-style configuration untouched', 'Boiler repairs; Drain clearing' === ( $GLOBALS['OPTS']['tz_fd_ai_services'] ?? null ) );
$restore = TZFD_Legacy_AI_Data::restore_from_json( $export );
check( 'restore: re-adds the three chat rows', true === $restore['ok'] && 3 === $restore['chats_inserted'] && 3 === count( $wpdb->rows ) );
check( 'restore: existing configuration is not overwritten', 'Boiler repairs; Drain clearing' === ( $GLOBALS['OPTS']['tz_fd_ai_services'] ?? null ) );
unset( $GLOBALS['OPTS']['tz_fd_ai_api_key'] );
$restore = TZFD_Legacy_AI_Data::restore_from_json( $export );
check( 'restore: credential is not restored and is flagged for re-entry', ! array_key_exists( 'tz_fd_ai_api_key', $GLOBALS['OPTS'] ) && $restore['credentials_to_reenter'] >= 1 );
$again = TZFD_Legacy_AI_Data::restore_from_json( $export );
check( 'restore: running twice adds nothing new', 0 === $again['chats_inserted'] && 3 === count( $wpdb->rows ) );
unset( $GLOBALS['OPTS']['tz_fd_ai_services'], $GLOBALS['OPTS']['tz_fd_ai_model'] );
$restore = TZFD_Legacy_AI_Data::restore_from_json( $export );
check( 'restore: missing configuration value is restored', 'Boiler repairs; Drain clearing' === ( $GLOBALS['OPTS']['tz_fd_ai_services'] ?? null ) && 1 === $restore['options_restored'] );
check( 'restore: provider settings are not restored', ! array_key_exists( 'tz_fd_ai_model', $GLOBALS['OPTS'] ) );

// ---- 7. Hostile restore files ----------------------------------------------------------------------------------
reset_state();
check( 'restore: wrong schema refused', 'bad_schema' === TZFD_Legacy_AI_Data::restore_from_json( '{"schema":"other"}' )['code'] );
check( 'restore: non-JSON refused', false === TZFD_Legacy_AI_Data::restore_from_json( 'not json' )['ok'] );
$hostile = json_encode( array(
    'schema'  => TZFD_Legacy_AI_Data::SCHEMA,
    'options' => array(
        array( 'name' => 'siteurl', 'class' => 'configuration', 'present' => true, 'value' => 'https://evil.test' ),
        array( 'name' => 'tz_fd_ai_api_key', 'class' => 'configuration', 'present' => true, 'value' => 'injected' ),
        array( 'name' => 'tz_fd_ai_services', 'class' => 'configuration', 'present' => true, 'value' => 'overwrite attempt' ),
    ),
    'chats'   => array(
        array( 'id' => 50, 'user_email' => 'x@example.test', 'evil_column' => 'drop', 'messages' => '[]' ),
        array( 'id' => 1, 'user_email' => 'a@example.test', 'messages' => 'dup' ),
        array( 'user_email' => 'no-id@example.test' ),
    ),
) );
$res = TZFD_Legacy_AI_Data::restore_from_json( $hostile );
check( 'restore: cannot set non-legacy option', ! array_key_exists( 'siteurl', $GLOBALS['OPTS'] ) );
check( 'restore: cannot inject or replace a credential', 'sk-TEST-SECRET-9f3a' === ( $GLOBALS['OPTS']['tz_fd_ai_api_key'] ?? null ) );
check( 'restore: cannot overwrite existing configuration', 'Boiler repairs; Drain clearing' === $GLOBALS['OPTS']['tz_fd_ai_services'] );
check( 'restore: unknown column dropped from chat row', ! isset( $wpdb->rows[3]['evil_column'] ) && 'x@example.test' === ( $wpdb->rows[3]['user_email'] ?? null ) );
check( 'restore: duplicate id and missing id skipped', 2 === $res['chats_skipped'] );

// ---- 8. Privacy export and retention for historic chats ---------------------------------------------------------
reset_state();
$p = TZFD_Legacy_AI_Data::privacy_export( 'A@example.test', 1 );
check( 'privacy export: matches case-insensitively and returns both rows', 2 === count( $p['data'] ) && true === $p['done'] );
check( 'privacy export: invalid email returns nothing', array() === TZFD_Legacy_AI_Data::privacy_export( 'not-an-email', 1 )['data'] );
$e = TZFD_Legacy_AI_Data::privacy_erase( 'a@example.test', 1 );
check( 'privacy erase: deletes both chats for the address and keeps other people\'s chats', true === $e['items_removed'] && false === $e['items_retained'] && 1 === count( $wpdb->rows ) && 'b@example.test' === $wpdb->rows[0]['user_email'] );
check( 'privacy erase: no chats left for the address', 0 === count( TZFD_Legacy_AI_Data::privacy_export( 'a@example.test', 1 )['data'] ) );

// ---- 9. Static guarantees: nothing deletes on load, no network, no key fields -----------------------------------
$files = array(
    dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-legacy-ai-data.php',
    dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-hosted-ai-connector.php',
);
$src = implode( "\n", array_map( 'file_get_contents', $files ) );
check( 'static: no admin_init or init hook in the new files', false === strpos( $src, "'admin_init'" ) && false === strpos( $src, "'init'" ) );
check( 'static: no HTTP client calls in the new files', ! preg_match( '/wp_remote_|curl_|fsockopen|stream_context_create/', $src ) );
check( 'static: connector has no credential fields', false === strpos( file_get_contents( $files[1] ), 'api_key' ) );
reset_state();
TZFD_Legacy_AI_Data::register();
check( 'static: register() adds no admin_init or init hook', ! in_array( 'admin_init', $GLOBALS['HOOKS'], true ) && ! in_array( 'init', $GLOBALS['HOOKS'], true ) );
check( 'static: legacy options survive register()', 'sk-TEST-SECRET-9f3a' === ( $GLOBALS['OPTS']['tz_fd_ai_api_key'] ?? null ) );

echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
