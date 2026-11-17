<?php
/**
 * WordPress integration checks for TZFD_FAQ_Retrieval (issue #21). Runs on the disposable test site, not shipped.
 * Run (with the plugin copied and active, under the site lock):
 *   wp --allow-root eval-file faq-retrieval-wp-tests.php
 * Uses real options, transients, scoped store and filters. Cleans up what it creates.
 */
if ( ! class_exists( 'TZFD_FAQ_Retrieval' ) ) {
    // Load from the plugin copy that WordPress itself uses (WP_PLUGIN_DIR), not a fixed server path.
    $tzfd_inc = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '/var/www/html/wp-content/plugins' ) . '/titan-zero-trades-front-desk/includes/';
    require_once $tzfd_inc . 'class-tzfd-scoped-store.php';
    require_once $tzfd_inc . 'class-tzfd-ai-connector.php';
    require_once $tzfd_inc . 'class-tzfd-faq-retrieval.php';
}
$R = 'TZFD_FAQ_Retrieval';
$GLOBALS['wp_pass'] = 0; $GLOBALS['wp_fail'] = 0;
// runtime-tests.php includes this file twice, so declare the helper only once.
if ( ! function_exists( 'wp_check' ) ) {
    function wp_check( $name, $ok, $detail = '' ) {
        if ( $ok ) { $GLOBALS['wp_pass']++; } else { $GLOBALS['wp_fail']++; }
        echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
    }
}

// A company id unique to this run. Cached answers survive between runs as transients, and the cleanup resets the
// index version, so reusing a fixed id would let a previous run's cached answer answer this one.
// The file is included twice in one process (runtime-tests.php), so add a per-include counter: two includes in the same
// second must not share a tenant, or the second run is answered from the first run's cached answers.
$GLOBALS['tzfd_faq_suite_runs'] = ( isset( $GLOBALS['tzfd_faq_suite_runs'] ) ? (int) $GLOBALS['tzfd_faq_suite_runs'] : 0 ) + 1;
$company = 700000 + ( ( time() % 100000 ) * 10 ) + $GLOBALS['tzfd_faq_suite_runs'];
$other   = 987654; // A company with no data on this site.
$faq = array(
    array( 'id' => 'hours', 'question' => 'What are your opening hours?', 'answer' => 'Open 8am to 6pm weekdays.', 'keywords' => 'hours,open', 'approved' => true ),
    array( 'id' => 'emergency', 'question' => 'Do you offer emergency plumbing on weekends?', 'answer' => 'Yes, urgent call-outs seven days.', 'keywords' => 'plumbing,emergency,weekend', 'approved' => true ),
    array( 'id' => 'draft', 'question' => 'Do you do gas fitting?', 'answer' => 'DRAFT NOT APPROVED', 'keywords' => 'gas', 'approved' => false ),
);

// Clean slate for this company.
$R::save_entries( $company, array() );
TZFD_Scoped_Store::update( $R::OPTION_CONSENT, false, $company );

$n = $R::save_entries( $company, $faq );
wp_check( 'save writes approved and draft entries through the real store', 3 === $n, (string) $n );
$raw = get_option( TZFD_Scoped_Store::option_name( $R::OPTION_ENTRIES, $company ), null );
wp_check( 'entries are stored under the company-scoped option name', is_array( $raw ) && 3 === count( $raw ) );
wp_check( 'options are stored with autoload off (site value "off"; older WordPress uses "no")', in_array( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", TZFD_Scoped_Store::option_name( $R::OPTION_ENTRIES, $company ) ) ), array( 'off', 'no' ), true ) );

$r = $R::answer( $company, 'what time are you open' );
wp_check( 'keyword answer from the real index', 'answer' === $r['status'] && 'hours' === $r['entry_id'], json_encode( $r ) );
$r2 = $R::answer( $company, 'what time are you open' );
wp_check( 'second call is served from the real transient cache', 'cache' === $r2['path'], $r2['path'] );

$d = $R::answer( $company, 'do you do gas fitting' );
wp_check( 'unapproved draft is never answered', '' === $d['answer'] && 'draft' !== $d['entry_id'] );

$o = $R::answer( $other, 'what time are you open' );
wp_check( 'a company with no index gets no answer (no leakage)', '' === $o['answer'] && 'answer' !== $o['status'], json_encode( $o ) );

$v1 = $R::index_version( $company );
$R::delete_entry( $company, 'hours' );
$after = $R::answer( $company, 'what time are you open' );
wp_check( 'delete bumps index version and invalidates the cached answer', $R::index_version( $company ) === $v1 + 1 && 'hours' !== $after['entry_id'], json_encode( $after ) );

// Embedding stage with a real filter.
$calls = 0;
add_filter( 'tzfd_faq_embedding_provider', function ( $v, $q, $questions ) use ( &$calls ) {
    $calls++;
    return array( 'query' => array( 1.0, 0.0 ), 'entries' => array_map( function () { return array( 1.0, 0.0 ); }, $questions ) );
}, 10, 3 );
$R::save_entries( $company, $faq );
$r = $R::answer( $company, 'when do you trade' );
wp_check( 'no consent: provider not called on the real site', 0 === $calls && 'clarify' === $r['status'] );
$R::set_embedding_consent( $company, true );
$r = $R::answer( $company, 'when do you trade' );
wp_check( 'consent without a hosted connection: provider not called', 'hosted_ai_not_connected' === $r['reason'] && 0 === $calls, json_encode( $r ) );
// Entitlement: a placeholder endpoint only. The provider is a filter here, so nothing leaves the test site.
TZFD_AI_Connector::configure( 'https://proxy.example.invalid/faq', 'site-a', str_repeat( 's', 48 ) );
TZFD_AI_Connector::grant_consent( array( 'faq.read' ) );
TZFD_AI_Connector::set_entitlements( array( 'free_ai' ) );
$r = $R::answer( $company, 'when do you trade' );
wp_check( 'consent + hosted connection: embedding answer and monthly counter stored', 'embedding' === $r['path'] && 1 === $R::embedding_calls_this_month( $company ), json_encode( $r ) );
$calls_before = $calls;
$r = $R::answer( $company, 'email me at jane@example.com or call 0412 345 678 about hours' );
wp_check( 'email and phone digits are redacted; the call still goes out', 'embedding' === $r['path'] && $calls === $calls_before + 1, "calls=$calls" );
$R::set_embedding_consent( $company, false );
wp_check( 'consent withdrawn: no new provider call', false === $R::has_embedding_consent( $company ) );

// Cleanup everything this test created.
remove_all_filters( 'tzfd_faq_embedding_provider' );
TZFD_AI_Connector::disconnect();
$R::save_entries( $company, array() );
TZFD_Scoped_Store::update( $R::OPTION_CONSENT, false, $company );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_ENTRIES, $company ) );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_VERSION, $company ) );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_CONSENT, $company ) );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_QUOTA . gmdate( 'Ym' ), $company ) );

echo "Summary: {$GLOBALS['wp_pass']} passed, {$GLOBALS['wp_fail']} failed\n";
// runtime-tests.php includes this file and folds its counters into its own exit status, so do not exit when nested.
if ( empty( $GLOBALS['tzfd_nested_suite'] ) ) {
	exit( $GLOBALS['wp_fail'] > 0 ? 1 : 0 );
}
