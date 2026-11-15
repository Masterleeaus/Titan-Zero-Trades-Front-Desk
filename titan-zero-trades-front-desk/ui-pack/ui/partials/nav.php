<?php
/**
 * Partial: navigation built from this plugin's registered admin pages.
 * Expects $tz_app (label), $tz_nav (array of page, label, url) and $tz_current (page slug).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz_nav     = isset( $tz_nav ) && is_array( $tz_nav ) ? $tz_nav : array();
$tz_current = isset( $tz_current ) ? (string) $tz_current : '';
$tz_label   = isset( $tz_app['label'] ) ? (string) $tz_app['label'] : '';
$tz_domain  = isset( $tz_app['domain'] ) ? (string) $tz_app['domain'] : 'default';
if ( ! empty( $tz_nav ) ) :
?>
<nav class="tz-app-nav" aria-label="<?php echo esc_attr( $tz_label . ' ' . __( 'pages', 'titan-zero-trades-front-desk' ) ); ?>">
	<ul>
		<?php foreach ( $tz_nav as $tz_item ) :
			$tz_is_here = isset( $tz_item['page'] ) && $tz_item['page'] === $tz_current;
			?>
			<li>
				<a href="<?php echo esc_url( (string) $tz_item['url'] ); ?>"<?php echo $tz_is_here ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html( (string) $tz_item['label'] ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
<?php endif; ?>
