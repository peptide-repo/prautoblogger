<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Run-level state: the `wp_prautoblogger_runs` ledger + lifecycle row.
 *
 * What: One row per pipeline run (keyed by run_id UUID) carrying the run
 *       status (pending|running|done|failed|halted), the per-run cost
 *       ledger the Cost_Governor reserves against (ceiling / reserved /
 *       settled / overage), and the prompt versions pinned at run start.
 *       Every method is self-healing: a missing table (half-migrated
 *       site) degrades to no-ops/empty reads, never a fatal. Final states
 *       are sticky — a halted or failed run cannot be flipped back to
 *       running by a late writer. Per-stage state lives in
 *       PRAutoBlogger_Run_Stage_State (run_stages table).
 * Who triggers it: Cost_Tracker::set_run_id() (ensure_run + pinning),
 *       Cost_Governor (ledger + halt), Run_Reaper (stuck-run sweep),
 *       Review Queue (halted-run surfacing), Pipeline_Runner (completion).
 * Dependencies: WordPress $wpdb, get_option (ceiling setting), wp_json_encode.
 *
 * @see core/class-cost-governor.php       — Reserves against this row atomically.
 * @see core/class-prompt-registry.php     — Reads/writes pins via this class.
 * @see core/class-run-stage-state.php     — Per-stage rows (commit 4).
 * @see ARCHITECTURE.md #21                — Design rationale.
 */
class PRAutoBlogger_Run_State {

	/** @var bool|null Per-request "runs table exists" probe result. */
	private static ?bool $table_ok = null;

	/** Fully-qualified runs table name (shared with Cost_Governor). */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'prautoblogger_runs';
	}

	/**
	 * Whether the runs table exists and is queryable. Cached per request;
	 * never throws.
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
	 * The per-run cost ceiling, read from the SETTING at call time
	 * (never a literal at the point of use). 0 disables the per-run guard.
	 *
	 * @return float Ceiling in USD.
	 */
	public static function ceiling_setting(): float {
		return (float) get_option( 'prautoblogger_per_run_cost_ceiling_usd', PRAUTOBLOGGER_DEFAULT_RUN_CEILING_USD );
	}

	/**
	 * Create the run row if it does not exist (INSERT IGNORE — concurrent
	 * callers cannot double-create). Snapshots the ceiling setting at run
	 * start so a mid-run settings change cannot move the goalposts.
	 *
	 * Side effects: one INSERT IGNORE.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return void
	 */
	public static function ensure_run( string $run_id ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				(run_id, status, ceiling_usd, reserved_usd, settled_usd, overage_usd, started_at, updated_at)
				VALUES (%s, 'running', %f, 0, 0, 0, %s, %s)",
				$run_id,
				self::ceiling_setting(),
				$now,
				$now
			)
		);
	}

	/**
	 * Fetch a run row.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return array<string, mixed>|null Row, or null when absent/unavailable.
	 */
	public static function get_run( string $run_id ): ?array {
		if ( '' === $run_id || ! self::is_available() ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s", $run_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Current run status, or null when the run has no row.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return string|null pending|running|done|failed|halted, or null.
	 */
	public static function get_status( string $run_id ): ?string {
		$run = self::get_run( $run_id );
		return null !== $run ? (string) $run['status'] : null;
	}

	/**
	 * Store the pinned prompt versions — only if the run has none yet, so
	 * a resumed run keeps the versions it started with.
	 *
	 * @param string             $run_id Pipeline run UUID.
	 * @param array<string, int> $pins   prompt_key => version map.
	 * @return void
	 */
	public static function set_pins_if_absent( string $run_id, array $pins ): void {
		if ( '' === $run_id || empty( $pins ) || ! self::is_available() ) {
			return;
		}
		self::ensure_run( $run_id );
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET pinned_prompts_json = %s, updated_at = %s
				WHERE run_id = %s AND (pinned_prompts_json IS NULL OR pinned_prompts_json = '')",
				wp_json_encode( $pins ),
				current_time( 'mysql' ),
				$run_id
			)
		);
	}

	/**
	 * The pinned prompt versions for a run.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @return array<string, int> prompt_key => version ({} when none).
	 */
	public static function get_pins( string $run_id ): array {
		$run = self::get_run( $run_id );
		if ( null === $run || empty( $run['pinned_prompts_json'] ) ) {
			return array();
		}
		$pins = json_decode( (string) $run['pinned_prompts_json'], true );
		return is_array( $pins ) ? array_map( 'intval', $pins ) : array();
	}

	/**
	 * Move a run to a new status. Forward transitions only touch open
	 * (pending/running) runs; `halted` and `failed` are sticky and `done`
	 * cannot overwrite them.
	 *
	 * @param string $run_id Pipeline run UUID.
	 * @param string $status One of done|failed|halted|running.
	 * @return bool True when a row transitioned.
	 */
	public static function mark_status( string $run_id, string $status ): bool {
		if ( '' === $run_id || ! self::is_available() ) {
			return false;
		}
		if ( ! in_array( $status, array( 'running', 'done', 'failed', 'halted' ), true ) ) {
			return false;
		}
		global $wpdb;
		$table    = self::table_name();
		$now      = current_time( 'mysql' );
		$finished = in_array( $status, array( 'done', 'failed', 'halted' ), true ) ? $now : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s, finished_at = %s
				WHERE run_id = %s AND status IN ('pending','running')",
				$status,
				$now,
				$finished,
				$run_id
			)
		);
		return $updated >= 1;
	}

	/**
	 * Record by how much a breaching reservation exceeded the ceiling.
	 *
	 * @param string $run_id  Pipeline run UUID.
	 * @param float  $overage USD amount over the ceiling.
	 * @return void
	 */
	public static function record_overage( string $run_id, float $overage ): void {
		if ( '' === $run_id || $overage <= 0 || ! self::is_available() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET overage_usd = GREATEST(overage_usd, %f), updated_at = %s WHERE run_id = %s",
				$overage,
				current_time( 'mysql' ),
				$run_id
			)
		);
	}

	/**
	 * Recent halted runs, for the Review Queue surface.
	 *
	 * @param int $limit Max rows (default 10).
	 * @return array<int, array<string, mixed>>
	 */
	public static function halted_runs( int $limit = 10 ): array {
		if ( ! self::is_available() ) {
			return array();
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'halted' ORDER BY updated_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Reopen a terminal run for operator-deliberate new spend (M3
	 * re-runs). done/failed/halted -> running; finished_at cleared; the
	 * cost ceiling is RE-SNAPSHOTTED from the current setting — the
	 * deliberate re-run action adopts the operator's current per-run
	 * policy (can lower as well as raise), exactly as a new run would.
	 * Accumulated reserved/settled spend is kept: the governor treats
	 * re-runs as new spend on the SAME run (CPO guardrail 4).
	 *
	 * The conditional UPDATE is the atomic gate: an actively executing
	 * run (pending/running) can never be reopened.
	 *
	 * @param string $run_id Run UUID.
	 * @return bool Whether the run transitioned to running.
	 */
	public static function reopen( string $run_id ): bool {
		if ( '' === $run_id || ! self::is_available() ) {
			return false;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'running', ceiling_usd = %f, updated_at = %s, finished_at = NULL
				WHERE run_id = %s AND status IN ('done','failed','halted')",
				self::ceiling_setting(),
				current_time( 'mysql' ),
				$run_id
			)
		);
		return 1 === (int) $updated;
	}

	/** Reset per-request caches (tests). */
	public static function flush_cache(): void {
		self::$table_ok = null;
	}
}
