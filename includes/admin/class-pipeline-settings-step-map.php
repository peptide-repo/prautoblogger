<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Canonical definition of every LLM pipeline step shown in Pipeline Settings.
 *
 * What: A single source of truth for step metadata: display name, icon,
 *       model option key, registry prompt keys (system + agent/user),
 *       and parameters sourced from PRAutoBlogger_Prompt_Registry::defs().
 *       Non-LLM steps (Scoring, Publish) are intentionally absent — they
 *       have no model/prompt config to surface.
 * Who calls it: PRAutoBlogger_Pipeline_Settings_Renderer (step rail + panels),
 *               PRAutoBlogger_Pipeline_Settings_Save_Handler (key validation).
 * Dependencies: PRAutoBlogger_Prompt_Registry::defs() for param snapshots.
 *
 * @see admin/class-pipeline-settings-renderer.php — Consumes step definitions.
 * @see core/class-prompt-defaults.php             — Canonical prompt bodies.
 * @see core/class-prompt-defaults-editorial.php   — Editor/research/image bodies.
 */
class PRAutoBlogger_Pipeline_Settings_Step_Map {

	/**
	 * Ordered list of LLM step definitions for the Pipeline Settings UI.
	 *
	 * Each entry is an associative array with:
	 *   id           string   Machine identifier (URL param / JS key).
	 *   label        string   Display name shown in the step rail.
	 *   icon         string   Dashicons class for the step button.
	 *   model_option string|null  wp_option that controls the model for this step.
	 *   capability   string   'text→text' or 'image_generation' — drives picker.
	 *   system_key   string|null  Registry key for the system prompt.
	 *   agent_keys   string[]     Registry keys for agent/user prompts (ordered).
	 *   description  string   One-line note shown above the step panel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function steps(): array {
		return array(
			array(
				'id'           => 'research',
				'label'        => __( 'Research', 'prautoblogger' ),
				'icon'         => 'dashicons-search',
				'model_option' => 'prautoblogger_research_model',
				'capability'   => 'text→text',
				'system_key'   => 'research.system',
				'agent_keys'   => array(),
				'description'  => __( 'LLM deep-research call. The user-side research brief is a setting below, not a registry prompt.', 'prautoblogger' ),
			),
			array(
				'id'           => 'curate',
				'label'        => __( 'Curate', 'prautoblogger' ),
				'icon'         => 'dashicons-filter',
				'model_option' => 'prautoblogger_curate_model',
				'capability'   => 'text→text',
				'system_key'   => 'curate.system',
				'agent_keys'   => array(),
				'description'  => __( 'Research judge: deduplicates and scores fan-out results. Authority tier only.', 'prautoblogger' ),
			),
			array(
				'id'           => 'analysis',
				'label'        => __( 'Analysis', 'prautoblogger' ),
				'icon'         => 'dashicons-chart-bar',
				'model_option' => 'prautoblogger_analysis_model',
				'capability'   => 'text→text',
				'system_key'   => 'analysis.system',
				'agent_keys'   => array( 'analysis.user' ),
				'description'  => __( 'Scores and selects ideas from research findings.', 'prautoblogger' ),
			),
			array(
				'id'           => 'writer',
				'label'        => __( 'Writer', 'prautoblogger' ),
				'icon'         => 'dashicons-edit',
				'model_option' => 'prautoblogger_writing_model',
				'capability'   => 'text→text',
				'system_key'   => 'content.system',
				'agent_keys'   => array( 'content.single_pass', 'content.outline', 'content.draft', 'content.polish' ),
				'description'  => __( 'Generates article content. Active sub-stages depend on the Writing Pipeline mode setting.', 'prautoblogger' ),
			),
			array(
				'id'           => 'editorial',
				'label'        => __( 'Editorial', 'prautoblogger' ),
				'icon'         => 'dashicons-admin-comments',
				'model_option' => 'prautoblogger_editor_model',
				'capability'   => 'text→text',
				'system_key'   => 'editor.system',
				'agent_keys'   => array( 'editor.review' ),
				'description'  => __( 'Single-pass editorial review — approves, revises, or rejects the draft.', 'prautoblogger' ),
			),
			array(
				'id'           => 'seo',
				'label'        => __( 'SEO', 'prautoblogger' ),
				'icon'         => 'dashicons-chart-line',
				'model_option' => 'prautoblogger_seo_model',
				'capability'   => 'text→text',
				'system_key'   => 'seo.system',
				'agent_keys'   => array(),
				'description'  => __( 'Writes post-meta SEO fields and computes citation score for the publish gate. Authority tier only.', 'prautoblogger' ),
			),
			array(
				'id'           => 'image',
				'label'        => __( 'Image', 'prautoblogger' ),
				'icon'         => 'dashicons-format-image',
				'model_option' => 'prautoblogger_image_model',
				'capability'   => 'image_generation',
				'system_key'   => 'image.rewriter_system',
				'agent_keys'   => array( 'image.style_template' ),
				'description'  => __( 'Image prompt rewriter + diffusion generation. The rewriter LLM uses the Analysis model.', 'prautoblogger' ),
			),
			array(
				'id'           => 'authority',
				'label'        => __( 'Authority', 'prautoblogger' ),
				'icon'         => 'dashicons-admin-site-alt3',
				'model_option' => null,
				'capability'   => 'settings',
				'system_key'   => null,
				'agent_keys'   => array(),
				'description'  => __( 'Master switch and per-category tier assignments for the Authority pipeline.', 'prautoblogger' ),
			),
		);
	}

	/**
	 * Return a single step definition by its id, or null when not found.
	 *
	 * @param string $step_id Machine identifier of the step.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $step_id ): ?array {
		foreach ( self::steps() as $step ) {
			if ( $step['id'] === $step_id ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * All registry keys that the save handler is permitted to update via
	 * the Pipeline Settings page. Any submitted key not in this list is rejected.
	 *
	 * @return string[]
	 */
	public static function allowed_prompt_keys(): array {
		$keys = array();
		foreach ( self::steps() as $step ) {
			if ( ! empty( $step['system_key'] ) ) {
				$keys[] = $step['system_key'];
			}
			foreach ( ( $step['agent_keys'] ?? array() ) as $k ) {
				$keys[] = $k;
			}
		}
		return array_unique( $keys );
	}

	/**
	 * All model option names that the save handler may update.
	 *
	 * @return string[]
	 */
	public static function allowed_model_options(): array {
		$opts = array();
		foreach ( self::steps() as $step ) {
			if ( ! empty( $step['model_option'] ) ) {
				$opts[] = $step['model_option'];
			}
		}
		return array_unique( $opts );
	}
}
