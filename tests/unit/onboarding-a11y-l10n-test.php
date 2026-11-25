<?php
/**
 * Accessibility, localisation and admin-notice gate for the setup wizard (issue #368, split from #332 and #15).
 *
 * Static checks on addons/core/onboarding.addon.php, so no WordPress is needed:
 *   php tests/unit/onboarding-a11y-l10n-test.php
 * Exits 1 if any check fails. Runs in the CI unit job, which runs every tests/unit/*.php file.
 */

$root   = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
$domain = 'titan-zero-trades-front-desk';
$file   = $root . '/addons/core/onboarding.addon.php';
$src    = (string) file_get_contents( $file );
$lines  = preg_split( '/\R/', $src );
$fails  = 0;
$passes = 0;
$check  = function ( $ok, $name, $detail = '' ) use ( &$fails, &$passes ) {
	if ( $ok ) {
		$passes++;
	} else {
		$fails++;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
};

/**
 * True when the element that starts at $tag on line $n sits inside a label that is still open at that point.
 * $pattern is a regex for the element. Looks at the previous line too, because the wizard writes one markup block per echo line.
 */
$in_open_label = function ( array $lines, $n, $pattern ) {
	$ctx = ( $n > 0 ? $lines[ $n - 1 ] : '' ) . "\n" . $lines[ $n ];
	if ( ! preg_match( $pattern, $ctx, $m, PREG_OFFSET_CAPTURE ) ) {
		return false;
	}
	$pos = $m[0][1];
	$before = substr( $ctx, 0, $pos );
	$open   = strrpos( $before, '<label' );
	$close  = strrpos( $before, '</label>' );
	return false !== $open && ( false === $close || $open > $close );
};

// ---- Accessibility: every control has an accessible name ----
$unnamed_select = array();
$unnamed_input  = array();
$tables_no_scope = array();
$fieldsets_no_legend = array();
$notices_bad = array();
$selects = 0;
$inputs  = 0;
foreach ( $lines as $n => $line ) {
	$where = 'line ' . ( $n + 1 );
	if ( false !== strpos( $line, '<select' ) ) {
		$selects++;
		if ( ! $in_open_label( $lines, $n, '/<select\b/' ) && false === strpos( $line, 'aria-label=' ) ) {
			$unnamed_select[] = $where;
		}
	}
	if ( preg_match( '/<input type="(text|url|password|number|email)"/', $line ) ) {
		$inputs++;
		if ( ! $in_open_label( $lines, $n, '/<input type="(text|url|password|number|email)"/' ) && false === strpos( $line, 'aria-label=' ) ) {
			$unnamed_input[] = $where;
		}
	}
	if ( preg_match( '/<th(?![^>]*\sscope=)[\s>]/', $line ) ) {
		$tables_no_scope[] = $where;
	}
	if ( false !== strpos( $line, '<fieldset' ) ) {
		$next = $lines[ $n + 1 ] ?? '';
		if ( false === strpos( $line, '<legend' ) && false === strpos( $next, '<legend' ) ) {
			$fieldsets_no_legend[] = $where;
		}
	}
	if ( false !== strpos( $line, 'class="notice ' ) ) {
		if ( ! preg_match( '/class="notice notice-(error|warning|success|info)/', $line ) || false === strpos( $line, '<p>' ) ) {
			$notices_bad[] = $where;
		}
	}
}
$check( $selects >= 3, 'select controls found in the wizard', (string) $selects );
$check( $inputs >= 10, 'text, url, password and number inputs found in the wizard', (string) $inputs );
$check( array() === $unnamed_select, 'every select has a label or aria-label', implode( ', ', $unnamed_select ) );
$check( array() === $unnamed_input, 'every text, url, password and number input has a label or aria-label', implode( ', ', $unnamed_input ) );
$check( array() === $tables_no_scope, 'every table header cell has a scope attribute', implode( ', ', $tables_no_scope ) );
$check( array() === $fieldsets_no_legend, 'every fieldset has a legend', implode( ', ', $fieldsets_no_legend ) );
$check( array() === $notices_bad, 'every notice has a WordPress notice type and a paragraph', implode( ', ', $notices_bad ) );

// ---- Localisation: translatable, positional, commented, escaped, in the plugin text domain ----
$wrong_domain = array();
$translations = 0;
if ( preg_match_all( '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*\'([^\']*)\'\s*\)/', $src, $hits ) ) {
	foreach ( $hits[1] as $d ) {
		$translations++;
		if ( $domain !== $d ) {
			$wrong_domain[] = $d;
		}
	}
}
$check( $translations > 100, 'translation calls found in the wizard', (string) $translations );
$check( array() === $wrong_domain, 'every translation call uses the plugin text domain', implode( ', ', array_unique( $wrong_domain ) ) );

$no_placeholder_order = array();
$no_translator_note   = array();
$raw_translation_out  = array();
foreach ( $lines as $n => $line ) {
	if ( false !== strpos( $line, 'sprintf(' ) ) {
		$placeholders = preg_match_all( '/%(?:\d+\$)?[ds]/', $line );
		if ( $placeholders > 1 && ! preg_match( '/%\d+\$[ds]/', $line ) ) {
			$no_placeholder_order[] = 'line ' . ( $n + 1 );
		}
		if ( false !== strpos( $line, "sprintf( __(" ) && false === strpos( $lines[ $n - 1 ] ?? '', 'translators:' ) ) {
			$no_translator_note[] = 'line ' . ( $n + 1 );
		}
	}
	if ( preg_match( '/echo\b[^;]*\.\s*__\(/', $line ) || preg_match( '/echo\s+__\(/', $line ) ) {
		$raw_translation_out[] = 'line ' . ( $n + 1 );
	}
}
$check( array() === $no_placeholder_order, 'sprintf strings with several values use numbered placeholders', implode( ', ', $no_placeholder_order ) );
$check( array() === $no_translator_note, 'translated sprintf strings have a translators note', implode( ', ', $no_translator_note ) );
$check( array() === $raw_translation_out, 'no translated string is echoed without escaping', implode( ', ', $raw_translation_out ) );

// Visible text in the markup must come from a translation call, not a literal English string.
$literal_text = array();
foreach ( $lines as $n => $line ) {
	if ( false === strpos( $line, 'echo' ) ) {
		continue;
	}
	$stripped = preg_replace( '/\b(?:esc_html__|esc_attr__|__|_e|esc_html_e|esc_attr_e)\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*(?:,\s*\'[^\']*\'\s*)?\)/', '', $line );
	if ( preg_match_all( '/>\s*([^<>\']*[A-Za-z]{3,}[^<>\']*)</', $stripped, $text ) ) {
		foreach ( $text[1] as $t ) {
			if ( trim( $t ) !== '' && ! preg_match( '/^\s*(?:esc_|wp_|sprintf|rest_|admin_|\$)/', $t ) ) {
				$literal_text[] = 'line ' . ( $n + 1 ) . ': ' . trim( $t );
			}
		}
	}
}
$check( array() === $literal_text, 'no visible English text is written without a translation call', implode( ' | ', array_slice( $literal_text, 0, 5 ) ) );

// ---- Admin notices: escaped, capability-gated, and not added as a nag on the wizard's own screen ----
$check( false === strpos( $src, "'admin_notices'" ), 'the wizard does not add a global admin notice' );
$check( (bool) preg_match( "/function render\(\)\s*\{\s*if \( ! current_user_can\( 'tz_fd_access' \) \) return;/", $src ), 'the wizard renders only for users with the Front Desk capability' );
$error_line = '';
foreach ( $lines as $line ) {
	if ( false !== strpos( $line, 'class="notice notice-error"' ) ) {
		$error_line = $line;
	}
}
$check( '' !== $error_line && false !== strpos( $error_line, 'esc_html(' ), 'the setup error notice from the URL is escaped' );
$check( false !== strpos( $src, "tzfd_request_text( 'get', 'error' )" ), 'the setup error notice reads its text through the request sanitiser' );

echo "Summary: $passes passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
