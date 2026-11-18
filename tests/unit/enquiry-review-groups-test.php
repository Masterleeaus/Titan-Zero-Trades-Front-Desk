<?php
/**
 * Operator review grouping for held enquiries (issue #34, #272). Pure PHP, no WordPress.
 * Run from the repository root: php -d error_reporting=-1 tests/unit/enquiry-review-groups-test.php
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-enquiry-triage-rules.php';

$R    = 'TZFD_Enquiry_Triage_Rules';
$pass = 0;
$fail = array();

function rg_check( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; return; }
    $fail[] = $name . ( '' !== $detail ? ' :: ' . $detail : '' );
}

$SIG_A = $R::signature( 'Buy cheap followers now' );
$SIG_B = $R::signature( 'Casino bonus for you' );
$SIG_U = $R::signature( "Caf\u{00E9} ' bonus" );

$items = array(
    array( 'id' => 5, 'signature' => $SIG_A, 'email' => 'x@example.test', 'ts' => 300, 'hold' => true ),
    array( 'id' => 2, 'signature' => $SIG_A, 'email' => 'Y@example.test', 'ts' => 100, 'hold' => true ),
    array( 'id' => 9, 'signature' => $SIG_A, 'email' => 'x@example.test', 'ts' => 200, 'hold' => true ),
    array( 'id' => 7, 'signature' => $SIG_B, 'email' => 'z@example.test', 'ts' => 50, 'hold' => true ),
    array( 'id' => 8, 'signature' => $SIG_B, 'email' => '', 'ts' => 60, 'hold' => false ),
    array( 'id' => 11, 'signature' => '', 'email' => 'q@example.test', 'ts' => 10, 'hold' => true ),
    array( 'id' => 12, 'signature' => '', 'email' => 'q@example.test', 'ts' => 20, 'hold' => true ),
    array( 'id' => 13, 'signature' => $SIG_U, 'email' => 'u@example.test', 'ts' => 70, 'hold' => true ),
    array( 'id' => null, 'signature' => $SIG_A, 'email' => 'n@example.test', 'ts' => 1, 'hold' => true ),
);

$g = $R::review_groups( $items );

rg_check( 'largest group comes first', isset( $g[0] ) && $g[0]['signature'] === $SIG_A );
rg_check( 'identical signature groups together', $g[0]['count'] === 3 && $g[0]['ids'] === array( 2, 5, 9 ) );
rg_check( 'distinct contacts counted case-insensitively', $g[0]['distinct_contacts'] === 2 );
rg_check( 'first and last timestamps span the group', $g[0]['first_ts'] === 100 && $g[0]['last_ts'] === 300 );
rg_check( 'not-held items are excluded', ! in_array( 8, $g[ count( $g ) - 1 ]['ids'], true ) && ! in_array( 8, array_merge( ...array_column( $g, 'ids' ) ), true ) );
rg_check( 'empty signatures are never grouped together', count( array_filter( $g, function ( $x ) { return '' === $x['signature'] && $x['count'] > 1; } ) ) === 0 );
rg_check( 'unsigned held items each stand alone', count( array_filter( $g, function ( $x ) { return '' === $x['signature']; } ) ) === 2 );
rg_check( 'items without an id are skipped', ! in_array( null, array_merge( ...array_column( $g, 'ids' ) ), true ) );
rg_check( 'no email addresses in output', strpos( json_encode( $g ), '@' ) === false );
rg_check( 'unicode text signature grouped on its own', count( array_filter( $g, function ( $x ) use ( $SIG_U ) { return $x['signature'] === $SIG_U && $x['count'] === 1; } ) ) === 1 );

$again = $R::review_groups( array_reverse( $items ) );
rg_check( 'order is deterministic regardless of input order', json_encode( $again ) === json_encode( $g ) );
rg_check( 'empty input gives empty output', $R::review_groups( array() ) === array() );

if ( $fail ) {
    foreach ( $fail as $f ) { echo "FAIL $f\n"; }
    echo "review groups: $pass passed, " . count( $fail ) . " failed\n";
    exit( 1 );
}
echo "review groups: $pass passed, 0 failed\n";
exit( 0 );
