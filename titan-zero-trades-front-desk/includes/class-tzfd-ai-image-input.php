<?php
/** Validate untrusted AI-assistant image data before private storage. GPL-2.0-only. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_AI_Image_Input {
    const MAX_ENCODED = 6291456;
    const MAX_BYTES = 4194304;
    public static function decode( $raw ) {
        if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_ENCODED ||
            ! preg_match( '~\Adata:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/]+={0,2})\z~iD', $raw, $match ) ) { return null; }
        $encoded = $match[2];
        if ( strlen( $encoded ) % 4 !== 0 ) { return null; }
        $bytes = base64_decode( $encoded, true );
        if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ||
            ! hash_equals( base64_encode( $bytes ), $encoded ) ) { return null; }
        $image = @getimagesizefromstring( $bytes );
        $types = array( IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp' );
        if ( ! is_array( $image ) || ! isset( $image[0], $image[1], $image[2] ) ||
            ! isset( $types[ $image[2] ] ) || $image[0] < 1 || $image[1] < 1 ||
            $image[0] > 8000 || $image[1] > 8000 || $image[0] * $image[1] > 25000000 ) { return null; }
        $declared = strtolower( $match[1] );
        $expected = 'jpg' === $declared || 'jpeg' === $declared ? 'jpg' : $declared;
        if ( $expected !== $types[ $image[2] ] ) { return null; }
        return array( 'bytes' => $bytes, 'extension' => $expected );
    }
}
