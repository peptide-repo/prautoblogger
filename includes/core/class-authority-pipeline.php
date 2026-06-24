<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Authority-tier article generation pipeline orchestrator.
 *
 * What: Wires together P2b.1 (Research_Fanout + Research_Judge), P2b.2
 *       (Editorial_Loop), and P2b.3 (Seo_Stage) into the 6-stage Authority
 *       generation path. Gated behind the master flag
 *       `prautoblogger_authority_pipeline_enabled` (default FALSE via
 *       Tier_Router) — never runs in production until an operator opts in.
 *
 *       Stage chain:
 *         1. research  — Research_Fanout::dispatch() → quorum miss → HOLD
 *         2. curate    — Research_Judge::curate() → kept sources
 *         3. draft     — Content_Generator::generate() → creates WP post
 *         4. editorial — Editorial_Loop::run() → escalated → HOLD
 *         5. seo       — Seo_Stage::run() → citation_score
 *         6. gate      — citation_score >= threshold → publish; else HOLD
 *
 *       Cost ceiling: PRAutoBlogger_Cost_Ceiling_Exception → HOLD (never
 *       force-complete). Per-run $0.50 ceiling inherited from Cost_Governor.
 *
 * Who triggers it: PRAutoBlogger_Article_Worker::generate() when tier='authority'.
 * Dependencies: All P2b.1–P2b.3 stage classes, Publisher, Cost_Governor,
 *       Run_Stage_State, Audit_Writer, Image_Pipeline (via Post_Assembler).
 *
 * @see providers/interface-authority-pipeline.php   — Interface.
 * @see core/class-authority-pipeline-stages.php     — Stage helpers (split for 300-line rule).
 * @see core/class-tier-router.php                   — Routes here on 'authority'.
 * @see core/class-article-worker.php                — Economy fallback path.
 * @see ARCHITECTURE.md                              — Phase 2b data flow.
 */
class PRAutoBlogger_Authority_Pipeline implements PRAutoBlogger_Authority_Pipeline_Interface {

	/** Option key for the citation score publish gate threshold. */
	public const OPTION_THRESHOLD = 'prautoblogger_citation_score_threshold';

	/** Default threshold: 0.0 — gate always passes until calibrated. */
	public const DEFAULT_THRESHOLD = 0.0;

	/** @var PRAutoBlogger_Cost_Tracker Pipeline cost tracker. */
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/** @var PRAutoBlogger_Research_Fanout|null Injected for tests. */
	private ?PRAutoBlogger_Research_Fanout $fanout;

	/** @var PRAutoBlogger_Research_Judge|null Injected for tests. */
	private ?PRAutoBlogger_Research_Judge $judge;

	/** @var PRAutoBlogger_Editorial_Loop|null Injected for tests. */
	private ?PRAutoBlogger_Editorial_Loop $editorial;

	/** @var PRAutoBlogger_Content_Generator|null Injected for tests. */
	private ?PRAutoBlogger_Content_Generator $generator;

	/**
	 * @param PRAutoBlogger_Cost_Tracker           $cost_tracker Pipeline cost tracker.
	 * @param PRAutoBlogger_Research_Fanout|null   $fanout       Optional fan-out override (tests).
	 * @param PRAutoBlogger_Research_Judge|null    $judge        Optional judge override (tests).
	 * @param PRAutoBlogger_Editorial_Loop|null    $editorial    Optional editorial loop override (tests).
	 * @param PRAutoBlogger_Content_Generator|null $generator    Optional generator override (tests).
	 */
	public function __construct(
		PRAutoBlogger_Cost_Tracker $cost_tracker,
		?PRAutoBlogger_Research_Fanout $fanout = null,
		?PRAutoBlogger_Research_Judge $judge = null,
		?PRAutoBlogger_Editorial_Loop $editorial = null,
		?PRAutoBlogger_Content_Generator $generator = null
	) {
		$this->cost_tracker = $cost_tracker;
		$this->fanout       = $fanout;
		$this->judge        = $judge;
		$this->editorial    = $editorial;
		$this->generator    = $generator;
	}

	/**
	 * Run the full Authority-tier pipeline for one article.
	 *
	 * Catches PRAutoBlogger_Cost_Ceiling_Exception at the top level and
	 * converts it to a HOLD (never a force-complete). All other exceptions
	 * propagate to the Article_Worker's outer Throwable catch (which logs
	 * + marks open stages as failed).
	 *
	 * Side effects: LLM API calls, DB writes, WP post creation, imagery
	 * gate post-meta, audit rows (run_stages + run_decisions), Logger calls.
	 *
	 * @param string                     $run_id       Unique run identifier.
	 * @param PRAutoBlogger_Article_Idea $idea         The article idea to process.
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Active cost tracker for this run.
	 * @return array{generated: int, published: int, rejected: int, cost: float, status: string}
	 */
	public function run(
		string $run_id,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	): array {
		$item_key = PRAutoBlogger_Run_Stage_State::item_key_for_idea( $idea );
		$result   = array(
			'generated' => 0,
			'published' => 0,
			'rejected'  => 0,
			'cost'      => 0.0,
			'status'    => 'pending',
		);

		try {
			$result = $this->execute_pipeline( $run_id, $item_key, $idea, $cost_tracker, $result );
		} catch ( PRAutoBlogger_Cost_Ceiling_Exception $e ) {
			// Ceiling breach: hold the article as draft — never force-complete.
			PRAutoBlogger_Authority_Pipeline_Stages::hold_as_draft(
				$run_id,
				$item_key,
				'<p>[Article generation halted by per-run cost ceiling.]</p>',
				$idea,
				$e->getMessage(),
				'halted',
				$run_id
			);
			$result['status'] = 'halted';
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Authority pipeline halted for "%s" (cost ceiling): %s', $idea->get_topic(), $e->getMessage() ),
				'authority-pipeline'
			);
		}

		$result['cost'] = $cost_tracker->get_current_run_cost();
		return $result;
	}

	// ── Private pipeline body ────────────────────────────────────────────

	/**
	 * Execute the 6-stage pipeline, returning the result array.
	 *
	 * Throws PRAutoBlogger_Cost_Ceiling_Exception on budget breach —
	 * caller (run()) converts to HOLD.
	 *
	 * @param string                     $run_id       Run UUID.
	 * @param string                     $item_key     Article-scoped item key.
	 * @param PRAutoBlogger_Article_Idea $idea         The article idea.
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Pipeline cost tracker.
	 * @param array                      $result       Result accumulator.
	 * @return array{generated: int, published: int, rejected: int, cost: float, status: string}
	 * @throws PRAutoBlogger_Cost_Ceiling_Exception When the cost ceiling is breached.
	 */
	private function execute_pipeline(
		string $run_id,
		string $item_key,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Cost_Tracker $cost_tracker,
		array $result
	): array {
		// Stage 1: Research fan-out.
		$fanout   = $this->fanout ?? new PRAutoBlogger_Research_Fanout();
		$fan_results = PRAutoBlogger_Authority_Pipeline_Stages::run_research( $run_id, $item_key, $idea, $cost_tracker, $fanout );
		if ( empty( $fan_results ) ) {
			PRAutoBlogger_Authority_Pipeline_Stages::hold_as_draft(
				$run_id,
				$item_key,
				'',
				$idea,
				'Research quorum not met — holding for re-run.',
				'quorum-miss',
				$run_id
			);
			$result['status'] = 'held-quorum';
			return $result;
		}

		// Stage 2: Curate.
		$judge        = $this->judge ?? new PRAutoBlogger_Research_Judge();
		$kept_sources = PRAutoBlogger_Authority_Pipeline_Stages::run_curate( $run_id, $item_key, $fan_results, $judge );

		// Stage 3: Draft.
		$draft_content    = PRAutoBlogger_Authority_Pipeline_Stages::run_draft( $run_id, $item_key, $idea, $cost_tracker, $this->generator );
		$result['generated'] = 1;

		// Stage 4: Editorial loop.
		$llm           = new PRAutoBlogger_OpenRouter_Provider();
		$chief_editor  = new PRAutoBlogger_Chief_Editor( $llm, $cost_tracker );
		$rev_caller    = new PRAutoBlogger_Editorial_Revision_Caller( $llm, $cost_tracker );
		$editorial     = $this->editorial ?? new PRAutoBlogger_Editorial_Loop( $chief_editor, $rev_caller );
		$editorial_out = PRAutoBlogger_Authority_Pipeline_Stages::run_editorial(
			$run_id,
			$item_key,
			$draft_content,
			$idea,
			$cost_tracker,
			$editorial
		);
		$final_content = $editorial_out['content'];
		$escalated     = $editorial_out['escalated'];

		if ( $escalated ) {
			PRAutoBlogger_Authority_Pipeline_Stages::hold_as_draft(
				$run_id,
				$item_key,
				$final_content,
				$idea,
				'Editorial loop exhausted max rounds — held for human review.',
				'escalated',
				$run_id
			);
			$result['status'] = 'held-escalated';
			return $result;
		}

		// Stage 3b: Save draft (needed for SEO stage and citation gate).
		$publisher = new PRAutoBlogger_Publisher();
		$stub_review = new PRAutoBlogger_Editorial_Review(
			array(
				'verdict'         => 'approved',
				'notes'           => 'Authority pipeline editorial approved.',
				'revised_content' => null,
				'quality_score'   => 0.85,
				'seo_score'       => 0.80,
				'issues'          => array(),
			)
		);
		$auto_publish = in_array( get_option( 'prautoblogger_auto_publish', '0' ), array( '1', 'yes' ), true );
		$post_id = $publisher->save_as_draft( $final_content, $idea, $stub_review, $run_id, null );
		PRAutoBlogger_Run_Stage_State::start( $run_id, 'publish', (string) PRAutoBlogger_Stage_Display_Map::default_agent_role( 'publish' ), $item_key );

		// Stage 5: SEO.
		$citation_score = PRAutoBlogger_Authority_Pipeline_Stages::run_seo( $run_id, $item_key, $post_id, $kept_sources, array() );

		// Stage 6: Publish gate.
		$threshold = (float) get_option( self::OPTION_THRESHOLD, self::DEFAULT_THRESHOLD );
		if ( $citation_score >= $threshold && ! $escalated ) {
			if ( $auto_publish ) {
				wp_publish_post( $post_id );
				// Image pipeline for published articles.
				PRAutoBlogger_Post_Assembler::attach_generated_images(
					$post_id,
					$idea,
					array(
						'post_title'   => $idea->get_suggested_title(),
						'post_content' => $final_content,
					),
					$cost_tracker
				);
				$result['published'] = 1;
			}
			PRAutoBlogger_Run_Stage_State::done( $run_id, 'publish', '', $item_key );
			PRAutoBlogger_Audit_Writer::record_decision(
				$run_id,
				'publish-gate',
				'approved',
				sprintf( 'citation_score=%.4f >= threshold=%.4f', $citation_score, $threshold ),
				$citation_score
			);
			$result['status'] = 'published';
		} else {
			// Citation gate: hold (imagery suppressed).
			update_post_meta( $post_id, '_prautoblogger_imagery_suppressed', '1' );
			PRAutoBlogger_Run_Stage_State::done( $run_id, 'publish', '', $item_key );
			PRAutoBlogger_Audit_Writer::record_decision(
				$run_id,
				'publish-gate',
				'held',
				sprintf( 'citation_score=%.4f < threshold=%.4f — held as draft.', $citation_score, $threshold ),
				$citation_score
			);
			$result['status'] = 'held-citation';
		}

		return $result;
	}
}
