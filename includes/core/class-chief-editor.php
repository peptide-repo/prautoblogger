<?php
declare(strict_types=1);

/**
 * Chief Editor agent — LLM-powered editorial review.
 *
 * Reviews generated articles for quality, accuracy, SEO, tone.
 * Approves, requests revisions, or rejects content.
 * Opik instrumentation: spans per review LLM call.
 *
 * Triggered by: PRAutoBlogger_Article_Worker.
 * Dependencies: LLM_Provider_Interface, Cost_Tracker, Prompt_Registry.
 */
class PRAutoBlogger_Chief_Editor {

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
	 * Review generated content and return an editorial verdict.
	 *
	 * @param string                     $content The generated HTML content.
	 * @param PRAutoBlogger_Article_Idea $idea    The original article idea.
	 * @return PRAutoBlogger_Editorial_Review
	 */
	public function review( string $content, PRAutoBlogger_Article_Idea $idea ): PRAutoBlogger_Editorial_Review {
		$model = get_option( 'prautoblogger_editor_model', PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL );
		$niche = get_option( 'prautoblogger_niche_description', '' );

		$system_prompt = $this->build_system_prompt( $niche );
		$user_prompt   = $this->build_review_prompt( $content, $idea );

		$ctx = PRAutoBlogger_Opik_Trace_Context::current();
		$span_id = $ctx->start_span(
			array(
				'name'     => 'editorial_review',
				'type'     => 'llm',
				'model'    => $model,
				'provider' => 'openrouter',
			)
		);

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
				'temperature'     => 0.3,
				'max_tokens'      => 5000,
				'response_format' => array( 'type' => 'json_object' ),
			)
		);

		$ctx->end_span(
			$span_id,
			array(
				'usage' => array(
					'prompt_tokens'     => $response['prompt_tokens'],
					'completion_tokens' => $response['completion_tokens'],
					'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
				),
			)
		);

		$this->cost_tracker->log_api_call(
			null,
			'review',
			$this->llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		return $this->parse_review_response( $response['content'] );
	}

	/**
	 * Build system prompt for editorial review.
	 *
	 * v0.18.0: renders from the versioned registry ('editor.system') with a
	 * byte-identical in-code fallback; the admin's extra editor
	 * instructions stay a setting and are injected as a token value.
	 *
	 * @param string $niche Niche description from settings.
	 * @return string System prompt text.
	 */
	private function build_system_prompt( string $niche ): string {
		$instructions       = trim( (string) get_option( 'prautoblogger_editor_instructions', '' ) );
		$instructions_block = '' !== $instructions ? "\nAdditional instructions:\n" . $instructions . "\n" : '';

		return PRAutoBlogger_Prompt_Registry::render(
			'editor.system',
			array(
				'niche_clause'       => '' !== $niche ? " specializing in {$niche} content" : '',
				'instructions_block' => $instructions_block,
			)
		);
	}

	/**
	 * Build user prompt for editorial review.
	 *
	 * v0.18.0: renders from the versioned registry ('editor.review').
	 *
	 * @param string                     $content The generated HTML content.
	 * @param PRAutoBlogger_Article_Idea $idea    The original article idea.
	 * @return string User prompt text.
	 */
	private function build_review_prompt( string $content, PRAutoBlogger_Article_Idea $idea ): string {
		return PRAutoBlogger_Prompt_Registry::render(
			'editor.review',
			array(
				'title'        => $idea->get_suggested_title(),
				'topic'        => $idea->get_topic(),
				'article_type' => $idea->get_article_type(),
				'key_points'   => implode( ', ', $idea->get_key_points() ),
				'keywords'     => implode( ', ', $idea->get_target_keywords() ),
				'content'      => $content,
			)
		);
	}

	/**
	 * Parse LLM editorial review response.
	 */
	private function parse_review_response( string $content ): PRAutoBlogger_Editorial_Review {
		$data = PRAutoBlogger_Json_Extractor::decode( $content );

		if ( ! is_array( $data ) || ! isset( $data['verdict'] ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'Chief editor response unparseable (first 200 chars): ' . mb_substr( $content, 0, 200 ),
				'editor'
			);
			return new PRAutoBlogger_Editorial_Review(
				array(
					'verdict'       => 'rejected',
					'notes'         => 'Editor response unparseable',
					'quality_score' => 0.0,
					'seo_score'     => 0.0,
					'issues'        => array( 'Unparseable editor response' ),
				)
			);
		}

		$verdict = sanitize_text_field( $data['verdict'] ?? 'rejected' );
		if ( ! in_array( $verdict, array( 'approved', 'revised', 'rejected' ), true ) ) {
			$verdict = 'rejected';
		}

		return new PRAutoBlogger_Editorial_Review(
			array(
				'verdict'         => $verdict,
				'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ),
				'revised_content' => isset( $data['revised_content'] ) ? wp_kses_post( $data['revised_content'] ) : null,
				'quality_score'   => (float) ( $data['quality_score'] ?? 0.0 ),
				'seo_score'       => (float) ( $data['seo_score'] ?? 0.0 ),
				'issues'          => array_map( 'sanitize_text_field', $data['issues'] ?? array() ),
			)
		);
	}
}
