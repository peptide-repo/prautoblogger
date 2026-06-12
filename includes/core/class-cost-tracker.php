<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Tracks all API costs and enforces monthly budget limits.
 *
 * Every LLM API call in the plugin is logged through this class. The budget
 * enforcement is a hard stop — if the monthly budget is exceeded, no further
 * API calls are made until the next month or the budget is increased.
 *
 * Triggered by: Every class that makes an LLM API call (Content_Analyzer,
 *               Content_Generator, Chief_Editor, Metrics_Collector).
 * Dependencies: WordPress $wpdb, PRAutoBlogger_OpenRouter_Provider (for cost estimation).
 *
 * @see core/class-content-analyzer.php  — Calls log_api_call() after analysis.
 * @see core/class-content-generator.php — Calls log_api_call() after each stage.
 * @see core/class-chief-editor.php      — Calls log_api_call() after review.
 * @see core/class-cost-reporter.php     — Extracted reporting methods (read-only queries).
 * @see admin/class-metrics-page.php     — Displays cost data via Cost_Reporter.
 * @see ARCHITECTURE.md                  — prab_generation_log table schema.
 */
class PRAutoBlogger_Cost_Tracker {

	/**
	 * Running cost for the current pipeline execution.
	 * Reset each time the pipeline starts.
	 */
	private float $current_run_cost = 0.0;

	/**
	 * Unique identifier for the current pipeline run.
	 * Used to link generation log entries to specific posts without timestamp-based guessing.
	 */
	private ?string $run_id = null;

	/**
	 * Set the run_id for the current pipeline execution.
	 *
	 * All subsequent log_api_call() entries will be tagged with this ID so
	 * link_generation_logs() can attribute them to the correct post.
	 *
	 * @param string $run_id UUID or unique string for this run.
	 *
	 * @return void
	 */
	public function set_run_id( string $run_id ): void {
		$this->run_id = $run_id;
		// v0.18.0: declaring a run id makes this PHP process part of that
		// run — record the context (cost-governor guard, prompt-version
		// stamping for components without a run reference), make sure the
		// run ledger row exists, and pin the active prompt versions once.
		// All three are self-healing no-ops on a half-migrated schema.
		PRAutoBlogger_Run_Context::set_run_id( $run_id );
		PRAutoBlogger_Run_State::ensure_run( $run_id );
		PRAutoBlogger_Prompt_Registry::pin_for_run( $run_id );
	}

	/**
	 * Get the current run_id.
	 *
	 * @return string|null
	 */
	public function get_run_id(): ?string {
		return $this->run_id;
	}

	/**
	 * Log an API call with its token usage and estimated cost.
	 *
	 * Side effects: database insert into prab_generation_log. Consumes the
	 * Request_Recorder stash (v0.20.0 / B1) into the row's request_json so
	 * every chat call's input is persisted (retention-pruned after R days).
	 *
	 * @param int|null    $post_id           WordPress post ID (null during generation, set later).
	 * @param string      $stage             Pipeline stage (see PRAutoBlogger_Stage_Display_Map).
	 * @param string      $provider          Provider name: 'OpenRouter'.
	 * @param string      $model             Model identifier used.
	 * @param int         $prompt_tokens     Input tokens consumed.
	 * @param int         $completion_tokens Output tokens generated.
	 * @param string      $response_status   'success', 'error', or 'timeout'.
	 * @param string      $error_message     Error details if status is not 'success'.
	 * @param string|null $agent_role        v0.18.0 — role that made the call; derived
	 *                                       from the stage map when null.
	 * @param string|null $prompt_key        v0.18.0 — registry key the call rendered
	 *                                       with (e.g. 'content.single_pass'); derived
	 *                                       from the stage map when null. Resolves the
	 *                                       run-pinned prompt_version stamped on the row.
	 *
	 * @return void
	 */
	public function log_api_call(
		?int $post_id,
		string $stage,
		string $provider,
		string $model,
		int $prompt_tokens,
		int $completion_tokens,
		string $response_status = 'success',
		string $error_message = '',
		?string $agent_role = null,
		?string $prompt_key = null
	): void {
		$llm  = new PRAutoBlogger_OpenRouter_Provider();
		$cost = $llm->estimate_cost( $model, $prompt_tokens, $completion_tokens );

		$this->current_run_cost += $cost;

		$log_entry = new PRAutoBlogger_Generation_Log(
			array(
				'post_id'           => $post_id,
				'run_id'            => $this->run_id,
				'stage'             => $stage,
				'provider'          => $provider,
				'model'             => $model,
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'estimated_cost'    => $cost,
				'response_status'   => $response_status,
				'error_message'     => $error_message,
				'request_json'      => PRAutoBlogger_Request_Recorder::consume(),
				'agent_role'        => $agent_role ?? PRAutoBlogger_Stage_Display_Map::default_agent_role( $stage ),
				'prompt_version'    => $this->resolve_prompt_version( $stage, $prompt_key ),
				'created_at'        => current_time( 'mysql' ),
			)
		);

		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $log_entry->to_db_row() );

		// Self-healing on a half-migrated schema (e.g. cron fires after a
		// deploy but before any admin_init migration pass): retry without
		// the v0.18.0 audit columns rather than losing the cost row.
		if ( false === $inserted ) {
			$legacy_row = $log_entry->to_db_row();
			unset( $legacy_row['agent_role'], $legacy_row['prompt_version'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert( $table, $legacy_row );
		}

		if ( false === $inserted || false === $wpdb->insert_id ) {
			PRAutoBlogger_Logger::instance()->error( 'Failed to log API call: ' . $wpdb->last_error, 'cost-tracker' );
		}
	}

	/**
	 * Check if the monthly budget has been exceeded.
	 *
	 * Uses round() to 4 decimal places to avoid floating-point comparison edge
	 * cases where e.g. $49.999999997 could be treated as >= $50.00.
	 *
	 * @return bool True if monthly spend >= configured budget.
	 */
	public function is_budget_exceeded(): bool {
		$budget = (float) get_option( 'prautoblogger_monthly_budget_usd', 50.00 );
		if ( $budget <= 0 ) {
			// Budget of 0 means no limit.
			return false;
		}

		$reporter      = new PRAutoBlogger_Cost_Reporter();
		$monthly_spend = $reporter->get_monthly_spend();

		// Round to 4 decimal places (0.01 cent precision) to avoid
		// floating-point representation artifacts triggering false positives.
		return round( $monthly_spend, 4 ) >= round( $budget, 4 );
	}

	/**
	 * Log an image generation API call with a known cost.
	 *
	 * Unlike log_api_call() which calculates cost from token counts, image
	 * generation uses per-megapixel pricing that the image provider already
	 * computed. This method accepts the pre-calculated cost directly.
	 *
	 * @param float  $cost_usd Estimated cost in USD (from image provider pricing).
	 * @param string $model    Image model alias (e.g. 'flux-1-schnell').
	 * @param int    $post_id  WordPress post ID the image is attached to.
	 * @param string $stage    Pipeline stage ('image_a' or 'image_b').
	 * @return void
	 */
	public function log_image_generation( float $cost_usd, string $model, int $post_id, string $stage ): void {
		$this->current_run_cost += $cost_usd;

		global $wpdb;
		if ( null === $wpdb ) {
			return;
		}
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		$row = array(
			'post_id'           => $post_id,
			'run_id'            => $this->run_id,
			'stage'             => $stage,
			'provider'          => 'cloudflare-workers-ai',
			'model'             => $model,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'estimated_cost'    => $cost_usd,
			'response_status'   => 'success',
			'error_message'     => '',
			'agent_role'        => PRAutoBlogger_Stage_Display_Map::default_agent_role( $stage ),
			'prompt_version'    => $this->resolve_prompt_version( $stage, null ),
			'created_at'        => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $row );

		// Self-healing on a half-migrated schema — see log_api_call().
		if ( false === $inserted ) {
			unset( $row['agent_role'], $row['prompt_version'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
		}
	}

	/**
	 * Resolve the pinned prompt version to stamp on a log row.
	 *
	 * Uses the explicit registry key when the call site passed one,
	 * otherwise the stage's primary key from the display map. The version
	 * comes from the run's pins; the run is this tracker's run_id or —
	 * for standalone trackers inside a pipeline process (e.g. the image
	 * prompt rewriter's) — the process-level run context. Null when there
	 * is no run, no pins, or no key (historical behavior preserved).
	 *
	 * @param string      $stage      Stage being logged.
	 * @param string|null $prompt_key Explicit registry key, or null to derive.
	 * @return string|null Pinned version as string, or null.
	 */
	private function resolve_prompt_version( string $stage, ?string $prompt_key ): ?string {
		$key = $prompt_key ?? PRAutoBlogger_Stage_Display_Map::default_prompt_key( $stage );
		if ( null === $key ) {
			return null;
		}
		$run_id = $this->run_id ?? PRAutoBlogger_Run_Context::current_run_id();
		$pins   = PRAutoBlogger_Prompt_Registry::pins_for_run( $run_id );
		return isset( $pins[ $key ] ) ? (string) $pins[ $key ] : null;
	}

	/**
	 * Check if adding an estimated cost would exceed the monthly budget.
	 *
	 * Unlike is_budget_exceeded() which checks current state, this method
	 * proactively checks whether a planned expenditure would push spend
	 * over the budget. Used by the image pipeline to pre-check before
	 * making an API call.
	 *
	 * @param float $estimated_cost_usd Estimated cost in USD for the planned operation.
	 * @return bool True if (current spend + estimated cost) >= configured budget.
	 */
	public function would_exceed_budget( float $estimated_cost_usd ): bool {
		$budget = (float) get_option( 'prautoblogger_monthly_budget_usd', 50.00 );
		if ( $budget <= 0 ) {
			return false;
		}

		$reporter  = new PRAutoBlogger_Cost_Reporter();
		$projected = $reporter->get_monthly_spend() + $estimated_cost_usd;
		return round( $projected, 4 ) >= round( $budget, 4 );
	}

	/**
	 * Get the cost accumulated during the current pipeline run.
	 *
	 * @return float USD cost for this run.
	 */
	public function get_current_run_cost(): float {
		return $this->current_run_cost;
	}

	/**
	 * Get average input/output token counts for given stages over a time period.
	 *
	 * Thin delegate to PRAutoBlogger_Cost_Reporter (the read-only reporting
	 * class) — kept here for API compatibility with the model-picker field
	 * and existing tests. See Cost_Reporter for the query.
	 *
	 * @param array<string> $stages Stage names ('analysis', 'outline', 'draft', 'polish', 'review').
	 * @param int           $days   Historical window (default 30 days).
	 *
	 * @return array{avg_prompt_tokens: float, avg_completion_tokens: float, sample_size: int}
	 *         Returns empty counters if no history. Never throws.
	 */
	public function get_avg_tokens_for_stages( array $stages, int $days = 30 ): array {
		return ( new PRAutoBlogger_Cost_Reporter() )->get_avg_tokens_for_stages( $stages, $days );
	}
}
