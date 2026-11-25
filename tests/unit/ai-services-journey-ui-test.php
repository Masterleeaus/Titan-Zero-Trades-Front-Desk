<?php
/**
 * Standalone UI journey test for the AI Services screen (issue #372, Assistant and Free in-place downgrade).
 * Run: php tests/unit/ai-services-journey-ui-test.php
 * No WordPress install and no database: option storage and WP helpers are in-memory shims.
 *
 * Walks the in-place journey Free -> Assistant -> Agent Team -> paused -> Assistant -> cancelled -> Free and renders
 * the real admin page at each step. The local downgrade handler exits, so it runs in a child PHP process
 * (--downgrade-child) whose shutdown hook reports the stored options and the redirect.
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tz_opts']       = array();
$GLOBALS['tz_redirect']   = null;

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['tz_opts'] ) ? $GLOBALS['tz_opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['tz_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['tz_opts'][ $k ] ); return true; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return true; }
function add_submenu_page( ...$args ) { return 'hook'; }
function current_user_can( $cap ) { return true; }
function check_admin_referer( $action = -1 ) { return true; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) { if ( $echo ) { echo ''; } return ''; }
function wp_unslash( $v ) { return $v; }
function wp_safe_redirect( $u ) { $GLOBALS['tz_redirect'] = $u; return true; }
function wp_die( $m = '', $t = 0 ) { throw new RuntimeException( 'wp_die' ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function __( $s, $d = '' ) { return $s; }
function checked( $c, $cur = true, $echo = true ) { $r = $c ? " checked='checked'" : ''; if ( $echo ) { echo $r; } return $r; }

require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-services-page.php';

use TZFD_AI_Services as S;

// Child process: run the real local downgrade handler once, then report what it left behind.
if ( in_array( '--downgrade-child', $argv ?? array(), true ) ) {
    $GLOBALS['tz_opts'] = array(
        S::OPTION                  => array( 'schema_version' => 1, 'company_id' => 1, 'state' => 'active', 'entitlements' => array( 'agent_team' ), 'expires_at' => 1900003600 ),
        TZFD_AI_Connector::OPTION  => array( 'endpoint' => 'https://hosted.example.test/ai', 'site_id' => 'site-a' ),
        'tzfd_local_probe'         => array( 'bookings' => 3, 'customers' => 2 ),
    );
    register_shutdown_function( static function () {
        echo "\nCHILD_RESULT " . json_encode( array( 'opts' => $GLOBALS['tz_opts'], 'redirect' => $GLOBALS['tz_redirect'] ) ) . "\n";
    } );
    TZFD_AI_Services_Page::handle_local_downgrade(); // exits
    exit( 0 );
}

$GLOBALS['tz_fail'] = 0;
$GLOBALS['tz_pass'] = 0;
function check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

function render_page() {
    ob_start();
    TZFD_AI_Services_Page::render();
    return ob_get_clean();
}

function journey_step( $state, array $entitlements, $company = 1, $expires = 1900003600 ) {
    return array( 'schema_version' => 1, 'company_id' => $company, 'state' => $state, 'entitlements' => $entitlements, 'expires_at' => $expires );
}

$now    = 1900000000;
$secret = 'J0URNEY-' . str_repeat( 'x', 40 );
$levels = TZFD_AI_Services::descriptors()['levels'];
$label  = static function ( $key ) use ( $levels ) { return htmlspecialchars( (string) $levels[ $key ]['label'], ENT_QUOTES, 'UTF-8' ); };

// A paired site with a stored secret: the secret must never reach the page at any step.
TZFD_AI_Connector::configure( 'https://hosted.example.test/ai', 'site-a', $secret );

// Local data sentinel: the AI screen must never touch it.
$local_probe = array( 'bookings' => 3, 'customers' => 2 );
$GLOBALS['tz_opts']['tzfd_local_probe'] = $local_probe;

// Each step: [name, payload, expected level key, expected reason, expected label key].
$steps = array(
    array( 'free (no entitlement)', null, 'free_ai', 'no_entitlement', 'free_ai' ),
    array( 'assistant active', journey_step( 'active', array( 'assistant' ) ), 'assistant', 'active', 'assistant' ),
    array( 'agent team active', journey_step( 'active', array( 'agent_team', 'assistant' ) ), 'agent_team', 'active', 'agent_team' ),
    array( 'agent team paused', journey_step( 'paused_agents', array( 'agent_team', 'assistant' ) ), 'assistant', 'paused_agents', 'assistant' ),
    array( 'back to assistant', journey_step( 'active', array( 'assistant' ) ), 'assistant', 'active', 'assistant' ),
    array( 'assistant cancelled', journey_step( 'cancelled', array( 'assistant' ) ), 'free_ai', 'cancelled', 'free_ai' ),
);

foreach ( $steps as $step ) {
    list( $name, $payload, $level, $reason, $label_key ) = $step;
    if ( null === $payload ) {
        unset( $GLOBALS['tz_opts'][ S::OPTION ] );
    } else {
        $GLOBALS['tz_opts'][ S::OPTION ] = $payload;
    }
    $html = render_page();

    check( $name . ': status row shows the level label', 1 === preg_match( '/<th scope="row">Hosted level<\/th>\s*<td>' . preg_quote( $label( $label_key ), '/' ) . '<\/td>/', $html ) );
    check( $name . ': status row shows the reason text', false !== strpos( $html, htmlspecialchars( S::reason_text( $reason ), ENT_QUOTES, 'UTF-8' ) ) );
    check( $name . ': local features stay always enabled', 1 === preg_match( '/<th scope="row">Local features<\/th>\s*<td>Always enabled<\/td>/', $html ) );
    check( $name . ': secret never rendered', false === strpos( $html, $secret ) && false === strpos( $html, 'J0URNEY' ) );
    check( $name . ': local data untouched by the page', $GLOBALS['tz_opts']['tzfd_local_probe'] === $local_probe );
    check( $name . ': resolver agrees with the rendered level', S::resolve( $payload, 1, $now )['level'] === $level );
}

// Downgrade keeps the paid-level label off the Free screen after the handler runs in a child process.
$GLOBALS['tz_opts'][ S::OPTION ] = journey_step( 'active', array( 'agent_team', 'assistant' ) );
$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --downgrade-child 2>&1';
$out = (string) shell_exec( $cmd );
$line = null;
foreach ( explode( "\n", $out ) as $row ) {
    if ( 0 === strpos( $row, 'CHILD_RESULT ' ) ) { $line = substr( $row, 13 ); }
}
$child = null !== $line ? json_decode( $line, true ) : null;
check( 'downgrade child reported its result', is_array( $child ) );
if ( is_array( $child ) ) {
    check( 'local downgrade removes the cached entitlement only', ! array_key_exists( S::OPTION, $child['opts'] ) );
    check( 'local downgrade leaves local data untouched', ( $child['opts']['tzfd_local_probe'] ?? null ) === $local_probe );
    check( 'local downgrade redirects with the local_free notice', is_string( $child['redirect'] ) && false !== strpos( $child['redirect'], 'tzfd_ai=local_free' ) && false !== strpos( $child['redirect'], 'page=tzfd-ai-services' ) );
}

// After the downgrade, the same screen shows Free with no paid level and no secret.
unset( $GLOBALS['tz_opts'][ S::OPTION ] );
$html = render_page();
check( 'after downgrade the screen shows Free', 1 === preg_match( '/<th scope="row">Hosted level<\/th>\s*<td>' . preg_quote( $label( 'free_ai' ), '/' ) . '<\/td>/', $html ) );
check( 'after downgrade the screen shows no paid level as current', false === strpos( $html, 'Hosted AI is active on this site.' ) );
check( 'after downgrade the secret is still not rendered', false === strpos( $html, $secret ) );

// Notice text for the downgrade lands on the next page load.
$_GET['tzfd_ai'] = 'local_free';
$html = render_page();
check( 'local_free notice renders after the downgrade redirect', false !== strpos( $html, 'Hosted AI is switched off on this site.' ) );
unset( $_GET['tzfd_ai'] );

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( 0 === $GLOBALS['tz_fail'] ? 0 : 1 );
