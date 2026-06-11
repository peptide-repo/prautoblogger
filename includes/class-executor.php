<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Cron/AJAX handlers for content generation, metrics collection, model registry.
 *
 * Triggered by: PRAutoBlogger hook registration.
 * Dependencies: Pipeline_Runner, Metrics_Collector, Generation_Lock, Logger.
 *
 * @see class-prautoblogger.php       — Hook wiring.
 * @see core/class-pipeline-runner.php — Actual generation pipeline.
 * @see class-generation-lock.php      — DB mutex.
 */
class PRAutoBlogger_Executor {

	/** @var PRAutoBlogger_OpenRouter_Model_Registry|null Lazy-loaded singleton. */
	private ?PRAutoBlogger_OpenRouter_Model_Registry $model_registry = null;

	/** Transient key for background generation status. */
	private const STATUS_TRANSIENT = 'prautoblogger_generation_status';

	/** How long to keep the result available for polling (seconds). */
	private const STATUS_TTL = 600;

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
	 *
	 * Returns immediately so the browser never hits Hostinger's 120-second
	 * connection timeout. The frontend polls on_ajax_generation_status()
	 * every few seconds to get progress and final results.
	 *
	 * Side effects: schedules a WP-Cron event, writes a status transient.
	 */
	public function on_ajax_generate_now(): void {
		check_ajax_referer( 'prautoblogger_generate_now', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
			return;
		}

		$force = isset( $_POST['force'] ) && '1' === $_POST['force'];
		if ( $force ) {
			PRAutoBlogger_Generation_Lock::release();
			delete_transient( self::STATUS_TRANSIENT );
		}

		// Check if a run is already in progress.
		$current = get_transient( self::STATUS_TRANSIENT );
		if ( is_array( $current ) && 'running' === ( $current['status'] ?? '' ) ) {
			wp_send_json_success(
				array(
					'background' => true,
					'message'    => __( 'Generation already in progress.', 'prautoblogger' ),
				)
			);
			return;
		}

		// Write initial "running" status for the frontend to poll.
		set_transient(
			self::STATUS_TRANSIENT,
			array(
				'status'       => 'running',
				'stage'        => __( 'Starting generation...', 'prautoblogger' ),
				'started'      => time(),
				'last_updated' => time(),
			),
			self::STATUS_TTL
		);

		// Schedule immediate one-shot cron event. WordPress will fire this
		// on the next page load (or via wp-cron.php) in a separate PHP process
		// that is not subject to the 120-second connection timeout.
		if ( ! wp_next_scheduled( 'prautoblogger_manual_generation' ) ) {
			wp_schedule_single_event( time(), 'prautoblogger_manual_generation' );
		}

		// Spawn the cron immediately via a non-blocking loopback request
		// so we don't depend on the next visitor to trigger it.
		spawn_cron();

		// Direct non-blocking loopback to wp-cron.php — ensures the event
		// fires even if DISABLE_WP_CRON is true or spawn_cron() no-ops.
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);

		wp_send_json_success(
			array(
				'background' => true,
				'message'    => __( 'Generation started in background. Polling for status...', 'prautoblogger' ),
			)
		);
	}

	/**
	 * Cron handler: runs pipeline orchestration + first article.
	 *
	 * If the pipeline queues additional articles (2..N), they will fire as
	 * chained cron events — each in its own PHP process. The lock is released
	 * by the pipeline when the last article completes, not here.
	 */
	public function on_manual_generation(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		if ( ! PRAutoBlogger_Generation_Lock::acquire() ) {
			set_transient(
				self::STATUS_TRANSIENT,
				array(
					'status'  => 'error',
					'message' => __( 'Could not acquire generation lock. Another run may be in progress.', 'prautoblogger' ),
				),
				self::STATUS_TTL
			);
			return;
		}

		try {
			$this->update_generation_stage( __( 'Collecting sources from Reddit...', 'prautoblogger' ) );

			$runner  = ( new PRAutoBlogger_Pipeline_Runner() )->set_skip_dedup( true );
			$results = $runner->run();

			// If no articles were queued (target=1 or 0 ideas), finalize here.
			// When articles ARE queued, the pipeline handles status + lock release.
			if ( ! get_option( 'prautoblogger_article_queue' ) ) {
				set_transient(
					self::STATUS_TRANSIENT,
					array(
						'status'    => 'complete',
						'generated' => $results['generated'],
						'published' => $results['published'],
						'rejected'  => $results['rejected'],
						'cost'      => $results['cost'],
					),
					self::STATUS_TTL
				);
				PRAutoBlogger_Generation_Lock::release();
			}
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Manual generation %s: %s', get_class( $e ), $e->getMessage() ),
				'pipeline'
			);
			set_transient(
				self::STATUS_TRANSIENT,
				array(
					'status'  => 'error',
					'message' => $e->getMessage(),
				),
				self::STATUS_TTL
			);
			$this->mark_current_run_failed();
			PRAutoBlogger_Generation_Lock::release();
		}
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
				self::STATUS_TRANSIENT,
				array(
					'status'  => 'error',
					'message' => $e->getMessage(),
				),
				self::STATUS_TTL
			);
		}
	}

	/** AJAX: return generation status for frontend polling. Recovers orphaned runs. */
	public function on_ajax_generation_status(): void {
		check_ajax_referer( 'prautoblogger_generate_now', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
			return;
		}

		$status = get_transient( self::STATUS_TRANSIENT );
		if ( ! is_array( $status ) ) {
			wp_send_json_success( array( 'status' => 'idle' ) );
			return;
		}

		if ( 'running' === $status['status'] ) {
			$elapsed = time() - ( $status['started'] ?? 0 );

			// Absolute timeout — give up after 10 minutes.
			if ( $elapsed > self::STATUS_TTL ) {
				$this->abort_orphaned_run( __( 'Generation timed out. Check the Activity Log.', 'prautoblogger' ) );
				return;
			}

			// After 90s, check if a queued job died and needs re-scheduling.
			if ( $elapsed > 90 && get_option( 'prautoblogger_article_queue' ) ) {
				if ( ! wp_next_scheduled( PRAutoBlogger_Pipeline_Runner::CRON_ACTION ) ) {
					wp_schedule_single_event( time(), PRAutoBlogger_Pipeline_Runner::CRON_ACTION );
					spawn_cron();
				}
			}

			// Stall = 5 min since last stage update (not since start).
			$last_activity = $status['last_updated'] ?? $status['started'] ?? 0;
			$idle_seconds  = time() - $last_activity;
			if ( $idle_seconds > 300 ) {
				$this->abort_orphaned_run( __( 'Generation stalled. Check Activity Log.', 'prautoblogger' ) );
				return;
			}
		}

		wp_send_json_success( $status );
	}

	/** Clean up an orphaned generation run and report error. */
	private function abort_orphaned_run( string $message ): void {
		// v0.18.0: the dead run renders as failed/"incomplete" in audit
		// queries instead of silently lingering as 'running'.
		$queue = get_option( 'prautoblogger_article_queue' );
		if ( is_array( $queue ) && ! empty( $queue['run_id'] ) ) {
			PRAutoBlogger_Run_State::mark_status( (string) $queue['run_id'], 'failed' );
		}
		delete_transient( self::STATUS_TRANSIENT );
		delete_option( 'prautoblogger_article_queue' );
		PRAutoBlogger_Generation_Lock::release();
		wp_send_json_success(
			array(
				'status'  => 'error',
				'message' => $message,
			)
		);
	}

	/**
	 * Helper: update the generation stage for frontend polling.
	 *
	 * @param string $stage Human-readable stage description.
	 */
	private function update_generation_stage( string $stage ): void {
		$current = get_transient( self::STATUS_TRANSIENT );
		if ( is_array( $current ) ) {
			$current['stage']        = $stage;
			$current['last_updated'] = time();
			set_transient( self::STATUS_TRANSIENT, $current, self::STATUS_TTL );
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
