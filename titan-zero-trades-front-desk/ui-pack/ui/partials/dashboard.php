<?php
/**
 * Partial: dashboard. Counts as tiles, then recent items as tables. Read-only.
 * Expects $tzfd_app (label, domain) and $tzfd_dash = array('stats' => [...], 'tables' => [...]).
 * A stat with value null is shown as unavailable, never as zero.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tzfd_domain = isset( $tzfd_app['domain'] ) ? (string) $tzfd_app['domain'] : 'default';
$tzfd_dash   = isset( $tzfd_dash ) && is_array( $tzfd_dash ) ? $tzfd_dash : array();
$tzfd_stats  = isset( $tzfd_dash['stats'] ) && is_array( $tzfd_dash['stats'] ) ? $tzfd_dash['stats'] : array();
$tzfd_tables = isset( $tzfd_dash['tables'] ) && is_array( $tzfd_dash['tables'] ) ? $tzfd_dash['tables'] : array();
$tzfd_tone_ok = array( 'ink', 'info', 'ok', 'warn', 'bad', 'neutral', 'accent' );
?>
<section class="tz-app-dash" aria-labelledby="tz-app-dash-title">
	<h2 id="tz-app-dash-title"><?php esc_html_e( 'Overview', 'titan-zero-trades-front-desk' ); ?></h2>
	<?php if ( empty( $tzfd_stats ) && empty( $tzfd_tables ) ) :
		$tzfd_empty_title = __( 'Figures unavailable', 'titan-zero-trades-front-desk' );
		$tzfd_empty_text  = __( 'This dashboard could not read its data. Check that the plugin is active and its database tables are in place.', 'titan-zero-trades-front-desk' );
		include __DIR__ . '/empty-state.php';
	endif; ?>
	<?php if ( ! empty( $tzfd_stats ) ) : ?>
		<ul class="tz-app-stats" role="list">
			<?php foreach ( $tzfd_stats as $tzfd_stat ) :
				$tzfd_value  = isset( $tzfd_stat['value'] ) ? $tzfd_stat['value'] : null;
				$tzfd_tone   = isset( $tzfd_stat['tone'] ) ? sanitize_key( (string) $tzfd_stat['tone'] ) : 'accent';
				$tzfd_tone   = in_array( $tzfd_tone, $tzfd_tone_ok, true ) ? $tzfd_tone : 'accent';
				$tzfd_url    = isset( $tzfd_stat['url'] ) ? (string) $tzfd_stat['url'] : '';
				$tzfd_local_sub    = isset( $tzfd_stat['sub'] ) ? (string) $tzfd_stat['sub'] : '';
				$tzfd_unavail = null === $tzfd_value;
				?>
				<li class="tz-app-stat tz-tone-<?php echo esc_attr( $tzfd_tone ); ?><?php echo $tzfd_unavail ? ' is-unavailable' : ''; ?>">
					<?php if ( '' !== $tzfd_url ) : ?><a href="<?php echo esc_url( $tzfd_url ); ?>"><?php endif; ?>
					<span class="tz-app-stat-value"><?php echo $tzfd_unavail ? '&ndash;' : esc_html( (string) $tzfd_value ); ?></span>
					<span class="tz-app-stat-label"><?php echo esc_html( isset( $tzfd_stat['label'] ) ? (string) $tzfd_stat['label'] : '' ); ?></span>
					<span class="tz-app-stat-sub"><?php echo esc_html( $tzfd_unavail ? __( 'Could not be read. Check the database before relying on this figure.', 'titan-zero-trades-front-desk' ) : $tzfd_local_sub ); ?></span>
					<?php if ( '' !== $tzfd_url ) : ?></a><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php foreach ( $tzfd_tables as $tzfd_block ) :
		$tzfd_table = isset( $tzfd_block['table'] ) && is_array( $tzfd_block['table'] ) ? $tzfd_block['table'] : array();
		$tzfd_link  = isset( $tzfd_block['link'] ) && is_array( $tzfd_block['link'] ) ? $tzfd_block['link'] : array();
		?>
		<section class="tz-app-card" aria-label="<?php echo esc_attr( isset( $tzfd_block['title'] ) ? (string) $tzfd_block['title'] : '' ); ?>">
			<div class="tz-app-card-head">
				<h3><?php echo esc_html( isset( $tzfd_block['title'] ) ? (string) $tzfd_block['title'] : '' ); ?></h3>
				<?php if ( ! empty( $tzfd_link['url'] ) ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( (string) $tzfd_link['url'] ); ?>"><?php echo esc_html( isset( $tzfd_link['label'] ) ? (string) $tzfd_link['label'] : __( 'Open', 'titan-zero-trades-front-desk' ) ); ?> &rarr;</a>
				<?php endif; ?>
			</div>
			<?php include __DIR__ . '/list-table.php'; ?>
		</section>
	<?php endforeach; ?>
</section>
