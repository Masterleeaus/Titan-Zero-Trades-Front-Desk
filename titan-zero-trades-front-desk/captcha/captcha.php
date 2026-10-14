<?php
// Titan Zero Trades: Front Desk adapter for the inherited CAPTCHA cookie/transient submission contract.
if (!defined('ABSPATH')) { exit; }
require_once dirname(__DIR__) . '/includes/class-tzfd-captcha.php';

function titan_zero_front_desk_get_captcha() {
    if (!ini_get('zlib.output_compression') && ob_get_level() > 0) { ob_clean(); }
    nocache_headers();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CAPTCHA image request: reads the request only to choose the challenge settings and returns an image. Its only write is a random challenge id, never user data.
    $request = is_array($_GET) ? wp_unslash($_GET) : array();
    $config = TZFD_Captcha::configuration($request);
    $suffix = isset($request['ps']) && is_string($request['ps']) ? substr(sanitize_key($request['ps']), 0, 32) : '';
    $code = TZFD_Captcha::code($config['letter_count']);
    // The answer is kept in a transient under a random id. Only that id reaches the browser, in the legacy cookie name.
    $id = strtolower(wp_generate_password(32, false, false));
    set_transient('tzfd-captcha-' . $id, $code, 1800);
    setcookie('rand_code' . $suffix, $id, time() + 1800, '/', '', is_ssl(), true);
    header('Content-Type: image/png');
    if (!function_exists('imagecreatetruecolor')) {
        // Stream the bundled binary PNG as-is. Escaping does not apply to image bytes.
        $fallback = __DIR__ . '/no-gd-library.png';
        if (is_readable($fallback)) { readfile($fallback); }
        exit;
    }
    $image = TZFD_Captcha::image($config, $code);
    imagepng($image);
    // imagedestroy() does nothing from PHP 8.0 and is deprecated in PHP 8.5, so it runs only on older PHP.
    if (PHP_VERSION_ID < 80500) { imagedestroy($image); }
    exit;
}

titan_zero_front_desk_get_captcha();
