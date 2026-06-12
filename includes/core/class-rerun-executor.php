<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Queues and executes operator re-runs as chained cron jobs (v0.20.0, M3).
 *
 * What: The chained-cron seam for the dossier's two re-run actions —
 *       NEVER synchronous (CPO hard constraint): the AJAX layer only
 *       schedules; ALL state mutations happen in the cron handler after
 *       re-validating eligibility under the global generation lock.
 *       - Replay job: single-stage re-run of the latest edited input
 *         fork (Stage_Replay). Demotes the one stage row to running
 *         (human_modified=1), stale-marks downstream, executes, restores
 *         a terminal run status.
 *       - Rebuild job ("re-run from here"): demotes the target + all
 *         downstream chain stages to pending, then re-enters
 *         Article_Worker with the idea reconstructed from the stored
 *         seed — upstream done stages are reused (never re-charged),
 *         demoted stages re-run with prompts rebuilt from CURRENT
 *         upstream snapshots, and the publisher refreshes the
 *         unpublished post.
 *       Both jobs: monthly budget check, Run_State::reopen() (ceiling
 *       re-snapshotted from the current setting — a deliberate new-spend
 *       decision), M1 status transient for board/dossier pickup
 *       visibility, lock released in finally. Failures restore/record a
 *       terminal run status so the run-reaper's stuck-running sweep
 *       stays meaningful.
 * Who triggers it: Dossier_Actions (queue_*), WP-Cron (on_* handlers via
 *       class-prautoblogger.php registration).
 * Dependencies: Rerun_Job_Support (lock/budget/transient plumbing),
 *               Run_State, Run_Stage_Rerun_State, Stage_Input_Store,
 *               Stage_Replay, Article_Worker, Rerun_Eligibility.
 *
 * @see admin/class-dossier-actions.php — The AJAX layer (queues via Job_Support).
 * @see core/class-rerun-job-support.php — Shared queue/lock/status plumbing.
 * @see core/class-stage-replay.php     — The governed replay call.
 * @see ARCHITECTURE.md #24             — Edit + re-run design.
 */
class PRAutoBlogger_Rerun_Executor {

	/** Cron action for single-stage replay jobs. */
	public const REPLAY_ACTION = 'prautoblogger_rerun_stage_replay';

	/** Cron action for re-run-from-here rebuild jobs. */
	public const REBUILD_ACTION = 'prautoblogger_rerun_from_stage';

	/**
	 * Cron handler: replay one stage from its latest edited input fork.
	 *
	 * Side effects: run_stages/runs mutations, one governed LLM call,
	 * generation_log row, status transient, lock acquire/release.
	 *
	 * @param string $run_id     Run UUID.
	 * @param int    $post_id    Post the dossier acted on.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Stage row agent role.
	 * @param string $item_key   Stage row item key.
	 * @return void
	 */
	public function on_replay_job( string $run_id, int $post_id, string $stage, string $agent_role, string $item_key ): void {
		PRAutoBlogger_Rerun_Job_Support::harden_process();
		if ( ! PRAutoBlogger_Rerun_Job_Support::acquire_lock_or_report() ) {
			return;
		}

		$previous_status = null;
		try {
			// Re-validate under the lock — state may have changed between
			// click and pickup (e.g. the post was published).
			// require_idle_lock=false: THIS handler owns the lock.
			$check = PRAutoBlogger_Rerun_Eligibility::check_replay( $run_id, $post_id, $stage, $agent_role, $item_key, false );
			if ( ! $check['ok'] ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( $check['reason'] );
				return;
			}
			if ( PRAutoBlogger_Rerun_Job_Support::monthly_budget_exceeded() ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'Monthly API budget exceeded. Re-run not started.', 'prautoblogger' ) );
				return;
			}

			$fork = PRAutoBlogger_Stage_Input_Store::latest_fork( $run_id, $stage, $agent_role, $item_key );
			$body = null !== $fork ? PRAutoBlogger_Stage_Replay::decode_body( (string) $fork['request_json'] ) : null;
			if ( null === $body ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'The saved input could not be decoded as a replayable request.', 'prautoblogger' ) );
				return;
			}

			$previous_status = PRAutoBlogger_Run_State::get_status( $run_id );
			if ( ! PRAutoBlogger_Run_State::reopen( $run_id ) ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'The run could not be reopened for new spend.', 'prautoblogger' ) );
				return;
			}

			if ( ! PRAutoBlogger_Run_Stage_Rerun_State::restart( $run_id, $stage, $agent_role, $item_key, true ) ) {
				PRAutoBlogger_Rerun_Job_Support::restore_terminal( $run_id, $previous_status );
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'The stage row could not be demoted for replay (it may be executing).', 'prautoblogger' ) );
				return;
			}
			// Guardrail 3: explicit, visible downstream invalidation.
			PRAutoBlogger_Run_Stage_Rerun_State::mark_stale( $run_id, $item_key, PRAutoBlogger_Rerun_Eligibility::downstream_of( $stage ) );

			PRAutoBlogger_Rerun_Job_Support::broadcast(
				sprintf(
					/* translators: %s: stage display label. */
					__( 'Re-running %s with the edited input…', 'prautoblogger' ),
					PRAutoBlogger_Stage_Display_Map::label( $stage )
				)
			);

			$result = PRAutoBlogger_Stage_Replay::run( $run_id, $stage, $agent_role, $item_key, $body );

			PRAutoBlogger_Run_State::mark_status( $run_id, 'done' );
			PRAutoBlogger_Rerun_Job_Support::finish_complete( $result['cost'] );
		} catch ( PRAutoBlogger_Cost_Ceiling_Exception $e ) {
			// Governor already halted the run + recorded the overage.
			PRAutoBlogger_Run_Stage_State::fail( $run_id, $stage, $agent_role, $item_key );
			PRAutoBlogger_Rerun_Job_Support::finish_error( $e->getMessage() );
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Run_Stage_State::fail( $run_id, $stage, $agent_role, $item_key );
			PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Stage replay %s for run %s/%s: %s', get_class( $e ), $run_id, $stage, $e->getMessage() ),
				'rerun'
			);
			PRAutoBlogger_Rerun_Job_Support::finish_error( $e->getMessage() );
		} finally {
			PRAutoBlogger_Generation_Lock::release();
		}
	}

	/**
	 * Cron handler: re-run from a stage — demote it + downstream, then
	 * re-enter the worker so demoted stages rebuild from current
	 * upstream snapshots.
	 *
	 * Side effects: run_stages/runs mutations, worker LLM calls
	 * (governed), post refresh, status transient, lock acquire/release.
	 *
	 * @param string $run_id   Run UUID.
	 * @param int    $post_id  Post the dossier acted on.
	 * @param string $stage    Target stage (start of the rebuild set).
	 * @param string $item_key Item key.
	 * @return void
	 */
	public function on_rebuild_job( string $run_id, int $post_id, string $stage, string $item_key ): void {
		PRAutoBlogger_Rerun_Job_Support::harden_process();
		if ( ! PRAutoBlogger_Rerun_Job_Support::acquire_lock_or_report() ) {
			return;
		}

		try {
			// require_idle_lock=false: THIS handler owns the lock.
			$check = PRAutoBlogger_Rerun_Eligibility::check_rebuild( $run_id, $post_id, $stage, $item_key, false );
			if ( ! $check['ok'] ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( $check['reason'] );
				return;
			}
			if ( PRAutoBlogger_Rerun_Job_Support::monthly_budget_exceeded() ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'Monthly API budget exceeded. Re-run not started.', 'prautoblogger' ) );
				return;
			}

			$idea_json = PRAutoBlogger_Stage_Input_Store::get_seed( $run_id, $item_key );
			$idea_data = null !== $idea_json ? json_decode( $idea_json, true ) : null;
			if ( ! is_array( $idea_data ) ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'The stored idea seed could not be decoded.', 'prautoblogger' ) );
				return;
			}
			$idea = new PRAutoBlogger_Article_Idea( $idea_data );

			$job_start = current_time( 'mysql' );
			$demoted   = PRAutoBlogger_Rerun_Eligibility::rebuild_set( $stage );

			if ( ! PRAutoBlogger_Run_State::reopen( $run_id ) ) {
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'The run could not be reopened for new spend.', 'prautoblogger' ) );
				return;
			}
			PRAutoBlogger_Run_Stage_Rerun_State::demote_to_pending( $run_id, $item_key, $demoted );

			PRAutoBlogger_Rerun_Job_Support::broadcast(
				sprintf(
					/* translators: %s: stage display label. */
					__( 'Re-running the pipeline from %s…', 'prautoblogger' ),
					PRAutoBlogger_Stage_Display_Map::label( $stage )
				)
			);

			$tracker = new PRAutoBlogger_Cost_Tracker();
			$tracker->set_run_id( $run_id );
			$result = ( new PRAutoBlogger_Article_Worker( $tracker ) )->generate( $idea );

			// The worker traps its own failures (fail_open_for_item);
			// publish-done is the success signal.
			$succeeded = PRAutoBlogger_Run_Stage_State::is_done( $run_id, 'publish', '', $item_key );

			// Decisions recorded while rebuilding a human-modified item
			// derive from edited content — flag them (guardrail 2).
			if ( PRAutoBlogger_Run_Stage_Rerun_State::item_has_human_modified( $run_id, $item_key ) ) {
				PRAutoBlogger_Audit_Writer::flag_decisions_human_modified( $run_id, $demoted, $job_start );
			}

			PRAutoBlogger_Run_State::mark_status( $run_id, $succeeded ? 'done' : 'failed' );
			if ( $succeeded ) {
				PRAutoBlogger_Rerun_Job_Support::finish_complete( (float) $result['cost'] );
			} else {
				PRAutoBlogger_Rerun_Job_Support::finish_error( __( 'The re-run did not complete — check the Activity Log and the dossier stage states.', 'prautoblogger' ) );
			}
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Rebuild from %s %s for run %s: %s', $stage, get_class( $e ), $run_id, $e->getMessage() ),
				'rerun'
			);
			PRAutoBlogger_Rerun_Job_Support::finish_error( $e->getMessage() );
		} finally {
			PRAutoBlogger_Generation_Lock::release();
		}
	}

}
