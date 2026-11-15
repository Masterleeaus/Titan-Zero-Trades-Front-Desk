<?php
/**
 * Partial: app header with the app icon and wordmark (light and dark variants).
 * Expects $tz_app (slug, label, logo, base, site). Read-only output, all escaped.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz_app   = isset( $tz_app ) && is_array( $tz_app ) ? $tz_app : array();
$tz_label = isset( $tz_app['label'] ) ? (string) $tz_app['label'] : '';
$tz_logo  = isset( $tz_app['logo'] ) ? sanitize_key( (string) $tz_app['logo'] ) : '';
$tz_base  = isset( $tz_app['base'] ) ? (string) $tz_app['base'] : '';
$tz_site  = isset( $tz_app['site'] ) ? (string) $tz_app['site'] : '';
$tz_brand = plugin_dir_url( dirname( __DIR__, 3 ) . '/titan-zero-front-desk.php' ) . 'images/brand/branding/';
?>
<header class="tz-app-head">
	<?php if ( '' !== $tz_site ) : ?>
		<p class="tz-app-site"><?php echo esc_html( $tz_site ); ?></p>
	<?php endif; ?>
</header>
