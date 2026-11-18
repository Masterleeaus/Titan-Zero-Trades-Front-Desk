<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Retired shortcodes (issue #185). [tz_fd_ai_assistant] was an empty stub: it is no longer registered, so it is not
 * in the shortcode list. Pages saved with the tag would otherwise show the raw tag text, so the tag is removed from
 * post content before shortcodes run. Surrounding content is left as it is.
 *
 * GPL-2.0-only.
 */
final class TZFD_Retired_Shortcodes {

	const TAG = 'tz_fd_ai_assistant';

	/** Hook the content filter. Runs before do_shortcode() (priority 11), so the tag never reaches the shortcode parser. */
	public static function boot() {
		add_filter( 'the_content', array( __CLASS__, 'strip' ), 0 );
	}

	/**
	 * Remove opening and closing forms of the retired tag, with or without attributes.
	 *
	 * @param mixed $content Post content.
	 * @return mixed Content with the retired tag removed. Non-string input is returned unchanged.
	 */
	public static function strip( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[' ) ) {
			return $content;
		}
		return preg_replace( '/\[\/?' . preg_quote( self::TAG, '/' ) . '(?:\s[^\]]*)?\]/', '', $content );
	}
}
