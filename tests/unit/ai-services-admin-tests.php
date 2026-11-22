<?php
/**
 * Standalone tests for the AI Services connector screen (TZFD_AI_Services_Page, issues #4 and #261).
 * Run: php -l on the page file, then: php tests/unit/ai-services-admin-tests.php
 * No WordPress install and no network: option storage and WP escaping helpers are in-memory shims.
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tz_opts'] = array();

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
function wp_safe_redirect( $u ) { return true; }
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

$P       = 'TZFD_AI_Services_Page';
$allowed = TZFD_AI_Connector::disclosure()['data_classes'];

// Scope normalisation: only disclosed scopes survive, duplicates collapse, non-strings drop.
$scopes = $P::normalise_scopes( array( $allowed[0], $allowed[0], 'root.everything', 42, null, array( 'x' ) ), $allowed );
check( 'scopes keep only disclosed values', $scopes === array( $allowed[0] ), json_encode( $scopes ) );
check( 'empty submission yields no scopes', array() === $P::normalise_scopes( array(), $allowed ) );
check( 'unknown-only submission yields no scopes', array() === $P::normalise_scopes( array( 'delete.all' ), $allowed ) );

// Retention: whole days 1..365 only.
check( 'retention accepts 30', 30 === $P::normalise_retention( '30' ) && 30 === $P::normalise_retention( 30 ) );
check( 'retention accepts bounds 1 and 365', 1 === $P::normalise_retention( '1' ) && 365 === $P::normalise_retention( '365' ) );
check( 'retention refuses 0, 366, negatives', false === $P::normalise_retention( '0' ) && false === $P::normalise_retention( '366' ) && false === $P::normalise_retention( '-5' ) );
check( 'retention refuses decimals, words and arrays', false === $P::normalise_retention( '3.5' ) && false === $P::normalise_retention( 'ten' ) && false === $P::normalise_retention( array( 3 ) ) );
check( 'retention refuses null and 1e2', false === $P::normalise_retention( null ) && false === $P::normalise_retention( '1e2' ) );

// Event rows: only four fields, bad rows skipped, latency clamped.
$rows = $P::event_rows( array(
    array( 'at' => 100, 'feature' => 'faq.answer', 'status' => 'ok', 'latency_ms' => 12, 'payload' => 'SECRET-BODY', 'secret' => 'SECRET-KEY' ),
    'not-an-array',
    array( 'at' => 101, 'feature' => 'agent.run', 'status' => 'offline', 'latency_ms' => -4 ),
) );
check( 'event rows skip non-array entries', 2 === count( $rows ) );
check( 'event rows keep only redacted fields', array( 'at', 'feature', 'status', 'latency_ms' ) === array_keys( $rows[0] ) );
check( 'event latency is never negative', 0 === $rows[1]['latency_ms'] );

// Render: default state, no consent, no secret shown.
$GLOBALS['tz_opts'] = array();
$html = render_page();
check( 'page shows no consent by default', false !== strpos( $html, 'None recorded' ) );
check( 'page shows no hosted event rows by default', false !== strpos( $html, 'No hosted AI events recorded' ) );
check( 'consent boxes are unticked by default', false === strpos( $html, "checked='checked'" ) );

// Pair the site with a secret, grant one scope, log an event, then render again.
$secret = 'S3CRET-' . str_repeat( 'k', 40 );
TZFD_AI_Connector::configure( 'https://hosted.example.test/ai', 'site-a', $secret );
TZFD_AI_Connector::grant_consent( array( $allowed[0] ) );
TZFD_AI_Connector::set_entitlements( array( 'free_ai' ) );
$GLOBALS['tz_opts'][ TZFD_AI_Connector::EVENT_OPTION ] = array( array( 'at' => time(), 'feature' => 'faq.answer', 'status' => 'ok', 'latency_ms' => 9 ) );
$html = render_page();
check( 'page shows granted scope after consent', false !== strpos( $html, 'Granted for: ' . htmlspecialchars( $allowed[0], ENT_QUOTES ) ) );
check( 'granted scope box is ticked', 1 === preg_match( '/value="' . preg_quote( htmlspecialchars( $allowed[0], ENT_QUOTES ), '/' ) . '"\s+checked=\'checked\'/', $html ) );
check( 'revoke control appears after consent', false !== strpos( $html, 'Revoke consent' ) );
check( 'event log row renders', false !== strpos( $html, 'faq.answer' ) && false !== strpos( $html, '>9<' ) );
check( 'provider secret is never rendered', false === strpos( $html, $secret ) && false === strpos( $html, 'S3CRET' ) );
check( 'endpoint is shown only as set or not set', false !== strpos( $html, 'Set' ) && false === strpos( $html, 'hosted.example.test' ) );

// Revocation clears consent for the screen as well.
TZFD_AI_Connector::revoke_consent();
$html = render_page();
check( 'after revocation the screen shows no consent', false !== strpos( $html, 'None recorded' ) && false === strpos( $html, 'Revoke consent' ) );

// Connector-side retention accepts the page's values.
check( 'connector accepts retention set by the page', $P::normalise_retention( '45' ) === 45 && TZFD_AI_Connector::set_retention_days( 45 ) && 45 === TZFD_AI_Connector::retention_days() );

// Opt-in connect and disconnect (#333). Pairing stores the secret but grants no consent.
$GLOBALS['tz_opts'] = array();
$connect_in = $P::normalise_connect( array( 'endpoint' => ' https://hosted.example.test/ai ', 'site_id' => 'site-b', 'secret' => $secret ) );
check( 'connect input is trimmed and kept', is_array( $connect_in ) && 'https://hosted.example.test/ai' === $connect_in['endpoint'] && 'site-b' === $connect_in['site_id'] );
check( 'connect input refuses a blank or missing field', false === $P::normalise_connect( array( 'endpoint' => 'https://x.test', 'site_id' => '  ', 'secret' => $secret ) ) && false === $P::normalise_connect( array( 'endpoint' => 'https://x.test', 'site_id' => 'a' ) ) );
check( 'connect input refuses non-string values', false === $P::normalise_connect( array( 'endpoint' => array( 'https://x.test' ), 'site_id' => 'a', 'secret' => $secret ) ) );
check( 'connector refuses a plain http endpoint', false === TZFD_AI_Connector::configure( 'http://hosted.example.test/ai', 'site-b', $secret ) );
check( 'connector refuses a short secret', false === TZFD_AI_Connector::configure( 'https://hosted.example.test/ai', 'site-b', 'short' ) );
check( 'refused pairing stores nothing', ! TZFD_AI_Connector::status()['secret_set'] && ! TZFD_AI_Connector::status()['endpoint_set'] );
$html = render_page();
check( 'unpaired page shows the connect form', false !== strpos( $html, 'id="tzfd-ai-secret"' ) && false !== strpos( $html, 'name="site_id"' ) );
check( 'connect form uses a password field for the secret', 1 === preg_match( '/type="password" id="tzfd-ai-secret"/', $html ) );
check( 'connect form never prefills the secret', false === strpos( $html, 'value="' . $secret . '"' ) );
check( 'connect form has no disconnect button when unpaired', false === strpos( $html, 'Removes the secret and endpoint' ) );

TZFD_AI_Connector::configure( 'https://hosted.example.test/ai', 'site-b', $secret );
TZFD_AI_Connector::grant_consent( array( $allowed[0] ) );
TZFD_AI_Connector::revoke_consent(); // what handle_connect does after a successful pairing
$html = render_page();
check( 'paired page shows the paired state and no connect form', false !== strpos( $html, 'paired with the hosted service' ) && false === strpos( $html, 'id="tzfd-ai-secret"' ) );
check( 'paired page never renders the secret', false === strpos( $html, $secret ) );
check( 'a new pairing starts with no consent', false !== strpos( $html, 'None recorded' ) );

TZFD_AI_Connector::disconnect();
$html = render_page();
check( 'disconnect removes the pairing and returns the connect form', false !== strpos( $html, 'id="tzfd-ai-secret"' ) && false === strpos( $html, 'paired with the hosted service' ) );
check( 'disconnect clears the stored secret flag', false === TZFD_AI_Connector::status()['secret_set'] );

// Agent Team panel: this standalone run does not load the gateway, so the panel must degrade, not fatal.
$html = render_page();
check( 'agent panel heading renders', false !== strpos( $html, 'Agent Team controls' ) );
check( 'agent panel says unavailable when the gateway is not loaded', false !== strpos( $html, 'Agent permissions are not available on this build.' ) );
check( 'agent panel adds no write form', false === strpos( $html, 'tzfd_agent_state' ) && false === strpos( $html, 'tzfd_agent_grant' ) );

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( 0 === $GLOBALS['tz_fail'] ? 0 : 1 );
