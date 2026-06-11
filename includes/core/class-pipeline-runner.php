<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Executes the content generation pipeline using a chained-job architecture.
 *
 * Orchestrates: Budget check → Collect source data → Analyze patterns →
 * Score ideas → Generate article 1 in-process → Queue articles 2..N as
 * individual WP-Cron events so each gets its own PHP execution time budget.
 *
 * Why chained jobs: Hostinger kills PHP processes at ~120s. A single article
 * (LLM generation + editorial + 2 images) takes ~60-90s, so one-per-process
 * is safe. Running N articles sequentially in one process times out at N≥2.
 *
 * Triggered by: PRAutoBlogger_Executor (daily cron + manual "Generate Now").
 * Dependencies: Core content classes, Article_Worker, WP-Cron for chaining.
 *
 * @see class-executor.php              — Cron/AJAX handlers that call us.
 * @see core/class-article-worker.php   — Single-article generation worker.
 * @see core/class-cost-tracker.php     — Tracks costs throughout the run.
 * @see ARCHITECTURE.md                 — Data flow diagram.
 */
class PRAutoBlogger_Pipeline_Runner {

	/** Option key for the article generation queue. */
	private const QUEUE_KEY = 'prautoblogger_article_queue';

	/** WP-Cron action for chained article generation. */
	public const CRON_ACTION = 'prautoblogger_generate_queued_article';

	/** @var bool Whether to skip duplicate-topic detection. */
	private bool $skip_dedup = false;

	/**
	 * @param bool $skip True to skip deduplication.
	 * @return $this
	 */
	public function set_skip_dedup( bool $skip ): self {
		$this->skip_dedup = $skip;
		return $this;
	}

	/**
	 * Run orchestration and generate the first article in-process.
	 *
	 * Steps: Budget check → Collect → Analyze → Score → Generate article 1.
	 * Remaining articles are queued as chained WP-Cron events.
	 *
	 * Side effects: API calls, DB writes, post creation, cron scheduling.
	 *
	 * @return array{generated: int, published: int, rejected: int, cost: float}
	 * @throws \RuntimeException If budget exceeded.
	 */
	public function run(): array {
		$cost_tracker = new PRAutoBlogger_Cost_Tracker();
		$cost_tracker->set_run_id( wp_generate_uuid4() );

		if ( $cost_tracker->is_budget_exceeded() ) {
			throw new \RuntimeException(
				__( 'Monthly API budget exceeded. Generation halted.', 'prautoblogger' )
			);
		}

		$ideas = $this->orchestrate( $cost_tracker );
		if ( empty( $ideas ) ) {
			return array(
				'generated' => 0,
				'published' => 0,
				'rejected'  => 0,
				'cost'      => 0.0,
			);
		}

		// Generate article 1 in this process — always fits within time limit.
		$first_idea = array_shift( $ideas );
		$worker     = new PRAutoBlogger_Article_Worker( $cost_tracker );
		$result     = $worker->generate( $first_idea );

		// Queue remaining articles as chained cron events.
		if ( ! empty( $ideas ) ) {
			$this->queue_remaining( $ideas, $cost_tracker->get_run_id(), $result );
		} else {
			// Single-article run — amortize research costs now (1 article = full cost).
			PRAutoBlogger_Post_Assembler::amortize_research_costs( $cost_tracker->get_run_id() );
			$this->log_summary( $result );
		}

		return $result;
	}

	/**
	 * Process the next article from the persistent queue.
	 *
	 * Called by WP-Cron for articles 2, 3, …N. Pops one idea, generates
	 * it, updates running totals, and chains the next job or finalizes.
	 *
	 * Side effects: API calls, DB writes, post creation, cron scheduling.
	 */
	public function process_next_queued_article(): void {
		$queue = get_option( self::QUEUE_KEY );
		if ( ! is_array( $queue ) || empty( $queue['ideas'] ) ) {
			$this->finish_queue();
			return;
		}

		$idea_data    = array_shift( $queue['ideas'] );
		$idea         = new PRAutoBlogger_Article_Idea( $idea_data );
		$cost_tracker = new PRAutoBlogger_Cost_Tracker();
		$cost_tracker->set_run_id( $queue['run_id'] );

		if ( $cost_tracker->is_budget_exceeded() ) {
			PRAutoBlogger_Logger::instance()->warning(
				'Budget limit reached. Aborting remaining queued articles.',
				'pipeline'
			);
			$this->finish_queue();
			return;
		}

		// v0.18.0 — a run halted by the per-run cost governor (or failed by
		// the reaper) must not dispatch its remaining queued articles.
		$run_status = PRAutoBlogger_Run_State::get_status( $queue['run_id'] );
		if ( in_array( $run_status, array( 'halted', 'failed' ), true ) ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Run %s is %s. Aborting remaining queued articles.', $queue['run_id'], $run_status ),
				'pipeline'
			);
			$this->finish_queue();
			return;
		}

		// Persist the consumed queue BEFORE generating so the status poller
		// cannot re-schedule a cron event for the same idea. Without this,
		// the DB still holds the old queue during the ~90s generation window,
		// and the poller's orphan-recovery logic duplicates the last article.
		if ( ! empty( $queue['ideas'] ) ) {
			update_option( self::QUEUE_KEY, $queue, false );
		} else {
			delete_option( self::QUEUE_KEY );
		}

		$worker         = new PRAutoBlogger_Article_Worker( $cost_tracker );
		$article_result = $worker->generate( $idea );

		// Merge into running totals.
		$queue['results']['generated'] += $article_result['generated'];
		$queue['results']['published'] += $article_result['published'];
		$queue['results']['rejected']  += $article_result['rejected'];
		$queue['results']['cost']      += $article_result['cost'];

		if ( ! empty( $queue['ideas'] ) ) {
			update_option( self::QUEUE_KEY, $queue, false );
			$this->update_queue_status( $queue );
			$this->schedule_next();
		} else {
			// All articles done — amortize shared research costs across them.
			PRAutoBlogger_Post_Assembler::amortize_research_costs( $queue['run_id'] );
			$this->write_final_status( $queue['results'] );
			delete_option( self::QUEUE_KEY );
			PRAutoBlogger_Generation_Lock::release();
			$this->log_summary( $queue['results'] );
		}
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Collect → Analyze → Score. Returns ranked ideas.
	 *
	 * @param PRAutoBlogger_Cost_Tracker $cost_tracker
	 * @return PRAutoBlogger_Article_Idea[]
	 */
	private function orchestrate( PRAutoBlogger_Cost_Tracker $cost_tracker ): array {
		$target = absint( get_option( 'prautoblogger_daily_article_target', 1 ) );

		$enabled       = json_decode( get_option( 'prautoblogger_enabled_sources', '["reddit"]' ), true );
		$source_labels = is_array( $enabled ) ? implode( ', ', $enabled ) : 'reddit';
		/* translators: %s is a comma-separated list of enabled source names, e.g. "reddit, llm_research". */
		$this->broadcast_stage( sprintf( __( 'Collecting sources from %s…', 'prautoblogger' ), $source_labels ) );
		( new PRAutoBlogger_Source_Collector() )
			->set_cost_tracker( $cost_tracker )
			->collect_from_all_sources();

		$this->broadcast_stage( __( 'Analyzing topics and scoring…', 'prautoblogger' ) );
		$llm      = new PRAutoBlogger_OpenRouter_Provider();
		$analyzer = new PRAutoBlogger_Content_Analyzer( $llm, $cost_tracker );
		$analysis = $analyzer->analyze_recent_data( max( $target * 2, 6 ) );

		$this->broadcast_stage( __( 'Selecting best topic…', 'prautoblogger' ) );
		$scorer = new PRAutoBlogger_Idea_Scorer();
		$scorer->set_skip_dedup( $this->skip_dedup );
		$ideas = $scorer->score_and_rank( $analysis, $target );

		if ( empty( $ideas ) ) {
			PRAutoBlogger_Logger::instance()->info(
				'No viable article ideas found. Skipping generation.',
				'pipeline'
			);
		}

		return $ideas;
	}

	/**
	 * Store remaining ideas and schedule the next chained job.
	 *
	 * @param PRAutoBlogger_Article_Idea[] $ideas  Remaining ideas (2..N).
	 * @param string                       $run_id Pipeline run UUID.
	 * @param array                        $first  Results from article 1.
	 */
	private function queue_remaining( array $ideas, string $run_id, array $first ): void {
		$serialized = array_map(
			static fn( PRAutoBlogger_Article_Idea $i ) => $i->to_array(),
			$ideas
		);

		$queue = array(
			'run_id'  => $run_id,
			'ideas'   => $serialized,
			'results' => $first,
		);

		update_option( self::QUEUE_KEY, $queue, false );
		$this->update_queue_status( $queue );
		$this->schedule_next();

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Article 1 done. Queued %d more as chained jobs.', count( $serialized ) ),
			'pipeline'
		);
	}

	/** Schedule the next chained cron event and fire it immediately. */
	private function schedule_next(): void {
		if ( ! wp_next_scheduled( self::CRON_ACTION ) ) {
			wp_schedule_single_event( time(), self::CRON_ACTION );
		}
		spawn_cron();
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);
	}

	/** Update the frontend status transient with queue progress. */
	private function update_queue_status( array $queue ): void {
		$done  = $queue['results']['generated'] + $queue['results']['rejected'];
		$total = $done + count( $queue['ideas'] );

		$current = get_transient( 'prautoblogger_generation_status' );
		set_transient(
			'prautoblogger_generation_status',
			array(
				'status'       => 'running',
				'stage'        => sprintf( __( 'Generating article %1$d of %2$d…', 'prautoblogger' ), $done + 1, $total ),
				'started'      => is_array( $current ) ? ( $current['started'] ?? time() ) : time(),
				'last_updated' => time(),
			),
			600
		);
	}

	/** Write the final "complete" status transient. */
	private function write_final_status( array $r ): void {
		set_transient(
			'prautoblogger_generation_status',
			array(
				'status'    => 'complete',
				'generated' => $r['generated'],
				'published' => $r['published'],
				'rejected'  => $r['rejected'],
				'cost'      => $r['cost'],
			),
			600
		);
	}

	/** Clean up a stale or empty queue. */
	private function finish_queue(): void {
		$queue = get_option( self::QUEUE_KEY );
		if ( is_array( $queue ) && isset( $queue['results'] ) ) {
			$this->write_final_status( $queue['results'] );
		}
		delete_option( self::QUEUE_KEY );
		PRAutoBlogger_Generation_Lock::release();
	}

	/** Log the pipeline summary. */
	private function log_summary( array $r ): void {
		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Pipeline complete: %d generated, %d published, %d rejected. Cost: $%.4f',
				$r['generated'],
				$r['published'],
				$r['rejected'],
				$r['cost']
			),
			'pipeline'
		);
	}

	/** Update the generation status transient with the current stage. */
	private function broadcast_stage( string $stage ): void {
		$key     = 'prautoblogger_generation_status';
		$current = get_transient( $key );
		if ( is_array( $current ) && 'running' === ( $current['status'] ?? '' ) ) {
			$current['stage']        = $stage;
			$current['last_updated'] = time();
			set_transient( $key, $current, 600 );
		}
	}
}
