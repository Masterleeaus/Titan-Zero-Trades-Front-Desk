<?php
/**
 * Partial: read-only summary of one record, shown above the existing editor.
 * Expects $tz_detail = array('title', 'rows' => [array(label, value)], 'back' => array('url', 'label')).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz_domain = isset( $tz_app['domain'] ) ? (string) $tz_app['domain'] : 'default';
$tz_detail = isset( $tz_detail ) && is_array( $tz_detail ) ? $tz_detail : array();
$tz_rows   = isset( $tz_detail['rows'] ) && is_array( $tz_detail['rows'] ) ? $tz_detail['rows'] : array();
$tz_back   = isset( $tz_detail['back'] ) && is_array( $tz_detail['back'] ) ? $tz_detail['back'] : array();
?>
<section class="tz-app-card tz-app-detail" aria-labelledby="tz-app-detail-title">
	<div class="tz-app-card-head">
		<h2 id="tz-app-detail-title"><?php echo esc_html( isset( $tz_detail['title'] ) ? (string) $tz_detail['title'] : '' ); ?></h2>
		<?php if ( ! empty( $tz_back['url'] ) ) : ?>
			<a class="button button-secondary" href="<?php echo esc_url( (string) $tz_back['url'] ); ?>">&larr; <?php echo esc_html( isset( $tz_back['label'] ) ? (string) $tz_back['label'] : __( 'Back', 'titan-zero-trades-front-desk' ) ); ?></a>
		<?php endif; ?>
	</div>
	<dl>
		<?php foreach ( $tz_rows as $tz_pair ) : ?>
			<div class="tz-app-dl-row">
				<dt><?php echo esc_html( (string) ( $tz_pair[0] ?? '' ) ); ?></dt>
				<dd><?php echo esc_html( (string) ( $tz_pair[1] ?? '' ) ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
</section>
