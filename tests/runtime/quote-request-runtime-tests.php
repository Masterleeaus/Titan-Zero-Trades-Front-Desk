<?php
/**
 * WordPress runtime checks for the quote request draft on enquiries (issue 32).
 * Run on the disposable test site: wp --allow-root eval-file quote-request-runtime-tests.php (not shipped in the plugin).
 */
global $wpdb;
$GLOBALS['qr_pass'] = 0; $GLOBALS['qr_fail'] = 0;
function qr_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['qr_pass']++; } else { $GLOBALS['qr_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

if ( ! class_exists( 'TZFD_Enquiry_Review' ) || ! class_exists( 'TZFD_Quote_Request_Classifier' ) || ! class_exists( 'TZFD_Lead_Intake' ) ) {
    qr_check( 'enquiry classes are loaded', false, 'plugin classes not found' );
    echo "\nSummary: {$GLOBALS['qr_pass']} passed, {$GLOBALS['qr_fail']} failed\n";
    return;
}

wp_set_current_user( 1 );
$company = (int) TZFD_Scoped_Store::company_id();
qr_check( 'company resolves for admin', $company > 0, 'company=' . $company );
$other = $company + 1000; // a company id with no enquiries
$tag   = 'qr-' . substr( md5( uniqid( '', true ) ), 0, 10 );
$table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;

// 1. Intake stores a quote-request enquiry for the company.
$id = TZFD_Lead_Intake::receive( array(
    'company_id' => $company, 'name' => 'Ana ' . $tag, 'email' => $tag . '@example.test', 'phone' => '0412 345 678',
    'enquiry' => "Can I get a quote for carpet cleaning?\nSite: 12 Smith St, Fitzroy\nNeeded 2026-12-05. Also \$250 fixed is fine.",
    'source' => 'website-form',
) );
qr_check( 'intake stores the enquiry', $id > 0, 'id=' . $id );

// 2. Negative: intake without a company stores nothing.
qr_check( 'intake without company stores nothing', 0 === TZFD_Lead_Intake::receive( array( 'name' => 'X', 'enquiry' => 'quote' ) ) );

// 3. Tenant isolation: the stored enquiry is listed for its company only.
$mine  = TZFD_Enquiry_Review::list_for_company( $company );
$found = false;
foreach ( $mine as $r ) { if ( (int) $r->id === $id ) { $found = true; } }
qr_check( 'owner company lists the enquiry', $found );
qr_check( 'other company does not list the enquiry', array() === TZFD_Enquiry_Review::list_for_company( $other ) );

// 4. Tenant isolation: another company cannot change the status.
$res = TZFD_Enquiry_Review::set_status( $id, 'reviewed', $other );
qr_check( 'other company status change is refused', is_wp_error( $res ) && 404 === $res->get_error_data()['status'] );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM $table WHERE id = %d", $id ) );
qr_check( 'status unchanged after refused change', $row && 'new' === $row->status );

// 5. Owner review page shows the draft, with no price and no approval.
ob_start();
TZFD_Enquiry_Review::page();
$html = ob_get_clean();
qr_check( 'review page shows the quote draft', false !== strpos( $html, 'tzfd-quote-draft' ) && false !== strpos( $html, 'owner review, no price' ) );
qr_check( 'review page shows the requested date', false !== strpos( $html, '2026-12-05' ) );
qr_check( 'review page shows the stated site', false !== strpos( $html, '12 Smith St, Fitzroy' ) );
// The customer's own enquiry text is shown by the existing page; only the draft block must carry no price.
$draft_block = '';
if ( preg_match( '/<div class="tzfd-quote-draft">.*?<\/div>/s', $html, $dm ) ) { $draft_block = $dm[0]; }
qr_check( 'draft block carries no price', '' !== $draft_block && false === strpos( $draft_block, '$' ) );

// 6. Negative: a user without the Front Desk capability cannot see the page or the draft.
$uid = wp_create_user( 'qr_' . $tag, wp_generate_password( 20 ), $tag . '-nocap@example.test' );
if ( is_int( $uid ) ) {
    $user = new WP_User( $uid );
    $user->set_role( 'subscriber' );
    wp_set_current_user( $uid );
    qr_check( 'subscriber lacks tz_fd_access', ! current_user_can( 'tz_fd_access' ) );
    wp_set_current_user( 1 );
    wp_delete_user( $uid );
} else {
    qr_check( 'test user created for capability check', false, 'wp_create_user failed' );
}

// 7. Cleanup of rows this test created.
$wpdb->delete( $table, array( 'id' => $id, 'company_id' => $company ), array( '%d', '%d' ) );
qr_check( 'test enquiry removed', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE id = %d", $id ) ) );

echo "\nSummary: {$GLOBALS['qr_pass']} passed, {$GLOBALS['qr_fail']} failed\n";
