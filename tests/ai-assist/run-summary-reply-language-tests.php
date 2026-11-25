<?php
/**
 * Pure PHP tests for conversation summaries, smart reply drafts and the reply-language settings screen logic.
 * No WordPress: WordPress option and capability functions are stubbed below. Covers privacy (no writes), source links,
 * bounds, role gates, contact redaction and the save rules for the language list.
 * Run: php tests/ai-assist/run-summary-reply-language-tests.php   (from the repository root)
 */
define( 'ABSPATH', __DIR__ );
$dir = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/ai-assist/';
foreach ( array( 'class-tzfd-ai-locale.php', 'class-tzfd-ai-hosted-client.php', 'class-tzfd-ai-multilingual.php', 'class-tzfd-ai-smart-reply.php', 'class-tzfd-ai-conversation-summary.php', 'class-tzfd-ai-language-settings.php' ) as $file ) {
    require_once $dir . $file;
}

/** WordPress stubs. State lives in globals so each test can reset it. */
$GLOBALS['t402_caps']   = array();
$GLOBALS['t402_writes'] = array();
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap ) { return ! empty( $GLOBALS['t402_caps'][ $cap ] ); }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) { return $default; }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value, $autoload = null ) { $GLOBALS['t402_writes'][] = $name; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
    function add_option( $name, $value = '', $deprecated = '', $autoload = null ) { $GLOBALS['t402_writes'][] = $name; return true; }
}

$GLOBALS['t_pass'] = 0; $GLOBALS['t_fail'] = 0;
function t402( $name, $ok ) {
    if ( $ok ) { $GLOBALS['t_pass']++; } else { $GLOBALS['t_fail']++; echo 'FAIL ' . $name . "\n"; }
}

/** Counts hosted calls so a test can prove that no hosted request was made. */
class Fx402_Counter implements TZFD_AI_Hosted_Client {
    public $calls = 0;
    public $text;
    public function __construct( $text = null ) { $this->text = $text; }
    public function translate( $text, $from, $to ) { $this->calls++; return array( 'ok' => false, 'reason' => 'not_used' ); }
    public function draft( $purpose, array $facts, $quoted ) {
        $this->calls++;
        return null === $this->text ? array( 'ok' => false, 'reason' => 'budget_exhausted' ) : array( 'ok' => true, 'text' => $this->text );
    }
}

$owner    = array( 'tz_fd_access' => true );
$stranger = array();
$facts    = array( 'business' => 'Sparkle Cleaners', 'hours' => 'Mon-Fri 8am-5pm' );
$enq      = array( 'name' => 'Sam Jones', 'enquiry' => 'Hi, is Deep Clean available? Email sam@example.com or call 0412 345 678.', 'email' => 'sam@example.com' );

// ---- Reply-language save rules (settings screen logic) ----
$GLOBALS['t402_caps'] = array( 'manage_options' => false );
$GLOBALS['t402_writes'] = array();
t402( 'non-admin save refused', false === TZFD_AI_Multilingual::save_configured_locales( array( 'es' ) ) );
t402( 'non-admin save writes nothing', array() === $GLOBALS['t402_writes'] );

$GLOBALS['t402_caps'] = array( 'manage_options' => true );
$GLOBALS['t402_writes'] = array();
$saved = TZFD_AI_Multilingual::save_configured_locales( array( 'en_au', 'es', 'ES', 'fr', 'de-DE', '<script>', 'ar', 'ja' ) );
t402( 'admin save normalises, dedupes and caps at 5', array( 'en-AU', 'es', 'fr', 'de-DE', 'ar' ) === $saved );
t402( 'save writes one site option key only', array( TZFD_AI_Multilingual::OPTION ) === $GLOBALS['t402_writes'] );
t402( 'parse splits commas, semicolons and spaces', array( 'en-AU', 'es', 'fr', 'ar' ) === TZFD_AI_Language_Settings::parse_input( "en-AU, es;fr  ar\n" ) );
t402( 'parse of non-string is empty', array() === TZFD_AI_Language_Settings::parse_input( null ) );
t402( 'all-invalid input yields no locales', array() === TZFD_AI_Multilingual::clean_locales( array( '<b>', '' ) ) );

// ---- Conversation summary: role gate, consent, privacy, source links, bounds ----
$thread = array(
    array( 'id' => 'm1', 'from' => 'customer', 'text' => 'Can you book Deep Clean? Reach me at jane@example.com or 0412 345 678.', 'at_utc' => '2026-11-20T09:00:00Z' ),
    array( 'id' => 'm2', 'from' => 'owner', 'text' => 'Thanks, we have Tuesday free.', 'at_utc' => '2026-11-20T09:05:00Z' ),
    array( 'id' => 'm3', 'from' => 'customer', 'text' => 'Is Tuesday morning possible?', 'at_utc' => '2026-11-20T09:10:00Z' ),
);

t402( 'summary forbidden without capability', 'forbidden' === TZFD_AI_Conversation_Summary::summarise( $thread, true, array(), $stranger )['status'] );
t402( 'summary blocked without consent', 'blocked' === TZFD_AI_Conversation_Summary::summarise( $thread, false, array(), $owner )['status'] );
t402( 'summary blocked reason is no_consent', 'no_consent' === TZFD_AI_Conversation_Summary::summarise( $thread, null, array(), $owner )['reason'] );

$GLOBALS['t402_writes'] = array();
$sum = TZFD_AI_Conversation_Summary::summarise( $thread, true, array(), $owner );
t402( 'summary produced for owner with consent', 'summary' === $sum['status'] && true === $sum['non_authoritative'] );
t402( 'privacy: summary writes nothing to storage', array() === $GLOBALS['t402_writes'] );
$flat = json_encode( $sum );
t402( 'privacy: raw email not in summary', false === strpos( $flat, 'jane@example.com' ) && true === $sum['redacted'] );
t402( 'privacy: raw phone not in summary', false === strpos( $flat, '0412 345 678' ) );
t402( 'source links: all message ids listed', array( 'm1', 'm2', 'm3' ) === $sum['source_message_ids'] );
t402( 'source links: stated request cites its message', 'm1' === $sum['stated_request_source'] );
t402( 'source links: outstanding question cites its message', 1 === count( $sum['outstanding'] ) && 'm3' === $sum['outstanding'][0]['source'] );
t402( 'not truncated for short thread', false === $sum['truncated'] && array() === array_filter( $sum['uncertainty'], function ( $u ) { return false !== strpos( $u, 'truncated' ); } ) );

$long = array();
for ( $i = 1; $i <= 40; $i++ ) {
    $long[] = array( 'id' => 'L' . $i, 'from' => ( $i % 2 ) ? 'customer' : 'owner', 'text' => 'Message ' . $i, 'at_utc' => '2026-11-20T09:00:00Z' );
}
$bounded = TZFD_AI_Conversation_Summary::bound( $long );
t402( 'cost bound: at most MAX_MESSAGES kept', count( $bounded['messages'] ) <= TZFD_AI_Conversation_Summary::MAX_MESSAGES );
t402( 'cost bound: head and tail kept, flagged truncated', 'L1' === $bounded['messages'][0]['id'] && 'L40' === end( $bounded['messages'] )['id'] && true === $bounded['truncated'] && 10 === $bounded['omitted'] );
$heavy = array();
for ( $i = 1; $i <= 12; $i++ ) {
    $heavy[] = array( 'id' => 'H' . $i, 'from' => 'customer', 'text' => str_repeat( 'a', 1000 ), 'at_utc' => '2026-11-20T09:00:00Z' );
}
$hb = TZFD_AI_Conversation_Summary::bound( $heavy );
t402( 'cost bound: character cap honoured', array_sum( array_map( function ( $m ) { return strlen( $m['text'] ); }, $hb['messages'] ) ) <= TZFD_AI_Conversation_Summary::MAX_TOTAL_CHARS );

$claim = array( array( 'id' => 'c1', 'from' => 'owner', 'text' => 'Your booking is confirmed for Friday.', 'at_utc' => '2026-11-20T09:00:00Z' ) );
$unsupported = TZFD_AI_Conversation_Summary::summarise( $claim, true, array(), $owner );
t402( 'fabrication: unsupported booked claim flagged', ! empty( array_filter( $unsupported['uncertainty'], function ( $u ) { return false !== strpos( $u, 'Unsupported claim' ); } ) ) );
$supported = TZFD_AI_Conversation_Summary::summarise( $claim, true, array( 'confirmed' => true ), $owner );
t402( 'fabrication: confirmed record supports the claim', empty( array_filter( $supported['uncertainty'], function ( $u ) { return false !== strpos( $u, 'Unsupported claim' ); } ) ) );

// ---- Smart reply: role gate, hosted-call count, screen, edit and revert ----
$hosted = new Fx402_Counter( 'Hello Sam, thank you for contacting Sparkle Cleaners.' );
$denied = TZFD_AI_Smart_Reply::draft_for( $stranger, $enq, $facts, 'acknowledge', $hosted );
t402( 'reply: forbidden without capability', 'forbidden' === $denied['status'] && false === $denied['auto_send'] );
t402( 'reply: forbidden makes no hosted call', 0 === $hosted->calls );

$draft = TZFD_AI_Smart_Reply::draft_for( $owner, $enq, $facts, 'acknowledge', $hosted );
t402( 'reply: owner gets a draft that is never auto-sent', 'draft' === $draft['status'] && false === $draft['auto_send'] );
t402( 'reply: one hosted call for owner', 1 === $hosted->calls && 'hosted' === $draft['source'] );
t402( 'reply: enquiry email never in outgoing body', false === strpos( $draft['body'], 'sam@example.com' ) );
t402( 'reply: quoted enquiry has contacts redacted', false === strpos( $draft['quoted_enquiry'], 'sam@example.com' ) && false === strpos( $draft['quoted_enquiry'], '0412 345 678' ) );

$leaky = new Fx402_Counter( 'Hello, write to sam@example.com about the guaranteed slot.' );
$fallback = TZFD_AI_Smart_Reply::draft_for( $owner, $enq, $facts, 'acknowledge', $leaky );
t402( 'reply: hosted draft with email or promise is dropped for local template', 'local' === $fallback['source'] && false === strpos( $fallback['body'], 'sam@example.com' ) );

$edited = TZFD_AI_Smart_Reply::edit_for( $owner, $draft, "Hello Sam, we will reply by Tuesday.\n\nOur hours: Mon-Fri 8am-5pm.", $facts );
t402( 'edit: owner edit accepted and marked edited', true === $edited['edited'] && null === $edited['edit_error'] );
$bad_edit = TZFD_AI_Smart_Reply::edit_for( $owner, $draft, 'Call sam@example.com for a discount', $facts );
t402( 'edit: email or forbidden phrase rejected, body kept', 'rejected' === $bad_edit['edit_error'] && $bad_edit['body'] === $draft['body'] );
$no_role_edit = TZFD_AI_Smart_Reply::edit_for( $stranger, $draft, 'Hello there', $facts );
t402( 'edit: forbidden without capability', 'forbidden' === $no_role_edit['edit_error'] && $no_role_edit['body'] === $draft['body'] );
$reverted = TZFD_AI_Smart_Reply::revert( $edited );
t402( 'edit: revert restores the original draft', $reverted['body'] === $draft['original_body'] && false === $reverted['edited'] );

$pass = $GLOBALS['t_pass']; $fail = $GLOBALS['t_fail'];
echo "Passed: $pass  Failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
