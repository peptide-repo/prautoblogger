<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Extended settings fields: schedule, publishing, analytics, display, and images.
 *
 * What: Declarative field definitions for operational settings sections.
 * Who calls it: PRAutoBlogger_Settings_Fields::get_fields() merges these in.
 * Dependencies: PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL, PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE constants,
 *               PRAutoBlogger_Article_Typography (font choices), PRAutoBlogger_Image_Model_Registry.
 *
 * @see admin/class-settings-fields.php       — Core fields and sections; calls get_extended_fields().
 * @see admin/class-admin-page.php            — Renders fields registered from these definitions.
 * @see admin/class-image-model-registry.php  — Static image model list extracted for 300-line compliance.
 * @see frontend/class-article-typography.php — Reads Display settings on the frontend.
 * @see CONVENTIONS.md                        — "How To: Add a New Admin Setting".
 */
class PRAutoBlogger_Settings_Fields_Extended {

	/**
	 * Static image model list for the admin model picker.
	 * Delegates to Image_Model_Registry (extracted for 300-line file limit).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_image_models(): array {
		return PRAutoBlogger_Image_Model_Registry::get_models();
	}

	/**
	 * Get schedule, publishing, analytics, and image settings fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields(): array {
		return array_merge(
			array(
				// ── Sources (extended) ──────────────────────────────────────
				array(
					'id'          => 'prautoblogger_research_model',
					'label'       => __( 'Research Model', 'prautoblogger' ),
					'type'        => 'model_select',
					'section'     => 'prautoblogger_sources',
					'default'     => PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL,
					'capability'  => 'text→text',
					'description' => __( 'Model for LLM Deep Research. Pick a reasoning-capable model (e.g. Grok 4.1 Fast, DeepSeek-R1) for best results. Only used when LLM Deep Research is enabled above.', 'prautoblogger' ),
					'badge'       => __( 'Reasoning', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_research_prompt',
					'label'       => __( 'Research Prompt', 'prautoblogger' ),
					'type'        => 'textarea',
					'section'     => 'prautoblogger_sources',
					'default'     => 'Conduct a deep research sweep of the {niche} space and identify the most substantive, actionable topics that practitioners and curious newcomers are searching for or actively discussing.' . "\n\n" . 'Focus on:' . "\n" . '- Practical questions real readers would search for (not theoretical debates)' . "\n" . '- Emerging trends gaining momentum in the community' . "\n" . '- Common misconceptions that deserve clarification' . "\n" . '- Comparative analysis (product vs. product, protocol vs. protocol)' . "\n" . '- Safety and efficacy topics that drive concern or curiosity' . "\n" . '- Beginner-to-advanced mix — cover entry-level and depth-focused topics' . "\n\n" . 'Aim for topics that feel timely, underserved by existing content, and likely to drive reader engagement. Return 8-12 findings with detailed analysis in each.',
					'description' => __( 'The research brief sent to the LLM. Use {niche} as a placeholder for your niche description. The system prompt handles output format — this controls WHAT to research.', 'prautoblogger' ),
				),

				// ── AI Models (extended) ────────────────────────────────────
				array(
					'id'          => 'prautoblogger_reasoning_enabled',
					'label'       => __( 'Enable Reasoning', 'prautoblogger' ),
					'type'        => 'toggle',
					'section'     => 'prautoblogger_models',
					'default'     => '0',
					'description' => __( 'Send reasoning instructions to models that support it (e.g. Grok, DeepSeek-R1). Reasoning tokens are billed as output tokens — expect higher costs per call. Models that don\'t support reasoning will ignore this.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_reasoning_effort',
					'label'       => __( 'Reasoning Effort', 'prautoblogger' ),
					'type'        => 'select',
					'section'     => 'prautoblogger_models',
					'default'     => 'medium',
					'options'     => array(
						'xhigh'   => __( 'Extra High — maximum depth, highest cost', 'prautoblogger' ),
						'high'    => __( 'High — thorough reasoning', 'prautoblogger' ),
						'medium'  => __( 'Medium — balanced (recommended)', 'prautoblogger' ),
						'low'     => __( 'Low — light reasoning, lower cost', 'prautoblogger' ),
						'minimal' => __( 'Minimal — barely any reasoning', 'prautoblogger' ),
					),
					'description' => __( 'How much effort the model spends reasoning before answering. Higher effort = more reasoning tokens = higher cost. Only applies when reasoning is enabled above.', 'prautoblogger' ),
				),

				// ── Board Settings ─────────────────────────────────────────
				array(
					'id'          => 'prautoblogger_board_poll_interval',
					'label'       => __( 'Board Poll Interval (seconds)', 'prautoblogger' ),
					'type'        => 'number',
					'section'     => 'prautoblogger_schedule',
					'default'     => 5,
					'min'         => 3,
					'max'         => 60,
					'description' => __( 'How often the kanban board polls for status updates (seconds). Minimum 3s. Doubles automatically when no article is actively generating.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_board_published_window_days',
					'label'       => __( 'Published Column Window (days)', 'prautoblogger' ),
					'type'        => 'number',
					'section'     => 'prautoblogger_schedule',
					'default'     => 7,
					'min'         => 1,
					'max'         => 90,
					'description' => __( 'How many days back the Published column shows articles. Increase to see older published articles on the board.', 'prautoblogger' ),
				),

				// ── Schedule & Budget ───────────────────────────────────────
				array(
					'id'      => 'prautoblogger_daily_article_target',
					'label'   => __( 'Articles per Day', 'prautoblogger' ),
					'type'    => 'number',
					'section' => 'prautoblogger_schedule',
					'default' => 1,
					'min'     => 1,
					'max'     => 10,
				),
				array(
					'id'          => 'prautoblogger_schedule_time',
					'label'       => __( 'Generation Time', 'prautoblogger' ),
					'type'        => 'time',
					'section'     => 'prautoblogger_schedule',
					'default'     => '03:00',
					'description' => sprintf(
						/* translators: %s: site timezone string, e.g. "Asia/Singapore". */
						esc_html__( "Daily generation runs at this time in your site's configured timezone (Settings → General → Timezone). Current site timezone: %s.", 'prautoblogger' ),
						esc_html( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' )
					),
				),
				array(
					'id'          => 'prautoblogger_monthly_budget_usd',
					'label'       => __( 'Monthly Budget (USD)', 'prautoblogger' ),
					'type'        => 'number',
					'section'     => 'prautoblogger_schedule',
					'default'     => 50,
					'min'         => 0,
					'step'        => '0.01',
					'description' => __( 'Hard stop when reached. 0 = unlimited.', 'prautoblogger' ),
				),

				// ── Publishing ──────────────────────────────────────────────
				array(
					'id'          => 'prautoblogger_auto_publish',
					'label'       => __( 'Auto-Publish', 'prautoblogger' ),
					'type'        => 'toggle',
					'section'     => 'prautoblogger_publishing',
					'default'     => '0',
					'description' => __( 'Automatically publish editor-approved posts. When off, all posts go to the Review Queue as drafts.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_default_author',
					'label'       => __( 'Default Author', 'prautoblogger' ),
					'type'        => 'author_select',
					'section'     => 'prautoblogger_publishing',
					'default'     => 0,
					'description' => __( 'WordPress user assigned as author of generated posts.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_default_category',
					'label'       => __( 'Default Category', 'prautoblogger' ),
					'type'        => 'category_select',
					'section'     => 'prautoblogger_publishing',
					'default'     => 0,
					'description' => __( 'Fallback category for generated posts.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_log_level',
					'label'       => __( 'Log Level', 'prautoblogger' ),
					'type'        => 'select',
					'section'     => 'prautoblogger_publishing',
					'default'     => 'info',
					'options'     => array(
						'error'   => __( 'Error — only failures', 'prautoblogger' ),
						'warning' => __( 'Warning — errors + warnings', 'prautoblogger' ),
						'info'    => __( 'Info — key events (recommended)', 'prautoblogger' ),
						'debug'   => __( 'Debug — everything (verbose)', 'prautoblogger' ),
					),
					'description' => __( 'Controls detail level in the Activity Log.', 'prautoblogger' ),
				),

				// ── Analytics ───────────────────────────────────────────────
				array(
					'id'          => 'prautoblogger_ga4_property_id',
					'label'       => __( 'GA4 Property ID', 'prautoblogger' ),
					'type'        => 'text',
					'section'     => 'prautoblogger_analytics',
					'description' => __( 'Your GA4 Property ID — digits only, e.g. 123456789 (the code adds the "properties/" prefix internally). Find it in GA4 → Admin → Property details. Leave blank to skip GA4.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_ga4_credentials_json',
					'label'       => __( 'Service Account JSON', 'prautoblogger' ),
					'type'        => 'password',
					'section'     => 'prautoblogger_analytics',
					'description' => __( 'Paste the full JSON key file for a service account with Analytics read access.', 'prautoblogger' ),
				),

				// ── Display ────────────────────────────────────────────────
				array(
					'id'          => 'prautoblogger_article_font_family',
					'label'       => __( 'Article Font', 'prautoblogger' ),
					'type'        => 'select',
					'section'     => 'prautoblogger_display',
					'default'     => 'default',
					'options'     => PRAutoBlogger_Article_Typography::get_font_choices(),
					'description' => __( 'Font family used for generated article body text. Serif fonts improve long-form readability.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_article_font_size',
					'label'       => __( 'Article Font Size (px)', 'prautoblogger' ),
					'type'        => 'number',
					'section'     => 'prautoblogger_display',
					'default'     => 0,
					'min'         => 0,
					'max'         => 32,
					'description' => __( 'Base font size for article body text. 0 uses the theme default (13px). Recommended: 16–18px for comfortable reading.', 'prautoblogger' ),
				),
				array(
					'id'          => 'prautoblogger_table_borders',
					'label'       => __( 'Table Borders', 'prautoblogger' ),
					'type'        => 'toggle',
					'section'     => 'prautoblogger_display',
					'default'     => '1',
					'description' => __( 'Add visible borders, padding, and alternating row colors to tables in generated articles.', 'prautoblogger' ),
				),
			),
			PRAutoBlogger_Settings_Fields_Images::get_fields()
		);
	}
}
