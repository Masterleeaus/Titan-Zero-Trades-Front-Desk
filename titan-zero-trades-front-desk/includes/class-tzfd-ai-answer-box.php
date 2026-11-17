<?php
/**
 * Rules-first answer logic for the public AI answer box (issue #20).
 *
 * Answers come only from approved entries supplied through the
 * `tzfd_ai_approved_answers` filter (the interface that the FAQ registry of #42
 * will provide). The model fallback is off by default and, when enabled, is capped
 * at two generative turns per visitor. Price, slot and availability questions never
 * reach the model. Unmatched questions are refused with a human handoff.
 *
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Answer_Box {

	const VERSION_OPTION      = 'tzfd_ai_knowledge_version';
	const RATE_PREFIX         = 'tzfd_ai_rl_';
	const TURNS_PREFIX        = 'tzfd_ai_turns_';
	const CACHE_PREFIX        = 'tzfd_ai_ans_';
	const MAX_QUESTION_LENGTH = 300;
	const MAX_ANSWER_LENGTH   = 600;
	const RATE_LIMIT          = 20;
	const RATE_WINDOW         = 600;
	const FALLBACK_TURN_LIMIT = 2;
	const CACHE_TTL           = 3600;

	const STATUS_ANSWERED = 'answered';
	const STATUS_REFUSED  = 'refused';
	const STATUS_LIMITED  = 'limited';
	const STATUS_INVALID  = 'invalid';

	/** Words whose questions must never be answered by a model or guessed. */
	const SENSITIVE_TOKENS = array(
		'price', 'prices', 'pricing', 'cost', 'costs', 'fee', 'fees', 'charge', 'charges', 'quote',
		'discount', 'refund', 'available', 'availability', 'slot', 'slots', 'today', 'tomorrow', 'free',
		'much', 'cheap', 'cheaper', 'pay', 'payment', 'deposit', 'invoice',
	);

	/** Tokens that flip the meaning of a question. Keyword-only matches on these are refused. */
	const NEGATION_TOKENS = array( 'not', 'no', 'never', 'without', 'cannot', 'dont', 'doesnt', 'isnt', 'wont' );

	/**
	 * Normalise customer text: strip control characters and punctuation, lowercase, collapse spaces.
	 * Invalid UTF-8 normalises to an empty string.
	 *
	 * @param mixed $text Raw text.
	 * @return string
	 */
	public static function normalize( $text ) {
		if ( ! is_string( $text ) || '' === $text || ! preg_match( '//u', $text ) ) {
			return '';
		}
		$text = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( "/[^\\p{L}\\p{N}]+/u", ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	/**
	 * Approved entries after validation. Malformed entries are dropped, never repaired.
	 *
	 * @return array<int, array{id: string, keywords: string[], questions: string[], answer: string}>
	 */
	public static function approved_entries() {
		$raw = apply_filters( 'tzfd_ai_approved_answers', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$entries = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['answer'], $entry['keywords'] ) ) {
				continue;
			}
			$id     = is_string( $entry['id'] ) ? $entry['id'] : '';
			$answer = is_string( $entry['answer'] ) ? trim( wp_strip_all_tags( $entry['answer'] ) ) : '';
			if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $id ) || '' === $answer || strlen( $answer ) > self::MAX_ANSWER_LENGTH ) {
				continue;
			}
			if ( ! is_array( $entry['keywords'] ) ) {
				continue;
			}
			$keywords = array();
			foreach ( array_slice( $entry['keywords'], 0, 40 ) as $keyword ) {
				$norm = self::normalize( $keyword );
				if ( '' !== $norm && strlen( $norm ) <= 60 ) {
					$keywords[] = $norm;
				}
			}
			if ( empty( $keywords ) ) {
				continue;
			}
			$questions = array();
			if ( isset( $entry['questions'] ) && is_array( $entry['questions'] ) ) {
				foreach ( array_slice( $entry['questions'], 0, 20 ) as $question ) {
					$norm = self::normalize( $question );
					if ( '' !== $norm ) {
						$questions[] = $norm;
					}
				}
			}
			$entries[] = array(
				'id'        => $id,
				'keywords'  => array_values( array_unique( $keywords ) ),
				'questions' => array_values( array_unique( $questions ) ),
				'answer'    => $answer,
			);
		}
		return $entries;
	}

	/**
	 * Match a normalised question against approved entries.
	 *
	 * @param string $norm    Normalised question.
	 * @param array  $entries Output of approved_entries().
	 * @return array{entry: ?array, exact: bool, ambiguous: bool, negated: bool}
	 */
	public static function match( $norm, array $entries ) {
		$padded  = ' ' . $norm . ' ';
		$tokens  = '' === $norm ? array() : explode( ' ', $norm );
		$negated = (bool) array_intersect( $tokens, self::NEGATION_TOKENS );

		$best_score = 0;
		$best       = null;
		$best_exact = false;
		$ties       = 0;

		foreach ( $entries as $entry ) {
			$exact = in_array( $norm, $entry['questions'], true );
			$score = 0;
			foreach ( $entry['keywords'] as $keyword ) {
				if ( false !== strpos( $padded, ' ' . $keyword . ' ' ) ) {
					$score++;
				}
			}
			if ( $exact ) {
				$score += 1000;
			}
			if ( $score < 1 ) {
				continue;
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $entry;
				$best_exact = $exact;
				$ties       = 1;
			} elseif ( $score === $best_score ) {
				$ties++;
			}
		}

		return array(
			'entry'     => $ties === 1 ? $best : null,
			'exact'     => $best_exact,
			'ambiguous' => $ties > 1,
			'negated'   => $negated,
		);
	}

	/**
	 * Whether a question touches prices, slots or availability.
	 *
	 * @param string $norm Normalised question.
	 * @return bool
	 */
	public static function is_sensitive( $norm ) {
		$tokens = '' === $norm ? array() : explode( ' ', $norm );
		return (bool) array_intersect( $tokens, self::SENSITIVE_TOKENS );
	}

	/**
	 * Version tag used in cache keys. Bumping it makes every cached answer unreachable.
	 *
	 * @return int
	 */
	public static function knowledge_version() {
		return absint( get_option( self::VERSION_OPTION, 1 ) );
	}

	/** Call after any approved FAQ is edited or deleted so stale answers are never served. */
	public static function invalidate() {
		update_option( self::VERSION_OPTION, self::knowledge_version() + 1, false );
	}

	/**
	 * Cache key: site, knowledge version, language and normalised request. Never contains visitor data.
	 *
	 * @param string $norm Normalised question.
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function cache_key( $norm, $lang = 'en' ) {
		$site = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return self::CACHE_PREFIX . md5( $site . '|' . self::knowledge_version() . '|' . $lang . '|' . $norm );
	}

	/**
	 * Count this visitor request against the unauthenticated rate limit.
	 *
	 * Counting is best-effort (transients are not atomic), so it limits casual abuse and does not
	 * guarantee an exact ceiling under parallel requests.
	 *
	 * @param string $visitor_key Hashed visitor key.
	 * @return bool True when the request is allowed.
	 */
	public static function allow_request( $visitor_key ) {
		$key   = self::RATE_PREFIX . md5( $visitor_key );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Answer one customer question.
	 *
	 * @param mixed  $question    Raw question text.
	 * @param string $visitor_key Hashed visitor key (never a raw IP address).
	 * @param string $lang        Language code.
	 * @return array{status: string, answer: string, entry_id: string, source: string, handoff: bool, cached: bool}
	 */
	public static function answer( $question, $visitor_key, $lang = 'en' ) {
		$base = array( 'status' => self::STATUS_INVALID, 'answer' => '', 'entry_id' => '', 'source' => 'none', 'handoff' => false, 'cached' => false );

		if ( ! is_string( $question ) || strlen( $question ) > self::MAX_QUESTION_LENGTH ) {
			return $base;
		}
		$norm = self::normalize( $question );
		if ( '' === $norm ) {
			return $base;
		}

		if ( ! self::allow_request( (string) $visitor_key ) ) {
			$base['status'] = self::STATUS_LIMITED;
			$base['handoff'] = true;
			return $base;
		}

		$cache_key = self::cache_key( $norm, $lang );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$entries = self::approved_entries();
		$found   = self::match( $norm, $entries );

		if ( $found['entry'] && ! $found['ambiguous'] && ( $found['exact'] || ! $found['negated'] ) ) {
			$result = array(
				'status'   => self::STATUS_ANSWERED,
				'answer'   => $found['entry']['answer'],
				'entry_id' => $found['entry']['id'],
				'source'   => 'approved_rule',
				'handoff'  => false,
				'cached'   => false,
			);
			set_transient( $cache_key, $result, self::CACHE_TTL );
			return $result;
		}

		// Unmatched, ambiguous or negated questions are refused, and sensitive topics never reach a model.
		$refusal = array_merge( $base, array( 'status' => self::STATUS_REFUSED, 'handoff' => true, 'source' => 'none' ) );
		if ( self::is_sensitive( $norm ) || ! self::fallback_enabled() ) {
			set_transient( $cache_key, $refusal, self::CACHE_TTL );
			return $refusal;
		}

		return self::generative_fallback( $norm, (string) $visitor_key, $lang, $refusal );
	}

	/** @return bool Whether the proxy fallback is switched on. Off unless the site enables it. */
	public static function fallback_enabled() {
		return (bool) apply_filters( 'tzfd_ai_fallback_enabled', false );
	}

	/**
	 * Bounded model fallback. Two turns per visitor; any failure or offline proxy becomes a handoff.
	 *
	 * @param string $norm        Normalised question.
	 * @param string $visitor_key Hashed visitor key.
	 * @param string $lang        Language code.
	 * @param array  $refusal     Refusal result to return when the fallback cannot answer.
	 * @return array
	 */
	private static function generative_fallback( $norm, $visitor_key, $lang, array $refusal ) {
		$key   = self::TURNS_PREFIX . md5( $visitor_key );
		$turns = (int) get_transient( $key );
		if ( $turns >= self::FALLBACK_TURN_LIMIT ) {
			return $refusal;
		}
		set_transient( $key, $turns + 1, self::RATE_WINDOW );

		$text = apply_filters( 'tzfd_ai_generative_fallback', null, $norm, $lang );
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $refusal; // Null, WP_Error or an offline proxy all end in a human handoff.
		}
		$text = trim( wp_strip_all_tags( $text ) );
		if ( strlen( $text ) > self::MAX_ANSWER_LENGTH ) {
			$text = substr( $text, 0, self::MAX_ANSWER_LENGTH );
		}

		// Model answers are not cached: only approved rule answers may be reused.
		return array(
			'status'   => self::STATUS_ANSWERED,
			'answer'   => $text,
			'entry_id' => '',
			'source'   => 'model',
			'handoff'  => true,
			'cached'   => false,
		);
	}
}
