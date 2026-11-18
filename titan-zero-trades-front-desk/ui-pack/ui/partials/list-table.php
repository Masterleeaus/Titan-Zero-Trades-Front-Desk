<?php
/**
 * Partial: list table for one set of records. Read-only.
 * Expects $tzfd_table = array('caption', 'columns' => [label...], 'rows' => [array('url', 'cells' => [...])], 'empty').
 * A cell is a string, or array('text', 'badge' => true, 'tone'). The first cell links to 'url' when given.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tzfd_domain  = isset( $tzfd_app['domain'] ) ? (string) $tzfd_app['domain'] : 'default';
$tzfd_table   = isset( $tzfd_table ) && is_array( $tzfd_table ) ? $tzfd_table : array();
$tzfd_columns = isset( $tzfd_table['columns'] ) && is_array( $tzfd_table['columns'] ) ? array_values( $tzfd_table['columns'] ) : array();
$tzfd_local_rows    = isset( $tzfd_table['rows'] ) && is_array( $tzfd_table['rows'] ) ? $tzfd_table['rows'] : array();
$tzfd_caption = isset( $tzfd_table['caption'] ) ? (string) $tzfd_table['caption'] : '';
$tzfd_allowed_tones = array( 'ink', 'info', 'ok', 'warn', 'bad', 'neutral' );
if ( empty( $tzfd_local_rows ) ) :
	$tzfd_empty_title = '';
	$tzfd_empty_text  = isset( $tzfd_table['empty'] ) ? (string) $tzfd_table['empty'] : __( 'Nothing to show yet.', 'titan-zero-trades-front-desk' );
	include __DIR__ . '/empty-state.php';
else :
?>
<div class="tz-app-table-wrap" role="region" tabindex="0" aria-label="<?php echo esc_attr( $tzfd_caption ); ?>">
	<table class="tz-app-table">
		<?php if ( '' !== $tzfd_caption ) : ?><caption class="screen-reader-text"><?php echo esc_html( $tzfd_caption ); ?></caption><?php endif; ?>
		<thead>
			<tr>
				<?php foreach ( $tzfd_columns as $tzfd_col ) : ?>
					<th scope="col"><?php echo esc_html( (string) $tzfd_col ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $tzfd_local_rows as $tzfd_local_row ) :
				$tzfd_url   = isset( $tzfd_local_row['url'] ) ? (string) $tzfd_local_row['url'] : '';
				$tzfd_cells = isset( $tzfd_local_row['cells'] ) && is_array( $tzfd_local_row['cells'] ) ? array_values( $tzfd_local_row['cells'] ) : array();
				?>
				<tr>
					<?php foreach ( $tzfd_cells as $tzfd_i => $tzfd_cell ) :
						$tzfd_is_arr = is_array( $tzfd_cell );
						$tzfd_text   = $tzfd_is_arr ? (string) ( $tzfd_cell['text'] ?? '' ) : (string) $tzfd_cell;
						$tzfd_label  = isset( $tzfd_columns[ $tzfd_i ] ) ? (string) $tzfd_columns[ $tzfd_i ] : '';
						?>
						<td data-label="<?php echo esc_attr( $tzfd_label ); ?>">
							<?php if ( $tzfd_is_arr && ! empty( $tzfd_cell['badge'] ) ) :
								$tzfd_badge_text = $tzfd_text;
								$tzfd_badge_tone = in_array( (string) ( $tzfd_cell['tone'] ?? '' ), $tzfd_allowed_tones, true ) ? (string) $tzfd_cell['tone'] : 'neutral';
								include __DIR__ . '/status-badge.php';
							elseif ( 0 === $tzfd_i && '' !== $tzfd_url ) : ?>
								<a href="<?php echo esc_url( $tzfd_url ); ?>"><?php echo esc_html( $tzfd_text ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $tzfd_text ); ?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>
