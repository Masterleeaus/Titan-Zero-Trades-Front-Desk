<?php
/** Partial: honest empty or unavailable state. Expects $tz_empty_title and $tz_empty_text. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="tz-app-empty" role="status">
	<?php if ( ! empty( $tz_empty_title ) ) : ?>
		<strong><?php echo esc_html( (string) $tz_empty_title ); ?></strong>
	<?php endif; ?>
	<span><?php echo esc_html( isset( $tz_empty_text ) ? (string) $tz_empty_text : '' ); ?></span>
</div>
