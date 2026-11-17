<?php
/**
 * Public AI answer box shortcode [tzfd_ai_answer_box] (issue #20).
 *
 * Works without JavaScript: the question is a GET form on the same page, and the answer is
 * rendered on the server. Output is escaped and plain text only. Booking and enquiry links are
 * handoffs, not writes. Visitor identity is a salted hash of REMOTE_ADDR, never stored raw.
 *
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-ai-answer-box.php';
require_once __DIR__ . '/class-tzfd-service-finder.php';
require_once __DIR__ . '/class-tzfd-booking-assistant.php';

final class TZFD_AI_Widget {

	const SHORTCODE = 'tzfd_ai_answer_box';
	const PARAM     = 'tzfd_ai_q';
	const STYLE     = 'tzfd-ai-answer-box';

	/** @var int Instance counter so several boxes on one page get unique IDs. */
	private static $instances = 0;

	public static function boot() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Shortcode attributes (unused).
	 * @return string HTML.
	 */
	public static function render( $atts = array() ) {
		unset( $atts );
		self::$instances++;
		$uid = 'tzfd-ai-' . self::$instances;

		wp_enqueue_style( self::STYLE, plugins_url( 'css/tzfd-ai-answer-box.css', dirname( __DIR__ ) . '/titan-zero-front-desk.php' ), array(), '1' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only public question; no state change. Sanitised with sanitize_text_field() below.
		$raw      = isset( $_GET[ self::PARAM ] ) ? wp_unslash( $_GET[ self::PARAM ] ) : '';
		$question = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		$answer_html = '';
		if ( '' !== trim( $question ) ) {
			$result      = TZFD_AI_Answer_Box::answer( $question, self::visitor_key() );
			$answer_html = self::answer_html( $result );
		}

		$booking = self::booking_url();
		$enquiry = self::enquiry_url();

		$out  = '<section class="tzfd-ai-box" aria-labelledby="' . esc_attr( $uid ) . '-title">';
		$out .= '<h2 id="' . esc_attr( $uid ) . '-title">' . esc_html__( 'Ask a question', 'titan-zero-trades-front-desk' ) . '</h2>';
		$out .= '<form class="tzfd-ai-form" method="get">';
		$out .= '<label for="' . esc_attr( $uid ) . '-q">' . esc_html__( 'Your question', 'titan-zero-trades-front-desk' ) . '</label>';
		$out .= '<input type="search" id="' . esc_attr( $uid ) . '-q" name="' . esc_attr( self::PARAM ) . '" maxlength="' . (int) TZFD_AI_Answer_Box::MAX_QUESTION_LENGTH . '" value="' . esc_attr( $question ) . '" autocomplete="off" enterkeyhint="search">';
		$out .= '<button type="submit">' . esc_html__( 'Get answer', 'titan-zero-trades-front-desk' ) . '</button>';
		$out .= '</form>';
		$out .= '<div class="tzfd-ai-answer" role="status" aria-live="polite">' . $answer_html . '</div>';
		$out .= '<p class="tzfd-ai-links">';
		if ( '' !== $booking ) {
			$out .= '<a href="' . esc_url( $booking ) . '">' . esc_html__( 'Book an appointment', 'titan-zero-trades-front-desk' ) . '</a> ';
		}
		if ( '' !== $enquiry ) {
			$out .= '<a href="' . esc_url( $enquiry ) . '">' . esc_html__( 'Talk to a person', 'titan-zero-trades-front-desk' ) . '</a>';
		}
		$out .= '</p></section>';

		return $out;
	}

	/**
	 * Plain-text answer markup. Model text is escaped like everything else.
	 *
	 * @param array $result Output of TZFD_AI_Answer_Box::answer().
	 * @return string
	 */
	public static function answer_html( array $result ) {
		$messages = array(
			TZFD_AI_Answer_Box::STATUS_INVALID => __( 'Please enter a question of up to 300 characters.', 'titan-zero-trades-front-desk' ),
			TZFD_AI_Answer_Box::STATUS_LIMITED => __( 'Too many questions were asked from this connection. Please try again later or talk to a person.', 'titan-zero-trades-front-desk' ),
			TZFD_AI_Answer_Box::STATUS_REFUSED => __( 'We do not have an approved answer to that question. Please talk to a person.', 'titan-zero-trades-front-desk' ),
		);
		$status = isset( $result['status'] ) ? (string) $result['status'] : '';
		if ( TZFD_AI_Answer_Box::STATUS_ANSWERED === $status && isset( $result['answer'] ) && is_string( $result['answer'] ) ) {
			$note = 'model' === ( $result['source'] ?? '' )
				? __( 'Automated answer. Please confirm details with us.', 'titan-zero-trades-front-desk' )
				: '';
			return '<p>' . esc_html( $result['answer'] ) . '</p>' . ( '' !== $note ? '<p class="tzfd-ai-note">' . esc_html( $note ) . '</p>' : '' );
		}
		return '<p>' . esc_html( $messages[ $status ] ?? $messages[ TZFD_AI_Answer_Box::STATUS_REFUSED ] ) . '</p>';
	}

	/** Salted, hashed visitor key. The raw address is never stored. */
	private static function visitor_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = 'unknown';
		}
		return wp_hash( $ip );
	}

	/** @return string Booking page URL supplied by the site, or empty. */
	private static function booking_url() {
		$url = apply_filters( 'tzfd_ai_booking_url', '' );
		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/** @return string Enquiry page URL supplied by the site, or empty. */
	private static function enquiry_url() {
		$url = apply_filters( 'tzfd_ai_enquiry_url', '' );
		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}
}
