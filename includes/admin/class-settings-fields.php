<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Declarative settings fields and sections for the PRAutoBlogger admin page.
 *
 * Centralizes all field and section definitions in one place, making it trivial
 * to add new settings (just add one array entry). Decoupled from page rendering logic.
 * Core fields (API Keys) live here; operational fields (Schedule, Publishing, Analytics,
 * Display, Images) are in Settings_Fields_Extended.
 *
 * M2 change: AI Models, Content, and Sources sections were retired. Those fields
 * are now edited exclusively in Pipeline Settings per-step panels. The underlying
 * wp_options are unchanged — only the UI surface has moved. See CONVENTIONS.md
 * §Retired Settings Tabs for the retirement pattern.
 *
 * Triggered by: PRAutoBlogger_Admin_Page::on_register_settings() calls static methods here.
 * Dependencies: PRAutoBlogger_Settings_Fields_Extended for operational field definitions.
 *
 * @see admin/class-settings-fields-extended.php          — Schedule, publishing, analytics, images.
 * @see admin/class-admin-page.php                        — Calls get_sections() + get_fields().
 * @see admin/class-pipeline-settings-option-fields.php   — Now owns AI Models/Content/Sources fields.
 * @see CONVENTIONS.md §Retired Settings Tabs             — Retirement pattern.
 * @see CONVENTIONS.md §How To: Add a New Admin Setting   — Extension guide.
 */
class PRAutoBlogger_Settings_Fields {

	/**
	 * Get all settings sections. Each section maps to a tab in the admin UI.
	 *
	 * AI Models, Content, and Sources sections were retired in M2 — those fields
	 * moved to Pipeline Settings per-step panels.
	 *
	 * @return array<string, array{title: string, icon: string, description: string}>
	 */
	public static function get_sections(): array {
		return array(
			'prautoblogger_api'        => array(
				'title'       => __( 'API Keys', 'prautoblogger' ),
				'icon'        => 'dashicons-admin-network',
				'description' => __( 'Connect your external services. Keys are encrypted at rest.', 'prautoblogger' ),
			),
			'prautoblogger_schedule'   => array(
				'title'       => __( 'Schedule & Budget', 'prautoblogger' ),
				'icon'        => 'dashicons-calendar-alt',
				'description' => __( 'Set daily generation schedule, volume, and spending limits.', 'prautoblogger' ),
			),
			'prautoblogger_publishing' => array(
				'title'       => __( 'Publishing', 'prautoblogger' ),
				'icon'        => 'dashicons-megaphone',
				'description' => __( 'Control how generated content is published.', 'prautoblogger' ),
			),
			'prautoblogger_analytics'  => array(
				'title'       => __( 'Analytics', 'prautoblogger' ),
				'icon'        => 'dashicons-chart-area',
				'description' => __( 'Connect Google Analytics 4 for post performance scoring.', 'prautoblogger' ),
			),
			'prautoblogger_display'    => array(
				'title'       => __( 'Display', 'prautoblogger' ),
				'icon'        => 'dashicons-editor-textcolor',
				'description' => __( 'Control how generated articles look on the frontend — fonts, sizes, and table styling.', 'prautoblogger' ),
			),
			'prautoblogger_images'     => array(
				'title'       => __( 'Images', 'prautoblogger' ),
				'icon'        => 'dashicons-format-image',
				'description' => __( 'Image provider, model, and style controls. OpenRouter reuses your existing API key.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Get all settings fields. Core fields here, operational fields merged from Extended.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields(): array {
		return array_merge( self::get_core_fields(), PRAutoBlogger_Settings_Fields_Extended::get_fields() );
	}

	/**
	 * Core fields: API Keys section only.
	 *
	 * AI Models, Content, and Sources fields have moved to Pipeline Settings
	 * per-step panels (M2). Do not re-add them here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_core_fields(): array {
		return array(
			// ── API Keys ────────────────────────────────────────────────
			array(
				'id'          => 'prautoblogger_openrouter_api_key',
				'label'       => __( 'OpenRouter API Key', 'prautoblogger' ),
				'type'        => 'password',
				'section'     => 'prautoblogger_api',
				'description' => __( 'Get your key at openrouter.ai/keys', 'prautoblogger' ),
				'icon'        => '🔑',
			),
			array(
				'id'          => 'prautoblogger_reddit_source_status',
				'label'       => __( 'Reddit Data Source', 'prautoblogger' ),
				'type'        => 'source_status',
				'section'     => 'prautoblogger_api',
				'description' => __( 'Uses Reddit RSS (primary) and .json (fallback). No API key required.', 'prautoblogger' ),
				'icon'        => '📡',
			),
			array(
				'id'          => 'prautoblogger_ai_gateway_base_url',
				'label'       => __( 'Cloudflare AI Gateway URL', 'prautoblogger' ),
				'type'        => 'text',
				'section'     => 'prautoblogger_api',
				'default'     => '',
				'description' => __( 'Optional. Route OpenRouter calls through a Cloudflare AI Gateway for caching, cost logging, and rate limiting. Format: https://gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openrouter — leave blank to call OpenRouter directly.', 'prautoblogger' ),
				'icon'        => '☁️',
			),
			array(
				'id'          => 'prautoblogger_ai_gateway_cache_ttl',
				'label'       => __( 'AI Gateway Cache TTL (seconds)', 'prautoblogger' ),
				'type'        => 'number',
				'section'     => 'prautoblogger_api',
				'default'     => 0,
				'min'         => 0,
				'max'         => 2592000,
				'description' => __( 'How long Cloudflare may serve cached responses for identical LLM calls. 0 disables caching. Only used when a gateway URL is set above. Safe values: 0 for article generation (always fresh), 3600+ for repeated classification/scoring calls.', 'prautoblogger' ),
			),
		);
	}
}
