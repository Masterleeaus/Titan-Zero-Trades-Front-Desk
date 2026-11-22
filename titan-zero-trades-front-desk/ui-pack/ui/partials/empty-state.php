<?php
/** Partial: honest empty or unavailable state. Expects $tzfd_empty_title and $tzfd_empty_text. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="tz-app-empty" role="status">
	<?php if ( ! empty( $tzfd_empty_title ) ) : ?>
		<strong><?php echo esc_html( (string) $tzfd_empty_title ); ?></strong>
	<?php endif; ?>
	<span><?php echo esc_html( isset( $tzfd_empty_text ) ? (string) $tzfd_empty_text : '' ); ?></span>
</div>
