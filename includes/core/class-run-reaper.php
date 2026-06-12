<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Daily sweep for stuck runs + audit-payload retention (extends #19).
 *
 * What: Rides the EXISTING `prautoblogger_reap_orphan_research_rows` cron
 *       (no new schedule) alongside the v0.8.1 Research_Reaper. Three jobs:
 *       1. Stage sweep — run_stages rows stuck `running` longer than
 *          2× the stage's expected wall-clock are marked `failed`
 *          (Hostinger kills PHP at ~120s; a stage "running" for 10+
 *          minutes is dead, its process gone).
 *       2. Run sweep — runs rows still open (pending/running) with no
 *          ledger/state activity for 2× the expected run wall-clock are
 *          marked `failed` and their non-done stages reaped to `failed`;
 *          such runs render as "incomplete" via incomplete_runs().
 *       3. Retention — `request_json` on generation_log, stage output
 *          payloads (`meta_json` on run_stages) are NULLed after R days;
 *          payloads, and stage_inputs human-fork bodies (v0.20.0);
 *          R comes from the `prautoblogger_request_json_retention_days`
 *          SETTING (default constant 14; 0 = keep forever) — never a
 *          literal at the point of use.
 * Who triggers it: WP-Cron `prautoblogger_reap_orphan_research_rows`
 *       (daily 03:15, registered in class-prautoblogger.php).
 * Dependencies: Run_State, Run_Stage_State, WordPress $wpdb, Logger.
 *
 * @see core/class-research-reaper.php — The #19 orphan-cost reaper (same cron).
 * @see core/class-run-state.php       — Run rows swept here.
 * @see ARCHITECTURE.md #21            — Design rationale.
 */
class PRAutoBlogger_Run_Reaper {

	/**
	 * Expected per-stage wall-clock seconds (sweep threshold = 2×). An
	 * engineering constant (like Research_Reaper::GRACE_WINDOW_SECONDS),
	 * overridable per stage via the
	 * `prautoblogger_filter_stage_expected_seconds` filter.
	 */
	private const EXPECTED_STAGE_SECONDS = 300;

	/**
	 * Expected whole-run wall-clock seconds (collect + analyze + up to 10
	 * chained articles ≈ 30 min worst case). Sweep threshold = 2×.
	 * Overridable via `prautoblogger_filter_expected_run_seconds`.
	 */
	private const EXPECTED_RUN_SECONDS = 1800;

	/**
	 * Cron action handler — entrypoint. Pure delegate, never throws.
	 *
	 * @return void
	 */
	public static function on_cron(): void {
		self::reap();
	}

	/**
	 * Run all three sweeps. Returns a summary (cron path ignores it).
	 *
	 * Side effects: UPDATEs on run_stages / runs / generation_log; INFO
	 * log lines for swept runs.
	 *
	 * @return array{stages_failed: int, runs_failed: int, payloads_pruned: int}
	 */
	public static function reap(): array {
		$stats = array(
			'stages_failed'   => 0,
			'runs_failed'     => 0,
			'payloads_pruned' => 0,
		);

		try {
			$stats['stages_failed']   = self::sweep_stuck_stages();
			$stats['runs_failed']     = self::sweep_stuck_runs();
			$stats['payloads_pruned'] = self::prune_payloads();
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->warning(
				'Run reaper encountered an error: ' . $e->getMessage(),
				'run-reaper'
			);
		}

		return $stats;
	}

	/**
	 * Runs that died mid-pipeline, for audit surfaces: failed/halted runs
	 * that still have non-done stage rows ("incomplete").
	 *
	 * @param int $limit Max rows (default 20).
	 * @return array<int, array<string, mixed>>
	 */
	public static function incomplete_runs( int $limit = 20 ): array {
		if ( ! PRAutoBlogger_Run_State::is_available() || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return array();
		}
		global $wpdb;
		$runs   = PRAutoBlogger_Run_State::table_name();
		$stages = PRAutoBlogger_Run_Stage_State::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.* FROM {$runs} r
				WHERE r.status IN ('failed','halted')
					AND EXISTS (SELECT 1 FROM {$stages} s WHERE s.run_id = r.run_id AND s.status != 'done')
				ORDER BY r.updated_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Stage sweep: `running` rows older than 2× expected stage wall-clock
	 * become `failed`.
	 *
	 * @return int Rows swept.
	 */
	private static function sweep_stuck_stages(): int {
		if ( ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return 0;
		}
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();

		/**
		 * Filter the expected per-stage wall-clock seconds used by the
		 * stuck-stage sweep (threshold is 2× this value).
		 *
		 * @param int $seconds Expected seconds (default 300).
		 */
		$expected = (int) apply_filters( 'prautoblogger_filter_stage_expected_seconds', self::EXPECTED_STAGE_SECONDS );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( 2 * $expected ) );
		$now      = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$swept = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', updated_at = %s, finished_at = %s
				WHERE status = 'running' AND updated_at < %s",
				$now,
				$now,
				$cutoff
			)
		);
		return is_numeric( $swept ) ? (int) $swept : 0;
	}

	/**
	 * Run sweep: open runs with no activity for 2× expected run wall-clock
	 * are marked `failed`; their pending/running stages are reaped.
	 *
	 * @return int Runs swept.
	 */
	private static function sweep_stuck_runs(): int {
		if ( ! PRAutoBlogger_Run_State::is_available() ) {
			return 0;
		}
		global $wpdb;
		$runs = PRAutoBlogger_Run_State::table_name();

		/**
		 * Filter the expected whole-run wall-clock seconds used by the
		 * stuck-run sweep (threshold is 2× this value).
		 *
		 * @param int $seconds Expected seconds (default 1800).
		 */
		$expected = (int) apply_filters( 'prautoblogger_filter_expected_run_seconds', self::EXPECTED_RUN_SECONDS );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( 2 * $expected ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT run_id FROM {$runs} WHERE status IN ('pending','running') AND updated_at < %s",
				$cutoff
			)
		);
		if ( ! is_array( $stuck ) || empty( $stuck ) ) {
			return 0;
		}

		$swept = 0;
		foreach ( $stuck as $run_id ) {
			$run_id = (string) $run_id;
			if ( ! PRAutoBlogger_Run_State::mark_status( $run_id, 'failed' ) ) {
				continue;
			}
			self::reap_open_stages( $run_id );
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Run %s stuck past 2x expected wall-clock — marked failed (renders as incomplete).', $run_id ),
				'run-reaper'
			);
			++$swept;
		}
		return $swept;
	}

	/**
	 * Mark a failed run's pending/running stage rows as failed.
	 *
	 * @param string $run_id Run UUID.
	 * @return void
	 */
	private static function reap_open_stages( string $run_id ): void {
		if ( ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return;
		}
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', updated_at = %s, finished_at = %s
				WHERE run_id = %s AND status IN ('pending','running')",
				$now,
				$now,
				$run_id
			)
		);
	}

	/**
	 * Retention: NULL heavy payloads older than R days. R is read from the
	 * `prautoblogger_request_json_retention_days` setting at every call
	 * (default = PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS;
	 * 0 or negative = keep forever).
	 *
	 * @return int Rows pruned (both tables combined).
	 */
	private static function prune_payloads(): int {
		$days = (int) get_option(
			'prautoblogger_request_json_retention_days',
			PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS
		);
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$pruned = 0;

		$gen_log = $wpdb->prefix . 'prautoblogger_generation_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$gen_log} SET request_json = NULL WHERE request_json IS NOT NULL AND created_at < %s",
				$cutoff
			)
		);
		$pruned += is_numeric( $result ) ? (int) $result : 0;

		if ( PRAutoBlogger_Run_Stage_State::is_available() ) {
			$stages = PRAutoBlogger_Run_Stage_State::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$stages} SET meta_json = NULL WHERE meta_json IS NOT NULL AND updated_at < %s",
					$cutoff
				)
			);
			$pruned += is_numeric( $result ) ? (int) $result : 0;
		}

		// v0.20.0: human edit-fork bodies ride the same retention (seed
		// rows are kept — structural, ~1KB). A pruned fork can no longer
		// be replayed; the dossier explains that state honestly.
		$pruned += PRAutoBlogger_Stage_Input_Store::prune_human_bodies( $cutoff );

		return $pruned;
	}
}
