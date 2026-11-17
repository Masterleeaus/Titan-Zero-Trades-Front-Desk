<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions -- standalone CLI harness: prints plain test text to a terminal, never HTML.
/**
 * Batch checks for TZFD_AI_Followup_Rules::sweep() (issue #33). Pure PHP, no WordPress, no network.
 * Run from the repository root: php -I tests/unit/followup-sweep-test.php
 *
 * Single-enquiry rules are covered by tests/ai-assist/run-tests.php. These checks cover the batch run: duplicates and
 * the per-customer throttle must hold between enquiries in one run, and sending is never part of the result.
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/ai-assist/class-tzfd-ai-followup-rules.php';

$R    = 'TZFD_AI_Followup_Rules';
$pass = 0;
$fail = array();

function fs_dump( $value ) {
    return print_r( $value, true );
}

function fs_check( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; return; }
    $fail[] = $name . ( '' !== $detail ? ' :: ' . $detail : '' );
}

$NOW = strtotime( '2026-11-16 03:00:00 UTC' ); // 14:00 in Melbourne.
$TZ  = 'Australia/Melbourne';
$OLD = gmdate( 'Y-m-d H:i:s', $NOW - 30 * 3600 ); // Unanswered for 30 hours.
$OK  = static function ( $email ) { return false; }; // Not opted out.
$OUT = static function ( $email ) { return true; };  // Opted out.

function fs_enquiry( $id, $key, $overrides = array() ) {
    global $OLD;
    return array_merge( array(
        'id' => $id, 'email' => 'pat@example.com', 'status' => 'new', 'received_utc' => $OLD,
        'replied' => false, 'abandoned' => false, 'contact_consent' => true, 'customer_key' => $key,
    ), $overrides );
}

// ---- Batch throttle: three overdue enquiries from one customer in one run -----------------
$batch = array( fs_enquiry( 1, 'cust-a' ), fs_enquiry( 2, 'cust-a' ), fs_enquiry( 3, 'cust-a' ) );
$run   = $R::sweep( $batch, $NOW, $TZ, array(), $OK );
$states = array_map( static function ( $d ) { return $d['decision']['state']; }, $run['decisions'] );
$by     = array_map( static function ( $d ) { return $d['decision']['suppressed_by']; }, $run['decisions'] );
fs_check( 'first two enquiries from one customer in one run are suggested', array( 'suggested', 'suggested', 'suppressed' ) === $states, fs_dump( $states ) );
fs_check( 'third enquiry in the same run is throttled', 'throttle' === $by[2], fs_dump( $by ) );
fs_check( 'run history grows by the suggestions only', 2 === count( $run['history'] ), fs_dump( $run['history'] ) );

// ---- Duplicate: the same enquiry listed twice in one run is suggested once -----------------
$run = $R::sweep( array( fs_enquiry( 7, 'cust-b' ), fs_enquiry( 7, 'cust-b' ) ), $NOW, $TZ, array(), $OK );
fs_check( 'same enquiry twice in one run is suggested once', 'suggested' === $run['decisions'][0]['decision']['state'] && 'duplicate' === $run['decisions'][1]['decision']['suppressed_by'], fs_dump( $run['decisions'] ) );

// ---- Opted-out contacts: nothing suggested, nothing recorded -------------------------------
$run = $R::sweep( array( fs_enquiry( 8, 'cust-c' ), fs_enquiry( 9, 'cust-c' ) ), $NOW, $TZ, array(), $OUT );
$all_opt = true;
foreach ( $run['decisions'] as $d ) { if ( 'opt_out' !== $d['decision']['suppressed_by'] ) { $all_opt = false; } }
fs_check( 'opted-out customer gets no suggestion in a run', $all_opt && array() === $run['history'], fs_dump( $run['decisions'] ) );

// ---- Existing history counts toward the throttle; withdrawn entries do not ------------------
$hist = array(
    array( 'enquiry_id' => 90, 'customer_key' => 'cust-d', 'state' => 'sent_by_owner', 'at_ts' => $NOW - 2 * 86400 ),
    array( 'enquiry_id' => 91, 'customer_key' => 'cust-d', 'state' => 'withdrawn', 'at_ts' => $NOW - 86400 ),
);
$run = $R::sweep( array( fs_enquiry( 10, 'cust-d' ) ), $NOW, $TZ, $hist, $OK );
fs_check( 'one owner-sent follow-up plus a withdrawn one leaves room for a suggestion', 'suggested' === $run['decisions'][0]['decision']['state'], fs_dump( $run['decisions'] ) );

$hist[] = array( 'enquiry_id' => 92, 'customer_key' => 'cust-d', 'state' => 'suggested', 'at_ts' => $NOW - 3600 );
$run = $R::sweep( array( fs_enquiry( 10, 'cust-d' ) ), $NOW, $TZ, $hist, $OK );
fs_check( 'two counted follow-ups in seven days throttle the next one', 'throttle' === $run['decisions'][0]['decision']['suppressed_by'], fs_dump( $run['decisions'] ) );

// ---- Sent and unsent state: a sweep never records a send, and the caller's history is untouched
$before = array( array( 'enquiry_id' => 1, 'customer_key' => 'cust-e', 'state' => 'suggested', 'at_ts' => $NOW - 3600 ) );
$copy   = $before;
$run    = $R::sweep( array( fs_enquiry( 11, 'cust-f' ) ), $NOW, $TZ, $before, $OK );
fs_check( 'caller history is not modified by a sweep', $copy === $before );
fs_check( 'a sweep never records sent_by_owner', 0 === count( array_filter( $run['history'], static function ( $h ) { return 'sent_by_owner' === $h['state']; } ) ) );
fs_check( 'a suggestion carries no sent flag', ! isset( $run['decisions'][0]['decision']['sent'] ) );

// ---- Replay: the same batch gives the same answer --------------------------------------------
$r1 = $R::sweep( $batch, $NOW, $TZ, array(), $OK );
$r2 = $R::sweep( $batch, $NOW, $TZ, array(), $OK );
fs_check( 'batch sweep is deterministic on replay', $r1 === $r2 );

// ---- Bad timezone and consent: every enquiry in the batch is suppressed, nothing recorded ---
$run = $R::sweep( array( fs_enquiry( 12, 'cust-g' ), fs_enquiry( 13, 'cust-h', array( 'contact_consent' => false ) ) ), $NOW, 'Mars/Olympus', array(), $OK );
fs_check( 'bad timezone suppresses every enquiry in the run', 'bad_timezone' === $run['decisions'][0]['decision']['suppressed_by'] && array() === $run['history'], fs_dump( $run['decisions'] ) );
$run = $R::sweep( array( fs_enquiry( 13, 'cust-h', array( 'contact_consent' => false ) ) ), $NOW, $TZ, array(), $OK );
fs_check( 'no consent is suppressed in a run', 'no_consent' === $run['decisions'][0]['decision']['suppressed_by'] );

// ---- Non-array rows are skipped and never produce a suggestion -----------------------------
$run = $R::sweep( array( 'junk', fs_enquiry( 14, 'cust-i' ) ), $NOW, $TZ, array(), $OK );
fs_check( 'non-array rows are skipped', 1 === count( $run['decisions'] ) && 14 === $run['decisions'][0]['enquiry_id'] );

echo 'Passed: ' . $pass . PHP_EOL;
echo 'Failed: ' . count( $fail ) . PHP_EOL;
foreach ( $fail as $f ) { echo 'FAIL: ' . $f . PHP_EOL; }
exit( empty( $fail ) ? 0 : 1 );
