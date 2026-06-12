<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Per-run per-stage state machine over `wp_prautoblogger_run_stages`.
 *
 * One row per (run_id, stage, agent_role, item_key) holding
 * pending|running|done|failed|halted plus the output snapshot (meta_json).
 * Done stages are sticky and never re-charged on resume. Self-healing:
 * a missing table degrades every method to a no-op/false.
 *
 * v0.18.2: role is resolved from Stage_Display_Map when caller passes ''
 * so start/done/is_done/get_output are always symmetric. Explicit non-empty
 * roles (Phase-2 fan-out) are honoured as-is. get() falls back to role=''
 * on miss for mid-run-upgrade compat with v0.18.1 rows.
 *
 * v0.20.0: write bodies extracted to PRAutoBlogger_Run_Stage_Writes (the
 * M2 split pattern — this class was at the 300-line cap); the public API
 * here is unchanged and delegates. Operator-action mutations (replay
 * restart, stale marking, re-run demotion) live in
 * PRAutoBlogger_Run_Stage_Rerun_State only.
 *
 * @see core/class-run-stage-writes.php      — Extracted write bodies.
 * @see core/class-run-stage-rerun-state.php — M3 operator-action writes.
 * @see core/class-run-state.php  — Run-level lifecycle + ledger row.
 * @see core/class-run-reaper.php — Sweeps stages stuck in 'running'.
 * @see ARCHITECTURE.md #22 / #24 — Substrate + edit/re-run design.
 */
class PRAutoBlogger_Run_Stage_State {

	/** @var bool|null Per-request "run_stages table exists" probe result. */
	private static ?bool $table_ok = null;

	/** @return string Fully-qualified run_stages table name. */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'prautoblogger_run_stages';
	}

	/**
	 * Whether the run_stages table exists and is queryable. Cached per request.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( null !== self::$table_ok ) {
			return self::$table_ok;
		}
		global $wpdb;
		if ( null === $wpdb ) {
			return false;
		}
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		self::$table_ok = ( $found === $table );
		return self::$table_ok;
	}

	/**
	 * Stable 16-hex hash for one article idea within a run.
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The idea.
	 * @return string
	 */
	public static function idea_hash( PRAutoBlogger_Article_Idea $idea ): string {
		return substr( md5( $idea->get_suggested_title() . '|' . $idea->get_topic() ), 0, 16 );
	}

	/**
	 * Stage item_key for an article idea ('' reserved for run-level stages).
	 *
	 * @param PRAutoBlogger_Article_Idea $idea The idea.
	 * @return string e.g. 'idea:1a2b3c4d5e6f7a8b'.
	 */
	public static function item_key_for_idea( PRAutoBlogger_Article_Idea $idea ): string {
		return 'idea:' . self::idea_hash( $idea );
	}

	/**
	 * Mark a stage as running. Sticky: done stages are never demoted.
	 * Delegates to Run_Stage_Writes (v0.20.0 split — behavior unchanged).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name (see Stage_Display_Map).
	 * @param string $agent_role Fan-out dimension ('' = resolve from stage map).
	 * @param string $item_key   Article scope ('' for run-level stages).
	 */
	public static function start( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): void {
		PRAutoBlogger_Run_Stage_Writes::start( $run_id, $stage, $agent_role, $item_key );
	}

	/**
	 * Mark a stage done and persist its output for resume-without-recharge.
	 * Delegates to Run_Stage_Writes (v0.20.0 split; fresh completion also
	 * clears the `stale` flag there).
	 *
	 * @param string      $run_id     Run UUID.
	 * @param string      $stage      Stage name.
	 * @param string      $agent_role Fan-out dimension ('' = resolve from stage map).
	 * @param string      $item_key   Article scope.
	 * @param string|null $output     Stage output snapshot (pruned after R days).
	 * @param float       $cost_usd   Cost attributed to this stage.
	 */
	public static function done( string $run_id, string $stage, string $agent_role = '', string $item_key = '', ?string $output = null, float $cost_usd = 0.0 ): void {
		PRAutoBlogger_Run_Stage_Writes::done( $run_id, $stage, $agent_role, $item_key, $output, $cost_usd );
	}

	/**
	 * Mark a stage failed (does not demote a done stage). Delegates to
	 * Run_Stage_Writes (v0.20.0 split — behavior unchanged).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension ('' = resolve from stage map).
	 * @param string $item_key   Article scope.
	 */
	public static function fail( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): void {
		PRAutoBlogger_Run_Stage_Writes::fail( $run_id, $stage, $agent_role, $item_key );
	}

	/**
	 * Fetch one stage row. Falls back to role='' on miss for mid-run-upgrade
	 * compat with v0.18.1 rows (one extra indexed query, only on miss).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension ('' = resolve from stage map).
	 * @param string $item_key   Article scope.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $run_id, string $stage, string $agent_role = '', string $item_key = '' ): ?array {
		if ( '' === $run_id || ! self::is_available() ) {
			return null;
		}
		$agent_role = PRAutoBlogger_Run_Stage_Writes::resolve_role( $stage, $agent_role );
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
		if ( is_array( $row ) ) {
			return $row;
		}
		// v0.18.1 upgrade compat: rows started before this fix carry role=''.
		// One extra indexed query, only on miss, only for known stages.
		if ( '' !== $agent_role ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$legacy = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE run_id = %s AND stage = %s AND agent_role = '' AND item_key = %s",
					$run_id,
					$stage,
					$item_key
				),
				ARRAY_A
			);
			return is_array( $legacy ) ? $legacy : null;
		}
		return null;
	}

	/**
	 * Whether a stage already completed (resume entry-point check).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Fan-out dimension ('' = resolve from stage map).
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
	 * @param string $agent_role Fan-out dimension ('' = resolve from stage map).
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
	 * Fail every still-open stage of one item. Done stages are untouched.
	 * Delegates to Run_Stage_Writes (v0.20.0 split — behavior unchanged).
	 *
	 * @param string $run_id   Run UUID.
	 * @param string $item_key Stage item key ('' = run-level stages).
	 */
	public static function fail_open_for_item( string $run_id, string $item_key ): void {
		PRAutoBlogger_Run_Stage_Writes::fail_open_for_item( $run_id, $item_key );
	}

	/**
	 * All stage rows for one item of a run (plus run-level rows when
	 * $include_run_level), ordered by id. Read API for the rerun engine
	 * and the dossier's item-scoped assembly (v0.20.0).
	 *
	 * @param string $run_id            Run UUID.
	 * @param string $item_key          Item key ('idea:<hash>').
	 * @param bool   $include_run_level Include item_key='' rows too.
	 * @return array<int, array<string, mixed>> Stage rows (ARRAY_A).
	 */
	public static function stages_for_item( string $run_id, string $item_key, bool $include_run_level = true ): array {
		if ( '' === $run_id || ! self::is_available() ) {
			return array();
		}
		global $wpdb;
		$table = self::table_name();
		$sql   = $include_run_level
			? "SELECT * FROM {$table} WHERE run_id = %s AND item_key IN (%s, '') ORDER BY id ASC"
			: "SELECT * FROM {$table} WHERE run_id = %s AND item_key = %s ORDER BY id ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- assembled from two fixed literals above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $run_id, $item_key ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Reset per-request caches (tests). */	/** Reset per-request caches (tests). */
	public static function flush_cache(): void {
		self::$table_ok = null;
	}

}
