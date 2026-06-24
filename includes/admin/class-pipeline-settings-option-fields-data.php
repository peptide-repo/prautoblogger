<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Raw field-array data for PRAutoBlogger_Pipeline_Settings_Option_Fields.
 *
 * What: Declarative arrays of field definitions per step context. Extracted
 *       from Option_Fields to satisfy the 300-line/file rule. Contains no
 *       logic — only data. Option keys are the same wp_option names that were
 *       previously registered in the retired AI Models, Content, and Sources
 *       Settings tabs. P2b.5 adds curate, seo, and authority contexts (data in
 *       class-pipeline-settings-option-fields-data-authority.php per 300-line rule),
 *       and inserts new fields into the research and editorial contexts.
 * Who calls it: PRAutoBlogger_Pipeline_Settings_Option_Fields::get_fields_for_context().
 * Dependencies: WP i18n helpers (__) only;
 *               PRAutoBlogger_Pipeline_Settings_Option_Fields_Data_Authority (curate/seo/authority).
 *
 * @see admin/class-pipeline-settings-option-fields.php          — Public API wrapper + sanitizer.
 * @see admin/class-pipeline-settings-option-fields-data-authority.php — Authority/curate/seo fields.
 * @see CONVENTIONS.md §Retired Settings Tabs                    — retirement pattern.
 * @see CONVENTIONS.md §Authority pipeline options               — naming pattern for Authority controls.
 */
class PRAutoBlogger_Pipeline_Settings_Option_Fields_Data {

	/**
	 * Route a context string to its field definitions array.
	 *
	 * @param string $context One of: global|research|analysis|writer|editorial|curate|seo|authority.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_for( string $context ): array {
		switch ( $context ) {
			case 'global':
				return self::global_fields();
			case 'research':
				return self::research_fields();
			case 'analysis':
				return self::analysis_fields();
			case 'writer':
				return self::writer_fields();
			case 'editorial':
				return self::editorial_fields();
			case 'curate':
			case 'seo':
			case 'authority':
				return PRAutoBlogger_Pipeline_Settings_Option_Fields_Data_Authority::fields_for( $context );
			default:
				return array();
		}
	}

	/**
	 * Global context: niche description fed to all pipeline stages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function global_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_niche_description',
				'label'       => __( 'Niche Description', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Describe your site\'s niche. Passed to every pipeline stage as context.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Research step context fields (moved from Sources Settings tab).
	 * P2b.5: prepends Research Agent Count for the Authority fan-out.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function research_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_research_agent_count',
				'label'       => __( 'Research Agent Count', 'prautoblogger' ),
				'type'        => 'number',
				'default'     => 3,
				'min'         => 1,
				'max'         => 5,
				'description' => __( 'Number of specialist research agents in the Authority fan-out (1–5). Default 3. Economy tier ignores this setting.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_enabled_sources',
				'label'       => __( 'Enabled Sources', 'prautoblogger' ),
				'type'        => 'checkboxes',
				'choices'     => array(
					'reddit'       => __( 'Reddit (RSS + .json)', 'prautoblogger' ),
					'llm_research' => __( 'LLM Deep Research (reasoning model)', 'prautoblogger' ),
				),
				'default'     => '["reddit"]',
				'description' => __( 'Select which platforms to monitor for topics.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_target_subreddits',
				'label'       => __( 'Target Subreddits', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Comma-separated, without r/. E.g.: peptides, Nootropics, biohackers', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_reddit_time_filter',
				'label'       => __( 'Reddit Time Window', 'prautoblogger' ),
				'type'        => 'select',
				'default'     => 'day',
				'options'     => array(
					'day'   => __( 'Past 24 hours', 'prautoblogger' ),
					'week'  => __( 'Past week', 'prautoblogger' ),
					'month' => __( 'Past month', 'prautoblogger' ),
				),
				'description' => __( 'How far back to search for trending posts.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_reddit_posts_per_subreddit',
				'label'       => __( 'Posts per Subreddit', 'prautoblogger' ),
				'type'        => 'number',
				'default'     => 25,
				'min'         => 5,
				'max'         => 100,
				'description' => __( 'Maximum posts to fetch per subreddit per collection run.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_pullpush_cache_ttl',
				'label'       => __( 'Research Cache (hours)', 'prautoblogger' ),
				'type'        => 'number',
				'default'     => 6,
				'min'         => 1,
				'max'         => 72,
				'description' => __( 'How long to cache Reddit research results before re-fetching.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_research_prompt',
				'label'       => __( 'Research Prompt', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'The research brief sent to the LLM. Use {niche} as a placeholder for your niche description.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Analysis step context fields (moved from Content Settings tab).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function analysis_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_analysis_instructions',
				'label'       => __( 'Analysis Instructions', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Custom instructions for the topic analysis LLM.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_topic_exclusions',
				'label'       => __( 'Topic Exclusions', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Comma-separated topics to never write about.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Writer step context fields (moved from AI Models + Content Settings tabs).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function writer_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_writing_pipeline',
				'label'       => __( 'Writing Pipeline', 'prautoblogger' ),
				'type'        => 'select',
				'default'     => 'multi_step',
				'options'     => array(
					'multi_step'  => __( 'Multi-step (outline → draft → polish)', 'prautoblogger' ),
					'single_pass' => __( 'Single-pass (one LLM call)', 'prautoblogger' ),
				),
				'description' => __( 'Controls which writer sub-stage prompts are active.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_tone',
				'label'       => __( 'Content Tone', 'prautoblogger' ),
				'type'        => 'select',
				'default'     => 'informational',
				'options'     => array(
					'informational'  => __( 'Informational', 'prautoblogger' ),
					'conversational' => __( 'Conversational', 'prautoblogger' ),
					'professional'   => __( 'Professional', 'prautoblogger' ),
					'casual'         => __( 'Casual', 'prautoblogger' ),
					'authoritative'  => __( 'Authoritative', 'prautoblogger' ),
				),
			),
			array(
				'id'      => 'prautoblogger_min_word_count',
				'label'   => __( 'Min Word Count', 'prautoblogger' ),
				'type'    => 'number',
				'default' => 800,
				'min'     => 200,
			),
			array(
				'id'      => 'prautoblogger_max_word_count',
				'label'   => __( 'Max Word Count', 'prautoblogger' ),
				'type'    => 'number',
				'default' => 2000,
				'min'     => 500,
			),
			array(
				'id'          => 'prautoblogger_writing_instructions',
				'label'       => __( 'Writing Instructions', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Custom instructions appended to the writing LLM system prompt.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_reasoning_enabled',
				'label'       => __( 'Enable Reasoning', 'prautoblogger' ),
				'type'        => 'toggle',
				'default'     => '0',
				'description' => __( 'Send reasoning instructions to models that support it. Reasoning tokens are billed as output tokens.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_reasoning_effort',
				'label'       => __( 'Reasoning Effort', 'prautoblogger' ),
				'type'        => 'select',
				'default'     => 'medium',
				'options'     => array(
					'xhigh'   => __( 'Extra High — maximum depth, highest cost', 'prautoblogger' ),
					'high'    => __( 'High — thorough reasoning', 'prautoblogger' ),
					'medium'  => __( 'Medium — balanced (recommended)', 'prautoblogger' ),
					'low'     => __( 'Low — light reasoning, lower cost', 'prautoblogger' ),
					'minimal' => __( 'Minimal — barely any reasoning', 'prautoblogger' ),
				),
				'description' => __( 'Only applies when reasoning is enabled.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Editorial step context fields (moved from Content Settings tab).
	 * P2b.5: prepends Max Editorial Rounds for the Authority bounded loop.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function editorial_fields(): array {
		return array(
			array(
				'id'          => 'prautoblogger_editorial_max_rounds',
				'label'       => __( 'Max Editorial Rounds', 'prautoblogger' ),
				'type'        => 'number',
				'default'     => 3,
				'min'         => 1,
				'max'         => 5,
				'description' => __( 'Maximum editor↔writer iterations in the Authority bounded loop. Default 3. Economy tier ignores this setting.', 'prautoblogger' ),
			),
			array(
				'id'          => 'prautoblogger_editor_instructions',
				'label'       => __( 'Editor Instructions', 'prautoblogger' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Custom instructions for the chief editor LLM review pass.', 'prautoblogger' ),
			),
		);
	}
}
