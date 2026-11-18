<?php
/** Partial: one status badge. Expects $tzfd_badge_text and $tzfd_badge_tone (ink, info, ok, warn, bad, neutral). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tzfd_allowed = array( 'ink', 'info', 'ok', 'warn', 'bad', 'neutral' );
$tzfd_tone    = isset( $tzfd_badge_tone ) ? sanitize_key( (string) $tzfd_badge_tone ) : 'neutral';
if ( ! in_array( $tzfd_tone, $tzfd_allowed, true ) ) { $tzfd_tone = 'neutral'; }
?>
<span class="tz-app-badge tz-app-badge--<?php echo esc_attr( $tzfd_tone ); ?>"><?php echo esc_html( isset( $tzfd_badge_text ) ? (string) $tzfd_badge_text : '' ); ?></span>
