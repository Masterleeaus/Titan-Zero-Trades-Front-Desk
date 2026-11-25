<?php
/**
 * Standalone regression check for the pre-rename hook aliases (issue #455, PR #468).
 * Not shipped in the plugin. Run: php tests/unit/legacy-hook-alias-tests.php   (exit 0 = all pass)
 *
 * Plain PHP, no WordPress. It reads the plugin source and checks that:
 *  1. every renamed hook still fires its old name right after the new tzfd_ name, with the same arguments;
 *  2. every old-name fire carries the phpcs:ignore annotation and the "legacy alias" reason;
 *  3. no tz_fd_ or titan_zero_front_desk_ hook is fired without that annotation;
 *  4. the has-checks that guard listeners consider both names.
 */
$plugin = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = array();
function t_ok( $cond, $msg ) {
	if ( $cond ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail'][] = $msg; }
}

/* ---- collect plugin PHP files (skip vendored and JS code) ---- */
$files = array();
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	$path = (string) $file;
	if ( substr( $path, -4 ) === '.php' && strpos( $path, '/vendor/' ) === false ) {
		$files[ $path ] = file( $path, FILE_IGNORE_NEW_LINES );
	}
}
t_ok( count( $files ) > 50, 'plugin PHP files were found (' . count( $files ) . ')' );

/* ---- helpers ---- */
$fire_re = '/\b(do_action|apply_filters)\(\s*\'((?:tz_fd_|titan_zero_front_desk_)[a-z0-9_]+)\'\s*(,[^;]*)?\)\s*;/';
$new_re = '/\\b(do_action|apply_filters)\\(\\s*\'(tzfd_[a-z0-9_]+)\'\\s*(,[^;]*)?\\)\\s*;/';
$new_name = static function ( $old ) {
	return 'tzfd_' . preg_replace( '/^(tz_fd_|titan_zero_front_desk_)/', '', $old );
};
$norm = static function ( $s ) {
	return preg_replace( '/\s+/', '', (string) $s );
};

$legacy_fires = 0;
$pairs        = array();
$unpaired     = array();
$unannotated  = array();
$bad_reason   = array();

foreach ( $files as $path => $lines ) {
	$rel = substr( $path, strlen( $plugin ) + 1 );
	foreach ( $lines as $i => $line ) {
		if ( ! preg_match( $fire_re, $line, $m ) ) {
			continue;
		}
		$kind = $m[1];
		$old  = $m[2];
		$args = isset( $m[3] ) ? $m[3] : '';
		$is_annotated = strpos( $line, 'phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound' ) !== false;

		if ( ! $is_annotated ) {
			$unannotated[] = $rel . ':' . ( $i + 1 ) . ' ' . $old;
			continue;
		}
		$legacy_fires++;
		if ( strpos( $line, '-- legacy alias' ) === false ) {
			$bad_reason[] = $rel . ':' . ( $i + 1 ) . ' ' . $old;
		}

		// The new name must be fired by the same kind of call within the three lines before it.
		$new    = $new_name( $old );
		$found  = false;
		for ( $j = max( 0, $i - 3 ); $j < $i; $j++ ) {
			if ( preg_match( $new_re, $lines[ $j ], $n ) && $n[2] === $new && $n[1] === $kind ) {
				$found = true;
				// Actions: the legacy fire must pass the same arguments. Filters chain the value through the
				// old name, so their first argument is the running value and is not compared.
				$same = ( $kind === 'apply_filters' ) || ( $norm( $args ) === $norm( isset( $n[3] ) ? $n[3] : '' ) );
				$pairs[] = array( $old, $new, $same );
				break;
			}
		}
		if ( ! $found ) {
			$unpaired[] = $rel . ':' . ( $i + 1 ) . ' ' . $old . ' has no ' . $new . ' call before it';
		}
	}
}

// Pinned count: 64 alias fires exist on the rename commit. If an alias is added or removed on purpose, update this number.
t_ok( $legacy_fires === 64, 'annotated legacy alias fires match the pinned count of 64 (found ' . $legacy_fires . ')' );
t_ok( $unannotated === array(), 'no tz_fd_ or titan_zero_front_desk_ hook fires without a legacy annotation: ' . implode( '; ', array_slice( $unannotated, 0, 5 ) ) );
t_ok( $bad_reason === array(), 'every legacy fire states its reason: ' . implode( '; ', array_slice( $bad_reason, 0, 5 ) ) );
t_ok( $unpaired === array(), 'every legacy fire follows its new-name fire: ' . implode( '; ', array_slice( $unpaired, 0, 5 ) ) );

$mismatch = array_filter( $pairs, static function ( $p ) { return ! $p[2]; } );
t_ok( $mismatch === array(), 'legacy and new fires pass the same arguments: ' . implode( ', ', array_map( static function ( $p ) { return $p[0]; }, $mismatch ) ) );

/* ---- critical pairs that must not drift ---- */
$critical = array(
	array( 'tzfd_update_status', 'tz_fd_update_status' ),
	array( 'tzfd_booking_status_persisted', 'titan_zero_front_desk_booking_status_persisted' ),
	array( 'tzfd_booking_rescheduled', 'titan_zero_front_desk_booking_rescheduled' ),
	array( 'tzfd_booking_created', 'titan_zero_front_desk_booking_created' ),
	array( 'tzfd_cache_store', 'tz_fd_cache_store' ),
	array( 'tzfd_process_data_insert_failed', 'tz_fd_process_data_insert_failed' ),
);
foreach ( $critical as $pair ) {
	list( $new, $old ) = $pair;
	$hit = false;
	foreach ( $pairs as $p ) {
		if ( $p[0] === $old && $p[1] === $new ) { $hit = true; break; }
	}
	t_ok( $hit, "critical alias pair $old fires after $new" );
}

/* ---- has-checks must consider both names (listeners may be on either) ---- */
$outbox = $plugin . '/includes/class-tzfd-customer-event-outbox.php';
$src    = is_file( $outbox ) ? file_get_contents( $outbox ) : '';
t_ok( strpos( $src, "has_action( 'tzfd_update_status' )" ) !== false && strpos( $src, "has_action( 'tz_fd_update_status' )" ) !== false, 'cancel path checks listeners on both update_status names' );
t_ok( strpos( $src, "has_action( 'tzfd_booking_rescheduled' )" ) !== false && strpos( $src, "has_action( 'titan_zero_front_desk_booking_rescheduled' )" ) !== false, 'reschedule path checks listeners on both booking_rescheduled names' );

/* ---- report ---- */
$fail = $GLOBALS['t_fail'];
foreach ( $fail as $f ) { echo 'FAIL ' . $f . "\n"; }
echo 'Legacy hook aliases: passed ' . $GLOBALS['t_pass'] . ', failed ' . count( $fail ) . ' (legacy fires checked: ' . $legacy_fires . ")\n";
exit( $fail ? 1 : 0 );
