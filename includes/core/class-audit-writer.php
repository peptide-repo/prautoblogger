<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Writes run-scoped audit rows: sources considered + stage decisions.
 *
 * What: Thin, self-healing insert layer over the v0.18.0 audit child
 *       tables. `record_decision()` persists a stage verdict (Phase 1:
 *       the chief editor's review verdict; Phase 2 adds curate/editorial/
 *       seo decisions and citation_score). `record_idea_sources()`
 *       consolidates which collected sources fed a generated article —
 *       the run_sources replacement for the historical source_ids_json /
 *       `_prautoblogger_research_sources` scatter — for NEW runs only (no
 *       backfill). Every method no-ops when the tables are missing.
 * Who triggers it: Article_Worker (decision after review, sources after
 *       publish), Phase-2 agents.
 * Dependencies: WordPress $wpdb, Run_State (availability probe pattern).
 *
 * @see core/class-article-worker.php — Phase-1 call sites.
 * @see ARCHITECTURE.md #21           — Audit substrate design.
 */
class PRAutoBlogger_Audit_Writer {

	/** @var bool|null Per-request "audit tables exist" probe result. */
	private static ?bool $tables_ok = null;

	/**
	 * Whether the audit child tables exist. Cached per request; never throws.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( null !== self::$tables_ok ) {
			return self::$tables_ok;
		}
		global $wpdb;
		if ( null === $wpdb ) {
			return false; // Not cached: $wpdb may appear later in boot.
		}
		$table = $wpdb->prefix . 'prautoblogger_run_decisions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found           = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		self::$tables_ok = ( $found === $table );
		return self::$tables_ok;
	}

	/**
	 * Persist a stage decision for a run.
	 *
	 * @param string     $run_id         Run UUID.
	 * @param string     $stage          Deciding stage (see Stage_Display_Map).
	 * @param string     $verdict        e.g. 'approved', 'revised', 'rejected'.
	 * @param string     $rationale      Why (editor notes, governor message, …).
	 * @param float|null $citation_score Phase-2 citation score (null in Phase 1).
	 * @return void
	 */
	public static function record_decision( string $run_id, string $stage, string $verdict, string $rationale = '', ?float $citation_score = null ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'prautoblogger_run_decisions',
			array(
				'run_id'         => $run_id,
				'stage'          => $stage,
				'verdict'        => $verdict,
				'rationale'      => $rationale,
				'citation_score' => $citation_score,
				'created_at'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Record the collected sources that fed a generated article (kept = 1;
	 * Phase-2 curation adds discard rows with reasons + quality scores).
	 *
	 * Resolves the idea's source_data ids to permalinks in one query.
	 *
	 * @param string                     $run_id Run UUID.
	 * @param PRAutoBlogger_Article_Idea $idea   The generated idea.
	 * @return void
	 */
	public static function record_idea_sources( string $run_id, PRAutoBlogger_Article_Idea $idea ): void {
		if ( '' === $run_id || ! self::is_available() ) {
			return;
		}
		$source_ids = array_filter( array_map( 'intval', $idea->get_source_ids() ) );
		if ( empty( $source_ids ) ) {
			return;
		}

		global $wpdb;
		$source_table = $wpdb->prefix . 'prautoblogger_source_data';
		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed %d list.
			$wpdb->prepare( "SELECT id, source_type, permalink FROM {$source_table} WHERE id IN ({$placeholders})", $source_ids ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'prautoblogger_run_sources',
				array(
					'run_id'        => $run_id,
					'agent_role'    => 'analyst',
					'source_url'    => (string) ( $row['permalink'] ?? '' ),
					'doi'           => null,
					'kept'          => 1,
					'reason'        => sprintf( 'Cited by analyzer for "%s" (%s #%d)', $idea->get_topic(), (string) $row['source_type'], (int) $row['id'] ),
					'quality_score' => null,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}
	}

	/**
	 * Flag the run_decisions rows of given stages as human-modified
	 * (v0.20.0 / CPO guardrail 2). Used in two cases: the decision rows of
	 * a stage whose edited input enters execution, and decisions recorded
	 * during a re-run-from-here window on an item that carries a human
	 * edit (derived-from-edited-content). Self-healing no-op when the
	 * column/table is missing.
	 *
	 * @param string        $run_id Run UUID.
	 * @param array<string> $stages Stage names to flag.
	 * @param string|null   $since  Optional MySQL datetime — only flag rows created at/after it.
	 * @return int Rows flagged.
	 */
	public static function flag_decisions_human_modified( string $run_id, array $stages, ?string $since = null ): int {
		if ( '' === $run_id || empty( $stages ) || ! self::is_available() ) {
			return 0;
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'prautoblogger_run_decisions';
		$placeholders = implode( ',', array_fill( 0, count( $stages ), '%s' ) );
		$sql          = "UPDATE {$table} SET human_modified = 1 WHERE run_id = %s AND stage IN ({$placeholders})";
		$params       = array_merge( array( $run_id ), array_values( $stages ) );
		if ( null !== $since ) {
			$sql     .= ' AND created_at >= %s';
			$params[] = $since;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed %s list.
		$updated = $wpdb->query( $wpdb->prepare( $sql, $params ) );
		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/** Reset per-request caches (tests). */
	public static function flush_cache(): void {
		self::$tables_ok = null;
	}
}
