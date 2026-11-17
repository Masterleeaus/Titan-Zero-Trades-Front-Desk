<?php
/**
 * Tests for issue #26 wiring: administrator-configured languages, the capability check on saving them, consent-gated
 * hosted translation and payload minimisation. Uses the real plugin classes with small WordPress stand-ins, no database.
 * Run: php tests/ai-assist/run-multilingual-wp-tests.php   (from the repository root)
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }

$GLOBALS['mt_pass'] = 0;
$GLOBALS['mt_fail'] = 0;
function mt( $name, $ok ) {
    if ( $ok ) { $GLOBALS['mt_pass']++; } else { $GLOBALS['mt_fail']++; echo 'FAIL ' . $name . "\n"; }
}

// ---- WordPress stand-ins (each declared only when WordPress has not already loaded it) ----------------------------
if ( ! function_exists( '__' ) ) { function __( $text, $domain = '' ) { return $text; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ) { return $thing instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        public function __construct( $code = '', $message = '' ) { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
}

$GLOBALS['mt_options'] = array();
$GLOBALS['mt_caps']    = array();
$GLOBALS['mt_filters'] = array();

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        return array_key_exists( $name, $GLOBALS['mt_options'] ) ? $GLOBALS['mt_options'][ $name ] : $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value, $autoload = null ) {
        $GLOBALS['mt_options'][ $name ] = $value;
        return true;
    }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        return ! empty( $GLOBALS['mt_caps'][ $capability ] );
    }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback ) {
        $GLOBALS['mt_filters'][ $tag ] = $callback;
    }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$args ) {
        if ( ! isset( $GLOBALS['mt_filters'][ $tag ] ) ) { return $value; }
        return call_user_func( $GLOBALS['mt_filters'][ $tag ], $value, ...$args );
    }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag, ...$args ) { return null; }
}

$dir = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk/includes/';
if ( ! class_exists( 'TZFD_Hosted_AI_Connector' ) ) { require_once $dir . 'class-tzfd-hosted-ai-connector.php'; }
if ( ! class_exists( 'TZFD_AI_Multilingual' ) ) { require_once $dir . 'ai-assist/class-tzfd-ai-multilingual.php'; }

/** Records every payload it receives. Returns a translation chosen by the test. */
function mt_connector( $response ) {
    return new class( $response ) {
        public $calls = array();
        private $response;
        public function __construct( $response ) { $this->response = $response; }
        public function send( $capability, $payload ) {
            $this->calls[] = array( 'capability' => $capability, 'payload' => $payload );
            return $this->response;
        }
    };
}

function mt_set_connector( $connector ) {
    add_filter( TZFD_Hosted_AI_Connector::FILTER, function () use ( $connector ) { return $connector; } );
}

$vars = array( 'service' => 'Deep Clean', 'date' => '2026-11-20' );

// ---- Administrator-configured languages ----------------------------------------------------------------------------
$GLOBALS['mt_caps'] = array();
$saved = TZFD_AI_Multilingual::save_configured_locales( array( 'es-MX', 'fr' ) );
mt( 'non-admin cannot save languages', false === $saved && ! isset( $GLOBALS['mt_options'][ TZFD_AI_Multilingual::OPTION ] ) );

$GLOBALS['mt_caps'] = array( 'manage_options' => true );
$saved = TZFD_AI_Multilingual::save_configured_locales( array( 'en_au', '<script>', 'es-MX', 'es-MX', 'fr', 'de', 'it', 'pt', 'nl' ) );
mt( 'admin save drops invalid, dedupes and caps at five', $saved === array( 'en-AU', 'es-MX', 'fr', 'de', 'it' ) );
mt( 'configured_locales reads the saved list', TZFD_AI_Multilingual::configured_locales() === array( 'en-AU', 'es-MX', 'fr', 'de', 'it' ) );

$GLOBALS['mt_options'][ TZFD_AI_Multilingual::OPTION ] = array( '<b>', 'ar', 'not a tag!' );
mt( 'corrupt stored value is sanitised on read', TZFD_AI_Multilingual::configured_locales() === array( 'ar' ) );
$GLOBALS['mt_options'][ TZFD_AI_Multilingual::OPTION ] = 'garbage';
mt( 'non-array stored value reads as empty', TZFD_AI_Multilingual::configured_locales() === array() );

// ---- Fixed texts first, no hosted call -----------------------------------------------------------------------------
$GLOBALS['mt_options'][ TZFD_AI_Multilingual::OPTION ] = array( 'en-AU', 'es' );
$connector = mt_connector( array( 'ok' => true, 'text' => 'should not be used' ) );
mt_set_connector( $connector );
$GLOBALS['mt_options'][ TZFD_Hosted_AI_Connector::CONSENT ] = 'granted';
$r = TZFD_AI_Multilingual::reply_configured( 'booking_confirm_request', 'es-MX', $vars );
mt( 'fixed translation used before hosted', $r['source'] === 'fixed' && $r['text'] === 'Responda para confirmar su cita de Deep Clean el 2026-11-20.' );
mt( 'fixed translation makes no hosted call', count( $connector->calls ) === 0 );

// ---- Consent gate: hosted translation is refused without consent --------------------------------------------------
$GLOBALS['mt_options'] = array( TZFD_AI_Multilingual::OPTION => array( 'en-AU', 'ar' ) );
$connector = mt_connector( array( 'ok' => true, 'text' => 'مرحبا Deep Clean 2026-11-20' ) );
mt_set_connector( $connector );
$r = TZFD_AI_Multilingual::reply_configured( 'contact_human', 'ar', $vars );
mt( 'without consent no hosted call is made', count( $connector->calls ) === 0 );
mt( 'without consent English is returned with handoff', $r['action'] === 'handoff' && $r['source'] === 'english' && $r['reason'] === 'hosted_tzfd_hosted_ai_no_consent' );

// ---- With consent and a connector: accepted hosted translation, minimal payload ------------------------------------
$GLOBALS['mt_options'][ TZFD_Hosted_AI_Connector::CONSENT ] = 'granted';
$connector = mt_connector( array( 'ok' => true, 'text' => 'يرجى الرد لتأكيد موعد Deep Clean في 2026-11-20.' ) );
mt_set_connector( $connector );
$r = TZFD_AI_Multilingual::reply_configured( 'booking_confirm_request', 'ar', $vars );
mt( 'consented hosted translation accepted and RTL', $r['source'] === 'hosted' && $r['action'] === 'use' && $r['dir'] === 'rtl' );
mt( 'hosted call uses the translate capability', count( $connector->calls ) === 1 && $connector->calls[0]['capability'] === 'translate' );
$payload = $connector->calls[0]['payload'];
mt( 'payload holds only approved text and language tags', array_keys( $payload ) === array( 'text', 'from_locale', 'to_locale' ) );
mt( 'payload carries the service name and date verbatim', strpos( $payload['text'], 'Deep Clean' ) !== false && strpos( $payload['text'], '2026-11-20' ) !== false );
mt( 'payload has no customer contact details', strpos( json_encode( $payload ), '@' ) === false );

// ---- Hosted output that is unsafe or mistranslated is refused ------------------------------------------------------
$bad = array(
    'drops the service name'   => array( 'ok' => true, 'text' => 'يرجى الرد لتأكيد موعدكم.' ),
    'contains markup'          => array( 'ok' => true, 'text' => '<b>Deep Clean</b> 2026-11-20' ),
    'not ok'                   => array( 'ok' => false, 'reason' => 'budget_exhausted' ),
    'bad shape'                => 'plain string',
);
foreach ( $bad as $label => $response ) {
    mt_set_connector( mt_connector( $response ) );
    $r = TZFD_AI_Multilingual::reply_configured( 'booking_confirm_request', 'ar', $vars );
    mt( 'hosted output refused: ' . $label, $r['source'] === 'english' && $r['action'] === 'handoff' );
}

// ---- Connector error codes are reported, never fatal -------------------------------------------------------------
mt_set_connector( new WP_Error( 'tzfd_hosted_ai_not_connected' ) );
$r = TZFD_AI_Multilingual::reply_configured( 'contact_human', 'ar', $vars );
mt( 'connector error reported as reason', $r['reason'] === 'hosted_tzfd_hosted_ai_not_connected' && $r['action'] === 'handoff' );

// ---- Invalid input never reaches the connector --------------------------------------------------------------------
$probe = mt_connector( array( 'ok' => true, 'text' => 'x' ) );
mt_set_connector( $probe );
$client = new TZFD_AI_Hosted_Connector_Client();
$out = $client->translate( "bad \xC3\x28 text", 'en-AU', 'ar' );
mt( 'invalid UTF-8 text refused before the connector', $out['ok'] === false && $out['reason'] === 'invalid_input' && count( $probe->calls ) === 0 );
$out = $client->translate( 'Hello', 'en-AU', '<script>' );
mt( 'invalid target tag refused before the connector', $out['ok'] === false && count( $probe->calls ) === 0 );

echo "{$GLOBALS['mt_pass']} passed, {$GLOBALS['mt_fail']} failed\n";
exit( $GLOBALS['mt_fail'] > 0 ? 1 : 0 );
