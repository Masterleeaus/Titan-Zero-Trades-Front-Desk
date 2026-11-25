<?php
/**
 * Offline 100 / 1k / 10k tenant simulation for the optional hosted AI connector (issue #43).
 *
 * Run: php tests/ai-connector/cost-simulation-test.php
 *      TZFD_SIM_PRICES="0.5,1.5,0.1" php tests/ai-connector/cost-simulation-test.php   (optional, per 1,000 tokens)
 *
 * Each simulated tenant is a separate site with its own seeded workload, pushed through the real
 * TZFD_AI_Connector request path (budget reservation, signing, reply verification, metering). The hosted
 * service is a local fake transport; no network and no provider call is made.
 *
 * The workload numbers are ASSUMPTIONS for shaping the simulation, not measurements from pilot sites.
 * No money figure is printed unless TZFD_SIM_PRICES supplies a complete price table, and any figure printed
 * with such a table is an illustration of the arithmetic, not a measured or verified cost per site.
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tz_opts']    = array();
$GLOBALS['tz_filters'] = array();
$GLOBALS['sim_usage']  = array();
$GLOBALS['sim_site']   = '';
$GLOBALS['sim_calls']  = 0;

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

const SIM_SECRET = 'simulation-secret-0123456789abcdef0123456789';

$GLOBALS['sim_fail'] = 0;
$GLOBALS['sim_pass'] = 0;
function sim_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['sim_pass']++; } else { $GLOBALS['sim_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

/** Signed, well-formed hosted reply for the request that was just sent. */
function sim_reply( array $req, $site, array $usage ) {
    $ts    = (string) time();
    $nonce = $req['headers']['X-TZFD-Nonce'];
    $body  = json_encode( array(
        'contract' => 'tzfd-ai/1', 'site' => $site, 'status' => 'ok',
        'data'     => array( 'answer' => 'simulated' ), 'usage' => $usage,
    ) );
    return array(
        'status_code' => 200,
        'headers'     => array(
            'X-TZFD-Timestamp' => $ts,
            'X-TZFD-Nonce'     => $nonce,
            'X-TZFD-Signature' => TZFD_AI_Connector::sign( SIM_SECRET, $ts, $nonce, $body ),
        ),
        'body' => $body,
    );
}

// Fake hosted service: counts transport calls and answers with the workload's usage for this request.
add_filter( 'tzfd_ai_connector_transport', function ( $value, $req ) {
    $GLOBALS['sim_calls']++;
    return sim_reply( $req, $GLOBALS['sim_site'], $GLOBALS['sim_usage'] );
}, 10, 2 );

/** Deterministic per-tenant workload (seeded), returned as a list of requests. */
function sim_workload( $tenant_index ) {
    mt_srand( 1000003 * ( $tenant_index + 1 ) );
    $plan = array();
    // Answer requests: 20 to 260 per month. The 200 model-fallback budget is reached by heavier tenants.
    $answers = mt_rand( 20, 260 );
    for ( $i = 0; $i < $answers; $i++ ) {
        $plan[] = array( 'feature' => 'faq.answer', 'hit' => mt_rand( 1, 100 ) <= 30, 'in' => mt_rand( 300, 800 ), 'out' => mt_rand( 50, 200 ) );
    }
    $embeds = mt_rand( 10, 120 );
    for ( $i = 0; $i < $embeds; $i++ ) {
        $plan[] = array( 'feature' => 'faq.embed_query', 'hit' => false, 'emb' => mt_rand( 20, 120 ) );
    }
    $batches = mt_rand( 0, 4 );
    for ( $i = 0; $i < $batches; $i++ ) {
        $plan[] = array( 'feature' => 'faq.suggest_batch', 'hit' => false, 'in' => 900, 'out' => 400 );
    }
    if ( mt_rand( 1, 100 ) <= 50 ) {
        $plan[] = array( 'feature' => 'insight.monthly', 'hit' => false, 'in' => 1200, 'out' => 300 );
    }
    return $plan;
}

/** Runs one tenant through the connector and returns the monthly cost report plus local counters. */
function sim_run_tenant( $tenant_index ) {
    $site = 'sim-site-' . $tenant_index;
    $GLOBALS['tz_opts']   = array();
    $GLOBALS['sim_calls'] = 0;
    // Issue #376: each simulated site is paired to its own Central company before any hosted call.
    $now   = time();
    $nonce = bin2hex( random_bytes( 16 ) );
    $pbody = json_encode( array(
        'contract' => 'central-pairing/1', 'site' => $site,
        'company_uuid' => sprintf( '00000000-0000-4000-8000-%012d', $tenant_index + 1 ),
        'operations' => array( 'hosted.request' ), 'nonce' => $nonce, 'issued_at' => $now, 'expires_at' => $now + 600,
    ) );
    TZFD_Central_Tenant::connect( $pbody, array(
        'X-TZFD-Timestamp' => (string) $now, 'X-TZFD-Nonce' => $nonce,
        'X-TZFD-Signature' => TZFD_Central_Tenant::sign( SIM_SECRET, (string) $now, $nonce, $pbody ),
    ), $site, SIM_SECRET, $now );
    TZFD_AI_Connector::configure( 'https://ai.example.test/v1/request', $site, SIM_SECRET );
    TZFD_AI_Connector::grant_consent( array( 'faq.read', 'reports.read' ) );
    TZFD_AI_Connector::set_entitlements( array( 'free_ai' ) );

    $outcomes = array();
    foreach ( sim_workload( $tenant_index ) as $job ) {
        $GLOBALS['sim_site']  = $site;
        $GLOBALS['sim_usage'] = array(
            'tokens_in'        => isset( $job['in'] ) ? $job['in'] : 0,
            'tokens_out'       => isset( $job['out'] ) ? $job['out'] : 0,
            'embedding_tokens' => isset( $job['emb'] ) ? $job['emb'] : 0,
            'cache_hit'        => $job['hit'],
        );
        $r = TZFD_AI_Connector::request( $job['feature'], array( 'q' => 'simulated' ) );
        $key = $job['feature'] . ':' . $r['status'];
        $outcomes[ $key ] = ( isset( $outcomes[ $key ] ) ? $outcomes[ $key ] : 0 ) + 1;
    }
    return array(
        'report'   => TZFD_AI_Connector::cost_report(),
        'outcomes' => $outcomes,
        'sent'     => $GLOBALS['sim_calls'],
        'usage'    => TZFD_AI_Connector::usage(),
    );
}

// Optional illustrative prices per 1,000 tokens: "input,output,embedding".
$prices = null;
if ( false !== getenv( 'TZFD_SIM_PRICES' ) && '' !== getenv( 'TZFD_SIM_PRICES' ) ) {
    $p = array_map( 'trim', explode( ',', getenv( 'TZFD_SIM_PRICES' ) ) );
    if ( 3 === count( $p ) ) {
        $prices = array( 'input' => $p[0], 'output' => $p[1], 'embedding' => $p[2] );
    }
}

$sizes = array( 100, 1000, 10000 );
$rows  = array();
$limits = TZFD_AI_Connector::budget_limits();

foreach ( $sizes as $size ) {
    $t0 = microtime( true );
    $agg = array( 'calls' => 0, 'ok' => 0, 'cache_hits' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'embedding_tokens' => 0, 'sent' => 0, 'allowance_exhausted' => 0, 'over_budget_tenants' => 0, 'max_model_ok' => 0 );
    $sum_ok_check = 0;
    for ( $t = 0; $t < $size; $t++ ) {
        $run = sim_run_tenant( $t );
        $tot = $run['report']['totals'];
        foreach ( array( 'calls', 'ok', 'cache_hits', 'tokens_in', 'tokens_out', 'embedding_tokens' ) as $k ) {
            $agg[ $k ] += $tot[ $k ];
        }
        $agg['sent'] += $run['sent'];
        $exhausted = 0;
        foreach ( $run['outcomes'] as $key => $n ) {
            if ( false !== strpos( $key, ':allowance_exhausted' ) ) { $exhausted += $n; }
        }
        $agg['allowance_exhausted'] += $exhausted;

        $model_ok = isset( $run['usage']['features']['faq.answer']['ok'] ) ? (int) $run['usage']['features']['faq.answer']['ok'] : 0;
        $agg['max_model_ok'] = max( $agg['max_model_ok'], $model_ok );
        if ( $model_ok > $limits['model_fallback'] ) { $agg['over_budget_tenants']++; }

        $sum_ok_check += $tot['ok'];
    }
    $elapsed = microtime( true ) - $t0;
    $cost    = null === $prices ? null : TZFD_AI_Connector::cost_report( $prices )['cost'];

    $rows[] = array(
        'tenants'            => $size,
        'transport_calls'    => $agg['sent'],
        'successful_calls'   => $agg['ok'],
        'allowance_exhausted' => $agg['allowance_exhausted'],
        'cache_hit_ratio'    => $agg['ok'] > 0 ? round( $agg['cache_hits'] / $agg['ok'], 4 ) : 0.0,
        'tokens_in'          => $agg['tokens_in'],
        'tokens_out'         => $agg['tokens_out'],
        'embedding_tokens'   => $agg['embedding_tokens'],
        'max_answer_ok_per_site' => $agg['max_model_ok'],
        'ceiling_units'      => TZFD_AI_Connector::tenant_ceiling( $size )['max_units_total'],
        'cost_per_month'     => $cost,
        'seconds'            => round( $elapsed, 2 ),
        'over_budget'        => $agg['over_budget_tenants'],
    );

    sim_check( "{$size} tenants: no site exceeds the monthly model-fallback budget", 0 === $agg['over_budget_tenants'], 'limit ' . $limits['model_fallback'] );
    sim_check( "{$size} tenants: successful calls equal the sum of per-site ok counters", $sum_ok_check === $agg['ok'] );
    sim_check( "{$size} tenants: transport calls equal the metered calls (rejected calls never reach the transport)", $agg['sent'] === $agg['calls'] );
}

echo "\nSimulation (assumed workload per site per month; not pilot measurements)\n";
echo "| Tenants | Transport calls | OK calls | Allowance exhausted | Cache hit ratio | Tokens in | Tokens out | Embedding tokens | Ceiling units | Cost | Seconds |\n";
echo "|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|---:|\n";
foreach ( $rows as $r ) {
    $cost_txt = null === $r['cost_per_month'] ? 'not computed (no price table)' : (string) $r['cost_per_month'];
    echo '| ' . implode( ' | ', array(
        $r['tenants'], $r['transport_calls'], $r['successful_calls'], $r['allowance_exhausted'],
        $r['cache_hit_ratio'], $r['tokens_in'], $r['tokens_out'], $r['embedding_tokens'],
        $r['ceiling_units'], $cost_txt, $r['seconds'],
    ) ) . " |\n";
}

echo "\nPASS {$GLOBALS['sim_pass']}  FAIL {$GLOBALS['sim_fail']}\n";
exit( 0 === $GLOBALS['sim_fail'] ? 0 : 1 );
