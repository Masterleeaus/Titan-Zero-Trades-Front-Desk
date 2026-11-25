<?php
/**
 * Wires the smart service finder into the booking form (issues #23, #269, owner decision 2026-11-18).
 *
 * Uses the existing tzfd_the_form filter, the same hook the add-ons use, so the booking engine and
 * the catalogue are not changed. The customer's search runs on the server against the visible,
 * company-scoped service list. A selected service ID is accepted only after validate_selection() and is
 * written into the form as a hidden field named tzfd_service_id. Nothing is written by this class.
 *
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-service-finder.php';

final class TZFD_Service_Finder_Wiring {

	const FILTER_HOOK   = 'tzfd_the_form';
	const SERVICES_HOOK = 'tzfd_service_finder_services';
	const PARAM_QUERY   = 'tzfd_svc_q';
	const PARAM_SERVICE = 'tzfd_service';
	const FIELD         = 'tzfd_service_id';
	const MAX_QUERY     = 300;

	public static function boot() {
		add_filter( self::FILTER_HOOK, array( __CLASS__, 'filter_form' ), 20, 2 );
	}

	/**
	 * Visible catalogue records for this company. Filterable so tests can supply fixtures.
	 *
	 * @return array
	 */
	public static function services() {
		$services = class_exists( 'TZFD_Local_Services' )
			? TZFD_Local_Services::list( array( 'limit' => 200 ) )
			: array();
		$services = apply_filters( 'tzfd_service_finder_services', $services );
		return is_array( $services ) ? $services : array();
	}

	/**
	 * tzfd_the_form callback. Returns the form HTML with the finder panel above it and, when a valid
	 * service was selected, a hidden service field inside each form.
	 *
	 * @param string $html    Form markup built by the booking form.
	 * @param mixed  $form_id Form ID (unused).
	 * @return string
	 */
	public static function filter_form( $html, $form_id = 0 ) {
		unset( $form_id );
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$services = self::services();
		$selected = self::selected_service( $services );
		$page     = self::page_context();

		if ( $selected > 0 ) {
			$hidden = '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( (string) $selected ) . '" />';
			$html   = (string) preg_replace_callback(
				'/<form\b[^>]*>/i',
				function ( $m ) use ( $hidden ) { return $m[0] . $hidden; },
				$html
			);
		}

		return self::panel( $services, $page ) . $html;
	}

	/**
	 * Validated service ID from the request, or 0.
	 *
	 * @param array $services Catalogue records.
	 * @return int
	 */
	public static function selected_service( array $services ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection; validated against the catalogue below.
		$raw = isset( $_GET[ self::PARAM_SERVICE ] ) ? wp_unslash( $_GET[ self::PARAM_SERVICE ] ) : '';
		if ( ! is_string( $raw ) ) {
			return 0;
		}
		return TZFD_Service_Finder::validate_selection( trim( $raw ), $services );
	}

	/**
	 * Page URL and the query parameters that identify the page (plain permalinks use ?page_id=N).
	 *
	 * @return array{action: string, query: array<string, string>}
	 */
	private static function page_context() {
		$permalink = function_exists( 'get_permalink' ) ? get_permalink() : '';
		$permalink = is_string( $permalink ) && '' !== $permalink ? $permalink : home_url( '/' );
		$parts     = wp_parse_url( $permalink );
		$action    = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' );
		parse_str( $parts['query'] ?? '', $query );
		$clean = array();
		foreach ( $query as $key => $value ) {
			if ( is_string( $value ) && preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $key ) ) {
				$clean[ (string) $key ] = $value;
			}
		}
		return array( 'action' => $action, 'query' => $clean );
	}

	/**
	 * Search box, and the matches for the current search. Markup uses only tags the booking form allows
	 * (see booking_form_markup_allowed), because the form output is passed through wp_kses afterwards.
	 *
	 * @param array $services Catalogue records.
	 * @param array $page     Output of page_context().
	 * @return string
	 */
	private static function panel( array $services, array $page ) {
		$query = self::query_text();
		$out   = '<div class="tzfd-svc-finder" id="tzfd-svc-finder">';
		$out  .= '<form class="tzfd-svc-form" method="get" action="' . esc_url( $page['action'] ) . '">';
		foreach ( $page['query'] as $key => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}
		$out .= '<label for="tzfd-svc-q">' . esc_html__( 'Describe the service you need', 'titan-zero-trades-front-desk' ) . '</label>';
		$out .= '<input type="search" id="tzfd-svc-q" name="' . esc_attr( self::PARAM_QUERY ) . '" value="' . esc_attr( $query ) . '" autocomplete="off" />';
		$out .= '<input type="submit" value="' . esc_attr__( 'Find service', 'titan-zero-trades-front-desk' ) . '" />';
		$out .= '</form>';

		if ( '' !== $query ) {
			$out .= '<div class="tzfd-svc-results" role="status" aria-live="polite">' . self::results_html( $services, $page, $query ) . '</div>';
		}
		return $out . '</div>';
	}

	private static function results_html( array $services, array $page, $query ) {
		$match = TZFD_Service_Finder::match( $query, $services );
		if ( 'one' === $match['status'] || 'many' === $match['status'] ) {
			$html = '';
			if ( 'many' === $match['status'] && '' !== $match['question'] ) {
				$html .= '<p>' . esc_html( $match['question'] ) . '</p>';
			}
			foreach ( $match['matches'] as $row ) {
				$link  = add_query_arg( array_merge( $page['query'], array( self::PARAM_SERVICE => (string) $row['id'] ) ), $page['action'] );
				$html .= '<div><a href="' . esc_url( $link ) . '">' . esc_html( sprintf( /* translators: %s: service name from the catalogue */ __( 'Book %s', 'titan-zero-trades-front-desk' ), $row['name'] ) ) . '</a></div>';
			}
			return $html;
		}
		return '<p>' . esc_html__( 'No matching service was found. Choose the service in the form below.', 'titan-zero-trades-front-desk' ) . '</p>';
	}

	/** The search text as typed, trimmed and length-limited. */
	private static function query_text() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search text; length-limited and matched only against the catalogue.
		$raw = isset( $_GET[ self::PARAM_QUERY ] ) ? wp_unslash( $_GET[ self::PARAM_QUERY ] ) : '';
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( preg_replace( '/[\x00-\x1F\x7F]/', ' ', $raw ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, self::MAX_QUERY ) : substr( $raw, 0, self::MAX_QUERY );
	}
}
