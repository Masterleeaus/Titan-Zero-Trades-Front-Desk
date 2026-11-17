<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-ai-free-core.php';
require_once __DIR__ . '/class-tzfd-ai-knowledge-builder.php';

/**
 * Weekly AI FAQ suggestions (issue #35).
 *
 * - One hosted batch per company per ISO week, at most MAX_BATCH items. The batch record makes reruns no-ops.
 * - Input is local: question themes from gap detection (counts only) and owner-approved facts.
 * - An item is kept only when it cites approved fact ids, reads as plain text, and states no figure
 *   that the cited facts do not contain.
 * - Items are proposals. An administrator approves or rejects each one. Approval goes through the
 *   knowledge builder (#39), so it is validated and versioned, and nothing publishes on its own.
 * - A provider failure leaves the batch in 'retry' and the attempt is counted. After MAX_ATTEMPTS the week is 'failed'.
 */
final class TZFD_Ai_Faq_Suggestions {
	const PROPOSALS    = 'tz_fd_ai_faq_proposals';
	const BATCH_PREFIX = 'tz_fd_ai_faq_batch_';
	const MAX_BATCH    = 5;
	const MAX_ATTEMPTS = 3;
	const MAX_STORED   = 200;

	/**
	 * Runs the week's batch for one company. Returns array( 'ok', 'status', 'added', 'error' ).
	 * Statuses: done, skipped, already_ran, retry, failed, consent_required, retry_exhausted.
	 */
	public static function run_weekly( $company_id, $now, array $themes, array $facts ) {
		$cid = (int) $company_id;
		if ( $cid < 1 ) {
			return self::result( false, 'failed', 0, 'company_required' );
		}
		if ( ! TZFD_Ai_Free_Core::consent_granted() ) {
			return self::result( false, 'consent_required', 0, 'consent_required' );
		}
		$week = TZFD_Ai_Free_Core::week_key( $now );
		$key  = self::batch_key( $cid, $week );
		$state = get_option( $key, array() );
		$state = is_array( $state ) ? $state : array();
		$status = $state['status'] ?? '';
		if ( 'done' === $status || 'skipped' === $status ) {
			return self::result( true, 'already_ran', 0 );
		}
		if ( 'failed' === $status || (int) ( $state['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS ) {
			return self::result( false, 'retry_exhausted', 0, 'retry_exhausted' );
		}

		$approved = self::approved_facts( $facts );
		if ( empty( $approved ) ) {
			return self::finish( $key, $state, 'skipped', 0, 'no_source_facts' );
		}
		$top = self::top_themes( $themes );
		if ( empty( $top ) ) {
			return self::finish( $key, $state, 'skipped', 0, 'no_question_themes' );
		}

		$state['attempts'] = (int) ( $state['attempts'] ?? 0 ) + 1;
		$call = TZFD_Ai_Free_Core::hosted_call( 'faq.weekly', array(
			'themes'    => $top,
			'facts'     => $approved,
			'max_items' => self::MAX_BATCH,
		), $cid );
		if ( empty( $call['ok'] ) ) {
			$state['status']     = (int) $state['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'retry';
			$state['last_error'] = $call['error'];
			update_option( $key, $state, false );
			return self::result( false, $state['status'], 0, $call['error'] );
		}

		$items = self::validate_items( $call['data']['faqs'] ?? array(), $approved );
		$added = self::store( $cid, $items, $week, $now );
		$state['usage'] = $call['usage'];
		unset( $state['last_error'] );
		return self::finish( $key, $state, 'done', $added, '' );
	}

	/** Pending proposals for one company, oldest first. */
	public static function pending( $company_id ) {
		$out = array();
		foreach ( self::load() as $p ) {
			if ( (int) $p['company_id'] === (int) $company_id && 'pending' === $p['status'] ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Approves a proposal into the knowledge builder and publishes it, using the administrator's edits
	 * when given. Rejecting only changes the proposal. Returns the same shape as the knowledge builder.
	 */
	public static function decide( $company_id, $proposal_id, $decision, array $facts, array $edits, $now, $actor ) {
		$cid = (int) $company_id;
		$all = self::load();
		if ( ! isset( $all[ $proposal_id ] ) || (int) $all[ $proposal_id ]['company_id'] !== $cid ) {
			return TZFD_Ai_Free_Core::fail( 'not_found' );
		}
		$p = $all[ $proposal_id ];
		if ( 'pending' !== $p['status'] ) {
			return TZFD_Ai_Free_Core::fail( 'already_decided' );
		}
		if ( 'reject' === $decision ) {
			$p['status'] = 'rejected';
			$all[ $proposal_id ] = $p;
			update_option( self::PROPOSALS, $all, false );
			return array( 'ok' => true, 'status' => 'rejected' );
		}
		if ( 'approve' !== $decision ) {
			return TZFD_Ai_Free_Core::fail( 'decision_not_allowed' );
		}
		$question = isset( $edits['question'] ) ? (string) $edits['question'] : $p['question'];
		$answer   = isset( $edits['answer'] ) ? (string) $edits['answer'] : $p['answer'];
		$saved = TZFD_Ai_Knowledge_Builder::save_draft( $cid, null, $question, $answer, $p['fact_ids'], $now, $actor );
		if ( empty( $saved['ok'] ) ) {
			return $saved;
		}
		$pub = TZFD_Ai_Knowledge_Builder::publish( $cid, $saved['id'], $facts, $now, $actor );
		if ( empty( $pub['ok'] ) ) {
			return $pub; // Proposal stays pending so the administrator can fix the text.
		}
		$p['status']   = 'approved';
		$p['entry_id'] = $saved['id'];
		$all[ $proposal_id ] = $p;
		update_option( self::PROPOSALS, $all, false );
		return array( 'ok' => true, 'status' => 'approved', 'entry_id' => $saved['id'], 'version' => $pub['version'] );
	}

	/** Items the model returned that pass every check. At most MAX_BATCH. */
	public static function validate_items( $raw, array $approved ) {
		$by_id = array();
		foreach ( $approved as $f ) { $by_id[ $f['id'] ] = $f['text']; }
		$out = array();
		$seen = array();
		foreach ( (array) $raw as $row ) {
			if ( count( $out ) >= self::MAX_BATCH ) { break; }
			if ( ! is_array( $row ) ) { continue; }
			$q = TZFD_Ai_Free_Core::plain_text( $row['question'] ?? '', 200 );
			$a = TZFD_Ai_Free_Core::plain_text( $row['answer'] ?? '', 600 );
			$cites = isset( $row['fact_ids'] ) && is_array( $row['fact_ids'] )
				? array_values( array_unique( array_map( 'strval', $row['fact_ids'] ) ) ) : array();
			if ( strlen( $q ) < 8 || strlen( $a ) < 8 || empty( $cites ) ) { continue; }
			$cited_texts = array();
			foreach ( $cites as $cid ) {
				if ( ! isset( $by_id[ $cid ] ) ) { continue 2; }
				$cited_texts[] = $by_id[ $cid ];
			}
			if ( ! empty( TZFD_Ai_Free_Core::ungrounded_figures( $a, $cited_texts ) ) ) { continue; }
			$norm = strtolower( $q );
			if ( isset( $seen[ $norm ] ) ) { continue; }
			$seen[ $norm ] = true;
			$out[] = array( 'question' => $q, 'answer' => $a, 'fact_ids' => $cites );
		}
		return $out;
	}

	/** Approved facts only, with a string id and text. */
	public static function approved_facts( array $facts ) {
		$out = array();
		foreach ( $facts as $f ) {
			if ( ! is_array( $f ) || empty( $f['approved'] ) || ! isset( $f['id'], $f['text'] ) ) { continue; }
			$text = TZFD_Ai_Free_Core::plain_text( $f['text'], 300 );
			if ( '' === $text ) { continue; }
			$out[] = array( 'id' => (string) $f['id'], 'text' => $text );
		}
		return $out;
	}

	/** Top themes by count, counts only. Themes with no count are dropped. */
	private static function top_themes( array $themes ) {
		$rows = array();
		foreach ( $themes as $t ) {
			if ( ! is_array( $t ) || (int) ( $t['count'] ?? 0 ) < 1 || ! isset( $t['label'] ) ) { continue; }
			$rows[] = array( 'label' => TZFD_Ai_Free_Core::plain_text( $t['label'], 120 ), 'count' => (int) $t['count'] );
		}
		usort( $rows, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
		return array_slice( $rows, 0, self::MAX_BATCH );
	}

	private static function store( $company_id, array $items, $week, $now ) {
		$all = self::load();
		$added = 0;
		foreach ( $items as $it ) {
			$id = substr( hash( 'sha256', $company_id . '|' . $week . '|' . strtolower( $it['question'] ) ), 0, 16 );
			if ( isset( $all[ $id ] ) ) { continue; } // idempotent: an identical item is never stored twice
			$all[ $id ] = array(
				'id'         => $id,
				'company_id' => (int) $company_id,
				'week'       => $week,
				'question'   => $it['question'],
				'answer'     => $it['answer'],
				'fact_ids'   => $it['fact_ids'],
				'status'     => 'pending',
				'created'    => (int) $now,
			);
			$added++;
		}
		if ( count( $all ) > self::MAX_STORED ) {
			uasort( $all, static function ( $a, $b ) { return $a['created'] <=> $b['created']; } );
			$all = array_slice( $all, -self::MAX_STORED, null, true );
		}
		update_option( self::PROPOSALS, $all, false );
		return $added;
	}

	private static function finish( $key, array $state, $status, $added, $error ) {
		$state['status'] = $status;
		if ( '' !== $error ) { $state['reason'] = $error; }
		update_option( $key, $state, false );
		return self::result( true, $status, $added, '' !== $error ? $error : null );
	}

	private static function batch_key( $company_id, $week ) {
		return self::BATCH_PREFIX . (int) $company_id . '_' . $week;
	}

	private static function result( $ok, $status, $added, $error = null ) {
		return array( 'ok' => (bool) $ok, 'status' => $status, 'added' => (int) $added, 'error' => $error );
	}

	private static function load() {
		$all = get_option( self::PROPOSALS, array() );
		return is_array( $all ) ? $all : array();
	}
}
