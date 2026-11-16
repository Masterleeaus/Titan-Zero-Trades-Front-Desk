<?php
/**
 * WordPress integration checks for TZFD_FAQ_Retrieval (issue #21). Runs on the disposable test site, not shipped.
 * Run (with the plugin copied and active, under the site lock):
 *   wp --allow-root eval-file faq-retrieval-wp-tests.php
 * Uses real options, transients, scoped store and filters. Cleans up what it creates.
 */
if ( ! class_exists( 'TZFD_FAQ_Retrieval' ) ) {
    require_once '/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/includes/class-tzfd-scoped-store.php';
    require_once '/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/includes/class-tzfd-faq-retrieval.php';
}
$R = 'TZFD_FAQ_Retrieval';
$GLOBALS['wp_pass'] = 0; $GLOBALS['wp_fail'] = 0;
function wp_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['wp_pass']++; } else { $GLOBALS['wp_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

// A company id unique to this run. Cached answers survive between runs as transients, and the cleanup resets the
// index version, so reusing a fixed id would let a previous run's cached answer answer this one.
$company = 700000 + ( time() % 100000 );
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
wp_check( 'consent: embedding answer and monthly counter stored', 'embedding' === $r['path'] && 1 === $R::embedding_calls_this_month( $company ), json_encode( $r ) );
$r = $R::answer( $company, 'email me at jane@example.com' );
wp_check( 'email in query is never sent to the provider', 'private_detail_not_sent' === $r['reason'] && 1 === $calls, "calls=$calls" );
$R::set_embedding_consent( $company, false );
wp_check( 'consent withdrawn: no new provider call', false === $R::has_embedding_consent( $company ) );

// Cleanup everything this test created.
remove_all_filters( 'tzfd_faq_embedding_provider' );
$R::save_entries( $company, array() );
TZFD_Scoped_Store::update( $R::OPTION_CONSENT, false, $company );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_ENTRIES, $company ) );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_VERSION, $company ) );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_CONSENT, $company ) );
delete_option( TZFD_Scoped_Store::option_name( $R::OPTION_QUOTA . gmdate( 'Ym' ), $company ) );

echo "Summary: {$GLOBALS['wp_pass']} passed, {$GLOBALS['wp_fail']} failed\n";
