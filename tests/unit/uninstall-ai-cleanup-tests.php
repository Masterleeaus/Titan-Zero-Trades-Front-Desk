<?php
/**
 * Uninstall cleanup for the AI connector and AI answer-box state (#43, data removal under #44).
 * Runs uninstall.php against an in-memory options table with WordPress functions stubbed.
 * The opt-in case runs in a child process, because TZ_DELETE_DATA_ON_UNINSTALL cannot be undefined.
 * Run from the repository root: php tests/unit/uninstall-ai-cleanup-tests.php
 */
define( 'ABSPATH', __DIR__ );
define( 'WP_UNINSTALL_PLUGIN', true );

$tz_mode = 'erase' === getenv( 'TZFD_UNINSTALL_CASE' ) ? 'erase' : 'keep';
if ( 'erase' === $tz_mode ) {
    define( 'TZ_DELETE_DATA_ON_UNINSTALL', true );
}

$GLOBALS['tz_opts'] = array(
    'tzfd_ai_connector'            => array( 'endpoint' => 'https://ai.example.test/x' ),
    'tzfd_ai_connector_secret'     => str_repeat( 's', 40 ),
    'tzfd_ai_usage_2026-11'        => array( 'features' => array() ),
    'tzfd_ai_events'               => array(),
    'tzfd_ai_services_entitlement' => array( 'tier' => 'free_ai' ),
    'tz_fd_business_profile'       => 'profile',
    'blogname'                     => 'Site',
    'tzfd_unrelated_option'        => 'keep',
    '_transient_tzfd_ai_ans_abc'   => 'cached answer',
    '_transient_timeout_tzfd_ai_ans_abc' => 999,
    '_transient_other_plugin_x'    => 1,
);

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['tz_opts'] ) ? $GLOBALS['tz_opts'][ $k ] : $d; }
    function update_option( $k, $v, $a = null ) { $GLOBALS['tz_opts'][ $k ] = $v; return true; }
    function delete_option( $k ) { unset( $GLOBALS['tz_opts'][ $k ] ); return true; }
    function wp_unschedule_hook( $h ) { return 0; }
    function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/tzfd-uninstall-none-' . getmypid() ); }
    function wp_delete_file( $f ) { @unlink( $f ); }
}

/** Converts a SQL LIKE pattern (with backslash escapes from esc_like) to a regex. */
function tz_like_to_regex( $p ) {
    $re = '';
    $n  = strlen( $p );
    for ( $i = 0; $i < $n; $i++ ) {
        $c = $p[ $i ];
        if ( '\\' === $c && $i + 1 < $n ) {
            $re .= preg_quote( $p[ ++$i ], '/' );
        } elseif ( '%' === $c ) {
            $re .= '.*';
        } elseif ( '_' === $c ) {
            $re .= '.';
        } else {
            $re .= preg_quote( $c, '/' );
        }
    }
    return '/^' . $re . '$/s';
}

/** In-memory wpdb: prepare() fills %s and %i, query() deletes wp_options rows matched by LIKE patterns. */
class TZFD_Uninstall_Test_WPDB {
    public $prefix  = 'wp_';
    public $options = 'wp_options';
    public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
    public function prepare( $sql, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%[si]/', function ( $m ) use ( &$i, $args ) { return "'" . $args[ $i++ ] . "'"; }, $sql );
    }
    public function query( $sql ) {
        if ( 0 !== strpos( $sql, 'DELETE FROM wp_options' ) ) { return 0; }
        preg_match_all( "/option_name LIKE '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
        $regexes = array_map( 'tz_like_to_regex', $m[1] );
        $removed = 0;
        foreach ( array_keys( $GLOBALS['tz_opts'] ) as $name ) {
            foreach ( $regexes as $re ) {
                if ( preg_match( $re, $name ) ) {
                    unset( $GLOBALS['tz_opts'][ $name ] );
                    $removed++;
                    break;
                }
            }
        }
        return $removed;
    }
}
$GLOBALS['wpdb'] = new TZFD_Uninstall_Test_WPDB();

$tz_pass = 0;
$tz_fail = 0;
function tz_check( $name, $ok, $detail = '' ) {
    global $tz_pass, $tz_fail;
    if ( $ok ) { $tz_pass++; } else { $tz_fail++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

require dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/uninstall.php';

$opts = $GLOBALS['tz_opts'];

if ( 'erase' === $tz_mode ) {
    tz_check( 'opt-in: AI connector settings are erased', ! isset( $opts['tzfd_ai_connector'] ) );
    tz_check( 'opt-in: AI usage counts are erased', ! isset( $opts['tzfd_ai_usage_2026-11'] ) );
    tz_check( 'opt-in: AI event log is erased', ! isset( $opts['tzfd_ai_events'] ) );
    tz_check( 'opt-in: AI services entitlement is erased', ! isset( $opts['tzfd_ai_services_entitlement'] ) );
    tz_check( 'opt-in: plugin settings are erased', ! isset( $opts['tz_fd_business_profile'] ) );
} else {
    tz_check( 'without opt-in: AI connector settings are kept', isset( $opts['tzfd_ai_connector'] ) );
    tz_check( 'without opt-in: AI usage counts are kept', isset( $opts['tzfd_ai_usage_2026-11'] ) );
    tz_check( 'without opt-in: AI event log is kept', isset( $opts['tzfd_ai_events'] ) );
    tz_check( 'without opt-in: plugin settings are kept', isset( $opts['tz_fd_business_profile'] ) );
}

tz_check( 'always: hosted AI secret is removed', ! isset( $opts['tzfd_ai_connector_secret'] ) );
tz_check( 'always: AI answer-box transient is removed', ! isset( $opts['_transient_tzfd_ai_ans_abc'] ) && ! isset( $opts['_transient_timeout_tzfd_ai_ans_abc'] ) );
tz_check( 'always: other plugins transients are kept', isset( $opts['_transient_other_plugin_x'] ) );
tz_check( 'always: WordPress core and unrelated options are kept', 'Site' === ( $opts['blogname'] ?? null ) && 'keep' === ( $opts['tzfd_unrelated_option'] ?? null ) );

if ( 'erase' === $tz_mode ) {
    echo "\nChild summary: $tz_pass passed, $tz_fail failed\n";
    exit( $tz_fail > 0 ? 1 : 0 );
}

// Keep case in the parent process: run the opt-in case in a child process and fold its result in.
$child_out = array();
$child_rc  = 0;
exec( 'TZFD_UNINSTALL_CASE=erase ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $child_out, $child_rc );
echo implode( "\n", array_map( function ( $l ) { return '  [opt-in] ' . $l; }, $child_out ) ) . "\n";
tz_check( 'opt-in child process passes every erase check', 0 === $child_rc, 'exit=' . $child_rc );

echo "\nSummary: $tz_pass passed, $tz_fail failed\n";
exit( $tz_fail > 0 ? 1 : 0 );
