<?php
/**
 * Smart service finder (issue #23).
 *
 * Maps customer wording onto the existing service catalogue with deterministic rules and
 * synonyms. It only ever returns records that already exist in the catalogue, never invents a
 * service, price or duration, and it never edits the catalogue. The selected identifier is
 * validated again on the server against the visible (non-archived, company-scoped) list.
 *
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-ai-answer-box.php';

final class TZFD_Service_Finder {

	const MAX_MATCH_LIST = 5;

	/** Canonical concept => customer wording that maps to it. Lowercase, normalised tokens. */
	const SYNONYMS = array(
		'clean'     => array( 'clean', 'cleaning', 'cleaner', 'cleans', 'tidy', 'wash', 'washing', 'scrub', 'vacuum', 'mop' ),
		'repair'    => array( 'repair', 'repairs', 'fix', 'fixing', 'mend', 'broken', 'broke', 'faulty', 'damaged' ),
		'install'   => array( 'install', 'installation', 'installing', 'fit', 'fitting', 'setup', 'mount', 'mounting' ),
		'inspect'   => array( 'inspect', 'inspection', 'check', 'checkup', 'assess', 'assessment', 'look', 'survey' ),
		'estimate'  => array( 'estimate', 'quote', 'quotation', 'price', 'pricing', 'cost', 'how much' ),
		'drain'     => array( 'drain', 'drains', 'blocked', 'blockage', 'clog', 'clogged', 'unblock' ),
		'urgent'    => array( 'urgent', 'emergency', 'asap', 'today', 'now', 'immediately' ),
		'garden'    => array( 'garden', 'gardening', 'lawn', 'mow', 'mowing', 'hedge', 'yard' ),
		'pool'      => array( 'pool', 'pools', 'spa' ),
		'water'     => array( 'water', 'leak', 'leaking', 'leaks', 'tap', 'pipe', 'pipes' ),
		'electric'  => array( 'electric', 'electrical', 'electrician', 'power', 'wiring', 'outlet', 'socket' ),
		'deep'      => array( 'deep', 'thorough', 'full', 'detailed' ),
		'regular'   => array( 'regular', 'weekly', 'fortnightly', 'monthly', 'recurring' ),
		'move'      => array( 'move', 'moving', 'bond', 'vacate', 'vacating', 'end' ),
	);

	/** Words with no service meaning. */
	const STOPWORDS = array( 'a', 'an', 'the', 'my', 'our', 'i', 'we', 'need', 'want', 'to', 'for', 'of', 'and', 'or', 'please', 'can', 'you', 'book', 'get', 'some', 'with', 'at', 'in', 'on', 'is', 'it', 'do', 'be', 'me', 'us', 'service', 'appointment' );

	/**
	 * Concept lookup built once: customer token => canonical concept.
	 *
	 * @return array<string, string>
	 */
	private static function concept_map() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array();
		foreach ( self::SYNONYMS as $concept => $words ) {
			foreach ( $words as $word ) {
				$map[ $word ] = $concept;
			}
		}
		return $map;
	}

	/**
	 * Concepts found in a normalised text. Stopwords and unknown words are dropped.
	 *
	 * @param string $text Normalised text.
	 * @return string[] Unique concept tokens. Plain words without a synonym group stay as themselves.
	 */
	public static function concepts( $text ) {
		$text = TZFD_AI_Answer_Box::normalize( $text );
		if ( '' === $text ) {
			return array();
		}
		$map    = self::concept_map();
		$out    = array();
		$tokens = explode( ' ', $text );
		foreach ( $tokens as $token ) {
			if ( '' === $token || in_array( $token, self::STOPWORDS, true ) ) {
				continue;
			}
			$out[] = isset( $map[ $token ] ) ? $map[ $token ] : $token;
		}
		// Multi-word synonyms such as "how much" map as phrases.
		foreach ( array( 'how much' ) as $phrase ) {
			if ( false !== strpos( ' ' . $text . ' ', ' ' . $phrase . ' ' ) ) {
				$out[] = $map[ $phrase ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Visible services only: non-archived records with a positive ID and a non-empty name.
	 *
	 * @param array $services Records from TZFD_Local_Services::list() or a fixture.
	 * @return array<int, array{id: int, name: string, description: string}>
	 */
	public static function visible( array $services ) {
		$out = array();
		foreach ( $services as $service ) {
			if ( ! is_array( $service ) || ! empty( $service['archived'] ) ) {
				continue;
			}
			$id = isset( $service['id'] ) ? filter_var( $service['id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
			$name = isset( $service['name'] ) && is_string( $service['name'] ) ? trim( $service['name'] ) : '';
			if ( false === $id || '' === $name ) {
				continue;
			}
			$out[] = array(
				'id'          => (int) $id,
				'name'        => $name,
				'description' => isset( $service['description'] ) && is_string( $service['description'] ) ? $service['description'] : '',
			);
		}
		return $out;
	}

	/**
	 * Match customer wording to visible services.
	 *
	 * @param string $text     Customer wording (at most 300 characters are considered).
	 * @param array  $services Catalogue records.
	 * @return array{status: string, matches: array<int, array{id: int, name: string}>, question: string}
	 *         status is one of none, one, many or invalid.
	 */
	public static function match( $text, array $services ) {
		if ( ! is_string( $text ) || strlen( $text ) > TZFD_AI_Answer_Box::MAX_QUESTION_LENGTH ) {
			return array( 'status' => 'invalid', 'matches' => array(), 'question' => '' );
		}
		$wanted = self::concepts( $text );
		if ( empty( $wanted ) ) {
			return array( 'status' => 'none', 'matches' => array(), 'question' => '' );
		}

		$scored = array();
		foreach ( self::visible( $services ) as $service ) {
			$name_concepts = self::concepts( $service['name'] );
			$all_concepts  = self::concepts( $service['name'] . ' ' . $service['description'] );
			$score = 0;
			foreach ( $wanted as $concept ) {
				if ( in_array( $concept, $name_concepts, true ) ) {
					$score += 2;
				} elseif ( in_array( $concept, $all_concepts, true ) ) {
					$score += 1;
				}
			}
			if ( $score > 0 ) {
				$scored[] = array( 'id' => $service['id'], 'name' => $service['name'], 'score' => $score );
			}
		}

		if ( empty( $scored ) ) {
			return array( 'status' => 'none', 'matches' => array(), 'question' => '' );
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id'];
			}
		);
		$top = $scored[0]['score'];
		$best = array_values( array_filter( $scored, function ( $row ) use ( $top ) {
			return $row['score'] === $top;
		} ) );

		if ( 1 === count( $best ) && ( count( $scored ) === 1 || $scored[1]['score'] < $top ) ) {
			return array(
				'status'   => 'one',
				'matches'  => array( array( 'id' => $best[0]['id'], 'name' => $best[0]['name'] ) ),
				'question' => '',
			);
		}

		$list = array_slice( $scored, 0, self::MAX_MATCH_LIST );
		return array(
			'status'   => 'many',
			'matches'  => array_map( function ( $row ) {
				return array( 'id' => $row['id'], 'name' => $row['name'] );
			}, $list ),
			'question' => __( 'Which of these services is closest to what you need?', 'titan-zero-trades-front-desk' ),
		);
	}

	/**
	 * Accept a selected service ID only if it is one of the visible services.
	 *
	 * @param mixed $service_id Submitted ID.
	 * @param array $services   Catalogue records.
	 * @return int Valid service ID, or 0.
	 */
	public static function validate_selection( $service_id, array $services ) {
		if ( ! is_int( $service_id ) && ! ( is_string( $service_id ) && preg_match( '/\A[1-9][0-9]{0,18}\z/D', $service_id ) ) ) {
			return 0;
		}
		$wanted = (int) $service_id;
		foreach ( self::visible( $services ) as $service ) {
			if ( $service['id'] === $wanted ) {
				return $wanted;
			}
		}
		return 0;
	}
}
