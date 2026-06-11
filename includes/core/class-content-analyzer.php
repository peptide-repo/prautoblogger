<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Analyzes collected source data using an LLM to detect recurring patterns:
 * questions people ask, complaints/pain points, and product comparisons.
 *
 * Uses a cheap model (configurable) to keep analysis costs low.
 * Batches source data into manageable chunks for the LLM context window.
 * Prompt construction is delegated to PRAutoBlogger_Analysis_Prompts.
 *
 * Triggered by: PRAutoBlogger::run_generation_pipeline() (step 2).
 * Dependencies: LLM_Provider_Interface, Cost_Tracker, PRAutoBlogger_Analysis_Prompts, WordPress $wpdb.
 *
 * @see core/class-analysis-prompts.php       — System/user prompt builders + performance context.
 * @see providers/interface-llm-provider.php  — LLM used for analysis.
 * @see core/class-cost-tracker.php           — Logs API costs.
 * @see core/class-json-extractor.php         — Tolerant JSON parsing for LLM output.
 * @see core/class-idea-scorer.php            — Consumes analysis results.
 * @see ARCHITECTURE.md                       — Data flow step 2.
 */
class PRAutoBlogger_Content_Analyzer {

	private PRAutoBlogger_LLM_Provider_Interface $llm;
	private PRAutoBlogger_Cost_Tracker $cost_tracker;

	public function __construct(
		PRAutoBlogger_LLM_Provider_Interface $llm,
		PRAutoBlogger_Cost_Tracker $cost_tracker
	) {
		$this->llm          = $llm;
		$this->cost_tracker = $cost_tracker;
	}

	/**
	 * Analyze recently collected source data for content patterns.
	 *
	 * Fetches unanalyzed data from the last 24 hours, batches it, sends to LLM
	 * for pattern detection, and stores results in ab_analysis_results.
	 *
	 * Side effects: LLM API call, database reads/writes.
	 *
	 * @param int $target_idea_count How many distinct topic ideas the LLM should produce.
	 *                               Defaults to 6 so the scorer has enough to pick from.
	 * @return PRAutoBlogger_Analysis_Result[] Detected patterns.
	 */
	public function analyze_recent_data( int $target_idea_count = 6 ): array {
		$source_items = $this->fetch_recent_source_data();

		if ( empty( $source_items ) ) {
			PRAutoBlogger_Logger::instance()->info( 'No new source data to analyze.', 'analyzer' );
			return array();
		}

		$niche = get_option( 'prautoblogger_niche_description', '' );
		$model = get_option( 'prautoblogger_analysis_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );

		$summary             = $this->build_source_summary( $source_items );
		$performance_context = PRAutoBlogger_Analysis_Prompts::get_performance_context();
		$system_prompt       = PRAutoBlogger_Analysis_Prompts::build_system_prompt( $niche, $performance_context, $target_idea_count );
		$user_prompt         = PRAutoBlogger_Analysis_Prompts::build_user_prompt( $summary, $target_idea_count );

		$response = $this->llm->send_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			$model,
			array(
				'temperature'     => 0.5,
				'max_tokens'      => 8000,
				'response_format' => array( 'type' => 'json_object' ),
				'stage'           => 'analysis',
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			'analysis',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		$results = $this->parse_analysis_response( $response['content'], $source_items );
		$this->store_results( $results );

		return $results;
	}

	// ── Backward-compatible proxies (prompt building moved to Analysis_Prompts) ──

	/**
	 * @deprecated Use PRAutoBlogger_Analysis_Prompts::build_system_prompt() directly.
	 * @see core/class-analysis-prompts.php
	 */
	private function build_system_prompt( string $niche, string $performance_context, int $target_count = 6 ): string {
		return PRAutoBlogger_Analysis_Prompts::build_system_prompt( $niche, $performance_context, $target_count );
	}

	/**
	 * @deprecated Use PRAutoBlogger_Analysis_Prompts::build_user_prompt() directly.
	 * @see core/class-analysis-prompts.php
	 */
	private function build_user_prompt( string $summary, int $target_count = 6 ): string {
		return PRAutoBlogger_Analysis_Prompts::build_user_prompt( $summary, $target_count );
	}

	/**
	 * @deprecated Use PRAutoBlogger_Analysis_Prompts::get_performance_context() directly.
	 * @see core/class-analysis-prompts.php
	 */
	private function get_performance_context(): string {
		return PRAutoBlogger_Analysis_Prompts::get_performance_context();
	}

	/**
	 * Fetch source data collected in the last 24 hours.
	 *
	 * @return array<int, array<string, mixed>> Raw database rows.
	 */
	private function fetch_recent_source_data(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_source_data';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE collected_at >= %s ORDER BY score DESC LIMIT 200",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			),
			ARRAY_A
		) ?? array();
	}

	/**
	 * Build a text summary of source data for the LLM to analyze.
	 *
	 * Prioritizes high-engagement content and keeps total text under ~8000 tokens.
	 *
	 * @param array<int, array<string, mixed>> $items Source data rows.
	 * @return string Formatted text summary.
	 */
	private function build_source_summary( array $items ): string {
		$parts         = array();
		$char_limit    = 30000; // Roughly ~7500 tokens.
		$current_chars = 0;

		foreach ( $items as $item ) {
			// LLM research findings have no subreddit; use a cleaner label.
			$source_label = 'llm_research' === $item['source_type']
				? sprintf( '[LLM Research] Relevance: %d', (int) $item['score'] )
				: sprintf( '[%s] r/%s | Score: %d | Comments: %d', $item['source_type'], $item['subreddit'] ?? 'unknown', (int) $item['score'], (int) $item['comment_count'] );

			$entry = sprintf(
				"%s\nTitle: %s\n%s\n---",
				$source_label,
				$item['title'] ?? '(comment)',
				mb_substr( $item['content'] ?? '', 0, 500 )
			);

			if ( $current_chars + strlen( $entry ) > $char_limit ) {
				break;
			}

			$parts[]        = $entry;
			$current_chars += strlen( $entry );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Parse the LLM's JSON response into Analysis_Result objects.
	 *
	 * @param string                            $content      LLM response content (JSON).
	 * @param array<int, array<string, mixed>>  $source_items Original source data for ID mapping.
	 * @return PRAutoBlogger_Analysis_Result[]
	 */
	private function parse_analysis_response( string $content, array $source_items ): array {
		$data = PRAutoBlogger_Json_Extractor::decode( $content );
		if ( ! isset( $data['patterns'] ) || ! is_array( $data['patterns'] ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'Analysis response was not valid JSON or missing patterns key. Raw (first 500 chars): '
				. mb_substr( $content, 0, 500 ),
				'analyzer'
			);
			return array();
		}

		$source_ids = array_column( $source_items, 'id' );
		$results    = array();

		foreach ( $data['patterns'] as $pattern ) {
			$results[] = new PRAutoBlogger_Analysis_Result(
				array(
					'analysis_type'   => sanitize_text_field( $pattern['type'] ?? 'question' ),
					'topic'           => sanitize_text_field( $pattern['topic'] ?? '' ),
					'summary'         => sanitize_textarea_field( $pattern['summary'] ?? '' ),
					'frequency'       => absint( $pattern['frequency'] ?? 1 ),
					'relevance_score' => (float) ( $pattern['relevance_score'] ?? 0.0 ),
					'source_ids'      => $source_ids,
					'analyzed_at'     => current_time( 'mysql' ),
					'metadata'        => array(
						'suggested_title' => $pattern['suggested_title'] ?? '',
						'key_points'      => $pattern['key_points'] ?? array(),
						'target_keywords' => $pattern['target_keywords'] ?? array(),
					),
				)
			);
		}

		return $results;
	}

	/**
	 * Store analysis results in the database.
	 *
	 * Side effects: database inserts.
	 *
	 * @param PRAutoBlogger_Analysis_Result[] $results
	 */
	private function store_results( array $results ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_analysis_results';

		foreach ( $results as $result ) {
			$row = $result->to_db_row();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
		}
	}
}
