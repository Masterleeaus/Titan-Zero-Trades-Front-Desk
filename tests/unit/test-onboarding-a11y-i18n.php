<?php
/**
 * Accessibility, localisation and admin-notice checks for the Preset Setup wizard (issue #368, from #332 item 7).
 *
 * Source-level checks: the wizard renders its markup inside TZFD_Onboarding::render(), which needs a full WordPress
 * site to run. These tests read the addon source and assert the markup rules that keep it usable:
 *   - every visible form control has a visible <label> or an aria-label,
 *   - fieldsets have a legend and headings never skip levels,
 *   - admin notices use a notice-* type and a paragraph, and redirect errors are translated,
 *   - visible text is translated with the plugin text domain.
 * Runs through tests/unit/run.php (the test-*.php glob).
 */

function tzfd_test_onboarding_a11y_i18n() {
	$root   = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	$file   = $root . '/addons/core/onboarding.addon.php';
	$src    = (string) file_get_contents( $file );
	$lines  = explode( "\n", $src );
	$slug   = 'titan-zero-trades-front-desk';

	tzfd_assert( '' !== $src, 'onboarding addon source is readable' );

	/* ---- Accessibility: every visible control has a label ---- */
	// A control is labelled when it has aria-label, or sits inside an open <label>. Open labels are tracked across
	// lines because several wizard fields open a <label> on one echo line and close it on a later line.
	$open_labels    = 0;
	$unlabelled     = array();
	$control_count  = 0;
	foreach ( $lines as $n => $line ) {
		if ( preg_match_all( '/<(input|select|textarea)\b[^>]*>|<label\b|<\/label>/i', $line, $tags, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tags[0] as $t ) {
				$tag = $t[0];
				if ( 0 === stripos( $tag, '<label' ) ) {
					$open_labels++;
					continue;
				}
				if ( 0 === stripos( $tag, '</label>' ) ) {
					$open_labels = max( 0, $open_labels - 1 );
					continue;
				}
				if ( preg_match( '/\btype="hidden"/i', $tag ) ) { continue; }
				$control_count++;
				$has_aria = (bool) preg_match( '/\baria-label=/i', $tag );
				if ( ! $has_aria && $open_labels < 1 ) {
					$unlabelled[] = ( $n + 1 ) . ': ' . trim( substr( $tag, 0, 60 ) );
				}
			}
		}
	}
	tzfd_assert( $control_count >= 20, 'control scan found the wizard form fields (' . $control_count . ')' );
	tzfd_assert( array() === $unlabelled, 'every visible form control has a label or aria-label | ' . implode( ' ; ', array_slice( $unlabelled, 0, 5 ) ) );

	// Every select sits in a label or has aria-label (covered above). Fieldsets must carry a legend.
	$fieldsets = preg_match_all( '/<fieldset\b/i', $src );
	$legends   = preg_match_all( '/<legend\b/i', $src );
	tzfd_assert( $fieldsets > 0 && $fieldsets === $legends, "every fieldset has a legend (fieldsets $fieldsets, legends $legends)" );

	// Decorative icons are hidden from assistive technology; the trade count is announced politely.
	tzfd_assert( (bool) preg_match( '/class="tzfd-vp-icon" aria-hidden="true"/', $src ), 'trade picker icons are aria-hidden' );
	tzfd_assert( false !== strpos( $src, 'class="tzfd-vp-count" aria-live="polite"' ), 'trade count is announced with aria-live=polite' );

	/* ---- Headings: never skip a level ---- */
	$heading_levels = array();
	if ( preg_match_all( '/<h([1-6])\b/i', $src, $hm ) ) {
		$heading_levels = array_map( 'intval', $hm[1] );
	}
	$deep = array_filter( $heading_levels, function ( $l ) { return $l > 3; } );
	tzfd_assert( array() === $deep, 'no heading deeper than h3 in the wizard' );
	$skips = array();
	$prev  = 0;
	foreach ( $heading_levels as $lvl ) {
		if ( $prev && $lvl > $prev + 1 ) { $skips[] = "h$prev to h$lvl"; }
		$prev = $lvl;
	}
	tzfd_assert( array() === $skips, 'heading levels never skip | ' . implode( ', ', $skips ) );

	/* ---- Admin notices ---- */
	$notice_lines = 0;
	$bad_notices  = array();
	foreach ( $lines as $n => $line ) {
		if ( false === strpos( $line, 'class="notice' ) ) { continue; }
		$notice_lines++;
		$typed = (bool) preg_match( '/class="notice notice-(error|warning|success|info)\b/', $line );
		$has_p = false !== strpos( $line, '<p>' );
		if ( ! $typed || ! $has_p ) { $bad_notices[] = ( $n + 1 ); }
	}
	tzfd_assert( $notice_lines >= 2, 'wizard has admin notices to check (' . $notice_lines . ')' );
	tzfd_assert( array() === $bad_notices, 'every admin notice has a notice-* type and a <p> | lines ' . implode( ', ', $bad_notices ) );
	tzfd_assert( (bool) preg_match( '/notice-error"><p>\' \. esc_html\( rawurldecode\( \$setup_error \) \)/', $src ), 'redirect error notice is escaped' );

	// Every redirect error message is translated. A variable is allowed only when it is filled from a validator that
	// returns translated text (missed-call settings_error() or apply_details()), checked below.
	preg_match_all( '/self::go\(\s*\d+\s*,\s*(.+?)\s*\);/', $src, $gm );
	$untranslated = array();
	foreach ( $gm[1] as $arg ) {
		if ( ! preg_match( '/^(__\(|TZFD_Vertical_Registry::message\(|\$err$)/', $arg ) ) { $untranslated[] = $arg; }
	}
	tzfd_assert( count( $gm[1] ) >= 5, 'redirect error calls found (' . count( $gm[1] ) . ')' );
	tzfd_assert( array() === $untranslated, 'every redirect error message is translated | ' . implode( ' ; ', array_slice( $untranslated, 0, 3 ) ) );
	preg_match_all( '/\$err\s*=\s*([A-Za-z_:]+)\(/', $src, $em );
	$err_sources = array_values( array_unique( $em[1] ) );
	sort( $err_sources );
	tzfd_assert( array( 'TZFD_MissedCall::settings_error', 'self::apply_details' ) === $err_sources, 'redirect $err comes only from translated validators | ' . implode( ', ', $err_sources ) );

	// The validators behind those messages return no bare English literal.
	$mc_src   = (string) file_get_contents( $root . '/addons/messaging/missed-call.addon.php' );
	$mc_bare  = array();
	if ( preg_match( '/public static function settings_error\(.*?\n        \}/s', $mc_src, $sm ) ) {
		preg_match_all( '/return\s+(\'[^\']+\'|"[^"]+")\s*;/', $sm[0], $lit );
		$mc_bare = $lit[1];
	}
	tzfd_assert( array() === $mc_bare, 'missed-call settings_error returns only translated messages | ' . implode( ' ; ', array_slice( $mc_bare, 0, 3 ) ) );

	/* ---- Localisation ---- */
	// No English sentence sits bare between HTML tags; visible text goes through a translation function.
	$bare = array();
	foreach ( $lines as $n => $line ) {
		if ( preg_match( '/>([A-Z][^<>\'"]{5,})</', $line, $bm ) ) { $bare[] = ( $n + 1 ) . ': ' . $bm[1]; }
	}
	tzfd_assert( array() === $bare, 'no untranslated English text between tags | ' . implode( ' ; ', array_slice( $bare, 0, 3 ) ) );

	$bare_returns = array();
	preg_match_all( '/return\s+\'([A-Z][^\']*)\'\s*;/', $src, $rm );
	$bare_returns = $rm[1];
	tzfd_assert( array() === $bare_returns, 'wizard returns no bare English message | ' . implode( ' ; ', array_slice( $bare_returns, 0, 3 ) ) );

	// Every translation call uses the plugin text domain.
	$functions = '__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e';
	preg_match_all( '/\b(?:' . $functions . ')\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*\'([^\']*)\'\s*\)/', $src, $dm );
	tzfd_assert( count( $dm[1] ) >= 60, 'wizard has translated strings (' . count( $dm[1] ) . ')' );
	$wrong_domain = array_filter( $dm[1], function ( $d ) use ( $slug ) { return $d !== $slug; } );
	tzfd_assert( array() === $wrong_domain, 'every wizard translation call uses the plugin text domain' );

	// The step headings, buttons and notices of the wizard are translated, not hard-coded.
	tzfd_assert( (bool) preg_match( '/esc_html__\(\s*\'Finish setup\'/', $src ), 'finish button text is translated' );
	tzfd_assert( (bool) preg_match( '/esc_html__\(\s*\'Preset Setup\'/', $src ), 'page title is translated' );
}
