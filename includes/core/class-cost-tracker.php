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
	 * Side effects: database insert into prab_generation_log.
	 *
	 * @param int|null $post_id           WordPress post ID (null during generation, set later).
	 * @param string   $stage             Pipeline stage: 'analysis', 'outline', 'draft', 'polish', 'review'.
	 * @param string   $provider          Provider name: 'OpenRouter'.
	 * @param string   $model             Model identifier used.
	 * @param int      $prompt_tokens     Input tokens consumed.
	 * @param int      $completion_tokens Output tokens generated.
	 * @param string   $response_status   'success', 'error', or 'timeout'.
	 * @param string   $error_message     Error details if status is not 'success'.
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
		string $error_message = ''
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
				'created_at'        => current_time( 'mysql' ),
			)
		);

		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $log_entry->to_db_row() );

		if ( false === $wpdb->insert_id ) {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
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
				'created_at'        => current_time( 'mysql' ),
			)
		);
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
	 * Used by the model picker field renderer to calculate estimated costs
	 * per generation based on historical token usage. Maps setting IDs to their
	 * constituting stages (e.g., writing model controls outline + draft + polish).
	 *
	 * @param array<string> $stages Stage names ('analysis', 'outline', 'draft', 'polish', 'review').
	 * @param int           $days   Historical window (default 30 days).
	 *
	 * @return array{avg_prompt_tokens: float, avg_completion_tokens: float, sample_size: int}
	 *         Returns empty counters if no history. Never throws.
	 */
	public function get_avg_tokens_for_stages( array $stages, int $days = 30 ): array {
		global $wpdb;

		if ( empty( $stages ) ) {
			return array(
				'avg_prompt_tokens'      => 0.0,
				'avg_completion_tokens'  => 0.0,
				'sample_size'            => 0,
			);
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$stage_list      = implode( ',', array_map( array( $wpdb, 'prepare' ), array_fill( 0, count( $stages ), '%s' ), $stages ) );
		$table_name      = $wpdb->prefix . 'prautoblogger_generation_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prepared stage list via array_map + prepare
		$query = $wpdb->prepare(
			"SELECT AVG(prompt_tokens) as avg_prompt,
                    AVG(completion_tokens) as avg_completion,
                    COUNT(*) as sample_count
             FROM $table_name
             WHERE stage IN ( $stage_list )
             AND created_at >= %s",
			$cutoff_datetime
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- phpcs pragma above
		$result = $wpdb->get_row( $query, ARRAY_A );

		if ( ! $result || 0 === (int) ( $result['sample_count'] ?? 0 ) ) {
			return array(
				'avg_prompt_tokens'      => 0.0,
				'avg_completion_tokens'  => 0.0,
				'sample_size'            => 0,
			);
		}

		return array(
			'avg_prompt_tokens'      => (float) ( $result['avg_prompt'] ?? 0 ),
			'avg_completion_tokens'  => (float) ( $result['avg_completion'] ?? 0 ),
			'sample_size'            => (int) ( $result['sample_count'] ?? 0 ),
		);
	}
}
