<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Operator-action state mutations for edit + re-run (v0.20.0, M3).
 *
 * What: The ONLY paths that may demote a 'done' run_stages row — and all
 *       of them are deliberate operator actions dispatched from the
 *       dossier (never reachable from pipeline resume code):
 *       - restart(): done/failed/halted/pending → running for a single-
 *         stage replay; bumps attempt, clears stale, sets human_modified
 *         when the replay uses an edited input fork (CPO guardrail 2).
 *       - mark_stale(): flags downstream 'done' stages as stale after an
 *         upstream edit enters execution. Stale is a COLUMN, not a
 *         status: the stage stays 'done', so resume-without-recharge
 *         logic can never silently re-run it (CPO guardrail 3 holds by
 *         construction). Only Run_Stage_Writes::done() clears it.
 *       - demote_to_pending(): re-run-from-here demotion; the worker
 *         re-entry then rebuilds those stages from current upstream
 *         snapshots. Audit columns (attempt, human_modified) survive;
 *         old meta_json survives until overwritten by the fresh done().
 *       Every method no-ops when the run_stages table is missing.
 * Who triggers it: PRAutoBlogger_Rerun_Executor (cron handlers, after
 *       re-validating eligibility under the generation lock).
 * Dependencies: Run_Stage_State (probe/table), $wpdb.
 *
 * @see core/class-rerun-executor.php      — Sole caller.
 * @see core/class-run-stage-writes.php    — Pipeline-path writes (done clears stale).
 * @see core/class-rerun-eligibility.php   — Policy gates before any mutation.
 * @see ARCHITECTURE.md #24                — Edit + re-run design.
 */
class PRAutoBlogger_Run_Stage_Rerun_State {

	/**
	 * Demote one stage row to 'running' for an operator replay.
	 *
	 * Allowed from done/failed/halted/pending — never from 'running'
	 * (a concurrently executing stage cannot be restarted). Bumps the
	 * attempt counter, clears `stale` (the row is being refreshed) and
	 * sets `human_modified` when the replay executes an edited fork.
	 * `human_modified` is never cleared by any code path — it is sticky
	 * audit state for the life of the run.
	 *
	 * Side effects: one UPDATE on run_stages.
	 *
	 * @param string $run_id         Run UUID.
	 * @param string $stage          Stage name.
	 * @param string $agent_role     Agent role (resolved by the caller from the actual row).
	 * @param string $item_key       Item key.
	 * @param bool   $human_modified Whether an edited input fork drives this replay.
	 * @return bool Whether a row was demoted.
	 */
	public static function restart( string $run_id, string $stage, string $agent_role, string $item_key, bool $human_modified ): bool {
		if ( '' === $run_id || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return false;
		}
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'running', attempt = attempt + 1, stale = 0,
					human_modified = IF(%d = 1, 1, human_modified),
					updated_at = %s, finished_at = NULL
				WHERE run_id = %s AND stage = %s AND agent_role = %s AND item_key = %s
					AND status IN ('done','failed','halted','pending')",
				$human_modified ? 1 : 0,
				$now,
				$run_id,
				$stage,
				$agent_role,
				$item_key
			)
		);
		return 1 === (int) $updated;
	}

	/**
	 * Flag a set of 'done' stages as stale (explicit downstream
	 * invalidation after an upstream edit/re-run — CPO guardrail 3).
	 *
	 * Nothing is demoted and nothing auto-re-runs: the rows stay 'done'
	 * and visible; the operator triggers each subsequent stage (or
	 * re-run-from-here) deliberately.
	 *
	 * @param string        $run_id   Run UUID.
	 * @param string        $item_key Item key.
	 * @param array<string> $stages   Stage names to flag.
	 * @return int Rows flagged.
	 */
	public static function mark_stale( string $run_id, string $item_key, array $stages ): int {
		if ( '' === $run_id || empty( $stages ) || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return 0;
		}
		global $wpdb;
		$table        = PRAutoBlogger_Run_Stage_State::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $stages ), '%s' ) );
		$params       = array_merge( array( current_time( 'mysql' ), $run_id, $item_key ), array_values( $stages ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed %s list.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET stale = 1, updated_at = %s
				WHERE run_id = %s AND item_key = %s AND status = 'done' AND stage IN ({$placeholders})",
				$params
			)
		);
		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Demote a set of stages to 'pending' for re-run-from-here.
	 *
	 * The pipeline's resume machinery treats pending stages as not-done
	 * and re-executes them with prompts REBUILT from current upstream
	 * snapshots — that is how an upstream edit propagates downstream.
	 * Rows keep attempt/human_modified (audit) and their old meta_json
	 * until the fresh completion overwrites it; `stale` stays 1 until
	 * Run_Stage_Writes::done() clears it on fresh output. Never touches
	 * a 'running' row.
	 *
	 * @param string        $run_id   Run UUID.
	 * @param string        $item_key Item key.
	 * @param array<string> $stages   Stage names to demote.
	 * @return int Rows demoted.
	 */
	public static function demote_to_pending( string $run_id, string $item_key, array $stages ): int {
		if ( '' === $run_id || empty( $stages ) || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return 0;
		}
		global $wpdb;
		$table        = PRAutoBlogger_Run_Stage_State::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $stages ), '%s' ) );
		$params       = array_merge( array( current_time( 'mysql' ), $run_id, $item_key ), array_values( $stages ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed %s list.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', stale = 1, updated_at = %s, finished_at = NULL
				WHERE run_id = %s AND item_key = %s AND status != 'running' AND stage IN ({$placeholders})",
				$params
			)
		);
		return is_numeric( $updated ) ? (int) $updated : 0;
	}
	/**
	 * Whether any stage row of this item carries the human_modified flag
	 * (drives derived-decision flagging during rebuilds — guardrail 2).
	 *
	 * @param string $run_id   Run UUID.
	 * @param string $item_key Item key.
	 * @return bool
	 */
	public static function item_has_human_modified( string $run_id, string $item_key ): bool {
		if ( '' === $run_id || ! PRAutoBlogger_Run_Stage_State::is_available() ) {
			return false;
		}
		global $wpdb;
		$table = PRAutoBlogger_Run_Stage_State::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE run_id = %s AND item_key = %s AND human_modified = 1 LIMIT 1",
				$run_id,
				$item_key
			)
		);
		return null !== $found;
	}
}
