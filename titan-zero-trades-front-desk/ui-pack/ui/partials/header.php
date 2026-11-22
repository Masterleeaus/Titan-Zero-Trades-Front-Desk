<?php
/**
 * Partial: app header with the app icon and wordmark (light and dark variants).
 * Expects $tzfd_app (slug, label, logo, base, site). Read-only output, all escaped.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tzfd_app   = isset( $tzfd_app ) && is_array( $tzfd_app ) ? $tzfd_app : array();
$tzfd_label = isset( $tzfd_app['label'] ) ? (string) $tzfd_app['label'] : '';
$tzfd_logo  = isset( $tzfd_app['logo'] ) ? sanitize_key( (string) $tzfd_app['logo'] ) : '';
$tzfd_base  = isset( $tzfd_app['base'] ) ? (string) $tzfd_app['base'] : '';
$tzfd_site  = isset( $tzfd_app['site'] ) ? (string) $tzfd_app['site'] : '';
$tzfd_brand = plugin_dir_url( dirname( __DIR__, 3 ) . '/titan-zero-front-desk.php' ) . 'images/brand/branding/';
?>
<header class="tz-app-head">
	<?php if ( '' !== $tzfd_site ) : ?>
		<p class="tz-app-site"><?php echo esc_html( $tzfd_site ); ?></p>
	<?php endif; ?>
</header>
