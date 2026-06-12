<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * INSERT-only store over `wp_prautoblogger_stage_inputs` (v0.20.0, M3).
 *
 * What: Immutable input-version rows for the edit + re-run feature.
 *       Two row kinds, discriminated by `source`:
 *       - 'human': an operator's edited request body for one stage. Each
 *         save creates the NEXT version for the (run, stage, role, item)
 *         scope — there is deliberately no UPDATE path in this class, so
 *         originals (and earlier forks) can never be overwritten (CPO
 *         guardrail 1; the executed original lives untouched in
 *         generation_log.request_json).
 *       - 'seed': the serialized Article_Idea persisted at worker start
 *         (stage='', version=1). Re-run-from-here reconstructs the EXACT
 *         idea from it — rebuilding the idea from post fields would risk
 *         an item_key hash mismatch (post_title is sanitized at insert)
 *         and a duplicate post.
 *       Self-healing: every method no-ops (null/false/empty) when the
 *       table is missing (half-migrated schema).
 *       Retention: human fork bodies are NULLed by the run-reaper after
 *       R days (same setting as all heavy payloads); seed rows are ~1KB
 *       structural state and are kept.
 * Who triggers it: Dossier_Actions (save fork), Rerun_Executor (read fork
 *       / seed), Article_Worker (save seed), Run_Reaper (prune).
 * Dependencies: WordPress $wpdb.
 *
 * @see admin/class-dossier-actions.php — Fork save endpoint.
 * @see core/class-rerun-executor.php   — Fork/seed consumption.
 * @see core/class-run-reaper.php       — Retention prune.
 * @see ARCHITECTURE.md #24             — Edit + re-run design.
 */
class PRAutoBlogger_Stage_Input_Store {

	/** Source discriminator: operator edit fork. */
	public const SOURCE_HUMAN = 'human';

	/** Source discriminator: idea seed persisted at worker start. */
	public const SOURCE_SEED = 'seed';

	/** @var bool|null Per-request "stage_inputs table exists" probe result. */
	private static ?bool $table_ok = null;

	/** @return string Fully-qualified stage_inputs table name. */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'prautoblogger_stage_inputs';
	}

	/**
	 * Whether the stage_inputs table exists. Cached per request.
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
	 * Save a human edit fork as the next version for its scope.
	 *
	 * INSERT-only by design. The version number is MAX(version)+1 for the
	 * scope; a concurrent-save collision on the UNIQUE key is retried
	 * once with a refreshed version.
	 *
	 * Side effects: one (rarely two) INSERTs into stage_inputs.
	 *
	 * @param string $run_id       Run UUID.
	 * @param string $stage        Stage name.
	 * @param string $agent_role   Stage row's agent role.
	 * @param string $item_key     Stage row's item key.
	 * @param string $request_json Edited request body (full JSON, no headers).
	 * @param string $author       WP user login of the editor.
	 * @return int|null New version number, or null on failure/missing table.
	 */
	public static function save_fork( string $run_id, string $stage, string $agent_role, string $item_key, string $request_json, string $author ): ?int {
		if ( '' === $run_id || '' === $stage || ! self::is_available() ) {
			return null;
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$version  = self::next_version( $run_id, $stage, $agent_role, $item_key );
			$inserted = self::insert_row( $run_id, $stage, $agent_role, $item_key, $version, self::SOURCE_HUMAN, $request_json, $author );
			if ( $inserted ) {
				return $version;
			}
		}
		return null;
	}

	/**
	 * Latest human fork row for a scope, or null when none exist.
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Agent role.
	 * @param string $item_key   Item key.
	 * @return array<string, mixed>|null Row incl. version/request_json/author/created_at.
	 */
	public static function latest_fork( string $run_id, string $stage, string $agent_role, string $item_key ): ?array {
		if ( '' === $run_id || ! self::is_available() ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE run_id = %s AND stage = %s AND agent_role = %s AND item_key = %s AND source = %s
				ORDER BY version DESC LIMIT 1",
				$run_id,
				$stage,
				$agent_role,
				$item_key,
				self::SOURCE_HUMAN
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Persist the idea seed for one run item (INSERT-once; replays no-op).
	 *
	 * @param string $run_id    Run UUID.
	 * @param string $item_key  Item key from Run_Stage_State::item_key_for_idea().
	 * @param string $idea_json Serialized Article_Idea::to_array() payload.
	 * @return void
	 */
	public static function save_seed( string $run_id, string $item_key, string $idea_json ): void {
		if ( '' === $run_id || '' === $item_key || ! self::is_available() ) {
			return;
		}
		if ( null !== self::get_seed( $run_id, $item_key ) ) {
			return; // Seed already persisted (idempotent resume).
		}
		self::insert_row( $run_id, '', '', $item_key, 1, self::SOURCE_SEED, $idea_json, 'pipeline' );
	}

	/**
	 * The idea seed JSON for one run item, or null when absent.
	 *
	 * @param string $run_id   Run UUID.
	 * @param string $item_key Item key.
	 * @return string|null
	 */
	public static function get_seed( string $run_id, string $item_key ): ?string {
		if ( '' === $run_id || ! self::is_available() ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT request_json FROM {$table}
				WHERE run_id = %s AND stage = '' AND item_key = %s AND source = %s LIMIT 1",
				$run_id,
				$item_key,
				self::SOURCE_SEED
			)
		);
		return ( is_string( $json ) && '' !== $json ) ? $json : null;
	}

	/**
	 * Retention: NULL human fork bodies older than the cutoff. Seed rows
	 * are kept (structural, ~1KB). Version/author/flag metadata survives
	 * as the permanent audit trail.
	 *
	 * @param string $cutoff MySQL datetime; rows created before it are pruned.
	 * @return int Rows pruned.
	 */
	public static function prune_human_bodies( string $cutoff ): int {
		if ( ! self::is_available() ) {
			return 0;
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET request_json = NULL
				WHERE source = %s AND request_json IS NOT NULL AND created_at < %s",
				self::SOURCE_HUMAN,
				$cutoff
			)
		);
		return is_numeric( $result ) ? (int) $result : 0;
	}

	/** Reset per-request caches (tests). */
	public static function flush_cache(): void {
		self::$table_ok = null;
	}

	/**
	 * Next version number for a scope (1 when none exist).
	 *
	 * @param string $run_id     Run UUID.
	 * @param string $stage      Stage name.
	 * @param string $agent_role Agent role.
	 * @param string $item_key   Item key.
	 * @return int
	 */
	private static function next_version( string $run_id, string $stage, string $agent_role, string $item_key ): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(version) FROM {$table}
				WHERE run_id = %s AND stage = %s AND agent_role = %s AND item_key = %s",
				$run_id,
				$stage,
				$agent_role,
				$item_key
			)
		);
		return ( null !== $max ? (int) $max : 0 ) + 1;
	}

	/**
	 * Raw row INSERT (the only write path — INSERT-only by construction).
	 *
	 * @param string $run_id       Run UUID.
	 * @param string $stage        Stage name ('' for seeds).
	 * @param string $agent_role   Agent role.
	 * @param string $item_key     Item key.
	 * @param int    $version      Version number.
	 * @param string $source       'human' or 'seed'.
	 * @param string $request_json Payload JSON.
	 * @param string $author       Author label.
	 * @return bool Whether the row was inserted.
	 */
	private static function insert_row( string $run_id, string $stage, string $agent_role, string $item_key, int $version, string $source, string $request_json, string $author ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'run_id'       => $run_id,
				'stage'        => $stage,
				'agent_role'   => $agent_role,
				'item_key'     => $item_key,
				'version'      => $version,
				'source'       => $source,
				'request_json' => $request_json,
				'author'       => $author,
				'created_at'   => current_time( 'mysql' ),
			)
		);
		return false !== $inserted;
	}
}
