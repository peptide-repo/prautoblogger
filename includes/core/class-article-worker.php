<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Generates a single article: content → editorial review → publish/draft.
 *
 * Extracted from Pipeline_Runner so each chained cron job has a focused,
 * single-responsibility worker. One worker = one article = one PHP process.
 *
 * v0.18.0: per-article stage state (run_stages, item-scoped) makes resume
 * idempotent — an already-published item is skipped outright, a done
 * review is reused from its snapshot, and the writer reuses done LLM
 * stages via Content_Generator::set_run_item(). Editorial verdicts and
 * the sources that fed the article are persisted to the audit tables.
 *
 * Triggered by: Pipeline_Runner (directly for article 1, via cron for 2..N).
 * Dependencies: Content_Generator, Chief_Editor, Publisher, Cost_Tracker,
 *               Run_Stage_State, Audit_Writer.
 *
 * Opik instrumentation: Initializes trace context at start, creates spans
 * for content generation and editorial review. Feature-flag gated (default off).
 *
 * @see core/class-pipeline-runner.php — Orchestrates and dispatches workers.
 * @see core/class-content-generator.php — LLM content generation.
 * @see core/class-chief-editor.php      — Editorial quality gate.
 * @see core/class-publisher.php         — WordPress post creation.
 * @see services/opik/class-opik-trace-context.php — Opik tracing.
 */
class PRAutoBlogger_Article_Worker {

	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	/**
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker Shared cost tracker.
	 */
	public function __construct( PRAutoBlogger_Cost_Tracker $cost_tracker ) {
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Generate one article from an idea.
	 *
	 * Runs the full single-article pipeline: LLM content generation →
	 * editorial review → publish or save as draft.
	 *
	 * Initializes Opik trace context if enabled and tears it down at end.
	 *
	 * Side effects: LLM API calls, database writes, WordPress post creation,
	 * image generation, cost logging, Opik span queuing.
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The scored idea to generate from.
	 *
	 * @return array{generated: int, published: int, rejected: int, cost: float}
	 */
	public function generate( PRAutoBlogger_Article_Idea $idea ): array {
		$run_id = (string) ( $this->cost_tracker->get_run_id() ?? '' );
		$item   = PRAutoBlogger_Run_Stage_State::item_key_for_idea( $idea );

		$result = array(
			'generated' => 0,
			'published' => 0,
			'rejected'  => 0,
			'cost'      => 0.0,
		);

		// Idempotent resume: this item already completed its publish stage
		// in this run — re-dispatching it must not create a second post or
		// charge a second cent.
		if ( '' !== $run_id && PRAutoBlogger_Run_Stage_State::is_done( $run_id, 'publish', '', $item ) ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Skipping "%s" — already completed in run %s (idempotent resume).', $idea->get_topic(), $run_id ),
				'pipeline'
			);
			return $result;
		}

		// Initialize Opik trace context for this article generation.
		$opik = $this->should_trace_with_opik();
		if ( $opik ) {
			PRAutoBlogger_Opik_Trace_Context::current()->init_trace();
		}

		$llm       = new PRAutoBlogger_OpenRouter_Provider();
		$generator = new PRAutoBlogger_Content_Generator( $llm, $this->cost_tracker );
		$generator->set_run_item( '' !== $run_id ? $run_id : null, $item );
		$editor    = new PRAutoBlogger_Chief_Editor( $llm, $this->cost_tracker );
		$publisher = new PRAutoBlogger_Publisher();

		$auto_publish = in_array(
			get_option( 'prautoblogger_auto_publish', '0' ),
			array( '1', 'yes' ),
			true
		);

		try {
			PRAutoBlogger_Pipeline_Status::broadcast( __( 'Generating article draft via AI…', 'prautoblogger' ) );
			$content             = $generator->generate( $idea );
			$result['generated'] = 1;

			PRAutoBlogger_Pipeline_Status::broadcast( __( 'Running editorial pass…', 'prautoblogger' ) );
			$review = $this->review_with_state( $editor, $content, $idea, $run_id, $item );

			PRAutoBlogger_Pipeline_Status::broadcast( __( 'Saving and publishing…', 'prautoblogger' ) );
			PRAutoBlogger_Run_Stage_State::start( $run_id, 'publish', '', $item );
			$this->publish_or_draft(
				$content,
				$idea,
				$review,
				$publisher,
				$auto_publish,
				$result
			);
			PRAutoBlogger_Run_Stage_State::done( $run_id, 'publish', '', $item );

			PRAutoBlogger_Audit_Writer::record_idea_sources( $run_id, $idea );
		} catch ( \Throwable $e ) {
			if ( '' !== $run_id ) {
				PRAutoBlogger_Run_Stage_State::fail_open_for_item( $run_id, $item );
			}
			PRAutoBlogger_Logger::instance()->error(
				sprintf(
					'Article generation %s for "%s": %s',
					get_class( $e ),
					$idea->get_topic(),
					$e->getMessage()
				),
				'pipeline'
			);
		}

		$result['cost'] = $this->cost_tracker->get_current_run_cost();

		// Finalize and queue Opik trace if enabled.
		if ( $opik ) {
			$this->finalize_opik_trace();
		}

		return $result;
	}

	/**
	 * Editorial review with stage state: a done review is reused from its
	 * snapshot (never re-charged); a fresh review is checkpointed and its
	 * verdict recorded to the run_decisions audit table.
	 *
	 * Side effects: run_stages upserts, one run_decisions insert, and —
	 * when not resuming — one LLM call via Chief_Editor.
	 *
	 * @param PRAutoBlogger_Chief_Editor $editor  Editor agent.
	 * @param string                     $content Generated HTML content.
	 * @param PRAutoBlogger_Article_Idea $idea    The idea under review.
	 * @param string                     $run_id  Run UUID ('' outside a run).
	 * @param string                     $item    Stage item key for this idea.
	 * @return PRAutoBlogger_Editorial_Review
	 */
	private function review_with_state(
		PRAutoBlogger_Chief_Editor $editor,
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		string $run_id,
		string $item
	): PRAutoBlogger_Editorial_Review {
		if ( '' !== $run_id ) {
			$snapshot = PRAutoBlogger_Run_Stage_State::get_output( $run_id, 'review', '', $item );
			if ( null !== $snapshot ) {
				$data = json_decode( $snapshot, true );
				if ( is_array( $data ) && isset( $data['verdict'] ) ) {
					PRAutoBlogger_Logger::instance()->info(
						sprintf( 'Reusing completed review for "%s" (idempotent resume).', $idea->get_topic() ),
						'pipeline'
					);
					return new PRAutoBlogger_Editorial_Review( $data );
				}
			}
			PRAutoBlogger_Run_Stage_State::start( $run_id, 'review', '', $item );
		}

		$review = $editor->review( $content, $idea );

		if ( '' !== $run_id ) {
			$snapshot = (string) wp_json_encode(
				array(
					'verdict'         => $review->get_verdict(),
					'notes'           => $review->get_notes(),
					'revised_content' => $review->get_revised_content(),
					'quality_score'   => $review->get_quality_score(),
					'seo_score'       => $review->get_seo_score(),
					'issues'          => $review->get_issues(),
				)
			);
			PRAutoBlogger_Run_Stage_State::done( $run_id, 'review', '', $item, $snapshot );
			PRAutoBlogger_Audit_Writer::record_decision( $run_id, 'review', $review->get_verdict(), $review->get_notes() );
		}

		return $review;
	}

	/**
	 * Publish approved content or save as draft.
	 *
	 * @param string                         $content      Generated HTML content.
	 * @param PRAutoBlogger_Article_Idea     $idea         Source idea.
	 * @param PRAutoBlogger_Editorial_Review $review       Editor verdict.
	 * @param PRAutoBlogger_Publisher         $publisher    Publisher instance.
	 * @param bool                           $auto_publish Whether to auto-publish.
	 * @param array                          &$result      Result counters (by ref).
	 */
	private function publish_or_draft(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		PRAutoBlogger_Publisher $publisher,
		bool $auto_publish,
		array &$result
	): void {
		$verdict = $review->get_verdict();
		$run_id  = $this->cost_tracker->get_run_id();

		if ( 'approved' === $verdict || 'revised' === $verdict ) {
			$final = 'revised' === $verdict
				? ( $review->get_revised_content() ?? $content )
				: $content;

			if ( $auto_publish ) {
				$publisher->publish( $final, $idea, $review, $run_id, $this->cost_tracker );
				++$result['published'];
			} else {
				$publisher->save_as_draft( $final, $idea, $review, $run_id, $this->cost_tracker );
			}
		} else {
			$publisher->save_as_draft( $content, $idea, $review, $run_id, $this->cost_tracker );
			++$result['rejected'];
			PRAutoBlogger_Logger::instance()->info(
				'Article rejected by editor: ' . $idea->get_topic(),
				'pipeline'
			);
		}
	}

	/**
	 * Check if Opik tracing is enabled and has credentials.
	 *
	 * @return bool
	 */
	private function should_trace_with_opik(): bool {
		if ( ! get_option( 'prautoblogger_opik_enabled', false ) ) {
			return false;
		}

		return defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) &&
			defined( 'PRAUTOBLOGGER_OPIK_WORKSPACE' ) &&
			! empty( PRAUTOBLOGGER_OPIK_API_KEY ) &&
			! empty( PRAUTOBLOGGER_OPIK_WORKSPACE );
	}

	/**
	 * Finalize and queue the Opik trace.
	 *
	 * Dispatches trace to queue for async posting.
	 */
	private function finalize_opik_trace(): void {
		$ctx = PRAutoBlogger_Opik_Trace_Context::current();
		$trace = $ctx->finalize_trace();
		$queue = new PRAutoBlogger_Opik_Span_Queue();

		// Queue the trace.
		$queue->enqueue( $trace, 'trace' );

		// Queue all spans.
		foreach ( $ctx->get_spans() as $span ) {
			$queue->enqueue( $span, 'span' );
		}

		// Schedule async dispatch if not already scheduled.
		if ( ! wp_next_scheduled( 'prautoblogger_opik_dispatch' ) ) {
			wp_schedule_single_event( time(), 'prautoblogger_opik_dispatch' );
		}

		// Teardown context for next request.
		PRAutoBlogger_Opik_Trace_Context::teardown();
	}
}
