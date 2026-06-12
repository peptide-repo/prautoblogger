<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Creates WordPress posts from generated and editor-approved content.
 *
 * Handles both publishing (editor-approved) and saving as draft (editor-rejected).
 * Stores all generation metadata as post_meta for transparency and auditability.
 *
 * Triggered by: PRAutoBlogger_Pipeline_Runner (step 6).
 * Dependencies: PRAutoBlogger_Post_Assembler (taxonomy, images, sanitization),
 *               WordPress wp_insert_post(), post meta API.
 *
 * @see core/class-post-assembler.php   — Post-creation helpers (taxonomy, images, logs).
 * @see core/class-peptide-linker.php   — Injects peptide database hyperlinks into content.
 * @see core/class-chief-editor.php     — Produces the editorial review we consume.
 * @see core/class-content-generator.php — Produces the content we publish.
 * @see ARCHITECTURE.md                  — Data flow step 6.
 */
class PRAutoBlogger_Publisher {

	/**
	 * Publish an editor-approved article.
	 *
	 * @param string                        $content The final HTML content.
	 * @param PRAutoBlogger_Article_Idea      $idea    The original article idea.
	 * @param PRAutoBlogger_Editorial_Review  $review  The editor's review.
	 * @param string|null                   $run_id  Pipeline run ID for log linking.
	 * @param ?PRAutoBlogger_Cost_Tracker $cost_tracker Optional cost tracker for image generation cost logging.
	 * @return int The created post ID.
	 * @throws \RuntimeException If post creation fails.
	 */
	public function publish(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		?string $run_id = null,
		?PRAutoBlogger_Cost_Tracker $cost_tracker = null
	): int {
		return $this->create_post( $content, $idea, $review, 'publish', $run_id, $cost_tracker );
	}

	/**
	 * Save an editor-rejected article as a draft for human review.
	 *
	 * @param string                        $content The generated HTML content (pre-revision).
	 * @param PRAutoBlogger_Article_Idea      $idea    The original article idea.
	 * @param PRAutoBlogger_Editorial_Review  $review  The editor's review with rejection notes.
	 * @param string|null                   $run_id  Pipeline run ID for log linking.
	 * @param ?PRAutoBlogger_Cost_Tracker $cost_tracker Optional cost tracker for image generation cost logging.
	 * @return int The created post ID.
	 * @throws \RuntimeException If post creation fails.
	 */
	public function save_as_draft(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		?string $run_id = null,
		?PRAutoBlogger_Cost_Tracker $cost_tracker = null
	): int {
		return $this->create_post( $content, $idea, $review, 'draft', $run_id, $cost_tracker );
	}

	/**
	 * Create a WordPress post with generation metadata.
	 *
	 * @param string                        $content     HTML content.
	 * @param PRAutoBlogger_Article_Idea      $idea        Article idea.
	 * @param PRAutoBlogger_Editorial_Review  $review      Editorial review.
	 * @param string                        $post_status 'publish' or 'draft'.
	 * @param string|null                   $run_id      Pipeline run ID for log linking.
	 * @return int Post ID.
	 * @throws \RuntimeException If wp_insert_post fails.
	 */
	private function create_post(
		string $content,
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		string $post_status,
		?string $run_id = null,
		?PRAutoBlogger_Cost_Tracker $cost_tracker = null
	): int {
		// v0.18.1 belt-and-braces: never create a post whose content is
		// empty once tags and whitespace are stripped — an empty "draft
		// for human review" (2026-06-11 incident, post 921) reviews
		// nothing and hides the underlying generation failure. The
		// provider/writer guards make this unreachable on OpenRouter
		// paths; this protects every other path into post creation.
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: intended post status, 2: article title. */
					__( 'Refusing to create %1$s post "%2$s": generated content is empty.', 'prautoblogger' ),
					$post_status,
					$idea->get_suggested_title()
				)
			);
		}

		// v0.18.0 idempotency: post creation is keyed by run + idea
		// (check-before-insert) so a retried/resumed run cannot create a
		// duplicate post. One run_id spans all N articles of a batch, so
		// the idea hash disambiguates within the run.
		$idea_hash = PRAutoBlogger_Run_Stage_State::idea_hash( $idea );
		if ( null !== $run_id && '' !== $run_id ) {
			$existing_id = $this->find_existing_post( $run_id, $idea_hash );
			if ( $existing_id > 0 ) {
				// v0.20.0: a re-run's regenerated content must land on the
				// existing UNPUBLISHED post (otherwise re-runs silently
				// discard their output). Published posts stay frozen
				// (CPO guardrail 5) — refresh skips them untouched.
				return $this->refresh_unpublished_post( $existing_id, $content, $idea, $review );
			}
		}

		// Clean LLM artifacts, then inject peptide hyperlinks deterministically
		// before the content enters WordPress. Peptide linker no-ops gracefully
		// if PR Core is not active.
		$clean_content  = PRAutoBlogger_Post_Assembler::sanitize_llm_content( $content );
		$linked_content = PRAutoBlogger_Peptide_Linker::inject_links( $clean_content );

		$post_data = array(
			'post_title'   => sanitize_text_field( $idea->get_suggested_title() ),
			'post_content' => wp_kses_post( $linked_content ),
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_author'  => PRAutoBlogger_Post_Assembler::get_default_author_id(),
			'meta_input'   => $this->build_meta( $idea, $review, $run_id, $idea_hash ),
		);

		/** @see class-prautoblogger.php — listeners registered in main loader. */
		$post_data = apply_filters( 'prautoblogger_filter_post_data', $post_data, $idea, $review );

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException(
				sprintf( __( 'Failed to create post: %s', 'prautoblogger' ), $post_id->get_error_message() )
			);
		}

		PRAutoBlogger_Post_Assembler::assign_taxonomy_terms( $post_id, $idea );
		PRAutoBlogger_Post_Assembler::link_generation_logs( $post_id, $run_id );

		if ( 'publish' === $post_status ) {
			PRAutoBlogger_Post_Assembler::attach_generated_images( $post_id, $idea, $post_data, $cost_tracker );
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Post created: ID=%d, status=%s, title="%s"', $post_id, $post_status, $idea->get_suggested_title() ),
			'publisher'
		);

		do_action( 'prautoblogger_post_created', $post_id, $post_status, $idea, $review );

		return $post_id;
	}

	/**
	 * Refresh an existing post from re-run output — unpublished only.
	 *
	 * Idempotent-resume calls land here too (same content in = same
	 * content out, so behavior is unchanged for plain resumes). The post
	 * keeps its CURRENT status: a re-run never publishes an unpublished
	 * post — publication stays an explicit Review Queue / WP editor
	 * action. Published (and scheduled/private) posts are returned
	 * untouched: the run is frozen (CPO guardrail 5).
	 *
	 * Side effects: wp_update_post + post meta updates (unpublished only).
	 *
	 * @param int                            $post_id Existing post ID.
	 * @param string                         $content Regenerated HTML content.
	 * @param PRAutoBlogger_Article_Idea     $idea    The idea.
	 * @param PRAutoBlogger_Editorial_Review $review  The fresh review.
	 * @return int The post ID.
	 */
	private function refresh_unpublished_post( int $post_id, string $content, PRAutoBlogger_Article_Idea $idea, PRAutoBlogger_Editorial_Review $review ): int {
		$post = get_post( $post_id );
		if ( PRAutoBlogger_Rerun_Eligibility::post_frozen( $post ) ) {
			PRAutoBlogger_Logger::instance()->info(
				sprintf( 'Post %d already exists and is published — frozen, skipping content refresh.', $post_id ),
				'publisher'
			);
			return $post_id;
		}

		// Same sanitize chain as create_post().
		$clean_content  = PRAutoBlogger_Post_Assembler::sanitize_llm_content( $content );
		$linked_content = PRAutoBlogger_Peptide_Linker::inject_links( $clean_content );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( $idea->get_suggested_title() ),
				'post_content' => wp_kses_post( $linked_content ),
			)
		);

		update_post_meta( $post_id, '_prautoblogger_editor_verdict', $review->get_verdict() );
		update_post_meta( $post_id, '_prautoblogger_editor_notes', $review->get_notes() );
		update_post_meta( $post_id, '_prautoblogger_quality_score', $review->get_quality_score() );
		update_post_meta( $post_id, '_prautoblogger_seo_score', $review->get_seo_score() );
		update_post_meta( $post_id, '_prautoblogger_generated_at', gmdate( 'c' ) );

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Post %d refreshed from re-run output (status preserved: %s).', $post_id, (string) ( $post->post_status ?? '' ) ),
			'publisher'
		);

		return $post_id;
	}

	/**
	 * Build post_meta array for generation metadata.
	 *
	 * The `_prautoblogger_run_id` key (added v0.8.1) lets the orphan-research
	 * reaper attribute an orphan `llm_research` row back to its sibling
	 * articles without re-walking the gen_log table. See
	 * core/class-research-reaper.php.
	 *
	 * @param PRAutoBlogger_Article_Idea     $idea
	 * @param PRAutoBlogger_Editorial_Review $review
	 * @param string|null                    $run_id Pipeline run UUID, or null in legacy paths.
	 * @return array<string, mixed>
	 */
	private function build_meta(
		PRAutoBlogger_Article_Idea $idea,
		PRAutoBlogger_Editorial_Review $review,
		?string $run_id = null,
		string $idea_hash = ''
	): array {
		$meta = array(
			'_prautoblogger_generated'       => '1',
			'_prautoblogger_analysis_id'     => $idea->get_analysis_id(),
			'_prautoblogger_source_ids'      => wp_json_encode( $idea->get_source_ids() ),
			'_prautoblogger_model_used'      => get_option( 'prautoblogger_writing_model', PRAUTOBLOGGER_DEFAULT_WRITING_MODEL ),
			'_prautoblogger_pipeline_mode'   => get_option( 'prautoblogger_writing_pipeline', 'multi_step' ),
			'_prautoblogger_editor_verdict'  => $review->get_verdict(),
			'_prautoblogger_editor_notes'    => $review->get_notes(),
			'_prautoblogger_quality_score'   => $review->get_quality_score(),
			'_prautoblogger_seo_score'       => $review->get_seo_score(),
			'_prautoblogger_generated_at'    => gmdate( 'c' ),
			'_prautoblogger_topic'           => $idea->get_topic(),
			'_prautoblogger_article_type'    => $idea->get_article_type(),
			'_prautoblogger_target_keywords' => wp_json_encode( $idea->get_target_keywords() ),
		);
		if ( null !== $run_id && '' !== $run_id ) {
			$meta['_prautoblogger_run_id'] = $run_id;
		}
		if ( '' !== $idea_hash ) {
			$meta['_prautoblogger_idea_hash'] = $idea_hash;
		}
		return $meta;
	}

	/**
	 * Find a post already created for this (run, idea) pair.
	 *
	 * Direct postmeta self-join (any post_status, including trash) — the
	 * idempotency check must see drafts and pending posts too.
	 *
	 * @param string $run_id    Pipeline run UUID.
	 * @param string $idea_hash Idea hash from Run_Stage_State::idea_hash().
	 * @return int Existing post ID, or 0 when none.
	 */
	private function find_existing_post( string $run_id, string $idea_hash ): int {
		global $wpdb;
		if ( null === $wpdb ) {
			return 0;
		}
		$postmeta = $wpdb->prefix . 'postmeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm_run.post_id FROM {$postmeta} pm_run
				INNER JOIN {$postmeta} pm_hash
					ON pm_hash.post_id = pm_run.post_id
					AND pm_hash.meta_key = '_prautoblogger_idea_hash'
					AND pm_hash.meta_value = %s
				WHERE pm_run.meta_key = '_prautoblogger_run_id' AND pm_run.meta_value = %s
				LIMIT 1",
				$idea_hash,
				$run_id
			)
		);
		return null !== $post_id ? (int) $post_id : 0;
	}
}
