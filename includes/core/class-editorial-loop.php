<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Authority-tier editorial loop — bounded iterative editor↔writer review.
 *
 * What: Wraps Chief_Editor::review() into a bounded loop (≤
 *       `prautoblogger_editorial_max_rounds`, default 3). Each round the
 *       editor critiques; if not approved, Editorial_Revision_Caller runs
 *       a targeted writer revision; if approved, the loop exits. After max
 *       rounds without approval the article escalates to the Review Queue
 *       (run() returns ''). Every round is recorded to the audit trail (one
 *       run_decisions row per round; run_stages start→done per iteration).
 *       Cost: each LLM call reserves worst-case cost via the existing
 *       provider seam. Article_Worker (single-pass) is untouched — ADDITIVE.
 *
 * Who triggers it: PRAutoBlogger_Authority_Pipeline (P2b.4, NOT live yet).
 * Dependencies: PRAutoBlogger_Chief_Editor, PRAutoBlogger_Editorial_Revision_Caller,
 *       PRAutoBlogger_Audit_Writer, PRAutoBlogger_Run_Stage_State,
 *       PRAutoBlogger_Pipeline_Status, PRAutoBlogger_Logger.
 *
 * @see providers/interface-editorial-loop.php       — Interface this implements.
 * @see core/class-chief-editor.php                  — Single-pass editor (reused).
 * @see core/class-editorial-revision-caller.php     — Writer revision step (extracted).
 * @see core/class-audit-writer.php                  — Round records + decision audit.
 * @see ARCHITECTURE.md                              — Phase 2b editorial stage.
 */
class PRAutoBlogger_Editorial_Loop implements PRAutoBlogger_Editorial_Loop_Interface {

	/** Default maximum rounds when the option is unset. */
	public const DEFAULT_MAX_ROUNDS = 3;

	/** Minimum configurable rounds (safety floor). */
	private const MIN_ROUNDS = 1;

	/** Maximum configurable rounds (sanity cap). */
	private const MAX_ROUNDS = 10;

	/** @var PRAutoBlogger_Chief_Editor Editor agent. */
	private PRAutoBlogger_Chief_Editor $editor;

	/** @var PRAutoBlogger_Editorial_Revision_Caller Writer revision step. */
	private PRAutoBlogger_Editorial_Revision_Caller $revision_caller;

	/** @var bool Whether the last run() call escalated to the Review Queue. */
	private bool $escalated = false;

	/** @var PRAutoBlogger_Editorial_Round[] Round records from the last run(). */
	private array $rounds = array();

	/**
	 * @param PRAutoBlogger_Chief_Editor              $editor          Editor agent.
	 * @param PRAutoBlogger_Editorial_Revision_Caller $revision_caller Writer revision step.
	 */
	public function __construct(
		PRAutoBlogger_Chief_Editor $editor,
		PRAutoBlogger_Editorial_Revision_Caller $revision_caller
	) {
		$this->editor          = $editor;
		$this->revision_caller = $revision_caller;
	}

	/**
	 * Run the bounded editorial loop for one article.
	 *
	 * Each round: editor critiques → writer revises if not approved → repeat.
	 * Returns '' and sets was_escalated()=true after max rounds without approval.
	 *
	 * Side effects: LLM API calls, run_stages + run_decisions DB writes,
	 * status broadcasts, Logger calls.
	 *
	 * @param string                     $run_id       Pipeline run UUID.
	 * @param string                     $item_key     Article-scoped stage key.
	 * @param string                     $content      Initial draft HTML.
	 * @param PRAutoBlogger_Article_Idea $idea         The article idea under review.
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Pipeline cost tracker.
	 * @return string Approved/revised HTML, or '' on Review-Queue escalation.
	 */
	public function run(
		string $run_id,
		string $item_key,
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	): string {
		$this->escalated = false;
		$this->rounds    = array();
		$max_rounds      = $this->resolve_max_rounds();
		$current         = $content;

		for ( $round = 1; $round <= $max_rounds; $round++ ) {
			PRAutoBlogger_Pipeline_Status::broadcast(
				sprintf(
					/* translators: 1: current round, 2: max rounds. */
					__( 'Editorial review — round %1$d of %2$d…', 'prautoblogger' ),
					$round,
					$max_rounds
				)
			);

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Editorial loop: round %d/%d for "%s".', $round, $max_rounds, mb_substr( $idea->get_topic(), 0, 60 ) ),
				'editorial-loop'
			);

			PRAutoBlogger_Run_Stage_State::start( $run_id, 'editorial', 'editor', $item_key );
			$review  = $this->editor->review( $current, $idea );
			$verdict = $review->get_verdict();
			$notes   = $review->get_notes();

			if ( 'approved' === $verdict ) {
				$record         = $this->make_round( $round, $notes, $verdict, '', $review );
				$this->rounds[] = $record;
				$this->record_round( $run_id, $item_key, $record );
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Editorial loop: APPROVED at round %d.', $round ),
					'editorial-loop'
				);
				return $current;
			}

			// Non-approved: get revised content (inline from editor, or a writer call).
			$revised = $this->get_revised_content( $round, $run_id, $item_key, $current, $idea, $review, $cost_tracker );

			$record         = $this->make_round( $round, $notes, $verdict, $revised, $review );
			$this->rounds[] = $record;
			$this->record_round( $run_id, $item_key, $record );
			$current        = '' !== $revised ? $revised : $current;

			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Editorial loop: round %d done (verdict=%s).', $round, $verdict ),
				'editorial-loop'
			);
		}

		// Exhausted all rounds without approval.
		$this->escalated = true;
		PRAutoBlogger_Logger::instance()->warning(
			sprintf( 'Editorial loop: max rounds (%d) exhausted for "%s". Escalating.', $max_rounds, mb_substr( $idea->get_topic(), 0, 80 ) ),
			'editorial-loop'
		);
		$this->record_escalation( $run_id, $item_key, $max_rounds );
		return '';
	}

	/** @return bool Whether the last run() escalated to the Review Queue. */
	public function was_escalated(): bool {
		return $this->escalated;
	}

	/**
	 * Round records from the most recent run() call.
	 *
	 * @return PRAutoBlogger_Editorial_Round[]
	 */
	public function get_rounds(): array {
		return $this->rounds;
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Resolve the configured max rounds, clamped to [MIN_ROUNDS, MAX_ROUNDS].
	 *
	 * @return int
	 */
	private function resolve_max_rounds(): int {
		$n = (int) get_option( 'prautoblogger_editorial_max_rounds', self::DEFAULT_MAX_ROUNDS );
		return max( self::MIN_ROUNDS, min( self::MAX_ROUNDS, $n ) );
	}

	/**
	 * Produce revised content: prefers inline revised_content; falls back to Editorial_Revision_Caller.
	 *
	 * @param int                            $round        Round number.
	 * @param string                         $run_id       Run UUID.
	 * @param string                         $item_key     Stage item key.
	 * @param string                         $current      Current draft HTML.
	 * @param PRAutoBlogger_Article_Idea     $idea         Article idea.
	 * @param PRAutoBlogger_Editorial_Review $review       Editor's review for this round.
	 * @param PRAutoBlogger_Cost_Tracker     $cost_tracker Cost tracker for revision call.
	 * @return string Revised HTML.
	 */
	private function get_revised_content(
		int $round,
		string $run_id,
		string $item_key,
		string $current,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	): string {
		$inline = $review->get_revised_content();
		if ( 'revised' === $review->get_verdict() && null !== $inline && '' !== $inline ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Editorial loop: round %d — using editor inline revision.', $round ),
				'editorial-loop'
			);
			return (string) $inline;
		}

		return $this->revision_caller->call(
			$round,
			$run_id,
			$item_key,
			$current,
			$idea,
			$review->get_notes()
		);
	}

	/**
	 * Construct an Editorial_Round value object for one loop iteration.
	 *
	 * @param int                            $round   1-based round number.
	 * @param string                         $notes   Editor critique notes.
	 * @param string                         $verdict Editor verdict for this round.
	 * @param string                         $revised Writer's revised content ('' for approved).
	 * @param PRAutoBlogger_Editorial_Review $review  Full review for score extraction.
	 * @return PRAutoBlogger_Editorial_Round
	 */
	private function make_round(
		int $round,
		string $notes,
		string $verdict,
		string $revised,
		PRAutoBlogger_Editorial_Review $review
	): PRAutoBlogger_Editorial_Round {
		return new PRAutoBlogger_Editorial_Round(
			$round,
			$notes,
			$verdict,
			$revised,
			$review->get_quality_score(),
			$review->get_seo_score()
		);
	}

	/**
	 * Persist one completed round to run_stages + run_decisions.
	 *
	 * @param string                        $run_id Run UUID.
	 * @param string                        $item_key Stage item key.
	 * @param PRAutoBlogger_Editorial_Round $record   Round to persist.
	 * @return void
	 */
	private function record_round(
		string $run_id,
		string $item_key,
		PRAutoBlogger_Editorial_Round $record
	): void {
		PRAutoBlogger_Run_Stage_State::done(
			$run_id,
			'editorial',
			'editor',
			$item_key,
			(string) wp_json_encode( $record->to_array() )
		);
		PRAutoBlogger_Audit_Writer::record_decision(
			$run_id,
			'editorial',
			$record->get_editor_verdict(),
			sprintf( 'Round %d: %s', $record->get_round_number(), $record->get_editor_notes() )
		);
	}

	/**
	 * Persist the escalation event to run_stages + run_decisions.
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $item_key   Stage item key.
	 * @param int    $max_rounds Max rounds that were exhausted.
	 * @return void
	 */
	private function record_escalation( string $run_id, string $item_key, int $max_rounds ): void {
		PRAutoBlogger_Run_Stage_State::done(
			$run_id,
			'editorial',
			'editor',
			$item_key,
			(string) wp_json_encode(
				array(
					'escalated'  => true,
					'max_rounds' => $max_rounds,
					'rounds'     => array_map( fn( $r ) => $r->to_array(), $this->rounds ),
				)
			)
		);
		PRAutoBlogger_Audit_Writer::record_decision(
			$run_id,
			'editorial',
			'escalated',
			sprintf( 'Max editorial rounds (%d) exhausted. Escalated to Review Queue.', $max_rounds )
		);
	}
}
