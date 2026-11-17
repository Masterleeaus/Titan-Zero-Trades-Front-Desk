<?php
/**
 * Runtime checks for FAQ knowledge gap detection (issue #36). Included from runtime-tests.php, which
 * defines rt_check(). Uses the real hooks, the real admin page and the real options on the test site.
 */
if ( ! function_exists( 'rt_check' ) ) { echo "FAIL harness -- include from runtime-tests.php\n"; return; }

wp_set_current_user( 1 );
$gap_company = (int) TZFD_Scoped_Store::company_id();
$gap_email   = 'gap-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '@example.test';
$gap_label   = 'boiler repair';

rt_check( 'gap: answer-flow listener is registered', false !== has_action( 'tzfd_ai_question_handled', array( 'TZFD_Ai_Gap_Detection', 'on_handled' ) ) );
rt_check( 'gap: admin page is registered on admin_menu', false !== has_action( 'admin_menu', array( 'TZFD_Ai_Gap_Page', 'add_page' ) ) );
rt_check( 'gap: merge handler is registered for admin-post only', false !== has_action( 'admin_post_tzfd_ai_gap_merge', array( 'TZFD_Ai_Gap_Page', 'handle_merge' ) ) );

delete_option( TZFD_Ai_Gap_Detection::OPTION );
TZFD_Ai_Free_Core::set_consent( false, time() );
do_action( 'tzfd_ai_question_handled', 'Do you do ' . $gap_label . ' on ' . $gap_email . '?', array( 'status' => 'refused', 'source' => 'none' ) );
rt_check( 'gap: nothing is stored while consent is off', false === get_option( TZFD_Ai_Gap_Detection::OPTION, false ) );

TZFD_Ai_Free_Core::set_consent( true, time() );
add_filter( TZFD_Ai_Gap_Detection::VOCAB_FILTER, static function () { return array( 'boiler repair', 'drain unblocking' ); } );
for ( $i = 1; $i <= 4; $i++ ) {
	do_action( 'tzfd_ai_question_handled', 'Can you do ' . $gap_label . ' for number ' . $i . ' on ' . $gap_email . '?', array( 'status' => 'refused', 'source' => 'none' ) );
}
for ( $i = 1; $i <= 3; $i++ ) {
	do_action( 'tzfd_ai_question_handled', 'Do you do drain unblocking, visit ' . $i . '?', array( 'status' => 'refused', 'source' => 'none' ) );
}
do_action( 'tzfd_ai_question_handled', 'Do you do ' . $gap_label . ' on Sunday?', array( 'status' => 'answered', 'source' => 'approved_rule' ) );

$stored = wp_json_encode( get_option( TZFD_Ai_Gap_Detection::OPTION, array() ) );
rt_check( 'gap: stored data has no email or raw question text', false === strpos( $stored, $gap_email ) && false === strpos( $stored, 'Sunday' ) && false === strpos( $stored, 'number' ) );

$report = TZFD_Ai_Gap_Detection::report( $gap_company, time(), TZFD_Ai_Gap_Detection::RETENTION_DAYS, array(), array() );
rt_check( 'gap: count is exact (seven unanswered, one answered, eight handled)', 8 === $report['total'] && 7 === $report['unanswered'], 'total=' . $report['total'] . ' unanswered=' . $report['unanswered'] );
rt_check( 'gap: topic counts match the questions asked (boiler 4, drain 3)', 2 === count( $report['topics'] ) && $gap_label === $report['topics'][0]['label'] && 4 === $report['topics'][0]['count'] && 3 === $report['topics'][1]['count'] );

ob_start();
TZFD_Ai_Gap_Page::render();
$html = ob_get_clean();
rt_check( 'page: renders the FAQ gaps heading', false !== strpos( $html, 'FAQ gaps' ) );
rt_check( 'page: shows the approved topic label and count', false !== strpos( $html, '>' . $gap_label . '<' ) && false !== strpos( $html, '>4<' ) );
rt_check( 'page: no customer email or raw question text in the HTML', false === strpos( $html, $gap_email ) && false === strpos( $html, 'Sunday' ) );
rt_check( 'page: merge form posts to admin-post with a nonce field', false !== strpos( $html, 'name="action" value="tzfd_ai_gap_merge"' ) && false !== strpos( $html, '_wpnonce' ) );
rt_check( 'page: filters render with the coverage allow-list', false !== strpos( $html, 'name="coverage"' ) );

$gap_sub = wp_create_user( 'gap_sub_' . substr( md5( uniqid( '', true ) ), 0, 6 ), wp_generate_password(), 'gap-sub-' . substr( md5( uniqid( '', true ) ), 0, 6 ) . '@example.test' );
if ( ! is_wp_error( $gap_sub ) ) {
	$sub = new WP_User( (int) $gap_sub );
	$sub->set_role( 'subscriber' );
	rt_check( 'page: a subscriber cannot view FAQ gaps (negative)', ! user_can( (int) $gap_sub, 'tz_fd_access' ) );
	wp_delete_user( (int) $gap_sub );
} else {
	rt_check( 'page: could create a negative-test user', false, $gap_sub->get_error_message() );
}

// Real public path: the widget shortcode reads the question, answers it and fires the hook.
$gap_before = TZFD_Ai_Gap_Detection::report( $gap_company, time(), TZFD_Ai_Gap_Detection::RETENTION_DAYS, array(), array() )['total'];
$_GET[ TZFD_AI_Widget::PARAM ] = 'Do you offer ' . $gap_label . ' on Sunday, call ' . $gap_email . '?';
$widget_html = do_shortcode( '[' . TZFD_AI_Widget::SHORTCODE . ']' );
unset( $_GET[ TZFD_AI_Widget::PARAM ] );
$gap_after = TZFD_Ai_Gap_Detection::report( $gap_company, time(), TZFD_Ai_Gap_Detection::RETENTION_DAYS, array(), array() )['total'];
rt_check( 'widget: a public question adds exactly one handled question', $gap_after === $gap_before + 1, 'before=' . $gap_before . ' after=' . $gap_after );
rt_check( 'widget: the public question text and email are not stored', false === strpos( wp_json_encode( get_option( TZFD_Ai_Gap_Detection::OPTION, array() ) ), $gap_email ) );

TZFD_Ai_Free_Core::set_consent( false, time() );
rt_check( 'gap: withdrawing consent purges the stored counts', false === get_option( TZFD_Ai_Gap_Detection::OPTION, false ) );
rt_check( 'gap: page shows zero handled questions after withdrawal', 0 === TZFD_Ai_Gap_Detection::report( $gap_company, time(), TZFD_Ai_Gap_Detection::RETENTION_DAYS, array(), array() )['total'] );
remove_all_filters( TZFD_Ai_Gap_Detection::VOCAB_FILTER );
delete_option( TZFD_Ai_Free_Core::CONSENT_OPTION );
