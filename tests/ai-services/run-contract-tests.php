<?php
/**
 * Contract tests for the in-plugin AI service level (issues #45, #46, #48, #49).
 * Run: php -d display_errors=1 tests/ai-services/run-contract-tests.php
 * No WordPress is needed. Fixtures live in tests/ai-services/fixtures/.
 */
define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-services.php';

$GLOBALS['tzfd_ai_pass'] = 0;
$GLOBALS['tzfd_ai_fail'] = 0;

function tzfd_ai_check( $name, $ok, $detail = '' ) {
    if ( $ok ) {
        $GLOBALS['tzfd_ai_pass']++;
    } else {
        $GLOBALS['tzfd_ai_fail']++;
    }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

use TZFD_AI_Services as S;

// 1. Fixture-driven resolution.
$fixtures = glob( __DIR__ . '/fixtures/*.json' );
tzfd_ai_check( 'fixtures present', count( $fixtures ) >= 10, 'count=' . count( $fixtures ) );
foreach ( $fixtures as $file ) {
    $case = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    if ( ! is_array( $case ) ) {
        tzfd_ai_check( basename( $file ) . ' parses', false );
        continue;
    }
    $got = S::resolve( $case['payload'], $case['site_company_id'], $case['now'] );
    $ok  = $got['level'] === $case['expect']['level'] && $got['reason'] === $case['expect']['reason'];
    tzfd_ai_check( $case['name'], $ok, 'got ' . $got['level'] . '/' . $got['reason'] );

    // Local features stay on for every resolved state.
    tzfd_ai_check( $case['name'] . ' keeps local features', S::local_features_enabled( $got ) === true );
}

// 2. Remote-service matrix.
$free  = S::resolve( null );
$asst  = S::resolve( array( 'schema_version' => 1, 'company_id' => 1, 'state' => 'active', 'entitlements' => array( 'assistant' ), 'expires_at' => 1900003600 ), null, 1900000000 );
$agent = S::resolve( array( 'schema_version' => 1, 'company_id' => 1, 'state' => 'active', 'entitlements' => array( 'agent_team' ), 'expires_at' => 1900003600 ), null, 1900000000 );
tzfd_ai_check( 'free: basic AI allowed only as opt-in service', S::remote_service_allowed( S::SERVICE_BASIC_AI, $free ) === true );
tzfd_ai_check( 'free: assistant allowed (no paid level gate)', S::remote_service_allowed( S::SERVICE_ASSISTANT, $free ) === true );
tzfd_ai_check( 'free: agent team allowed (no paid level gate)', S::remote_service_allowed( S::SERVICE_AGENT_TEAM, $free ) === true );
tzfd_ai_check( 'assistant: assistant allowed', S::remote_service_allowed( S::SERVICE_ASSISTANT, $asst ) === true );
tzfd_ai_check( 'assistant: agent team allowed (no paid level gate)', S::remote_service_allowed( S::SERVICE_AGENT_TEAM, $asst ) === true );
tzfd_ai_check( 'agent team includes assistant service', S::remote_service_allowed( S::SERVICE_ASSISTANT, $agent ) === true );
tzfd_ai_check( 'agent team: agent team allowed', S::remote_service_allowed( S::SERVICE_AGENT_TEAM, $agent ) === true );

// 3. Downgrade is data-only for local features.
$down = S::downgrade_actions( S::LEVEL_AGENT_TEAM, S::LEVEL_FREE );
tzfd_ai_check( 'agent to free stops agent work', in_array( 'agent_schedules_and_queued_agent_tasks', $down['stopped_remote'], true ) );
tzfd_ai_check( 'agent to free stops assistant work', in_array( 'assistant_conversations_and_drafts_requests', $down['stopped_remote'], true ) );
tzfd_ai_check( 'downgrade disables no local feature', $down['disabled_local'] === array() );
foreach ( array( 'bookings', 'customers', 'services', 'calendars', 'reminders', 'faq_knowledge', 'local_rules', 'privacy_exports', 'local_audit_log' ) as $item ) {
    tzfd_ai_check( 'downgrade preserves ' . $item, in_array( $item, $down['preserved'], true ) );
}
$step = S::downgrade_actions( S::LEVEL_AGENT_TEAM, S::LEVEL_ASSISTANT );
tzfd_ai_check( 'agent to assistant keeps assistant work', ! in_array( 'assistant_conversations_and_drafts_requests', $step['stopped_remote'], true ) );
tzfd_ai_check( 'assistant to free stops assistant work', in_array( 'assistant_conversations_and_drafts_requests', S::downgrade_actions( S::LEVEL_ASSISTANT, S::LEVEL_FREE )['stopped_remote'], true ) );

// 3b. In-place journey, decision logic only: Free -> Assistant -> Agent Team -> paused -> Assistant -> Free,
// plus cancellation and company switch. Each step checks the level, local features, and that a downgrade keeps local data.
$journey_now  = 1900000000;
$journey_cid  = 1;
$journey_step = function ( $state, array $entitlements, $company = 1, $expires = 1900003600 ) {
    return array( 'schema_version' => 1, 'company_id' => $company, 'state' => $state, 'entitlements' => $entitlements, 'expires_at' => $expires );
};
$j_free      = S::resolve( null, $journey_cid, $journey_now );
$j_assist    = S::resolve( $journey_step( 'active', array( 'assistant' ) ), $journey_cid, $journey_now );
$j_agent     = S::resolve( $journey_step( 'active', array( 'agent_team', 'assistant' ) ), $journey_cid, $journey_now );
$j_paused    = S::resolve( $journey_step( 'paused_agents', array( 'agent_team', 'assistant' ) ), $journey_cid, $journey_now );
$j_back_asst = S::resolve( $journey_step( 'active', array( 'assistant' ) ), $journey_cid, $journey_now );
$j_cancelled = S::resolve( $journey_step( 'cancelled', array( 'assistant' ) ), $journey_cid, $journey_now );
$j_switch    = S::resolve( $journey_step( 'active', array( 'agent_team' ), 2 ), $journey_cid, $journey_now );

$journey_expect = array(
    'free'              => array( $j_free, S::LEVEL_FREE, 'no_entitlement' ),
    'assistant'         => array( $j_assist, S::LEVEL_ASSISTANT, 'active' ),
    'agent team'        => array( $j_agent, S::LEVEL_AGENT_TEAM, 'active' ),
    'paused agents'     => array( $j_paused, S::LEVEL_ASSISTANT, 'paused_agents' ),
    'back to assistant' => array( $j_back_asst, S::LEVEL_ASSISTANT, 'active' ),
    'cancelled to free' => array( $j_cancelled, S::LEVEL_FREE, 'cancelled' ),
    'company switch'    => array( $j_switch, S::LEVEL_FREE, 'company_mismatch' ),
);
foreach ( $journey_expect as $label => $row ) {
    tzfd_ai_check( 'journey: ' . $label . ' resolves', $row[0]['level'] === $row[1] && $row[0]['reason'] === $row[2], 'got ' . $row[0]['level'] . '/' . $row[0]['reason'] );
    tzfd_ai_check( 'journey: ' . $label . ' keeps local features', S::local_features_enabled( $row[0] ) === true );
}

$journey_downs = array(
    'agent to assistant' => S::downgrade_actions( S::LEVEL_AGENT_TEAM, S::LEVEL_ASSISTANT ),
    'assistant to free'  => S::downgrade_actions( S::LEVEL_ASSISTANT, S::LEVEL_FREE ),
    'agent to free'      => S::downgrade_actions( S::LEVEL_AGENT_TEAM, S::LEVEL_FREE ),
);
foreach ( $journey_downs as $label => $down ) {
    tzfd_ai_check( 'journey: ' . $label . ' keeps all local data', array_diff( array( 'bookings', 'customers', 'services', 'calendars', 'reminders', 'faq_knowledge', 'privacy_exports' ), $down['preserved'] ) === array() );
    tzfd_ai_check( 'journey: ' . $label . ' disables no local feature', $down['disabled_local'] === array() );
}
tzfd_ai_check( 'journey: agent to assistant stops agent work only', S::downgrade_actions( S::LEVEL_AGENT_TEAM, S::LEVEL_ASSISTANT )['stopped_remote'] === array( 'agent_schedules_and_queued_agent_tasks' ) );

// 4. Descriptors: single plugin, approved prices only (Free null), Agent Team includes Assistant without its own billing line.
$desc   = S::descriptors();
$levels = isset( $desc['levels'] ) ? $desc['levels'] : array();
tzfd_ai_check( 'descriptor: three levels', count( $levels ) === 3, 'count=' . count( $levels ) );
tzfd_ai_check( 'descriptor: single plugin, no separate licence', ( $desc['single_plugin'] ?? false ) === true && ( $desc['separate_plugin_or_licence'] ?? true ) === false );
tzfd_ai_check( 'descriptor: agent team includes assistant', in_array( 'assistant', $levels['agent_team']['includes'] ?? array(), true ) );
tzfd_ai_check( 'descriptor: agent team is the only billing entitlement', ( $levels['agent_team']['billing_entitlement'] ?? '' ) === 'agent_team' && ! isset( $levels['assistant']['billing_entitlement'] ) );
$prices_ok = array_key_exists( 'price', $levels['free_ai'] ?? array() ) && null === $levels['free_ai']['price'];
$prices_ok = $prices_ok && 29 === ( $levels['assistant']['price'] ?? null ) && 99 === ( $levels['agent_team']['price'] ?? null );
tzfd_ai_check( 'descriptor: approved prices only (Assistant 29, Agent Team 99, Free null)', $prices_ok );
tzfd_ai_check( 'descriptor: local features always enabled', count( array_filter( $levels, static function ( $lvl ) { return ( $lvl['local_features'] ?? '' ) !== 'always_enabled'; } ) ) === 0 );

// 5. Outage (connector unreachable, cache only) and malformed or hostile payloads (issue #340).
// Outage: the cached entitlement is the only input. Remote services stop when it expires, local features never change.
// Malformed: every odd type resolves to Free without a PHP warning and without granting a paid level.
$outage_warnings = 0;
set_error_handler( static function ( $no, $str ) use ( &$outage_warnings ) { $outage_warnings++; return true; } );
$o_now     = 1900000000;
$o_active  = array( 'schema_version' => 1, 'company_id' => 1, 'state' => 'active', 'entitlements' => array( 'agent_team' ), 'expires_at' => $o_now + 600 );
$o_grace   = array( 'schema_version' => 1, 'company_id' => 1, 'state' => 'grace', 'entitlements' => array( 'agent_team' ), 'expires_at' => $o_now + 600 );
$o_expired = array_merge( $o_active, array( 'expires_at' => $o_now - 1 ) );

$o_offline_none = S::resolve( null, 1, $o_now );
tzfd_ai_check( 'outage: never connected stays free', $o_offline_none['level'] === S::LEVEL_FREE && $o_offline_none['reason'] === 'no_entitlement' );
tzfd_ai_check( 'outage: never connected keeps local features', S::local_features_enabled( $o_offline_none ) === true );

$o_within_ttl = S::resolve( $o_active, 1, $o_now + 300 );
tzfd_ai_check( 'outage: cache within TTL keeps agent team', $o_within_ttl['level'] === S::LEVEL_AGENT_TEAM );
tzfd_ai_check( 'outage: grace keeps paid level while connector is down', S::resolve( $o_grace, 1, $o_now )['level'] === S::LEVEL_AGENT_TEAM );

$o_past_ttl = S::resolve( $o_active, 1, $o_now + 600 );
tzfd_ai_check( 'outage: cache at exact expiry drops to free', $o_past_ttl['level'] === S::LEVEL_FREE && $o_past_ttl['reason'] === 'cache_expired' );
tzfd_ai_check( 'outage: expired cache keeps local features', S::local_features_enabled( $o_past_ttl ) === true );
tzfd_ai_check( 'outage: expired cache resolves to free, not agent team', $o_past_ttl['level'] !== S::LEVEL_AGENT_TEAM );
$o_down = S::downgrade_actions( S::LEVEL_AGENT_TEAM, S::LEVEL_FREE );
tzfd_ai_check( 'outage: downgrade on expiry keeps all local data', array_diff( array( 'bookings', 'customers', 'services', 'calendars', 'reminders', 'faq_knowledge', 'local_rules', 'local_reports', 'privacy_exports', 'local_audit_log' ), $o_down['preserved'] ) === array() && $o_down['disabled_local'] === array() );
tzfd_ai_check( 'outage: expired paused-agents cache keeps no assistant', S::resolve( array_merge( $o_active, array( 'state' => 'paused_agents', 'entitlements' => array( 'assistant' ), 'expires_at' => $o_now - 5 ) ), 1, $o_now )['level'] === S::LEVEL_FREE );

// Malformed and hostile payloads.
$m_base = array( 'schema_version' => 1, 'company_id' => 1, 'state' => 'active', 'entitlements' => array( 'agent_team' ), 'expires_at' => $o_now + 600 );
$m_cases = array(
    'payload is a string'            => array( 'garbage', S::LEVEL_FREE, 'no_entitlement' ),
    'payload is an int'              => array( 42, S::LEVEL_FREE, 'no_entitlement' ),
    'state is an array'              => array( array_merge( $m_base, array( 'state' => array( 'active' ) ) ), S::LEVEL_FREE, 'unknown_state' ),
    'entitlements is a bare string'  => array( array_merge( $m_base, array( 'entitlements' => 'agent_team' ) ), S::LEVEL_FREE, 'no_known_entitlement' ),
    'entitlement is nested array'    => array( array_merge( $m_base, array( 'entitlements' => array( array( 'agent_team' ) ) ) ), S::LEVEL_FREE, 'no_known_entitlement' ),
    'entitlement is unknown name'    => array( array_merge( $m_base, array( 'entitlements' => array( 'enterprise' ) ) ), S::LEVEL_FREE, 'no_known_entitlement' ),
    'company id is negative'         => array( array_merge( $m_base, array( 'company_id' => -1 ) ), S::LEVEL_FREE, 'invalid_company' ),
    'schema version is future'       => array( array_merge( $m_base, array( 'schema_version' => 2 ) ), S::LEVEL_FREE, 'unsupported_schema' ),
    'expires_at is not numeric'      => array( array_merge( $m_base, array( 'expires_at' => 'soon' ) ), S::LEVEL_FREE, 'cache_expired' ),
);
foreach ( $m_cases as $label => $row ) {
    $got = S::resolve( $row[0], 1, $o_now );
    tzfd_ai_check( 'malformed: ' . $label . ' resolves to free', $got['level'] === $row[1] && $got['reason'] === $row[2], 'got ' . $got['level'] . '/' . $got['reason'] );
}
tzfd_ai_check( 'malformed: no PHP warning raised by any payload above', $outage_warnings === 0, 'warnings=' . $outage_warnings );
restore_error_handler();

echo "\nPASS {$GLOBALS['tzfd_ai_pass']}  FAIL {$GLOBALS['tzfd_ai_fail']}\n";
exit( $GLOBALS['tzfd_ai_fail'] > 0 ? 1 : 0 );
