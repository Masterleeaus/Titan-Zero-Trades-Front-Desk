<?php
/**
 * Retired [tz_fd_ai_assistant] stub (issue #185): not registered as a shortcode, and its tag is removed from
 * post content so old pages show no raw shortcode text. Surrounding content is untouched.
 * Run through tests/unit/run.php, which calls every tzfd_test_* function.
 */

function tzfd_test_retired_ai_shortcode() {
	$root = dirname( __DIR__, 2 ) . '/titan-zero-trades-front-desk';
	require_once $root . '/includes/class-tzfd-retired-shortcodes.php';
	$C = 'TZFD_Retired_Shortcodes';

	/* Not registered: the main plugin file no longer calls add_shortcode for the tag, and it boots the stripper. */
	$main = file_get_contents( $root . '/titan-zero-front-desk.php' );
	tzfd_assert( false === strpos( $main, "add_shortcode( 'tz_fd_ai_assistant'" ), 'main file no longer registers the retired shortcode' );
	tzfd_assert( false !== strpos( $main, 'TZFD_Retired_Shortcodes::boot();' ), 'main file boots the retired-tag stripper' );

	/* Plain tag and tag with attributes are removed. */
	tzfd_assert_same( 'Hello  world', $C::strip( 'Hello [tz_fd_ai_assistant] world' ), 'plain tag is removed' );
	tzfd_assert_same( 'A  B', $C::strip( 'A [tz_fd_ai_assistant title="x" class="y"] B' ), 'tag with attributes is removed' );

	/* Closing tag and enclosed text: only the tags go, the text stays. */
	tzfd_assert_same( 'inside', $C::strip( '[tz_fd_ai_assistant]inside[/tz_fd_ai_assistant]' ), 'opening and closing tags are removed and enclosed text stays' );

	/* Other shortcodes and similar names are not touched. */
	tzfd_assert_same( '[tzfd_ai_answer_box]', $C::strip( '[tzfd_ai_answer_box]' ), 'the working answer box shortcode is untouched' );
	tzfd_assert_same( '[tz_fd_ai_assistant_v2]', $C::strip( '[tz_fd_ai_assistant_v2]' ), 'a longer tag name that starts the same is untouched' );
	tzfd_assert_same( 'plain text', $C::strip( 'plain text' ), 'content without the tag is unchanged' );

	/* Non-string input is returned as given. */
	tzfd_assert_same( null, $C::strip( null ), 'null content is returned unchanged' );
	tzfd_assert_same( 7, $C::strip( 7 ), 'non-string content is returned unchanged' );

	/* The filter is attached at priority 0, before shortcodes run at 11. */
	$src = file_get_contents( $root . '/includes/class-tzfd-retired-shortcodes.php' );
	tzfd_assert( false !== strpos( $src, "add_filter( 'the_content', array( __CLASS__, 'strip' ), 0 );" ), 'stripper is hooked on the_content at priority 0' );
}
