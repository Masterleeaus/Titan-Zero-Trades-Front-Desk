<?php
/*
 * WordPress integration checks for TZFD_Answer_Box (issue #20). Run with WP-CLI eval-file on the test site
 * after the plugin is copied in. Exits non-zero through WP_CLI::error when a check fails.
 */
if ( ! class_exists( 'TZFD_Answer_Box' ) ) { WP_CLI::error( 'TZFD_Answer_Box not loaded' ); }
$A = 'TZFD_Answer_Box';
$pass = 0; $fail = 0;
$check = function ( $name, $cond, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name $detail\n"; }
};
// Fresh identifiers per run: transients from an earlier run must not change results.
$run = bin2hex( random_bytes( 4 ) );
$conv = bin2hex( random_bytes( 16 ) );
$nofilter = function () { return 0; };

// Reset state
delete_option( TZFD_Answer_Box::OPT_ENTRIES ); delete_option( TZFD_Answer_Box::OPT_HOURS ); delete_option( TZFD_Answer_Box::OPT_BUDGET );
remove_all_filters( 'tzfd_answer_box_generate' ); remove_all_filters( 'tzfd_answer_box_daily_generation_cap' );

$n = $A::save_entries( array(
	array( 'kind' => 'faq', 'question' => 'Do you offer free quotes?', 'answer' => 'Yes, quotes are free.', 'keywords' => 'quote,estimate', 'approved' => true ),
	array( 'kind' => 'faq', 'question' => 'Draft', 'answer' => 'Hidden', 'approved' => false ),
) );
$check( 'approved entries persisted via options', 1 === $n && 1 === count( get_option( TZFD_Answer_Box::OPT_ENTRIES ) ) );
$A::save_hours( 'Mon-Fri 8am to 5pm' );
$A::save_handoff_url( 'https://example.test/contact/' );
$r = $A::answer( 'What are your hours?', $conv, 'wp-1-' . $run );
$check( 'hours answered from stored owner text', 'approved' === $r['status'] && false !== strpos( $r['text'], 'Mon-Fri' ), json_encode( $r ) );

$r = $A::answer( 'Do you offer free quotes?', $conv, 'wp-2-' . $run );
$check( 'exact match answer', 'approved' === $r['status'] );
$r = $A::answer( 'Do you offer free quotes?', $conv, 'wp-2-' . $run );
$check( 'cache hit via transient', 'cache' === $r['status'], json_encode( $r ) );
$A::save_entries( array( array( 'kind' => 'faq', 'question' => 'Do you offer free quotes?', 'answer' => 'Quotes cost money now.', 'approved' => true ) ) );
$r = $A::answer( 'Do you offer free quotes?', $conv, 'wp-2-' . $run );
$check( 'cache invalidated on approved-content change', 'approved' === $r['status'] && 'Quotes cost money now.' === $r['text'], json_encode( $r ) );

$A::save_handoff_url( 'https://example.test/contact/' );
$r = $A::answer( 'Do you bake bread?', $conv, 'wp-3-' . $run );
$check( 'unsupported question refused when generation off', 'unavailable' === $r['status'] && null !== $r['link'], json_encode( $r ) );
$r = $A::answer( 'Do you bake bread?', $conv, 'wp-3-' . $run );
$A::save_handoff_url( '' );
$r = $A::answer( 'Do you bake bread?', $conv, 'wp-3b-' . $run );
$check( 'no handoff configured: refusal has no link but still says to contact the business', null === $r['link'] && false !== strpos( $r['text'], 'contact the business' ), json_encode( $r ) );
$A::save_handoff_url( 'https://example.test/contact/' );

// Generative branch with a stub provider via the real filter.
add_filter( 'tzfd_answer_box_daily_generation_cap', function () { return 10; } );
$calls = 0;
add_filter( 'tzfd_answer_box_generate', function () use ( &$calls ) { $calls++; return array( 'ok' => true, 'text' => 'Please contact us for that.' ); } );
$c2 = bin2hex( random_bytes( 16 ) );
$A::answer( 'Can you fix a fence', $c2, 'wp-4-' . $run );
$A::answer( 'Can you fix a gate', $c2, 'wp-4-' . $run );
$r = $A::answer( 'Can you fix a roof', $c2, 'wp-4-' . $run );
$check( 'two generative turns then refusal', 2 === $calls && 'turns_exhausted' === $r['status'], "calls=$calls status={$r['status']}" );

// Shortcode rendering through WordPress (do_shortcode) and XSS escaping.
$_GET = array( 'tzab_q' => '<script>x</script>Do you offer free quotes?', 'tzab_c' => $conv );
$html = do_shortcode( '[tzfd_answer_box]' );
$check( 'shortcode renders for a visitor', false !== strpos( $html, 'class="tzab"' ) );
$check( 'no raw script from visitor input', false === stripos( $html, '<script>x' ) );
$check( 'shortcode status region present', false !== strpos( $html, 'role="status"' ) );
$_GET = array();
$html = do_shortcode( '[tzfd_answer_box]' );
$check( 'first load has no answer text and a fresh token', 1 === preg_match( '/name="tzab_c" value="[a-f0-9]{32}"/', $html ) );

// Anonymous rate limit through real transients.
$A::save_entries( array( array( 'kind' => 'faq', 'question' => 'Hours', 'answer' => 'Open.', 'approved' => true ) ) );
$limited = 0;
for ( $i = 0; $i < 11; $i++ ) { if ( 'rate_limited' === $A::answer( 'Hours', $conv, 'wp-rl-' . $run )['status'] ) { $limited++; } }
$check( 'anonymous rate limit enforced via transients', 1 === $limited, "limited=$limited" );
$leak = false; global $wpdb;
$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_tzab_rl_%'" );
foreach ( $rows as $name ) { if ( false !== strpos( $name, 'wp-rl-' . $run ) ) { $leak = true; } }
$check( 'rate limit keys hold no raw client value', ! $leak );


// Tenant scope: a service row owned by another company must never be answered.
global $wpdb;
$svc_table = $wpdb->prefix . 'tzfd_local_services';
$wpdb->insert( $svc_table, array( 'company_id' => 2, 'name' => 'Tenant Zebra Service', 'description' => 'Other company only.', 'price' => 0, 'duration_minutes' => 30, 'gst_applies' => 0, 'archived' => 0 ) );
$r = $A::answer( 'Do you offer Tenant Zebra Service', $conv, 'wp-tenant-' . $run );
$check( 'service of another company is never answered', false === strpos( $r['text'], 'Other company only' ), json_encode( $r ) );
$wpdb->delete( $svc_table, array( 'company_id' => 2, 'name' => 'Tenant Zebra Service' ) );

// Outage: a provider that throws must produce a handoff, not an error page or a guess.
remove_all_filters( 'tzfd_answer_box_generate' );
add_filter( 'tzfd_answer_box_generate', function () { throw new RuntimeException( 'proxy down' ); } );
$r = $A::answer( 'Can you paint a fence in purple', bin2hex( random_bytes( 16 ) ), 'wp-outage-' . $run );
$check( 'provider outage returns proxy_unavailable with handoff link', 'proxy_unavailable' === $r['status'] && is_array( $r['link'] ), json_encode( $r ) );
remove_all_filters( 'tzfd_answer_box_generate' );

// Cleanup
delete_option( TZFD_Answer_Box::OPT_ENTRIES ); delete_option( TZFD_Answer_Box::OPT_HOURS ); delete_option( TZFD_Answer_Box::OPT_BUDGET );
echo "\nSummary: $pass passed, $fail failed\n";
if ( $fail > 0 ) { WP_CLI::error( "$fail failed" ); }
