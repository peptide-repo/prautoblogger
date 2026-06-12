<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Provides historical cost reporting and analysis methods.
 *
 * Extracted from PRAutoBlogger_Cost_Tracker, this class handles all read-only
 * reporting queries: monthly spend, daily spend trends, spend-by-stage breakdowns,
 * and budget utilization percentage.
 *
 * Called by: Metrics dashboard, admin notices, and dashboard widget.
 * Dependencies: WordPress $wpdb, get_option() for budget configuration.
 *
 * @see core/class-cost-tracker.php    — Complementary class for logging and budget enforcement.
 * @see admin/class-metrics-page.php   — Displays cost reports.
 * @see admin/class-admin-notices.php  — Shows budget warnings via get_budget_utilization().
 * @see admin/class-dashboard-widget.php — Displays current month costs.
 */
class PRAutoBlogger_Cost_Reporter {

	/**
	 * Get total estimated spend for the current calendar month.
	 *
	 * @return float Total USD spend this month.
	 */
	public function get_monthly_spend(): float {
		global $wpdb;
		if ( null === $wpdb ) {
			return 0.0;
		}

		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		$first_of_month = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(estimated_cost), 0) FROM {$table} WHERE created_at >= %s AND response_status = 'success'",
				$first_of_month
			)
		);

		return (float) $result;
	}

	/**
	 * Get daily spend for the last N days (for the metrics dashboard chart).
	 *
	 * @param int $days Number of days to look back.
	 *
	 * @return array<string, float> Associative array of date => total_cost.
	 */
	public function get_daily_spend( int $days = 30 ): array {
		global $wpdb;
		if ( null === $wpdb ) {
			return array();
		}

		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		$start_date = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as day, SUM(estimated_cost) as total_cost
				FROM {$table}
				WHERE created_at >= %s AND response_status = 'success'
				GROUP BY DATE(created_at)
				ORDER BY day ASC",
				$start_date . ' 00:00:00'
			),
			ARRAY_A
		);

		$daily = array();
		foreach ( ( $results ?? array() ) as $row ) {
			$daily[ $row['day'] ] = (float) $row['total_cost'];
		}

		return $daily;
	}

	/**
	 * Get spend breakdown by pipeline stage for a given period.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 *
	 * @return array<string, array{cost: float, tokens: int, calls: int}> Breakdown by stage.
	 */
	public function get_spend_by_stage( string $start_date, string $end_date ): array {
		global $wpdb;
		if ( null === $wpdb ) {
			return array();
		}

		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stage,
					SUM(estimated_cost) as total_cost,
					SUM(prompt_tokens + completion_tokens) as total_tokens,
					COUNT(*) as call_count
				FROM {$table}
				WHERE created_at BETWEEN %s AND %s AND response_status = 'success'
				GROUP BY stage",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			),
			ARRAY_A
		);

		$breakdown = array();
		foreach ( ( $results ?? array() ) as $row ) {
			$breakdown[ $row['stage'] ] = array(
				'cost'   => (float) $row['total_cost'],
				'tokens' => (int) $row['total_tokens'],
				'calls'  => (int) $row['call_count'],
			);
		}

		return $breakdown;
	}

	/**
	 * Get budget utilization percentage for the current month.
	 *
	 * @return float Percentage (0-100+). Can exceed 100 if overspent.
	 */
	public function get_budget_utilization(): float {
		$budget = (float) get_option( 'prautoblogger_monthly_budget_usd', 50.00 );
		if ( $budget <= 0 ) {
			return 0.0;
		}
		return ( $this->get_monthly_spend() / $budget ) * 100.0;
	}

	/**
	 * Get average input/output token counts for given stages over a time period.
	 *
	 * Used by the model picker field renderer to calculate estimated costs
	 * per generation based on historical token usage. Maps setting IDs to their
	 * constituting stages (e.g., writing model controls outline + draft + polish).
	 * Moved here from Cost_Tracker in v0.18.0 (read-only reporting query;
	 * Cost_Tracker keeps a delegating wrapper for API compatibility).
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
