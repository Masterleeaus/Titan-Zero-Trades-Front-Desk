<?php
/** Independent font-file-free CAPTCHA primitives. GPLv2 or later. */
if (!defined('ABSPATH')) { exit; }

class TZFD_Captcha {
    private static function bounded($tzfd_value, $default, $minimum, $maximum) {
        if ((!is_string($tzfd_value) && !is_int($tzfd_value)) || !is_numeric($tzfd_value)) { return $default; }
        $number = (float) $tzfd_value;
        if (!is_finite($number)) { return $default; }
        return (int) max($minimum, min($maximum, $number));
    }

    public static function configuration($request) {
        if (!is_array($request)) { $request = array(); }
        $spec = array('width'=>array(180,120,800), 'height'=>array(60,40,600),
            'letter_count'=>array(5,3,12), 'min_size'=>array(25,12,72), 'max_size'=>array(35,12,72),
            'noise'=>array(200,0,1000), 'noiselength'=>array(5,1,50));
        $config = array();
        foreach ($spec as $tzfd_key => $limits) {
            $config[$tzfd_key] = self::bounded(isset($request[$tzfd_key]) ? $request[$tzfd_key] : null, $limits[0], $limits[1], $limits[2]);
        }
        $config['max_size'] = max($config['min_size'], $config['max_size']);
        $config['width'] = max($config['width'], $config['letter_count'] * 16 + 30);
        foreach (array('bcolor'=>'FFFFFF', 'border'=>'000000') as $tzfd_key => $default) {
            $tzfd_value = isset($request[$tzfd_key]) && is_string($request[$tzfd_key]) ? $request[$tzfd_key] : '';
            $config[$tzfd_key] = preg_match('/^[a-fA-F0-9]{6}$/D', $tzfd_value) ? strtoupper($tzfd_value) : $default;
        }
        return $config;
    }

    public static function rgb($hex) {
        $tzfd_value = hexdec($hex);
        return array(($tzfd_value >> 16) & 255, ($tzfd_value >> 8) & 255, $tzfd_value & 255);
    }

    private static function random($min, $max) {
        return function_exists('wp_rand') ? wp_rand($min, $max) : random_int($min, $max);
    }

    public static function code($length) {
        $length = self::bounded($length, 5, 3, 12);
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($tzfd_i = 0; $tzfd_i < $length; $tzfd_i++) { $code .= $alphabet[self::random(0, strlen($alphabet) - 1)]; }
        return $code;
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
        for ($tzfd_i = 0; $tzfd_i < $c['noise']; $tzfd_i++) {
            $color = imagecolorallocatealpha($image, $contrast, $contrast, $contrast, self::random(95,115));
            $x = self::random(0, $c['width'] - 1); $y = self::random(0, $c['height'] - 1);
            imageline($image, $x, $y, $x + self::random(1,$c['noiselength']), $y + self::random(1,$c['noiselength']), $color);
        }
        $border = self::rgb($c['border']);
        imagerectangle($image, 0, 0, $c['width'] - 1, $c['height'] - 1, imagecolorallocate($image, $border[0], $border[1], $border[2]));
        return $image;
    }
}
