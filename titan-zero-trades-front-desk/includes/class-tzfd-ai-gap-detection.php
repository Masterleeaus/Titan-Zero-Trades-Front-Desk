<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-free-core.php';
require_once __DIR__ . '/class-tzfd-ai-knowledge-builder.php';

/**
 * Unanswered-question gap detection (issue #36).
 *
 * - Local only. Counts are kept per day and per topic. Question text is never stored.
 * - Topics come from an administrator-approved vocabulary (service names and policy terms). A question
 *   is matched to a term only when every word of the term appears in it. Unmatched questions add to
 *   the totals and nothing else, so no free text, names or contact details are kept.
 * - Denominators are stored. The total is every question handled in the window, so shares are real.
 * - Repeats of the same question within the dedupe window count once.
 * - Figures below MIN_REPORT_COUNT are dropped from the report. No share is shown below MIN_BASE.
 * - Consent withdrawn purges everything. Recording stops while consent is off.
 */
final class TZFD_Ai_Gap_Detection {
	const OPTION           = 'tz_fd_ai_gaps';
	const RETENTION_DAYS   = 30;
	const MAX_TOPICS       = 200;
	const DEDUPE_SECONDS   = 600;
	const MIN_REPORT_COUNT = 3;
	const MIN_BASE         = 10;
	const ANSWERED_CONFIDENCE = 0.6;
	const HANDLED_ACTION      = 'tzfd_ai_question_handled';
	const VOCAB_FILTER        = 'tzfd_ai_gap_vocabulary';

	const STOPWORDS = array(
		'a', 'an', 'the', 'is', 'are', 'do', 'does', 'you', 'i', 'we', 'my', 'our', 'of', 'to', 'for', 'and', 'or',
		'it', 'this', 'that', 'can', 'how', 'what', 'when', 'where', 'there', 'any', 'have', 'has', 'be', 'at', 'on',
		'in', 'with', 'me', 'please', 'hi', 'hello', 'your', 'get', 'if', 'will', 'would', 'about', 'so', 'like',
	);

	public static function register() {
		add_action( TZFD_Ai_Free_Core::CONSENT_WITHDRAWN_ACTION, array( __CLASS__, 'purge' ) );
		add_action( self::HANDLED_ACTION, array( __CLASS__, 'on_handled' ), 10, 2 );
	}

	public static function purge() {
		delete_option( self::OPTION );
	}

	/**
	 * Listener for every public question the answer box handled (fired by the AI widget).
	 * Only an approved-rule answer counts as answered. Refusals, limits, offline proxy fallbacks and
	 * model answers count as gaps. The question text is passed on and never stored.
	 */
	public static function on_handled( $question, $result ) {
		if ( ! is_string( $question ) || ! is_array( $result ) || ! TZFD_Ai_Free_Core::consent_granted() ) {
			return;
		}
		$cid = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
		if ( $cid < 1 ) {
			return;
		}
		$answered = 'answered' === ( $result['status'] ?? '' ) && 'approved_rule' === ( $result['source'] ?? '' );
		self::record( $cid, $question, $answered, $answered ? 1.0 : 0.0, self::vocabulary(), time() );
	}

	/** Topic labels: normalised keywords of approved FAQ entries, filterable by the site. */
	public static function vocabulary() {
		$vocab = array();
		if ( class_exists( 'TZFD_AI_Answer_Box' ) ) {
			foreach ( TZFD_AI_Answer_Box::approved_entries() as $entry ) {
				foreach ( (array) ( $entry['keywords'] ?? array() ) as $keyword ) {
					$vocab[] = (string) $keyword;
				}
			}
		}
		$filtered = apply_filters( self::VOCAB_FILTER, $vocab );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : array();
	}

	/**
	 * Records one handled question. $vocabulary is an array of topic labels, for example service names.
	 * Returns one of: consent_off, answered, recorded, duplicate, unmatched.
	 */
	public static function record( $company_id, $text, $answered, $confidence, array $vocabulary, $now ) {
		if ( ! TZFD_Ai_Free_Core::consent_granted() ) {
			return 'consent_off';
		}
		$cid = (int) $company_id;
		if ( $cid < 1 ) {
			return 'company_required';
		}
		$all = self::load();
		$co  = self::prune( $all[ $cid ] ?? self::empty_company(), (int) $now );
		$day = TZFD_Ai_Free_Core::day_key( $now );
		$co['days'][ $day ]['total'] = (int) ( $co['days'][ $day ]['total'] ?? 0 ) + 1;

		$result = null;
		if ( $answered && (float) $confidence >= self::ANSWERED_CONFIDENCE ) {
			$result = 'answered';
		} else {
			$norm = self::normalise( $text );
			$h    = substr( hash( 'sha256', $norm ), 0, 16 );
			if ( isset( $co['recent'][ $h ] ) ) {
				$result = 'duplicate';
			} else {
				$co['recent'][ $h ] = (int) $now;
				$co['days'][ $day ]['unanswered'] = (int) ( $co['days'][ $day ]['unanswered'] ?? 0 ) + 1;
				$topic = self::match_topic( $norm, $vocabulary );
				if ( null === $topic ) {
					$result = 'unmatched';
				} else {
					$key = self::key_for( $topic );
					if ( ! isset( $co['topics'][ $key ] ) && count( $co['topics'] ) >= self::MAX_TOPICS ) {
						$result = 'unmatched';
					} else {
						$co['topics'][ $key ]['label'] = $topic;
						$co['topics'][ $key ]['days'][ $day ] = (int) ( $co['topics'][ $key ]['days'][ $day ] ?? 0 ) + 1;
						$result = 'recorded';
					}
				}
			}
		}
		$all[ $cid ] = $co;
		update_option( self::OPTION, $all, false );
		return $result;
	}

	/** Merges one topic into another for reporting. The source topic's counts still exist and are added in. */
	public static function merge_topics( $company_id, $from_key, $into_key ) {
		$cid = (int) $company_id;
		$all = self::load();
		if ( ! isset( $all[ $cid ] ) || $from_key === $into_key ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}
		if ( ! isset( $all[ $cid ]['topics'][ $from_key ], $all[ $cid ]['topics'][ $into_key ] ) ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}
		// Follow the existing chain from the target. Reaching the source means the merge would loop.
		$target = $into_key;
		for ( $i = 0; $i < 10 && isset( $all[ $cid ]['merges'][ $target ] ); $i++ ) {
			$target = $all[ $cid ]['merges'][ $target ];
		}
		if ( $target === $from_key ) {
			return TZFD_Ai_Free_Core::fail( 'merge_cycle' );
		}
		$all[ $cid ]['merges'][ $from_key ] = $into_key;
		update_option( self::OPTION, $all, false );
		return array( 'ok' => true );
	}

	/**
	 * Report for the window ending on $now. Filters: min_count (never below MIN_REPORT_COUNT),
	 * search (label substring), coverage ('missing' or 'covered', judged against approved text).
	 * $approved_texts is an array of approved knowledge-entry strings.
	 */
	public static function report( $company_id, $now, $window_days, array $filters, array $approved_texts ) {
		$cid  = (int) $company_id;
		// Consent withdrawn means nothing is shown, even if old data were still present.
		$co   = TZFD_Ai_Free_Core::consent_granted()
			? self::prune( self::load()[ $cid ] ?? self::empty_company(), (int) $now )
			: self::empty_company();
		$from =TZFD_Ai_Free_Core::day_key( (int) $now - ( max( 1, (int) $window_days ) - 1 ) * DAY_IN_SECONDS );
		$to   = TZFD_Ai_Free_Core::day_key( $now );
		$total = 0; $unanswered = 0;
		foreach ( $co['days'] as $day => $c ) {
			if ( $day < $from || $day > $to ) { continue; }
			$total      += (int) ( $c['total'] ?? 0 );
			$unanswered += (int) ( $c['unanswered'] ?? 0 );
		}
		$min   = max( self::MIN_REPORT_COUNT, (int) ( $filters['min_count'] ?? 0 ) );
		$search = strtolower( trim( (string) ( $filters['search'] ?? '' ) ) );
		$coverage = $filters['coverage'] ?? '';

		$rows = array();
		foreach ( self::merged_totals( $co, $from, $to ) as $key => $row ) {
			if ( $row['count'] < $min ) { continue; }
			if ( '' !== $search && false === strpos( strtolower( $row['label'] ), $search ) ) { continue; }
			$covered = self::is_covered( $row['label'], $approved_texts );
			if ( 'missing' === $coverage && $covered ) { continue; }
			if ( 'covered' === $coverage && ! $covered ) { continue; }
			$rows[] = array(
				'key'      => $key,
				'label'    => $row['label'],
				'count'    => $row['count'],
				'share'    => $total >= self::MIN_BASE ? round( $row['count'] / $total, 4 ) : null,
				'coverage' => $covered ? 'covered' : 'missing',
			);
		}
		usort( $rows, static function ( $a, $b ) { return $b['count'] <=> $a['count'] ?: strcmp( $a['label'], $b['label'] ); } );

		return array(
			'window'           => array( 'from' => $from, 'to' => $to ),
			'total'            => $total,
			'unanswered'       => $unanswered,
			'unanswered_share' => $total >= self::MIN_BASE ? round( $unanswered / $total, 4 ) : null,
			'insufficient'     => $total < self::MIN_BASE,
			'topics'           => $rows,
		);
	}

	/** Words of the label appear in approved text, so the topic is covered. */
	public static function is_covered( $label, array $approved_texts ) {
		$words = array_filter( explode( ' ', strtolower( (string) $label ) ) );
		foreach ( $approved_texts as $text ) {
			$hay = strtolower( (string) $text );
			$all = true;
			foreach ( $words as $w ) {
				if ( false === strpos( $hay, $w ) ) { $all = false; break; }
			}
			if ( $all && ! empty( $words ) ) { return true; }
		}
		return false;
	}

	/** Lower-case words with punctuation and contact details removed. */
	public static function normalise( $text ) {
		$t = TZFD_Ai_Free_Core::scrub( strtolower( (string) $text ) );
		$t = preg_replace( '/[^a-z0-9 ]+/', ' ', $t );
		return trim( preg_replace( '/\s+/', ' ', $t ) );
	}

	/** The longest vocabulary term whose words all appear in the normalised question. Null when none match. */
	public static function match_topic( $norm, array $vocabulary ) {
		$words = array_flip( array_filter( explode( ' ', $norm ) ) );
		$best  = null;
		foreach ( $vocabulary as $term ) {
			$tn = self::normalise( $term );
			$tw = array_filter( explode( ' ', $tn ), static function ( $w ) { return strlen( $w ) > 1 && ! in_array( $w, self::STOPWORDS, true ); } );
			if ( empty( $tw ) ) { continue; }
			$all = true;
			foreach ( $tw as $w ) {
				if ( ! isset( $words[ $w ] ) ) { $all = false; break; }
			}
			if ( $all && ( null === $best || strlen( $tn ) > strlen( $best ) ) ) {
				$best = $tn;
			}
		}
		return null === $best ? null : $best;
	}

	/** Stable topic key from the label. */
	public static function key_for( $label ) {
		return substr( hash( 'sha256', strtolower( (string) $label ) ), 0, 16 );
	}

	/** Sums per-day topic counts in the window, follows merges, and returns key => array( label, count ). */
	private static function merged_totals( array $co, $from, $to ) {
		$totals = array();
		foreach ( $co['topics'] as $key => $t ) {
			$count = 0;
			foreach ( $t['days'] as $day => $n ) {
				if ( $day >= $from && $day <= $to ) { $count += (int) $n; }
			}
			if ( 0 === $count ) { continue; }
			$target = $key;
			$seen = 0;
			while ( isset( $co['merges'][ $target ] ) && $seen++ < 10 ) { $target = $co['merges'][ $target ]; }
			$label = $co['topics'][ $target ]['label'] ?? $t['label'];
			$totals[ $target ]['label'] = $label;
			$totals[ $target ]['count'] = ( $totals[ $target ]['count'] ?? 0 ) + $count;
		}
		return $totals;
	}

	/** Drops days and dedupe marks past retention. Drops topics with no days left. */
	private static function prune( array $co, $now ) {
		$cutoff = TZFD_Ai_Free_Core::day_key( $now - self::RETENTION_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $co['days'] ?? array() ) as $day ) {
			if ( $day < $cutoff ) { unset( $co['days'][ $day ] ); }
		}
		foreach ( $co['topics'] ?? array() as $key => $t ) {
			foreach ( array_keys( $t['days'] ) as $day ) {
				if ( $day < $cutoff ) { unset( $co['topics'][ $key ]['days'][ $day ] ); }
			}
			if ( empty( $co['topics'][ $key ]['days'] ) ) { unset( $co['topics'][ $key ] ); }
		}
		foreach ( $co['recent'] ?? array() as $h => $ts ) {
			if ( (int) $ts < $now - self::DEDUPE_SECONDS ) { unset( $co['recent'][ $h ] ); }
		}
		return $co;
	}

	private static function empty_company() {
		return array( 'days' => array(), 'topics' => array(), 'merges' => array(), 'recent' => array() );
	}

	private static function load() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}
}
