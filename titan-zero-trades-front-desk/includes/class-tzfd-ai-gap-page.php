<?php
/**
 * FAQ knowledge gaps admin view (issue #36).
 *
 * Shows counts of unanswered or low-confidence question topics for the last 30 days, the share of
 * handled questions, and whether approved content covers each topic. Topics can be merged by an
 * administrator. Only counts and admin-approved topic labels are shown: no question text, names or
 * contact details are ever stored or rendered. Read access needs tz_fd_access, and the merge form is
 * nonce-checked and capability-checked again on submit.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-ai-gap-detection.php';
require_once __DIR__ . '/class-tzfd-ai-knowledge-builder.php';

final class TZFD_Ai_Gap_Page {
	const SLUG         = 'tzfd-ai-gaps';
	const MERGE_ACTION = 'tzfd_ai_gap_merge';
	const MERGE_NONCE  = 'tzfd_ai_gap_merge';
	const CAP          = 'tz_fd_access';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 13 );
		add_action( 'admin_post_' . self::MERGE_ACTION, array( __CLASS__, 'handle_merge' ) );
	}

	public static function add_page() {
		add_submenu_page(
			'tzfd',
			__( 'FAQ gaps', 'titan-zero-trades-front-desk' ),
			__( 'FAQ gaps', 'titan-zero-trades-front-desk' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Filters from the query string. Pure, so the unit suite can check the clamping and allow-lists.
	 * min_count is 0..1000, search is at most 60 characters, coverage is 'missing', 'covered' or ''.
	 */
	public static function parse_filters( array $raw ) {
		$min      = isset( $raw['min_count'] ) ? max( 0, min( 1000, (int) $raw['min_count'] ) ) : 0;
		$search   = isset( $raw['search'] ) && is_string( $raw['search'] ) ? mb_substr( trim( $raw['search'] ), 0, 60 ) : '';
		$coverage = isset( $raw['coverage'] ) && in_array( $raw['coverage'], array( 'missing', 'covered' ), true ) ? $raw['coverage'] : '';
		return array( 'min_count' => $min, 'search' => $search, 'coverage' => $coverage );
	}

	/** Topic keys are the 16-character hex values that gap detection generates. */
	public static function valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $key );
	}

	public static function handle_merge() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change FAQ gaps.', 'titan-zero-trades-front-desk' ), 403 );
		}
		check_admin_referer( self::MERGE_NONCE );
		$cid  = self::company_id();
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$from = isset( $post['from'] ) ? (string) $post['from'] : '';
		$into = isset( $post['into'] ) ? (string) $post['into'] : '';
		$ok   = $cid > 0 && self::valid_key( $from ) && self::valid_key( $into )
			&& ! empty( TZFD_Ai_Gap_Detection::merge_topics( $cid, $from, $into )['ok'] );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tzfd_gap' => $ok ? 'merged' : 'merge_failed' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'titan-zero-trades-front-desk' ), 403 );
		}
		$cid    = self::company_id();
		$raw    = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filter = self::parse_filters( is_array( $raw ) ? $raw : array() );
		$notice = isset( $raw['tzfd_gap'] ) && is_string( $raw['tzfd_gap'] ) ? $raw['tzfd_gap'] : '';

		$approved = array();
		if ( $cid > 0 ) {
			foreach ( TZFD_Ai_Knowledge_Builder::published( $cid ) as $entry ) {
				$approved[] = (string) $entry['question'];
				$approved[] = (string) $entry['answer'];
			}
		}
		$now    = time();
		$all    = TZFD_Ai_Gap_Detection::report( $cid, $now, TZFD_Ai_Gap_Detection::RETENTION_DAYS, array(), $approved );
		$report = TZFD_Ai_Gap_Detection::report( $cid, $now, TZFD_Ai_Gap_Detection::RETENTION_DAYS, $filter, $approved );
		?>
		<div class="wrap tzfd-ai-gaps">
			<h1><?php esc_html_e( 'FAQ gaps', 'titan-zero-trades-front-desk' ); ?></h1>
			<p><?php esc_html_e( 'Topics customers asked about that approved FAQ answers did not cover, over the last 30 days. Counts only. No question text, names or contact details are kept or shown.', 'titan-zero-trades-front-desk' ); ?></p>

			<?php if ( 'merged' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Topics merged. Counts were added into the target topic.', 'titan-zero-trades-front-desk' ); ?></p></div>
			<?php elseif ( 'merge_failed' === $notice ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Those topics could not be merged. Pick two different topics that do not already form a loop.', 'titan-zero-trades-front-desk' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $cid < 1 ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'No business is selected for this request, so there is nothing to show.', 'titan-zero-trades-front-desk' ); ?></p></div>
			<?php elseif ( $all['insufficient'] ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Not enough questions yet to show shares. Counts appear once a topic has been asked about several times.', 'titan-zero-trades-front-desk' ); ?></p></div>
			<?php endif; ?>

			<p><strong><?php esc_html_e( 'Handled questions', 'titan-zero-trades-front-desk' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $all['total'] ) ); ?>
				&middot; <strong><?php esc_html_e( 'Not answered from approved content', 'titan-zero-trades-front-desk' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $all['unanswered'] ) ); ?></p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tzfd-gap-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label><?php esc_html_e( 'Minimum count', 'titan-zero-trades-front-desk' ); ?>
					<input type="number" min="0" max="1000" name="min_count" value="<?php echo esc_attr( (string) $filter['min_count'] ); ?>" /></label>
				<label><?php esc_html_e( 'Topic contains', 'titan-zero-trades-front-desk' ); ?>
					<input type="search" maxlength="60" name="search" value="<?php echo esc_attr( $filter['search'] ); ?>" /></label>
				<label><?php esc_html_e( 'Coverage', 'titan-zero-trades-front-desk' ); ?>
					<select name="coverage">
						<option value="" <?php selected( '', $filter['coverage'] ); ?>><?php esc_html_e( 'All topics', 'titan-zero-trades-front-desk' ); ?></option>
						<option value="missing" <?php selected( 'missing', $filter['coverage'] ); ?>><?php esc_html_e( 'Missing or outdated', 'titan-zero-trades-front-desk' ); ?></option>
						<option value="covered" <?php selected( 'covered', $filter['coverage'] ); ?>><?php esc_html_e( 'Covered', 'titan-zero-trades-front-desk' ); ?></option>
					</select></label>
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'titan-zero-trades-front-desk' ); ?></button>
			</form>

			<table class="widefat striped" style="max-width:56em">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Topic', 'titan-zero-trades-front-desk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Questions', 'titan-zero-trades-front-desk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Share', 'titan-zero-trades-front-desk' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Approved content', 'titan-zero-trades-front-desk' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $report['topics'] ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No topics match these filters.', 'titan-zero-trades-front-desk' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $report['topics'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
						<td><?php echo null === $row['share'] ? esc_html__( 'Not enough data', 'titan-zero-trades-front-desk' ) : esc_html( round( $row['share'] * 100, 1 ) . '%' ); ?></td>
						<td><?php echo 'missing' === $row['coverage']
							? esc_html__( 'Missing or outdated: add or update an approved answer', 'titan-zero-trades-front-desk' )
							: esc_html__( 'Covered', 'titan-zero-trades-front-desk' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $cid > 0 && count( $all['topics'] ) > 1 ) : ?>
				<h2><?php esc_html_e( 'Group topics', 'titan-zero-trades-front-desk' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Merging adds the counts of the first topic into the second for reporting. Nothing is deleted.', 'titan-zero-trades-front-desk' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::MERGE_ACTION ); ?>" />
					<?php wp_nonce_field( self::MERGE_NONCE ); ?>
					<label><?php esc_html_e( 'Merge', 'titan-zero-trades-front-desk' ); ?>
						<select name="from"><?php self::options( $all['topics'] ); ?></select></label>
					<label><?php esc_html_e( 'into', 'titan-zero-trades-front-desk' ); ?>
						<select name="into"><?php self::options( $all['topics'] ); ?></select></label>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Merge topics', 'titan-zero-trades-front-desk' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array $topics Rows from the report, each with key and label. */
	private static function options( array $topics ) {
		foreach ( $topics as $row ) {
			echo '<option value="' . esc_attr( (string) $row['key'] ) . '">' . esc_html( (string) $row['label'] ) . '</option>';
		}
	}

	private static function company_id() {
		return class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
	}
}
