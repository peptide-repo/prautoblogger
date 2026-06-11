<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Prompt construction for the content analysis LLM call.
 *
 * What: Builds system and user prompts for source-data analysis, including
 *       past-performance context for self-improvement feedback loops.
 * Who calls it: PRAutoBlogger_Content_Analyzer::analyze_recent_data().
 * Dependencies: PRAutoBlogger_Prompt_Registry (versioned prompt bodies),
 *               WordPress $wpdb (for performance lookup), no external APIs.
 *
 * @see core/class-content-analyzer.php — Orchestrates analysis and calls these builders.
 * @see ARCHITECTURE.md                 — Data flow step 2.
 */
class PRAutoBlogger_Analysis_Prompts {

	/**
	 * Build the system prompt for content analysis.
	 *
	 * @param string $niche               Niche description from settings.
	 * @param string $performance_context  Past performance data summary.
	 * @param int    $target_count         Number of ideas the LLM should produce.
	 * @return string System prompt text.
	 */
	public static function build_system_prompt( string $niche, string $performance_context, int $target_count = 6 ): string {
		// Blocks carry their own separators when non-empty so the rendered
		// output is byte-identical to the historical concatenation.
		$performance_block = '' !== $performance_context ? $performance_context . "\n\n" : '';

		$recent_context = self::get_recent_articles_context();
		$recent_block   = '' !== $recent_context ? $recent_context . "\n\n" : '';

		$instructions       = trim( (string) get_option( 'prautoblogger_analysis_instructions', '' ) );
		$instructions_block = '' !== $instructions ? "Additional instructions:\n" . $instructions . "\n\n" : '';

		return PRAutoBlogger_Prompt_Registry::render(
			'analysis.system',
			array(
				'niche_clause'          => '' !== $niche ? " in the {$niche} niche" : '',
				'target_count'          => (string) $target_count,
				'performance_block'     => $performance_block,
				'recent_articles_block' => $recent_block,
				'instructions_block'    => $instructions_block,
			)
		);
	}

	/**
	 * Build the user prompt containing the actual source data.
	 *
	 * @param string $summary      Formatted source data summary.
	 * @param int    $target_count Number of ideas requested.
	 * @return string User prompt text.
	 */
	public static function build_user_prompt( string $summary, int $target_count = 6 ): string {
		return PRAutoBlogger_Prompt_Registry::render(
			'analysis.user',
			array(
				'target_count' => (string) $target_count,
				'summary'      => $summary,
			)
		);
	}

	/**
	 * Get past content performance data to feed into analysis for self-improvement.
	 *
	 * Side effects: database read.
	 *
	 * @return string Summary of what topics performed well, or empty string if no data.
	 */
	public static function get_performance_context(): string {
		global $wpdb;
		$scores_table = $wpdb->prefix . 'prautoblogger_content_scores';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$top_posts = $wpdb->get_results(
			"SELECT cs.post_id, cs.composite_score, p.post_title  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			FROM {$scores_table} cs
			JOIN {$wpdb->posts} p ON p.ID = cs.post_id
			WHERE cs.composite_score > 0
			ORDER BY cs.composite_score DESC
			LIMIT 5",
			ARRAY_A
		);

		if ( empty( $top_posts ) ) {
			return '';
		}

		$lines = array( 'Top performing past articles (learn from these):' );
		foreach ( $top_posts as $post ) {
			$lines[] = sprintf(
				'- "%s" (score: %.1f)',
				$post['post_title'],
				(float) $post['composite_score']
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get titles of recently published articles so the LLM avoids repeating them.
	 *
	 * Fetches generated posts from the last 30 days. A wider window than the
	 * scorer's 7-day dedup because the LLM is smart enough to find genuinely
	 * new angles even on adjacent topics — we just need it to know what exists.
	 *
	 * Side effects: database read via WP_Query.
	 *
	 * @return string Formatted context block, or empty string if no recent articles.
	 */
	public static function get_recent_articles_context(): string {
		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'meta_key'       => '_prautoblogger_generated',
				'meta_value'     => '1',
				'date_query'     => array( array( 'after' => '30 days ago' ) ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $query->posts ) ) {
			return '';
		}

		$titles = array();
		foreach ( $query->posts as $post_id ) {
			$titles[] = '- "' . get_the_title( $post_id ) . '"';
		}

		$block  = 'IMPORTANT — Topics already covered (last 30 days). Do NOT suggest topics that overlap with these. ';
		$block .= "Find genuinely NEW angles, questions, or subjects that are NOT already covered:\n";
		$block .= implode( "\n", $titles );

		return $block;
	}
}
