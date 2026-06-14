<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Private helpers for PRAutoBlogger_Generation_Checkpoint_Runner (v0.21.0, M4).
 *
 * Extracted to keep class-generation-checkpoint-runner.php under the 300-line
 * cap (CONVENTIONS §1). Contains the three internal-only static methods:
 *   finalize()       — write final transient, mark run done, release lock.
 *   cleanup_queue()  — delete queue + run-id options on abort paths.
 *   fire_cron_now()  — non-blocking loopback to fire cron events immediately.
 *
 * Only PRAutoBlogger_Generation_Checkpoint_Runner should call this class.
 *
 * @see core/class-generation-checkpoint-runner.php — Caller / orchestrator.
 * @see ARCHITECTURE.md ss25                        — Checkpoint design.
 */
class PRAutoBlogger_Generation_Checkpoint_Helpers {

	/** Option key for the checkpoint queue (mirrors Runner constant). */
	private const QUEUE_KEY = 'prautoblogger_article_queue';

	/** Option key for the in-progress run UUID (mirrors Runner constant). */
	private const RUN_ID_KEY = 'prautoblogger_checkpoint_run_id';

	/**
	 * Finalize a completed run: write final transient, mark run done, release lock.
	 *
	 * @param array  $results Generation counters (generated/published/rejected/cost).
	 * @param string $run_id  Run UUID (read from option if blank).
	 * @return void
	 */
	public static function finalize( array $results, string $run_id = '' ): void {
		if ( '' === $run_id ) {
			$run_id = (string) get_option( self::RUN_ID_KEY, '' );
		}
		if ( '' !== $run_id ) {
			PRAutoBlogger_Run_State::mark_status( $run_id, 'done' );
		}
		PRAutoBlogger_Pipeline_Status::write_final( $results );
		PRAutoBlogger_Pipeline_Status::log_summary( $results );
		PRAutoBlogger_Generation_Lock::release();
		delete_option( self::RUN_ID_KEY );
	}

	/** Remove the persistent queue and run-id option on abort paths. */
	public static function cleanup_queue(): void {
		delete_option( self::QUEUE_KEY );
		delete_option( self::RUN_ID_KEY );
	}

	/**
	 * Non-blocking loopback to fire cron events immediately.
	 * Mirrors Pipeline_Runner::schedule_next() exactly.
	 *
	 * @return void
	 */
	public static function fire_cron_now(): void {
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
}
