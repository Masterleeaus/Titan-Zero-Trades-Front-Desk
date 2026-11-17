<?php
/**
 * WordPress integration checks for the saved enquiry route map (issue #28).
 * Run on the disposable test site only: wp --allow-root eval-file enquiry-triage-route-wp-tests.php (not shipped in the plugin)
 * Each enquiry goes through TZFD_Lead_Intake::receive(), so the real intake, triage and route storage are exercised.
 */
$GLOBALS['etr_pass'] = 0; $GLOBALS['etr_fail'] = 0;
function etr_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['etr_pass']++; } else { $GLOBALS['etr_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

global $wpdb;
$company = (int) TZFD_Scoped_Store::company_id();
$other   = $company + 1000;
etr_check( 'company resolves for the test site', $company > 0, 'company=' . $company );
$option = TZFD_Enquiry_Triage::ROUTE_RULES_OPTION;
$table  = TZFD_Tables::name( TZFD_Tables::ENQUIRY_TRIAGE );
$tag    = substr( md5( uniqid( '', true ) ), 0, 8 );

// Stores one enquiry through intake and returns its stored triage row (or null).
$store = function ( $company_id, $text ) use ( $wpdb, $table, $tag ) {
    static $n = 0;
    $n++;
    $id = TZFD_Lead_Intake::receive( array(
        'company_id' => $company_id,
        'name'       => 'Route ' . $tag . ' ' . $n,
        'email'      => 'route-' . $tag . '-' . $n . '@example.test',
        'enquiry'    => $text . ' ref ' . $tag . '-' . $n,
    ) );
    if ( $id < 1 ) { return null; }
    return $wpdb->get_row( $wpdb->prepare( 'SELECT enquiry_id, primary_intent, route FROM %i WHERE company_id = %d AND enquiry_id = %d', $table, $company_id, $id ) );
};
$quote_text = 'Can I get a quote for a deep clean of my 3 bedroom house';

// 1. No saved map: the default quote route is unchanged.
TZFD_Scoped_Store::delete( $option, $company );
$r = $store( $company, $quote_text );
etr_check( 'no saved map keeps default quote route', $r && 'quote' === $r->primary_intent && 'quote_review' === $r->route, json_encode( $r ) );

// 2. Saved allowlisted rule moves quote to the general enquiry queue.
TZFD_Scoped_Store::update( $option, array( 'quote' => 'enquiry_queue' ), $company );
$r = $store( $company, $quote_text );
etr_check( 'saved rule routes quote to enquiry queue', $r && 'enquiry_queue' === $r->route, json_encode( $r ) );

// 3. A queue outside the allowlist is ignored and the default route stays.
TZFD_Scoped_Store::update( $option, array( 'quote' => 'admin_only_queue' ), $company );
$r = $store( $company, $quote_text );
etr_check( 'non-allowlisted queue ignored, default route kept', $r && 'quote_review' === $r->route, json_encode( $r ) );

// 4. A malformed saved value falls back to the default route without a fatal error.
TZFD_Scoped_Store::update( $option, 'not-a-map', $company );
$r = $store( $company, $quote_text );
etr_check( 'malformed saved map falls back to default route', $r && 'quote_review' === $r->route, json_encode( $r ) );

// 5. Tenant isolation: a rule saved for another company does not change this company's routes.
TZFD_Scoped_Store::delete( $option, $company );
TZFD_Scoped_Store::update( $option, array( 'quote' => 'enquiry_queue' ), $other );
$r = $store( $company, $quote_text );
etr_check( 'rule for another company does not affect this company', $r && 'quote_review' === $r->route, json_encode( $r ) );
$r_other = $store( $other, $quote_text );
etr_check( 'rule applies to the company that saved it', $r_other && 'enquiry_queue' === $r_other->route, json_encode( $r_other ) );

// 6. Cleanup: remove the saved maps so later checks start from the default.
TZFD_Scoped_Store::delete( $option, $company );
TZFD_Scoped_Store::delete( $option, $other );

echo 'Passed ' . $GLOBALS['etr_pass'] . ', Failed ' . $GLOBALS['etr_fail'] . "\n";
if ( $GLOBALS['etr_fail'] > 0 ) { exit( 1 ); }
