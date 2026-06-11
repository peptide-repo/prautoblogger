<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Builds all LLM prompts used by the content generation pipeline.
 *
 * Centralises prompt construction so Content_Generator stays lean and
 * prompt logic (system prompt, stage prompts, linking rules) can evolve
 * independently of the generation orchestration.
 *
 * Triggered by: PRAutoBlogger_Content_Generator (called for every stage).
 * Dependencies: PRAutoBlogger_Prompt_Registry (versioned prompt bodies),
 *               WordPress query functions (get_posts, get_the_title, get_permalink).
 *
 * @see core/class-content-generator.php — Consumer of these prompts.
 * @see models/class-content-request.php — Data bag passed to every builder.
 */
class PRAutoBlogger_Content_Prompts {

	/**
	 * Build the system prompt shared across all writing stages.
	 *
	 * Includes niche, tone, mandatory style guide, internal link reference,
	 * peptide database links, and the "never fabricate URLs" rule.
	 *
	 * v0.18.0: the prompt copy renders from the versioned registry
	 * ('content.system', falls back to the identical in-code default);
	 * the style guide and linking rules are data injection and stay here.
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @return string Complete system prompt.
	 */
	public static function build_system( PRAutoBlogger_Content_Request $request ): string {
		$niche  = $request->get_niche_description();
		$prompt = PRAutoBlogger_Prompt_Registry::render(
			'content.system',
			array(
				'niche_clause' => '' !== $niche ? " specializing in {$niche}" : '',
				'tone'         => $request->get_tone(),
			)
		);

		// Append user-defined writing instructions as a mandatory style guide.
		$instructions = trim( $request->get_writing_instructions() );
		if ( '' !== $instructions ) {
			$prompt .= "\n\n--- MANDATORY STYLE GUIDE ---\n";
			$prompt .= "You MUST follow every requirement below. These override any conflicting defaults:\n\n";
			$prompt .= $instructions;
			$prompt .= "\n--- END STYLE GUIDE ---";
		}

		$prompt .= "\n\n" . self::build_linking_rules();

		return $prompt;
	}

	/**
	 * Build the user prompt for single-pass generation.
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @return string User prompt.
	 */
	public static function build_single_pass( PRAutoBlogger_Content_Request $request ): string {
		$idea = $request->get_idea();

		return PRAutoBlogger_Prompt_Registry::render(
			'content.single_pass',
			array(
				'title'        => $idea->get_suggested_title(),
				'topic'        => $idea->get_topic(),
				'article_type' => $idea->get_article_type(),
				'key_points'   => implode( "\n- ", $idea->get_key_points() ),
				'keywords'     => implode( ', ', $idea->get_target_keywords() ),
				'min_words'    => (string) $request->get_min_word_count(),
				'max_words'    => (string) $request->get_max_word_count(),
			)
		);
	}

	/**
	 * Build the outline stage prompt (multi-step stage 1).
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @return string Outline prompt.
	 */
	public static function build_outline( PRAutoBlogger_Content_Request $request ): string {
		$idea = $request->get_idea();

		return PRAutoBlogger_Prompt_Registry::render(
			'content.outline',
			array(
				'title'        => $idea->get_suggested_title(),
				'topic'        => $idea->get_topic(),
				'article_type' => $idea->get_article_type(),
				'key_points'   => implode( "\n- ", $idea->get_key_points() ),
				'keywords'     => implode( ', ', $idea->get_target_keywords() ),
				'min_words'    => (string) $request->get_min_word_count(),
				'max_words'    => (string) $request->get_max_word_count(),
			)
		);
	}

	/**
	 * Build the draft stage prompt (multi-step stage 2).
	 *
	 * @param PRAutoBlogger_Content_Request $request Content request with settings.
	 * @param string                        $outline The outline from stage 1.
	 * @return string Draft prompt.
	 */
	public static function build_draft( PRAutoBlogger_Content_Request $request, string $outline ): string {
		return PRAutoBlogger_Prompt_Registry::render(
			'content.draft',
			array(
				'outline'   => $outline,
				'tone'      => $request->get_tone(),
				'min_words' => (string) $request->get_min_word_count(),
				'max_words' => (string) $request->get_max_word_count(),
				'keywords'  => implode( ', ', $request->get_idea()->get_target_keywords() ),
			)
		);
	}

	/**
	 * Build the polish stage prompt (multi-step stage 3).
	 *
	 * @param string $draft The draft from stage 2.
	 * @return string Polish prompt.
	 */
	public static function build_polish( string $draft ): string {
		return PRAutoBlogger_Prompt_Registry::render(
			'content.polish',
			array( 'draft' => $draft )
		);
	}

	/**
	 * Build the linking rules section (internal articles + peptide database).
	 *
	 * Fetches published blog posts and peptide pages, formats them as a
	 * reference list so the model uses real URLs instead of fabricating them.
	 *
	 * @return string Complete linking rules block.
	 */
	private static function build_linking_rules(): string {
		$rules  = "--- LINKING RULES ---\n";
		$rules .= "NEVER fabricate or invent URLs. You do NOT have access to external sources.\n";
		$rules .= "Only use the internal links listed below when linking within the article.\n";
		$rules .= "If no listed article is relevant to a section, do not insert a link.\n";
		$rules .= "Do NOT add links for peptide names — those are injected automatically after generation.\n\n";

		$rules .= self::build_article_links();

		$rules .= '--- END LINKING RULES ---';

		return $rules;
	}

	/**
	 * Build a reference list of published blog posts for internal linking.
	 *
	 * @return string Formatted list, or a fallback note.
	 */
	private static function build_article_links(): string {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return "No published articles available for internal linking yet.\n";
		}

		$lines = array( "Available article links (use where topically relevant):\n" );
		foreach ( $posts as $post_id ) {
			$title = get_the_title( $post_id );
			$url   = get_permalink( $post_id );
			if ( $title && $url ) {
				$lines[] = "- {$title}: {$url}";
			}
		}

		return implode( "\n", $lines ) . "\n";
	}
}
