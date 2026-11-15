<?php
/**
 * Partial: dashboard. Counts as tiles, then recent items as tables. Read-only.
 * Expects $tz_app (label, domain) and $tz_dash = array('stats' => [...], 'tables' => [...]).
 * A stat with value null is shown as unavailable, never as zero.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz_domain = isset( $tz_app['domain'] ) ? (string) $tz_app['domain'] : 'default';
$tz_dash   = isset( $tz_dash ) && is_array( $tz_dash ) ? $tz_dash : array();
$tz_stats  = isset( $tz_dash['stats'] ) && is_array( $tz_dash['stats'] ) ? $tz_dash['stats'] : array();
$tz_tables = isset( $tz_dash['tables'] ) && is_array( $tz_dash['tables'] ) ? $tz_dash['tables'] : array();
$tz_tone_ok = array( 'ink', 'info', 'ok', 'warn', 'bad', 'neutral', 'accent' );
?>
<section class="tz-app-dash" aria-labelledby="tz-app-dash-title">
	<h2 id="tz-app-dash-title"><?php esc_html_e( 'Overview', 'titan-zero-trades-front-desk' ); ?></h2>
	<?php if ( empty( $tz_stats ) && empty( $tz_tables ) ) :
		$tz_empty_title = __( 'Figures unavailable', 'titan-zero-trades-front-desk' );
		$tz_empty_text  = __( 'This dashboard could not read its data. Check that the plugin is active and its database tables are in place.', 'titan-zero-trades-front-desk' );
		include __DIR__ . '/empty-state.php';
	endif; ?>
	<?php if ( ! empty( $tz_stats ) ) : ?>
		<ul class="tz-app-stats" role="list">
			<?php foreach ( $tz_stats as $tz_stat ) :
				$tz_value  = isset( $tz_stat['value'] ) ? $tz_stat['value'] : null;
				$tz_tone   = isset( $tz_stat['tone'] ) ? sanitize_key( (string) $tz_stat['tone'] ) : 'accent';
				$tz_tone   = in_array( $tz_tone, $tz_tone_ok, true ) ? $tz_tone : 'accent';
				$tz_url    = isset( $tz_stat['url'] ) ? (string) $tz_stat['url'] : '';
				$tz_sub    = isset( $tz_stat['sub'] ) ? (string) $tz_stat['sub'] : '';
				$tz_unavail = null === $tz_value;
				?>
				<li class="tz-app-stat tz-tone-<?php echo esc_attr( $tz_tone ); ?><?php echo $tz_unavail ? ' is-unavailable' : ''; ?>">
					<?php if ( '' !== $tz_url ) : ?><a href="<?php echo esc_url( $tz_url ); ?>"><?php endif; ?>
					<span class="tz-app-stat-value"><?php echo $tz_unavail ? '&ndash;' : esc_html( (string) $tz_value ); ?></span>
					<span class="tz-app-stat-label"><?php echo esc_html( isset( $tz_stat['label'] ) ? (string) $tz_stat['label'] : '' ); ?></span>
					<span class="tz-app-stat-sub"><?php echo esc_html( $tz_unavail ? __( 'Could not be read. Check the database before relying on this figure.', 'titan-zero-trades-front-desk' ) : $tz_sub ); ?></span>
					<?php if ( '' !== $tz_url ) : ?></a><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php foreach ( $tz_tables as $tz_block ) :
		$tz_table = isset( $tz_block['table'] ) && is_array( $tz_block['table'] ) ? $tz_block['table'] : array();
		$tz_link  = isset( $tz_block['link'] ) && is_array( $tz_block['link'] ) ? $tz_block['link'] : array();
		?>
		<section class="tz-app-card" aria-label="<?php echo esc_attr( isset( $tz_block['title'] ) ? (string) $tz_block['title'] : '' ); ?>">
			<div class="tz-app-card-head">
				<h3><?php echo esc_html( isset( $tz_block['title'] ) ? (string) $tz_block['title'] : '' ); ?></h3>
				<?php if ( ! empty( $tz_link['url'] ) ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( (string) $tz_link['url'] ); ?>"><?php echo esc_html( isset( $tz_link['label'] ) ? (string) $tz_link['label'] : __( 'Open', 'titan-zero-trades-front-desk' ) ); ?> &rarr;</a>
				<?php endif; ?>
			</div>
			<?php include __DIR__ . '/list-table.php'; ?>
		</section>
	<?php endforeach; ?>
</section>
