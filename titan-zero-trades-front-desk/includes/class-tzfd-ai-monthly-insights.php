<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-free-core.php';

/**
 * Monthly improvement report (issue #41).
 *
 * - Local deterministic report first. It needs no consent, connector or key.
 * - Aggregates only: counts grouped by status and source from tz_enquiries, plus counts the caller supplies.
 *   The SQL selects no name, email, phone or message column.
 * - Every row carries its denominator. Rows below MIN_CELL are omitted. The whole report is suppressed below MIN_BASE.
 * - The optional hosted summary is one batch per company per month. It gets the report rows only, and it is
 *   rejected if it states a figure the report does not contain.
 * - A hosted failure, missing consent or missing connector returns the local report alone.
 */
final class TZFD_Ai_Monthly_Insights {
	const MIN_CELL       = 5;
	const MIN_BASE       = 10;
	const MAX_ATTEMPTS   = 2;
	const SUMMARY_MAX    = 600;
	const STATE_PREFIX   = 'tz_fd_ai_insights_';

	/** Source categories reported. Anything else is grouped as 'other'. */
	const SOURCES = array( 'web', 'phone', 'sms', 'email', 'walk_in' );

	public static function month_window( $month ) {
		if ( ! is_string( $month ) || ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m ) ) {
			return null;
		}
		$start = gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] );
		$end   = gmmktime( 0, 0, 0, (int) $m[2] + 1, 1, (int) $m[1] );
		return array( 'start' => gmdate( 'Y-m-d H:i:s', $start ), 'end' => gmdate( 'Y-m-d H:i:s', $end ) );
	}

	/**
	 * Enquiry counts for one company and month from tz_enquiries. $db is a wpdb-compatible object.
	 * Returns array( 'base' => int, 'by_status' => array, 'by_source' => array ) or null.
	 */
	public static function aggregate( $db, $company_id, $month ) {
		$win = self::month_window( $month );
		$cid = (int) $company_id;
		if ( null === $win || $cid < 1 ) { return null; }
		$table = $db->prefix . 'tz_enquiries';
		$rows = $db->get_results( $db->prepare(
			'SELECT status, source, COUNT(*) AS n FROM %i WHERE company_id = %d AND created >= %s AND created < %s GROUP BY status, source',
			$table, $cid, $win['start'], $win['end']
		), ARRAY_A );
		$base = 0; $by_status = array(); $by_source = array();
		foreach ( (array) $rows as $r ) {
			$n = (int) $r['n'];
			$base += $n;
			$by_status[ (string) $r['status'] ] = ( $by_status[ (string) $r['status'] ] ?? 0 ) + $n;
			$src = in_array( (string) $r['source'], self::SOURCES, true ) ? (string) $r['source'] : 'other';
			$by_source[ $src ] = ( $by_source[ $src ] ?? 0 ) + $n;
		}
		ksort( $by_status );
		ksort( $by_source );
		return array( 'base' => $base, 'by_status' => $by_status, 'by_source' => $by_source );
	}

	/**
	 * Local report. $agg from aggregate(). $extra is an array of sections, each
	 * array( 'section' => label, 'rows' => array( array( 'label', 'count', 'base' ) ) ), for example
	 * unanswered FAQ topics (base = questions handled), callback patterns and response handling (base = requests).
	 */
	public static function local_report( $month, array $agg, array $extra ) {
		$win  = self::month_window( $month );
		$base = (int) $agg['base'];
		$report = array(
			'month'      => $month,
			'window'     => $win,
			'base'       => $base,
			'suppressed' => $base < self::MIN_BASE,
			'sections'   => array(),
			'omitted'    => 0,
			'text'       => array(),
		);
		if ( $report['suppressed'] ) {
			$report['text'][] = sprintf( 'Too few enquiries in %s to report (%d). The report needs at least %d.', $month, $base, self::MIN_BASE );
			return $report;
		}
		$sections = array(
			array( 'section' => 'Enquiries by status', 'rows' => self::rows( $agg['by_status'], $base ) ),
			array( 'section' => 'Enquiries by source', 'rows' => self::rows( $agg['by_source'], $base ) ),
		);
		foreach ( $extra as $sec ) {
			if ( ! is_array( $sec ) || empty( $sec['rows'] ) ) { continue; }
			$rows = array();
			foreach ( $sec['rows'] as $r ) {
				$cnt = (int) ( $r['count'] ?? 0 );
				$den = (int) ( $r['base'] ?? 0 );
				if ( $cnt < self::MIN_CELL || $den < self::MIN_BASE ) { $report['omitted']++; continue; }
				$rows[] = array( 'label' => TZFD_Ai_Free_Core::scrub( TZFD_Ai_Free_Core::plain_text( $r['label'] ?? '', 120 ) ), 'count' => $cnt, 'base' => $den, 'share' => round( $cnt / $den, 4 ) );
			}
			$sections[] = array( 'section' => TZFD_Ai_Free_Core::scrub( TZFD_Ai_Free_Core::plain_text( $sec['section'], 80 ) ), 'rows' => $rows );
		}
		foreach ( $sections as $sec ) {
			$lines = array();
			foreach ( $sec['rows'] as $r ) {
				$share = $r['share'] ?? round( $r['count'] / $base, 4 );
				$lines[] = sprintf( '%s: %d of %d (%s%%)', $r['label'], $r['count'], $r['base'] ?? $base, number_format( $share * 100, 1, '.', '' ) );
			}
			$report['sections'][] = array( 'section' => $sec['section'], 'rows' => $sec['rows'] );
			if ( ! empty( $lines ) ) { $report['text'][] = $sec['section'] . ': ' . implode( '; ', $lines ) . '.'; }
		}
		return $report;
	}

	/**
	 * Full report: local always, hosted summary when allowed. $now is the time of the request, used for the hosted state.
	 * Returns array( 'local' => report, 'summary' => string|null, 'summary_status' => string ).
	 */
	public static function report( $company_id, $month, $db, array $extra, $now ) {
		$cid = (int) $company_id;
		$agg = self::aggregate( $db, $cid, $month );
		if ( null === $agg ) {
			return array( 'ok' => false, 'error' => 'invalid_month_or_company' );
		}
		$local = self::local_report( $month, $agg, $extra );
		if ( $local['suppressed'] ) {
			return array( 'ok' => true, 'local' => $local, 'summary' => null, 'summary_status' => 'suppressed' );
		}
		$hosted = self::hosted_summary( $cid, $month, $local, $now );
		return array( 'ok' => true, 'local' => $local, 'summary' => $hosted['summary'], 'summary_status' => $hosted['status'] );
	}

	/** One hosted summary per company per month. Returns array( 'summary' => string|null, 'status' => string ). */
	public static function hosted_summary( $company_id, $month, array $local, $now ) {
		$cid = (int) $company_id;
		$key = self::STATE_PREFIX . $cid . '_' . $month;
		$state = get_option( $key, array() );
		$state = is_array( $state ) ? $state : array();
		if ( ! empty( $state['summary'] ) && 'ok' === ( $state['status'] ?? '' ) ) {
			return array( 'summary' => $state['summary'], 'status' => 'cached' );
		}
		if ( ! TZFD_Ai_Free_Core::consent_granted() ) {
			return array( 'summary' => null, 'status' => 'consent_required' );
		}
		if ( (int) ( $state['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
			return array( 'summary' => null, 'status' => 'retry_exhausted' );
		}
		$state['attempts'] = (int) ( $state['attempts'] ?? 0 ) + 1;
		$rows = array();
		foreach ( $local['sections'] as $sec ) {
			foreach ( $sec['rows'] as $r ) {
				$rows[] = array( 'section' => $sec['section'], 'label' => $r['label'] ?? '', 'count' => $r['count'], 'base' => $r['base'] ?? $local['base'] );
			}
		}
		$call = TZFD_Ai_Free_Core::hosted_call( 'insights.summary', array(
			'month' => $month,
			'base'  => $local['base'],
			'rows'  => $rows,
		), $cid );
		if ( empty( $call['ok'] ) ) {
			$state['status'] = $state['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
			$state['last_error'] = $call['error'];
			update_option( $key, $state, false );
			return array( 'summary' => null, 'status' => $call['error'] );
		}
		$text = TZFD_Ai_Free_Core::plain_text( $call['data']['summary'] ?? '', self::SUMMARY_MAX );
		$allowed = implode( ' ', $local['text'] );
		if ( '' === $text || ! empty( TZFD_Ai_Free_Core::ungrounded_figures( $text, array( $allowed ) ) ) ) {
			$state['status'] = 'rejected';
			update_option( $key, $state, false );
			return array( 'summary' => null, 'status' => 'ungrounded_summary' );
		}
		$state['status']  = 'ok';
		$state['summary'] = $text;
		$state['usage']   = $call['usage'];
		unset( $state['last_error'] );
		update_option( $key, $state, false );
		return array( 'summary' => $text, 'status' => 'ok' );
	}

	/** Rows with count, base and share for a count map. Small cells are dropped. */
	private static function rows( array $counts, $base ) {
		$out = array();
		foreach ( $counts as $label => $n ) {
			if ( (int) $n < self::MIN_CELL ) { continue; }
			$out[] = array( 'label' => (string) $label, 'count' => (int) $n, 'base' => $base, 'share' => round( $n / $base, 4 ) );
		}
		return $out;
	}
}
