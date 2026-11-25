<?php
/**
 * Journey test for the in-place local downgrade (Assistant or Agent Team to Free) on the AI Services page (issue #423).
 *
 * It calls the real TZFD_AI_Services_Page::handle_local_downgrade() handler. WordPress functions are in-memory shims:
 * wp_die, check_admin_referer and wp_safe_redirect throw instead of exiting, so the test can assert what happened.
 * No WordPress install, database or network is needed. The browser (rendered page) journey is not covered here.
 *
 * Run: php -d error_reporting=-1 tests/ai-services/run-downgrade-journey-tests.php
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tz_opts']     = array();
$GLOBALS['tz_filters']  = array();
$GLOBALS['tz_calls']    = array();
$GLOBALS['tz_can']      = true;
$GLOBALS['tz_nonce_ok'] = true;
$GLOBALS['tz_redirect'] = null;

final class TZ_Journey_Stop extends RuntimeException {}

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['tz_opts'] ) ? $GLOBALS['tz_opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['tz_opts'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) {
    if ( array_key_exists( $k, $GLOBALS['tz_opts'] ) ) { return false; }
    $GLOBALS['tz_opts'][ $k ] = $v;
    return true;
}
function delete_option( $k ) { unset( $GLOBALS['tz_opts'][ $k ] ); return true; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['tz_filters'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value, ...$args ) {
    foreach ( isset( $GLOBALS['tz_filters'][ $tag ] ) ? $GLOBALS['tz_filters'][ $tag ] : array() as $cb ) {
        $value = $cb( $value, ...$args );
    }
    return $value;
}
function wp_json_encode( $v ) { return json_encode( $v ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function current_user_can( $cap ) { return (bool) $GLOBALS['tz_can']; }
function check_admin_referer( $action ) {
    if ( ! $GLOBALS['tz_nonce_ok'] ) { throw new TZ_Journey_Stop( 'nonce' ); }
    return 1;
}
function wp_die( $message = '', $code = 0 ) { throw new TZ_Journey_Stop( 'wp_die ' . $code ); }
function wp_safe_redirect( $url ) { $GLOBALS['tz_redirect'] = $url; throw new TZ_Journey_Stop( 'redirect' ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $path = '' ) { return 'https://site.test/wp-admin/' . $path; }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }

require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-central-tenant.php';
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-services-page.php';

$GLOBALS['tzfd_j_pass'] = 0;
$GLOBALS['tzfd_j_fail'] = 0;
function j_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['tzfd_j_pass']++; } else { $GLOBALS['tzfd_j_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

const J_SITE   = 'site-a';
const J_SECRET = 'journey-secret-0123456789abcdef0123456789abcdef'; // test value, never a real credential
const J_CO     = '3f2b8c1e-5d4a-4b7e-9c1f-0a1b2c3d4e5f';

$S = 'TZFD_AI_Services';
$C = 'TZFD_AI_Connector';
$P = 'TZFD_AI_Services_Page';

/** Puts the site into a paid level: a verified entitlement cache plus connector consent, as the connector would leave it. */
function j_paid_site( $entitlements ) {
    global $S, $C;
    $GLOBALS['tz_opts'] = array(
        $S::OPTION => array(
            'schema_version' => 1, 'company_id' => 7, 'state' => 'active',
            'entitlements' => $entitlements, 'expires_at' => time() + 600,
        ),
        'tzfd_local_sentinel' => array( 'bookings' => 3, 'customers' => 2, 'faq_items' => 5 ),
    );
    $C::configure( 'https://ai.example.test/v1/request', J_SITE, J_SECRET );
    $C::grant_consent( array( 'faq.read', 'customer.read' ) );
}

/** Runs the real downgrade handler; returns the outcome. */
function j_run_downgrade() {
    global $P;
    $GLOBALS['tz_redirect'] = null;
    try {
        $P::handle_local_downgrade();
        return 'returned';
    } catch ( TZ_Journey_Stop $stop ) {
        return $stop->getMessage();
    }
}

// Journey 1: Assistant to Free in place.
j_paid_site( array( 'assistant' ) );
$before = TZFD_AI_Services::resolve( get_option( TZFD_AI_Services::OPTION ), 7 );
j_check( 'start: the site resolves to the Assistant level', 'assistant' === $before['level'] && true === $before['assistant'] );
j_check( 'start: hosted consent is granted', true === TZFD_AI_Connector::status()['consent'] );

$outcome = j_run_downgrade();
j_check( 'downgrade: handler reaches the redirect', 'redirect' === $outcome, $outcome );
j_check( 'downgrade: redirect carries the local_free notice on the AI Services page', false !== strpos( (string) $GLOBALS['tz_redirect'], 'tzfd_ai=local_free' ) && false !== strpos( (string) $GLOBALS['tz_redirect'], 'page=tzfd-ai-services' ), (string) $GLOBALS['tz_redirect'] );

$after = TZFD_AI_Services::resolve( get_option( TZFD_AI_Services::OPTION ), 7 );
j_check( 'downgrade: the level resolves to Free with no entitlement', 'free_ai' === $after['level'] && 'no_entitlement' === $after['reason'] );
j_check( 'downgrade: hosted consent is revoked', false === TZFD_AI_Connector::status()['consent'] && array() === TZFD_AI_Connector::status()['scopes'] );
j_check( 'downgrade: the revocation is in the connection history', in_array( 'consent_revoked', array_column( TZFD_AI_Connector::audit_log(), 'action' ), true ) );
j_check( 'downgrade: the local data sentinel is unchanged', array( 'bookings' => 3, 'customers' => 2, 'faq_items' => 5 ) === get_option( 'tzfd_local_sentinel' ) );
j_check( 'downgrade: local features stay enabled at Free', true === TZFD_AI_Services::local_features_enabled( $after ) );
j_check( 'downgrade: the stored secret and endpoint are kept, so a later consent needs no reconnect', true === TZFD_AI_Connector::status()['secret_set'] && true === TZFD_AI_Connector::status()['endpoint_set'] );

// Journey 2: Agent Team to Free in place, consent already revoked by the owner. Nothing to revoke, nothing extra written.
j_paid_site( array( 'agent_team' ) );
TZFD_AI_Connector::revoke_consent();
$rows_before = count( TZFD_AI_Connector::audit_log() );
$outcome     = j_run_downgrade();
j_check( 'agent to free: handler reaches the redirect', 'redirect' === $outcome, $outcome );
j_check( 'agent to free: no second revocation is written when consent was already off', $rows_before === count( TZFD_AI_Connector::audit_log() ) );
j_check( 'agent to free: level is Free afterwards', 'free_ai' === TZFD_AI_Services::resolve( get_option( TZFD_AI_Services::OPTION ), 7 )['level'] );

// Journey 3: no capability. The handler refuses and changes nothing.
j_paid_site( array( 'assistant' ) );
$GLOBALS['tz_can'] = false;
$outcome           = j_run_downgrade();
$GLOBALS['tz_can'] = true;
j_check( 'no capability: refused with 403', 'wp_die 403' === $outcome, $outcome );
j_check( 'no capability: the entitlement and consent are kept', array_key_exists( TZFD_AI_Services::OPTION, $GLOBALS['tz_opts'] ) && true === TZFD_AI_Connector::status()['consent'] );

// Journey 4: bad or missing nonce. The handler stops before any change.
j_paid_site( array( 'assistant' ) );
$GLOBALS['tz_nonce_ok'] = false;
$outcome                = j_run_downgrade();
$GLOBALS['tz_nonce_ok'] = true;
j_check( 'bad nonce: the handler stops before any change', 'nonce' === $outcome, $outcome );
j_check( 'bad nonce: the entitlement and consent are kept', array_key_exists( TZFD_AI_Services::OPTION, $GLOBALS['tz_opts'] ) && true === TZFD_AI_Connector::status()['consent'] );

// Journey 5: after the downgrade, a hosted request is refused with no transport call, even though consent was granted before.
j_paid_site( array( 'assistant' ) );
$calls = 0;
add_filter( 'tzfd_ai_connector_transport', function ( $value, $req ) use ( &$calls ) { $calls++; return array( 'error' => 'offline' ); }, 10, 2 );
j_run_downgrade();
$r = TZFD_AI_Connector::request( 'faq.answer', array( 'q' => 'hours' ) );
j_check( 'after downgrade: a hosted request returns consent_required', 'consent_required' === $r['status'] && true === $r['use_local'] );
j_check( 'after downgrade: no transport call is made', 0 === $calls );

echo "\n{$GLOBALS['tzfd_j_pass']} passed, {$GLOBALS['tzfd_j_fail']} failed\n";
exit( $GLOBALS['tzfd_j_fail'] > 0 ? 1 : 0 );
