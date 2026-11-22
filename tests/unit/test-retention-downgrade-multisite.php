<?php
/**
 * Issue #364: acceptance tests for data retention, multi-site scheduling and downgrade.
 * Pure PHP, run by tests/unit/run.php. No WordPress: the option store is the in-memory stub in wp-stubs.php.
 */
require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-multi-location.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-services.php';
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-ai-connector.php';

/* ---------- Multi-site scheduling (TZFD_Multi_Location) ---------- */

/** Unix time on Monday 2026-11-23 (UTC) at the given hour and minute. */
function tzfd_ret_mon( $hour, $minute = 0 ) {
	return gmmktime( $hour, $minute, 0, 11, 23, 2026 );
}

/** Two sites of one company, open 09:00 to 17:00 every day, UTC. */
function tzfd_ret_sites() {
	$hours = array_fill_keys( range( 1, 7 ), array( array( 540, 1020 ) ) );
	return array(
		array( 'id' => 'site-a', 'company_id' => 'co-1', 'timezone' => 'UTC', 'weekly_hours' => $hours, 'closed_dates' => array() ),
		array( 'id' => 'site-b', 'company_id' => 'co-1', 'timezone' => 'UTC', 'weekly_hours' => $hours, 'closed_dates' => array() ),
	);
}

/** A verified request for site-a, 10:00 to 11:00, with two candidate staff. */
function tzfd_ret_request( array $over = array() ) {
	return array_merge( array(
		'company_id' => 'co-1', 'verified_company_id' => 'co-1', 'snapshot_company_id' => 'co-1',
		'location_id' => 'site-a', 'start_utc' => tzfd_ret_mon( 10 ), 'end_utc' => tzfd_ret_mon( 11 ),
		'snapshot_complete' => true, 'sites_verified' => true, 'staff_schedule_verified' => true,
		'candidates_verified' => true, 'candidate_staff_ids' => array( 'st-1', 'st-2' ),
	), $over );
}

/** Symmetric travel buffers (minutes) between the two sites. */
function tzfd_ret_travel( $minutes = 20 ) {
	return array( 'site-a' => array( 'site-b' => $minutes ), 'site-b' => array( 'site-a' => $minutes ) );
}

function tzfd_test_multi_site_eligibility() {
	$P = 'TZFD_Multi_Location';

	// Open slot with no assignments: both staff eligible, flags say the result is read-only and must be re-checked on commit.
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), array(), tzfd_ret_travel() );
	tzfd_assert_same( true, $out['site_open'], 'multi-site: open slot is open' );
	tzfd_assert_same( array( 'st-1', 'st-2' ), $out['eligible_staff_ids'], 'multi-site: both free staff eligible' );
	tzfd_assert_same( null, $out['reason'], 'multi-site: no reason for an open slot' );
	tzfd_assert( true === $out['read_only'] && true === $out['requires_commit_revalidation'], 'multi-site: result is read-only and needs commit revalidation' );
	tzfd_assert_same( 1, $out['schema_version'], 'multi-site: schema version 1' );

	// Site hours: before opening and across midnight are refused by reason, not by an exception.
	$out = $P::evaluate( tzfd_ret_request( array( 'start_utc' => tzfd_ret_mon( 7 ), 'end_utc' => tzfd_ret_mon( 8 ) ) ), tzfd_ret_sites(), array(), tzfd_ret_travel() );
	tzfd_assert_same( 'outside_site_hours', $out['reason'], 'multi-site: slot before opening is refused' );
	tzfd_assert_same( array(), $out['eligible_staff_ids'], 'multi-site: no staff eligible outside hours' );
	$out = $P::evaluate( tzfd_ret_request( array( 'start_utc' => tzfd_ret_mon( 23, 30 ), 'end_utc' => tzfd_ret_mon( 24, 30 ) ) ), tzfd_ret_sites(), array(), tzfd_ret_travel() );
	tzfd_assert_same( 'crosses_local_midnight', $out['reason'], 'multi-site: booking across local midnight is refused' );

	// A closed date at the requested site refuses the slot.
	$sites = tzfd_ret_sites();
	$sites[0]['closed_dates'] = array( '2026-11-23' );
	$out = $P::evaluate( tzfd_ret_request(), $sites, array(), tzfd_ret_travel() );
	tzfd_assert_same( 'site_closed', $out['reason'], 'multi-site: closed date refuses the slot' );

	// Overlapping assignment at the same site excludes only that staff member.
	$same = array( array( 'staff_id' => 'st-1', 'location_id' => 'site-a', 'company_id' => 'co-1', 'start_utc' => tzfd_ret_mon( 10, 30 ), 'end_utc' => tzfd_ret_mon( 11, 30 ) ) );
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $same, tzfd_ret_travel() );
	tzfd_assert_same( array( 'st-2' ), $out['eligible_staff_ids'], 'multi-site: overlapping staff excluded, other staff kept' );

	// Back-to-back at the same site (ends exactly at the start) is allowed.
	$touch = array( array( 'staff_id' => 'st-1', 'location_id' => 'site-a', 'company_id' => 'co-1', 'start_utc' => tzfd_ret_mon( 9 ), 'end_utc' => tzfd_ret_mon( 10 ) ) );
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $touch, tzfd_ret_travel() );
	tzfd_assert_same( array( 'st-1', 'st-2' ), $out['eligible_staff_ids'], 'multi-site: back-to-back at the same site is allowed' );

	// Travel buffer from another site, before the booking: 09:50 end plus 20 minutes is too late; plus 5 is fine.
	$before = array( array( 'staff_id' => 'st-1', 'location_id' => 'site-b', 'company_id' => 'co-1', 'start_utc' => tzfd_ret_mon( 8 ), 'end_utc' => tzfd_ret_mon( 9, 50 ) ) );
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $before, tzfd_ret_travel( 20 ) );
	tzfd_assert_same( array( 'st-2' ), $out['eligible_staff_ids'], 'multi-site: travel from another site blocks a tight handover' );
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $before, tzfd_ret_travel( 5 ) );
	tzfd_assert_same( array( 'st-1', 'st-2' ), $out['eligible_staff_ids'], 'multi-site: enough travel time allows the handover' );

	// Travel buffer after the booking uses the reverse direction (site-a to site-b).
	$after = array( array( 'staff_id' => 'st-1', 'location_id' => 'site-b', 'company_id' => 'co-1', 'start_utc' => tzfd_ret_mon( 11, 30 ), 'end_utc' => tzfd_ret_mon( 12, 30 ) ) );
	$travel = array( 'site-a' => array( 'site-b' => 40 ), 'site-b' => array( 'site-a' => 20 ) );
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $after, $travel );
	tzfd_assert_same( array( 'st-2' ), $out['eligible_staff_ids'], 'multi-site: reverse-direction buffer blocks a later job when too short' );
	$travel['site-a']['site-b'] = 20;
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $after, $travel );
	tzfd_assert_same( array( 'st-1', 'st-2' ), $out['eligible_staff_ids'], 'multi-site: reverse-direction buffer allows a later job when long enough' );

	// Missing travel data fails closed for a staff member who would need it.
	$out = $P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $before, array( 'site-a' => array(), 'site-b' => array() ) );
	tzfd_assert_same( array( 'st-2' ), $out['eligible_staff_ids'], 'multi-site: missing travel minutes fail closed' );

	// Refusals that must throw: incomplete or foreign snapshots, bad sites, bad assignments.
	tzfd_throws( function () use ( $P ) { $P::evaluate( tzfd_ret_request( array( 'snapshot_complete' => false ) ), tzfd_ret_sites(), array(), tzfd_ret_travel() ); }, 'multi-site: incomplete snapshot throws' );
	tzfd_throws( function () use ( $P ) { $P::evaluate( tzfd_ret_request( array( 'snapshot_company_id' => 'co-2' ) ), tzfd_ret_sites(), array(), tzfd_ret_travel() ); }, 'multi-site: snapshot of another company throws' );
	tzfd_throws( function () use ( $P ) { $P::evaluate( tzfd_ret_request( array( 'location_id' => 'site-z' ) ), tzfd_ret_sites(), array(), tzfd_ret_travel() ); }, 'multi-site: unknown location throws' );
	tzfd_throws( function () use ( $P ) {
		$sites = tzfd_ret_sites();
		$sites[1]['company_id'] = 'co-2';
		$P::evaluate( tzfd_ret_request(), $sites, array(), tzfd_ret_travel() );
	}, 'multi-site: foreign site in the snapshot throws' );
	tzfd_throws( function () use ( $P ) {
		$foreign = array( array( 'staff_id' => 'st-1', 'location_id' => 'site-a', 'company_id' => 'co-2', 'start_utc' => tzfd_ret_mon( 8 ), 'end_utc' => tzfd_ret_mon( 9 ) ) );
		$P::evaluate( tzfd_ret_request(), tzfd_ret_sites(), $foreign, tzfd_ret_travel() );
	}, 'multi-site: cross-company assignment throws' );
	tzfd_throws( function () use ( $P ) { $P::evaluate( tzfd_ret_request( array( 'end_utc' => tzfd_ret_mon( 10 ) + 90000 ) ), tzfd_ret_sites(), array(), tzfd_ret_travel() ); }, 'multi-site: booking longer than 24 hours throws' );
	tzfd_throws( function () use ( $P ) { $P::evaluate( tzfd_ret_request( array( 'candidate_staff_ids' => array( 'st-1', 'st-1' ) ) ), tzfd_ret_sites(), array(), tzfd_ret_travel() ); }, 'multi-site: duplicate candidate throws' );
}

/* ---------- Downgrade (TZFD_AI_Services) ---------- */

function tzfd_test_downgrade_keeps_local_data() {
	$S = 'TZFD_AI_Services';
	$levels = array( $S::LEVEL_FREE, $S::LEVEL_ASSISTANT, $S::LEVEL_AGENT_TEAM );
	$rank   = array( $S::LEVEL_FREE => 0, $S::LEVEL_ASSISTANT => 1, $S::LEVEL_AGENT_TEAM => 2 );

	foreach ( $levels as $from ) {
		foreach ( $levels as $to ) {
			$d = $S::downgrade_actions( $from, $to );
			// Local data survives every change of level, and no local feature is switched off.
			tzfd_assert_same( $S::PRESERVED_LOCAL, $d['preserved'], "downgrade $from -> $to keeps every local data set" );
			tzfd_assert_same( array(), $d['disabled_local'], "downgrade $from -> $to disables no local feature" );
			if ( $rank[ $to ] >= $rank[ $from ] ) {
				tzfd_assert_same( array(), $d['stopped_remote'], "change $from -> $to stops no remote work" );
			}
		}
	}

	tzfd_assert_same( array( 'agent_schedules_and_queued_agent_tasks', 'assistant_conversations_and_drafts_requests' ), $S::downgrade_actions( $S::LEVEL_AGENT_TEAM, $S::LEVEL_FREE )['stopped_remote'], 'agent team to free stops agent and assistant remote work' );
	tzfd_assert_same( array( 'agent_schedules_and_queued_agent_tasks' ), $S::downgrade_actions( $S::LEVEL_AGENT_TEAM, $S::LEVEL_ASSISTANT )['stopped_remote'], 'agent team to assistant stops agent work only' );
	tzfd_assert_same( array( 'assistant_conversations_and_drafts_requests' ), $S::downgrade_actions( $S::LEVEL_ASSISTANT, $S::LEVEL_FREE )['stopped_remote'], 'assistant to free stops assistant work only' );

	foreach ( array(
		'bookings', 'customers', 'reminders', 'faq_knowledge', 'local_rules', 'local_reports', 'privacy_exports', 'local_audit_log',
	) as $item ) {
		tzfd_assert( in_array( $item, $S::PRESERVED_LOCAL, true ), "local data set '$item' is named as preserved" );
	}
}

/* ---------- Data retention (TZFD_AI_Connector event log) ---------- */

function tzfd_test_retention_window_and_pruning() {
	$C = 'TZFD_AI_Connector';
	$day = 86400;

	// Default and clamping of the stored value.
	$GLOBALS['tzfd_test_options'] = array();
	tzfd_assert_same( 30, $C::retention_days(), 'retention: default is 30 days' );
	tzfd_assert_same( true, $C::set_retention_days( 1 ), 'retention: 1 day is accepted' );
	tzfd_assert_same( true, $C::set_retention_days( 365 ), 'retention: 365 days is accepted' );
	tzfd_assert_same( 365, $C::retention_days(), 'retention: stored 365 is read back' );
	foreach ( array( 0, -3, 366, '7', 7.0, null ) as $bad ) {
		tzfd_assert_same( false, $C::set_retention_days( $bad ), 'retention: rejects ' . var_export( $bad, true ) );
	}
	tzfd_assert_same( 365, $C::retention_days(), 'retention: rejected values leave the setting unchanged' );
	$GLOBALS['tzfd_test_options'][ $C::OPTION ] = array( 'retention_days' => 9999 );
	tzfd_assert_same( 365, $C::retention_days(), 'retention: an out-of-range stored value is capped' );
	$GLOBALS['tzfd_test_options'][ $C::OPTION ] = array( 'retention_days' => -5 );
	tzfd_assert_same( 1, $C::retention_days(), 'retention: a negative stored value is floored at 1' );

	// Pruning on write: events older than the window are removed, newer ones kept, the new one appended.
	$log = new ReflectionMethod( $C, 'log_event' );
	$log->setAccessible( true ); // PHP 7.4 needs this to call a private method; a no-op on 8.1 and later.
	$now = time();
	$GLOBALS['tzfd_test_options'] = array(
		$C::OPTION       => array( 'retention_days' => 30 ),
		$C::EVENT_OPTION => array(
			array( 'at' => $now - 31 * $day, 'feature' => 'faq', 'status' => 'ok', 'latency_ms' => 1 ),
			array( 'at' => $now - 29 * $day, 'feature' => 'faq', 'status' => 'ok', 'latency_ms' => 2 ),
		),
	);
	$log->invoke( null, 'faq', 'ok', 7 );
	$ats = array_column( $C::events(), 'at' );
	tzfd_assert_same( 2, count( $ats ), 'retention: 30-day window keeps one old event and the new event' );
	tzfd_assert( ! in_array( $now - 31 * $day, $ats, true ), 'retention: event older than the window is deleted' );
	tzfd_assert( in_array( $now - 29 * $day, $ats, true ), 'retention: event inside the window is kept' );
	tzfd_assert( ! array_key_exists( $C::LOCK_OPTION, $GLOBALS['tzfd_test_options'] ), 'retention: write releases its lock' );

	// A shorter window prunes more.
	$C::set_retention_days( 1 );
	$GLOBALS['tzfd_test_options'][ $C::EVENT_OPTION ] = array(
		array( 'at' => $now - 2 * $day, 'feature' => 'faq', 'status' => 'ok', 'latency_ms' => 1 ),
		array( 'at' => $now - 3600, 'feature' => 'faq', 'status' => 'ok', 'latency_ms' => 1 ),
	);
	$log->invoke( null, 'faq', 'fallback_local', 3 );
	$events = $C::events();
	tzfd_assert_same( 2, count( $events ), 'retention: 1-day window keeps only the recent event and the new one' );
	tzfd_assert_same( 'fallback_local', $events[1]['status'], 'retention: new event is appended last' );

	// The log is bounded by EVENT_LIMIT even inside the window.
	$full = array();
	for ( $i = 0; $i < 60; $i++ ) {
		$full[] = array( 'at' => $now - 60 + $i, 'feature' => 'faq', 'status' => 'ok', 'latency_ms' => 1 );
	}
	$GLOBALS['tzfd_test_options'][ $C::EVENT_OPTION ] = $full;
	$log->invoke( null, 'faq', 'ok', 1 );
	tzfd_assert_same( $C::EVENT_LIMIT, count( $C::events() ), 'retention: log is capped at EVENT_LIMIT entries' );
}
