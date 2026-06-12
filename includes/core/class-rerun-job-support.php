<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Shared plumbing for re-run cron jobs (v0.20.0, M3).
 *
 * What: The queue/lock/budget/status-transient helpers both
 *       Rerun_Executor handlers share — extracted so the executor stays
 *       under the 300-line cap. The status transient writes use the M1
 *       Generation_Status_Poller shape so the kanban board (and the
 *       dossier's poll) show queued → pickup → result without any new
 *       polling infrastructure; the poller's existing R2/R3 orphan
 *       recovery covers a dead re-run process unchanged.
 * Who triggers it: PRAutoBlogger_Rerun_Executor (handlers) and
 *       PRAutoBlogger_Dossier_Actions (queue()).
 * Dependencies: Generation_Lock, Generation_Status_Poller (constants),
 *               Cost_Tracker, Run_State, Logger, WP-Cron.
 *
 * @see core/class-rerun-executor.php — The job handlers.
 * @see ARCHITECTURE.md #24           — Edit + re-run design.
 */
class PRAutoBlogger_Rerun_Job_Support {

	/**
	 * Schedule a re-run job and fire the cron spawner (non-blocking).
	 *
	 * The uniq argument keeps wp_schedule_single_event()'s identical-
	 * args dedup from swallowing a legitimate second click after a
	 * failed attempt within the 10-minute window.
	 *
	 * Side effects: one single cron event, status transient write,
	 * non-blocking loopback request.
	 *
	 * @param string $action         Cron action name.
	 * @param array  $args           Handler args.
	 * @param string $queued_message Operator-facing "queued" copy.
	 * @return void
	 */
	public static function queue( string $action, array $args, string $queued_message ): void {
		$args[] = (string) microtime( true ); // Uniqueness token.
		wp_schedule_single_event( time(), $action, $args );

		self::broadcast( $queued_message );

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

	/** Raise process limits for a background LLM job (cron context). */
	public static function harden_process(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );
	}

	/**
	 * Acquire the global generation mutex or report the conflict.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	public static function acquire_lock_or_report(): bool {
		if ( PRAutoBlogger_Generation_Lock::acquire() ) {
			return true;
		}
		self::finish_error( __( 'Could not acquire the generation lock — another run is in progress. Re-run again when it completes.', 'prautoblogger' ) );
		return false;
	}

	/**
	 * Whether the monthly budget hard-stop blocks new spend.
	 *
	 * @return bool
	 */
	public static function monthly_budget_exceeded(): bool {
		return ( new PRAutoBlogger_Cost_Tracker() )->is_budget_exceeded();
	}

	/**
	 * Restore a terminal run status after an aborted-but-reopened job.
	 *
	 * @param string      $run_id Run UUID.
	 * @param string|null $status The pre-reopen status (done/failed/halted).
	 * @return void
	 */
	public static function restore_terminal( string $run_id, ?string $status ): void {
		if ( null !== $status && in_array( $status, array( 'done', 'failed', 'halted' ), true ) ) {
			PRAutoBlogger_Run_State::mark_status( $run_id, $status );
		}
	}

	/**
	 * Update the live status transient (board + dossier polling).
	 *
	 * @param string $message Stage message.
	 * @return void
	 */
	public static function broadcast( string $message ): void {
		set_transient(
			PRAutoBlogger_Generation_Status_Poller::STATUS_TRANSIENT,
			array(
				'status'       => 'running',
				'stage'        => $message,
				'started'      => time(),
				'last_updated' => time(),
			),
			PRAutoBlogger_Generation_Status_Poller::STATUS_TTL
		);
	}

	/**
	 * Final transient: success (board-compatible shape).
	 *
	 * @param float $cost Job spend in USD.
	 * @return void
	 */
	public static function finish_complete( float $cost ): void {
		set_transient(
			PRAutoBlogger_Generation_Status_Poller::STATUS_TRANSIENT,
			array(
				'status'    => 'complete',
				'generated' => 0,
				'published' => 0,
				'rejected'  => 0,
				'cost'      => $cost,
				'message'   => __( 'Re-run complete.', 'prautoblogger' ),
			),
			PRAutoBlogger_Generation_Status_Poller::STATUS_TTL
		);
	}

	/**
	 * Final transient: error (board-compatible shape) + log.
	 *
	 * @param string $message Operator-facing error.
	 * @return void
	 */
	public static function finish_error( string $message ): void {
		PRAutoBlogger_Logger::instance()->warning( 'Re-run job: ' . $message, 'rerun' );
		set_transient(
			PRAutoBlogger_Generation_Status_Poller::STATUS_TRANSIENT,
			array(
				'status'  => 'error',
				'message' => $message,
			),
			PRAutoBlogger_Generation_Status_Poller::STATUS_TTL
		);
	}
}
