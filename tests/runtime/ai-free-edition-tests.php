<?php
/**
 * Regression checks for issue #2: the Front Desk free edition has no AI execution path.
 *
 * Runs with the PHP CLI only, no WordPress install needed:
 *   php tests/runtime/ai-free-edition-tests.php
 *
 * Not shipped in the plugin. Exits 1 if any check fails.
 */

define( 'ABSPATH', __DIR__ . '/' );

$root   = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
$inc    = $root . '/includes';
$pass   = 0;
$fail   = 0;

function ai_check( $name, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) {
		$pass++;
	} else {
		$fail++;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

// Minimal WordPress stand-ins for the functions the two classes under test call.
if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text ) { return $text; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return $url; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, $product ) {
		return isset( $GLOBALS['ai_test_filter'] ) && is_callable( $GLOBALS['ai_test_filter'] )
			? call_user_func( $GLOBALS['ai_test_filter'], $value, $product )
			: $value;
	}
}

require_once $inc . '/class-tzfd-addon-loader.php';
require_once $inc . '/class-tzfd-upgrades.php';

// 1. Add-on manifest and catalogue.
$ai_files = array();
foreach ( array_keys( TZFD_Addon_Loader::manifest() ) as $relative ) {
	if ( preg_match( '/(^|\/)(ai|assistant|agent)[-.]/i', $relative ) ) {
		$ai_files[] = $relative;
	}
}
ai_check( 'manifest lists no AI add-on file', array() === $ai_files, implode( ',', $ai_files ) );

$ids = TZFD_Addon_Loader::domain_ids();
ai_check( 'catalogue has no tzfd-AIAssistant', ! isset( $ids['tzfd-AIAssistant'] ) );
ai_check( 'catalogue has no tzfd-AISetup', ! isset( $ids['tzfd-AISetup'] ) );
ai_check( 'catalogue keeps booking add-ons', isset( $ids['tzfd-Reminder'], $ids['tzfd-Onboarding'] ) );

// 2. Negative: a legacy saved AI selection is dropped on the next catalogue save.
$kept = TZFD_Addon_Loader::preserve_compatibility_selections(
	array( 'tzfd-AIAssistant', 'tzfd-AISetup' ),
	array()
);
ai_check( 'legacy AI selections are not preserved on save', array() === $kept, implode( ',', $kept ) );

$kept = TZFD_Addon_Loader::preserve_compatibility_selections(
	array( 'tzfd-AIAssistant', 'tzfd-Reminder' ),
	array( 'tzfd-Reminder', 'tzfd-AIAssistant' )
);
ai_check( 'booking add-on survives while AI ID is dropped', array( 'tzfd-Reminder' ) === $kept, implode( ',', $kept ) );

// 3. Negative: an unlisted file in the add-ons folder can never load.
$tmp = sys_get_temp_dir() . '/tzfd-ai-test-' . getmypid();
@mkdir( $tmp . '/core', 0777, true );
file_put_contents( $tmp . '/core/ai-assistant.addon.php', '<?php' );
file_put_contents( $tmp . '/core/base.addon.php', '<?php' );
$loaded = TZFD_Addon_Loader::files( $tmp );
ai_check( 'unlisted AI file is not loaded', ! in_array( $tmp . '/core/ai-assistant.addon.php', $loaded, true ) );
ai_check( 'listed base file is loaded', in_array( $tmp . '/core/base.addon.php', $loaded, true ) );
@unlink( $tmp . '/core/ai-assistant.addon.php' );
@unlink( $tmp . '/core/base.addon.php' );
@rmdir( $tmp . '/core' );
@rmdir( $tmp );

// 4. Upgrade links: no sales or checkout URL in the free build.
$GLOBALS['ai_test_filter'] = null;
ai_check( 'no upgrade cards without a filter', array() === TZFD_Upgrades::cards() );
ai_check( 'no plugin-row upgrade link without a filter', array() === TZFD_Upgrades::plugin_row_link( array() ) );

// Positive: a verified https URL supplied through the filter is shown.
$GLOBALS['ai_test_filter'] = function ( $url, $product ) {
	return 'ai' === $product ? 'https://example.test/assistant' : $url;
};
$cards = TZFD_Upgrades::cards();
ai_check( 'filter-supplied https URL produces one card', 1 === count( $cards ) && 'https://example.test/assistant' === $cards[0]['url'] );

// Negative: a plain http URL from the filter is refused.
$GLOBALS['ai_test_filter'] = function ( $url, $product ) {
	return 'ai' === $product ? 'http://example.test/assistant' : $url;
};
ai_check( 'non-https filter URL is refused', array() === TZFD_Upgrades::cards() );
$GLOBALS['ai_test_filter'] = null;

// 5. Notice dismissal accepts only the speed reminder.
$notices = file_get_contents( $inc . '/class-tzfd-admin-notices.php' );
ai_check( 'dismiss handler allows only speed notice', false !== strpos( $notices, "\$known    = array( 'speed' );" ) );
ai_check( 'no AI key reminder left in notices', false === strpos( $notices, 'ai_key' ) );

// 6. Shortcode for the retired assistant still renders nothing.
$main = file_get_contents( $root . '/titan-zero-front-desk.php' );
ai_check(
	'retired AI shortcode renders empty',
	false !== strpos( $main, "add_shortcode( 'tz_fd_ai_assistant', '__return_empty_string' )" )
);

// 7. Static scan: no outbound AI provider calls anywhere in the plugin PHP.
$php_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$remote    = array();
$ai_hosts  = array();
foreach ( $php_files as $file ) {
	if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/vendor/' ) ) {
		continue;
	}
	$src  = file_get_contents( $file->getPathname() );
	$name = substr( $file->getPathname(), strlen( $root ) + 1 );
	if ( preg_match( '/\b(wp_remote_(post|get|request)|curl_init)\s*\(/', $src ) ) {
		$remote[] = $name;
	}
	if ( preg_match( '/api\.openai\.com|api\.anthropic\.com|generativelanguage\.googleapis|chat\/completions|v1\/messages/i', $src ) ) {
		$ai_hosts[] = $name;
	}
}
ai_check( 'only the Twilio SMS transport makes outbound HTTP calls', array( 'includes/class-tzfd-twilio-sms.php' ) === $remote, implode( ',', $remote ) );
ai_check( 'no AI provider host or endpoint in plugin PHP', array() === $ai_hosts, implode( ',', $ai_hosts ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
