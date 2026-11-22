<?php
/**
 * Partial: read-only summary of one record, shown above the existing editor.
 * Expects $tzfd_detail = array('title', 'rows' => [array(label, value)], 'back' => array('url', 'label')).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tzfd_domain = isset( $tzfd_app['domain'] ) ? (string) $tzfd_app['domain'] : 'default';
$tzfd_detail = isset( $tzfd_detail ) && is_array( $tzfd_detail ) ? $tzfd_detail : array();
$tzfd_local_rows   = isset( $tzfd_detail['rows'] ) && is_array( $tzfd_detail['rows'] ) ? $tzfd_detail['rows'] : array();
$tzfd_back   = isset( $tzfd_detail['back'] ) && is_array( $tzfd_detail['back'] ) ? $tzfd_detail['back'] : array();
?>
<section class="tz-app-card tz-app-detail" aria-labelledby="tz-app-detail-title">
	<div class="tz-app-card-head">
		<h2 id="tz-app-detail-title"><?php echo esc_html( isset( $tzfd_detail['title'] ) ? (string) $tzfd_detail['title'] : '' ); ?></h2>
		<?php if ( ! empty( $tzfd_back['url'] ) ) : ?>
			<a class="button button-secondary" href="<?php echo esc_url( (string) $tzfd_back['url'] ); ?>">&larr; <?php echo esc_html( isset( $tzfd_back['label'] ) ? (string) $tzfd_back['label'] : __( 'Back', 'titan-zero-trades-front-desk' ) ); ?></a>
		<?php endif; ?>
	</div>
	<dl>
		<?php foreach ( $tzfd_local_rows as $tzfd_pair ) : ?>
			<div class="tz-app-dl-row">
				<dt><?php echo esc_html( (string) ( $tzfd_pair[0] ?? '' ) ); ?></dt>
				<dd><?php echo esc_html( (string) ( $tzfd_pair[1] ?? '' ) ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
</section>
