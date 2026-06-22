<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Chained-cron checkpoint runner for in-admin manual article generation (v0.21.0, M4).
 *
 * What: Replaces the monolithic on_manual_generation() path with a two-tick
 *       checkpoint machine so Hostinger's background-PHP-process kill cannot
 *       abort a mid-pipeline run:
 *         Tick 1 (ORCHESTRATE): collect sources -> analyze patterns -> score ideas ->
 *                                persist idea queue to option -> schedule Tick 2.
 *         Tick 2..N (GENERATE): pop one idea from queue -> Article_Worker ->
 *                                update transient -> schedule next tick or finalize.
 *
 * Why separate from Rerun_Executor: Rerun_Executor targets existing run_stages
 * rows (replay/rebuild). This class creates a brand-new run from scratch.
 *
 * SSH retirement (v0.21.0): the former `wp eval 'do_action("prautoblogger_manual_generation")'`
 * workaround fired on_manual_generation() synchronously in one PHP process --
 * bypassing the nonce and running the full pipeline without checkpoints.
 * That path is retired: Executor::on_manual_generation() now delegates here,
 * and `wp prautoblogger generate` queues via kick_off() (see ARCHITECTURE.md ss25).
 *
 * Cost governor: Run_State::ceiling_setting() is applied per-run; a halted run
 * aborts all remaining ticks (same guarantee as Pipeline_Runner's queued-article path).
 *
 * Who triggers it: PRAutoBlogger_Executor::on_manual_generation() (cron),
 *                  PRAutoBlogger_WP_CLI_Commands (wp prautoblogger generate).
 * Dependencies: Pipeline_Runner (orchestration), Run_State (run ledger),
 *               Pipeline_Status (transient), Generation_Lock, Cost_Tracker, Logger.
 *
 * @see includes/class-executor.php                       -- Cron/AJAX entry points.
 * @see core/class-pipeline-runner.php                    -- Orchestration + article worker.
 * @see core/class-run-state.php                          -- Per-run ledger (cost governor).
 * @see core/class-pipeline-status.php                    -- Transient broadcast helpers.
 * @see core/class-generation-checkpoint-helpers.php      -- finalize/cleanup/fire helpers.
 * @see ARCHITECTURE.md ss25                              -- Checkpoint generation path design.
 */
class PRAutoBlogger_Generation_Checkpoint_Runner {

	/** Option key for the checkpoint queue (shared with Pipeline_Runner article queue). */
	private const QUEUE_KEY = 'prautoblogger_article_queue';

	/** Option key for the in-progress run UUID (generation tick). */
	private const RUN_ID_KEY = 'prautoblogger_checkpoint_run_id';

	/** WP-Cron action for the orchestration tick. */
	public const ORCHESTRATE_ACTION = 'prautoblogger_gen_orchestrate';

	/** WP-Cron action for each article-generation tick. */
	public const GENERATE_ACTION = 'prautoblogger_gen_tick';

	/**
	 * When true, on_generate_tick() skips wp-cron rescheduling so the VPS
	 * orchestrator's external loop is the sole driver. Set via set_sync_mode().
	 * Never true on the wp-cron path; reset to false after the sync run.
	 *
	 * @var bool
	 */
	private static bool $sync_mode = false;

	/**
	 * Enable or disable synchronous-driver mode.
	 * Called by WP_CLI_Commands::run_sync() before and after the tick loop.
	 *
	 * @param bool $enabled True to suppress internal cron reschedule.
	 * @return void
	 */
	public static function set_sync_mode( bool $enabled ): void {
		self::$sync_mode = $enabled;
	}

	/**
	 * Schedule the orchestration tick and write the initial status transient.
	 * Called by on_ajax_generate_now() (via Executor) and by the WP-CLI command.
	 * Returns immediately -- ALL pipeline work happens in cron.
	 *
	 * Side effects: cron scheduling, transient write.
	 *
	 * @return void
	 */
	public static function kick_off(): void {
		if ( wp_next_scheduled( self::ORCHESTRATE_ACTION ) ) {
			return; // Idempotent: already queued.
		}
		wp_schedule_single_event( time(), self::ORCHESTRATE_ACTION );
		PRAutoBlogger_Pipeline_Status::write_initial();
		PRAutoBlogger_Generation_Checkpoint_Helpers::fire_cron_now();
	}

	/**
	 * Cron Tick 1: collect -> analyze -> score. Persists ideas, creates run row,
	 * schedules Tick 2 for the first article, then returns.
	 *
	 * Side effects: source/analysis DB writes, run row insert, option write,
	 * status transient update, cron scheduling.
	 *
	 * @return void
	 */
	public static function on_orchestrate_tick(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		if ( ! PRAutoBlogger_Generation_Lock::acquire() ) {
			PRAutoBlogger_Pipeline_Status::write_error(
				__( 'Could not acquire generation lock. Another run may be in progress.', 'prautoblogger' )
			);
			return;
		}

		$run_id = wp_generate_uuid4();
		update_option( self::RUN_ID_KEY, $run_id, false );
		PRAutoBlogger_Run_State::ensure_run( $run_id );

		$cost_tracker = new PRAutoBlogger_Cost_Tracker();
		$cost_tracker->set_run_id( $run_id );

		try {
			if ( $cost_tracker->is_budget_exceeded() ) {
				PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
				PRAutoBlogger_Pipeline_Status::write_error(
					__( 'Monthly API budget exceeded. Generation halted.', 'prautoblogger' )
				);
				PRAutoBlogger_Generation_Lock::release();
				return;
			}

			PRAutoBlogger_Pipeline_Status::broadcast( __( 'Collecting sources…', 'prautoblogger' ) );
			$runner = ( new PRAutoBlogger_Pipeline_Runner() )->set_skip_dedup( true );
			$ideas  = $runner->orchestrate_only( $cost_tracker );

			if ( empty( $ideas ) ) {
				PRAutoBlogger_Run_State::mark_status( $run_id, 'done' );
				PRAutoBlogger_Pipeline_Status::write_final(
					array(
						'generated' => 0,
						'published' => 0,
						'rejected' => 0,
						'cost' => 0.0,
					)
				);
				PRAutoBlogger_Generation_Lock::release();
				delete_option( self::RUN_ID_KEY );
				return;
			}

			$serialized = array_map(
				static fn( PRAutoBlogger_Article_Idea $i ) => $i->to_array(),
				$ideas
			);
			update_option(
				self::QUEUE_KEY,
				array(
					'run_id'  => $run_id,
					'ideas'   => $serialized,
					'results' => array(
						'generated' => 0,
						'published' => 0,
						'rejected' => 0,
						'cost' => 0.0,
					),
				),
				false
			);

			PRAutoBlogger_Pipeline_Status::broadcast(
				sprintf(
					/* translators: %d: number of ideas found */
					__( 'Found %d idea(s) — starting generation…', 'prautoblogger' ),
					count( $ideas )
				)
			);

			// In sync mode the VPS tick loop is the external driver; skip
			// wp-cron reschedule to prevent a competing background tick.
			// on_generate_tick() has the same guard for subsequent reschedules.
			if ( ! self::$sync_mode ) {
				if ( ! wp_next_scheduled( self::GENERATE_ACTION ) ) {
					wp_schedule_single_event( time(), self::GENERATE_ACTION );
				}
				PRAutoBlogger_Generation_Checkpoint_Helpers::fire_cron_now();
			}
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Orchestrate tick %s: %s', get_class( $e ), $e->getMessage() ),
				'pipeline'
			);
			PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
			PRAutoBlogger_Pipeline_Status::write_error( $e->getMessage() );
			PRAutoBlogger_Generation_Lock::release();
			delete_option( self::RUN_ID_KEY );
		}
	}

	/**
	 * Cron Tick 2..N: generate one article, persist totals, reschedule or finalize.
	 *
	 * Side effects: LLM API calls, DB writes, post creation, transient updates,
	 * cron scheduling.
	 *
	 * @return void
	 */
	public static function on_generate_tick(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		$queue = get_option( self::QUEUE_KEY );
		if ( ! is_array( $queue ) || empty( $queue['ideas'] ) ) {
			PRAutoBlogger_Generation_Checkpoint_Helpers::finalize(
				array(
					'generated' => 0,
					'published' => 0,
					'rejected' => 0,
					'cost' => 0.0,
				)
			);
			return;
		}

		$run_id     = (string) ( $queue['run_id'] ?? '' );
		$run_status = PRAutoBlogger_Run_State::get_status( $run_id );
		if ( in_array( $run_status, array( 'halted', 'failed' ), true ) ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Generate tick: run %s is %s -- aborting.', $run_id, $run_status ),
				'pipeline'
			);
			PRAutoBlogger_Generation_Checkpoint_Helpers::cleanup_queue();
			PRAutoBlogger_Generation_Lock::release();
			return;
		}

		$idea_data    = array_shift( $queue['ideas'] );
		$idea         = new PRAutoBlogger_Article_Idea( $idea_data );
		$cost_tracker = new PRAutoBlogger_Cost_Tracker();
		$cost_tracker->set_run_id( $run_id );

		if ( $cost_tracker->is_budget_exceeded() ) {
			PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
			PRAutoBlogger_Generation_Checkpoint_Helpers::cleanup_queue();
			PRAutoBlogger_Generation_Lock::release();
			PRAutoBlogger_Pipeline_Status::write_error(
				__( 'Monthly API budget exceeded. Generation halted.', 'prautoblogger' )
			);
			return;
		}

		// Persist consumed queue BEFORE generating (prevents orphan-recovery duplication).
		if ( ! empty( $queue['ideas'] ) ) {
			update_option( self::QUEUE_KEY, $queue, false );
		} else {
			delete_option( self::QUEUE_KEY );
		}

		try {
			$worker = new PRAutoBlogger_Article_Worker( $cost_tracker );
			$result = $worker->generate( $idea );

			$queue['results']['generated'] += $result['generated'];
			$queue['results']['published'] += $result['published'];
			$queue['results']['rejected']  += $result['rejected'];
			$queue['results']['cost']      += $result['cost'];

			if ( ! empty( $queue['ideas'] ) ) {
				update_option( self::QUEUE_KEY, $queue, false );
				PRAutoBlogger_Pipeline_Status::update_queue_progress( $queue );
				// In sync mode the VPS tick loop is the external driver; skip
				// wp-cron reschedule to prevent a competing background tick.
				if ( ! self::$sync_mode ) {
					if ( ! wp_next_scheduled( self::GENERATE_ACTION ) ) {
						wp_schedule_single_event( time(), self::GENERATE_ACTION );
					}
					PRAutoBlogger_Generation_Checkpoint_Helpers::fire_cron_now();
				}
			} else {
				PRAutoBlogger_Post_Assembler::amortize_research_costs( $run_id );
				PRAutoBlogger_Generation_Checkpoint_Helpers::finalize( $queue['results'], $run_id );
			}
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Generate tick %s for run %s: %s', get_class( $e ), $run_id, $e->getMessage() ),
				'pipeline'
			);
			PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
			PRAutoBlogger_Generation_Checkpoint_Helpers::cleanup_queue();
			PRAutoBlogger_Generation_Lock::release();
			PRAutoBlogger_Pipeline_Status::write_error( $e->getMessage() );
		}
	}
}
