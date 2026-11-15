<?php
/**
 * Partial: page wrapper, opening half. Prints header, navigation and an optional title and lead.
 * Expects $tz_app, $tz_nav, $tz_current; optional $tz_title and $tz_lead. Close with page-close.php.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz_title = isset( $tz_title ) ? (string) $tz_title : '';
$tz_lead  = isset( $tz_lead ) ? (string) $tz_lead : '';
$tz_slug  = isset( $tz_app['slug'] ) ? sanitize_key( (string) $tz_app['slug'] ) : '';
?>
<div class="tz-app" data-tz-app="<?php echo esc_attr( $tz_slug ); ?>">
	<?php include __DIR__ . '/header.php'; ?>
	<?php include __DIR__ . '/nav.php'; ?>
	<main class="tz-app-main" id="tz-app-main">
	<?php if ( '' !== $tz_title ) : ?>
		<div class="tz-app-page-head">
			<h1><?php echo esc_html( $tz_title ); ?></h1>
			<?php if ( '' !== $tz_lead ) : ?>
				<p class="tz-app-lead"><?php echo esc_html( $tz_lead ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
