<?php
/**
 * Partial: navigation built from this plugin's registered admin pages.
 * Expects $tzfd_app (label), $tzfd_nav (array of page, label, url) and $tzfd_current (page slug).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tzfd_nav     = isset( $tzfd_nav ) && is_array( $tzfd_nav ) ? $tzfd_nav : array();
$tzfd_current = isset( $tzfd_current ) ? (string) $tzfd_current : '';
$tzfd_label   = isset( $tzfd_app['label'] ) ? (string) $tzfd_app['label'] : '';
$tzfd_domain  = isset( $tzfd_app['domain'] ) ? (string) $tzfd_app['domain'] : 'default';
if ( ! empty( $tzfd_nav ) ) :
?>
<nav class="tz-app-nav" aria-label="<?php echo esc_attr( $tzfd_label . ' ' . __( 'pages', 'titan-zero-trades-front-desk' ) ); ?>">
	<ul>
		<?php foreach ( $tzfd_nav as $tzfd_item ) :
			$tzfd_is_here = isset( $tzfd_item['page'] ) && $tzfd_item['page'] === $tzfd_current;
			?>
			<li>
				<a href="<?php echo esc_url( (string) $tzfd_item['url'] ); ?>"<?php echo $tzfd_is_here ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html( (string) $tzfd_item['label'] ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
<?php endif; ?>
