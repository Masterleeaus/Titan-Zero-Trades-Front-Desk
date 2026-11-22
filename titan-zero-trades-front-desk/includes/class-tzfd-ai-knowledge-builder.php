<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-free-core.php';

/**
 * Knowledge entries for the FAQ answers (issue #39).
 *
 * - Every save is a draft and appends a revision. Nothing reaches visitors until an administrator publishes it.
 * - Publishing needs validated text and at least one source fact that is marked approved.
 * - Rollback copies an earlier revision into a new draft. History is never deleted or rewritten.
 * - AI output is stored as a suggestion beside the entry. It never changes the entry.
 * - Published content leaves through render(), which escapes all text.
 * - Each company has a version. Publishing or unpublishing bumps it and fires an action so index and answer caches can drop.
 */
final class TZFD_Ai_Knowledge_Builder {
	const OPTION        = 'tz_fd_ai_kb';
	const EDIT_CAP      = 'tz_fd_access';
	const MAX_REVISIONS = 50;
	const CHANGED_ACTION = 'tzfd_ai_knowledge_changed';

	/** Placeholder markers that must never reach a visitor. */
	const PLACEHOLDER_PATTERN = '/\[|\]|\{|\}|\bTBD\b|\bXX\b|lorem ipsum/i';

	public static function can_edit() {
		return (bool) current_user_can( self::EDIT_CAP );
	}

	public static function version( $company_id ) {
		$all = self::load();
		return (int) ( $all[ (int) $company_id ]['version'] ?? 0 );
	}

	/** Creates or edits a draft. Returns array( 'ok', 'id', 'status' ) or array( 'ok' => false, 'error' ). */
	public static function save_draft( $company_id, $entry_id, $question, $answer, array $sources, $now, $actor ) {
		if ( ! self::can_edit() ) {
			return TZFD_Ai_Free_Core::fail( 'forbidden' );
		}
		$cid  = (int) $company_id;
		if ( $cid < 1 ) {
			return TZFD_Ai_Free_Core::fail( 'company_required' );
		}
		$all  = self::load();
		$co   = $all[ $cid ] ?? array( 'version' => 0, 'entries' => array() );
		$id   = is_string( $entry_id ) && '' !== $entry_id ? $entry_id : 'kb-' . bin2hex( random_bytes( 6 ) );
		$entry = $co['entries'][ $id ] ?? array( 'id' => $id, 'status' => 'draft', 'revisions' => array(), 'rev_seq' => 0, 'created' => (int) $now );
		if ( $entry_id && ! isset( $co['entries'][ $id ] ) ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}

		$q = TZFD_Ai_Free_Core::plain_text( $question, 200 );
		$a = TZFD_Ai_Free_Core::plain_text( $answer, 600 );
		$src = array_values( array_unique( array_filter( array_map( 'strval', $sources ) ) ) );
		$changed = ( $q !== ( $entry['question'] ?? '' ) ) || ( $a !== ( $entry['answer'] ?? '' ) );
		$unpublished = false;
		if ( 'published' === $entry['status'] && $changed ) {
			// Edited text is no longer the published text. It stays unpublished until re-published.
			$entry['status'] = 'draft';
			$co['version']   = (int) $co['version'] + 1;
			$unpublished     = true;
		}
		$entry['question'] = $q;
		$entry['answer']   = $a;
		$entry['sources']  = $src;
		$entry['updated']  = (int) $now;
		$co['entries'][ $id ] = self::with_revision( $entry, (int) $actor, (int) $now );
		$all[ $cid ] = $co;
		self::save( $all );
		if ( $unpublished ) {
			do_action( self::CHANGED_ACTION, $cid, $co['version'] );
		}
		return array( 'ok' => true, 'id' => $id, 'status' => $entry['status'] );
	}

	/**
	 * Publishes a draft. $facts is the owner-approved source list: array of array( 'id', 'text', 'approved' ).
	 * Returns array( 'ok' => true, 'version' ) or array( 'ok' => false, 'error', 'reasons' ).
	 */
	public static function publish( $company_id, $entry_id, array $facts, $now, $actor ) {
		if ( ! self::can_edit() ) {
			return TZFD_Ai_Free_Core::fail( 'forbidden' );
		}
		$cid = (int) $company_id;
		$all = self::load();
		$co  = $all[ $cid ] ?? null;
		if ( null === $co || ! isset( $co['entries'][ $entry_id ] ) ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}
		$entry   = $co['entries'][ $entry_id ];
		$reasons = self::publish_problems( $entry, $facts );
		if ( ! empty( $reasons ) ) {
			return array( 'ok' => false, 'error' => 'not_publishable', 'reasons' => $reasons );
		}
		$entry['status'] = 'published';
		$entry['published_at'] = (int) $now;
		$co['version']   = (int) $co['version'] + 1;
		$co['entries'][ $entry_id ] = self::with_revision( $entry, (int) $actor, (int) $now );
		$all[ $cid ] = $co;
		self::save( $all );
		do_action( self::CHANGED_ACTION, $cid, $co['version'] );
		return array( 'ok' => true, 'version' => $co['version'] );
	}

	/** Copies an earlier revision into a new draft. Published text stops being served until it is re-published. */
	public static function rollback( $company_id, $entry_id, $revision_no, $now, $actor ) {
		if ( ! self::can_edit() ) {
			return TZFD_Ai_Free_Core::fail( 'forbidden' );
		}
		$cid = (int) $company_id;
		$all = self::load();
		$co  = $all[ $cid ] ?? null;
		if ( null === $co || ! isset( $co['entries'][ $entry_id ] ) ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}
		$entry = $co['entries'][ $entry_id ];
		$target = null;
		foreach ( $entry['revisions'] as $rev ) {
			if ( (int) $rev['n'] === (int) $revision_no ) { $target = $rev; }
		}
		if ( null === $target ) {
			return TZFD_Ai_Free_Core::fail( 'revision_not_found' );
		}
		$was_published = 'published' === $entry['status'];
		$entry['question'] = $target['question'];
		$entry['answer']   = $target['answer'];
		$entry['sources']  = $target['sources'];
		$entry['status']   = 'draft';
		$entry['updated']  = (int) $now;
		if ( $was_published ) {
			$co['version'] = (int) $co['version'] + 1;
		}
		$co['entries'][ $entry_id ] = self::with_revision( $entry, (int) $actor, (int) $now );
		$all[ $cid ] = $co;
		self::save( $all );
		if ( $was_published ) {
			do_action( self::CHANGED_ACTION, $cid, $co['version'] );
		}
		return array( 'ok' => true, 'status' => 'draft', 'version' => $co['version'] );
	}

	/**
	 * Records that an administrator checked a published answer and its facts still hold (issue #436).
	 * Only the review time changes. Text, status, version and revision history stay as they are.
	 */
	public static function mark_reviewed( $company_id, $entry_id, $now, $actor ) {
		if ( ! self::can_edit() ) {
			return TZFD_Ai_Free_Core::fail( 'forbidden' );
		}
		$cid = (int) $company_id;
		$all = self::load();
		$co  = $all[ $cid ] ?? null;
		if ( null === $co || ! isset( $co['entries'][ $entry_id ] ) ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}
		$entry = $co['entries'][ $entry_id ];
		if ( 'published' !== $entry['status'] ) {
			return TZFD_Ai_Free_Core::fail( 'not_published' );
		}
		$entry['reviewed'] = (int) $now;
		$co['entries'][ $entry_id ] = $entry;
		$all[ $cid ] = $co;
		self::save( $all );
		return array( 'ok' => true, 'reviewed' => (int) $now, 'by' => (int) $actor );
	}

	/** Entries visitors may see. Only published entries. */
	public static function published( $company_id ) {
		$co = self::load()[ (int) $company_id ] ?? array( 'entries' => array() );
		$out = array();
		foreach ( $co['entries'] as $e ) {
			if ( 'published' === $e['status'] ) {
				$out[] = array( 'id' => $e['id'], 'question' => $e['question'], 'answer' => $e['answer'], 'sources' => $e['sources'], 'updated' => (int) ( $e['updated'] ?? 0 ), 'reviewed' => (int) ( $e['reviewed'] ?? 0 ) );
			}
		}
		return $out;
	}

	/** Escaped markup for one published entry. */
	public static function render( array $entry ) {
		return '<div class="tzfd-faq"><p class="tzfd-faq-q">' . esc_html( (string) $entry['question'] ) . '</p>'
			. '<p class="tzfd-faq-a">' . esc_html( (string) $entry['answer'] ) . '</p></div>';
	}

	/**
	 * Conflicts (same question, different answers) and missing details (empty or placeholder text,
	 * or figures with no source) for entries that are not archived.
	 */
	public static function flags( $company_id, array $facts ) {
		$co = self::load()[ (int) $company_id ] ?? array( 'entries' => array() );
		$groups = array();
		$flags  = array();
		$texts  = self::fact_texts( $facts );
		foreach ( $co['entries'] as $e ) {
			$norm = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $e['question'] ) ) );
			$groups[ $norm ][ strtolower( trim( (string) $e['answer'] ) ) ][] = $e['id'];
			if ( '' === trim( (string) $e['answer'] ) || preg_match( self::PLACEHOLDER_PATTERN, (string) $e['answer'] ) ) {
				$flags[] = array( 'id' => $e['id'], 'type' => 'missing_details' );
			}
			if ( ! empty( TZFD_Ai_Free_Core::ungrounded_figures( (string) $e['answer'], self::source_texts( $e['sources'], $texts ) ) ) ) {
				$flags[] = array( 'id' => $e['id'], 'type' => 'ungrounded_figures' );
			}
		}
		foreach ( $groups as $norm => $by_answer ) {
			if ( count( $by_answer ) > 1 ) {
				foreach ( $by_answer as $ids ) {
					foreach ( $ids as $id ) { $flags[] = array( 'id' => $id, 'type' => 'conflict' ); }
				}
			}
		}
		return $flags;
	}

	/** Stores an AI suggestion beside the entry. The entry text does not change. */
	public static function store_suggestion( $company_id, $entry_id, array $facts, $now ) {
		if ( ! self::can_edit() ) {
			return TZFD_Ai_Free_Core::fail( 'forbidden' );
		}
		$cid = (int) $company_id;
		$all = self::load();
		if ( ! isset( $all[ $cid ]['entries'][ $entry_id ] ) ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}
		$e = $all[ $cid ]['entries'][ $entry_id ];
		$call = TZFD_Ai_Free_Core::hosted_call( 'knowledge.improve', array(
			'question' => $e['question'],
			'answer'   => $e['answer'],
			'facts'    => self::approved_only( $facts ),
		), $cid );
		if ( empty( $call['ok'] ) ) {
			return $call;
		}
		$text = TZFD_Ai_Free_Core::plain_text( $call['data']['suggestion'] ?? '', 600 );
		if ( '' === $text ) {
			return TZFD_Ai_Free_Core::fail( 'empty_suggestion' );
		}
		$texts = self::fact_texts( $facts );
		if ( ! empty( TZFD_Ai_Free_Core::ungrounded_figures( $text, array_values( $texts ) ) ) ) {
			return TZFD_Ai_Free_Core::fail( 'ungrounded_suggestion' );
		}
		$e['suggestion'] = array( 'text' => $text, 'at' => (int) $now );
		$all[ $cid ]['entries'][ $entry_id ] = $e;
		self::save( $all );
		return array( 'ok' => true, 'suggestion' => $text );
	}

	/** @return string[] reasons; empty when the entry may be published. */
	public static function publish_problems( array $entry, array $facts ) {
		$reasons = array();
		if ( strlen( (string) $entry['question'] ) < 8 ) { $reasons[] = 'question_too_short'; }
		if ( strlen( (string) $entry['answer'] ) < 8 )   { $reasons[] = 'answer_too_short'; }
		if ( preg_match( self::PLACEHOLDER_PATTERN, (string) $entry['question'] . ' ' . (string) $entry['answer'] ) ) {
			$reasons[] = 'placeholder_text';
		}
		$texts = self::fact_texts( $facts );
		$cited = array();
		foreach ( $entry['sources'] as $sid ) {
			if ( isset( $texts[ $sid ] ) ) { $cited[] = $texts[ $sid ]; }
		}
		if ( empty( $cited ) ) {
			$reasons[] = 'no_approved_source';
		} elseif ( ! empty( TZFD_Ai_Free_Core::ungrounded_figures( (string) $entry['answer'], $cited ) ) ) {
			$reasons[] = 'ungrounded_figures';
		}
		return $reasons;
	}

	/** Facts usable as sources: marked approved, with an id and text. Keyed by id. */
	private static function fact_texts( array $facts ) {
		$out = array();
		foreach ( $facts as $f ) {
			if ( ! is_array( $f ) || empty( $f['approved'] ) || ! isset( $f['id'], $f['text'] ) ) { continue; }
			$out[ (string) $f['id'] ] = (string) $f['text'];
		}
		return $out;
	}

	private static function source_texts( array $sources, array $texts ) {
		$out = array();
		foreach ( $sources as $sid ) {
			if ( isset( $texts[ $sid ] ) ) { $out[] = $texts[ $sid ]; }
		}
		return $out;
	}

	private static function approved_only( array $facts ) {
		$out = array();
		foreach ( $facts as $f ) {
			if ( is_array( $f ) && ! empty( $f['approved'] ) ) {
				$out[] = array( 'id' => (string) $f['id'], 'text' => (string) $f['text'] );
			}
		}
		return $out;
	}

	private static function with_revision( array $entry, $actor, $now ) {
		$entry['rev_seq'] = (int) ( $entry['rev_seq'] ?? 0 ) + 1;
		$entry['revisions'][] = array(
			'n'        => $entry['rev_seq'],
			'question' => $entry['question'],
			'answer'   => $entry['answer'],
			'sources'  => $entry['sources'],
			'status'   => $entry['status'],
			'by'       => $actor,
			'at'       => $now,
		);
		if ( count( $entry['revisions'] ) > self::MAX_REVISIONS ) {
			$entry['revisions'] = array_slice( $entry['revisions'], -self::MAX_REVISIONS );
		}
		return $entry;
	}

	private static function load() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	private static function save( array $all ) {
		update_option( self::OPTION, $all, false );
	}
}
