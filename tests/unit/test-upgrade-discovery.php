<?php
function tzfd_test_upgrade_discovery() {
	$D = 'TZFD_Upgrade_Discovery';
	$none = array( 'service_catalogue' => false, 'staff_calendar' => false, 'operating_hours' => false, 'publish_form' => false, 'test_booking' => false, 'view_customer' => false, 'configure_reminders' => false );
	$activated = array_merge( $none, array( 'service_catalogue' => true, 'operating_hours' => true, 'publish_form' => true, 'test_booking' => true ) );
	$fresh = array( 'dismissed' => false, 'shows' => 0, 'next_allowed_at' => null );
	$now = 1800000000;

	// Checklist: all seven first-run steps, with activation from the four core steps.
	$list = $D::checklist( $none );
	tzfd_assert_same( 7, $list['total'], 'first run has seven steps' );
	tzfd_assert( false === $list['activated'] && 0 === $list['completed'], 'nothing done means not activated' );
	$list = $D::checklist( $activated );
	tzfd_assert( true === $list['activated'] && 4 === $list['completed'], 'four core steps activate the site' );
	tzfd_assert( false === $D::checklist( array_merge( $activated, array( 'publish_form' => false ) ) )['activated'], 'missing publish step is not activated' );
	tzfd_throws( function () use ( $D, $none ) { $D::checklist( array_diff_key( $none, array( 'view_customer' => true ) ) ); }, 'missing step fact throws' );
	tzfd_throws( function () use ( $D, $none ) { $D::checklist( array_merge( $none, array( 'upsell_pro' => true ) ) ); }, 'unknown step throws' );
	tzfd_throws( function () use ( $D, $none ) { $D::checklist( array_merge( $none, array( 'view_customer' => 'yes' ) ) ); }, 'non-boolean step throws' );

	// No notice before activation, even when hosted services are live.
	$d = $D::decide( $fresh, $none, $now, true );
	tzfd_assert( false === $d['show'] && 'not_activated' === $d['reason'], 'no notice before activation' );

	// Hosted services are not live: no notice at all, after activation or not.
	$d = $D::decide( $fresh, $activated, $now );
	tzfd_assert( false === $d['show'] && 'hosted_services_not_live' === $d['reason'], 'no notice while the hosted services are not live' );
	tzfd_assert( false === $D::HOSTED_SERVICES_LIVE, 'hosted services flag defaults to off until the backend is tested' );

	// Once live and activated, the notice shows with accurate offers.
	$d = $D::decide( $fresh, $activated, $now, true );
	tzfd_assert( true === $d['show'] && 'eligible' === $d['reason'], 'notice shows after activation when live' );
	$ids = array_map( function ( $o ) { return $o['id']; }, $d['offers'] );
	tzfd_assert_same( array( 'assistant', 'agent_team' ), $ids, 'both optional services are offered' );
	$text = implode( ' ', array_map( function ( $o ) { return $o['description']; }, $d['offers'] ) );
	tzfd_assert( false !== strpos( $text, 'Bookings, calendars, customers and reminders stay in this plugin' ), 'offers say what the free plugin keeps' );
	tzfd_assert( 0 === preg_match( '/\b(pro|licen[cs]e)\b/i', $text ), 'offers do not refer to a Pro plugin or licence' );

	// Dismissal is permanent until the owner clears it.
	$dismissed = $D::dismiss( $fresh );
	$d = $D::decide( $dismissed, $activated, $now, true );
	tzfd_assert( false === $d['show'] && 'dismissed' === $d['reason'], 'dismissed notice stays hidden' );
	$d = $D::decide( $dismissed, $activated, $now + 10 * 365 * 86400, true );
	tzfd_assert( false === $d['show'], 'dismissal does not expire by itself' );

	// Snooze and cap: at most one notice per snooze window, and at most MAX_SHOWS in total.
	$shown_once = $D::record_shown( $fresh, $now );
	tzfd_assert( 1 === $shown_once['shows'] && $now + $D::SNOOZE_SECONDS === $shown_once['next_allowed_at'], 'showing records a 30 day snooze' );
	$d = $D::decide( $shown_once, $activated, $now + 3600, true );
	tzfd_assert( false === $d['show'] && 'snoozed' === $d['reason'], 'notice is snoozed for an hour after showing' );
	$d = $D::decide( $shown_once, $activated, $now + $D::SNOOZE_SECONDS, true );
	tzfd_assert( true === $d['show'], 'notice returns after the snooze window' );
	$shown_twice = $D::record_shown( $shown_once, $now + $D::SNOOZE_SECONDS );
	$d = $D::decide( $shown_twice, $activated, $now + 2 * $D::SNOOZE_SECONDS + 1, true );
	tzfd_assert( false === $d['show'] && 'show_limit_reached' === $d['reason'], 'lifetime cap ends the notices (no nagging)' );

	// Invalid state is rejected, not guessed at.
	tzfd_throws( function () use ( $D, $none, $now ) { $D::decide( array( 'dismissed' => 'no', 'shows' => 0 ), $none, $now, true ); }, 'non-boolean dismissed throws' );
	tzfd_throws( function () use ( $D, $activated ) { $D::decide( array( 'dismissed' => false, 'shows' => -1 ), $activated, 1, true ); }, 'negative show count throws' );
	tzfd_throws( function () use ( $D, $activated, $fresh ) { $D::decide( $fresh, $activated, -5, true ); }, 'negative time throws' );

	// Offers never include a registration requirement.
	$has_registration_gate = false;
	foreach ( $D::offers() as $offer ) {
		if ( isset( $offer['requires_registration'] ) ) { $has_registration_gate = true; }
	}
	tzfd_assert( false === $has_registration_gate, 'no offer requires registration' );
}
