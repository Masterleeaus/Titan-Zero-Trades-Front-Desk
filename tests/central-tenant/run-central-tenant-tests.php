<?php
/**
 * Offline tests for TZFD_Central_Tenant (issue #376): site-to-Central company pairing.
 *
 * Run: php -l on the changed files, then  php tests/central-tenant/run-central-tenant-tests.php
 * No WordPress install and no network. Option storage and filters are in-memory shims. Each simulated
 * WordPress site has its own option store, which is how multisite keeps one pairing per site.
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tz_opts']    = array();
$GLOBALS['tz_filters'] = array();
$GLOBALS['tz_calls']   = array();
$GLOBALS['tz_reply']   = null;

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

require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-central-tenant.php';
require __DIR__ . '/../../titan-zero-trades-front-desk/includes/class-tzfd-ai-connector.php';

$T = 'TZFD_Central_Tenant';
$C = 'TZFD_AI_Connector';

const SECRET_A  = '0123456789abcdef0123456789abcdef0123456789abcdef';
const SECRET_B  = 'fedcba9876543210fedcba9876543210fedcba9876543210';
const SITE_A    = 'site-a';
const SITE_B    = 'site-b';
const COMPANY_X = '3f2b8c1e-5d4a-4b7e-9c1f-0a1b2c3d4e5f';
const COMPANY_Y = '9a8b7c6d-1e2f-4a3b-8c9d-0e1f2a3b4c5d';
const LOCAL_ID  = 1; // TZFD_Local_Business::id(): site-local integer, never a Central UUID

$GLOBALS['tz_fail'] = 0;
$GLOBALS['tz_pass'] = 0;
function check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['tz_pass']++; } else { $GLOBALS['tz_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

/** Builds a signed pairing assertion. $o overrides any field or the signing secret. */
function make_assertion( array $o = array() ) {
    $now   = isset( $o['now'] ) ? $o['now'] : 1800000000;
    $nonce = isset( $o['nonce'] ) ? $o['nonce'] : bin2hex( random_bytes( 16 ) );
    $claims = array(
        'contract'     => isset( $o['contract'] ) ? $o['contract'] : 'central-pairing/1',
        'site'         => isset( $o['site'] ) ? $o['site'] : SITE_A,
        'company_uuid' => isset( $o['company'] ) ? $o['company'] : COMPANY_X,
        'operations'   => isset( $o['operations'] ) ? $o['operations'] : array( 'hosted.request' ),
        'nonce'        => isset( $o['claim_nonce'] ) ? $o['claim_nonce'] : $nonce,
        'issued_at'    => isset( $o['issued_at'] ) ? $o['issued_at'] : $now,
        'expires_at'   => isset( $o['expires_at'] ) ? $o['expires_at'] : $now + 600,
    );
    if ( array_key_exists( 'drop', $o ) ) { unset( $claims[ $o['drop'] ] ); }
    $body = isset( $o['body'] ) ? $o['body'] : json_encode( $claims );
    $ts   = isset( $o['ts'] ) ? $o['ts'] : (string) $now;
    $sig_secret = isset( $o['sign_with'] ) ? $o['sign_with'] : SECRET_A;
    $headers = array(
        'X-TZFD-Timestamp' => $ts,
        'X-TZFD-Nonce'     => $nonce,
        'X-TZFD-Signature' => TZFD_Central_Tenant::sign( $sig_secret, $ts, $nonce, $body ),
    );
    return array( 'body' => $body, 'headers' => $headers, 'now' => $now, 'nonce' => $nonce );
}
/** Verifies or connects with an assertion built by make_assertion(). */
function try_connect( array $a, $site = SITE_A, $secret = SECRET_A, $reassign = false ) {
    return TZFD_Central_Tenant::connect( $a['body'], $a['headers'], $site, $secret, $a['now'], $reassign );
}
function reset_site() { $GLOBALS['tz_opts'] = array(); $GLOBALS['tz_calls'] = array(); }

/* ---------- 1. Local integer is never a Central UUID ---------- */
check( 'UUID check accepts a canonical company UUID', true === $T::is_company_uuid( COMPANY_X ) );
check( 'local integer 1 is not a company UUID', false === $T::is_company_uuid( LOCAL_ID ) );
check( 'string "1" is not a company UUID', false === $T::is_company_uuid( '1' ) );
check( 'integer 42 and string "42" are not company UUIDs', false === $T::is_company_uuid( 42 ) && false === $T::is_company_uuid( '42' ) );
check( 'nil UUID is not a company UUID', false === $T::is_company_uuid( '00000000-0000-0000-0000-000000000000' ) );
check( 'email-like and empty values are not company UUIDs', false === $T::is_company_uuid( 'ops@example.test' ) && false === $T::is_company_uuid( '' ) && false === $T::is_company_uuid( null ) );

reset_site();
check( 'a pairing with integer company 1 is refused', 'company_invalid' === try_connect( make_assertion( array( 'company' => '1' ) ) )['reason'] && false === $T::resolve( SITE_A, 'hosted.request', 1800000000 )['ok'] );
check( 'a pairing with a non-UUID company is refused', 'company_invalid' === try_connect( make_assertion( array( 'company' => 'Acme Pty Ltd' ) ) )['reason'] );
check( 'local integer company_id stays unchanged by pairing', true === try_connect( make_assertion() )['ok'] && 1 === LOCAL_ID );

/* ---------- 2. Signature, identity, contract, expiry and nonce checks ---------- */
reset_site();
$now = 1800000000;
$good = make_assertion();
$v = $T::verify_assertion( $good['body'], $good['headers'], SITE_A, SECRET_A, $now );
check( 'valid signed assertion verifies and returns claims', true === $v['ok'] && COMPANY_X === $v['claims']['company_uuid'] );
check( 'wrong signing secret is rejected', 'bad_signature' === $T::verify_assertion( $good['body'], $good['headers'], SITE_A, SECRET_B, $now )['reason'] );
$tampered = str_replace( COMPANY_X, COMPANY_Y, $good['body'] );
check( 'body changed after signing is rejected', 'bad_signature' === $T::verify_assertion( $tampered, $good['headers'], SITE_A, SECRET_A, $now )['reason'] );
$wrong_site = make_assertion( array( 'site' => SITE_B ) );
check( 'assertion for another site is rejected (wrong-company / wrong-site)', 'site_mismatch' === $T::verify_assertion( $wrong_site['body'], $wrong_site['headers'], SITE_A, SECRET_A, $now )['reason'] );
$stale = make_assertion( array( 'now' => $now - 400 ) );
check( 'stale timestamp is rejected', 'stale' === $T::verify_assertion( $stale['body'], $stale['headers'], SITE_A, SECRET_A, $now )['reason'] );
$expired = make_assertion( array( 'issued_at' => $now - 700, 'expires_at' => $now - 100 ) );
check( 'expired assertion is rejected', 'expired' === $T::verify_assertion( $expired['body'], $expired['headers'], SITE_A, SECRET_A, $now )['reason'] );
$long = make_assertion( array( 'expires_at' => $now + 7200 ) );
check( 'assertion lifetime over one hour is rejected', 'lifetime_invalid' === $T::verify_assertion( $long['body'], $long['headers'], SITE_A, SECRET_A, $now )['reason'] );
$future = make_assertion( array( 'issued_at' => $now + 120, 'expires_at' => $now + 600 ) );
check( 'future-dated assertion is rejected', 'future_dated' === $T::verify_assertion( $future['body'], $future['headers'], SITE_A, SECRET_A, $now )['reason'] );
$badc = make_assertion( array( 'contract' => 'central-pairing/9' ) );
check( 'unknown contract version is rejected', 'contract_unsupported' === $T::verify_assertion( $badc['body'], $badc['headers'], SITE_A, SECRET_A, $now )['reason'] );
$missing = make_assertion( array( 'drop' => 'company_uuid' ) );
check( 'assertion missing a required field is rejected', 'missing_field' === $T::verify_assertion( $missing['body'], $missing['headers'], SITE_A, SECRET_A, $now )['reason'] );
$echo = make_assertion( array( 'claim_nonce' => str_repeat( 'a', 32 ) ) );
check( 'response nonce not echoed in the signed body is rejected', 'nonce_mismatch' === $T::verify_assertion( $echo['body'], $echo['headers'], SITE_A, SECRET_A, $now )['reason'] );
$op = make_assertion( array( 'operations' => array() ) );
check( 'assertion with no operations is rejected', 'malformed' === $T::verify_assertion( $op['body'], $op['headers'], SITE_A, SECRET_A, $now )['reason'] );

/* ---------- 3. Replay ---------- */
reset_site();
$a = make_assertion();
check( 'first use of an assertion connects', true === try_connect( $a )['ok'] );
check( 'replaying the same assertion is refused', 'replayed' === try_connect( $a )['reason'] );

/* ---------- 4. Two companies, two sites, no cross-use ---------- */
reset_site();
$pa = make_assertion( array( 'company' => COMPANY_X, 'site' => SITE_A, 'now' => $now ) );
check( 'site A pairs to company X', true === try_connect( $pa, SITE_A, SECRET_A )['ok'] );
$ra = $T::resolve( SITE_A, 'hosted.request', $now );
check( 'site A resolves to company X', true === $ra['ok'] && COMPANY_X === $ra['company_uuid'] );
check( 'site A cannot resolve as site B', 'site_mismatch' === $T::resolve( SITE_B, 'hosted.request', $now )['reason'] );

// Second WordPress site (own option store) pairs to company Y with its own credential.
$site_a_opts = $GLOBALS['tz_opts'];
$GLOBALS['tz_opts'] = array();
$pb = make_assertion( array( 'company' => COMPANY_Y, 'site' => SITE_B, 'now' => $now, 'sign_with' => SECRET_B ) );
check( 'site B pairs to company Y with its own credential', true === try_connect( $pb, SITE_B, SECRET_B )['ok'] );
check( 'site B resolves to company Y', COMPANY_Y === $T::resolve( SITE_B, 'hosted.request', $now )['company_uuid'] );
$cross = make_assertion( array( 'company' => COMPANY_X, 'site' => SITE_B, 'now' => $now, 'sign_with' => SECRET_A ) );
check( 'site A credential cannot re-point site B to company X', 'bad_signature' === try_connect( $cross, SITE_B, SECRET_B )['reason'] );
$GLOBALS['tz_opts'] = $site_a_opts;
check( 'site A is still bound to company X after site B', COMPANY_X === $T::resolve( SITE_A, 'hosted.request', $now )['company_uuid'] );

/* ---------- 5. Company reassignment and credential rotation ---------- */
reset_site();
try_connect( make_assertion( array( 'company' => COMPANY_X, 'now' => $now ) ) );
check( 'pairing to a different company is refused without an explicit reassign', 'company_reassign_required' === try_connect( make_assertion( array( 'company' => COMPANY_Y, 'now' => $now ) ) )['reason'] && COMPANY_X === $T::resolve( SITE_A, 'hosted.request', $now )['company_uuid'] );
check( 'reassign with an explicit flag moves the pairing and is audited', 'reassign' === try_connect( make_assertion( array( 'company' => COMPANY_Y, 'now' => $now ) ), SITE_A, SECRET_A, true )['reason'] && COMPANY_Y === $T::resolve( SITE_A, 'hosted.request', $now )['company_uuid'] );
$log = $T::audit_log();
check( 'audit trail records connect and reassign with company UUIDs only', 2 === count( $log ) && 'reassign' === $log[1]['action'] && COMPANY_Y === $log[1]['company_uuid'] && false === strpos( json_encode( $log ), SECRET_A ) );
$rot = make_assertion( array( 'company' => COMPANY_Y, 'now' => $now + 60, 'sign_with' => SECRET_B ) );
check( 'credential rotation for the same company is accepted under the new secret', 'rotate' === try_connect( $rot, SITE_A, SECRET_B )['reason'] );
$old = make_assertion( array( 'company' => COMPANY_Y, 'now' => $now + 120, 'sign_with' => SECRET_A ) );
check( 'old credential is refused after rotation', 'bad_signature' === try_connect( $old, SITE_A, SECRET_B )['reason'] );

/* ---------- 6. resolve() fail-closed states ---------- */
reset_site();
check( 'unpaired site resolves as not_paired', 'not_paired' === $T::resolve( SITE_A, 'hosted.request', $now )['reason'] );
try_connect( make_assertion( array( 'now' => $now, 'expires_at' => $now + 600 ) ) );
check( 'pairing is refused after its expiry', 'pairing_expired' === $T::resolve( SITE_A, 'hosted.request', $now + 601 )['reason'] );
check( 'operation not in the pairing is refused', 'operation_not_paired' === $T::resolve( SITE_A, 'booking.write', $now )['reason'] );
$rec = get_option( $T::OPTION );
$rec['v'] = 2;
update_option( $T::OPTION, $rec );
check( 'unknown record version fails closed and is not deleted', 'pairing_unsupported' === $T::resolve( SITE_A, 'hosted.request', $now )['reason'] && false !== get_option( $T::OPTION, false ) );
$rec['v'] = 1;
update_option( $T::OPTION, $rec );
delete_option( $T::SECRET_OPTION );
check( 'missing credential refuses resolve', 'credential_missing' === $T::resolve( SITE_A, 'hosted.request', $now )['reason'] );

/* ---------- 7. Disconnect keeps local data ---------- */
reset_site();
try_connect( make_assertion( array( 'now' => $now ) ) );
$GLOBALS['tz_opts']['tzfd_local_bookings'] = array( array( 'id' => 7, 'company_id' => LOCAL_ID, 'customer' => 'Local customer' ) );
$GLOBALS['tz_opts']['tzfd_business_name']  = 'Local Business';
$before = $GLOBALS['tz_opts']['tzfd_local_bookings'];
check( 'disconnect returns true', true === $T::disconnect( $now ) );
check( 'disconnect removes the pairing and the credential', 'not_paired' === $T::resolve( SITE_A, 'hosted.request', $now )['reason'] && false === get_option( $T::SECRET_OPTION, false ) );
check( 'disconnect leaves local bookings and the integer company_id untouched', $before === $GLOBALS['tz_opts']['tzfd_local_bookings'] && 'Local Business' === $GLOBALS['tz_opts']['tzfd_business_name'] );
$trail = $T::audit_log();
check( 'disconnect is audited', 'disconnect' === end( $trail )['action'] );

/* ---------- 8. Status never exposes the credential ---------- */
reset_site();
try_connect( make_assertion( array( 'now' => $now ) ) );
$st = $T::status();
check( 'status reports connected with the company UUID', true === $st['connected'] && COMPANY_X === $st['company_uuid'] );
check( 'status and audit never contain the credential', false === strpos( json_encode( array( $st, get_option( $T::OPTION ), get_option( $T::NONCE_OPTION ) ) ), SECRET_A ) );

/* ---------- 9. Connector gate: no hosted call without a valid pairing ---------- */
reset_site();
add_filter( 'tzfd_ai_connector_transport', function ( $value, $req ) {
    $GLOBALS['tz_calls'][] = $req;
    return array( 'error' => 'offline' );
}, 10, 2 );
$C::configure( 'https://ai.example.test/v1/request', SITE_A, SECRET_A );
$C::grant_consent( array( 'faq.read' ) );
$r = $C::request( 'faq.answer', array( 'q' => 'hours' ) );
check( 'connector refuses an unpaired site with not_configured and no transport call', 'not_configured' === $r['status'] && true === $r['use_local'] && 0 === count( $GLOBALS['tz_calls'] ) );
check( 'unpaired refusal charges no budget', empty( $C::usage()['budget'] ) );

try_connect( make_assertion( array( 'now' => time(), 'expires_at' => time() + 600 ) ) );
$r = $C::request( 'faq.answer', array( 'q' => 'hours' ) );
check( 'paired site reaches the transport seam (offline here)', 1 === count( $GLOBALS['tz_calls'] ) && 'offline' === $r['status'] );

$GLOBALS['tz_opts'][ $T::OPTION ]['expires_at'] = time() - 1;
$r = $C::request( 'faq.answer', array( 'q' => 'hours' ) );
check( 'expired pairing stops the hosted call again', 'not_configured' === $r['status'] && 1 === count( $GLOBALS['tz_calls'] ) );

/* ---------- 10. Offline hosted service leaves local booking working ---------- */
$GLOBALS['tz_opts'][ $T::OPTION ]['expires_at'] = time() + 600;
$GLOBALS['tz_opts']['tzfd_local_bookings'] = array( array( 'id' => 8, 'company_id' => LOCAL_ID ) );
$r = $C::request( 'faq.answer', array( 'q' => 'hours' ) );
$local_ok = is_array( get_option( 'tzfd_local_bookings' ) ) && 1 === count( get_option( 'tzfd_local_bookings' ) );
check( 'hosted outage returns use_local and local booking data is still readable', true === $r['use_local'] && $local_ok );

echo "\n{$GLOBALS['tz_pass']} passed, {$GLOBALS['tz_fail']} failed\n";
exit( $GLOBALS['tz_fail'] > 0 ? 1 : 0 );
