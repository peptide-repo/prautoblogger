<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * LLM Deep Research source provider — uses a reasoning-capable model to
 * identify trending topics, emerging questions, and content gaps in the niche.
 *
 * Unlike the Reddit provider (which scrapes real-time discussions), this
 * provider leverages an LLM's training knowledge to surface topics that
 * community data might miss: recent research directions, common
 * misconceptions, safety concerns, and underserved content gaps.
 *
 * Findings are stored as Source_Data objects alongside Reddit data so the
 * Content_Analyzer sees both signals when generating article ideas.
 *
 * Triggered by: Source_Collector::collect_from_all_sources() when 'llm_research' is enabled.
 * Dependencies: PRAutoBlogger_OpenRouter_Provider (LLM calls), PRAutoBlogger_Cost_Tracker.
 *
 * @see providers/interface-source-provider.php — Interface this class implements.
 * @see core/class-source-collector.php         — Orchestrates collection from all providers.
 * @see core/class-content-analyzer.php         — Consumes research findings for idea generation.
 * @see ARCHITECTURE.md                         — Data flow step 1.
 */
class PRAutoBlogger_LLM_Research_Provider implements PRAutoBlogger_Source_Provider_Interface {

	private const SOURCE_TYPE = 'llm_research';

	/**
	 * Collect research findings by sending the configured prompt to an LLM.
	 *
	 * Sends the system prompt + user research prompt to the selected model,
	 * parses the JSON response, and returns each finding as a Source_Data object.
	 * Cost is tracked via the shared Cost_Tracker.
	 *
	 * Side effects: LLM API call via OpenRouter, cost logging.
	 *
	 * @param array{prompt?: string, model?: string, cost_tracker?: PRAutoBlogger_Cost_Tracker} $config Research-specific configuration.
	 * @return PRAutoBlogger_Source_Data[] Array of research findings as source data.
	 * @throws \RuntimeException On API error after retries.
	 */
	public function collect_data( array $config ): array {
		$model  = $config['model'] ?? get_option( 'prautoblogger_research_model', PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL );
		$prompt = $config['prompt'] ?? get_option( 'prautoblogger_research_prompt', '' );
		$niche  = get_option( 'prautoblogger_niche_description', '' );

		if ( '' === trim( $prompt ) ) {
			PRAutoBlogger_Logger::instance()->warning( 'LLM Research prompt is empty. Skipping.', 'llm-research' );
			return array();
		}

		$system_prompt = $this->build_system_prompt();
		$user_prompt   = $this->build_user_prompt( $prompt, $niche );

		$llm      = new PRAutoBlogger_OpenRouter_Provider();
		$response = $llm->send_chat_completion(
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
				'temperature'     => 0.7,
				'max_tokens'      => 8000,
				'response_format' => array( 'type' => 'json_object' ),
				'stage'           => 'llm_research',
			)
		);

		// Use the pipeline's cost tracker if provided (for run_id tagging),
		// otherwise create a standalone one (backward compat for manual calls).
		$cost_tracker = $config['cost_tracker'] ?? new PRAutoBlogger_Cost_Tracker();
		$cost_tracker->log_api_call(
			null,
			'llm_research',
			$llm->get_provider_name(),
			$response['model'],
			$response['prompt_tokens'],
			$response['completion_tokens']
		);

		return $this->parse_findings( $response['content'], $response['model'] );
	}

	/**
	 * @return string
	 */
	public function get_source_type(): string {
		return self::SOURCE_TYPE;
	}

	/**
	 * Validate credentials by delegating to the OpenRouter provider.
	 *
	 * @return bool
	 */
	public function validate_credentials(): bool {
		return ( new PRAutoBlogger_OpenRouter_Provider() )->validate_credentials();
	}

	/**
	 * LLM research has no rate limits beyond the OpenRouter provider's own.
	 *
	 * @return array{remaining: int, limit: int, resets_at: string}
	 */
	public function get_rate_limit_status(): array {
		return array(
			'remaining' => 999,
			'limit'     => 999,
			'resets_at' => '',
		);
	}

	/**
	 * Build the system prompt for the research LLM.
	 *
	 * @return string
	 */
	private function build_system_prompt(): string {
		// v0.18.0: renders from the versioned registry ('research.system')
		// with a byte-identical in-code fallback.
		return PRAutoBlogger_Prompt_Registry::render( 'research.system' );
	}

	/**
	 * Build the user prompt by injecting the niche into the configured template.
	 *
	 * @param string $prompt_template User-configured research prompt.
	 * @param string $niche           Niche description from settings.
	 * @return string
	 */
	private function build_user_prompt( string $prompt_template, string $niche ): string {
		$niche_label = '' !== $niche ? $niche : 'your domain';
		return str_replace( '{niche}', $niche_label, $prompt_template );
	}

	/**
	 * Parse the LLM's JSON response into Source_Data objects.
	 *
	 * @param string $content Raw LLM response (JSON).
	 * @param string $model   Model that produced the response (stored as author).
	 * @return PRAutoBlogger_Source_Data[]
	 */
	private function parse_findings( string $content, string $model ): array {
		$data = PRAutoBlogger_Json_Extractor::decode( $content );

		if ( ! isset( $data['findings'] ) || ! is_array( $data['findings'] ) ) {
			PRAutoBlogger_Logger::instance()->error(
				'LLM Research response was not valid JSON or missing findings key. Raw (first 500 chars): '
				. mb_substr( $content, 0, 500 ),
				'llm-research'
			);
			return array();
		}

		$items    = array();
		$run_date = gmdate( 'Y-m-d' );

		foreach ( $data['findings'] as $index => $finding ) {
			$title    = sanitize_text_field( $finding['title'] ?? '' );
			$body     = sanitize_textarea_field( $finding['content'] ?? '' );
			$score    = min( 100, max( 0, (int) ( $finding['relevance_score'] ?? 50 ) ) );
			$category = sanitize_text_field( $finding['category'] ?? 'guide' );

			if ( '' === $title || '' === $body ) {
				continue;
			}

			// source_id uses date + index so the same day's run deduplicates
			// but a new day produces fresh entries.
			$source_id = 'llm_' . $run_date . '_' . $index;

			$items[] = new PRAutoBlogger_Source_Data(
				array(
					'source_type'   => self::SOURCE_TYPE,
					'source_id'     => $source_id,
					'subreddit'     => null,
					'title'         => $title,
					'content'       => $body,
					'author'        => $model,
					'score'         => $score,
					'comment_count' => 0,
					'permalink'     => null,
					'collected_at'  => current_time( 'mysql' ),
					'metadata'      => array(
						'category'    => $category,
						'data_source' => 'llm_research',
						'model'       => $model,
					),
				)
			);
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'LLM Research produced %d findings via %s.', count( $items ), $model ),
			'llm-research'
		);

		return $items;
	}
}
