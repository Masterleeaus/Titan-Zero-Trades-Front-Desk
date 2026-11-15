<?php
/** Partial: one status badge. Expects $tz_badge_text and $tz_badge_tone (ink, info, ok, warn, bad, neutral). */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz_allowed = array( 'ink', 'info', 'ok', 'warn', 'bad', 'neutral' );
$tz_tone    = isset( $tz_badge_tone ) ? sanitize_key( (string) $tz_badge_tone ) : 'neutral';
if ( ! in_array( $tz_tone, $tz_allowed, true ) ) { $tz_tone = 'neutral'; }
?>
<span class="tz-app-badge tz-app-badge--<?php echo esc_attr( $tz_tone ); ?>"><?php echo esc_html( isset( $tz_badge_text ) ? (string) $tz_badge_text : '' ); ?></span>
