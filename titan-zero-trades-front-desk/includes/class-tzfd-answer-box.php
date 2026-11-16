<?php
/**
 * Customer-facing Answer Box (issue #20, Free AI 01).
 *
 * Answers visitor questions only from owner-approved sources: approved FAQ/policy entries,
 * the owner's business-hours text and the local service catalogue. Order of resolution:
 *   1. approved cache (keyed by the fingerprint of the approved knowledge base, so any edit misses)
 *   2. exact normalised match on an approved question
 *   3. keyword match on an approved entry (unique best match above a coverage threshold)
 *   4. generative fallback, only when the owner enables a daily cap and a provider is registered
 *      through the tzfd_answer_box_generate filter; max 2 generative turns per visitor conversation
 *   5. refusal with a human handoff link
 * Generated text is shown as escaped plain text and is refused when it makes price, time, licence or
 * guarantee claims. Generated text is never cached.
 *
 * No provider ships in this build, so the generative branch is inert by default.
 *
 * @package titan-zero-trades-front-desk
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Answer_Box {

	const SHORTCODE       = 'tzfd_answer_box';
	const OPT_ENTRIES     = 'tzfd_answer_box_entries';    // approved FAQ and policy entries only
	const OPT_HOURS       = 'tzfd_answer_box_hours';      // owner-approved business hours text
	const OPT_HANDOFF_URL = 'tzfd_answer_box_handoff_url'; // owner-approved human handoff link
	const OPT_VERSION     = 'tzfd_answer_box_version';    // bumped on every explicit knowledge change
	const OPT_BUDGET      = 'tzfd_answer_box_pilot_budget'; // shared daily generation counter
	const PARAM_Q         = 'tzab_q';
	const PARAM_C         = 'tzab_c';
	const MAX_QUESTION    = 300;
	const MAX_ANSWER      = 1000;
	const MAX_GENERATED   = 500;
	const MAX_TURNS       = 2;     // generative turns per visitor conversation during pilot
	const CONV_TTL        = 7200;
	const RATE_MAX        = 10;    // queries per client per window (anonymous)
	const RATE_WINDOW     = 600;
	const CACHE_TTL       = 86400;
	const MATCH_COVERAGE  = 0.5;

	/** Words that carry no meaning for matching. */
	const STOPWORDS = array(
		'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'do', 'does', 'did', 'you', 'your', 'yours',
		'i', 'me', 'my', 'we', 'our', 'us', 'to', 'of', 'and', 'or', 'in', 'on', 'at', 'for', 'with',
		'what', 'when', 'where', 'which', 'how', 'can', 'could', 'would', 'will', 'it', 'this', 'that',
		'there', 'any', 'some', 'please', 'hi', 'hello', 'if', 'so', 'have', 'has', 'get', 'got',
	);

	/** Generated text that makes a claim the owner has not approved is refused. */
	const REFUSED_CLAIM_PATTERN = '/[$€£]|\b(AUD|USD|NZD)\b|\d\s*(am|pm)\b|\d:\d{2}|\bper (hour|visit|job)\b|\b(guarantee[ds]?|insured|licen[cs]ed|certified|cheapest|discount|free|refund)\b|https?:|www\./i';

	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/* ------------------------------------------------------------------ owner-approved knowledge */

	/**
	 * Store approved FAQ/policy entries. Entries without an explicit approved flag are dropped.
	 *
	 * @param array $raw Each: kind (faq|policy), question, answer, keywords (array|string), approved (true).
	 * @return int Number of entries stored.
	 */
	public static function save_entries( $raw ) {
		$clean = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( ! is_array( $entry ) || true !== ( $entry['approved'] ?? false ) ) { continue; }
			$question = isset( $entry['question'] ) && is_scalar( $entry['question'] ) ? sanitize_text_field( (string) $entry['question'] ) : '';
			$answer   = isset( $entry['answer'] ) && is_scalar( $entry['answer'] ) ? sanitize_textarea_field( (string) $entry['answer'] ) : '';
			if ( '' === $question || '' === $answer ) { continue; }
			$keywords = is_array( $entry['keywords'] ?? null ) ? $entry['keywords'] : explode( ',', (string) ( $entry['keywords'] ?? '' ) );
			$clean[]  = array(
				'kind'     => in_array( ( $entry['kind'] ?? '' ), array( 'faq', 'policy' ), true ) ? $entry['kind'] : 'faq',
				'question' => substr( $question, 0, 190 ),
				'answer'   => substr( $answer, 0, self::MAX_ANSWER ),
				'keywords' => array_values( array_filter( array_map( 'sanitize_text_field', array_filter( $keywords, 'is_scalar' ) ) ) ),
			);
		}
		update_option( self::OPT_ENTRIES, $clean, false );
		self::bump_version();
		return count( $clean );
	}

	/** Store the owner's approved business-hours text. Empty text means hours are not answered. */
	public static function save_hours( $text ) {
		update_option( self::OPT_HOURS, substr( sanitize_textarea_field( (string) $text ), 0, self::MAX_ANSWER ), false );
		self::bump_version();
	}

	public static function save_handoff_url( $url ) {
		update_option( self::OPT_HANDOFF_URL, esc_url_raw( (string) $url ), false );
		self::bump_version();
	}

	public static function bump_version() {
		update_option( self::OPT_VERSION, bin2hex( random_bytes( 8 ) ), false );
	}

	/**
	 * Every approved knowledge item the box may answer from.
	 * Each item: id, kind, question, answer, vocabulary (normalised tokens).
	 *
	 * @return array<int, array>
	 */
	public static function knowledge() {
		$items = array();
		$index = 0;
		foreach ( (array) get_option( self::OPT_ENTRIES, array() ) as $entry ) {
			if ( ! is_array( $entry ) || '' === trim( (string) ( $entry['answer'] ?? '' ) ) ) { continue; }
			$vocab = self::tokens( (string) $entry['question'] );
			foreach ( (array) ( $entry['keywords'] ?? array() ) as $keyword ) {
				$vocab = array_merge( $vocab, self::tokens( (string) $keyword ) );
			}
			$items[] = array( 'id' => 'e' . $index++, 'kind' => $entry['kind'] ?? 'faq', 'question' => (string) $entry['question'],
				'answer' => (string) $entry['answer'], 'vocabulary' => array_values( array_unique( $vocab ) ) );
		}
		$hours = trim( (string) get_option( self::OPT_HOURS, '' ) );
		if ( '' !== $hours ) {
			$items[] = array( 'id' => 'hours', 'kind' => 'policy', 'question' => 'Business hours', 'answer' => $hours,
				'vocabulary' => array( 'hours', 'open', 'opening', 'close', 'closed', 'closing', 'time' ) );
		}
		foreach ( self::services() as $service ) {
			$answer = $service['name'] . '. ' . $service['description'];
			if ( $service['duration'] > 0 ) { $answer .= ' Typical duration: ' . $service['duration'] . ' minutes.'; }
			$items[] = array( 'id' => 'svc' . $service['id'], 'kind' => 'service', 'question' => $service['name'],
				'answer' => trim( $answer ), 'vocabulary' => self::tokens( $service['name'] ) );
		}
		return $items;
	}

	/** Active services from the local catalogue. Prices are deliberately not repeated in answers. */
	private static function services() {
		if ( ! class_exists( 'TZFD_Local_Services' ) ) { return array(); }
		$rows = (array) TZFD_Local_Services::list( array( 'limit' => 200 ) );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) { continue; }
			$out[] = array( 'id' => (int) ( $row['id'] ?? 0 ), 'name' => sanitize_text_field( (string) $row['name'] ),
				'description' => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
				'duration' => (int) ( $row['duration_minutes'] ?? 0 ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ matching helpers */

	/** Lower-case words with punctuation removed. */
	public static function normalize( $text ) {
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $text );
		$text = mb_strtolower( (string) $text, 'UTF-8' );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/** Meaningful unique tokens of a text. */
	public static function tokens( $text ) {
		$words = array_filter( explode( ' ', self::normalize( $text ) ), function ( $word ) {
			return strlen( $word ) > 1 && ! in_array( $word, self::STOPWORDS, true );
		} );
		return array_values( array_unique( $words ) );
	}

	/** Knowledge fingerprint; any change to approved content produces a new cache key. */
	private static function fingerprint( array $items ) {
		$parts = array();
		foreach ( $items as $item ) { $parts[] = $item['id'] . '|' . $item['question'] . '|' . $item['answer']; }
		return md5( (string) get_option( self::OPT_VERSION, '' ) . '||' . implode( "\n", $parts ) );
	}

	/**
	 * Unique best keyword match. Coverage is the share of question tokens found in an entry's vocabulary.
	 * A tie at the top, or coverage below the threshold, is a refusal rather than a guess.
	 */
	public static function best_match( array $items, $question ) {
		$q = self::tokens( $question );
		if ( empty( $q ) ) { return null; }
		$scored = array();
		foreach ( $items as $item ) {
			$hits = count( array_intersect( $q, $item['vocabulary'] ) );
			if ( $hits > 0 ) { $scored[] = array( 'item' => $item, 'cover' => $hits / count( $q ), 'hits' => $hits ); }
		}
		if ( empty( $scored ) ) { return null; }
		usort( $scored, function ( $a, $b ) { return ( $b['cover'] <=> $a['cover'] ) ?: ( $b['hits'] <=> $a['hits'] ); } );
		if ( $scored[0]['cover'] < self::MATCH_COVERAGE ) { return null; }
		if ( isset( $scored[1] ) && $scored[1]['cover'] === $scored[0]['cover'] && $scored[1]['hits'] === $scored[0]['hits'] ) { return null; }
		return $scored[0]['item'];
	}

	/* ------------------------------------------------------------------ answering */

	/**
	 * Resolve one question. Returns array: source, text, link (array url,label or null), status.
	 *
	 * @param string $question Raw visitor text, already unslashed.
	 * @param string $conversation 32 hex characters identifying one visitor conversation.
	 * @param string $client Anonymous client key (hashed by the caller); used for rate limiting only.
	 */
	public static function answer( $question, $conversation, $client = '' ) {
		$question = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $question ) );
		if ( '' === $question || mb_strlen( $question, 'UTF-8' ) > self::MAX_QUESTION ) {
			return self::result( 'invalid', __( 'Please enter a question of up to 300 characters.', 'titan-zero-trades-front-desk' ) );
		}
		if ( '' !== $client && self::rate_limited( $client ) ) {
			return self::result( 'rate_limited', __( 'You have sent several questions in a short time. Please wait a few minutes, or contact the business directly.', 'titan-zero-trades-front-desk' ), self::handoff_link() );
		}

		$items   = self::knowledge();
		$cache   = 'tzab_c_' . md5( self::fingerprint( $items ) . '|' . self::normalize( $question ) );
		$cached  = get_transient( $cache );
		if ( is_array( $cached ) && isset( $cached['text'] ) ) {
			return self::result( 'cache', (string) $cached['text'], self::link_for( $cached ) );
		}

		$match = self::best_match( $items, $question );
		if ( null !== $match ) {
			set_transient( $cache, array( 'id' => $match['id'], 'text' => $match['answer'], 'url' => $match['url'] ?? '' ), self::CACHE_TTL );
			return self::result( 'approved', $match['answer'], self::link_for( $match ) );
		}

		return self::generative( $question, $conversation, $items );
	}

	/** Generative fallback under the pilot limits. Refuses rather than invents. */
	private static function generative( $question, $conversation, array $items ) {
		$cap = (int) apply_filters( 'tzfd_answer_box_daily_generation_cap', 0 );
		if ( $cap < 1 ) {
			return self::refusal( 'unavailable' );
		}
		$budget = get_option( self::OPT_BUDGET, array() );
		$today  = gmdate( 'Y-m-d' );
		$used   = ( is_array( $budget ) && ( $budget['day'] ?? '' ) === $today ) ? (int) ( $budget['used'] ?? 0 ) : 0;
		if ( $used >= $cap ) {
			return self::refusal( 'budget_exhausted' );
		}
		$turn_key = 'tzab_g_' . md5( $conversation );
		$turns    = (int) get_transient( $turn_key );
		if ( $turns >= self::MAX_TURNS ) {
			return self::refusal( 'turns_exhausted' );
		}
		// Count the attempt before calling out, so failing providers cannot be hammered.
		update_option( self::OPT_BUDGET, array( 'day' => $today, 'used' => $used + 1 ), false );
		set_transient( $turn_key, $turns + 1, self::CONV_TTL );

		$context = array_map( function ( $item ) { return array( 'question' => $item['question'], 'answer' => $item['answer'] ); }, $items );
		try {
			$reply = apply_filters( 'tzfd_answer_box_generate', null, array( 'question' => $question, 'context' => $context ) );
		} catch ( Throwable $e ) {
			return self::refusal( 'proxy_unavailable' );
		}
		if ( ! is_array( $reply ) || empty( $reply['ok'] ) || ! isset( $reply['text'] ) || ! is_string( $reply['text'] ) ) {
			return self::refusal( 'proxy_unavailable' );
		}
		$text = trim( wp_strip_all_tags( $reply['text'] ) );
		if ( '' === $text || mb_strlen( $text, 'UTF-8' ) > self::MAX_GENERATED || preg_match( self::REFUSED_CLAIM_PATTERN, $text ) ) {
			return self::refusal( 'unsupported_claim' );
		}
		// Generated text is never cached and never mistaken for an approved answer.
		return self::result( 'generated', $text, self::handoff_link() );
	}

	private static function refusal( $status ) {
		return self::result( $status, __( "I'm not able to answer that reliably, so I won't guess. Please contact the business or request a booking and a person will help.", 'titan-zero-trades-front-desk' ), self::handoff_link() );
	}

	private static function result( $status, $text, $link = null ) {
		return array( 'status' => $status, 'source' => $status, 'text' => (string) $text, 'link' => $link );
	}

	/** Booking link, always offered as the deep link. */
	private static function link_for( array $item ) {
		return self::handoff_link();
	}

	private static function handoff_link() {
		$url = (string) get_option( self::OPT_HANDOFF_URL, '' );
		if ( '' === $url && class_exists( 'TZFD_Client_Portal' ) ) {
			$page = (int) get_option( TZFD_Client_Portal::OPT_BOOKING_PAGE, 0 );
			$url  = $page > 0 ? (string) get_permalink( $page ) : '';
		}
		return '' === $url ? null : array( 'url' => $url, 'label' => __( 'Book or contact us', 'titan-zero-trades-front-desk' ) );
	}

	/** Anonymous rate limit. Stores only a salted hash of the client key, never the address. */
	private static function rate_limited( $client ) {
		$key   = 'tzab_rl_' . md5( (string) wp_hash( $client ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_MAX ) { return true; }
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return false;
	}

	/* ------------------------------------------------------------------ shortcode */

	/** Renders the widget. Works without JavaScript: a plain GET form, results in the page. */
	public static function render() {
		$question = '';
		$conv     = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only question form; the anonymous rate limit is the control.
		if ( isset( $_GET[ self::PARAM_Q ] ) ) { $question = sanitize_text_field( wp_unslash( $_GET[ self::PARAM_Q ] ) ); }
		if ( isset( $_GET[ self::PARAM_C ] ) ) { $conv = sanitize_text_field( wp_unslash( $_GET[ self::PARAM_C ] ) ); }
		// phpcs:enable
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $conv ) ) { $conv = bin2hex( random_bytes( 16 ) ); }

		$client = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed before use.
		$out    = '<section class="tzab" aria-labelledby="tzab-title"><style>'
			. '.tzab{max-width:40rem;font:inherit;line-height:1.5}.tzab input[type=text]{width:100%;box-sizing:border-box;min-height:44px;padding:.5rem;font:inherit}'
			. '.tzab button,.tzab a{min-height:44px;display:inline-block;padding:.5rem 1rem;font:inherit}'
			. '.tzab :focus{outline:3px solid currentColor;outline-offset:2px}.tzab-result{margin-top:1rem}'
			. '</style>';
		$out   .= '<h2 id="tzab-title">' . esc_html__( 'Ask a question', 'titan-zero-trades-front-desk' ) . '</h2>';
		$out   .= '<form method="get" action="" class="tzab-form">';
		$out   .= '<input type="hidden" name="' . esc_attr( self::PARAM_C ) . '" value="' . esc_attr( $conv ) . '">';
		$out   .= '<label for="tzab-q">' . esc_html__( 'Your question', 'titan-zero-trades-front-desk' ) . '</label> ';
		$out   .= '<input type="text" id="tzab-q" name="' . esc_attr( self::PARAM_Q ) . '" maxlength="' . self::MAX_QUESTION . '" autocomplete="off" aria-describedby="tzab-hint" value="' . esc_attr( $question ) . '">';
		$out   .= '<p id="tzab-hint">' . esc_html__( 'Answers come from approved business information only.', 'titan-zero-trades-front-desk' ) . '</p>';
		$out   .= '<button type="submit">' . esc_html__( 'Ask', 'titan-zero-trades-front-desk' ) . '</button></form>';

		// The status region is always present so assistive technology announces the first answer too.
		$out .= '<div class="tzab-result" role="status" aria-live="polite">';
		if ( '' !== $question ) {
			// An unknown address still shares one rate-limit bucket, so it can never bypass the limit.
			$r    = self::answer( $question, $conv, wp_hash( '' !== $client ? $client : 'unknown' ) );
			$out .= '<p>' . esc_html( self::plain_text( $r['text'] ) ) . '</p>';
			if ( is_array( $r['link'] ) && '' !== $r['link']['url'] ) {
				$out .= '<p><a href="' . esc_url( $r['link']['url'] ) . '">' . esc_html( $r['link']['label'] ) . '</a></p>';
			}
		}
		$out .= '</div>';
		return $out . '</section>';
	}

	/**
	 * Plain-text rendering: removes markup and markdown syntax so that nothing is interpreted as HTML.
	 * The result is still escaped by the caller.
	 */
	public static function plain_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/!?\[([^\]]*)\]\(((?:[^()]++|\([^()]*\))*)\)/', '$1', $text );
		$text = preg_replace( '/[*_`#>]+/', '', $text );
		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}
}
