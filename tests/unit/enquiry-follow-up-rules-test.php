<?php
/**
 * Unit checks for TZFD_Enquiry_Follow_Up_Rules (issue #33). Pure PHP, no WordPress needed.
 * Run: php tests/unit/enquiry-follow-up-rules-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/class-tzfd-enquiry-follow-up-rules.php';

$failures = 0;
$passes   = 0;
function tzfd_fu_check( $label, $cond, $detail = '' ) {
    global $failures, $passes;
    if ( $cond ) { $passes++; return; }
    $failures++;
    echo "FAIL: {$label}" . ( '' !== $detail ? " | {$detail}" : '' ) . "\n";
}

$R   = 'TZFD_Enquiry_Follow_Up_Rules';
$TZ  = 'Australia/Melbourne';
$MEL = new DateTimeZone( $TZ );

/** Unix time of a local Melbourne wall-clock string. */
function tzfd_fu_at( $s ) {
    global $MEL;
    return ( new DateTimeImmutable( $s, $MEL ) )->getTimestamp();
}
/** Local Melbourne 'Y-m-d H:i' of a Unix time. */
function tzfd_fu_local( $ts ) {
    global $MEL;
    return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $MEL )->format( 'Y-m-d H:i' );
}

// Base enquiry: company 7, received Wednesday 08:00 local, checked at 12:00 (4 hours later, business hours).
$now  = tzfd_fu_at( '2026-11-18 12:00:00' );
$base = array(
    'company_id' => 7,
    'enquiry_id' => 101,
    'status'     => 'new',
    'received'   => tzfd_fu_at( '2026-11-18 08:00:00' ),
    'name'       => 'Sam',
    'email'      => 'sam@example.com',
    'phone'      => '',
    'spam_held'  => false,
    'opted_out'  => false,
);
$ctx = array( 'now' => $now, 'tz' => $TZ, 'suggestions' => array(), 'contact_history' => array() );

// Classification bands.
tzfd_fu_check( 'band: 30 minutes is new', 'new' === $R::classify( 'new', 1800 ) );
tzfd_fu_check( 'band: 4 hours is unanswered', 'unanswered' === $R::classify( 'new', 14400 ) );
tzfd_fu_check( 'band: 4 days is stale', 'stale' === $R::classify( 'new', 345600 ) );
tzfd_fu_check( 'band: reviewed is closed (owner handled)', 'closed' === $R::classify( 'reviewed', 14400 ) );
tzfd_fu_check( 'band: unknown status fails closed', 'closed' === $R::classify( 'mystery', 14400 ) );
tzfd_fu_check( 'band: future-dated receipt is new, not negative age', 'new' === $R::classify( 'new', -60 ) );

// Happy path: unanswered, in business hours, proposed for now, owner approval only.
$d = $R::evaluate( $base, $ctx );
tzfd_fu_check( 'unanswered enquiry in business hours gets a suggestion', 'suggest' === $d['action'] && 'unanswered' === $d['state'], json_encode( $d ) );
tzfd_fu_check( 'proposed time is now inside the window', $now === $d['propose_at'] );
tzfd_fu_check( 'never auto-sent', false === $d['auto_send'] );
tzfd_fu_check( 'draft ends with the opt-out line', '' !== $d['text'] && substr( $d['text'], -strlen( $R::OPT_OUT_LINE ) ) === $R::OPT_OUT_LINE );
tzfd_fu_check( 'local template source', 'local' === $d['text_source']);

// Suppressions, each with its reason.
$d = $R::evaluate( array_merge( $base, array( 'received' => tzfd_fu_at( '2026-11-18 11:30:00' ) ) ), $ctx );
tzfd_fu_check( 'too recent: no suggestion', 'none' === $d['action'] && in_array( 'too_recent', $d['reasons'], true ) );
$d = $R::evaluate( array_merge( $base, array( 'status' => 'reviewed' ) ), $ctx );
tzfd_fu_check( 'reviewed enquiry: not_open', in_array( 'not_open', $d['reasons'], true ) );
$d = $R::evaluate( array_merge( $base, array( 'spam_held' => true ) ), $ctx );
tzfd_fu_check( 'spam hold suppresses follow-up', in_array( 'spam_held', $d['reasons'], true ) && 'none' === $d['action'] );
$d = $R::evaluate( array_merge( $base, array( 'opted_out' => true ) ), $ctx );
tzfd_fu_check( 'opted-out contact is suppressed', in_array( 'opted_out', $d['reasons'], true ) && 'none' === $d['action'] );
$d = $R::evaluate( array_merge( $base, array( 'email' => '', 'phone' => '' ) ), $ctx );
tzfd_fu_check( 'no contact details: no suggestion', in_array( 'no_contact', $d['reasons'], true ) );
$d = $R::evaluate( array_merge( $base, array( 'received' => tzfd_fu_at( '2026-11-14 12:00:00' ) ) ), $ctx );
tzfd_fu_check( 'stale enquiry is highlighted, not suggested', 'stale' === $d['state'] && in_array( 'stale', $d['reasons'], true ) );
$d = $R::evaluate( $base, array( 'now' => 0, 'tz' => $TZ ) );
tzfd_fu_check( 'invalid clock refused', in_array( 'invalid_input', $d['reasons'], true ) && 'none' === $d['action'] );
$d = $R::evaluate( array_merge( $base, array( 'company_id' => 0 ) ), $ctx );
tzfd_fu_check( 'zero company refused', in_array( 'invalid_input', $d['reasons'], true ) );
$d = $R::evaluate( $base, array( 'now' => $now, 'tz' => 'Mars/Olympus', 'suggestions' => array(), 'contact_history' => array() ) );
tzfd_fu_check( 'bad timezone: fail closed', in_array( 'bad_timezone', $d['reasons'], true ) && 'none' === $d['action'] );

// Duplicate prevention: one suggestion per enquiry, whatever its state.
foreach ( array( 'proposed', 'sent', 'dismissed' ) as $st ) {
    $d = $R::evaluate( $base, array_merge( $ctx, array( 'suggestions' => array( array( 'company_id' => 7, 'enquiry_id' => 101, 'state' => $st ) ) ) ) );
    tzfd_fu_check( "duplicate: existing {$st} suggestion blocks a second one", in_array( 'already_suggested', $d['reasons'], true ) );
}
$d = $R::evaluate( $base, array_merge( $ctx, array( 'suggestions' => array( array( 'company_id' => 8, 'enquiry_id' => 101, 'state' => 'sent' ) ) ) ) );
tzfd_fu_check( 'tenant: another company\'s suggestion for the same enquiry id does not block', 'suggest' === $d['action'] );

// Per-customer throttling, with tenant scope and dismissed suggestions not counting.
$key = $R::contact_key( 'sam@example.com', '' );
$d = $R::evaluate( $base, array_merge( $ctx, array( 'contact_history' => array( array( 'company_id' => 7, 'contact_key' => $key, 'ts' => $now - 3600, 'state' => 'sent' ) ) ) ) );
tzfd_fu_check( 'throttle: one suggestion for this contact in 24 hours', in_array( 'contact_cooldown', $d['reasons'], true ) );
$d = $R::evaluate( $base, array_merge( $ctx, array( 'contact_history' => array(
    array( 'company_id' => 7, 'contact_key' => $key, 'ts' => $now - 2 * 86400, 'state' => 'sent' ),
    array( 'company_id' => 7, 'contact_key' => $key, 'ts' => $now - 4 * 86400, 'state' => 'approved' ),
) ) ) );
tzfd_fu_check( 'throttle: two suggestions in 7 days is the weekly limit', in_array( 'contact_weekly_limit', $d['reasons'], true ) );
$d = $R::evaluate( $base, array_merge( $ctx, array( 'contact_history' => array( array( 'company_id' => 7, 'contact_key' => $key, 'ts' => $now - 3600, 'state' => 'dismissed' ) ) ) ) );
tzfd_fu_check( 'throttle: a dismissed suggestion does not count', 'suggest' === $d['action'] );
$d = $R::evaluate( $base, array_merge( $ctx, array( 'contact_history' => array( array( 'company_id' => 8, 'contact_key' => $key, 'ts' => $now - 3600, 'state' => 'sent' ) ) ) ) );
tzfd_fu_check( 'tenant: another company\'s contact history does not throttle', 'suggest' === $d['action'] );
$d = $R::evaluate( $base, array_merge( $ctx, array( 'contact_history' => array( array( 'company_id' => 7, 'contact_key' => $key, 'ts' => $now - 8 * 86400, 'state' => 'sent' ) ) ) ) );
tzfd_fu_check( 'throttle: history older than 7 days is ignored', 'suggest' === $d['action'] );

// Timing in the local window (Australia/Melbourne).
$d = $R::evaluate( array_merge( $base, array( 'received' => tzfd_fu_at( '2026-11-18 16:00:00' ) ) ), array_merge( $ctx, array( 'now' => tzfd_fu_at( '2026-11-18 20:00:00' ) ) ) );
tzfd_fu_check( 'evening enquiry: proposed next morning 09:00 local', '2026-11-19 09:00' === tzfd_fu_local( $d['propose_at'] ), tzfd_fu_local( $d['propose_at'] ) );
$d = $R::evaluate( array_merge( $base, array( 'received' => tzfd_fu_at( '2026-11-20 12:00:00' ) ) ), array_merge( $ctx, array( 'now' => tzfd_fu_at( '2026-11-20 18:00:00' ) ) ) );
tzfd_fu_check( 'Friday evening: proposed Monday 09:00 local', '2026-11-23 09:00' === tzfd_fu_local( $d['propose_at'] ), tzfd_fu_local( $d['propose_at'] ) );
tzfd_fu_check( 'Saturday: proposed Monday 09:00 local', '2026-11-23 09:00' === tzfd_fu_local( $R::next_window( tzfd_fu_at( '2026-11-21 11:00:00' ), $TZ ) ) );
tzfd_fu_check( 'before opening (07:00): proposed 09:00 the same day', '2026-11-18 09:00' === tzfd_fu_local( $R::next_window( tzfd_fu_at( '2026-11-18 07:00:00' ), $TZ ) ) );
tzfd_fu_check( 'DST end weekend (Sat 4 Apr 2026): proposed Mon 6 Apr 09:00 local', '2026-04-06 09:00' === tzfd_fu_local( $R::next_window( tzfd_fu_at( '2026-04-04 12:00:00' ), $TZ ) ) );
tzfd_fu_check( 'window end is exclusive: 17:00 rolls to next day', '2026-11-19 09:00' === tzfd_fu_local( $R::next_window( tzfd_fu_at( '2026-11-18 17:00:00' ), $TZ ) ) );

// Contact keys.
tzfd_fu_check( 'contact key: email case and spaces are one contact', $R::contact_key( ' Sam@Example.com ', '' ) === $R::contact_key( 'sam@example.com', '' ) );
tzfd_fu_check( 'contact key: phone formatting is one contact', $R::contact_key( '', '0400 000 000' ) === $R::contact_key( '', '0400000000' ) );
tzfd_fu_check( 'contact key: no contact is empty', '' === $R::contact_key( '', '' ) );

// Draft text: no price, first name only, opt-out line, length limit.
tzfd_fu_check( 'first name keeps letters, apostrophe and hyphen', 'Mary-Jane' === $R::first_name( 'Mary-Jane O\'Neil' ) );
tzfd_fu_check( 'first name drops markup', false === strpos( $R::first_name( '<img src=x onerror=1>Sam' ), '<' ) );
tzfd_fu_check( 'no name: greeting without a name', 0 === strpos( $R::local_template( '' ), 'Hi there,' ) );
$t = $R::local_template( '<script>Jo</script>' );
tzfd_fu_check( 'template never contains markup from a name', false === strpos( $t, '<' ) && false === strpos( $t, '>' ) );
tzfd_fu_check( 'template has no currency or price', 0 === preg_match( '/[$€£]|\bprice\b|\bfee\b/iu', $t ) );
$long = str_repeat( 'We can help with your job and we will call you back. ', 20 );
$f    = $R::finish( $long );
tzfd_fu_check( 'long text is cut to the limit', mb_strlen( $f, 'UTF-8' ) <= $R::MAX_TEXT );
tzfd_fu_check( 'long text still ends with the opt-out line', substr( $f, -strlen( $R::OPT_OUT_LINE ) ) === $R::OPT_OUT_LINE );
$f = $R::finish( 'Thanks. reply stop to opt out.' );
tzfd_fu_check( 'opt-out line appears once, in canonical form', 1 === substr_count( $f, $R::OPT_OUT_LINE ) && 'Thanks. ' . $R::OPT_OUT_LINE === $f, $f );

// Hosted draft: optional, and an outage or a rule break falls back to the local template.
$d = $R::draft( 'Sam', function () { throw new RuntimeException( 'proxy down' ); } );
tzfd_fu_check( 'proxy outage (exception): local template used', 'local' === $d['source'] && 'hosted_unavailable' === $d['reason'] );
$d = $R::draft( 'Sam', function () { return null; } );
tzfd_fu_check( 'proxy returns nothing: local template used', 'local' === $d['source'] && 'hosted_unavailable' === $d['reason'] );
$d = $R::draft( 'Sam', function () { return new WP_Error_Stub(); } );
tzfd_fu_check( 'proxy returns an error object: local template used', 'local' === $d['source'] );
$d = $R::draft( 'Sam', function () { return 'Hi Sam, we can do this for $200 today.'; } );
tzfd_fu_check( 'hosted draft with a currency amount is rejected', 'local' === $d['source'] && 'hosted_rejected' === $d['reason'] );
$d = $R::draft( 'Sam', function () { return str_repeat( 'x', 400 ); } );
tzfd_fu_check( 'hosted draft over the length limit is rejected', 'hosted_rejected' === $d['reason'] );
$d = $R::draft( 'Sam', function ( $name ) { return "Hi {$name}, your request is with us. Happy to talk when suits."; } );
tzfd_fu_check( 'hosted draft that passes is used, opt-out line added', 'hosted' === $d['source'] && substr( $d['text'], -strlen( $R::OPT_OUT_LINE ) ) === $R::OPT_OUT_LINE && '' === $d['reason'] );
$d = $R::evaluate( $base, array_merge( $ctx, array( 'hosted' => function () { throw new RuntimeException( 'proxy down' ); } ) ) );
tzfd_fu_check( 'evaluate: outage still gives a suggestion, with the reason', 'suggest' === $d['action'] && 'local' === $d['text_source'] && in_array( 'hosted_unavailable', $d['reasons'], true ) );

// Owner edits and state moves.
$s = array( 'company_id' => 7, 'enquiry_id' => 101, 'state' => 'proposed', 'text' => 'Old' );
$e = $R::apply_owner_edit( $s, 'Hi Sam, happy to help. Call us anytime.', 7 );
tzfd_fu_check( 'owner edit: text kept, state edited, opt-out line added', 'edited' === $e['state'] && false !== strpos( $e['text'], 'Hi Sam, happy to help.' ) && substr( $e['text'], -strlen( $R::OPT_OUT_LINE ) ) === $R::OPT_OUT_LINE );
tzfd_fu_check( 'owner edit: other company refused', 'tenant_mismatch' === $R::apply_owner_edit( $s, 'x', 8 )['error'] );
tzfd_fu_check( 'owner edit: zero company refused', isset( $R::apply_owner_edit( $s, 'x', 0 )['error'] ) );
tzfd_fu_check( 'owner edit: empty text refused', 'empty_text' === $R::apply_owner_edit( $s, '   ', 7 )['error'] );
tzfd_fu_check( 'owner edit: sent suggestion is final', 'not_editable' === $R::apply_owner_edit( array_merge( $s, array( 'state' => 'sent' ) ), 'x', 7 )['error'] );
tzfd_fu_check( 'move: proposed to approved', 'approved' === $R::move( 'proposed', 'approved' ) );
tzfd_fu_check( 'move: approved to sent', 'sent' === $R::move( 'approved', 'sent' ) );
tzfd_fu_check( 'move: sent cannot go back to approved', '' === $R::move( 'sent', 'approved' ) );
tzfd_fu_check( 'move: dismissed is final', '' === $R::move( 'dismissed', 'approved' ) );
tzfd_fu_check( 'move: no skipping approval to sent', '' === $R::move( 'proposed', 'sent' ) );

// Stand-in for an error object returned by a proxy call. Only checked for the not-a-string path.
class WP_Error_Stub {}

echo "\nSummary: {$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
