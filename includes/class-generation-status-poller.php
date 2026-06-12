<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * AJAX handlers for "Generate Now" + status polling.
 *
 * What: Two AJAX endpoints: kick-off (fire-and-forget cron spawn) and the
 *       status poll that drives the admin UI spinner. Extracted from
 *       PRAutoBlogger_Executor in v0.18.3 to keep each file under 300 lines.
 *
 * R2/R3 resilience (v0.18.3):
 *   - R2(a): on_ajax_generate_now() renews the transient with the
 *     full STATUS_TTL on every stage update via update_generation_stage().
 *   - R2(b): on_ajax_generation_status() detects lock-held-but-transient-gone
 *     when the lock has been held > STATUS_TTL seconds (Hostinger background-
 *     process death); marks the stuck run failed + surfaces a human-readable
 *     "infrastructure timeout" message for the Activity Log.
 *   - R3: on_ajax_generation_status() returns status:running (with
 *     started_at) when the transient is absent but the lock IS held and
 *     within TTL — so the button reflects reality during long runs rather
 *     than silently resetting to idle.
 *
 * Who calls it: PRAutoBlogger hook registration (wp_ajax_prautoblogger_*).
 * Dependencies: PRAutoBlogger_Generation_Lock, PRAutoBlogger_Run_State,
 *               PRAutoBlogger_Run_Context, PRAutoBlogger_Pipeline_Runner,
 *               PRAutoBlogger_Logger.
 *
 * @see class-executor.php         — Cron handlers (daily, manual, queued).
 * @see class-generation-lock.php  — DB mutex (acquire/release/timestamps).
 * @see core/class-pipeline-status.php — Transient broadcast helpers.
 * @see ARCHITECTURE.md #21        — Run lifecycle and reaper design.
 */
class PRAutoBlogger_Generation_Status_Poller {

	/** Transient key for background generation status (shared with Pipeline_Status). */
	public const STATUS_TRANSIENT = 'prautoblogger_generation_status';

	/**
	 * How long (seconds) to keep the status transient readable.
	 * Renewed on every stage transition so a long run doesn't expire it early.
	 */
	public const STATUS_TTL = 600;

	/**
	 * AJAX handler: kick off manual generation as a background cron job.
	 *
	 * Returns immediately so the browser never hits Hostinger's connection
	 * timeout. The frontend polls on_ajax_generation_status() every few
	 * seconds for progress and final results.
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
		// not subject to the connection timeout.
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

	/** AJAX: return generation status for frontend polling. Recovers orphaned runs. */
	public function on_ajax_generation_status(): void {
		check_ajax_referer( 'prautoblogger_generate_now', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'prautoblogger' ) ), 403 );
			return;
		}

		$status = get_transient( self::STATUS_TRANSIENT );

		// R2(b) + R3: transient is gone — check the lock before returning idle.
		if ( ! is_array( $status ) ) {
			$this->handle_missing_transient();
			return;
		}

		if ( 'running' === $status['status'] ) {
			$elapsed = time() - ( $status['started'] ?? 0 );

			// Absolute timeout — give up after STATUS_TTL.
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

	/**
	 * Handle the case where the status transient is absent.
	 *
	 * R3: if lock is HELD and within TTL → return status:running so the
	 *     button reflects reality during long background runs.
	 * R2(b): if lock is held but has been held > STATUS_TTL → the background
	 *     PHP process died without releasing it; mark the run failed and
	 *     surface "infrastructure timeout" to the UI.
	 *
	 * @return void Sends JSON and exits.
	 */
	private function handle_missing_transient(): void {
		$acquired_at = PRAutoBlogger_Generation_Lock::get_acquired_at();

		if ( null === $acquired_at ) {
			// Lock not held — genuinely idle.
			wp_send_json_success( array( 'status' => 'idle' ) );
			return;
		}

		$lock_age = time() - $acquired_at;

		if ( $lock_age > self::STATUS_TTL ) {
			// Lock held longer than STATUS_TTL: background process died.
			// R2(b): mark the stuck run failed + surface a clear error.
			PRAutoBlogger_Logger::instance()->warning(
				sprintf(
					'Status transient absent and lock held for %ds (> %ds TTL) — background process died; marking run failed.',
					$lock_age,
					self::STATUS_TTL
				),
				'pipeline'
			);
			$this->abort_orphaned_run(
				__( 'Generation failed — infrastructure timeout. Check Activity Log for details.', 'prautoblogger' )
			);
			return;
		}

		// R3: lock is fresh (within TTL) but transient is gone — long run in
		// progress (pipeline broadcast hasn't fired yet, or transient was
		// evicted by object-cache pressure). Return running so the button
		// does not silently reset to idle.
		wp_send_json_success(
			array(
				'status'       => 'running',
				'stage'        => __( 'Generation in progress (background). Check Activity Log for details.', 'prautoblogger' ),
				'started'      => $acquired_at,
				'last_updated' => $acquired_at,
			)
		);
	}

	/** Clean up an orphaned generation run and report error. */
	private function abort_orphaned_run( string $message ): void {
		// Mark the stuck run as failed in the audit ledger.
		$queue = get_option( 'prautoblogger_article_queue' );
		if ( is_array( $queue ) && ! empty( $queue['run_id'] ) ) {
			PRAutoBlogger_Run_State::mark_status( (string) $queue['run_id'], 'failed' );
		}
		// Also attempt to close the current active run from Run_Context.
		$run_id = PRAutoBlogger_Run_Context::current_run_id();
		if ( null !== $run_id ) {
			PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' );
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
	 * Update the generation stage text for frontend polling.
	 * Renews the transient TTL at every call so a long run doesn't expire it.
	 *
	 * @param string $stage Human-readable stage description.
	 */
	public function update_generation_stage( string $stage ): void {
		$current = get_transient( self::STATUS_TRANSIENT );
		if ( is_array( $current ) ) {
			$current['stage']        = $stage;
			$current['last_updated'] = time();
			// R2(a): renew the TTL on every stage update so the transient
			// outlives any single LLM call (STATUS_TTL from last activity).
			set_transient( self::STATUS_TRANSIENT, $current, self::STATUS_TTL );
		}
	}
}
