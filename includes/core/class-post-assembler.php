<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Post-creation assembly helpers: taxonomy, log linking, images, content sanitization.
 *
 * What: After wp_insert_post, assigns categories/tags, links generation logs,
 *       generates and attaches images, and sanitizes raw LLM content.
 * Who calls it: PRAutoBlogger_Publisher::create_post().
 * Dependencies: WordPress taxonomy/meta APIs, PRAutoBlogger_Image_Pipeline, PRAutoBlogger_Logger.
 *
 * @see core/class-publisher.php — Orchestrates post creation and calls these helpers.
 * @see core/class-image-pipeline.php — Image generation for attach_generated_images.
 */
class PRAutoBlogger_Post_Assembler {

	/**
	 * Assign category and tags to a generated post.
	 *
	 * @param int                        $post_id Post ID.
	 * @param PRAutoBlogger_Article_Idea $idea    Article idea with type and keywords.
	 */
	public static function assign_taxonomy_terms( int $post_id, PRAutoBlogger_Article_Idea $idea ): void {
		$type_category_map = array(
			'guide'      => 'Guides',
			'solution'   => 'Solutions',
			'comparison' => 'Comparisons',
			'article'    => 'Articles',
		);

		$category_name = $type_category_map[ $idea->get_article_type() ] ?? 'Articles';
		$category      = get_term_by( 'name', $category_name, 'category' );

		if ( ! $category ) {
			$result = wp_insert_term( $category_name, 'category' );
			if ( ! is_wp_error( $result ) ) {
				wp_set_post_categories( $post_id, array( $result['term_id'] ) );
			}
		} else {
			wp_set_post_categories( $post_id, array( $category->term_id ) );
		}

		$keywords = $idea->get_target_keywords();
		if ( ! empty( $keywords ) ) {
			wp_set_post_tags( $post_id, $keywords, true );
		}
	}

	/**
	 * Link generation log entries to the newly created post.
	 * Uses run_id for precise linking; falls back to timestamp for legacy data.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $run_id  Pipeline run identifier, or null for legacy fallback.
	 */
	public static function link_generation_logs( int $post_id, ?string $run_id = null ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		if ( null !== $run_id && '' !== $run_id ) {
			// Exclude 'llm_research' stage — those shared costs are amortized
			// across all articles after the full pipeline completes, not linked
			// to a single post. See amortize_research_costs().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$table} SET post_id = %d WHERE post_id IS NULL AND run_id = %s AND stage != 'llm_research'",
					$post_id,
					$run_id
				)
			);
		} else {
			// Legacy fallback: timestamp-based linking for pre-migration log entries.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query(
				$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$table} SET post_id = %d WHERE post_id IS NULL AND created_at >= %s",
					$post_id,
					gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
				)
			);
		}
	}

	/**
	 * Amortize shared LLM research costs across all articles in a pipeline run.
	 *
	 * The research LLM call runs once per pipeline execution before any articles
	 * are generated. Its cost is logged with the run_id but no post_id. After all
	 * articles finish, this method divides the research cost evenly and inserts
	 * one 'llm_research' row per article so the cost breakdown popover includes
	 * the amortized research overhead. The audit fields prompt_version and
	 * agent_role are carried from the original research row (v0.19.4 fix;
	 * prior builds left them NULL/empty — see phase-1 thread seq 49).
	 *
	 * Side effects: database SELECT, INSERT, DELETE on generation_log.
	 *
	 * @param string|null $run_id Pipeline run UUID. Null or empty is a no-op.
	 */
	public static function amortize_research_costs( ?string $run_id ): void {
		if ( null === $run_id || '' === $run_id ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'prautoblogger_generation_log';

		// Find the unlinked research cost row for this run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$research_row = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, estimated_cost, provider, model, prompt_tokens, completion_tokens
				FROM {$table}
				WHERE run_id = %s AND stage = 'llm_research' AND post_id IS NULL
				LIMIT 1",
				$run_id
			)
		);

		if ( ! $research_row || (float) $research_row->estimated_cost <= 0.0 ) {
			return; // No research cost to amortize (LLM research not enabled or free).
		}

		// Count distinct articles produced in this run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT post_id FROM {$table} WHERE run_id = %s AND post_id IS NOT NULL",
				$run_id
			)
		);

		$post_count = count( $post_ids );
		if ( $post_count < 1 ) {
			return; // No articles produced — nothing to amortize into.
		}

		$total_cost       = (float) $research_row->estimated_cost;
		$amortized_cost   = $total_cost / $post_count;
		$total_prompt     = (int) $research_row->prompt_tokens;
		$total_completion = (int) $research_row->completion_tokens;
		$amortized_prompt = (int) round( $total_prompt / $post_count );
		$amortized_compl  = (int) round( $total_completion / $post_count );

		// Insert one amortized research row per article.
		foreach ( $post_ids as $post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'post_id'           => (int) $post_id,
					'run_id'            => $run_id,
					'stage'             => 'llm_research',
					'provider'          => $research_row->provider,
					'model'             => $research_row->model,
					'prompt_tokens'     => $amortized_prompt,
					'completion_tokens' => $amortized_compl,
					'estimated_cost'    => $amortized_cost,
					'response_status'   => 'success',
					'error_message'     => '',
					'prompt_version'    => $research_row->prompt_version,
					'agent_role'        => $research_row->agent_role ?? '',
					'created_at'        => current_time( 'mysql' ),
				)
			);
		}

		// Remove the original unlinked row — it's been replaced by per-article rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, array( 'id' => (int) $research_row->id ) );

		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Amortized LLM research cost $%.4f across %d articles ($%.4f each).',
				$total_cost,
				$post_count,
				$amortized_cost
			),
			'cost-tracker'
		);
	}

	/**
	 * Strip common LLM output artifacts from generated content.
	 * Removes markdown fences, HTML document wrappers, stray head-level tags.
	 *
	 * @param string $content Raw LLM output.
	 * @return string Cleaned HTML suitable for post_content.
	 */
	public static function sanitize_llm_content( string $content ): string {
		$content = trim( $content );

		// Strip markdown code fences: ```html ... ``` or ``` ... ```
		if ( preg_match( '/^```(?:\w+)?\s*\n(.*?)```\s*$/s', $content, $matches ) ) {
			$content = trim( $matches[1] );
		}

		// Strip full HTML document wrappers — extract only <body> inner content.
		if ( preg_match( '/<body[^>]*>(.*)<\/body>/is', $content, $matches ) ) {
			$content = trim( $matches[1] );
		}

		// Remove stray document-level tags.
		$content = preg_replace( '/<\/?(html|head|body|meta|title|!DOCTYPE)[^>]*>/i', '', $content );
		$content = preg_replace( '/<link\s[^>]*>/i', '', $content );
		$content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		return trim( $content );
	}

	/**
	 * Get the default author ID for generated posts.
	 *
	 * @return int WordPress user ID.
	 */
	public static function get_default_author_id(): int {
		$author_id = absint( get_option( 'prautoblogger_default_author', 0 ) );
		if ( $author_id > 0 ) {
			return $author_id;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}

	/**
	 * Generate and attach images to a published post.
	 * Non-blocking — logs errors but doesn't re-throw since the post is already created.
	 *
	 * @param int                        $post_id      Post ID.
	 * @param PRAutoBlogger_Article_Idea $idea         Article idea (contains source IDs).
	 * @param array                      $post_data    Post data array (for article content).
	 * @param ?PRAutoBlogger_Cost_Tracker $cost_tracker Optional cost tracker for image generation cost logging.
	 */
	public static function attach_generated_images( int $post_id, PRAutoBlogger_Article_Idea $idea, array $post_data, ?PRAutoBlogger_Cost_Tracker $cost_tracker = null ): void {
		// Fetch the original Reddit source data for Image B's source-driven prompt.
		// The idea's source_ids point to rows in the source_data table collected
		// during the pipeline's collection step.
		$source_data = ( new PRAutoBlogger_Source_Collector() )->get_source_data_for_image( $idea->get_source_ids() );

		try {
			// The pipeline now sets featured image and Image B meta
			// internally, immediately after each image generates. This
			// ensures attachment persists even if the process times out
			// before this method returns.
			$result = ( new PRAutoBlogger_Image_Pipeline( null, $cost_tracker ) )->generate_and_attach_images( $post_id, $post_data, $source_data );

			if ( ! empty( $result['errors'] ) ) {
				foreach ( $result['errors'] as $error ) {
					PRAutoBlogger_Logger::instance()->warning( 'Image warning for post ' . $post_id . ': ' . $error, 'publisher' );
				}
			}

			if ( $result['cost_usd'] > 0.0 ) {
				PRAutoBlogger_Logger::instance()->info(
					sprintf( 'Image generation cost: $%.4f for post %d', $result['cost_usd'], $post_id ),
					'publisher'
				);
			}
		} catch ( \Throwable $e ) {
			// Catch \Throwable (not just \Exception) so PHP 8 TypeError/Error
			// doesn't kill the process silently without any log entry.
			PRAutoBlogger_Logger::instance()->error(
				sprintf( 'Image pipeline %s for post %d: %s', get_class( $e ), $post_id, $e->getMessage() ),
				'publisher'
			);
		}
	}
}
