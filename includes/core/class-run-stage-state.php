<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Per-run per-stage state machine over `wp_prautoblogger_run_stages`.
 *
 * What: One row per (run_id, stage, agent_role, item_key) holding
 *       pending|running|done|failed|halted plus the stage's output
 *       snapshot (meta_json). This is what makes resume idempotent on the
 *       chained-cron architecture: a `done` stage is NEVER re-run or
 *       re-charged — re-entry reuses its stored output; `done` is sticky
 *       (an upsert cannot demote it). agent_role is the Phase-2 fan-out
 *       dimension (quorum members; the quorum logic itself is Phase 2);
 *       item_key scopes article-level stages because one run_id spans all
 *       N articles of a batch run ('' for run-level stages). Self-healing:
 *       a missing table degrades every method to a no-op/false.
 * Who triggers it: Pipeline_Runner (run-level research/analysis),
 *       Article_Worker (per-article generate/review/publish),
 *       Content_Generator (per-LLM-stage outline/draft/polish),
 *       Run_Reaper (stuck-stage sweep + payload pruning).
 * Dependencies: WordPress $wpdb, wp_json_encode.
 *
 * @see core/class-run-state.php   — Run-level lifecycle + ledger row.
 * @see core/class-run-reaper.php  — Sweeps stages stuck in 'running'.
 * @see ARCHITECTURE.md #21        — Idempotency-key design.
 */
class PRAutoBlogger_Run_Stage_State {

	/** @var bool|null Per-request "run_stages table exists" probe result. */
	private static ?bool $table_ok = null;

	/** Fully-qualified run_stages table name. */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'prautoblogger_run_stages';
	}

	/**
	 * Whether the run_stages table exists and is queryable. Cached per
	 * request; never throws.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( null !== self::$table_ok ) {
			return self::$table_ok;
		}
		global $wpdb;
		if ( null === $wpdb ) {
			return false; // Not cached: $wpdb may appear later in boot.
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		self::$table_ok = ( $found === $table );
		return self::$table_ok;
	}

	/**
	 * Stable hash identifying one article idea within a run (post-creation
	 * idempotency + stage item scoping both key off this).
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The idea.
	 * @return string 16-hex-char hash.
	 */
	public static function idea_hash( PRAutoBlogger_Article_Idea $idea ): string {
		return substr( md5( $idea->get_suggested_title() . '|' . $idea->get_topic() ), 0, 16 );
	}

	/**
	 * Stage item_key for an article idea ('' is reserved for run-level stages).
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The idea.
	 * @return string e.g. 'idea:1a2b3c4d5e6f7a8b'.
	 */
	public static function item_key_for_idea( PRAutoBlogger_Article_Idea $idea ): string {
		return 'idea:' . self::idea_hash( $idea );
	}

	/**
	 * Mark a stage as entered (running). Upsert: first entry inserts the
	 * row; re-entry bumps `attempt` — but a `done` stage is sticky and is
	 * neither demoted nor re-counted.
	 *
	 * Side effects: one INSERT … ON DUPLICATE KEY UPDATE.
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name (see Stage_Display_Map).
	 * @param string $agent_role Fan-out dimension ('' default in Phase 1).
	 * @param string $item_key   Article scope ('' for run-level stages).
	 * @return void
	 */
	public static function start( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
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
	 * Mark a stage done, persisting its output for resume-without-recharge.
	 *
	 * Side effects: one INSERT … ON DUPLICATE KEY UPDATE.
	 *
	 * @param string      $run_id     Run UUID.
	 * @param string      $stage      Stage name.
	 * @param string      $agent_role Fan-out dimension.
	 * @param string      $item_key   Article scope.
	 * @param string|null $output     Stage output to snapshot (pruned after R days).
	 * @param float       $cost_usd   Cost attributed to the stage.
	 * @return void
	 */
	public static function done( string $run_id, string $stage, string $agent_role = '', string $item_key = '', ?string $output = null, float $cost_usd = 0.0 ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );
		$meta  = null !== $output ? wp_json_encode( array( 'output' => $output ) ) : null;
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

	/**
	 * Mark a stage failed (does not demote a `done` stage).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension.
	 * @param string $item_key   Article scope.
	 * @return void
	 */
	public static function fail( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
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
	 * Fetch one stage row.
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension.
	 * @param string $item_key   Article scope.
	 * @return array<string, mixed>|null Row or null.
	 */
	public static function get( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): ?array {
		if ( '' === $run_id || ! self::is_available() ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE run_id = %s AND stage = %s AND agent_role = %s AND item_key = %s",
				$run_id,
				$stage,
				$agent_role,
				$item_key
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a stage already completed (resume entry-point check).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension.
	 * @param string $item_key   Article scope.
	 * @return bool
	 */
	public static function is_done( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): bool {
		$row = self::get( $run_id, $stage, $agent_role, $item_key );
		return null !== $row && 'done' === $row['status'];
	}

	/**
	 * A done stage's stored output (null when absent, not done, or pruned).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension.
	 * @param string $item_key   Article scope.
	 * @return string|null
	 */
	public static function get_output( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): ?string {
		$row = self::get( $run_id, $stage, $agent_role, $item_key );
		if ( null === $row || 'done' !== $row['status'] || empty( $row['meta_json'] ) ) {
			return null;
		}
		$meta = json_decode( (string) $row['meta_json'], true );
		return is_array( $meta ) && isset( $meta['output'] ) ? (string) $meta['output'] : null;
	}

	/**
	 * Fail every still-open (pending/running) stage of one item — used by
	 * the worker's catch-all so a crashed article leaves no stage stuck
	 * in 'running' until the reaper. Done stages are untouched.
	 *
	 * @param string $run_id   Run UUID.
	 * @param string $item_key Stage item key ('' = run-level stages).
	 * @return void
	 */
	public static function fail_open_for_item( string $run_id, string $item_key ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
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

	/** Reset per-request caches (tests). */
	public static function flush_cache(): void {
		self::$table_ok = null;
	}
}
