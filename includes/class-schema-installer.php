<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Creates / updates the plugin's custom database tables via `dbDelta`.
 *
 * Extracted from `PRAutoBlogger_Activator` in v0.8.2 to keep that class
 * under the 300-line cap once the timezone-aware cron migration landed.
 * The Activator delegates to `install()` on activate and on db-version
 * upgrade; schema definitions live here and only here.
 *
 * Triggered by: PRAutoBlogger_Activator::activate().
 * Dependencies: WordPress $wpdb, dbDelta() from wp-admin/includes/upgrade.php.
 *
 * @see class-activator.php      — Sole caller; delegates on activate and db-version bump.
 * @see ARCHITECTURE.md          — Data model section documents each table's purpose.
 */
class PRAutoBlogger_Schema_Installer {

	/**
	 * Create (or upgrade) all plugin tables.
	 *
	 * `dbDelta` is idempotent and will create new tables, add missing
	 * columns on existing tables, and add missing indexes — but it will
	 * not drop columns or indexes. That matches the plugin's forward-only
	 * schema-migration policy.
	 *
	 * Side effects: up to five `CREATE TABLE` / `ALTER TABLE` statements.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'prautoblogger_';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Source data — raw posts/comments from social platforms.
		$sql_source_data = "CREATE TABLE {$prefix}source_data (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type VARCHAR(50) NOT NULL,
			source_id VARCHAR(255) NOT NULL,
			subreddit VARCHAR(255) DEFAULT NULL,
			title TEXT DEFAULT NULL,
			content LONGTEXT DEFAULT NULL,
			author VARCHAR(255) DEFAULT NULL,
			score INT DEFAULT 0,
			comment_count INT DEFAULT 0,
			permalink VARCHAR(500) DEFAULT NULL,
			collected_at DATETIME NOT NULL,
			metadata_json LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source_unique (source_type, source_id),
			KEY source_collected (source_type, collected_at)
		) {$charset_collate};";

		// Analysis results — detected patterns from source data.
		$sql_analysis = "CREATE TABLE {$prefix}analysis_results (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			analysis_type VARCHAR(50) NOT NULL,
			topic VARCHAR(500) NOT NULL,
			summary TEXT DEFAULT NULL,
			frequency INT DEFAULT 1,
			relevance_score FLOAT DEFAULT 0,
			source_ids_json LONGTEXT DEFAULT NULL,
			analyzed_at DATETIME NOT NULL,
			metadata_json LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			KEY type_relevance (analysis_type, relevance_score),
			KEY analyzed_at (analyzed_at)
		) {$charset_collate};";

		// Generation log — every API call with cost tracking. `run_id`
		// groups all entries from a single pipeline execution so
		// `link_generation_logs()` can accurately attribute costs to a post.
		// v0.18.0 adds the nullable audit columns `agent_role` and
		// `prompt_version` (dbDelta adds them additively on upgrade;
		// historical rows stay valid with NULL).
		$sql_generation_log = "CREATE TABLE {$prefix}generation_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			run_id VARCHAR(36) DEFAULT NULL,
			stage VARCHAR(50) NOT NULL,
			provider VARCHAR(50) NOT NULL,
			model VARCHAR(100) NOT NULL,
			prompt_tokens INT DEFAULT 0,
			completion_tokens INT DEFAULT 0,
			estimated_cost DECIMAL(10,6) DEFAULT 0,
			request_json LONGTEXT DEFAULT NULL,
			response_status VARCHAR(20) NOT NULL DEFAULT 'success',
			error_message TEXT DEFAULT NULL,
			agent_role VARCHAR(50) DEFAULT NULL,
			prompt_version VARCHAR(20) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY run_id (run_id),
			KEY created_at (created_at),
			KEY stage (stage)
		) {$charset_collate};";

		// Content scores — post performance metrics for self-improvement.
		$sql_content_scores = "CREATE TABLE {$prefix}content_scores (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			pageviews INT DEFAULT 0,
			avg_time_on_page FLOAT DEFAULT 0,
			bounce_rate FLOAT DEFAULT 0,
			comment_count INT DEFAULT 0,
			composite_score FLOAT DEFAULT 0,
			score_factors_json LONGTEXT DEFAULT NULL,
			measured_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY measured_at (measured_at),
			KEY composite_score (composite_score)
		) {$charset_collate};";

		// Event log — structured application log entries for the admin log viewer.
		$sql_event_log = "CREATE TABLE {$prefix}event_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			context VARCHAR(100) DEFAULT NULL,
			message TEXT NOT NULL,
			meta_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY level (level),
			KEY created_at (created_at),
			KEY level_created (level, created_at)
		) {$charset_collate};";

		dbDelta( $sql_source_data );
		dbDelta( $sql_analysis );
		dbDelta( $sql_generation_log );
		dbDelta( $sql_content_scores );
		dbDelta( $sql_event_log );
	}
}
