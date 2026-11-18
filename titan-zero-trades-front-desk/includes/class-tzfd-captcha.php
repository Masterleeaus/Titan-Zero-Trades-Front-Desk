<?php
/** Independent font-file-free CAPTCHA primitives. GPL-2.0-only. */
if (!defined('ABSPATH')) { exit; }
require_once dirname( __DIR__ ) . '/includes/class-tzfd-request-scalar.php';

class TZFD_Captcha {
    /** Fixed server-side code length. A request cannot choose a shorter code. 30^7 is about 2.2e10 codes. */
    const CODE_LENGTH = 7;
    /** Wrong answers allowed for one challenge before it is withdrawn. */
    const MAX_ATTEMPTS = 5;
    /** Wrong answers allowed from one client address per window, across all challenges. */
    const IP_MAX_FAILURES = 20;
    const IP_WINDOW = 900;
    const CHALLENGE_TTL = 1800;

    private static function bounded($tz_value, $default, $minimum, $maximum) {
        if ((!is_string($tz_value) && !is_int($tz_value)) || !is_numeric($tz_value)) { return $default; }
        $number = (float) $tz_value;
        if (!is_finite($number)) { return $default; }
        return (int) max($minimum, min($maximum, $number));
    }

    public static function configuration($request) {
        if (!is_array($request)) { $request = array(); }
        $spec = array('width'=>array(180,120,800), 'height'=>array(60,40,600),
            'letter_count'=>array(5,3,12), 'min_size'=>array(25,12,72), 'max_size'=>array(35,12,72),
            'noise'=>array(200,0,1000), 'noiselength'=>array(5,1,50));
        $config = array();
        foreach ($spec as $tz_key => $limits) {
            $config[$tz_key] = self::bounded(isset($request[$tz_key]) ? $request[$tz_key] : null, $limits[0], $limits[1], $limits[2]);
        }
        // The code length is not taken from the request; a short code made brute force cheap.
        $config['letter_count'] = self::CODE_LENGTH;
        $config['max_size'] = max($config['min_size'], $config['max_size']);
        $config['width'] = max($config['width'], $config['letter_count'] * 16 + 30);
        foreach (array('bcolor'=>'FFFFFF', 'border'=>'000000') as $tz_key => $default) {
            $tz_value = isset($request[$tz_key]) && is_string($request[$tz_key]) ? $request[$tz_key] : '';
            $config[$tz_key] = preg_match('/^[a-fA-F0-9]{6}$/D', $tz_value) ? strtoupper($tz_value) : $default;
        }
        return $config;
    }

    public static function rgb($hex) {
        $tz_value = hexdec($hex);
        return array(($tz_value >> 16) & 255, ($tz_value >> 8) & 255, $tz_value & 255);
    }

    private static function random($min, $max) {
        return function_exists('wp_rand') ? wp_rand($min, $max) : random_int($min, $max);
    }

    public static function code($length) {
        $length = self::bounded($length, 5, 3, 12);
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($tz_i = 0; $tz_i < $length; $tz_i++) { $code .= $alphabet[self::random(0, strlen($alphabet) - 1)]; }
        return $code;
    }

    /**
     * Wrong answers allowed per client address. Production always uses IP_MAX_FAILURES.
     * A test-only override (issue #196, owner decision: keep the limit, add a test-only override) applies
     * only when BOTH constants are defined on a test site: TZFD_CAPTCHA_TEST_MODE is true and
     * TZFD_CAPTCHA_TEST_IP_MAX_FAILURES is a positive integer. Neither constant is set by the plugin.
     */
    public static function ip_max_failures() {
        if (defined('TZFD_CAPTCHA_TEST_MODE') && true === TZFD_CAPTCHA_TEST_MODE && defined('TZFD_CAPTCHA_TEST_IP_MAX_FAILURES')) {
            $override = filter_var(TZFD_CAPTCHA_TEST_IP_MAX_FAILURES, FILTER_VALIDATE_INT);
            if (false !== $override && $override >= 1) { return $override; }
        }
        return self::IP_MAX_FAILURES;
    }

    /** Client address used to count failed answers. Stored as a hash, never as the address itself. */
    public static function client_key() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? tzfd_request_text( 'server', 'REMOTE_ADDR' ) : '';
        return md5('' !== $ip ? $ip : 'unknown');
    }

    /**
     * True only when the answer matches the challenge that the browser holds, and the challenge and the client
     * are still under their wrong-answer limits. Each wrong answer counts against the challenge and the address.
     * A challenge that reaches MAX_ATTEMPTS is withdrawn, so no further guess against it can pass.
     */
    public static function check_answer($challenge_id, $answer, $client) {
        $challenge_id = sanitize_key((string) $challenge_id);
        $answer       = strtolower(trim((string) $answer));
        $client       = preg_replace('/[^a-f0-9]/', '', (string) $client);
        $client_key   = 'tzfd-captcha-ip-' . $client;
        $code         = '' !== $challenge_id ? get_transient('tzfd-captcha-' . $challenge_id) : false;

        if ((int) get_transient($client_key) >= self::ip_max_failures()) { return false; }
        if (!is_string($code) || '' === $code) { self::count_failure($client_key, '', 0); return false; }

        $tries_key = 'tzfd-captcha-tries-' . $challenge_id;
        $tries = (int) get_transient($tries_key);
        if ($tries >= self::MAX_ATTEMPTS) { return false; }
        if ('' !== $answer && hash_equals(strtolower($code), $answer)) { return true; }

        $tries++;
        if ($tries >= self::MAX_ATTEMPTS) {
            set_transient('tzfd-captcha-' . $challenge_id, '', self::CHALLENGE_TTL);
        }
        self::count_failure($client_key, $tries_key, $tries);
        return false;
    }

    private static function count_failure($client_key, $tries_key, $tries) {
        if ('' !== $tries_key) { set_transient($tries_key, $tries, self::CHALLENGE_TTL); }
        set_transient($client_key, (int) get_transient($client_key) + 1, self::IP_WINDOW);
    }

    /** Return a GD image using only GD's built-in bitmap glyphs. */
    public static function image($configuration, $code) {
        if (!function_exists('imagecreatetruecolor')) { throw new RuntimeException('GD image support is unavailable'); }
        if (!is_string($code) || !preg_match('/^[a-z2-9]{3,12}$/D', $code)) { throw new InvalidArgumentException('Invalid challenge'); }
        $c = self::configuration($configuration);
        $image = imagecreatetruecolor($c['width'], $c['height']);
        $rgb = self::rgb($c['bcolor']);
        $background = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($image, 0, 0, $background);
        $contrast = ($rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114) / 1000 > 128 ? 25 : 235;
        $cell = ($c['width'] - 30) / strlen($code);
        foreach (str_split($code) as $index => $character) {
            $glyph_width = imagefontwidth(5); $glyph_height = imagefontheight(5);
            $glyph = imagecreatetruecolor($glyph_width, $glyph_height);
            imagefill($glyph, 0, 0, imagecolorallocate($glyph, $rgb[0], $rgb[1], $rgb[2]));
            imagestring($glyph, 5, 0, 0, $character, imagecolorallocate($glyph, $contrast, $contrast, $contrast));
            $height = min($c['height'] - 12, self::random($c['min_size'], $c['max_size']));
            $width = max(1, min((int) $cell - 4, (int) round($glyph_width * $height / $glyph_height)));
            $x = (int) round(15 + $index * $cell + ($cell - $width) / 2);
            $y = (int) round(($c['height'] - $height) / 2);
            imagecopyresampled($image, $glyph, $x, $y, 0, 0, $width, $height, $glyph_width, $glyph_height);
            // imagedestroy() does nothing from PHP 8.0 and is deprecated in PHP 8.5, so it runs only on older PHP.
            if (PHP_VERSION_ID < 80500) { imagedestroy($glyph); }
        }
        for ($tz_i = 0; $tz_i < $c['noise']; $tz_i++) {
            $color = imagecolorallocatealpha($image, $contrast, $contrast, $contrast, self::random(95,115));
            $x = self::random(0, $c['width'] - 1); $y = self::random(0, $c['height'] - 1);
            imageline($image, $x, $y, $x + self::random(1,$c['noiselength']), $y + self::random(1,$c['noiselength']), $color);
        }
        $border = self::rgb($c['border']);
        imagerectangle($image, 0, 0, $c['width'] - 1, $c['height'] - 1, imagecolorallocate($image, $border[0], $border[1], $border[2]));
        return $image;
    }
}
