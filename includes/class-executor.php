<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Cron handlers for content generation and metrics collection.
 *
 * What: Daily generation, manual generation, queued-article processing,
 *       and metrics collection cron handlers. AJAX generation handlers
 *       live in PRAutoBlogger_Generation_Status_Poller (v0.18.3 split).
 * Triggered by: PRAutoBlogger hook registration.
 * Dependencies: Pipeline_Runner, Metrics_Collector, Generation_Lock, Logger.
 *
 * @see class-generation-status-poller.php — "Generate Now" AJAX + status poll.
 * @see class-prautoblogger.php            — Hook wiring.
 * @see core/class-pipeline-runner.php     — Actual generation pipeline.
 * @see class-generation-lock.php          — DB mutex.
 */
class PRAutoBlogger_Executor {

	/** @var PRAutoBlogger_OpenRouter_Model_Registry|null Lazy-loaded singleton. */
	private ?PRAutoBlogger_OpenRouter_Model_Registry $model_registry = null;

	/** @var PRAutoBlogger_Generation_Status_Poller|null Lazy-loaded poller. */
	private ?PRAutoBlogger_Generation_Status_Poller $poller = null;

	/**
	 * Handle the daily generation cron event.
	 *
	 * Uses an atomic DB mutex. If the pipeline queues additional articles,
	 * they fire as chained cron events and the lock is released by the
	 * last article job — not here.
	 *
	 * Side effects: API calls, database writes, WordPress post creation.
	 */
	public function on_daily_generation(): void {
		do_action( 'prautoblogger_refresh_model_registry', false );

		if ( ! PRAutoBlogger_Generation_Lock::acquire() ) {
			PRAutoBlogger_Logger::instance()->info( 'Daily generation skipped: already running (lock held).', 'scheduler' );
			return;
		}

		try {
			( new PRAutoBlogger_Pipeline_Runner() )->run();

			// Release lock only if no articles were queued for chained processing.
			if ( ! get_option( 'prautoblogger_article_queue' ) ) {
				PRAutoBlogger_Generation_Lock::release();
			}
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Daily generation %s: %s', get_class( $e ), $e->getMessage() ),
				'scheduler'
			);
			$this->mark_current_run_failed();
			PRAutoBlogger_Generation_Lock::release();
		}
	}

	/**
	 * Handle the metrics collection cron event.
	 * Side effects: API calls to GA4, database writes to ab_content_scores.
	 */
	public function on_collect_metrics(): void {
		try {
			( new PRAutoBlogger_Metrics_Collector() )->collect_all();
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Metrics collection %s: %s', get_class( $e ), $e->getMessage() ),
				'metrics'
			);
		}
	}

	/**
	 * AJAX handler: kick off manual generation as a background cron job.
	 * Delegates to PRAutoBlogger_Generation_Status_Poller.
	 *
	 * Side effects: schedules a WP-Cron event, writes a status transient.
	 */
	public function on_ajax_generate_now(): void {
		$this->poller()->on_ajax_generate_now();
	}

	/**
	 * Cron handler: kick off the chained-cron orchestration tick (v0.21.0, M4).
	 *
	 * Delegates to Generation_Checkpoint_Runner::on_orchestrate_tick() which
	 * runs Tick 1 (collect -> analyze -> score), then schedules Tick 2..N for
	 * article generation. This replaces the former monolithic pipeline run and
	 * retires the SSH-only `do_action("prautoblogger_manual_generation")` workaround
	 * (see ARCHITECTURE.md ss25).
	 *
	 * Lock acquisition and status transient writes are fully owned by the
	 * checkpoint runner; this method is a thin routing wrapper.
	 *
	 * @return void
	 */
	public function on_manual_generation(): void {
		PRAutoBlogger_Generation_Checkpoint_Runner::on_orchestrate_tick();
	}

	/** Cron handler: process next queued article. Chained by Pipeline_Runner. */
	public function on_process_article_queue(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		try {
			( new PRAutoBlogger_Pipeline_Runner() )->process_next_queued_article();
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Queued article generation %s: %s', get_class( $e ), $e->getMessage() ),
				'pipeline'
			);
			// Clean up on catastrophic failure.
			delete_option( 'prautoblogger_article_queue' );
			PRAutoBlogger_Generation_Lock::release();
			set_transient(
				PRAutoBlogger_Generation_Status_Poller::STATUS_TRANSIENT,
				array(
					'status'  => 'error',
					'message' => $e->getMessage(),
				),
				PRAutoBlogger_Generation_Status_Poller::STATUS_TTL
			);
		}
	}

	/** AJAX: return generation status for frontend polling. Delegates to poller. */
	public function on_ajax_generation_status(): void {
		$this->poller()->on_ajax_generation_status();
	}

	/**
	 * Lazy-load the generation status poller.
	 *
	 * @return PRAutoBlogger_Generation_Status_Poller
	 */
	private function poller(): PRAutoBlogger_Generation_Status_Poller {
		if ( null === $this->poller ) {
			$this->poller = new PRAutoBlogger_Generation_Status_Poller();
		}
		return $this->poller;
	}

	/**
	 * Mark this process's run failed after a fatal pipeline error
	 * (no-op outside a run; a governor-halted run stays halted — final
	 * states are sticky).
	 *
	 * @return void
	 */
	private function mark_current_run_failed(): void {
		$run_id = PRAutoBlogger_Run_Context::current_run_id();
		if ( null !== $run_id ) {
			PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
		}
	}

	/**
	 * Lazy-load the OpenRouter model registry singleton.
	 * Config injected here — registry class has no knowledge of PRAUTOBLOGGER_* constants.
	 *
	 * @return PRAutoBlogger_OpenRouter_Model_Registry
	 */
	public function get_model_registry(): PRAutoBlogger_OpenRouter_Model_Registry {
		if ( null === $this->model_registry ) {
			$this->model_registry = new PRAutoBlogger_OpenRouter_Model_Registry(
				'prautoblogger_openrouter_model_registry',
				'prautoblogger_openrouter_model_registry_cache'
			);
		}
		return $this->model_registry;
	}
}
