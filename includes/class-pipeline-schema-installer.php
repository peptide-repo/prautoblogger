<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Creates / updates the Pipeline v2 substrate tables via `dbDelta` (db 1.3.0).
 *
 * What: Owns the six pipeline substrate tables — the versioned prompt
 *       registry (`prompts`), the audit child tables (`run_sources`,
 *       `run_decisions`), and the run ledger / state machine tables
 *       (`runs`, `run_stages`), and the immutable stage-input version
 *       store (`stage_inputs`, v0.20.0 / db 1.3.0 — edit + re-run forks
 *       and idea seeds). Kept separate from the original
 *       PRAutoBlogger_Schema_Installer so both classes stay under the
 *       300-line cap and the v1.1.0 schema remains byte-stable.
 * Who triggers it: PRAutoBlogger_Activator::activate() — on activation and
 *       on every `prautoblogger_db_version` mismatch (self-healing
 *       auto-migration via PRAutoBlogger::on_check_db_version()).
 * Dependencies: WordPress $wpdb, dbDelta() from wp-admin/includes/upgrade.php.
 *
 * @see class-schema-installer.php      — v1.1.0 tables (source data, logs, scores).
 * @see class-activator.php             — Sole caller.
 * @see core/class-prompt-registry.php  — Reads/writes the prompts table.
 * @see core/class-run-state.php        — Reads/writes runs + run_stages.
 * @see ARCHITECTURE.md                 — Database schema section documents each table.
 */
class PRAutoBlogger_Pipeline_Schema_Installer {

	/**
	 * Create (or upgrade) the Pipeline v2 substrate tables.
	 *
	 * `dbDelta` is idempotent: it creates missing tables, adds missing
	 * columns and indexes, and never drops anything — matching the
	 * plugin's forward-only schema policy. Safe to re-run on every
	 * version mismatch.
	 *
	 * Side effects: up to six CREATE TABLE / ALTER TABLE statements.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'prautoblogger_';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Versioned prompt registry. Versions are immutable: writes create
		// new (prompt_key, version) rows; exactly one row per key has
		// active = 1. `prompt_key` (not `key`) because KEY is reserved SQL.
		$sql_prompts = "CREATE TABLE {$prefix}prompts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			prompt_key VARCHAR(64) NOT NULL,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			body LONGTEXT NOT NULL,
			model VARCHAR(100) DEFAULT NULL,
			params_json LONGTEXT DEFAULT NULL,
			author VARCHAR(100) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY key_version (prompt_key, version),
			KEY key_active (prompt_key, active)
		) {$charset_collate};";

		// Research-source audit rows: one row per source a run considered,
		// with the keep/discard decision. Consolidates the old
		// source_ids_json + _prautoblogger_research_sources scatter for
		// new runs (no backfill of historical runs).
		$sql_run_sources = "CREATE TABLE {$prefix}run_sources (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(36) NOT NULL,
			agent_role VARCHAR(50) NOT NULL DEFAULT '',
			source_url VARCHAR(500) DEFAULT NULL,
			doi VARCHAR(255) DEFAULT NULL,
			kept TINYINT(1) NOT NULL DEFAULT 0,
			reason TEXT DEFAULT NULL,
			quality_score FLOAT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY run_id (run_id)
		) {$charset_collate};";

		// Stage-decision audit rows: verdict + rationale per pipeline
		// stage. citation_score is nullable until the Phase-2 editorial
		// loop computes it.
		$sql_run_decisions = "CREATE TABLE {$prefix}run_decisions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(36) NOT NULL,
			stage VARCHAR(50) NOT NULL,
			verdict VARCHAR(50) NOT NULL,
			rationale TEXT DEFAULT NULL,
			citation_score FLOAT DEFAULT NULL,
			human_modified TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY run_id (run_id)
		) {$charset_collate};";

		// Per-run ledger + lifecycle row. reserved/settled/ceiling drive the
		// Cost_Governor's atomic reserve-before-call check; pinned_prompts_json
		// freezes the prompt versions a run uses at start; status feeds the
		// run state machine (pending|running|done|failed|halted).
		$sql_runs = "CREATE TABLE {$prefix}runs (
			run_id VARCHAR(36) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			ceiling_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			reserved_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			settled_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			overage_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			pinned_prompts_json LONGTEXT DEFAULT NULL,
			started_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			finished_at DATETIME DEFAULT NULL,
			PRIMARY KEY (run_id),
			KEY status (status),
			KEY started_at (started_at)
		) {$charset_collate};";

		// Per-run per-stage state rows. The idempotency key is
		// (run_id, stage, agent_role, item_key): agent_role is the Phase-2
		// fan-out dimension (quorum members), item_key scopes article-level
		// stages within multi-article runs ('' for run-level stages).
		// meta_json holds the stage output for resume-without-recharge.
		$sql_run_stages = "CREATE TABLE {$prefix}run_stages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(36) NOT NULL,
			stage VARCHAR(50) NOT NULL,
			agent_role VARCHAR(50) NOT NULL DEFAULT '',
			item_key VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			meta_json LONGTEXT DEFAULT NULL,
			human_modified TINYINT(1) NOT NULL DEFAULT 0,
			stale TINYINT(1) NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			finished_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY run_stage_role_item (run_id, stage, agent_role, item_key),
			KEY status_updated (status, updated_at)
		) {$charset_collate};";

		// Immutable stage-input version store (v0.20.0 / db 1.3.0, M3
		// edit + re-run). Rows are INSERT-only: a human edit creates the
		// next (scope, version) row -- the original input (the
		// generation_log.request_json of the executed call) is never
		// overwritten (CPO guardrail 1). source='seed' rows persist the
		// Article_Idea payload at worker start so re-run-from-here can
		// reconstruct the exact idea (item_key hash stability); 'human'
		// rows are edit forks. Human fork bodies are retention-pruned
		// like all heavy payloads; seed rows (~1KB) are structural and
		// kept for the life of the run.
		$sql_stage_inputs = "CREATE TABLE {$prefix}stage_inputs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(36) NOT NULL,
			stage VARCHAR(50) NOT NULL DEFAULT '',
			agent_role VARCHAR(50) NOT NULL DEFAULT '',
			item_key VARCHAR(64) NOT NULL DEFAULT '',
			version INT UNSIGNED NOT NULL DEFAULT 1,
			source VARCHAR(20) NOT NULL DEFAULT 'human',
			request_json LONGTEXT DEFAULT NULL,
			author VARCHAR(100) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY scope_version (run_id, stage, agent_role, item_key, version),
			KEY run_id (run_id)
		) {$charset_collate};";

		dbDelta( $sql_prompts );
		dbDelta( $sql_run_sources );
		dbDelta( $sql_run_decisions );
		dbDelta( $sql_runs );
		dbDelta( $sql_run_stages );
		dbDelta( $sql_stage_inputs );
	}
}
