<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Pipeline-path write operations over `wp_prautoblogger_run_stages`.
 *
 * Extracted from PRAutoBlogger_Run_Stage_State in v0.20.0 so that class
 * stays under the 300-line cap (the M2 class-split pattern): start/done/
 * fail/fail_open_for_item bodies moved here verbatim; Run_Stage_State
 * keeps thin delegating proxies, so every existing call site is
 * unchanged. One M3 behavior delta, isolated in done(): a freshly
 * completed stage clears its `stale` flag (fresh output is never stale),
 * with a self-healing legacy retry for half-migrated schemas that lack
 * the v0.20.0 columns (same retry pattern as Cost_Tracker v0.18.0).
 *
 * Operator-action mutations (restart/mark_stale/demote) live in
 * PRAutoBlogger_Run_Stage_Rerun_State, not here.
 *
 * Triggered by: PRAutoBlogger_Run_Stage_State proxies (pipeline call sites).
 * Dependencies: Run_Stage_State (table probe), Stage_Display_Map (role).
 *
 * @see core/class-run-stage-state.php       — Public API + read methods.
 * @see core/class-run-stage-rerun-state.php — M3 operator-action writes.
 * @see ARCHITECTURE.md #22 / #24            — Substrate + edit/re-run design.
 */
class PRAutoBlogger_Run_Stage_Writes {

	/**
	 * Mark a stage as running. Sticky: done stages are never demoted.
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name (see Stage_Display_Map).
	 * @param string $agent_role Fan-out dimension ('' = resolve from stage map).
	 * @param string $item_key   Article scope ('' for run-level stages).
	 */
	public static function start( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): void {
		if ( '' === $run_id || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return;
		}
		$agent_role = self::resolve_role( $stage, $agent_role );
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				(run_id, stage, agent_role, item_key, status, attempt, started_at, updated_at)
				VALUES (%s, %s, %s, %s, 'running', 1, %s, %s)
				ON DUPLICATE KEY UPDATE
					attempt = IF(status = 'done', attempt, attempt + 1),
					status = IF(status = 'done', 'done', 'running'),
					updated_at = VALUES(updated_at)",
				$run_id,
				$stage,
				$agent_role,
				$item_key,
				$now,
				$now
			)
		);
	}

	/**
	 * Mark a stage done and persist its output for resume-without-recharge.
	 *
	 * v0.20.0: a fresh completion clears the `stale` flag (and never
	 * touches `human_modified` — that flag is sticky run audit). When the
	 * site's schema predates the v0.20.0 columns (cron fired before the
	 * admin_init migration pass), the write self-heals by retrying the
	 * pre-v0.20.0 statement instead of losing the checkpoint.
	 *
	 * @param string      $run_id     Run UUID.
	 * @param string      $stage      Stage name.
	 * @param string      $agent_role Fan-out dimension ('' = resolve from stage map).
	 * @param string      $item_key   Article scope.
	 * @param string|null $output     Stage output snapshot (pruned after R days).
	 * @param float       $cost_usd   Cost attributed to this stage.
	 */
	public static function done( string $run_id, string $stage, string $agent_role = '', string $item_key = '', ?string $output = null, float $cost_usd = 0.0 ): void {
		if ( '' === $run_id || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return;
		}
		$agent_role = self::resolve_role( $stage, $agent_role );
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		$now   = current_time( 'mysql' );
		$meta  = null !== $output ? wp_json_encode( array( 'output' => $output ) ) : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				(run_id, stage, agent_role, item_key, status, attempt, cost_usd, meta_json, stale, started_at, updated_at, finished_at)
				VALUES (%s, %s, %s, %s, 'done', 1, %f, %s, 0, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					status = 'done',
					cost_usd = VALUES(cost_usd),
					meta_json = VALUES(meta_json),
					stale = 0,
					updated_at = VALUES(updated_at),
					finished_at = VALUES(finished_at)",
				$run_id,
				$stage,
				$agent_role,
				$item_key,
				$cost_usd,
				$meta,
				$now,
				$now,
				$now
			)
		);

		// Self-healing on a half-migrated schema (no `stale` column yet):
		// retry with the pre-v0.20.0 statement rather than losing the
		// resume checkpoint. Same pattern as Cost_Tracker (v0.18.0).
		if ( false === $result ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
					(run_id, stage, agent_role, item_key, status, attempt, cost_usd, meta_json, started_at, updated_at, finished_at)
					VALUES (%s, %s, %s, %s, 'done', 1, %f, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE
						status = 'done',
						cost_usd = VALUES(cost_usd),
						meta_json = VALUES(meta_json),
						updated_at = VALUES(updated_at),
						finished_at = VALUES(finished_at)",
					$run_id,
					$stage,
					$agent_role,
					$item_key,
					$cost_usd,
					$meta,
					$now,
					$now,
					$now
				)
			);
		}
	}

	/**
	 * Mark a stage failed (does not demote a done stage).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension ('' = resolve from stage map).
	 * @param string $item_key   Article scope.
	 */
	public static function fail( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): void {
		if ( '' === $run_id || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return;
		}
		$agent_role = self::resolve_role( $stage, $agent_role );
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', updated_at = %s, finished_at = %s
				WHERE run_id = %s AND stage = %s AND agent_role = %s AND item_key = %s AND status != 'done'",
				$now,
				$now,
				$run_id,
				$stage,
				$agent_role,
				$item_key
			)
		);
	}

	/**
	 * Fail every still-open stage of one item. Done stages are untouched.
	 *
	 * @param string $run_id   Run UUID.
	 * @param string $item_key Stage item key ('' = run-level stages).
	 */
	public static function fail_open_for_item( string $run_id, string $item_key ): void {
		if ( '' === $run_id || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return;
		}
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', updated_at = %s, finished_at = %s
				WHERE run_id = %s AND item_key = %s AND status IN ('pending','running')",
				$now,
				$now,
				$run_id,
				$item_key
			)
		);
	}

	/**
	 * Resolve the effective agent role for a stage.
	 *
	 * '' → derive from Stage_Display_Map (unknown stages map to '').
	 * Non-empty → returned as-is (Phase-2 explicit fan-out role).
	 *
	 * Public in v0.20.0 (was private on Run_Stage_State) so the read API
	 * and the rerun classes share one resolution source.
	 *
	 * @param string $stage      Stage name.
	 * @param string $agent_role Caller-supplied role ('' = auto-resolve).
	 * @return string Effective agent role.
	 */
	public static function resolve_role( string $stage, string $agent_role ): string {
		if ( '' !== $agent_role ) {
			return $agent_role;
		}
		return (string) PRAutoBlogger_Stage_Display_Map::default_agent_role( $stage );
	}
}
