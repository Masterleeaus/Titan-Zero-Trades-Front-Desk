<?php
/**
 * Partial: page wrapper, opening half. Prints header, navigation and an optional title and lead.
 * Expects $tzfd_app, $tzfd_nav, $tzfd_current; optional $tzfd_title and $tzfd_lead. Close with page-close.php.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tzfd_title = isset( $tzfd_title ) ? (string) $tzfd_title : '';
$tzfd_lead  = isset( $tzfd_lead ) ? (string) $tzfd_lead : '';
$tzfd_slug  = isset( $tzfd_app['slug'] ) ? sanitize_key( (string) $tzfd_app['slug'] ) : '';
?>
<div class="tz-app" data-tz-app="<?php echo esc_attr( $tzfd_slug ); ?>">
	<?php include __DIR__ . '/header.php'; ?>
	<?php include __DIR__ . '/nav.php'; ?>
	<main class="tz-app-main" id="tz-app-main">
	<?php if ( '' !== $tzfd_title ) : ?>
		<div class="tz-app-page-head">
			<h1><?php echo esc_html( $tzfd_title ); ?></h1>
			<?php if ( '' !== $tzfd_lead ) : ?>
				<p class="tz-app-lead"><?php echo esc_html( $tzfd_lead ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
