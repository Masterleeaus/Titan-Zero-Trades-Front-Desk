<?php
/**
 * Smart Service Finder (deterministic core).
 *
 * Maps a customer's wording onto the existing service catalogue of one calendar
 * (the `fapp` services already stored in the form structure). It never creates,
 * prices, renames or removes a service, and it never queries the database with
 * customer text. Text is treated as data: it is normalised, length-capped and
 * compared against catalogue names only.
 *
 * GPL-2.0-only.
 *
 * @package titan-zero-trades-front-desk
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Service_Finder_Core {

	const MAX_QUERY_CHARS = 300;
	const MAX_RESULTS     = 5;
	const MAX_SERVICE_IDX = 9999;

	const STATUS_NONE     = 'none';
	const STATUS_SINGLE   = 'single';
	const STATUS_MULTIPLE = 'multiple';
	const STATUS_EMPTY    = 'clarify';

	/**
	 * Words that carry no service meaning. Removed before matching.
	 *
	 * @var string[]
	 */
	private static $stopwords = array(
		'a', 'an', 'the', 'i', 'me', 'my', 'we', 'our', 'you', 'to', 'for', 'of', 'in', 'on', 'at', 'it',
		'is', 'are', 'do', 'does', 'can', 'could', 'would', 'please', 'need', 'want', 'like', 'get', 'have',
		'help', 'with', 'and', 'or', 'some', 'looking', 'book', 'booking', 'service', 'services',
	);

	/**
	 * Synonym groups per language. Each key is the canonical token; the values are customer words that
	 * map to it. Languages without an entry match literal words only.
	 *
	 * @var array<string, array<string, string[]>>
	 */
	private static $synonyms = array(
		'en' => array(
			'clean'     => array( 'clean', 'cleaning', 'cleaner', 'cleaners', 'tidy', 'wash', 'washing', 'housekeeping', 'maid' ),
			'repair'    => array( 'repair', 'fix', 'fixing', 'broken', 'mend', 'handyman' ),
			'plumb'     => array( 'plumb', 'plumbing', 'plumber', 'leak', 'leaking', 'pipe', 'drain', 'blocked', 'tap' ),
			'electric'  => array( 'electric', 'electrical', 'electrician', 'wiring', 'socket', 'power' ),
			'paint'     => array( 'paint', 'painting', 'painter', 'decorate', 'decorating' ),
			'garden'    => array( 'garden', 'gardening', 'gardener', 'lawn', 'mow', 'mowing', 'landscape', 'landscaping', 'hedge' ),
			'consult'   => array( 'consult', 'consultation', 'advice', 'assessment', 'inspection', 'inspect', 'quote', 'estimate' ),
		),
	);

	/**
	 * Language part of a locale, lower-cased. "en_AU" and "en-GB" both give "en". Unknown input gives "".
	 *
	 * @param mixed $locale Locale string.
	 * @return string
	 */
	public static function language( $locale ) {
		if ( ! is_string( $locale ) || ! preg_match( '/\A([a-z]{2,3})(?:[_-][A-Za-z0-9]{2,8})*\z/D', $locale, $m ) ) {
			return '';
		}
		return strtolower( $m[1] );
	}

	/**
	 * Normalise customer text: strip tags and control characters, lower-case, drop punctuation,
	 * cap length. The result is only ever used as a list of tokens.
	 *
	 * @param mixed $raw Raw text.
	 * @return string
	 */
	public static function normalise_query( $raw ) {
		if ( ! is_string( $raw ) ) { return ''; }
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : preg_replace( '/<[^>]*>?|\x00/', '', $raw );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, self::MAX_QUERY_CHARS, 'UTF-8' ) : substr( (string) $text, 0, self::MAX_QUERY_CHARS );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', (string) $text );
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Canonical tokens for a piece of text in one language.
	 *
	 * @param string $text   Normalised text.
	 * @param string $locale Locale used to pick synonym groups.
	 * @return string[] Unique canonical tokens, in order of appearance.
	 */
	public static function tokens( $text, $locale ) {
		$text = self::normalise_query( $text );
		if ( '' === $text ) { return array(); }
		$lang   = self::language( $locale );
		$groups = isset( self::$synonyms[ $lang ] ) ? self::$synonyms[ $lang ] : array();
		$lookup = array();
		foreach ( $groups as $canonical => $words ) {
			foreach ( $words as $word ) { $lookup[ $word ] = $canonical; }
		}

		$out = array();
		foreach ( explode( ' ', $text ) as $word ) {
			if ( '' === $word || in_array( $word, self::$stopwords, true ) ) { continue; }
			$plain = ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) ? substr( $word, 0, -1 ) : $word;
			$token = isset( $lookup[ $word ] ) ? $lookup[ $word ] : ( isset( $lookup[ $plain ] ) ? $lookup[ $plain ] : $plain );
			if ( ! in_array( $token, $out, true ) ) { $out[] = $token; }
		}
		return $out;
	}

	/**
	 * Services of one calendar's form structure that a customer may be offered: `fapp` fields only,
	 * disabled or archived services removed. The catalogue itself is read-only here.
	 *
	 * @param int    $calendar_id Calendar (form) ID, used to build the identifier.
	 * @param string $structure   Form structure JSON, as stored on the calendar.
	 * @return array<int, array{id: string, calendar_id: int, field: int, index: int, name: string, duration: string, price: string}>
	 */
	public static function visible_services( $calendar_id, $structure ) {
		$calendar_id = (int) $calendar_id;
		if ( $calendar_id < 1 ) { return array(); }
		$data = json_decode( (string) $structure, true );
		if ( ! is_array( $data ) || ! isset( $data[0] ) || ! is_array( $data[0] ) ) { return array(); }

		$out = array();
		foreach ( $data[0] as $field_pos => $field ) {
			if ( ! is_array( $field ) || ( $field['ftype'] ?? '' ) !== 'fapp' || ! isset( $field['services'] ) || ! is_array( $field['services'] ) ) { continue; }
			foreach ( $field['services'] as $index => $service ) {
				if ( ! is_array( $service ) || ! is_string( $service['name'] ?? null ) || '' === trim( $service['name'] ) ) { continue; }
				if ( ! is_int( $index ) || $index < 0 || $index > self::MAX_SERVICE_IDX ) { continue; }
				if ( self::is_switched_off( $service ) ) { continue; }
				$out[] = array(
					'id'          => $calendar_id . ':' . (int) $field_pos . ':' . $index,
					'calendar_id' => $calendar_id,
					'field'       => (int) $field_pos,
					'index'       => $index,
					'name'        => (string) $service['name'],
					'duration'    => isset( $service['duration'] ) && is_scalar( $service['duration'] ) ? (string) $service['duration'] : '',
					'price'       => isset( $service['price'] ) && is_scalar( $service['price'] ) ? (string) $service['price'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Disabled, hidden or archived services are never offered to customers.
	 *
	 * @param array $service Service row from the form structure.
	 * @return bool
	 */
	private static function is_switched_off( array $service ) {
		foreach ( array( 'disabled', 'hidden', 'archived', 'inactive' ) as $flag ) {
			if ( isset( $service[ $flag ] ) && in_array( $service[ $flag ], array( true, 1, '1', 'true', 'yes', 'on' ), true ) ) { return true; }
		}
		return false;
	}

	/**
	 * Match customer wording to visible services.
	 *
	 * @param string $query    Customer text.
	 * @param array  $services Output of visible_services() for one calendar, already tenant-verified.
	 * @param string $locale   Locale of the customer's request, for example "en_AU".
	 * @return array{status: string, matches: array, clarify: bool}
	 */
	public static function match( $query, array $services, $locale ) {
		$query_tokens = self::tokens( $query, $locale );
		if ( empty( $query_tokens ) ) {
			return array( 'status' => self::STATUS_EMPTY, 'matches' => array(), 'clarify' => true );
		}

		$scored = array();
		foreach ( $services as $service ) {
			if ( ! is_array( $service ) || ! isset( $service['id'], $service['name'] ) ) { continue; }
			$service_tokens = self::tokens( (string) $service['name'], $locale );
			$shared         = count( array_intersect( $query_tokens, $service_tokens ) );
			if ( $shared < 1 ) { continue; }
			$scored[] = array( 'score' => $shared, 'service' => $service );
		}

		if ( empty( $scored ) ) {
			return array( 'status' => self::STATUS_NONE, 'matches' => array(), 'clarify' => true );
		}

		usort( $scored, static function ( $a, $b ) {
			if ( $a['score'] !== $b['score'] ) { return $b['score'] <=> $a['score']; }
			return strcmp( (string) $a['service']['name'], (string) $b['service']['name'] );
		} );

		$matches = array();
		foreach ( array_slice( $scored, 0, self::MAX_RESULTS ) as $row ) {
			$matches[] = self::public_row( $row['service'] );
		}

		if ( 1 === count( $scored ) ) {
			return array( 'status' => self::STATUS_SINGLE, 'matches' => $matches, 'clarify' => false );
		}
		return array( 'status' => self::STATUS_MULTIPLE, 'matches' => $matches, 'clarify' => true );
	}

	/**
	 * Only the fields a customer needs to choose, as stored. Nothing is computed or invented.
	 *
	 * @param array $service Visible service row.
	 * @return array{id: string, name: string, duration: string, price: string}
	 */
	public static function public_row( array $service ) {
		return array(
			'id'       => (string) $service['id'],
			'name'     => (string) $service['name'],
			'duration' => (string) ( $service['duration'] ?? '' ),
			'price'    => (string) ( $service['price'] ?? '' ),
		);
	}

	/**
	 * Check that a selected identifier names a visible service of the current company's calendar.
	 * Returns the service row, or null for anything else (malformed, other tenant, disabled, unknown).
	 *
	 * @param mixed $selected   Identifier posted by the customer, "calendar:field:index".
	 * @param int   $company_id Company the request is scoped to (already verified by the caller).
	 * @param array $services_by_calendar Map of calendar ID => visible_services() output for that calendar.
	 * @param callable $owner_of_calendar Returns the owning company ID for a calendar ID.
	 * @return array|null
	 */
	public static function validate_selection( $selected, $company_id, array $services_by_calendar, callable $owner_of_calendar ) {
		if ( ! is_string( $selected ) || ! preg_match( '/\A([1-9][0-9]{0,9}):([0-9]{1,4}):([0-9]{1,4})\z/D', $selected, $m ) ) {
			return null;
		}
		$calendar_id = (int) $m[1];
		$company_id  = (int) $company_id;
		if ( $company_id < 1 || (int) $owner_of_calendar( $calendar_id ) !== $company_id ) { return null; }
		if ( ! isset( $services_by_calendar[ $calendar_id ] ) || ! is_array( $services_by_calendar[ $calendar_id ] ) ) { return null; }

		foreach ( $services_by_calendar[ $calendar_id ] as $service ) {
			if ( is_array( $service ) && isset( $service['id'] ) && hash_equals( (string) $service['id'], $selected ) ) {
				return $service;
			}
		}
		return null;
	}
}
