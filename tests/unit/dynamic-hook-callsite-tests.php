<?php
/**
 * Regression check for issue #455, owner decisions: hook names are literal at every call site, and the
 * booking-created hook is renamed to tzfd_process_data with the pre-rename name kept as a legacy alias.
 * Not shipped in the plugin. Run: php tests/unit/dynamic-hook-callsite-tests.php   (exit 0 = all pass)
 *
 * Plain PHP, no WordPress. It checks that:
 *  1. the 14 fired call sites that Plugin Check flagged (WordPress.NamingConventions.PrefixAllGlobals.
 *     DynamicHooknameFound) take literal names, not class constants or a $hook variable;
 *  2. each literal that replaced a former constant equals that constant's value, so behaviour is identical;
 *  3. no listener is attached to the old booking name, and the new name is the one listened to;
 *  4. the dispatch fires tzfd_process_data, then the legacy tz_fd_process_data, with the same arguments,
 *     and one failing listener does not stop the others or leak a wrapper.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

$plugin = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = array();
function t_ok( $cond, $msg ) {
	if ( $cond ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail'][] = $msg; }
}

/* ---- collect plugin PHP files ---- */
$files = array();
$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iter as $file ) {
	$path = (string) $file;
	if ( substr( $path, -4 ) === '.php' && strpos( $path, '/vendor/' ) === false ) {
		$files[ $path ] = (string) file_get_contents( $path );
	}
}
t_ok( count( $files ) > 50, 'plugin PHP files were found (' . count( $files ) . ')' );

/* ---- 1. the flagged call sites no longer take a class constant or a $hook variable ---- */
$self_fire = '/\b(do_action|apply_filters|do_action_ref_array|apply_filters_ref_array)\(\s*+self::/';
$hits = array();
foreach ( $files as $path => $src ) {
	if ( preg_match_all( $self_fire, $src, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$hits[] = substr( $path, strlen( $plugin ) + 1 ) . ':' . ( substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1 );
		}
	}
}
t_ok( $hits === array(), 'no hook or filter that the plugin fires is named by a class constant: ' . implode( ', ', $hits ) );
$dispatch_src = $files[ $plugin . '/includes/class-tzfd-post-commit-dispatch.php' ];
t_ok( ! preg_match( '/\b(do_action|do_action_ref_array|apply_filters)\(\s*+\$hook\b/', $dispatch_src ), 'booking dispatch fires literal names, not $hook' );

/* ---- 2. literal call sites match the constants they replaced ---- */
$sites = array(
	array( 'includes/class-tzfd-ai-free-core.php', 'CONSENT_WITHDRAWN_ACTION', 'tzfd_ai_consent_withdrawn' ),
	array( 'includes/class-tzfd-ai-free-core.php', 'HOSTED_FILTER', 'tzfd_ai_hosted_request' ),
	array( 'includes/class-tzfd-ai-knowledge-builder.php', 'CHANGED_ACTION', 'tzfd_ai_knowledge_changed' ),
	array( 'includes/class-tzfd-ai-pairing.php', 'ENDPOINT_FILTER', 'tzfd_ai_pairing_endpoint' ),
	array( 'includes/class-tzfd-ai-pairing.php', 'TRANSPORT_FILTER', 'tzfd_ai_pairing_transport' ),
	array( 'includes/class-tzfd-hosted-ai-connector.php', 'FILTER', 'tzfd_hosted_ai_connector' ),
	array( 'includes/class-tzfd-cancel-assistant-api.php', 'POLICY_FILTER', 'tzfd_cancel_policy_hours' ),
	array( 'includes/class-tzfd-service-finder-wiring.php', 'SERVICES_HOOK', 'tzfd_service_finder_services' ),
	array( 'includes/class-tzfd-ai-faq-suggestions.php', 'FACTS_FILTER', 'tzfd_ai_approved_faq_facts' ),
	array( 'includes/class-tzfd-ai-gap-detection.php', 'VOCAB_FILTER', 'tzfd_ai_gap_vocabulary' ),
	array( 'includes/class-tzfd-ai-gap-detection.php', 'OUTDATED_FILTER', 'tzfd_ai_outdated_days' ),
);
foreach ( $sites as $s ) {
	list( $rel, $const, $literal ) = $s;
	$src = isset( $files[ $plugin . '/' . $rel ] ) ? $files[ $plugin . '/' . $rel ] : '';
	$ok  = (bool) preg_match( '/const\s+' . $const . '\s*=\s*\'' . preg_quote( $literal, '/' ) . '\'\s*;/', $src );
	t_ok( $ok, "$rel: const $const is '$literal' (the literal at the call site)" );
	t_ok( strpos( $src, "'$literal'" ) !== false, "$rel: call site uses the literal '$literal'" );
}

/* ---- 3. booking hook: new name only for listeners ---- */
$old_listeners = array();
$new_listeners = 0;
foreach ( $files as $path => $src ) {
	if ( preg_match_all( '/add_action\(\s*\'(tz_fd_process_data|tzfd_process_data)\'/', $src, $m ) ) {
		foreach ( $m[1] as $name ) {
			if ( $name === 'tz_fd_process_data' ) { $old_listeners[] = substr( $path, strlen( $plugin ) + 1 ); }
			else { $new_listeners++; }
		}
	}
}
t_ok( $old_listeners === array(), 'no add_action listens to the old booking name tz_fd_process_data: ' . implode( ', ', $old_listeners ) );
t_ok( $new_listeners >= 9, "booking listeners attach to tzfd_process_data ($new_listeners found, expected at least 9)" );
t_ok( strpos( $files[ $plugin . '/includes/class-tzfd-post-commit-dispatch.php' ], "'tzfd_process_data' !== \$hook" ) !== false, 'dispatch accepts only tzfd_process_data' );

/* ---- 4. dispatch behaviour with a minimal hook registry ---- */
class T_Fake_Hook {
	public $callbacks = array();
}
$GLOBALS['wp_filter'] = array();
function do_action_ref_array( $hook, $args ) {
	global $wp_filter;
	if ( ! isset( $wp_filter[ $hook ] ) ) { return; }
	$reg = $wp_filter[ $hook ];
	ksort( $reg->callbacks );
	foreach ( $reg->callbacks as $priority => $cbs ) {
		foreach ( $cbs as $entry ) {
			call_user_func_array( $entry['function'], $args );
		}
	}
}
function t_add( $hook, $fn, $priority = 10 ) {
	global $wp_filter;
	if ( ! isset( $wp_filter[ $hook ] ) ) { $wp_filter[ $hook ] = new T_Fake_Hook(); }
	$wp_filter[ $hook ]->callbacks[ $priority ][] = array( 'function' => $fn );
	return array( $priority, count( $wp_filter[ $hook ]->callbacks[ $priority ] ) - 1 );
}

require_once $plugin . '/includes/class-tzfd-post-commit-dispatch.php';

$log = array();
$params_seen = null;
$payload = array( 'booking_id' => 7, 'form_id' => 3 );
$new_fn = function ( &$p ) use ( &$log ) { $log[] = 'new'; $p['touched_new'] = true; };
$legacy_fn = function ( &$p ) use ( &$log, &$params_seen ) { $log[] = 'legacy'; $params_seen = $p; };
t_add( 'tzfd_process_data', $new_fn, 10 );
t_add( 'tz_fd_process_data', $legacy_fn, 10 );
$ok = TZFD_Post_Commit_Dispatch::run_booking_created( 'tzfd_process_data', array( &$payload ), 7 );
t_ok( $ok === true, 'dispatch returns true when every listener succeeds' );
t_ok( $log === array( 'new', 'legacy' ), 'new name fires, then the legacy name: ' . implode( ',', $log ) );
t_ok( isset( $params_seen['touched_new'] ) && $params_seen['booking_id'] === 7, 'legacy listener receives the same (by reference) arguments' );
t_ok( $GLOBALS['wp_filter']['tzfd_process_data']->callbacks[10][0]['function'] === $new_fn, 'new-name listener is restored after dispatch' );
t_ok( $GLOBALS['wp_filter']['tz_fd_process_data']->callbacks[10][0]['function'] === $legacy_fn, 'legacy listener is restored after dispatch' );

// A failing listener on the new name must not stop the next listener, and the dispatch reports failure.
$GLOBALS['wp_filter'] = array();
$log = array();
$bad_fn  = function ( &$p ) use ( &$log ) { $log[] = 'bad'; throw new RuntimeException( 'boom' ); };
$good_fn = function ( &$p ) use ( &$log ) { $log[] = 'good'; };
t_add( 'tzfd_process_data', $bad_fn, 10 );
t_add( 'tzfd_process_data', $good_fn, 20 );
t_add( 'tz_fd_process_data', function ( &$p ) use ( &$log ) { $log[] = 'legacy-after-failure'; }, 10 );
$ok = TZFD_Post_Commit_Dispatch::run_booking_created( 'tzfd_process_data', array( &$payload ), 7 );
t_ok( $ok === false, 'dispatch returns false when a listener throws' );
t_ok( $log === array( 'bad', 'good', 'legacy-after-failure' ), 'a failing listener does not stop the others: ' . implode( ',', $log ) );
t_ok( $GLOBALS['wp_filter']['tzfd_process_data']->callbacks[10][0]['function'] === $bad_fn, 'original failing listener is restored after dispatch' );
t_ok( $GLOBALS['wp_filter']['tzfd_process_data']->callbacks[20][0]['function'] === $good_fn, 'original good listener is restored after dispatch' );

// Any other hook name is refused without firing anything.
$GLOBALS['wp_filter'] = array();
$log = array();
t_add( 'tz_fd_process_data', function ( &$p ) use ( &$log ) { $log[] = 'should-not-run'; }, 10 );
$ok = TZFD_Post_Commit_Dispatch::run_booking_created( 'tz_fd_process_data', array( &$payload ), 7 );
t_ok( $ok === false && $log === array(), 'dispatch refuses any name other than tzfd_process_data' );

/* ---- report ---- */
$fail = $GLOBALS['t_fail'];
foreach ( $fail as $f ) { echo 'FAIL ' . $f . "\n"; }
echo 'Dynamic hook call sites and booking hook: passed ' . $GLOBALS['t_pass'] . ', failed ' . count( $fail ) . "\n";
exit( $fail ? 1 : 0 );
