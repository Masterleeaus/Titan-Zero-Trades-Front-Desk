<?php
/**
 * Read-only admin viewer for agent action receipts. Pure data plus escaped HTML, no WordPress calls on
 * the data path.
 *
 * The source is any object with receipts_for_company( $company_id ). Today the source is a fake in tests.
 * Later it will be the signed connector's receipt store. Receipts from another company are dropped and
 * counted, never shown. Each row says whether it verifies against the chain. A broken chain is reported,
 * and receipts from the break onward are marked unverified.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-action-receipt.php';

final class TZFD_Action_Receipt_Viewer {

	const MAX_ROWS = 200;

	/**
	 * @param object $source     Object with receipts_for_company( string ): array.
	 * @param string $company_id Company whose receipts are shown.
	 * @param string $secret     Site receipt secret.
	 * @param array  $filters    Optional: tool, outcome (allowlisted values only).
	 * @param int    $limit      1 to MAX_ROWS.
	 * @return array rows, chain_valid, broken_at_sequence, foreign_dropped, total, truncated, read_only.
	 * @throws InvalidArgumentException When the source or the inputs are unusable.
	 */
	public static function rows( $source, $company_id, $secret, $filters = array(), $limit = 50 ) {
		if ( ! is_object( $source ) || ! method_exists( $source, 'receipts_for_company' ) ) { throw new InvalidArgumentException( 'invalid_source' ); }
		if ( ! is_string( $company_id ) || ! preg_match( TZFD_Delegated_Roles::ID_PATTERN, $company_id ) ) { throw new InvalidArgumentException( 'invalid_company_id' ); }
		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_ROWS ) { throw new InvalidArgumentException( 'invalid_limit' ); }
		if ( ! is_array( $filters ) ) { throw new InvalidArgumentException( 'invalid_filters' ); }
		$all = $source->receipts_for_company( $company_id );
		if ( ! is_array( $all ) ) { throw new InvalidArgumentException( 'source_failed' ); }

		$own = array();
		$foreign = 0;
		foreach ( $all as $receipt ) {
			if ( ! is_array( $receipt ) || ( $receipt['company_id'] ?? null ) !== $company_id ) { $foreign++; continue; }
			$own[] = $receipt;
		}
		usort( $own, function ( $a, $b ) {
			return ( $a['sequence'] ?? 0 ) <=> ( $b['sequence'] ?? 0 );
		} );

		$chain = TZFD_Action_Receipt::verify_chain( $secret, $own );
		$broken_index = $chain['broken_at'];

		$tool_filter = $filters['tool'] ?? null;
		$outcome_filter = $filters['outcome'] ?? null;
		if ( null !== $tool_filter && ! in_array( $tool_filter, array_keys( TZFD_Agent_Tool_Permissions::catalogue() ), true ) ) { throw new InvalidArgumentException( 'unknown_tool_filter' ); }
		if ( null !== $outcome_filter && ! in_array( $outcome_filter, TZFD_Action_Receipt::OUTCOMES, true ) ) { throw new InvalidArgumentException( 'unknown_outcome_filter' ); }

		$rows = array();
		foreach ( $own as $index => $receipt ) {
			if ( null !== $tool_filter && ( $receipt['tool'] ?? null ) !== $tool_filter ) { continue; }
			if ( null !== $outcome_filter && ( $receipt['outcome'] ?? null ) !== $outcome_filter ) { continue; }
			$verified = null === $broken_index || $index < $broken_index;
			$rows[] = array(
				'receipt_id'    => $receipt['receipt_id'] ?? '',
				'sequence'      => $receipt['sequence'] ?? 0,
				'occurred_at'   => $receipt['occurred_at'] ?? 0,
				'tool'          => $receipt['tool'] ?? '',
				'outcome'       => $receipt['outcome'] ?? '',
				'reason'        => $receipt['reason'] ?? '',
				'actor'         => $receipt['actor'] ?? '',
				'grant_version' => $receipt['grant_version'] ?? 0,
				'verified'      => $verified,
			);
		}
		$rows = array_reverse( $rows );
		$total = count( $rows );

		return array(
			'company_id'          => $company_id,
			'rows'                => array_slice( $rows, 0, $limit ),
			'total'               => $total,
			'truncated'           => $total > $limit,
			'chain_valid'         => $chain['valid'],
			'broken_at_sequence'  => null === $broken_index ? null : ( $own[ $broken_index ]['sequence'] ?? null ),
			'foreign_dropped'     => $foreign,
			'read_only'           => true,
		);
	}

	/** Escaped HTML table for the admin screen. Raw arguments are never shown, only digests were stored. */
	public static function render( $result ) {
		$rows = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
		$html = '<div class="tzfd-receipts">';
		if ( ! ( $result['chain_valid'] ?? false ) ) {
			$message = sprintf( self::t( 'Receipt history failed verification from sequence %s. Entries from that point are marked as not verified.' ), (string) ( $result['broken_at_sequence'] ?? '' ) );
			$html   .= '<p class="notice notice-error">' . self::e( $message ) . '</p>';
		}
		if ( ( $result['foreign_dropped'] ?? 0 ) > 0 ) {
			$html .= '<p class="notice notice-warning">' . self::e( self::t( 'Some stored receipts did not belong to this company and were not shown.' ) ) . '</p>';
		}
		if ( empty( $rows ) ) {
			return $html . '<p>' . self::e( self::t( 'No agent actions have been recorded yet.' ) ) . '</p></div>';
		}
		$html .= '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'Sequence', 'Time', 'Tool', 'Outcome', 'Reason', 'Actor', 'Grant version', 'Verified' ) as $heading ) {
			$html .= '<th scope="col">' . self::e( self::t( $heading ) ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>'
				. '<td>' . self::e( (string) $row['sequence'] ) . '</td>'
				. '<td>' . self::e( gmdate( 'Y-m-d H:i:s', (int) $row['occurred_at'] ) ) . ' UTC</td>'
				. '<td>' . self::e( (string) $row['tool'] ) . '</td>'
				. '<td>' . self::e( (string) $row['outcome'] ) . '</td>'
				. '<td>' . self::e( (string) $row['reason'] ) . '</td>'
				. '<td>' . self::e( (string) $row['actor'] ) . '</td>'
				. '<td>' . self::e( (string) $row['grant_version'] ) . '</td>'
				. '<td>' . self::e( self::t( $row['verified'] ? 'Yes' : 'No' ) ) . '</td>'
				. '</tr>';
		}
		return $html . '</tbody></table></div>';
	}

	private static function e( $text ) {
		return function_exists( 'esc_html' ) ? esc_html( $text ) : htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	private static function t( $text ) {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- every caller passes a literal string; this wrapper only forwards it to __().
		return function_exists( '__' ) ? __( $text, 'titan-zero-trades-front-desk' ) : $text;
	}
}
