<?php
/**
 * Partial: list table for one set of records. Read-only.
 * Expects $tz_table = array('caption', 'columns' => [label...], 'rows' => [array('url', 'cells' => [...])], 'empty').
 * A cell is a string, or array('text', 'badge' => true, 'tone'). The first cell links to 'url' when given.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tz_domain  = isset( $tz_app['domain'] ) ? (string) $tz_app['domain'] : 'default';
$tz_table   = isset( $tz_table ) && is_array( $tz_table ) ? $tz_table : array();
$tz_columns = isset( $tz_table['columns'] ) && is_array( $tz_table['columns'] ) ? array_values( $tz_table['columns'] ) : array();
$tz_rows    = isset( $tz_table['rows'] ) && is_array( $tz_table['rows'] ) ? $tz_table['rows'] : array();
$tz_caption = isset( $tz_table['caption'] ) ? (string) $tz_table['caption'] : '';
$tz_allowed_tones = array( 'ink', 'info', 'ok', 'warn', 'bad', 'neutral' );
if ( empty( $tz_rows ) ) :
	$tz_empty_title = '';
	$tz_empty_text  = isset( $tz_table['empty'] ) ? (string) $tz_table['empty'] : __( 'Nothing to show yet.', 'titan-zero-trades-front-desk' );
	include __DIR__ . '/empty-state.php';
else :
?>
<div class="tz-app-table-wrap" role="region" tabindex="0" aria-label="<?php echo esc_attr( $tz_caption ); ?>">
	<table class="tz-app-table">
		<?php if ( '' !== $tz_caption ) : ?><caption class="screen-reader-text"><?php echo esc_html( $tz_caption ); ?></caption><?php endif; ?>
		<thead>
			<tr>
				<?php foreach ( $tz_columns as $tz_col ) : ?>
					<th scope="col"><?php echo esc_html( (string) $tz_col ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $tz_rows as $tz_row ) :
				$tz_url   = isset( $tz_row['url'] ) ? (string) $tz_row['url'] : '';
				$tz_cells = isset( $tz_row['cells'] ) && is_array( $tz_row['cells'] ) ? array_values( $tz_row['cells'] ) : array();
				?>
				<tr>
					<?php foreach ( $tz_cells as $tz_i => $tz_cell ) :
						$tz_is_arr = is_array( $tz_cell );
						$tz_text   = $tz_is_arr ? (string) ( $tz_cell['text'] ?? '' ) : (string) $tz_cell;
						$tz_label  = isset( $tz_columns[ $tz_i ] ) ? (string) $tz_columns[ $tz_i ] : '';
						?>
						<td data-label="<?php echo esc_attr( $tz_label ); ?>">
							<?php if ( $tz_is_arr && ! empty( $tz_cell['badge'] ) ) :
								$tz_badge_text = $tz_text;
								$tz_badge_tone = in_array( (string) ( $tz_cell['tone'] ?? '' ), $tz_allowed_tones, true ) ? (string) $tz_cell['tone'] : 'neutral';
								include __DIR__ . '/status-badge.php';
							elseif ( 0 === $tz_i && '' !== $tz_url ) : ?>
								<a href="<?php echo esc_url( $tz_url ); ?>"><?php echo esc_html( $tz_text ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $tz_text ); ?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>
