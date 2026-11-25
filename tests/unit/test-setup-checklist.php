<?php
/**
 * Pure tests for the first-run checklist (issue #15): detected versus self-reported steps, completion, and
 * progress recording that needs consent and never overwrites an earlier completion time.
 */
require_once __DIR__ . '/wp-stubs.php';

function tzfd_test_setup_checklist() {
	$C = 'TZFD_Setup_Checklist';
	$steps = $C::steps();

	tzfd_assert_same( 7, count( $steps ), 'checklist has the seven first-run steps' );
	$self = array_keys( array_filter( $steps, function ( $s ) { return 'self' === $s['source']; } ) );
	sort( $self );
	tzfd_assert_same( array( 'form', 'hours', 'reminders' ), $self, 'steps that cannot be detected are self-reported' );

	$none = $C::evaluate( array(), array() );
	tzfd_assert( false === $none['complete'] && 0 === $none['done_count'], 'nothing is done for an empty site' );

	$detected = $C::evaluate( array( 'services' => true, 'calendar' => true, 'test_booking' => true, 'customer' => true ), array() );
	tzfd_assert_same( 4, $detected['done_count'], 'detected steps count as done when the facts say so' );
	tzfd_assert( false === $detected['steps']['hours']['done'] && false === $detected['steps']['reminders']['done'], 'self-reported steps are not done by detection' );

	$manual = $C::evaluate( array(), array( 'hours' => true, 'form' => true, 'reminders' => true ) );
	tzfd_assert( true === $manual['steps']['hours']['done'] && 3 === $manual['done_count'], 'a self-reported step counts once the administrator marks it' );
	tzfd_assert( false === $manual['steps']['services']['done'], 'a manual mark does not complete a detected step' );

	$all = $C::evaluate( array( 'services' => true, 'calendar' => true, 'test_booking' => true, 'customer' => true ), array( 'hours' => true, 'form' => true, 'reminders' => true ) );
	tzfd_assert( true === $all['complete'] && 7 === $all['done_count'] && 7 === $all['total'], 'all seven done is complete' );

	// Progress records: no consent, no writes; consent, first completion time kept.
	$now = 1800000000;
	tzfd_assert_same( array(), $C::record( false, array(), $detected['steps'], $now ), 'without consent nothing is recorded' );
	$first = $C::record( true, array(), $detected['steps'], $now );
	tzfd_assert( 4 === count( $first ) && isset( $first['services'] ) && ! isset( $first['hours'] ), 'with consent only done steps are recorded' );
	$later = $C::record( true, $first, $all['steps'], $now + 500 );
	tzfd_assert( $now === $later['services'] && $now + 500 === $later['hours'], 'an earlier completion time is kept; new steps get their own time' );
	tzfd_assert_same( $first, $C::record( false, $first, $all['steps'], $now + 900 ), 'turning consent off freezes the existing record' );
	tzfd_assert_same( array( 'services' => $now ), $C::record( true, array( 'services' => $now ), array( 'services' => array( 'done' => true ), 'unknown' => array( 'done' => true ) ), $now + 1 ), 'steps not in the catalogue are never recorded' );

	// Setup measures (issue #367): consent-gated, per site, derived only from the stored record.
	$off = $C::metrics( array( 'services' => $now ), false );
	tzfd_assert( false === $off['measured'] && null === $off['completion_percent'] && null === $off['converted'], 'without consent no setup measure is reported' );
	tzfd_assert_same( array(), array_diff( array( 'steps_done', 'completion_percent', 'first_booking_at', 'seconds_to_first_booking', 'converted' ), array_keys( $off ) ), 'the unmeasured shape keeps every key' );

	$none_m = $C::metrics( array(), true );
	tzfd_assert( true === $none_m['measured'] && 0 === $none_m['steps_done'] && 0 === $none_m['completion_percent'], 'consent with no record measures zero completion' );
	tzfd_assert( false === $none_m['complete'] && null === $none_m['first_booking_at'] && false === $none_m['converted'], 'no booking and no completion is not converted' );

	$partial = $C::metrics( array( 'services' => $now, 'calendar' => $now + 10, 'hours' => $now + 20 ), true );
	tzfd_assert( 3 === $partial['steps_done'] && 43 === $partial['completion_percent'], 'three of seven steps is 43 percent' );
	tzfd_assert( null === $partial['seconds_to_first_booking'] && false === $partial['converted'], 'no booking seen means no time to first booking' );

	$booked = $C::metrics( array( 'services' => $now, 'calendar' => $now + 10, 'hours' => $now + 20, 'test_booking' => $now + 300 ), true );
	tzfd_assert( 300 === $booked['seconds_to_first_booking'] && $now + 300 === $booked['first_booking_at'], 'time to first booking runs from the earliest recorded step' );
	tzfd_assert( false === $booked['converted'], 'a booking before setup is complete does not count as converted' );

	$full = array_fill_keys( array_keys( $C::steps() ), $now );
	$full['test_booking'] = $now + 60;
	$conv = $C::metrics( $full, true );
	tzfd_assert( true === $conv['complete'] && 100 === $conv['completion_percent'] && true === $conv['converted'], 'complete setup with a booking is converted' );
	tzfd_assert( 60 === $conv['seconds_to_first_booking'], 'full record gives the time to first booking' );

	$skew = $C::metrics( array( 'services' => $now, 'test_booking' => $now - 5 ), true );
	tzfd_assert( 0 === $skew['seconds_to_first_booking'], 'a booking stamped before the first step is clamped to zero, never negative' );
	tzfd_assert_same( 1, $C::metrics( array( 'services' => $now, 'bogus' => $now ), true )['steps_done'], 'keys outside the catalogue are not counted as steps' );
	// Discovery links (issue #366): off by default, after opt-in and activation only, no registration.
	$active = array( 'services' => true, 'calendar' => false );
	tzfd_assert( false === $C::discovery_visible( null, $active ), 'discovery links are off when the option was never set' );
	tzfd_assert( false === $C::discovery_visible( false, $active ), 'discovery links stay off when the administrator has not opted in' );
	tzfd_assert( false === $C::discovery_visible( 'yes', $active ), 'a truthy non-boolean option does not turn discovery on' );
	tzfd_assert( false === $C::discovery_visible( true, array() ), 'opt-in alone does not show links before the plugin is in use' );
	tzfd_assert( false === $C::discovery_visible( true, array( 'services' => false, 'calendar' => false ) ), 'no service or calendar means the plugin is not yet in use' );
	tzfd_assert( true === $C::discovery_visible( true, $active ), 'opt-in with a service shows the links' );
	tzfd_assert( true === $C::discovery_visible( true, array( 'calendar' => true ) ), 'opt-in with a calendar shows the links' );

	$links = $C::discovery_links();
	tzfd_assert_same( array( 'pro', 'ai', 'agent' ), array_keys( $links ), 'discovery links cover Pro, AI and Agent in that order' );
	tzfd_assert_same( array( 'tzfd-booking-report', 'tzfd-ai-services', 'tzfd-agent-receipts' ), array_column( $links, 'page' ), 'each discovery link points to an existing admin screen' );
	$text = '';
	foreach ( $links as $link ) { $text .= $link['label'] . ' ' . $link['hint'] . ' '; }
	tzfd_assert( false === stripos( $text, 'register' ) && false === stripos( $text, 'sign up' ) && false === stripos( $text, 'buy' ), 'discovery copy never asks for registration or a purchase' );
}

// Run on its own (php tests/unit/<this file>): one PASS or FAIL line per assertion, exit 1 on failure (issue #192).
if ( ! defined( 'TZFD_UNIT_FILE_RUNNER' ) && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === __FILE__ ) {
	require_once __DIR__ . '/tzfd-standalone-runner.php';
	exit( tzfd_standalone_run( __FILE__ ) );
}
